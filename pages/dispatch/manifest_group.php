<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/invoice_service.php';
require_once __DIR__ . '/../../includes/pdf.php';
require_once __DIR__ . '/../../includes/azure_blob.php';

auth_check();
if (!can('dispatch.view') && !can('travel.view') && !can('rental.view')) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$activeCid = active_company_id();
if ($activeCid === null && !is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

category_service_ensure_schema();
$db = db();

$travelAllowed = can('travel.view');
$rentalAllowed = can('rental.view');

$date = parse_valid_ymd($_GET['date'] ?? null) ?? date('Y-m-d');
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$timeRaw = trim((string) ($_GET['time'] ?? ''));
$timeFilter = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeRaw) ? $timeRaw : '';
$kindFilter = strtolower(trim((string) ($_GET['kind'] ?? 'all')));
if (!in_array($kindFilter, ['all', 'travel', 'rental'], true)) {
    $kindFilter = 'all';
}
$isPdf = (($_GET['format'] ?? '') === 'pdf');
$autoPrint = (($_GET['print'] ?? '') === '1');

$vehRow = null;
if ($vehicleId > 0) {
    $stVeh = $db->prepare(
        "SELECT v.id, v.company_id, v.vehicle_number, v.vehicle_category, v.seat,
                COALESCE(bv.name, '') AS brand_name,
                c.code AS comp_code, c.name AS comp_name
           FROM vehicles v
           LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
           JOIN companies c ON c.id = v.company_id
          WHERE v.id = ?
          LIMIT 1"
    );
    $stVeh->bind_param('i', $vehicleId);
    $stVeh->execute();
    $vehRow = $stVeh->get_result()->fetch_assoc() ?: null;
    $stVeh->close();

    if (!$vehRow) {
        http_response_code(404);
        exit('Armada tidak ditemukan.');
    }
    if (!is_super_admin() && (int) $vehRow['company_id'] !== $activeCid) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
}

$companyIds = $activeCid !== null ? [$activeCid] : accessible_company_ids(0);
$vehicleOptions = [];
if ($companyIds) {
    $compIn = implode(',', array_map('intval', $companyIds));
    $multiComp = $activeCid === null;
    $resVo = $db->query(
        "SELECT v.id, v.company_id, v.vehicle_number, v.vehicle_category, v.seat,
                COALESCE(bv.name, '') AS brand_name, c.code AS comp_code
           FROM vehicles v
           LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
           JOIN companies c ON c.id = v.company_id
          WHERE v.company_id IN ({$compIn}) AND v.is_active = 1
          ORDER BY v.vehicle_number ASC"
    );
    if ($resVo) {
        $vehicleOptions = $resVo->fetch_all(MYSQLI_ASSOC);
        $resVo->close();
    }
}
if ($vehRow) {
    $foundInOpts = false;
    foreach ($vehicleOptions as $vo) {
        if ((int) $vo['id'] === (int) $vehRow['id']) {
            $foundInOpts = true;
            break;
        }
    }
    if (!$foundInOpts) {
        array_unshift($vehicleOptions, $vehRow);
    }
}

$travelVisibility = ($travelAllowed && in_array($kindFilter, ['all', 'travel'], true)) ? '1=1' : '1=0';
$rentalVisibility = ($rentalAllowed && in_array($kindFilter, ['all', 'rental'], true)) ? '1=1' : '1=0';

$companySql = $activeCid !== null ? ' AND b.company_id = ?' : '';
$companyTypes = $activeCid !== null ? 'i' : '';
$companyParams = $activeCid !== null ? [$activeCid] : [];

$vehSql = '';
$vehTypes = '';
$vehParams = [];
if ($vehicleId > 0) {
    $vehSql = ' AND b.vehicle_id = ?';
    $vehTypes = 'i';
    $vehParams = [$vehicleId];
} elseif ($vehicleId === -1) {
    $vehSql = ' AND (b.vehicle_id IS NULL OR b.vehicle_id = 0)';
}

$timeSql = '';
$timeTypes = '';
$timeParams = [];
if ($timeFilter !== '') {
    $timeSql = ' AND b.time LIKE ?';
    $timeTypes = 's';
    $timeParams = [$timeFilter . ':%'];
}

