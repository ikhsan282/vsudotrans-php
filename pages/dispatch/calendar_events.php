<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/invoice_service.php';
require_permission('dispatch.view');

$companyId = active_company_id();
if ($companyId === null && !is_super_admin()) {
    http_response_code(403);
    exit('Pilih perusahaan terlebih dahulu.');
}

$travelAllowed = can('travel.view');
$rentalAllowed = can('rental.view');
if (!$travelAllowed && !$rentalAllowed) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Tidak memiliki izin melihat booking.']);
    exit;
}

$fromRaw = trim((string) ($_GET['start'] ?? date('Y-m-01')));
$toRaw = trim((string) ($_GET['end'] ?? date('Y-m-t')));
$parseCalendarDate = static function (string $value): ?string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date->format('Y-m-d') : null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        return null;
    }
    try {
        $dateTime = new DateTimeImmutable($value);
        return $dateTime->format('Y-m-d\\TH:i:s') === substr($value, 0, 19)
            ? substr($value, 0, 10) : null;
    } catch (Throwable) {
        return null;
    }
};
$from = $parseCalendarDate($fromRaw);
$to = $parseCalendarDate($toRaw);
if ($from === null || $to === null || $from >= $to || (strtotime($to) - strtotime($from)) > 371 * 86400) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Rentang tanggal tidak valid.']);
    exit;
}

$kindFilter = strtolower(trim((string) ($_GET['kind'] ?? 'all')));
if (!in_array($kindFilter, ['all', 'travel', 'rental', ''], true)) {
    $kindFilter = 'all';
}
$validStatuses = array_keys(booking_status_options());
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}
$hideCancelled = ((string) ($_GET['hide_cancelled'] ?? '0')) === '1';
$driverId = max(0, (int) ($_GET['driver_id'] ?? 0));
$vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? 0));
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}

/**
 * Bangun klausa filter dinamis & parameter per cabang booking (travel/rental).
 *
 * @return array{sql:string, types:string, params:array<int,int|string>}
 */
