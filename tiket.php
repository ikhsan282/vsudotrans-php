<?php
/**
 * Tiket publik: GET /tiket.php?no=<document_number>. Tanpa login.
 * - Mode layar : kartu ringkas + tombol Cetak.
 * - Mode cetak : ?print=1 -> jendela cetak otomatis (pilih "Simpan sebagai PDF").
 * Tata cetak mengikuti tiket resmi Maura: kop logo + E-TIKET, blok perusahaan,
 * data rute, tabel penumpang + total, alamat jemput/antar, dan cap/stempel MTM.
 * JANGAN tampilkan booking_cost_lines / premi. Nomor tidak ada -> pesan generik.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/booking_service.php';
require_once __DIR__ . '/includes/invoice_service.php';
require_once __DIR__ . '/includes/payment_gateway.php';

$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$companyCode = strtoupper(trim((string) ($_GET['company'] ?? '')));
$print = isset($_GET['print']);
$pdf   = ($_GET['format'] ?? '') === 'pdf';
$row = null;
$passengers = [];
$canPayQris = false;
if (booking_doc_valid($no)) {
    $type = booking_resolve_type_by_doc($no, $companyCode);
    $table = $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
    $extraCols = $type === 'rental'
        ? 'b.start_date, b.end_date, b.trip,'
        : 'NULL AS start_date, NULL AS end_date, 0 AS trip,';
    // Rute dicetak dari nama kota asal -> tujuan (booking tidak menyimpan nama rute).
    $sql = "SELECT b.id, b.company_id, b.document_number, b.date, b.time, b.pickup_address, b.delivery_address,
            b.total_price, b.total_payment, b.created_user, b.passenger_id, {$extraCols}
            c.code AS company_code, c.name AS company_name, c.address, c.phone_number, c.email, c.website, c.logo, c.stamp,
            i.id AS invoice_id, i.status AS invoice_status, i.total AS invoice_total, i.invoice_no,
            cp1.name AS city_from, cp2.name AS city_to,
            dp1.name AS dist_from, dp2.name AS dist_to,
            pp1.name AS prov_from, pp2.name AS prov_to,
            p.name AS passenger_name, p.phone_number AS passenger_phone,
            v.vehicle_number, bv.name AS brand_name, mu.name AS model_name,
            CONCAT_WS(' ', bv.name, mu.name) AS vehicle_name, cv.name AS category_name
        FROM {$table} b
        JOIN companies c ON c.id = b.company_id
        LEFT JOIN invoices i ON i.document_number = b.document_number
        LEFT JOIN cities cp1 ON cp1.id = b.pickup_point_city_id
        LEFT JOIN cities cp2 ON cp2.id = b.delivery_point_city_id
        LEFT JOIN districts dp1 ON dp1.id = b.pickup_point_district_id
        LEFT JOIN districts dp2 ON dp2.id = b.delivery_point_district_id
        LEFT JOIN provinces pp1 ON pp1.id = b.pickup_point_province_id
        LEFT JOIN provinces pp2 ON pp2.id = b.delivery_point_province_id
        LEFT JOIN passengers p ON p.id = b.passenger_id
        LEFT JOIN vehicles v ON v.id = b.vehicle_id
        LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
        LEFT JOIN model_units mu ON mu.id = v.model_unit_id
        LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
        WHERE b.document_number = ?";
    if ($companyCode !== '') {
        $sql .= ' AND c.code = ? LIMIT 1';
        $st = db()->prepare($sql);
        $st->bind_param('ss', $no, $companyCode);
    } else {
        $sql .= ' LIMIT 1';
        $st = db()->prepare($sql);
        $st->bind_param('s', $no);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $passengers = booking_passengers_list($type, (int) $row['id'], (int) $row['passenger_id']);
        $invTotal = (float) ($row['invoice_total'] ?? $row['total_price']);
        $paid = !empty($row['invoice_id']) ? invoice_paid_total((int) $row['invoice_id']) : (float) $row['total_payment'];
        $rem = max(0.0, round($invTotal - $paid, 2));
        if ($rem > 0.01 && ($row['invoice_status'] ?? 'issued') !== 'cancelled') {
            $canPayQris = gateway_active_channel((int) $row['company_id']) !== null;
        }

        $pickupParts = array_filter([
            trim((string) ($row['pickup_address'] ?? '')),
            trim((string) ($row['dist_from'] ?? '')),
            trim((string) ($row['city_from'] ?? '')),
            trim((string) ($row['prov_from'] ?? ''))
        ]);
        $pickupFull = $pickupParts ? implode(', ', $pickupParts) : '—';

        $deliveryParts = array_filter([
            trim((string) ($row['delivery_address'] ?? '')),
            trim((string) ($row['dist_to'] ?? '')),
            trim((string) ($row['city_to'] ?? '')),
            trim((string) ($row['prov_to'] ?? ''))
        ]);
        $deliveryFull = $deliveryParts ? implode(', ', $deliveryParts) : '—';

        $bName = trim((string) ($row['brand_name'] ?? ''));
        $mName = trim((string) ($row['model_name'] ?? ''));
        if ($bName !== '' && $mName !== '') {
            $vUnit = (stripos($mName, $bName) === 0) ? $mName : ($bName . ' ' . $mName);
        } else {
            $vUnit = $bName ?: $mName;
        }
        $vPlat = trim((string) ($row['vehicle_number'] ?? ''));
        $vehicleDisplay = trim($vUnit . ($vPlat !== '' ? ' (' . $vPlat . ')' : ''));

        // Format periode sewa: dd-MMM-yyyy s/d dd-MMM-yyyy (X Hari)
        $sDate = (string) ($row['start_date'] ?: $row['date']);
        $eDate = (string) ($row['end_date'] ?: $sDate);
        $sTs = strtotime($sDate);
        $eTs = strtotime($eDate);
        $days = ($sTs && $eTs && $eTs >= $sTs) ? (int) round(($eTs - $sTs) / 86400) + 1 : 1;
        $sFmt = $sTs ? date('d-M-Y', $sTs) : $sDate;
        $eFmt = $eTs ? date('d-M-Y', $eTs) : $eDate;
        $periodeDisplay = $sFmt . ' s/d ' . $eFmt . ' (' . $days . ' Hari)';
    }
}

$scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$self_link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . APP_URL_PATH . '/tiket.php?no=' . urlencode($no);

// Tautan resmi verifikasi tiket publik: prioritaskan domain website resmi PT (contoh: https://mauratransmandiri.com/verifikasi?no=...)
$compWeb = trim((string) ($row['website'] ?? ''));
if ($compWeb !== '') {
    $ver_link = rtrim($compWeb, '/') . '/verifikasi?no=' . urlencode($no);
} else {
    $ver_link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . APP_URL_PATH . '/verifikasi.php?no=' . urlencode($no);
}
$title = 'Tiket' . ($row ? ' ' . $row['document_number'] : '');
if ($pdf) {
    ob_start();
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Ukuran & font disamakan dengan contoh resmi (terukur dari PDF contoh):
           label 7,5pt · nilai & isi tabel 9pt · Detail Penumpang 9,8pt · E-TIKET 25,5pt. */
        body { font-family: "Liberation Sans", Helvetica, Arial, sans-serif; }
        .tk-doc { color: #777; font-size: 7.5pt; letter-spacing: .4px; text-transform: uppercase; white-space: nowrap; }
        .tk-v { font-weight: 700; font-size: 9pt; line-height: 14pt; }
        .tk-body { font-size: 9pt; line-height: 13pt; }
        .tk-sub { font-size: 10.5pt; font-weight: 700; line-height: 15pt; color: #1a1a1a; }
        .tk-addr { font-size: 9pt; font-weight: 700; }
        .tk-foot { font-size: 9pt; }
        .tk-ver { font-size: 7.5pt; color: #777; }
        .tk-eti { font-size: 25.5pt; white-space: nowrap; text-align: right; }
        .tk-sect { font-size: 9.8pt; font-weight: 700; }
        table.tk-tbl { font-size: 9pt; width: 100%; border-collapse: collapse; }
        table.tk-tbl th { background: #f2f2f2; font-size: 8.2pt; }
        /* Utilitas yang dibutuhkan tiket didefinisikan sendiri (tak bergantung Bootstrap,
           agar PDF cetak/preview identik di semua lingkungan). */
        .text-end { text-align: right; }
        .text-muted { color: #6c757d; }
        .align-bottom { vertical-align: bottom !important; }
        .align-top { vertical-align: top !important; }
        .fw-bold, strong { font-weight: 700; }
        .mt-4 { margin-top: 18pt; }
        .mt-2 { margin-top: 6pt; }
        .mb-1 { margin-bottom: 3pt; }
        .mb-0 { margin-bottom: 0; }
        .table { width: 100%; border-collapse: collapse; }
        .table td, .table th { padding: 5px 8px; vertical-align: top; }
        .table-bordered td, .table-bordered th { border: 1px solid #d9d9d9; }
        .text-break { word-break: break-all; }
        .tk-stamp { width: 152px; }
        /* Box Syarat & Ketentuan Perjalanan (Travel & Rental) */
        .tk-terms { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; padding: 8px 12px; font-size: 8pt; line-height: 11.5pt; color: #444; }
        .tk-terms-title { font-weight: 700; font-size: 8.5pt; margin-bottom: 4px; color: #222; text-transform: uppercase; letter-spacing: .3px; }
        .tk-terms ul { margin: 0; padding-left: 15px; }
        .tk-terms li { margin-bottom: 2px; }
        /* Logo & gambar dibatasi agar tidak melebar menekan kolom kanan keluar halaman. */
        .tk-logo { height: 46px; width: auto; max-width: 220px; }
        .tk-head { margin-bottom: 4px; }
        /* Tata cetak berbasis tabel: didukung semua renderer & printer (tanpa flexbox). */
        table.tk-lay { border-collapse: collapse; table-layout: fixed; width: 100%; }
        table.tk-lay > tbody > tr > td { border: 0; padding: 0; vertical-align: top; }
        table.tk-lay > tbody > tr > td.tk-split-left { width: 43%; padding-right: 18pt; vertical-align: top; }
        table.tk-lay > tbody > tr > td.tk-split-right { width: 57%; padding-left: 18pt; vertical-align: top; }
        .tk-sect-lbl { color: #888; font-size: 7pt; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 4pt; }
        .tk-hr { border: 0; border-top: 1px solid #dee2e6; margin: 6pt 0 10pt 0; opacity: 1; }
        table.tk-kv { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.tk-kv td { padding: 2.5pt 0; vertical-align: middle; border-bottom: 1px dashed #eceff2; }
        table.tk-kv tr:last-child td { border-bottom: 0; padding-bottom: 0; }
        table.tk-kv tr:first-child td { padding-top: 0; }
        table.tk-kv td.tk-kv-k { width: 105pt; color: #6c757d; font-size: 7.5pt; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; white-space: nowrap; }
        table.tk-kv td.tk-kv-v { color: #111; font-size: 9pt; font-weight: 700; line-height: 13pt; }
        .tk-price-val { color: #0a58ca; font-size: 9.8pt; font-weight: 700; }
        .tk-card {
            min-height: 820px;
            display: flex;
            flex-direction: column;
        }
        .tk-card > .card-body {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
        }
        .tk-body-content {
            flex: 1 0 auto;
        }
        .tk-footer {
            margin-top: auto;
            padding-top: 18pt;
        }
        @media print {
            @page { size: A4; margin: 12mm 12mm 12mm 12mm; }
            html, body { background: #fff !important; height: 100%; margin: 0 !important; padding: 0 !important; }
            .no-print { display: none !important; }
            main.container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .tk-card {
                border: 0 !important;
                box-shadow: none !important;
                min-height: 260mm !important;
                display: flex !important;
                flex-direction: column !important;
                page-break-inside: avoid;
            }
            .tk-card > .card-body {
                display: flex !important;
                flex-direction: column !important;
                flex: 1 1 auto !important;
                padding: 0 !important;
            }
            .tk-body-content {
                flex: 1 0 auto !important;
            }
            .tk-footer {
                margin-top: auto !important;
                padding-top: 18pt !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-body-tertiary">
<div class="no-print container py-2 text-end" style="max-width: 860px;">
    <?php if ($canPayQris && $row): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(APP_URL . '/pages/finance/invoices/gateway_pay.php?no=' . urlencode($row['document_number'])) ?>">Bayar via QRIS</a>
    <?php endif; ?>
    <?php if ($row): ?>
        <a class="btn btn-danger btn-sm" href="?no=<?= urlencode($no) ?><?= $companyCode !== '' ? '&company=' . urlencode($companyCode) : '' ?>&format=pdf">
            <i class="bi bi-file-earmark-pdf"></i> Unduh PDF
        </a>
    <?php endif; ?>
    <button class="btn btn-dark btn-sm" onclick="window.print()">Cetak / Simpan PDF</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/index.php">Beranda</a>
</div>
<main class="container py-2" style="max-width: 860px;padding-left:0;padding-right:0;">
<?php if (!$row): ?>
    <div class="alert alert-warning">Tiket tidak ditemukan. Periksa kembali nomor tiket Anda.</div>
<?php else: ?>
    <div class="card tk-card shadow-sm border-0">
        <div class="card-body">
            <div class="tk-body-content">
            <table class="tk-lay tk-head w-100"><tr>
                <td class="align-top" style="width:340px">
                    <?php
                    $logoPath = (string) ($row['logo'] ?? '');
                    $logoSrc  = $pdf ? resolve_asset_data_uri($logoPath) : resolve_asset_url($logoPath);
                    ?>
                    <?php if ($logoSrc !== ''): ?>
                        <img src="<?= e($logoSrc) ?>" alt="<?= e($row['company_name']) ?>" class="tk-logo">
                    <?php else: ?>
                        <div class="fw-bold" style="font-size:14pt"><?= e($row['company_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-end align-top"><h1 class="tk-eti" style="margin:0">E-TIKET</h1></td>
            </tr></table>
            <hr class="tk-hr">

            <table class="tk-lay w-100"><tr>
                <td class="tk-split-left">
                    <div class="tk-sect-lbl">Perusahaan</div>
                    <div class="tk-sub mb-1"><?= e($row['company_name']) ?></div>
                    <div class="tk-body text-muted" style="line-height: 13.5pt;">
                        <?= e((string) $row['address']) ?>
                    </div>
                </td>
                <td class="tk-split-right">
                    <div class="tk-sect-lbl"><?= $type === 'rental' ? 'Detail Pemesanan' : 'Detail Tiket Perjalanan' ?></div>
                    <table class="tk-kv">
                        <?php if ($type === 'rental'): ?>
                            <tr>
                                <td class="tk-kv-k">Resi</td>
                                <td class="tk-kv-v"><?= e($row['document_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Jam Berangkat</td>
                                <td class="tk-kv-v"><?= e(substr((string) $row['time'], 0, 5)) ?> WIB</td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Periode Sewa</td>
                                <td class="tk-kv-v"><?= e($periodeDisplay) ?></td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Layanan</td>
                                <td class="tk-kv-v">Sewa Kendaraan<?= !empty($row['category_name']) ? ' &middot; ' . e($row['category_name']) : '' ?></td>
                            </tr>
                            <?php if (!empty($vehicleDisplay) && $vehicleDisplay !== '—'): ?>
                            <tr>
                                <td class="tk-kv-k">Kendaraan</td>
                                <td class="tk-kv-v"><?= e($vehicleDisplay) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="tk-kv-k">Total Harga</td>
                                <td class="tk-kv-v tk-price-val"><?= e(rupiah((float) ($row['invoice_total'] ?? $row['total_price']))) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="tk-kv-k">Nomor Tiket</td>
                                <td class="tk-kv-v"><?= e($row['document_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Rute Perjalanan</td>
                                <td class="tk-kv-v"><?= e(trim(($row['city_from'] ?? '') . ' - ' . ($row['city_to'] ?? ''), ' -') ?: '—') ?></td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Tanggal Berangkat</td>
                                <td class="tk-kv-v"><?= e(tanggal_indo((string) $row['date'])) ?></td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Jam Berangkat</td>
                                <td class="tk-kv-v"><?= e(substr((string) $row['time'], 0, 5)) ?> WIB</td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Total Penumpang</td>
                                <td class="tk-kv-v"><?= count($passengers) ?> orang</td>
                            </tr>
                            <tr>
                                <td class="tk-kv-k">Total Harga</td>
                                <td class="tk-kv-v tk-price-val"><?= e(rupiah((float) ($row['invoice_total'] ?? $row['total_price']))) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </td>
            </tr></table>

            <div class="tk-sect mb-1" style="margin-top: 12pt;">Detail Penumpang</div>
            <table class="table table-bordered tk-tbl mb-0">
                <thead><tr><th style="width:35px" class="text-center">#</th><th style="width:150px">Tipe</th><th style="width:320px">Nama</th><th style="width:220px">Nomor Telepon</th></tr></thead>
                <tbody>
                <?php if (!$passengers): ?>
                <tr>
                    <td class="text-center text-muted">1</td>
                    <td>Orang</td>
                    <td>—</td>
                    <td>—</td>
                </tr>
                <?php else: foreach ($passengers as $i => $p): ?>
                <tr>
                    <td class="text-center text-muted"><?= $i + 1 ?></td>
                    <td><?= (int) $p['is_primary'] === 1 ? 'Orang (utama)' : 'Orang' ?></td>
                    <td><?= e((string) $p['name']) ?></td>
                    <td><?= e((string) ($p['phone_number'] ?? '—') ?: '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <table class="tk-lay w-100 mt-4"><tr>
                <td style="width:50%;padding-right:15px;vertical-align:top">
                    <div class="tk-doc">Alamat / Titik Penjemputan</div>
                    <div class="tk-addr"><?= e($pickupFull) ?></div>
                </td>
                <td style="width:50%;padding-left:15px;vertical-align:top">
                    <div class="tk-doc">Alamat / Titik Pengantaran</div>
                    <div class="tk-addr"><?= e($deliveryFull) ?></div>
                </td>
            </tr></table>

            <?php if ($type !== 'rental'): ?>
            <!-- Syarat & Ketentuan Perjalanan (Travel) -->
            <div class="tk-terms mt-4">
                <div class="tk-terms-title">Syarat &amp; Ketentuan Perjalanan:</div>
                <ul>
                    <li>Penumpang wajib berada di titik penjemputan dan nomor telepon aktif minimal <strong>15–30 menit</strong> sebelum jadwal keberangkatan.</li>
                    <li>Kapasitas bagasi cuma-cuma maksimal 1 tas koper standar + 1 tas jinjing per penumpang. Muatan berlebih wajib konfirmasi terlebih dahulu.</li>
                    <li><strong>Perubahan Jadwal (Reschedule):</strong> Perubahan jam/tanggal perjalanan dapat dilakukan maksimal <strong>H-1 (24 jam)</strong> sebelum keberangkatan sesuai ketersediaan kursi.</li>
                    <li><strong>Pembatalan (Cancel / No-Show):</strong> Pembatalan pada hari keberangkatan atau penumpang tidak dapat dihubungi saat driver tiba di lokasi penjemputan dianggap hangus (non-refundable).</li>
                </ul>
            </div>
            <?php endif; ?>
            </div>

            <!-- FOOTER RESMI (SELALU DI BAWAH) -->
            <div class="tk-footer">
                <?php
                $stampPath = (string) ($row['stamp'] ?? '');
                $stampSrc  = $pdf ? resolve_asset_data_uri($stampPath) : resolve_asset_url($stampPath);
                ?>
                <?php if ($stampSrc !== ''): ?>
                <table class="tk-lay w-100 mb-2"><tr>
                    <td></td>
                    <td class="text-end align-bottom" style="width:170px; vertical-align: bottom !important;">
                        <img class="tk-stamp" src="<?= e($stampSrc) ?>" alt="Stempel <?= e($row['company_name']) ?>" style="vertical-align: bottom;">
                    </td>
                </tr></table>
                <?php endif; ?>

                <div class="tk-ver" style="border-top:1px dashed #c8c8c8;padding-top:8px">
                    <table class="tk-lay w-100"><tr>
                        <td class="align-bottom tk-ver" style="width:58%; vertical-align: bottom !important;">
                            Cek keaslian melalui: <span class="text-break"><?= e($ver_link) ?></span>
                        </td>
                        <td class="text-end align-bottom tk-ver" style="width:42%; vertical-align: bottom !important;">
                            Dibuat oleh <strong><?= e((string) ($row['created_user'] ?: '—')) ?></strong> pada tanggal <strong><?= e(tanggal_indo((string) $row['date'])) ?></strong>
                        </td>
                    </tr></table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</main>
<?php if ($print && $row && !$pdf): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
<?php if ($pdf): ?>
<?php
$html = ob_get_clean();
require_once __DIR__ . '/includes/pdf.php';
pdf_send_or_html(pdf_prepare_screen_html($html, '.no-print'), 'Tiket-' . ($row['document_number'] ?? $no));
?>
<?php endif; ?>
</body>
</html>