$extraCountTravelSql = booking_extra_passenger_count_sql('travel');
$extraNamesTravelSql = booking_extra_passengers_export_sql('travel');
$extraCountRentalSql = booking_extra_passenger_count_sql('rental');
$extraNamesRentalSql = booking_extra_passengers_export_sql('rental');

$sql = "SELECT 'travel' AS kind, b.id, b.company_id, comp.code AS company_code, comp.name AS company_name,
               b.document_number, b.date AS raw_start_date, b.date AS raw_end_date, 1 AS trip_days,
               b.time AS starts_at, p.name AS passenger, COALESCE(p.phone_number, '') AS passenger_phone,
               {$extraCountTravelSql} AS extra_pax_count,
               COALESCE({$extraNamesTravelSql}, '') AS extra_pax_names,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='travel' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               b.vehicle_id, COALESCE(v.vehicle_number, '') AS vehicle_number, COALESCE(v.vehicle_category, '') AS vehicle_category,
               COALESCE(bv.name, '') AS brand_name,
               COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name, COALESCE(cv.pricing_unit, '') AS pricing_unit,
               COALESCE(oc.name, '') AS orig_city, COALESCE(dc.name, '') AS dest_city,
               COALESCE(b.pickup_address, '') AS pickup_address, COALESCE(b.delivery_address, '') AS delivery_address,
               COALESCE(b.total_price, 0) AS total_price, COALESCE(b.total_payment, 0) AS total_payment,
               COALESCE(inv.status, '') AS invoice_status, COALESCE(inv.total, b.total_price, 0) AS invoice_total,
               COALESCE(b.note, '') AS note
          FROM travel_ticket_bookings b
          JOIN companies comp ON comp.id = b.company_id
          JOIN passengers p ON p.id = b.passenger_id
          LEFT JOIN vehicles v ON v.id = b.vehicle_id
          LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
          LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
          LEFT JOIN cities oc ON oc.id = b.pickup_point_city_id
          LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
          LEFT JOIN invoices inv ON inv.id = (SELECT i2.id FROM invoices i2 WHERE i2.booking_type='travel' AND i2.booking_id=b.id AND i2.company_id=b.company_id ORDER BY i2.id DESC LIMIT 1)
         WHERE {$travelVisibility}
           AND b.date = ?
           AND COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='travel' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'
           {$companySql}{$vehSql}{$timeSql}
        UNION ALL
        SELECT 'rental' AS kind, b.id, b.company_id, comp.code AS company_code, comp.name AS company_name,
               b.document_number, b.start_date AS raw_start_date, b.end_date AS raw_end_date, GREATEST(1, COALESCE(b.trip, 1)) AS trip_days,
               b.time AS starts_at, p.name AS passenger, COALESCE(p.phone_number, '') AS passenger_phone,
               {$extraCountRentalSql} AS extra_pax_count,
               COALESCE({$extraNamesRentalSql}, '') AS extra_pax_names,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               b.vehicle_id, COALESCE(v.vehicle_number, '') AS vehicle_number, COALESCE(v.vehicle_category, '') AS vehicle_category,
               COALESCE(bv.name, '') AS brand_name,
               COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name, COALESCE(cv.pricing_unit, '') AS pricing_unit,
               COALESCE(oc.name, '') AS orig_city, COALESCE(dc.name, '') AS dest_city,
               COALESCE(b.pickup_address, '') AS pickup_address, COALESCE(b.delivery_address, '') AS delivery_address,
               COALESCE(b.total_price, 0) AS total_price, COALESCE(b.total_payment, 0) AS total_payment,
               COALESCE(inv.status, '') AS invoice_status, COALESCE(inv.total, b.total_price, 0) AS invoice_total,
               COALESCE(b.note, '') AS note
          FROM rental_car_bookings b
          JOIN companies comp ON comp.id = b.company_id
          JOIN passengers p ON p.id = b.passenger_id
          LEFT JOIN vehicles v ON v.id = b.vehicle_id
          LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
          LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
          LEFT JOIN cities oc ON oc.id = b.pickup_point_city_id
          LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
          LEFT JOIN invoices inv ON inv.id = (SELECT i2.id FROM invoices i2 WHERE i2.booking_type='rental' AND i2.booking_id=b.id AND i2.company_id=b.company_id ORDER BY i2.id DESC LIMIT 1)
         WHERE {$rentalVisibility}
           AND b.start_date <= ? AND b.end_date >= ?
           AND COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'
           {$companySql}{$vehSql}{$timeSql}
         ORDER BY starts_at ASC, kind ASC, id ASC";