$buildBranchFilter = static function (string $bookingType) use ($vehicleId, $driverId, $q): array {
    $sql = '';
    $types = '';
    $params = [];

    if ($vehicleId > 0) {
        $sql .= ' AND b.vehicle_id = ?';
        $types .= 'i';
        $params[] = $vehicleId;
    }
    if ($driverId > 0) {
        $sql .= " AND (ta.employee_id = ? OR EXISTS (SELECT 1 FROM trip_assignments taf WHERE taf.booking_type = '{$bookingType}' AND taf.booking_id = b.id AND taf.employee_id = ?) OR EXISTS (SELECT 1 FROM booking_cost_lines bclf WHERE bclf.booking_type = '{$bookingType}' AND bclf.booking_id = b.id AND bclf.employee_id = ? AND bclf.cost_type <> 'payment' AND (LOWER(bclf.cost_type) LIKE 'sopir%' OR LOWER(bclf.cost_type) LIKE 'driver%')))";
        $types .= 'iii';
        array_push($params, $driverId, $driverId, $driverId);
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= " AND (b.document_number LIKE ? OR comp.code LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ? OR v.vehicle_number LIKE ? OR v.vehicle_category LIKE ? OR cv.name LIKE ? OR e.name LIKE ? OR oc.name LIKE ? OR dc.name LIKE ? OR EXISTS (SELECT 1 FROM booking_cost_lines bclq JOIN employees eq ON eq.id = bclq.employee_id WHERE bclq.booking_type = '{$bookingType}' AND bclq.booking_id = b.id AND bclq.cost_type <> 'payment' AND eq.name LIKE ?))";
        $types .= str_repeat('s', 11);
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
};

$formatIndoDate = static function (string $ymd): string {
    static $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    $m = (int) date('n', $ts);
    return date('d', $ts) . ' ' . ($months[$m] ?? date('M', $ts)) . ' ' . date('Y', $ts);
};

$eventStyle = static function (string $kind, string $status): array {
    $isRental = $kind === 'rental';
    $palette = [
        'draft'     => ['bg' => $isRental ? '#4b8b6b' : '#4f7fc8', 'border' => $isRental ? '#166534' : '#1e40af'],
        'scheduled' => ['bg' => $isRental ? '#198754' : '#0d6efd', 'border' => $isRental ? '#146c43' : '#0a58ca'],
        'on_trip'   => ['bg' => $isRental ? '#059669' : '#0284c7', 'border' => '#f59e0b'],
        'done'      => ['bg' => $isRental ? '#157347' : '#2563eb', 'border' => $isRental ? '#0f5132' : '#1d4ed8'],
        'cancelled' => ['bg' => '#6c757d', 'border' => '#495057'],
    ];
    $c = $palette[$status] ?? $palette['scheduled'];
    return [
        'backgroundColor' => $c['bg'],
        'borderColor'     => $c['border'],
        'textColor'       => '#ffffff',
        'classNames'      => ['fc-vst-event', 'fc-vst-kind-' . $kind, 'fc-vst-status-' . $status],
    ];
};

$travelVisibility = ($travelAllowed && ($kindFilter === 'all' || $kindFilter === '' || $kindFilter === 'travel')) ? '1=1' : '1=0';
$rentalVisibility = ($rentalAllowed && ($kindFilter === 'all' || $kindFilter === '' || $kindFilter === 'rental')) ? '1=1' : '1=0';
$companySql = $companyId === null ? '' : ' AND b.company_id = ?';
$companyTypes = $companyId === null ? '' : 'i';
$travelFilter = $buildBranchFilter('travel');
$rentalFilter = $buildBranchFilter('rental');

$sql = "SELECT 'travel' AS kind, b.id, b.company_id, comp.code AS company_code, comp.name AS company_name,
               b.document_number, b.date AS starts_on, b.time AS starts_at,
               NULL AS ends_on, NULL AS ends_at,
               b.date AS raw_start_date, b.date AS raw_end_date, 0 AS trip_days,
               p.name AS passenger, COALESCE(p.phone_number, '') AS passenger_phone,
               COALESCE((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_type='travel' AND bp.booking_id=b.id AND bp.is_primary=0), 0) AS extra_pax,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='travel' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               v.id AS vehicle_id, COALESCE(v.vehicle_number, '') AS vehicle_number,
               COALESCE(v.vehicle_category, '') AS vehicle_category,
               COALESCE(bv.name, '') AS brand_name,
               COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name,
               ta.id AS assignment_id, COALESCE(ta.employee_id, 0) AS driver_id,
               COALESCE(e.name, '') AS driver, COALESCE(e.phone_number, '') AS driver_phone,
               COALESCE(oc.name, '') AS orig_city, COALESCE(dc.name, '') AS dest_city,
               COALESCE(oc.short_name, '') AS orig_city_short, COALESCE(dc.short_name, '') AS dest_city_short,
               COALESCE(b.pickup_address, '') AS pickup_address, COALESCE(b.delivery_address, '') AS delivery_address,
               COALESCE(b.total_price, 0) AS total_price, COALESCE(b.total_payment, 0) AS total_payment,
               COALESCE(inv.status, '') AS invoice_status, COALESCE(inv.total, b.total_price, 0) AS invoice_total,
               COALESCE(b.note, '') AS note
        FROM travel_ticket_bookings b
        JOIN companies comp ON comp.id=b.company_id
        JOIN passengers p ON p.id=b.passenger_id
        LEFT JOIN vehicles v ON v.id=b.vehicle_id
        LEFT JOIN brand_vehicles bv ON bv.id=v.brand_id
        LEFT JOIN category_vehicles cv ON cv.id=b.category_vehicle_id
        LEFT JOIN cities oc ON oc.id=b.pickup_point_city_id
        LEFT JOIN cities dc ON dc.id=b.delivery_point_city_id
        LEFT JOIN trip_assignments ta ON ta.id=(SELECT t2.id FROM trip_assignments t2 WHERE t2.booking_type='travel' AND t2.booking_id=b.id ORDER BY (t2.ended_at IS NULL) DESC, t2.id DESC LIMIT 1)
        LEFT JOIN employees e ON e.id=ta.employee_id
        LEFT JOIN invoices inv ON inv.id=(SELECT i2.id FROM invoices i2 WHERE i2.booking_type='travel' AND i2.booking_id=b.id AND i2.company_id=b.company_id ORDER BY i2.id DESC LIMIT 1)
        WHERE {$travelVisibility} AND b.date >= ? AND b.date < ?{$companySql}{$travelFilter['sql']}
        UNION ALL
        SELECT 'rental' AS kind, b.id, b.company_id, comp.code AS company_code, comp.name AS company_name,
               b.document_number, b.start_date AS starts_on, b.time AS starts_at,
               DATE_ADD(b.end_date, INTERVAL 1 DAY) AS ends_on, NULL AS ends_at,
               b.start_date AS raw_start_date, b.end_date AS raw_end_date, COALESCE(b.trip, 0) AS trip_days,
               p.name AS passenger, COALESCE(p.phone_number, '') AS passenger_phone,
               COALESCE((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_type='rental' AND bp.booking_id=b.id AND bp.is_primary=0), 0) AS extra_pax,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               v.id AS vehicle_id, COALESCE(v.vehicle_number, '') AS vehicle_number,
               COALESCE(v.vehicle_category, '') AS vehicle_category,
               COALESCE(bv.name, '') AS brand_name,
               COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name,
               ta.id AS assignment_id, COALESCE(ta.employee_id, 0) AS driver_id,
               COALESCE(e.name, '') AS driver, COALESCE(e.phone_number, '') AS driver_phone,
               COALESCE(oc.name, '') AS orig_city, COALESCE(dc.name, '') AS dest_city,
               COALESCE(oc.short_name, '') AS orig_city_short, COALESCE(dc.short_name, '') AS dest_city_short,
               COALESCE(b.pickup_address, '') AS pickup_address, COALESCE(b.delivery_address, '') AS delivery_address,
               COALESCE(b.total_price, 0) AS total_price, COALESCE(b.total_payment, 0) AS total_payment,
               COALESCE(inv.status, '') AS invoice_status, COALESCE(inv.total, b.total_price, 0) AS invoice_total,
               COALESCE(b.note, '') AS note
        FROM rental_car_bookings b
        JOIN companies comp ON comp.id=b.company_id
        JOIN passengers p ON p.id=b.passenger_id
        LEFT JOIN vehicles v ON v.id=b.vehicle_id
        LEFT JOIN brand_vehicles bv ON bv.id=v.brand_id
        LEFT JOIN category_vehicles cv ON cv.id=b.category_vehicle_id
        LEFT JOIN cities oc ON oc.id=b.pickup_point_city_id
        LEFT JOIN cities dc ON dc.id=b.delivery_point_city_id
        LEFT JOIN trip_assignments ta ON ta.id=(SELECT t2.id FROM trip_assignments t2 WHERE t2.booking_type='rental' AND t2.booking_id=b.id ORDER BY (t2.ended_at IS NULL) DESC, t2.id DESC LIMIT 1)
        LEFT JOIN employees e ON e.id=ta.employee_id
        LEFT JOIN invoices inv ON inv.id=(SELECT i2.id FROM invoices i2 WHERE i2.booking_type='rental' AND i2.booking_id=b.id AND i2.company_id=b.company_id ORDER BY i2.id DESC LIMIT 1)
        WHERE {$rentalVisibility} AND b.start_date < ? AND b.end_date >= ?{$companySql}{$rentalFilter['sql']}
        ORDER BY starts_on, starts_at";

$st = db()->prepare($sql);
$types = 'ss' . $companyTypes . $travelFilter['types'] . 'ss' . $companyTypes . $rentalFilter['types'];
$params = array_merge(
    [$from, $to],
    $companyId === null ? [] : [$companyId],
    $travelFilter['params'],
    [$to, $from],
    $companyId === null ? [] : [$companyId],
    $rentalFilter['params']
);
$st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Fallback kru sopir dari biaya booking bila belum punya baris trip_assignments
$missingDrivers = ['travel' => [], 'rental' => []];
foreach ($rows as $row) {
    if ((int) ($row['driver_id'] ?? 0) === 0) {
        $missingDrivers[$row['kind']][] = (int) $row['id'];
    }
}
$driverFallbacks = [
    'travel' => $missingDrivers['travel'] ? booking_driver_summaries('travel', $missingDrivers['travel']) : [],
    'rental' => $missingDrivers['rental'] ? booking_driver_summaries('rental', $missingDrivers['rental']) : [],
];

$multiCompany = $companyId === null;
$payLabels = ['unpaid' => 'Belum Bayar', 'partial' => 'Sebagian / DP', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];
$events = [];

foreach ($rows as $row) {
    $kind = (string) $row['kind'];
    $id = (int) $row['id'];
    $status = (string) ($row['status'] ?: 'draft');

    if ($statusFilter !== '') {
        if ($status !== $statusFilter) {
            continue;
        }
    } elseif ($hideCancelled && $status === 'cancelled') {
        continue;
    }

    if ((int) ($row['driver_id'] ?? 0) === 0 && !empty($driverFallbacks[$kind][$id]['primary'])) {
        $pDrv = $driverFallbacks[$kind][$id]['primary'];
        $row['driver_id'] = (int) ($pDrv['id'] ?? 0);
        $row['driver'] = (string) ($pDrv['name'] ?? '');
        $row['driver_phone'] = (string) ($pDrv['phone'] ?? '');
    }

    $rawTime = trim((string) ($row['starts_at'] ?? '00:00:00'));
    if (preg_match('/^\d{2}:\d{2}$/', $rawTime)) {
        $timeFull = $rawTime . ':00';
    } elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $rawTime)) {
        $timeFull = $rawTime;
    } else {
        $timeFull = '00:00:00';
    }
    $timeShort = substr($timeFull, 0, 5);

    $origCity = trim((string) ($row['orig_city'] ?? ''));
    $destCity = trim((string) ($row['dest_city'] ?? ''));
    $origCityShort = trim((string) ($row['orig_city_short'] ?? ''));
    $destCityShort = trim((string) ($row['dest_city_short'] ?? ''));
    $routeLabel = ($origCity !== '' && $destCity !== '')
        ? ($origCity . ' → ' . $destCity)
        : ($origCity !== '' ? $origCity : ($destCity !== '' ? $destCity : '—'));

    $rawStart = (string) ($row['raw_start_date'] ?: $row['starts_on']);
    $rawEnd = (string) ($row['raw_end_date'] ?: $row['starts_on']);
    if ($kind === 'rental') {
        $daysCalc = max(1, (int) ((strtotime($rawEnd) - strtotime($rawStart)) / 86400) + 1);
        $tripDays = max($daysCalc, (int) ($row['trip_days'] ?? 0));
        $periodLabel = $rawStart === $rawEnd
            ? ($formatIndoDate($rawStart) . ' (' . $tripDays . ' hari) · ' . $timeShort)
            : ($formatIndoDate($rawStart) . ' – ' . $formatIndoDate($rawEnd) . ' (' . $tripDays . ' hari) · ' . $timeShort);
    } else {
        $tripDays = 0;
        $periodLabel = $formatIndoDate($rawStart) . ' · ' . $timeShort;
    }

    $invTotal = (float) ($row['invoice_total'] ?? $row['total_price'] ?? 0);
    $paidTotal = (float) ($row['total_payment'] ?? 0);
    $balanceAmount = $status === 'cancelled' ? 0.0 : max(0.0, (float) $row['total_price'] - $paidTotal);
    $payStatus = ($status === 'cancelled' || ($row['invoice_status'] ?? '') === 'cancelled')
        ? 'cancelled'
        : invoice_pay_status(['total' => $invTotal, 'paid_total' => $paidTotal, 'status' => ($row['invoice_status'] ?: 'issued')]);

    $statusLabel = booking_status_label($status);
    $kindLabel = $kind === 'rental' ? 'Rental' : 'Travel';
    $compCode = trim((string) ($row['company_code'] ?? ''));
    $plate = trim((string) ($row['vehicle_number'] ?? ''));
    $vehCategory = trim((string) ($row['vehicle_category'] ?? ''));
    $brandName = trim((string) ($row['brand_name'] ?? ''));
    $categoryCode = trim((string) ($row['category_code'] ?? ''));
    $categoryName = trim((string) ($row['category_name'] ?? ''));
    $driverName = trim((string) ($row['driver'] ?? ''));
    $passengerName = trim((string) $row['passenger']);
    $extraPax = (int) ($row['extra_pax'] ?? 0);

    if ($kind === 'rental' && $plate !== '') {
        $titleParts = [$kindLabel . ' · ' . $plate, $passengerName];
    } else {
        $titleParts = [$kindLabel . ' · ' . $passengerName];
        if ($plate !== '') {
            $titleParts[] = $plate;
        }
    }
    $title = ($multiCompany && $compCode !== '' ? '[' . $compCode . '] ' : '') . implode(' · ', $titleParts);

    $details = array_filter([
        'No. ' . $row['document_number'],
        'Status: ' . $statusLabel,
        $routeLabel !== '—' ? 'Rute: ' . $routeLabel : null,
        $categoryName !== '' ? 'Layanan: ' . $categoryName : null,
        ($vehCategory !== '' && strcasecmp($vehCategory, $categoryName) !== 0) ? 'Tipe Armada: ' . $vehCategory : null,
        $brandName !== '' ? 'Merk: ' . $brandName : null,
        $plate !== '' ? 'Armada: ' . $plate : null,
        $driverName !== '' ? 'Sopir: ' . $driverName : null,
    ]);

    $style = $eventStyle($kind, $status);
    $detailUrl = APP_URL . '/pages/' . $kind . '/bookings/detail.php?id=' . $id;
    $assignmentId = (int) ($row['assignment_id'] ?? 0);
    $manifestUrl = $status !== 'cancelled'
        ? ($assignmentId > 0
            ? APP_URL . '/pages/dispatch/manifest.php?id=' . $assignmentId
            : APP_URL . '/pages/dispatch/manifest.php?type=' . $kind . '&booking_id=' . $id)
        : null;

    $events[] = [
        'id'              => $kind . '-' . $id,
        'title'           => $title,
        'start'           => $kind === 'rental' ? $row['starts_on'] : ($row['starts_on'] . 'T' . $timeFull),
        'end'             => $kind === 'rental' ? $row['ends_on'] : null,
        'allDay'          => $kind === 'rental',
        'display'         => 'block',
        'url'             => $detailUrl,
        'backgroundColor' => $style['backgroundColor'],
        'borderColor'     => $style['borderColor'],
        'textColor'       => $style['textColor'],
        'classNames'      => $style['classNames'],
        'extendedProps'   => [
            'booking_id'         => $id,
            'kind'               => $kind,
            'kind_label'         => $kindLabel,
            'document_number'    => (string) $row['document_number'],
            'status'             => $status,
            'status_label'       => $statusLabel,
            'payment_status'     => $payStatus,
            'payment_label'      => $payLabels[$payStatus] ?? 'Belum Bayar',
            'company_code'       => $compCode,
            'company_name'       => (string) ($row['company_name'] ?? ''),
            'multi_company'      => $multiCompany,
            'passenger'          => $passengerName,
            'passenger_phone'    => trim((string) ($row['passenger_phone'] ?? '')),
            'passenger_phone_62' => normalize_phone_62((string) ($row['passenger_phone'] ?? '')) ?? '',
            'extra_pax'          => $extraPax,
            'vehicle_id'         => (int) ($row['vehicle_id'] ?? 0),
            'vehicle_number'     => $plate,
            'vehicle_category'   => $vehCategory,
            'brand_name'         => $brandName,
            'category_code'      => $categoryCode,
            'category_name'      => $categoryName,
            'assignment_id'      => $assignmentId,
            'driver_id'          => (int) ($row['driver_id'] ?? 0),
            'driver'             => $driverName,
            'driver_phone'       => trim((string) ($row['driver_phone'] ?? '')),
            'driver_phone_62'    => normalize_phone_62((string) ($row['driver_phone'] ?? '')) ?? '',
            'has_driver'         => $driverName !== '',
            'orig_city'          => $origCity,
            'dest_city'          => $destCity,
            'orig_city_short'    => $origCityShort,
            'dest_city_short'    => $destCityShort,
            'route_label'        => $routeLabel,
            'pickup_address'     => trim((string) ($row['pickup_address'] ?? '')),
            'delivery_address'   => trim((string) ($row['delivery_address'] ?? '')),
            'starts_on'          => $rawStart,
            'ends_on_inclusive'  => $rawEnd,
            'starts_at'          => $timeShort,
            'trip_days'          => $tripDays,
            'period_label'       => $periodLabel,
            'total_price'        => (float) $row['total_price'],
            'total_payment'      => $paidTotal,
            'balance_amount'     => $balanceAmount,
            'total_price_fmt'    => rupiah((float) $row['total_price']),
            'total_payment_fmt'  => rupiah($paidTotal),
            'balance_fmt'        => rupiah($balanceAmount),
            'note'               => trim((string) ($row['note'] ?? '')),
            'detail_url'         => $detailUrl,
            'manifest_url'       => $manifestUrl,
            'details'            => implode(' · ', $details),
        ],
    ];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
