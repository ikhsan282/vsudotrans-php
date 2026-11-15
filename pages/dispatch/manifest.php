<?php
/**
 * Surat Jalan & Manifest Penumpang Driver:
 * GET /pages/dispatch/manifest.php?id=<assignment_id>[&print=1][&format=pdf]
 * GET /pages/dispatch/manifest.php?type=travel|rental&booking_id=<id>[&print=1][&format=pdf]
 *
 * Dokumen operasional resmi untuk pengemudi (layar, cetak browser, dan unduh PDF Dompdf):
 * kop perusahaan, nomor Surat Jalan, detail perjalanan Travel/Rental, unit kendaraan & kru,
 * status pembayaran & sisa tagihan, alamat jemput/antar lengkap, daftar penumpang, dan kolom tanda tangan.
 */
require_once __DIR__ . '/../../config/config.php';
if (!can('dispatch.view') && !can('travel.view') && !can('rental.view')) {
    require_permission('dispatch.view');
}
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_once __DIR__ . '/../../includes/invoice_service.php';
require_once __DIR__ . '/../../includes/pdf.php';

$id      = (int) ($_GET['id'] ?? 0);
$reqType = trim((string) ($_GET['type'] ?? ''));
$reqBid  = (int) ($_GET['booking_id'] ?? 0);
$print   = isset($_GET['print']);
$pdf     = ($_GET['format'] ?? '') === 'pdf';

if ($pdf) {
    ob_start();
}

/**
 * Ambil data booking Travel/Rental lengkap beserta layanan, wilayah asal/tujuan, dan kota cabang.
 */