$st = $db->prepare($sql);
$types = 's' . $companyTypes . $vehTypes . $timeTypes
       . 'ss' . $companyTypes . $vehTypes . $timeTypes;
$params = array_merge(
    [$date],
    $companyParams,
    $vehParams,
    $timeParams,
    [$date, $date],
    $companyParams,
    $vehParams,
    $timeParams
);
$st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$idsByKind = ['travel' => [], 'rental' => []];
foreach ($rows as $row) {
    $idsByKind[$row['kind']][] = (int) $row['id'];
}
$driverMap = [
    'travel' => $idsByKind['travel'] ? booking_driver_summaries('travel', $idsByKind['travel']) : [],
    'rental' => $idsByKind['rental'] ? booking_driver_summaries('rental', $idsByKind['rental']) : [],
];

$formatIndoDate = static function (string $ymd): string {
    static $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    static $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    $dow = $days[(int) date('w', $ts)] ?? '';
    $m = $months[(int) date('n', $ts)] ?? date('M', $ts);
    return ($dow !== '' ? $dow . ', ' : '') . date('d', $ts) . ' ' . $m . ' ' . date('Y', $ts);
};

$totalBookings = count($rows);
$totalPax = 0;
$totalPackages = 0;
$totalInvoice = 0.0;
$totalPaid = 0.0;
$totalCod = 0.0;
$uniqueDrivers = [];
$uniqueVehicles = [];
$payLabels = ['unpaid' => 'Belum Bayar', 'partial' => 'DP / Sebagian', 'paid' => 'Lunas'];

foreach ($rows as &$r) {
    $kind = (string) $r['kind'];
    $bid = (int) $r['id'];
    $timeStr = substr((string) ($r['starts_at'] ?? '00:00:00'), 0, 5);
    $r['time_short'] = $timeStr !== '' ? $timeStr : '-';

    $codeUpper = strtoupper(trim((string) ($r['category_code'] ?? '')));
    $pricingUnit = trim((string) ($r['pricing_unit'] ?? ''));
    $catName = trim((string) ($r['category_name'] ?? ''));
    $isPackage = $kind === 'travel' && ($pricingUnit === 'per_package' || in_array($codeUpper, ['PKT', 'HWN'], true) || stripos($catName, 'paket') !== false);
    $r['is_package'] = $isPackage;

    $paxCount = 1 + max(0, (int) ($r['extra_pax_count'] ?? 0));
    $r['pax_count'] = $paxCount;
    if ($isPackage) {
        $totalPackages++;
    } else {
        $totalPax += $paxCount;
    }

    $invTotal = (float) ($r['invoice_total'] ?? $r['total_price'] ?? 0);
    $paidTotal = (float) ($r['total_payment'] ?? 0);
    $codAmount = max(0.0, $invTotal - $paidTotal);
    $payStatus = invoice_pay_status(['total' => $invTotal, 'paid_total' => $paidTotal, 'status' => ($r['invoice_status'] ?: 'issued')]);
    $r['inv_total'] = $invTotal;
    $r['paid_total'] = $paidTotal;
    $r['cod_amount'] = $codAmount;
    $r['pay_status'] = $payStatus;
    $r['pay_label'] = $payLabels[$payStatus] ?? 'Belum Bayar';
    $r['status_label'] = booking_status_label((string) ($r['status'] ?: 'draft'));

    $totalInvoice += $invTotal;
    $totalPaid += $paidTotal;
    $totalCod += $codAmount;

    $drvSummary = $driverMap[$kind][$bid] ?? null;
    $primaryDrv = $drvSummary['primary'] ?? null;
    $r['driver_name'] = (string) ($primaryDrv['name'] ?? '');
    $r['driver_phone'] = (string) ($primaryDrv['phone'] ?? '');
    $r['driver_extra'] = (int) ($drvSummary['extra_count'] ?? 0);
    if ($r['driver_name'] !== '') {
        $uniqueDrivers[$r['driver_name']] = $r['driver_name'] . ($r['driver_phone'] !== '' ? ' (' . $r['driver_phone'] . ')' : '');
    }

    $plate = trim((string) ($r['vehicle_number'] ?? ''));
    if ($plate !== '') {
        $vLabel = trim(($r['vehicle_category'] ? $r['vehicle_category'] . ' ' : '') . $plate);
        $uniqueVehicles[$plate] = $vLabel;
    }
}
unset($r);

