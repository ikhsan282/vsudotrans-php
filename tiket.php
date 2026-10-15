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

$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$print = isset($_GET['print']);
$row = null;
$passengers = [];
if (preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $no)) {
    $type = str_starts_with($no, 'RNT') ? 'rental' : 'travel';
    $table = $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
    // Rute dicetak dari nama kota asal -> tujuan (booking tidak menyimpan nama rute).
    $st = db()->prepare("SELECT b.id, b.document_number, b.date, b.time, b.pickup_address, b.delivery_address,
            b.total_price, b.created_user, b.passenger_id, c.name AS company_name, c.address, c.phone_number, c.email, c.logo,
            i.status AS invoice_status, i.total AS invoice_total, i.invoice_no,
            cp1.name AS city_from, cp2.name AS city_to,
            p.name AS passenger_name, p.phone_number AS passenger_phone
        FROM {$table} b
        JOIN companies c ON c.id = b.company_id
        LEFT JOIN invoices i ON i.document_number = b.document_number
        LEFT JOIN cities cp1 ON cp1.id = b.pickup_point_city_id
        LEFT JOIN cities cp2 ON cp2.id = b.delivery_point_city_id
        LEFT JOIN passengers p ON p.id = b.passenger_id
        WHERE b.document_number = ? LIMIT 1");
    $st->bind_param('s', $no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $passengers = booking_passengers_list($type, (int) $row['id'], (int) $row['passenger_id']);
    }
}

$scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$self_link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . APP_URL_PATH . '/tiket.php?no=' . urlencode($no);
$title = 'Tiket' . ($row ? ' ' . $row['document_number'] : '');
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Tampilan = replika persis contoh Maura: sans default, label abu, nilai bold. */
        .tk-doc { color: #777; letter-spacing: .4px; text-transform: uppercase; }
        .tk-v { font-weight: 700; }
        .tk-table th { background: #f2f2f2; }
        .tk-stamp { width: 118px; }
        /* Logo & gambar dibatasi agar tidak melebar menekan kolom kanan keluar halaman. */
        .tk-logo { height: 58px; width: auto; max-width: 220px; }
        .tk-eti { font-size: 34px; white-space: nowrap; }
        /* Tata cetak berbasis tabel: didukung semua renderer & printer (tanpa flexbox). */
        table.tk-lay { border-collapse: collapse; }
        table.tk-lay > tbody > tr > td { border: 0; padding: 0; vertical-align: top; }
        @media print {
            @page { size: A4; margin: 14mm 13mm; }
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .tk-card { border: 0 !important; box-shadow: none !important; }
            .tk-card, .tk-table tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-body-tertiary">
<div class="no-print container py-2 text-end" style="max-width: 860px;">
    <button class="btn btn-dark btn-sm" onclick="window.print()">Cetak / Simpan PDF</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/index.php">Beranda</a>
</div>
<main class="container py-2" style="max-width: 860px;">
<?php if (!$row): ?>
    <div class="alert alert-warning">Tiket tidak ditemukan. Periksa kembali nomor tiket Anda.</div>
<?php else: ?>
    <div class="card tk-card shadow-sm border-0">
        <div class="card-body">
            <table class="tk-lay w-100"><tr>
                <td class="align-top">
                    <?php if (!empty($row['logo'])): ?>
                        <img src="<?= e(APP_URL . '/' . ltrim((string) $row['logo'], '/')) ?>" alt="<?= e($row['company_name']) ?>" class="tk-logo">
                    <?php else: ?>
                        <div class="fs-4 fw-bold"><?= e($row['company_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-end align-top" style="width:240px"><h1 class="tk-eti" style="margin:0">E-TIKET</h1></td>
            </tr></table>
            <hr>

            <table class="tk-lay w-100"><tr>
                <td class="align-top" style="width:50%;padding-right:24px">
                    <div class="tk-doc">Perusahaan</div>
                    <div class="fw-bold fs-5"><?= e($row['company_name']) ?></div>
                    <div class="text-muted"><?= e((string) $row['address']) ?></div>
                    <div class="text-muted"><?= e((string) $row['phone_number']) ?> &middot; <?= e((string) $row['email']) ?></div>
                </td>
                <td class="align-top">
                    <table class="tk-lay w-100">
                        <tr><td class="tk-doc" style="width:140px">Rute</td><td class="tk-v"><?= e(trim(($row['city_from'] ?? '') . ' - ' . ($row['city_to'] ?? ''), ' -') ?: '—') ?></td></tr>
                        <tr><td class="tk-doc">Nomor Tiket</td><td class="tk-v"><?= e($row['document_number']) ?></td></tr>
                        <tr><td class="tk-doc">Tanggal</td><td class="tk-v"><?= e(tanggal_indo((string) $row['date'])) ?></td></tr>
                        <tr><td class="tk-doc">Jam</td><td class="tk-v"><?= e(substr((string) $row['time'], 0, 5)) ?></td></tr>
                        <tr><td class="tk-doc">Harga</td><td class="tk-v"><?= e(rupiah((float) ($row['invoice_total'] ?? $row['total_price']))) ?></td></tr>
                        <tr><td class="tk-doc">Total Tiket</td><td class="tk-v"><?= count($passengers) ?> orang</td></tr>
                    </table>
                </td>
            </tr></table>

            <div class="fw-bold mt-4 mb-1">Detail Penumpang</div>
            <table class="table table-bordered tk-table mb-0">
                <thead><tr><th style="width:40px">#</th><th style="width:90px">Tipe</th><th>Nama</th><th style="width:160px">Nomor Telepon</th></tr></thead>
                <tbody>
                <?php if (!$passengers): ?>
                <tr>
                    <td class="text-muted">1</td>
                    <td>Orang</td>
                    <td>—</td>
                    <td>—</td>
                </tr>
                <?php else: foreach ($passengers as $i => $p): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>Orang<?= (int) $p['is_primary'] === 1 ? ' (utama)' : '' ?></td>
                    <td><?= e((string) $p['name']) ?></td>
                    <td><?= e((string) ($p['phone_number'] ?? '—') ?: '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
                <tr>
                    <td colspan="3" class="text-end fw-bold">TOTAL</td>
                    <td class="fw-bold"><?= e(rupiah((float) ($row['invoice_total'] ?? $row['total_price']))) ?></td>
                </tr>
                </tbody>
            </table>

            <div class="mt-4">
                <div class="tk-doc">Alamat Penjemputan</div>
                <div class="tk-v small"><?= e((string) ($row['pickup_address'] ?: '—')) ?></div>
                <div class="tk-doc mt-2">Alamat Pengantaran</div>
                <div class="tk-v small"><?= e((string) ($row['delivery_address'] ?: '—')) ?></div>
            </div>

            <table class="tk-lay w-100 mt-4"><tr>
                <td class="align-bottom">
                    <div>Dibuat oleh <strong><?= e((string) ($row['created_user'] ?: '—')) ?></strong></div>
                    <div><span class="text-muted">pada tanggal</span> <strong><?= e(tanggal_indo((string) $row['date'])) ?></strong></div>
                </td>
                <td class="text-center align-bottom" style="width:170px">
                    <img class="tk-stamp" src="<?= e(APP_URL . '/assets/brand/stamp-mtm.png') ?>" alt="Stempel Maura Trans Mandiri">
                </td>
            </tr></table>

            <div class="text-muted mt-4" style="border-top:1px dashed #c8c8c8;padding-top:8px">
                Cek keaslian tiket: <span class="text-break"><?= e($self_link) ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>
</main>
<?php if ($print && $row): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
