<?php
/**
 * GET /api/bot/staff_rekap.php
 * Khusus Admin/Staff Grup WhatsApp Hermes: menarik rekap manifest pesanan & ringkasan keuangan
 * berdasarkan tanggal/rentang tanggal, jam, jenis (travel/rental), status, pembayaran, dan pencarian kata kunci.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/bot_staff_booking.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$cid     = (int) $company['id'];

$staffCtx = bot_staff_require($ctx, ['travel.view', 'rental.view']);
$canTravel = bot_staff_has_perm($staffCtx, 'travel.view');
$canRental = bot_staff_has_perm($staffCtx, 'rental.view');

// Parse tanggal / rentang tanggal (maks 31 hari)
$rawDate = trim((string) ($_GET['date'] ?? ''));
$rawFrom = trim((string) ($_GET['date_from'] ?? ''));
$rawTo   = trim((string) ($_GET['date_to'] ?? ''));

if ($rawFrom !== '' || $rawTo !== '') {
    $dateFrom = bot_staff_parse_indonesian_date($rawFrom !== '' ? $rawFrom : $rawTo) ?? date('Y-m-d');
    $dateTo   = bot_staff_parse_indonesian_date($rawTo !== '' ? $rawTo : $rawFrom) ?? $dateFrom;
} elseif ($rawDate !== '') {
    $dateFrom = bot_staff_parse_indonesian_date($rawDate) ?? date('Y-m-d');
    $dateTo   = $dateFrom;
} else {
    $dateFrom = date('Y-m-d');
    $dateTo   = $dateFrom;
}
if ($dateTo < $dateFrom) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
if (strtotime($dateTo) - strtotime($dateFrom) > 31 * 86400) {
    $dateTo = date('Y-m-d', strtotime($dateFrom . ' +31 days'));
}

$timeFilter = trim((string) ($_GET['time'] ?? ''));
$timeNorm   = $timeFilter !== '' ? (bot_staff_parse_time($timeFilter) ?? '') : '';

$typeFilter = strtolower(trim((string) ($_GET['booking_type'] ?? 'all')));
if (!in_array($typeFilter, ['all', 'travel', 'rental'], true)) {
    $typeFilter = 'all';
}
$typesToQuery = [];
if ($typeFilter === 'travel' && $canTravel) {
    $typesToQuery = ['travel'];
} elseif ($typeFilter === 'rental' && $canRental) {
    $typesToQuery = ['rental'];
} elseif ($typeFilter === 'all') {
    if ($canTravel) {
        $typesToQuery[] = 'travel';
    }
    if ($canRental) {
        $typesToQuery[] = 'rental';
    }
}
if (!$typesToQuery) {
    bot_error(403, 'FORBIDDEN_PERMISSION', 'Anda tidak memiliki izin melihat data Travel maupun Rental.');
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'active')));
$validSt      = ['active', 'all', 'draft', 'scheduled', 'on_trip', 'done', 'cancelled', 'rescheduled'];
if (!in_array($statusFilter, $validSt, true)) {
    $statusFilter = 'active';
}

$payFilter = strtolower(trim((string) ($_GET['payment_status'] ?? 'all')));
if (!in_array($payFilter, ['all', 'unpaid', 'partial', 'paid'], true)) {
    $payFilter = 'all';
}

$q      = trim((string) ($_GET['q'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'full')));
if (!in_array($format, ['full', 'driver'], true)) {
    $format = 'full';
}

$items = [];
$fetchType = static function (string $bType) use (
    $db, $cid, $dateFrom, $dateTo, $timeNorm, $statusFilter, $payFilter, $q, $format
): array {
    $tbl      = booking_table($bType);
    $dateExpr = $bType === 'rental' ? 'COALESCE(NULLIF(b.start_date, \'\'), b.date)' : 'b.date';
    $where    = ["b.company_id = ?", "{$dateExpr} BETWEEN ? AND ?"];
    $types    = 'iss';
    $params   = [$cid, $dateFrom, $dateTo];

    if ($timeNorm !== '') {
        $where[]  = 'LEFT(b.time, 5) = ?';
        $types   .= 's';
        $params[] = $timeNorm;
    }

    $sql = "SELECT b.id, b.document_number, {$dateExpr} AS eff_date, b.time,
                   b.pickup_address, b.delivery_address, b.total_price, b.total_payment, b.note,
                   p.name AS pax_name, p.phone_number AS pax_phone,
                   COALESCE(pc.name, '') AS pickup_city_name,
                   COALESCE(pd.name, '') AS pickup_district_name,
                   COALESCE(dc.name, '') AS delivery_city_name,
                   COALESCE(dd.name, '') AS delivery_district_name,
                   COALESCE(v.vehicle_number, '') AS vehicle_plate,
                   COALESCE(mu.name, v.vehicle_category, '') AS vehicle_name,
                   COALESCE(drv.name, '') AS driver_name,
                   i.id AS invoice_id, i.invoice_no, i.total AS invoice_total, i.status AS invoice_status,
                   COALESCE(paid_sub.paid, 0) AS paid_amount,
                   COALESCE(pax_cnt.cnt, 1) AS pax_rows,
                   COALESCE((SELECT h.to_status FROM booking_status_history h
                              WHERE h.booking_type = '{$bType}' AND h.booking_id = b.id
                              ORDER BY h.id DESC LIMIT 1), 'draft') AS booking_status
              FROM {$tbl} b
              JOIN passengers p ON p.id = b.passenger_id
              LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
              LEFT JOIN districts pd ON pd.id = b.pickup_point_district_id
              LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
              LEFT JOIN districts dd ON dd.id = b.delivery_point_district_id
              LEFT JOIN vehicles v ON v.id = b.vehicle_id
              LEFT JOIN model_units mu ON mu.id = v.model_unit_id
              LEFT JOIN (
                  SELECT ta.booking_id, e.name
                    FROM trip_assignments ta
                    JOIN employees e ON e.id = ta.employee_id
                   WHERE ta.booking_type = '{$bType}' AND ta.company_id = ? AND ta.ended_at IS NULL
                   ORDER BY ta.id DESC
              ) drv ON drv.booking_id = b.id
              LEFT JOIN invoices i ON i.booking_type = '{$bType}' AND i.booking_id = b.id AND i.company_id = ?
              LEFT JOIN (
                  SELECT booking_id, COALESCE(SUM(amount), 0) AS paid
                    FROM booking_cost_lines
                   WHERE company_id = ? AND booking_type = '{$bType}' AND cost_type = 'payment'
                   GROUP BY booking_id
              ) paid_sub ON paid_sub.booking_id = b.id
              LEFT JOIN (
                  SELECT booking_id, COUNT(*) AS cnt
                    FROM booking_passengers
                   WHERE booking_type = '{$bType}'
                   GROUP BY booking_id
              ) pax_cnt ON pax_cnt.booking_id = b.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY b.id
             ORDER BY eff_date ASC, b.time ASC, b.id ASC
             LIMIT 100";

    $fullTypes  = 'iii' . $types;
    $fullParams = array_merge([$cid, $cid, $cid], $params);
    $st         = $db->prepare($sql);
    $st->bind_param($fullTypes, ...$fullParams);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $out = [];
    foreach ($rows as $r) {
        $bkSt = (string) ($r['booking_status'] ?? 'draft');
        if ($statusFilter === 'active' && $bkSt === 'cancelled') {
            continue;
        }
        if (!in_array($statusFilter, ['active', 'all'], true) && $bkSt !== $statusFilter) {
            continue;
        }

        $invTot = $r['invoice_total'] !== null ? (float) $r['invoice_total'] : (float) $r['total_price'];
        $paid   = (float) $r['paid_amount'];
        $invSt  = (string) ($r['invoice_status'] ?? 'issued');
        if ($invSt === 'cancelled' || $bkSt === 'cancelled') {
            $pSt = 'cancelled';
        } elseif ($paid > 0.001 && $paid + 0.001 >= $invTot) {
            $pSt = 'paid';
        } elseif ($paid > 0.001) {
            $pSt = 'partial';
        } else {
            $pSt = 'unpaid';
        }
        if ($payFilter !== 'all' && $pSt !== $payFilter) {
            continue;
        }

        if ($q !== '') {
            $hay = mb_strtolower(
                implode(' ', [
                    $r['document_number'], $r['invoice_no'] ?? '', $r['pax_name'], $r['pax_phone'] ?? '',
                    $r['pickup_address'] ?? '', $r['delivery_address'] ?? '',
                    $r['pickup_city_name'], $r['delivery_city_name'],
                    $r['vehicle_plate'], $r['driver_name'], $r['note'] ?? '',
                ]),
                'UTF-8'
            );
            if (!str_contains($hay, mb_strtolower($q, 'UTF-8'))) {
                continue;
            }
        }

        $seats = bot_staff_extract_seats($r, (int) $r['pax_rows']);
        $item  = [
            'booking_id'         => (int) $r['id'],
            'booking_type'       => $bType,
            'document_number'    => (string) $r['document_number'],
            'invoice_no'         => $r['invoice_no'] ?: null,
            'date'               => (string) $r['eff_date'],
            'time'               => substr((string) $r['time'], 0, 5),
            'passenger_name'     => (string) $r['pax_name'],
            'passenger_phone'    => $r['pax_phone'] ?: null,
            'seats'              => $seats,
            'pickup_city_name'   => (string) $r['pickup_city_name'],
            'pickup_district'    => (string) $r['pickup_district_name'],
            'pickup_address'     => (string) ($r['pickup_address'] ?? ''),
            'delivery_city_name' => (string) $r['delivery_city_name'],
            'delivery_district'  => (string) $r['delivery_district_name'],
            'delivery_address'   => (string) ($r['delivery_address'] ?? ''),
            'vehicle_plate'      => $r['vehicle_plate'] !== '' ? (string) $r['vehicle_plate'] : null,
            'vehicle_name'       => $r['vehicle_name'] !== '' ? (string) $r['vehicle_name'] : null,
            'driver_name'        => $r['driver_name'] !== '' ? (string) $r['driver_name'] : null,
            'booking_status'     => $bkSt,
            'payment_status'     => $pSt,
            'note'               => $r['note'] ?: null,
        ];
        if ($format !== 'driver') {
            $rem                = max(0.0, $invTot - $paid);
            $item['total_price'] = bot_money($invTot);
            $item['paid_amount'] = bot_money($paid);
            $item['remaining']   = bot_money($rem);
        }
        $out[] = $item;
    }
    return $out;
};

foreach ($typesToQuery as $tp) {
    foreach ($fetchType($tp) as $row) {
        $items[] = $row;
    }
}
usort($items, static fn(array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['time'] <=> $b['time']) ?: ($a['booking_id'] <=> $b['booking_id']));

$totalSeats = 0;
$totalPrice = 0.0;
$totalPaid  = 0.0;
$byStatus   = ['draft' => 0, 'scheduled' => 0, 'on_trip' => 0, 'done' => 0, 'cancelled' => 0];
$byPay      = ['unpaid' => 0, 'partial' => 0, 'paid' => 0, 'cancelled' => 0];

foreach ($items as $it) {
    $totalSeats += (int) $it['seats'];
    $stKey = $it['booking_status'];
    $byStatus[$stKey] = ($byStatus[$stKey] ?? 0) + 1;
    $pKey = $it['payment_status'];
    $byPay[$pKey] = ($byPay[$pKey] ?? 0) + 1;
    if ($format !== 'driver' && $stKey !== 'cancelled') {
        $totalPrice += (float) $it['total_price']['raw'];
        $totalPaid  += (float) $it['paid_amount']['raw'];
    }
}

$summary = [
    'total_bookings'    => count($items),
    'total_seats'       => $totalSeats,
    'by_status'         => $byStatus,
    'by_payment_status' => $byPay,
];
if ($format !== 'driver') {
    $summary['total_price']     = bot_money($totalPrice);
    $summary['total_paid']      = bot_money($totalPaid);
    $summary['total_remaining'] = bot_money(max(0.0, $totalPrice - $totalPaid));
}

$meta = [
    'date_from'      => $dateFrom,
    'date_to'        => $dateTo,
    'time'           => $timeNorm ?: null,
    'booking_type'   => $typeFilter,
    'status'         => $statusFilter,
    'payment_status' => $payFilter,
    'q'              => $q ?: null,
    'format'         => $format,
];

bot_reply(200, [
    'company' => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'staff'   => $staffCtx['staff'],
    'filter'  => $meta,
    'summary' => $summary,
    'items'   => $items,
    'wa_text' => bot_staff_format_rekap_wa($items, $meta, $company, $format),
]);