// Header company profile
$headerCid = $vehRow ? (int) $vehRow['company_id'] : ($activeCid ?? (int) ($rows[0]['company_id'] ?? 1));
$stComp = $db->prepare('SELECT id, code, name, address, city, phone_number, email, website, logo, stamp FROM companies WHERE id = ? LIMIT 1');
$stComp->bind_param('i', $headerCid);
$stComp->execute();
$company = $stComp->get_result()->fetch_assoc() ?: null;
$stComp->close();

$compName = (string) ($company['name'] ?? COMPANY_NAME);
$compAddr = trim(implode(', ', array_filter([(string) ($company['address'] ?? COMPANY_ADDRESS), (string) ($company['city'] ?? '')])));
$compPhone = (string) ($company['phone_number'] ?? COMPANY_PHONE);
$compEmail = (string) ($company['email'] ?? '');
$compWeb = (string) ($company['website'] ?? '');

$resolveImg = static function (?string $path) use ($isPdf): string {
    $raw = trim((string) $path);
    if ($raw === '') {
        return '';
    }
    if ($isPdf && !extension_loaded('gd')) {
        return '';
    }
    $dataUri = resolve_asset_data_uri($raw);
    if ($dataUri !== '') {
        return $dataUri;
    }
    return $isPdf ? '' : resolve_asset_url($raw);
};
$logoSrc = $resolveImg($company['logo'] ?? '');
$stampSrc = $resolveImg($company['stamp'] ?? '');

if ($vehRow) {
    $armadaHeading = trim(format_vehicle_brand_model($vehRow['brand_name'], $vehRow['vehicle_category']) . ' — ' . $vehRow['vehicle_number']);
    if ((int) $vehRow['seat'] > 0) {
        $armadaHeading .= ' (' . (int) $vehRow['seat'] . ' seat)';
    }
} elseif ($vehicleId === -1) {
    $armadaHeading = 'Belum Ada Armada (Perlu Penugasan)';
} else {
    $armadaHeading = 'Semua Armada (' . (count($uniqueVehicles) > 0 ? count($uniqueVehicles) . ' unit aktif' : 'Gabungan') . ')';
}
$driverHeading = $uniqueDrivers ? implode(', ', array_values($uniqueDrivers)) : 'Belum ditugaskan';

// Format rekap teks WhatsApp untuk grup / sopir
$waLines = [
    '*MANIFEST GABUNGAN ARMADA — ' . $compName . '*',
    'Tanggal: ' . $formatIndoDate($date) . ($timeFilter !== '' ? ' · Shift ' . $timeFilter : ''),
    'Armada: ' . $armadaHeading,
    'Sopir: ' . $driverHeading,
    'Ringkasan: ' . $totalBookings . ' booking · ' . $totalPax . ' pnp' . ($totalPackages > 0 ? ' + ' . $totalPackages . ' paket' : '') . ' · Total COD ' . rupiah($totalCod),
    '----------------------------------',
];
if (!$rows) {
    $waLines[] = '(Tidak ada jadwal keberangkatan pada filter ini)';
} else {
    foreach ($rows as $idx => $item) {
        $no = $idx + 1;
        $routeTxt = ($item['orig_city'] !== '' && $item['dest_city'] !== '')
            ? ($item['orig_city'] . ' -> ' . $item['dest_city'])
            : ($item['orig_city'] ?: ($item['dest_city'] ?: '-'));
        $codNote = $item['cod_amount'] > 0 ? ('COD ' . rupiah($item['cod_amount'])) : 'LUNAS';
        $waLines[] = $no . '. *' . $item['passenger'] . '*'
            . ($item['passenger_phone'] !== '' ? ' (' . $item['passenger_phone'] . ')' : '')
            . ' [' . $item['document_number'] . ' | ' . $item['time_short'] . ']';
        $waLines[] = '   Rute: ' . $routeTxt . ' · ' . $item['pax_count'] . ($item['is_package'] ? ' brg' : ' pnp') . ' · ' . $codNote;
        if ($item['pickup_address'] !== '') {
            $waLines[] = '   Jemput: ' . preg_replace('/\s+/', ' ', trim((string) $item['pickup_address']));
        }
        if ($item['delivery_address'] !== '') {
            $waLines[] = '   Antar: ' . preg_replace('/\s+/', ' ', trim((string) $item['delivery_address']));
        }
    }
}
$waSummaryText = implode("\n", $waLines);

