<?php
/**
 * Tiket publik: GET /tiket.php?no=<document_number>. Tanpa login.
 * Hanya tampilkan tiket & total. JANGAN tampilkan booking_cost_lines / premi.
 * Nomor tidak ditemukan -> pesan generik (tidak membocorkan keberadaan nomor).
 */
require_once __DIR__ . '/config/config.php';

$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$row = null;
if (preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $no)) {
    $type = str_starts_with($no, 'RNT') ? 'rental' : 'travel';
    $table = $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
    $st = db()->prepare("SELECT b.document_number, b.date, b.time, b.pickup_address, b.delivery_address,
            b.total_price, c.name AS company_name, c.address, c.phone_number, c.email, c.logo,
            i.status AS invoice_status, i.total AS invoice_total, i.invoice_no
        FROM {$table} b
        JOIN companies c ON c.id = b.company_id
        LEFT JOIN invoices i ON i.document_number = b.document_number
        WHERE b.document_number = ? LIMIT 1");
    $st->bind_param('s', $no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
}
$title = 'Tiket';
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tiket — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width: 640px;">
<?php if (!$row): ?>
    <div class="alert alert-warning">Tiket tidak ditemukan. Periksa kembali nomor tiket Anda.</div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php if (!empty($row['logo'])): ?>
                <img src="<?= e(APP_URL . '/' . ltrim((string) $row['logo'], '/')) ?>" alt="" height="48" class="mb-2">
            <?php endif; ?>
            <h5 class="fw-bold"><?= e($row['company_name']) ?></h5>
            <div class="small text-muted mb-3">
                <?= e($row['address'] ?? '') ?><br>
                <?= e($row['phone_number'] ?? '') ?> <?= e($row['email'] ?? '') ?>
            </div>
            <dl class="row mb-0">
                <dt class="col-5">No. Resi</dt><dd class="col-7 fw-semibold"><?= e($row['document_number']) ?></dd>
                <dt class="col-5">Tanggal</dt><dd class="col-7"><?= e($row['date']) ?> <?= e(substr((string) $row['time'], 0, 5)) ?></dd>
                <dt class="col-5">Jemput</dt><dd class="col-7"><?= e($row['pickup_address'] ?? '—') ?></dd>
                <dt class="col-5">Antar</dt><dd class="col-7"><?= e($row['delivery_address'] ?? '—') ?></dd>
                <dt class="col-5">Total</dt><dd class="col-7 fw-bold"><?= e(rupiah((float) ($row['invoice_total'] ?? $row['total_price']))) ?></dd>
                <dt class="col-5">Status</dt><dd class="col-7"><?= e($row['invoice_status'] === 'cancelled' ? 'Dibatalkan' : 'Terbit') ?></dd>
            </dl>
        </div>
    </div>
<?php endif; ?>
</main>
</body>
</html>