function manifest_fetch_booking(string $type, int $bookingId): ?array
{
    $table = booking_table($type);
    $extraCols = $type === 'rental'
        ? 'b.start_date, b.end_date, b.trip,'
        : 'NULL AS start_date, NULL AS end_date, 0 AS trip,';
    [$cSql, $cTypes, $cParams] = company_scope('b.company_id');

    $sql = "SELECT b.*, {$extraCols}
                   cv.code AS category_code, cv.name AS category_name,
                   br.name AS branch_name,
                   cp1.name AS city_from, cp2.name AS city_to,
                   dp1.name AS dist_from, dp2.name AS dist_to,
                   pp1.name AS prov_from, pp2.name AS prov_to
              FROM {$table} b
              LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
              LEFT JOIN branches br ON br.id = b.branch_id
              LEFT JOIN cities cp1 ON cp1.id = b.pickup_point_city_id
              LEFT JOIN cities cp2 ON cp2.id = b.delivery_point_city_id
              LEFT JOIN districts dp1 ON dp1.id = b.pickup_point_district_id
              LEFT JOIN districts dp2 ON dp2.id = b.delivery_point_district_id
              LEFT JOIN provinces pp1 ON pp1.id = b.pickup_point_province_id
              LEFT JOIN provinces pp2 ON pp2.id = b.delivery_point_province_id
             WHERE b.id = ? AND {$cSql}
             LIMIT 1";
    $st = db()->prepare($sql);
    $bindTypes = 'i' . $cTypes;
    $st->bind_param($bindTypes, $bookingId, ...$cParams);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

$assign  = null;
$booking = null;
$type    = '';
$bid     = 0;
$cid     = 0;

if ($id > 0) {
    [$aSql, $aTypes, $aParams] = company_scope('a.company_id');
    $st = db()->prepare("SELECT a.*, e.code AS employee_code, e.name AS employee_name, e.phone_number AS employee_phone,
                                v.vehicle_category, v.vehicle_number, v.vehicle_color, v.seat AS vehicle_seat,
                                b.name AS brand_name, m.name AS model_name
                         FROM trip_assignments a
                         JOIN employees e ON e.id = a.employee_id
                         LEFT JOIN vehicles v ON v.id = a.vehicle_id
                         LEFT JOIN brand_vehicles b ON b.id = v.brand_id
                         LEFT JOIN model_units m ON m.id = v.model_unit_id
                         WHERE a.id = ? AND {$aSql} LIMIT 1");
    $bindTypes = 'i' . $aTypes;
    $st->bind_param($bindTypes, $id, ...$aParams);
    $st->execute();
    $assign = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    if (!$assign) {
        if ($pdf) ob_end_clean();
        flash('error', 'Penugasan tidak ditemukan.');
        redirect('pages/dispatch/calendar.php');
    }

    $type    = (string) $assign['booking_type'];
    $bid     = (int) $assign['booking_id'];
    $cid     = (int) $assign['company_id'];
    $booking = manifest_fetch_booking($type, $bid);

    if (!$booking) {
        if ($pdf) ob_end_clean();
        flash('error', 'Data booking perjalanan tidak ditemukan.');
        redirect('pages/dispatch/calendar.php');
    }
} elseif (in_array($reqType, ['travel', 'rental'], true) && $reqBid > 0) {
    $booking = manifest_fetch_booking($reqType, $reqBid);
    if (!$booking) {
        if ($pdf) ob_end_clean();
        flash('error', 'Data booking perjalanan tidak ditemukan.');
        redirect('pages/' . $reqType . '/bookings/index.php');
    }

    $type = $reqType;
    $bid  = $reqBid;
    $cid  = (int) $booking['company_id'];

    $st = db()->prepare("SELECT a.*, e.code AS employee_code, e.name AS employee_name, e.phone_number AS employee_phone,
                                v.vehicle_category, v.vehicle_number, v.vehicle_color, v.seat AS vehicle_seat,
                                b.name AS brand_name, m.name AS model_name
                         FROM trip_assignments a
                         JOIN employees e ON e.id = a.employee_id
                         LEFT JOIN vehicles v ON v.id = a.vehicle_id
                         LEFT JOIN brand_vehicles b ON b.id = v.brand_id
                         LEFT JOIN model_units m ON m.id = v.model_unit_id
                         WHERE a.booking_type = ? AND a.booking_id = ? AND a.company_id = ?
                         ORDER BY (a.ended_at IS NULL) DESC, a.id DESC
                         LIMIT 1");
    $st->bind_param('sii', $type, $bid, $cid);
    $st->execute();
    $assign = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    if ($assign) {
        $id = (int) $assign['id'];
    } else {
        $stCr = db()->prepare("SELECT e.id AS employee_id, e.code AS employee_code, e.name AS employee_name, e.phone_number AS employee_phone
                               FROM booking_cost_lines bcl
                               JOIN employees e ON e.id = bcl.employee_id
                               WHERE bcl.booking_type = ? AND bcl.booking_id = ? AND bcl.company_id = ?
                                 AND bcl.cost_type <> 'payment' AND bcl.employee_id > 0
                               ORDER BY (bcl.cost_type LIKE 'Sopir%') DESC, bcl.id ASC
                               LIMIT 1");
        $stCr->bind_param('sii', $type, $bid, $cid);
        $stCr->execute();
        $empRow = $stCr->get_result()->fetch_assoc() ?: null;
        $stCr->close();

        $assign = [
            'employee_id'      => (int) ($empRow['employee_id'] ?? 0),
            'employee_code'    => (string) ($empRow['employee_code'] ?? ''),
            'employee_name'    => (string) ($empRow['employee_name'] ?? 'Tanpa Sopir'),
            'employee_phone'   => (string) ($empRow['employee_phone'] ?? ''),
            'vehicle_category' => '',
            'vehicle_number'   => '',
            'vehicle_color'    => '',
            'vehicle_seat'     => 0,
            'brand_name'       => '',
            'model_name'       => '',
            'started_at'       => '',
            'reason'           => '',
            'created_user'     => $booking['modified_user'] ?: ($booking['created_user'] ?: ($_SESSION['username'] ?? 'Petugas Operasional')),
        ];
    }
} else {
    if ($pdf) ob_end_clean();
    flash('error', 'Parameter Surat Jalan tidak lengkap.');
    redirect('pages/dispatch/calendar.php');
}

// Lengkapi info kendaraan dari booking jika penugasan belum mencantumkan vehicle_id
if (empty($assign['vehicle_number']) && empty($assign['vehicle_category']) && !empty($booking['vehicle_id'])) {
    $bVehId = (int) $booking['vehicle_id'];
    $stV = db()->prepare("SELECT v.vehicle_category, v.vehicle_number, v.vehicle_color, v.seat AS vehicle_seat,
                                 b.name AS brand_name, m.name AS model_name
                          FROM vehicles v
                          LEFT JOIN brand_vehicles b ON b.id = v.brand_id
                          LEFT JOIN model_units m ON m.id = v.model_unit_id
                          WHERE v.id = ? LIMIT 1");
    $stV->bind_param('i', $bVehId);
    $stV->execute();
    $vehRow = $stV->get_result()->fetch_assoc();
    $stV->close();
    if ($vehRow) {
        $assign['vehicle_category'] = $vehRow['vehicle_category'] ?? '';
        $assign['vehicle_number']   = $vehRow['vehicle_number'] ?? '';
        $assign['vehicle_color']    = $vehRow['vehicle_color'] ?? '';
        $assign['vehicle_seat']     = (int) ($vehRow['vehicle_seat'] ?? 0);
        $assign['brand_name']       = $vehRow['brand_name'] ?? '';
        $assign['model_name']       = $vehRow['model_name'] ?? '';
    }
}

// Data Perusahaan
$st = db()->prepare('SELECT code, name, address, city, phone_number, email, logo, stamp FROM companies WHERE id = ? LIMIT 1');
$st->bind_param('i', $cid);
$st->execute();
$company = $st->get_result()->fetch_assoc() ?: [];
$st->close();

// Kru tambahan (Kernet, Sopir Cadangan, Montir, dll. selain pengemudi utama)
$extraCrewList = [];
$primaryEmpId  = (int) ($assign['employee_id'] ?? 0);
$stCrew = db()->prepare("SELECT bcl.cost_type, e.id AS employee_id, e.name, e.phone_number
                         FROM booking_cost_lines bcl
                         JOIN employees e ON e.id = bcl.employee_id
                         WHERE bcl.booking_type = ? AND bcl.booking_id = ? AND bcl.company_id = ?
                           AND bcl.cost_type <> 'payment' AND bcl.employee_id > 0
                         ORDER BY bcl.id ASC");
$stCrew->bind_param('sii', $type, $bid, $cid);
$stCrew->execute();
$crewRows = $stCrew->get_result()->fetch_all(MYSQLI_ASSOC);
$stCrew->close();
$seenCrewIds = $primaryEmpId > 0 ? [$primaryEmpId => true] : [];
foreach ($crewRows as $cr) {
    $ceid = (int) ($cr['employee_id'] ?? 0);
    if ($ceid <= 0 || isset($seenCrewIds[$ceid])) {
        continue;
    }
    $seenCrewIds[$ceid] = true;
    $roleLbl = trim((string) ($cr['cost_type'] ?? 'Kru'));
    $cName   = trim((string) ($cr['name'] ?? ''));
    $cPhone  = trim((string) ($cr['phone_number'] ?? ''));
    if ($cName !== '') {
        $extraCrewList[] = $roleLbl . ': ' . $cName . ($cPhone !== '' ? ' (' . $cPhone . ')' : '');
    }
}

// Daftar penumpang terurut (pemesan utama lebih dulu)
$passengers = booking_passengers_list($type, $bid, !empty($booking['passenger_id']) ? (int) $booking['passenger_id'] : null);
$primaryPaxIdx = null;
foreach ($passengers as $idx => $pax) {
    if (!empty($pax['is_primary'])) {
        $primaryPaxIdx = $idx;
        break;
    }
}
if ($primaryPaxIdx === null && $passengers) {
    $primaryPaxIdx = 0;
}
$primaryPaxName = $passengers ? trim((string) ($passengers[$primaryPaxIdx]['name'] ?? '')) : '';

// Data Invoice & Status Pembayaran
$stInv = db()->prepare('SELECT id, invoice_no, total, status FROM invoices WHERE booking_type = ? AND booking_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1');
$stInv->bind_param('sii', $type, $bid, $cid);
$stInv->execute();
$invRow = $stInv->get_result()->fetch_assoc() ?: null;
$stInv->close();

$bookingStatus = booking_current_status($type, $bid);
$statusLabel   = booking_status_label($bookingStatus);
$total         = $invRow ? (float) $invRow['total'] : (float) ($booking['total_price'] ?? 0);
$paid          = $invRow ? invoice_paid_total((int) $invRow['id']) : (float) ($booking['total_payment'] ?? 0);
$sisa          = max(0.0, round($total - $paid, 2));
$isCancelled   = ($bookingStatus === 'cancelled') || (($invRow['status'] ?? '') === 'cancelled');
$isLunas       = !$isCancelled && $sisa <= 0.01;

// Format Tanggal, Jam, dan Nomor Dokumen
if ($type === 'rental') {
    $rawStart = (string) (!empty($booking['start_date']) && $booking['start_date'] !== '0000-00-00'
        ? $booking['start_date']
        : ($booking['date'] ?? ''));
    $rawEnd = (string) (!empty($booking['end_date']) && $booking['end_date'] !== '0000-00-00'
        ? $booking['end_date']
        : $rawStart);
    $sTs = ($rawStart !== '' && $rawStart !== '0000-00-00') ? strtotime($rawStart) : false;
    $eTs = ($rawEnd !== '' && $rawEnd !== '0000-00-00') ? strtotime($rawEnd) : false;
    $tripDays = (int) ($booking['trip'] ?? 0);
    if ($tripDays <= 0) {
        $tripDays = ($sTs && $eTs && $eTs >= $sTs) ? (int) round(($eTs - $sTs) / 86400) + 1 : 1;
    }
    $sFmt = $sTs ? date('d-M-Y', $sTs) : '—';
    $eFmt = $eTs ? date('d-M-Y', $eTs) : $sFmt;
    $dateDisplay = ($sTs && $eTs && $eFmt !== $sFmt)
        ? ($sFmt . ' s/d ' . $eFmt . ' (' . $tripDays . ' Hari)')
        : ($sFmt . ' (' . $tripDays . ' Hari)');
} else {
    $rawDate     = (string) ($booking['date'] ?? '');
    $dTs         = ($rawDate !== '' && $rawDate !== '0000-00-00') ? strtotime($rawDate) : false;
    $dateDisplay = $dTs ? date('d-M-Y', $dTs) : '—';
}

$docNumber = trim((string) ($booking['document_number'] ?? '')) ?: ($type . '-' . $bid);

$rawTime     = trim((string) ($booking['time'] ?? ''));
$timeDisplay = $rawTime !== '' ? (substr($rawTime, 0, 5) . ' WIB') : '—';

$startedAtRaw  = trim((string) ($assign['started_at'] ?? ''));
$startedTs     = ($startedAtRaw !== '' && !str_starts_with($startedAtRaw, '0000-00-00')) ? strtotime($startedAtRaw) : false;
$startedDisplay = $startedTs ? (date('d-M-Y H:i', $startedTs) . ' WIB') : '';

// Deskripsi Layanan, Rute & Alamat Lengkap
$catLabel     = trim((string) ($booking['category_name'] ?? ''));
$serviceLabel = ($type === 'rental' ? 'Sewa Kendaraan' : 'Tiket Perjalanan') . ($catLabel !== '' ? ' · ' . $catLabel : '');

$cityFrom = trim((string) ($booking['city_from'] ?? ''));
$cityTo   = trim((string) ($booking['city_to'] ?? ''));
$routeDisplay = ($cityFrom !== '' && $cityTo !== '')
    ? ($cityFrom . ' → ' . $cityTo)
    : ($cityFrom ?: ($cityTo ?: '—'));

$pickupFull = implode(', ', array_filter([
    trim((string) ($booking['pickup_address'] ?? '')),
    trim((string) ($booking['dist_from'] ?? '')),
    $cityFrom,
    trim((string) ($booking['prov_from'] ?? '')),
])) ?: '—';

$deliveryFull = implode(', ', array_filter([
    trim((string) ($booking['delivery_address'] ?? '')),
    trim((string) ($booking['dist_to'] ?? '')),
    $cityTo,
    trim((string) ($booking['prov_to'] ?? '')),
])) ?: '—';

// Format Armada & Pengemudi
$vCat  = trim((string) ($assign['vehicle_category'] ?? ''));
$bName = trim((string) ($assign['brand_name'] ?? ''));
$mName = trim((string) ($assign['model_name'] ?? ''));
if ($bName !== '' && $mName !== '') {
    $vUnit = (stripos($mName, $bName) === 0) ? $mName : ($bName . ' ' . $mName);
} else {
    $vUnit = $bName ?: $mName;
}
if ($vCat !== '' && $vUnit !== '') {
    $vTitle = (stripos($vUnit, $vCat) === false) ? ($vCat . ' · ' . $vUnit) : $vUnit;
} else {
    $vTitle = $vUnit ?: $vCat;
}
$vNum        = trim((string) ($assign['vehicle_number'] ?? ''));
$vCol        = trim((string) ($assign['vehicle_color'] ?? ''));
$vSeat       = (int) ($assign['vehicle_seat'] ?? 0);
$vehicleDesc = trim(implode(' ', array_filter([
    $vTitle,
    $vNum !== '' ? '[' . $vNum . ']' : '',
    $vCol !== '' ? '(' . $vCol . ')' : '',
])));
if ($vehicleDesc === '') {
    $vehicleDesc = 'Tanpa Mobil';
}

$driverName  = trim((string) ($assign['employee_name'] ?? '')) ?: 'Tanpa Sopir';
$driverCode  = trim((string) ($assign['employee_code'] ?? ''));
$driverPhone = trim((string) ($assign['employee_phone'] ?? ''));
$driverDisplay = $driverName;
if ($driverName !== 'Tanpa Sopir' && $driverCode !== '' && $driverCode !== '-') {
    $driverDisplay .= ' (' . $driverCode . ')';
}
if ($driverPhone !== '' && $driverPhone !== '-') {
    $driverDisplay .= ' · ' . $driverPhone;
}
$petugasName = trim((string) ($assign['created_user'] ?? '')) ?: 'Petugas Operasional';

// Asset Logo & Stempel (Dompdf butuh ekstensi GD untuk merender gambar PNG ber-alpha)
$canPdfImg = extension_loaded('gd');
$logoPath  = (string) ($company['logo'] ?? '');
$logoSrc   = $pdf ? ($canPdfImg ? resolve_asset_data_uri($logoPath) : '') : resolve_asset_url($logoPath);
$stampPath = (string) ($company['stamp'] ?? '');
$stampSrc  = $pdf ? ($canPdfImg ? resolve_asset_data_uri($stampPath) : '') : resolve_asset_url($stampPath);

$compAddr = trim(implode(', ', array_filter([
    trim((string) ($company['address'] ?? '')),
    trim((string) ($company['city'] ?? '')),
])));
$compContact = implode(' · ', array_filter([
    !empty($company['phone_number']) ? 'Telp/WA: ' . $company['phone_number'] : '',
    !empty($company['email']) ? 'Email: ' . $company['email'] : '',
]));

$backUrl = APP_URL . '/pages/' . $type . '/bookings/detail.php?id=' . $bid;
$pdfHref = $reqBid > 0
    ? ('?type=' . urlencode($type) . '&booking_id=' . $bid . '&format=pdf')
    : ('?id=' . $id . '&format=pdf');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan — <?= e($docNumber) ?></title>
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page { size: A4; margin: 12mm; }
        body {
            font-family: "Liberation Sans", Arial, Helvetica, sans-serif;
            font-size: 9.5pt;
            color: #1e293b;
            background: #f1f5f9;
            margin: 0;
            padding: 18px;
            line-height: 1.4;
        }
        .actions-bar {
            max-width: 820px;
            margin: 0 auto 14px auto;
            text-align: right;
        }
        .btn {
            display: inline-block;
            padding: 7px 14px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: 1px solid transparent;
            margin-left: 6px;
        }
        .btn-primary { background: #0f172a; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-secondary { background: #fff; border-color: #cbd5e1; color: #334155; }

        .sj-sheet {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            padding: 26px 28px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }

        table.sj-lay {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.sj-lay > tbody > tr > td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }

        .sj-head {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .sj-logo {
            max-height: 44px;
            max-width: 210px;
            width: auto;
            margin-bottom: 5px;
        }
        .company-title {
            font-size: 13.5pt;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .company-meta {
            font-size: 8.2pt;
            color: #475569;
            line-height: 1.35;
        }
        .doc-title {
            font-size: 16pt;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin: 0;
        }
        .doc-no {
            font-family: monospace;
            font-size: 9.5pt;
            font-weight: bold;
            color: #334155;
            margin-top: 3px;
        }
        .doc-meta {
            font-size: 8pt;
            color: #64748b;
            margin-top: 3px;
        }

        .sj-box {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #f8fafc;
            padding: 9px 11px;
        }
        .sj-box-title {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        table.sj-kv {
            width: 100%;
            border-collapse: collapse;
        }
        table.sj-kv td {
            border: 0;
            padding: 2.2px 0;
            vertical-align: top;
            font-size: 8.8pt;
        }
        table.sj-kv td.k {
            width: 112px;
            color: #64748b;
            font-size: 8.3pt;
            padding-right: 6px;
        }
        table.sj-kv td.v {
            color: #0f172a;
            font-weight: bold;
        }

        .sj-addr-box {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px 10px;
            background: #fff;
            min-height: 54px;
        }
        .sj-addr-lbl {
            font-size: 7.3pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
            margin-bottom: 3px;
        }
        .sj-addr-val {
            font-size: 8.8pt;
            font-weight: bold;
            color: #0f172a;
        }

        .section-heading {
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #0f172a;
            margin: 14px 0 6px 0;
        }
        table.manifest-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 8.8pt;
        }
        table.manifest-table th, table.manifest-table td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            vertical-align: middle;
        }
        table.manifest-table th {
            background: #f1f5f9;
            font-weight: bold;
            font-size: 8.2pt;
            color: #1e293b;
            text-align: left;
        }
        .text-center { text-align: center !important; }
        .text-end { text-align: right !important; }
        .text-muted { color: #64748b; }

        .tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: bold;
        }
        .tag-success { background: #dcfce7; color: #166534; }
        .tag-warning { background: #fef3c7; color: #92400e; }
        .tag-danger  { background: #fee2e2; color: #991b1b; }
        .tag-neutral { background: #e2e8f0; color: #334155; }

        .note-box {
            font-size: 8.6pt;
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 8px 10px;
            border-radius: 4px;
            margin-bottom: 14px;
        }

        table.sj-sig {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 20px;
            page-break-inside: avoid;
        }
        table.sj-sig td {
            border: 0;
            text-align: center;
            vertical-align: bottom;
            padding: 0 10px;
            font-size: 8.8pt;
        }
        .sig-role {
            color: #334155;
            margin-bottom: 6px;
        }
        .sig-space {
            height: 54px;
            vertical-align: middle;
        }
        .sig-stamp {
            max-height: 50px;
            width: auto;
            opacity: 0.9;
        }
        .sig-line {
            border-top: 1px dashed #475569;
            padding-top: 4px;
            font-weight: bold;
            color: #0f172a;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .sj-sheet { border: 0; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="actions-bar no-print">
        <a href="<?= e($backUrl) ?>" class="btn btn-secondary">&larr; Kembali</a>
        <a href="<?= e($pdfHref) ?>" class="btn btn-danger">Unduh PDF</a>
        <button type="button" onclick="window.print()" class="btn btn-primary">Cetak Surat Jalan</button>
    </div>

    <div class="sj-sheet">
        <div class="sj-head">
            <table class="sj-lay">
                <tr>
                    <td style="width: 58%; padding-right: 12px;">
                        <?php if ($logoSrc !== ''): ?>
                            <img src="<?= e($logoSrc) ?>" alt="<?= e($company['name'] ?? APP_NAME) ?>" class="sj-logo"><br>
                        <?php endif; ?>
                        <div class="company-title"><?= e((string) ($company['name'] ?? '') ?: APP_NAME) ?></div>
                        <div class="company-meta">
                            <?php if ($compAddr !== ''): ?><?= e($compAddr) ?><br><?php endif; ?>
                            <?php if ($compContact !== ''): ?><?= e($compContact) ?><?php endif; ?>
                        </div>
                    </td>
                    <td class="text-end" style="width: 42%;">
                        <div class="doc-title">Surat Jalan</div>
                        <div class="doc-meta">
                            Status: <span class="tag <?= $isCancelled ? 'tag-danger' : 'tag-neutral' ?>"><?= e(strtoupper($statusLabel)) ?></span>
                        </div>
                        <div class="doc-meta">Tgl Cetak: <?= date('d-M-Y H:i') ?> WIB</div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="sj-lay" style="margin-bottom: 10px;">
            <tr>
                <td style="width: 50%; padding-right: 6px;">
                    <div class="sj-box">
                        <div class="sj-box-title">Detail Perjalanan</div>
                        <table class="sj-kv">
                            <tr>
                                <td class="k">No. Resi Booking</td>
                                <td class="v"><?= e((string) $booking['document_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="k">Layanan</td>
                                <td class="v"><?= e($serviceLabel) ?></td>
                            </tr>
                            <tr>
                                <td class="k">Rute</td>
                                <td class="v"><?= e($routeDisplay) ?></td>
                            </tr>
                            <tr>
                                <td class="k"><?= $type === 'rental' ? 'Periode Sewa' : 'Tgl Berangkat' ?></td>
                                <td class="v"><?= e($dateDisplay) ?></td>
                            </tr>
                            <tr>
                                <td class="k"><?= $type === 'rental' ? 'Jam Mulai / Kursi' : 'Jam Berangkat' ?></td>
                                <td class="v">
                                    <?= e($timeDisplay) ?>
                                    <?php if ($type === 'rental' && $vSeat > 0): ?> · <?= (int) $vSeat ?> Kursi<?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($booking['branch_name'])): ?>
                            <tr>
                                <td class="k">Cabang</td>
                                <td class="v"><?= e((string) $booking['branch_name']) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </td>
                <td style="width: 50%; padding-left: 6px;">
                    <div class="sj-box">
                        <div class="sj-box-title">Armada, Kru &amp; Tagihan</div>
                        <table class="sj-kv">
                            <tr>
                                <td class="k">Armada Unit</td>
                                <td class="v"><?= e($vehicleDesc) ?></td>
                            </tr>
                            <tr>
                                <td class="k">Pengemudi / Sopir</td>
                                <td class="v"><?= e($driverDisplay) ?></td>
                            </tr>
                            <?php if ($extraCrewList): ?>
                            <tr>
                                <td class="k">Kru Pendamping</td>
                                <td class="v"><?= e(implode('; ', $extraCrewList)) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($startedDisplay !== ''): ?>
                            <tr>
                                <td class="k">Mulai Tugas</td>
                                <td class="v"><?= e($startedDisplay) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="k">Total Tagihan</td>
                                <td class="v"><?= e(rupiah($total)) ?> (Dibayar: <?= e(rupiah($paid)) ?>)</td>
                            </tr>
                            <tr>
                                <td class="k">Status Pembayaran</td>
                                <td class="v">
                                    <?php if ($isCancelled): ?>
                                        <span class="tag tag-danger">DIBATALKAN</span>
                                    <?php elseif ($isLunas): ?>
                                        <span class="tag tag-success">LUNAS (RP 0)</span>
                                    <?php else: ?>
                                        <span class="tag tag-warning">DITAGIH SOPIR: <?= e(rupiah($sisa)) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table class="sj-lay" style="margin-bottom: 12px;">
            <tr>
                <td style="width: 50%; padding-right: 6px;">
                    <div class="sj-addr-box">
                        <div class="sj-addr-lbl">Titik Penjemputan (Pickup)</div>
                        <div class="sj-addr-val"><?= e($pickupFull) ?></div>
                    </div>
                </td>
                <td style="width: 50%; padding-left: 6px;">
                    <div class="sj-addr-box">
                        <div class="sj-addr-lbl">Titik Pengantaran (Drop-off)</div>
                        <div class="sj-addr-val"><?= e($deliveryFull) ?></div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="section-heading">Daftar Manifest Penumpang (<?= count($passengers) ?> Orang)</div>
        <table class="manifest-table">
            <thead>
                <tr>
                    <th style="width: 30px;" class="text-center">No</th>
                    <th>Nama Penumpang</th>
                    <th style="width: 115px;">Tipe</th>
                    <th style="width: 115px;">No. Telepon</th>
                    <th style="width: 150px;" class="text-center">Keterangan Tagihan</th>
                    <th style="width: 65px;" class="text-center">Paraf</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($passengers): ?>
                    <?php foreach ($passengers as $idx => $p): ?>
                    <tr>
                        <td class="text-center"><?= $idx + 1 ?></td>
                        <td style="font-weight: bold;"><?= e((string) ($p['name'] ?? '-')) ?></td>
                        <td><?= !empty($p['is_primary']) ? 'Pemesan (Utama)' : 'Penumpang' ?></td>
                        <td><?= e((string) ($p['phone_number'] ?? '') ?: '—') ?></td>
                        <td class="text-center">
                            <?php if ($isCancelled): ?>
                                <span class="tag tag-danger">BATAL</span>
                            <?php elseif ($isLunas): ?>
                                <span class="tag tag-success">LUNAS</span>
                            <?php elseif ($idx === $primaryPaxIdx): ?>
                                <span class="tag tag-warning">Tagih: <?= e(rupiah($sisa)) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 8pt;">Ikut Pemesan</span>
                            <?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted" style="padding: 14px;">Tidak ada penumpang tercatat.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($booking['note']) || !empty($assign['reason'])): ?>
        <div class="note-box">
            <strong>Catatan Operasional:</strong><br>
            <?= nl2br(e(trim(implode("\n", array_filter([
                !empty($booking['note']) ? 'Booking: ' . trim((string) $booking['note']) : '',
                !empty($assign['reason']) ? 'Penugasan: ' . trim((string) $assign['reason']) : '',
            ]))))) ?>
        </div>
        <?php endif; ?>

        <table class="sj-sig">
            <tr>
                <td>
                    <div class="sig-role">Pengemudi / Driver,</div>
                    <div class="sig-space">&nbsp;</div>
                    <div class="sig-line"><?= e($driverName) ?></div>
                </td>
                <td>
                    <div class="sig-role">Penumpang / Penyewa,</div>
                    <div class="sig-space">&nbsp;</div>
                    <div class="sig-line"><?= e($primaryPaxName !== '' ? $primaryPaxName : '( .......................... )') ?></div>
                </td>
                <td>
                    <div class="sig-role">Petugas Operasional,</div>
                    <div class="sig-space">
                        <?php if ($stampSrc !== ''): ?>
                            <img src="<?= e($stampSrc) ?>" alt="Stempel <?= e($company['name'] ?? APP_NAME) ?>" class="sig-stamp">
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </div>
                    <div class="sig-line"><?= e($petugasName) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <?php if ($print && !$pdf): ?>
    <script>window.addEventListener('load', function() { window.print(); });</script>
    <?php endif; ?>

    <?php if ($pdf): ?>
    <?php
    $html = ob_get_clean();
    pdf_send_or_html(pdf_prepare_screen_html($html, '.no-print'), 'Surat-Jalan-' . $docNumber);
    ?>
    <?php endif; ?>
</body>
</html>
