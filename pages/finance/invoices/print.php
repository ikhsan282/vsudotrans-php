<?php
/**
 * Cetak invoice: GET pages/finance/invoices/print.php?id=<id>[&print=1].
 * Wajib login + scoped company (invoice bukan dokumen publik seperti tiket).
 * Tata cetak meniru tiket.php: HTML mandiri, <style> sendiri (Bootstrap tidak dimuat),
 * @media print menyembunyikan tombol, ?print=1 memicu window.print() saat load.
 * Rincian biaya diambil dari booking_cost_lines (yang BUKAN cost_type='payment');
 * bila kosong, pakai baris subtotal/pajak/total invoice sebagai rincian.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('invoices.view');
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$id    = (int) ($_GET['id'] ?? 0);
$print = isset($_GET['print']);
$inv = $id > 0 ? invoice_find($id) : null;
if ($inv) {
    assert_company_access((int) $inv['company_id']);
}

$company = null;
$party   = null;      // "Ditagihkan kepada"
$lines   = [];        // rincian biaya (bukan payment)
$payments = [];
$methods  = [];
$qr_method = null;    // metode QRIS pertama yang punya gambar QR

if ($inv) {
    $cid = (int) $inv['company_id'];

    // Perusahaan (nama + alamat + logo) — konstanta dipakai sebagai fallback.
    $st = db()->prepare('SELECT name, address, city, phone_number, email, logo FROM companies WHERE id = ?');
    $st->bind_param('i', $cid);
    $st->execute();
    $company = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    // Pihak tertagih: ambil dari booking (penumpang utama/pelanggan).
    $bt  = (string) $inv['booking_type'];
    $bid = (int) $inv['booking_id'];
    $tbl = booking_table($bt);
    $st = db()->prepare("SELECT b.document_number, b.date, b.pickup_address, b.delivery_address,
            p.name AS party_name, p.phone_number AS party_phone, p.email AS party_email
        FROM {$tbl} b LEFT JOIN passengers p ON p.id = b.passenger_id
        WHERE b.id = ? AND b.company_id = ? LIMIT 1");
    $st->bind_param('ii', $bid, $cid);
    $st->execute();
    $party = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    // Rincian biaya: semua baris booking BUKAN pembayaran (a/b/c/bbm/...).
    $st = db()->prepare("SELECT description, amount, note FROM booking_cost_lines
        WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND cost_type <> 'payment'
        ORDER BY id");
    $st->bind_param('sii', $bt, $bid, $cid);
    $st->execute();
    $lines = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $payments = invoice_payments($id);

    // Metode bayar aktif (milik perusahaan atau global) + gambar QR.
    $st = db()->prepare("SELECT id, code, name, account_name, account_number, instructions,
            (qr_image IS NOT NULL AND qr_image <> '') AS has_qr
        FROM payment_methods
        WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL)
        ORDER BY sort_order, name");
    $st->bind_param('i', $cid);
    $st->execute();
    $methods = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($methods as $m) {
        if ((int) $m['has_qr'] === 1) { $qr_method = $m; break; }
    }
}

$paid      = $inv ? invoice_paid_total($id) : 0.0;
$pay_status = $inv ? invoice_pay_status($inv) : 'unpaid';
$remaining = $inv ? max(0, round((float) $inv['total'] - $paid, 2)) : 0.0;
$logo = $company['logo'] ?? null;
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= e($inv['invoice_no'] ?? '') ?></title>
    <style>
        /* Font & ukuran disamakan dengan tiket.php agar hasil cetak konsisten. */
        body { font-family: "Liberation Sans", Helvetica, Arial, sans-serif; margin: 0; background: #f1f3f5; color: #212529; }
        .iv-doc { color: #777; font-size: 7.5pt; letter-spacing: .4px; text-transform: uppercase; white-space: nowrap; }
        .iv-v { font-weight: 700; font-size: 9pt; line-height: 14pt; }
        .iv-body { font-size: 9pt; line-height: 13pt; }
        .iv-sub { font-size: 10.5pt; font-weight: 700; line-height: 12pt; }
        .iv-addr { font-size: 9pt; font-weight: 700; }
        .iv-sect { font-size: 9.8pt; font-weight: 700; margin-top: 14pt; }
        .iv-title { font-size: 25.5pt; white-space: nowrap; text-align: right; margin: 0; }
        .iv-card { max-width: 820px; margin: 12px auto; background: #fff; padding: 22px 26px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        /* Utilitas yang dipakai halaman ini didefinisikan sendiri (tak bergantung Bootstrap),
           agar tampilan layar & PDF cetak identik di semua lingkungan. */
        .text-end { text-align: right; }
        .text-muted { color: #6c757d; }
        .fw-bold, strong { font-weight: 700; }
        .mt-2 { margin-top: 6pt; }
        .mt-4 { margin-top: 18pt; }
        .mb-0 { margin-bottom: 0; }
        .mb-1 { margin-bottom: 3pt; }
        table.iv-tbl { font-size: 9pt; width: 100%; border-collapse: collapse; }
        table.iv-tbl th, table.iv-tbl td { padding: 5px 8px; vertical-align: top; }
        table.iv-tbl th { background: #f2f2f2; font-size: 8.2pt; text-align: left; }
        table.iv-tbl td, table.iv-tbl th { border: 1px solid #d9d9d9; }
        /* Tata cetak berbasis tabel: didukung semua renderer & printer (tanpa flexbox). */
        table.iv-lay { border-collapse: collapse; table-layout: fixed; width: 100%; }
        table.iv-lay > tbody > tr > td { border: 0; padding: 0; vertical-align: top; }
        /* Logo & QR dibatasi agar tidak melebar menekan kolom lain keluar halaman. */
        .iv-logo { height: 46px; width: auto; max-width: 220px; }
        .iv-qr { height: 150px; width: 150px; object-fit: contain; border: 1px solid #d9d9d9; }
        .iv-no-print { max-width: 820px; margin: 10px auto 0; text-align: right; }
        .iv-btn { display: inline-block; padding: 6px 12px; border-radius: 4px; font-size: 12px; text-decoration: none;
                  background: #212529; color: #fff; border: 1px solid #212529; cursor: pointer; }
        .iv-btn.sec { background: #fff; color: #212529; border-color: #ced4da; }
        @media print {
            @page { size: A4; margin: 13mm; }
            body { background: #fff !important; }
            .iv-no-print { display: none !important; }
            .iv-card { box-shadow: none !important; border-radius: 0 !important; margin: 0 !important; max-width: none !important; padding: 0 !important; }
            .iv-card, table.iv-tbl tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="iv-no-print">
    <button class="iv-btn" onclick="window.print()">Cetak / Simpan PDF</button>
    <a class="iv-btn sec" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= $id ?>">Kembali</a>
</div>
<div class="iv-card">
<?php if (!$inv): ?>
    <div class="iv-sect" style="color:#b02a37">Invoice tidak ditemukan.</div>
<?php else: ?>
    <!-- Kop: logo + judul INVOICE -->
    <table class="iv-lay"><tr>
        <td style="width:60%">
            <?php if ($logo): ?>
                <img class="iv-logo" src="<?= e(APP_URL . '/' . ltrim((string) $logo, '/')) ?>" alt="<?= e((string) ($company['name'] ?? COMPANY_NAME)) ?>">
            <?php else: ?>
                <div class="fw-bold" style="font-size:14pt"><?= e((string) ($company['name'] ?? COMPANY_NAME)) ?></div>
            <?php endif; ?>
        </td>
        <td style="width:40%"><h1 class="iv-title">INVOICE</h1></td>
    </tr></table>
    <hr style="border:0;border-top:1px solid #dee2e6;margin:8px 0 12px">

    <!-- Data perusahaan + nomor invoice -->
    <table class="iv-lay"><tr>
        <td style="width:50%;padding-right:22px">
            <div class="iv-doc">Perusahaan</div>
            <div class="iv-sub"><?= e((string) ($company['name'] ?? COMPANY_NAME)) ?></div>
            <div class="iv-body text-muted"><?= e((string) ($company['address'] ?? COMPANY_ADDRESS)) ?></div>
            <div class="iv-body text-muted"><?= e((string) ($company['phone_number'] ?? COMPANY_PHONE)) ?><?= !empty($company['email']) ? ' &middot; ' . e((string) $company['email']) : '' ?></div>
        </td>
        <td style="width:50%">
            <table class="iv-lay">
                <tr><td class="iv-doc" style="width:120px">No. Invoice</td><td class="iv-v"><?= e($inv['invoice_no']) ?></td></tr>
                <tr><td class="iv-doc">Tanggal Terbit</td><td class="iv-v"><?= e(tanggal_indo((string) $inv['issued_at'])) ?></td></tr>
                <tr><td class="iv-doc">Resi Booking</td><td class="iv-v"><?= e((string) $inv['document_number']) ?></td></tr>
                <tr><td class="iv-doc">Status</td><td class="iv-v"><?= e(['unpaid' => 'Belum Bayar', 'partial' => 'Sebagian', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'][$pay_status] ?? $pay_status) ?></td></tr>
            </table>
        </td>
    </tr></table>

    <!-- Ditagihkan kepada -->
    <div class="iv-sect mb-1">Ditagihkan Kepada</div>
    <table class="iv-lay"><tr>
        <td style="width:50%">
            <div class="iv-sub"><?= e((string) ($party['party_name'] ?? '—')) ?></div>
            <div class="iv-body text-muted"><?= e((string) ($party['party_phone'] ?? '')) ?><?= !empty($party['party_email']) ? ' &middot; ' . e((string) $party['party_email']) : '' ?></div>
        </td>
        <td style="width:50%">
            <div class="iv-doc">Penjemputan</div>
            <div class="iv-body"><?= e((string) ($party['pickup_address'] ?? '') ?: '—') ?></div>
            <div class="iv-doc mt-2">Pengantaran</div>
            <div class="iv-body"><?= e((string) ($party['delivery_address'] ?? '') ?: '—') ?></div>
        </td>
    </tr></table>

    <!-- Rincian biaya -->
    <div class="iv-sect mb-1">Rincian Biaya</div>
    <table class="iv-tbl mb-0">
        <thead><tr><th style="width:32px">#</th><th>Deskripsi</th><th class="text-end" style="width:140px">Jumlah</th></tr></thead>
        <tbody>
        <?php if ($lines): $n = 0; foreach ($lines as $l): $n++; ?>
            <tr>
                <td class="text-muted"><?= $n ?></td>
                <td><?= e((string) ($l['description'] ?: $l['note'] ?: '—')) ?></td>
                <td class="text-end"><?= e(rupiah((float) $l['amount'])) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td class="text-muted">1</td><td>Subtotal</td><td class="text-end"><?= e(rupiah((float) $inv['subtotal'])) ?></td></tr>
            <?php if ((float) $inv['tax_amount'] != 0.0): ?>
            <tr><td class="text-muted">2</td><td>Pajak (<?= e((string) $inv['tax_percent']) ?>%)</td><td class="text-end"><?= e(rupiah((float) $inv['tax_amount'])) ?></td></tr>
            <?php endif; ?>
        <?php endif; ?>
            <tr class="fw-bold"><td colspan="2" class="text-end">TOTAL</td><td class="text-end"><?= e(rupiah((float) $inv['total'])) ?></td></tr>
            <tr><td colspan="2" class="text-end">Dibayar</td><td class="text-end"><?= e(rupiah($paid)) ?></td></tr>
            <tr class="fw-bold"><td colspan="2" class="text-end">Sisa Tagihan</td><td class="text-end"><?= e(rupiah($remaining)) ?></td></tr>
        </tbody>
    </table>

    <!-- Riwayat pembayaran -->
    <div class="iv-sect mb-1">Riwayat Pembayaran</div>
    <table class="iv-tbl mb-0">
        <thead><tr><th style="width:32px">#</th><th style="width:140px">Tanggal</th><th class="text-end" style="width:140px">Nominal</th><th>Catatan</th><th style="width:110px">Oleh</th></tr></thead>
        <tbody>
        <?php if ($payments): $i = 0; foreach ($payments as $pay): $i++; ?>
            <tr>
                <td class="text-muted"><?= $i ?></td>
                <td><?= e(substr((string) $pay['created_date'], 0, 16)) ?></td>
                <td class="text-end"><?= e(rupiah((float) $pay['amount'])) ?></td>
                <td><?= e((string) ($pay['note'] ?: $pay['description'])) ?></td>
                <td><?= e((string) ($pay['created_user'] ?? '')) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-muted">Belum ada pembayaran.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Cara pembayaran -->
    <div class="iv-sect mb-1">Cara Pembayaran</div>
    <table class="iv-lay"><tr>
        <td style="width:<?= $qr_method ? '58%' : '100%' ?><?= $qr_method ? ';padding-right:18px' : '' ?>">
            <?php if ($methods): ?>
                <?php foreach ($methods as $m): ?>
                    <div class="iv-body" style="margin-bottom:8px">
                        <div class="fw-bold"><?= e($m['name']) ?></div>
                        <div class="text-muted">
                            <?php if (!empty($m['account_number'])): ?>
                                <?= e((string) ($m['account_name'] ?? '')) ?><?= !empty($m['account_name']) ? ' &middot; ' : '' ?><?= e((string) $m['account_number']) ?>
                            <?php endif; ?>
                            <?php if (!empty($m['code'])): ?><span class="iv-doc"><?= e((string) $m['code']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($m['instructions'])): ?>
                            <div class="text-muted" style="font-size:8pt"><?= nl2br(e((string) $m['instructions'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="iv-body text-muted">Belum ada metode pembayaran aktif — hubungi admin.</div>
            <?php endif; ?>
        </td>
        <?php if ($qr_method): ?>
        <td style="width:42%" class="text-end">
            <img class="iv-qr" src="<?= e(APP_URL) ?>/pages/finance/payment_methods/qr.php?id=<?= (int) $qr_method['id'] ?>" alt="QR <?= e((string) $qr_method['name']) ?>">
            <div class="iv-doc mt-2 text-left" style="text-align:left">Scan QRIS</div>
        </td>
        <?php endif; ?>
    </tr></table>

    <?php if (!empty($inv['note'])): ?>
    <div class="iv-body text-muted mt-4">Catatan: <?= e((string) $inv['note']) ?></div>
    <?php endif; ?>

    <div class="iv-body text-muted mt-4" style="border-top:1px dashed #c8c8c8;padding-top:8px">
        Invoice ini dicetak dari sistem <?= e(APP_NAME) ?>. Bukti sah setelah pembayaran terverifikasi.
    </div>
<?php endif; ?>
</div>
<?php if ($print && $inv): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