$pdfQuery = http_build_query(array_filter([
    'date'       => $date,
    'vehicle_id' => $vehicleId !== 0 ? (string) $vehicleId : null,
    'time'       => $timeFilter !== '' ? $timeFilter : null,
    'kind'       => $kindFilter !== 'all' ? $kindFilter : null,
    'format'     => 'pdf',
], static fn($v) => $v !== null));
$pdfUrl = APP_URL . '/pages/dispatch/manifest_group.php?' . $pdfQuery;
$backCalUrl = APP_URL . '/pages/dispatch/calendar.php?date=' . urlencode($date);
$pdfBaseName = 'Manifest_Gabungan_' . str_replace('-', '', $date)
    . ($vehRow ? '_' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $vehRow['vehicle_number']) : '');

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manifest Gabungan Armada — <?= e($formatIndoDate($date)) ?></title>
<style>
@page { size: A4 landscape; margin: 12mm; }
* { box-sizing: border-box; }
body {
    font-family: "DejaVu Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    font-size: 11px;
    line-height: 1.45;
    color: #1e293b;
    background: #eef2f7;
    margin: 0;
    padding: 16px;
}
.no-print {
    max-width: 1180px;
    margin: 0 auto 12px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 14px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
}
.mg-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.mg-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.mg-filter-form label {
    font-size: 11px;
    font-weight: 600;
    color: #475569;
}
.mg-ctrl {
    height: 31px;
    padding: 4px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    background: #fff;
    color: #0f172a;
}
.mg-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.mg-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 11px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
}
.mg-btn:hover { background: #f8fafc; }
.mg-btn-primary { background: #0d6efd; border-color: #0d6efd; color: #fff; }
.mg-btn-primary:hover { background: #0b5ed7; color: #fff; }
.mg-btn-success { background: #198754; border-color: #198754; color: #fff; }
.mg-btn-success:hover { background: #157347; color: #fff; }
.mg-btn-danger { background: #dc3545; border-color: #dc3545; color: #fff; }
.mg-btn-danger:hover { background: #bb2d3b; color: #fff; }

.sj-sheet {
    max-width: 1180px;
    margin: 0 auto;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 18px 22px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .07);
}
.sj-lay {
    width: 100%;
    border-collapse: collapse;
}
.sj-head {
    border-bottom: 2px solid #0d3b66;
    padding-bottom: 10px;
    margin-bottom: 12px;
}
.sj-head td {
    vertical-align: top;
    border: 0;
    padding: 0;
}
.sj-logo {
    max-height: 46px;
    max-width: 130px;
    object-fit: contain;
    margin-bottom: 4px;
}
.sj-comp-name {
    font-size: 14px;
    font-weight: bold;
    color: #0d3b66;
}
.sj-comp-sub {
    font-size: 10px;
    color: #475569;
}
.sj-doc-title {
    text-align: right;
}
.sj-doc-title h1 {
    margin: 0;
    font-size: 17px;
    letter-spacing: .5px;
    color: #0d3b66;
}
.sj-doc-title .sub {
    font-size: 11px;
    color: #334155;
    margin-top: 2px;
}
.mg-meta-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 10px;
}
.mg-meta-table td {
    border: 1px solid #cbd5e1;
    padding: 6px 9px;
    font-size: 10.5px;
    vertical-align: top;
    background: #f8fafc;
}
.mg-meta-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: #64748b;
    display: block;
    margin-bottom: 2px;
}
.mg-summary-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
}
.mg-summary-table td {
    width: 25%;
    border: 1px solid #cbd5e1;
    padding: 7px 10px;
    text-align: center;
    background: #ffffff;
}
.mg-sum-val {
    font-size: 14px;
    font-weight: bold;
    color: #0f172a;
}
.mg-sum-val.cod { color: #b91c1c; }
.mg-sum-lbl {
    font-size: 9.5px;
    color: #64748b;
    text-transform: uppercase;
}
.manifest-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
}
.manifest-table th {
    background: #0d3b66;
    color: #ffffff;
    text-align: left;
    padding: 6px 7px;
    border: 1px solid #0d3b66;
    font-size: 9.5px;
    white-space: nowrap;
}
.manifest-table td {
    border: 1px solid #cbd5e1;
    padding: 6px 7px;
    vertical-align: top;
}
.manifest-table tbody tr:nth-child(even) {
    background: #f8fafc;
}
.num { text-align: center; width: 28px; }
.right { text-align: right; }
.nowrap { white-space: nowrap; }
.mono { font-family: "DejaVu Sans Mono", Consolas, monospace; font-weight: bold; }
.tag {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: bold;
}
.tag-travel { background: #dbeafe; color: #1e40af; }
.tag-rental { background: #dcfce7; color: #166534; }
.tag-paid { background: #dcfce7; color: #166534; }
.tag-partial { background: #fef3c7; color: #92400e; }
.tag-unpaid { background: #fee2e2; color: #991b1b; }
.muted { color: #64748b; font-size: 9.5px; }
.sj-sig {
    width: 100%;
    border-collapse: collapse;
    margin-top: 18px;
}
.sj-sig td {
    width: 33.33%;
    text-align: center;
    border: 0;
    vertical-align: top;
    font-size: 10.5px;
    padding: 4px 10px;
}
.sj-sig-space {
    height: 54px;
}
.sj-stamp {
    max-height: 52px;
    max-width: 120px;
    opacity: .85;
}
@media print {
    body { background: #ffffff; padding: 0; }
    .sj-sheet { border: 0; box-shadow: none; padding: 0; max-width: none; }
    .no-print { display: none !important; }
}
</style>
</head>
<body>
<div class="no-print">
    <div class="mg-bar">
        <form method="get" class="mg-filter-form" action="">
            <a href="<?= e($backCalUrl) ?>" class="mg-btn">&larr; Kalender</a>
            <label for="mg-date">Tanggal</label>
            <input type="date" id="mg-date" name="date" value="<?= e($date) ?>" class="mg-ctrl" required>
            <label for="mg-veh">Armada</label>
            <select id="mg-veh" name="vehicle_id" class="mg-ctrl">
                <option value="0"<?= $vehicleId === 0 ? ' selected' : '' ?>>Semua Armada</option>
                <option value="-1"<?= $vehicleId === -1 ? ' selected' : '' ?>>Tanpa Armada (Perlu Tugas)</option>
                <?php foreach ($vehicleOptions as $vo):
                    $vid = (int) $vo['id'];
                    $vTxt = $vo['vehicle_number'] . ' — ' . format_vehicle_brand_model($vo['brand_name'], $vo['vehicle_category']);
                    if ($activeCid === null && !empty($vo['comp_code'])) {
                        $vTxt .= ' [' . $vo['comp_code'] . ']';
                    }
                    ?>
                    <option value="<?= $vid ?>"<?= $vehicleId === $vid ? ' selected' : '' ?>><?= e($vTxt) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="mg-time">Shift/Jam</label>
            <input type="time" id="mg-time" name="time" value="<?= e($timeFilter) ?>" class="mg-ctrl" title="Opsional: filter jam keberangkatan">
            <label for="mg-kind">Layanan</label>
            <select id="mg-kind" name="kind" class="mg-ctrl">
                <option value="all"<?= $kindFilter === 'all' ? ' selected' : '' ?>>Semua</option>
                <?php if ($travelAllowed): ?><option value="travel"<?= $kindFilter === 'travel' ? ' selected' : '' ?>>Travel</option><?php endif; ?>
                <?php if ($rentalAllowed): ?><option value="rental"<?= $kindFilter === 'rental' ? ' selected' : '' ?>>Rental</option><?php endif; ?>
            </select>
            <button type="submit" class="mg-btn mg-btn-primary">Tampilkan</button>
        </form>
        <div class="mg-actions">
            <button type="button" id="copyWaRekapBtn" class="mg-btn mg-btn-success">Salin Rekap WA</button>
            <button type="button" onclick="window.print()" class="mg-btn">Cetak</button>
            <a href="<?= e($pdfUrl) ?>" class="mg-btn mg-btn-danger">Unduh PDF</a>
        </div>
    </div>
</div>

<div class="sj-sheet">
    <table class="sj-lay sj-head">
        <tr>
            <td style="width:60%">
                <?php if ($logoSrc !== ''): ?>
                    <div><img src="<?= e($logoSrc) ?>" alt="Logo" class="sj-logo"></div>
                <?php endif; ?>
                <div class="sj-comp-name"><?= e($compName) ?></div>
                <?php if ($compAddr !== ''): ?><div class="sj-comp-sub"><?= e($compAddr) ?></div><?php endif; ?>
                <div class="sj-comp-sub"><?= e(implode(' · ', array_filter([$compPhone, $compEmail, $compWeb]))) ?></div>
            </td>
            <td style="width:40%" class="sj-doc-title">
                <h1>MANIFEST GABUNGAN ARMADA</h1>
                <div class="sub">Tanggal: <strong><?= e($formatIndoDate($date)) ?></strong><?= $timeFilter !== '' ? ' · Shift ' . e($timeFilter) : '' ?></div>
                <div class="muted">Dicetak: <?= e(date('d/m/Y H:i')) ?> WIB</div>
            </td>
        </tr>
    </table>

    <table class="mg-meta-table">
        <tr>
            <td style="width:50%">
                <span class="mg-meta-label">Armada / Unit Bertugas</span>
                <strong><?= e($armadaHeading) ?></strong>
            </td>
            <td style="width:50%">
                <span class="mg-meta-label">Sopir / Kru Bertugas</span>
                <strong><?= e($driverHeading) ?></strong>
            </td>
        </tr>
    </table>

    <table class="mg-summary-table">
        <tr>
            <td>
                <div class="mg-sum-val"><?= $totalBookings ?></div>
                <div class="mg-sum-lbl">Total Pemesanan</div>
            </td>
            <td>
                <div class="mg-sum-val"><?= $totalPax ?> Orang<?= $totalPackages > 0 ? ' + ' . $totalPackages . ' Paket' : '' ?></div>
                <div class="mg-sum-lbl">Total Muatan</div>
            </td>
            <td>
                <div class="mg-sum-val"><?= e(rupiah($totalInvoice)) ?></div>
                <div class="mg-sum-lbl">Total Tagihan</div>
            </td>
            <td>
                <div class="mg-sum-val cod"><?= e(rupiah($totalCod)) ?></div>
                <div class="mg-sum-lbl">Total Sisa COD Ditagih Sopir</div>
            </td>
        </tr>
    </table>

    <table class="manifest-table">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Resi &amp; Layanan</th>
                <th>Jam / Periode</th>
                <th>Penumpang &amp; HP</th>
                <th>Rute &amp; Alamat Jemput / Antar</th>
                <th>Armada &amp; Sopir</th>
                <th class="right">Tagihan &amp; COD</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="8" style="text-align:center; padding:18px; color:#64748b;">
                    Tidak ada jadwal perjalanan maupun sewa kendaraan aktif pada tanggal <?= e($formatIndoDate($date)) ?> sesuai filter ini.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $i => $row):
                $routeTxt = ($row['orig_city'] !== '' && $row['dest_city'] !== '')
                    ? ($row['orig_city'] . ' → ' . $row['dest_city'])
                    : ($row['orig_city'] ?: ($row['dest_city'] ?: '—'));
                $kindCls = $row['kind'] === 'rental' ? 'tag-rental' : 'tag-travel';
                $kindLbl = $row['kind'] === 'rental' ? 'Rental' : 'Travel';
                $payCls = $row['pay_status'] === 'paid' ? 'tag-paid' : ($row['pay_status'] === 'partial' ? 'tag-partial' : 'tag-unpaid');
                $periodSub = $row['kind'] === 'rental'
                    ? ($row['raw_start_date'] . ' s/d ' . $row['raw_end_date'] . ' (' . (int) $row['trip_days'] . ' hr)')
                    : $row['status_label'];
                $vehStr = $row['vehicle_number'] !== ''
                    ? trim(format_vehicle_brand_model($row['brand_name'], $row['vehicle_category']) . ' · ' . $row['vehicle_number'])
                    : 'Tanpa Armada';
                $drvStr = $row['driver_name'] !== ''
                    ? ($row['driver_name'] . ($row['driver_extra'] > 0 ? ' (+' . $row['driver_extra'] . ')' : ''))
                    : 'Tanpa Sopir';
                ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td class="nowrap">
                        <div class="mono"><?= e($row['document_number']) ?></div>
                        <div>
                            <span class="tag <?= $kindCls ?>"><?= e($kindLbl) ?></span>
                            <?php if ($row['category_name'] !== ''): ?>
                                <span class="muted"><?= e($row['category_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="nowrap">
                        <strong><?= e($row['time_short']) ?></strong>
                        <div class="muted"><?= e($periodSub) ?></div>
                    </td>
                    <td>
                        <strong><?= e($row['passenger']) ?></strong>
                        <span class="muted">(<?= (int) $row['pax_count'] ?> <?= $row['is_package'] ? 'brg' : 'pnp' ?>)</span>
                        <?php if ($row['passenger_phone'] !== ''): ?>
                            <div class="muted"><?= e($row['passenger_phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($row['extra_pax_names'] !== ''): ?>
                            <div class="muted">+ <?= e($row['extra_pax_names']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($routeTxt) ?></strong>
                        <?php if ($row['pickup_address'] !== ''): ?>
                            <div class="muted"><strong>Jemput:</strong> <?= e($row['pickup_address']) ?></div>
                        <?php endif; ?>
                        <?php if ($row['delivery_address'] !== ''): ?>
                            <div class="muted"><strong>Antar:</strong> <?= e($row['delivery_address']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><strong><?= e($vehStr) ?></strong></div>
                        <div class="muted"><?= e($drvStr) ?><?= $row['driver_phone'] !== '' ? ' · ' . e($row['driver_phone']) : '' ?></div>
                    </td>
                    <td class="right nowrap">
                        <div><span class="tag <?= $payCls ?>"><?= e($row['pay_label']) ?></span></div>
                        <div class="muted">Tot: <?= e(rupiah((float) $row['inv_total'])) ?></div>
                        <?php if ($row['cod_amount'] > 0): ?>
                            <div style="color:#b91c1c; font-weight:bold;">COD: <?= e(rupiah((float) $row['cod_amount'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['note'] !== '' ? $row['note'] : '—') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <table class="sj-sig">
        <tr>
            <td>
                <div>Disiapkan Oleh (Admin Operasional),</div>
                <div class="sj-sig-space">
                    <?php if ($stampSrc !== ''): ?><img src="<?= e($stampSrc) ?>" alt="Stempel" class="sj-stamp"><?php endif; ?>
                </div>
                <div><strong><?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin Operasional') ?></strong></div>
            </td>
            <td>
                <div>Pemeriksa Keberangkatan / Dispatcher,</div>
                <div class="sj-sig-space"></div>
                <div>( ______________________ )</div>
            </td>
            <td>
                <div>Diterima Oleh (Pengemudi),</div>
                <div class="sj-sig-space"></div>
                <div><strong><?= e($uniqueDrivers ? implode(' / ', array_keys($uniqueDrivers)) : '( ______________________ )') ?></strong></div>
            </td>
        </tr>
    </table>
</div>

<?php if (!$isPdf): ?>
<script>
(function () {
    const btn = document.getElementById('copyWaRekapBtn');
    const waText = <?= json_encode($waSummaryText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    if (btn) {
        btn.addEventListener('click', async function () {
            let copied = false;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(waText);
                    copied = true;
                }
            } catch (e) {}
            if (!copied) {
                try {
                    const ta = document.createElement('textarea');
                    ta.value = waText;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    copied = document.execCommand('copy');
                    document.body.removeChild(ta);
                } catch (e) {}
            }
            if (copied) {
                const orig = btn.textContent;
                btn.textContent = 'Rekap WA Tersalin!';
                setTimeout(() => { btn.textContent = orig; }, 2000);
            }
        });
    }
    <?php if ($autoPrint): ?>
    window.addEventListener('load', () => setTimeout(() => window.print(), 250));
    <?php endif; ?>
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
$html = (string) ob_get_clean();
if ($isPdf) {
    pdf_send_or_html(pdf_prepare_screen_html($html, '.no-print'), $pdfBaseName, 'A4', 'landscape');
    exit;
}
echo $html;
