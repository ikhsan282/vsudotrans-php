<?php
/**
 * Verifikasi tiket publik: GET /verifikasi.php?no=<document_number>.
 * Hanya menampilkan status keaslian + rute/tanggal/jam. TANPA data pribadi
 * (nama, telepon, alamat) — untuk dibagikan/scan oleh pihak ketiga.
 */
require_once __DIR__ . '/config/config.php';

$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$row = null;
if (preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $no)) {
    $type = str_starts_with($no, 'RNT') ? 'rental' : 'travel';
    $table = $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
    $st = db()->prepare("SELECT b.document_number, b.date, b.time, c.name AS company_name,
            i.status AS invoice_status, cp1.name AS city_from, cp2.name AS city_to
        FROM {$table} b
        JOIN companies c ON c.id = b.company_id
        LEFT JOIN invoices i ON i.document_number = b.document_number
        LEFT JOIN cities cp1 ON cp1.id = b.pickup_point_city_id
        LEFT JOIN cities cp2 ON cp2.id = b.delivery_point_city_id
        WHERE b.document_number = ? LIMIT 1");
    $st->bind_param('s', $no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Tiket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 560px;">
    <div class="card shadow-sm border-0"><div class="card-body p-4 text-center">
        <h5 class="fw-bold mb-3">Verifikasi Tiket</h5>
        <?php if (!$row): ?>
            <div class="alert alert-danger mb-0">Tiket <strong><?= e($no ?: '—') ?></strong> tidak ditemukan / tidak sah.</div>
        <?php else:
            $batal = strtolower((string) $row['invoice_status']) === 'cancelled'; ?>
            <div class="alert alert-<?= $batal ? 'warning' : 'success' ?>">
                <strong><?= $batal ? 'Tiket DIBATALKAN' : 'Tiket SAH' ?></strong>
            </div>
            <table class="table table-sm mb-0 text-start">
                <tr><th style="width:40%">Nomor Tiket</th><td class="fw-bold"><?= e($row['document_number']) ?></td></tr>
                <tr><th>Perusahaan</th><td><?= e($row['company_name']) ?></td></tr>
                <tr><th>Rute</th><td><?= e(trim(($row['city_from'] ?? '') . ' - ' . ($row['city_to'] ?? ''), ' -') ?: '—') ?></td></tr>
                <tr><th>Tanggal</th><td><?= e(tanggal_indo((string) $row['date'])) ?></td></tr>
                <tr><th>Jam</th><td><?= e(substr((string) $row['time'], 0, 5)) ?></td></tr>
            </table>
            <p class="text-muted small mt-3 mb-0">Halaman ini hanya menampilkan status &amp; jadwal. Data penumpang tidak ditampilkan demi privasi.</p>
        <?php endif; ?>
    </div></div>
</main>
</body>
</html>
