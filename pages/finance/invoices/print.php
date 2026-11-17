<?php
/**
 * Cetak invoice: GET pages/finance/invoices/print.php?id=<id>[&print=1|&format=pdf].
 * Wajib login + scoped company (invoice bukan dokumen publik seperti tiket).
 * Tata cetak HTML mandiri (<style> sendiri, berbasis tabel agar layar & Dompdf A4 identik).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('invoices.view');
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$id    = (int) ($_GET['id'] ?? 0);
$print = isset($_GET['print']);
$pdf   = ($_GET['format'] ?? '') === 'pdf';
$inv   = $id > 0 ? invoice_find($id) : null;
if ($inv) {
    assert_company_access((int) $inv['company_id']);
}

$company  = null;
$party    = null;
$payments = [];

if ($inv) {
    $cid = (int) $inv['company_id'];

    $st = db()->prepare('SELECT name, address, city, phone_number, email, logo, stamp FROM companies WHERE id = ?');
    $st->bind_param('i', $cid);
    $st->execute();
    $company = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    $bt        = (string) $inv['booking_type'];
    $bid       = (int) $inv['booking_id'];
    $tbl       = booking_table($bt);
    $extraCols = $bt === 'rental' ? 'b.start_date, b.end_date, b.trip,' : "NULL AS start_date, NULL AS end_date, NULL AS trip,";
    $st = db()->prepare("SELECT b.document_number, b.date, b.time, {$extraCols} b.pickup_address, b.delivery_address,
            cv.name AS category_name, v.vehicle_number,
            p.name AS party_name, p.phone_number AS party_phone, p.email AS party_email
        FROM {$tbl} b
        LEFT JOIN passengers p ON p.id = b.passenger_id
        LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
        LEFT JOIN vehicles v ON v.id = b.vehicle_id
        WHERE b.id = ? AND b.company_id = ? LIMIT 1");
    $st->bind_param('ii', $bid, $cid);
    $st->execute();
    $party = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    $payments = invoice_payments($id);
}

$paid       = $inv ? invoice_paid_total($id) : 0.0;
$pay_status = $inv ? invoice_pay_status($inv) : 'unpaid';
$remaining  = $inv ? max(0, round((float) $inv['total'] - $paid, 2)) : 0.0;
$logo       = $company['logo'] ?? null;

$statusLabels = [
    'unpaid'    => 'BELUM BAYAR',
    'partial'   => 'DIBAYAR SEBAGIAN',
    'paid'      => 'LUNAS',
    'cancelled' => 'DIBATALKAN',
];
$statusStyles = [
    'unpaid'    => 'background:#fef2f2;color:#b91c1c;border:1.2px solid #fca5a5;',
    'partial'   => 'background:#fffbeb;color:#b45309;border:1.2px solid #fcd34d;',
    'paid'      => 'background:#ecfdf5;color:#047857;border:1.2px solid #6ee7b7;',
    'cancelled' => 'background:#f3f4f6;color:#4b5563;border:1.2px solid #d1d5db;',
];
$statusLabel = $statusLabels[$pay_status] ?? $pay_status;
$statusStyle = $statusStyles[$pay_status] ?? $statusStyles['cancelled'];

$itemTitle = 'Layanan Transportasi';
$itemMeta  = '';
if ($inv && $party) {
    if ($inv['booking_type'] === 'rental') {
        $itemTitle = 'Sewa Kendaraan' . (!empty($party['category_name']) ? ' — ' . $party['category_name'] : '');
        $dStart    = (string) ($party['start_date'] ?: $party['date'] ?: '');
        $dEnd      = (string) ($party['end_date'] ?: $dStart);
        $parts     = [];
        if ($dStart !== '') {
            $parts[] = tanggal_indo($dStart) . ($dEnd !== '' && $dEnd !== $dStart ? ' s/d ' . tanggal_indo($dEnd) : '');
        }
        if ((int) ($party['trip'] ?? 0) > 0) {
            $parts[] = (int) $party['trip'] . ' hari';
        }
        if (!empty($party['vehicle_number'])) {
            $parts[] = 'Unit ' . $party['vehicle_number'];
        }
        $itemMeta = implode(' · ', $parts);
    } else {
        $itemTitle = 'Tiket Perjalanan Travel' . (!empty($party['category_name']) ? ' — ' . $party['category_name'] : '');
        $parts     = [];
        if (!empty($party['date'])) {
            $parts[] = tanggal_indo((string) $party['date']) . (!empty($party['time']) ? ' ' . substr((string) $party['time'], 0, 5) : '');
        }
        if (!empty($party['vehicle_number'])) {
            $parts[] = 'Unit ' . $party['vehicle_number'];
        }
        $itemMeta = implode(' · ', $parts);
    }
}
?>
<?php if ($pdf) { ob_start(); } ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= e($inv['invoice_no'] ?? '') ?></title>
    <style>
        /* Tata letak murni tabel + box sederhana: konsisten di browser print & Dompdf A4. */
        body { font-family: "Liberation Sans", "DejaVu Sans", Helvetica, Arial, sans-serif; margin: 0; background: #eef1f5; color: #1f2937; font-size: 9pt; line-height: 1.45; }
        .iv-lbl { color: #6b7280; font-size: 7.4pt; letter-spacing: .5px; text-transform: uppercase; white-space: nowrap; }
        .iv-val { font-weight: 700; font-size: 9pt; color: #111827; }
        .iv-body { font-size: 9pt; color: #1f2937; }
        .iv-sub { font-size: 10.5pt; font-weight: 700; color: #111827; margin: 2pt 0; }
        .iv-sect { font-size: 9.2pt; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #374151; margin: 14pt 0 5pt; }
        .iv-title { font-size: 24pt; font-weight: 800; letter-spacing: 1px; text-align: right; margin: 0; color: #0f2942; }
        .iv-card { max-width: 790px; margin: 14px auto 24px; background: #fff; padding: 26px 30px; border-radius: 6px; box-shadow: 0 2px 8px rgba(15, 23, 42, .08); }
        .iv-status { display: inline-block; padding: 2.5px 9px; border-radius: 4px; font-size: 7.8pt; font-weight: 700; letter-spacing: .6px; line-height: 1.25; }
        .iv-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 9px 12px; }
        .iv-terms { background: #f8fafc; border-left: 3px solid #0f2942; padding: 9px 12px; margin-top: 14pt; font-size: 8.6pt; color: #334155; }
        .text-end { text-align: right; }
        .text-muted { color: #6b7280; }
        .fw-bold, strong { font-weight: 700; }
        .mt-2 { margin-top: 6pt; }
        .mb-1 { margin-bottom: 3pt; }
        table.iv-tbl { font-size: 8.8pt; width: 100%; border-collapse: collapse; }
        table.iv-tbl th, table.iv-tbl td { padding: 6px 9px; vertical-align: top; border: 1px solid #d1d5db; }
        table.iv-tbl th { background: #f1f5f9; color: #334155; font-size: 8pt; text-transform: uppercase; letter-spacing: .3px; text-align: left; }
        table.iv-tbl tr.iv-sum td { background: #f8fafc; font-weight: 700; }
        table.iv-lay { border-collapse: collapse; table-layout: fixed; width: 100%; }
        table.iv-lay > tbody > tr > td, table.iv-lay > tr > td { border: 0; padding: 0; vertical-align: top; }
        table.iv-lay.iv-meta > tbody > tr > td, table.iv-lay.iv-meta > tr > td { padding: 3pt 0; vertical-align: middle; }
        table.iv-lay.iv-meta > tbody > tr.iv-meta-status > td, table.iv-lay.iv-meta > tr.iv-meta-status > td { border-top: 1px solid #e2e8f0; padding-top: 5.5pt; }
        .iv-logo { height: 46px; width: auto; max-width: 220px; }
        .iv-no-print { max-width: 790px; margin: 14px auto 0; text-align: right; }
        .iv-btn { display: inline-block; padding: 6px 13px; border-radius: 4px; font-size: 12px; font-weight: 600; text-decoration: none;
                  background: #0f2942; color: #fff; border: 1px solid #0f2942; cursor: pointer; margin-left: 4px; }
        .iv-btn.sec { background: #fff; color: #1f2937; border-color: #cbd5e1; }
        @media print {
            @page { size: A4; margin: 13mm; }
            body { background: #fff !important; }
            .iv-no-print { display: none !important; }
            .iv-card { box-shadow: none !important; border-radius: 0 !important; margin: 0 !important; max-width: none !important; padding: 0 !important; }
            table.iv-tbl tr, .iv-box, .iv-terms { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<?php
$backBookUrl = $inv
    ? (APP_URL . '/pages/' . ($inv['booking_type'] === 'rental' ? 'rental' : 'travel') . '/bookings/detail.php?id=' . (int) $inv['booking_id'] . '&tab=payment#tab-payment')
    : (APP_URL . '/pages/dashboard/index.php');
?>
<div class="iv-no-print">
    <button class="iv-btn" onclick="window.print()">Cetak / Simpan PDF</button>
    <a class="iv-btn sec" href="<?= e($backBookUrl) ?>">Kembali ke Booking</a>
</div>

<div class="iv-card">
<?php if (!$inv): ?>
    <div class="iv-sect" style="color:#b02a37">Invoice tidak ditemukan.</div>
<?php else: ?>
    <!-- Kop: logo + judul INVOICE -->
    <table class="iv-lay"><tr>
        <td style="width:60%;vertical-align:middle">
            <?php if ($logo): ?>
                <img class="iv-logo" src="<?= e(resolve_asset_url((string) $logo)) ?>" alt="<?= e((string) ($company['name'] ?? COMPANY_NAME)) ?>">
            <?php else: ?>
                <div class="fw-bold" style="font-size:14pt;color:#0f2942"><?= e((string) ($company['name'] ?? COMPANY_NAME)) ?></div>
            <?php endif; ?>
        </td>
        <td style="width:40%;vertical-align:middle"><h1 class="iv-title">INVOICE</h1></td>
    </tr></table>
    <hr style="border:0;border-top:1.5px solid #cbd5e1;margin:10px 0 14px">

    <!-- Identitas Perusahaan + Ringkasan Nomor Invoice -->
    <table class="iv-lay"><tr>
        <td style="width:55%;padding-right:20px">
            <div class="iv-lbl">Diterbitkan Oleh</div>
            <div class="iv-sub"><?= e((string) ($company['name'] ?? COMPANY_NAME)) ?></div>
            <div class="iv-body text-muted"><?= e((string) ($company['address'] ?? COMPANY_ADDRESS)) ?></div>
            <div class="iv-body text-muted">
                <?= e((string) ($company['phone_number'] ?? COMPANY_PHONE)) ?>
                <?= !empty($company['email']) ? ' &middot; ' . e((string) $company['email']) : '' ?>
            </div>
        </td>
        <td style="width:45%">
            <div class="iv-box">
                <table class="iv-lay iv-meta">
                    <tbody>
                        <tr><td class="iv-lbl" style="width:110px">No. Invoice</td><td class="iv-val"><?= e((string) $inv['invoice_no']) ?></td></tr>
                        <tr><td class="iv-lbl">Tanggal Terbit</td><td class="iv-val"><?= e(tanggal_indo((string) $inv['issued_at'])) ?></td></tr>
                        <tr><td class="iv-lbl" style="padding-bottom:5pt">Resi Booking</td><td class="iv-val" style="padding-bottom:5pt"><?= e((string) $inv['document_number']) ?></td></tr>
                        <tr class="iv-meta-status">
                            <td class="iv-lbl">Status</td>
                            <td><span class="iv-status" style="<?= $statusStyle ?>"><?= e($statusLabel) ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </td>
    </tr></table>

    <!-- Ditagihkan Kepada + Rute / Jadwal -->
    <div class="iv-sect">Ditagihkan Kepada &amp; Rincian Perjalanan</div>
    <div class="iv-box">
        <table class="iv-lay"><tr>
            <td style="width:46%;padding-right:16px">
                <div class="iv-lbl">Pelanggan</div>
                <div class="iv-sub"><?= e((string) ($party['party_name'] ?? '—')) ?></div>
                <div class="iv-body text-muted">
                    <?= e((string) ($party['party_phone'] ?? '')) ?>
                    <?= !empty($party['party_email']) ? ' &middot; ' . e((string) $party['party_email']) : '' ?>
                </div>
            </td>
            <td style="width:54%">
                <div class="iv-lbl">Penjemputan</div>
                <div class="iv-body"><?= e((string) ($party['pickup_address'] ?? '') ?: '—') ?></div>
                <div class="iv-lbl mt-2">Pengantaran</div>
                <div class="iv-body"><?= e((string) ($party['delivery_address'] ?? '') ?: '—') ?></div>
            </td>
        </tr></table>
    </div>

    <!-- Rincian Tagihan -->
    <div class="iv-sect">Rincian Tagihan</div>
    <table class="iv-tbl">
        <thead>
            <tr>
                <th style="width:34px;text-align:center">#</th>
                <th>Deskripsi Layanan</th>
                <th style="width:150px;text-align:right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-muted" style="text-align:center">1</td>
                <td>
                    <strong><?= e($itemTitle) ?></strong>
                    <?php if ($itemMeta !== ''): ?>
                        <div class="text-muted" style="font-size:8.2pt;margin-top:2pt"><?= e($itemMeta) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= e(rupiah((float) $inv['subtotal'])) ?></td>
            </tr>
            <?php if ((float) $inv['tax_amount'] != 0.0): ?>
            <tr>
                <td class="text-muted" style="text-align:center">2</td>
                <td>Pajak (<?= e((string) $inv['tax_percent']) ?>%)</td>
                <td class="text-end"><?= e(rupiah((float) $inv['tax_amount'])) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="iv-sum">
                <td colspan="2" class="text-end">Total Tagihan</td>
                <td class="text-end"><?= e(rupiah((float) $inv['total'])) ?></td>
            </tr>
            <tr>
                <td colspan="2" class="text-end text-muted">Sudah Dibayar</td>
                <td class="text-end"><?= e(rupiah($paid)) ?></td>
            </tr>
            <tr class="iv-sum">
                <td colspan="2" class="text-end">Sisa Tagihan</td>
                <td class="text-end" style="color:<?= $remaining > 0 ? '#b02a37' : '#0f5132' ?>"><?= e(rupiah($remaining)) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Riwayat Pembayaran -->
    <div class="iv-sect">Riwayat Pembayaran</div>
    <table class="iv-tbl">
        <thead>
            <tr>
                <th style="width:34px;text-align:center">#</th>
                <th style="width:130px">Tanggal</th>
                <th>Keterangan / Metode</th>
                <th style="width:95px">Oleh</th>
                <th style="width:150px;text-align:right">Nominal</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($payments): $i = 0; foreach ($payments as $pay): $i++;
            $pDt = substr((string) $pay['created_date'], 0, 10);
            $pTm = substr((string) $pay['created_date'], 11, 5);
        ?>
            <tr>
                <td class="text-muted" style="text-align:center"><?= $i ?></td>
                <td><?= e(($pDt !== '' ? date('d M Y', strtotime($pDt)) : '') . ($pTm !== '' ? ' ' . $pTm : '')) ?></td>
                <td><?= e((string) ($pay['note'] ?: $pay['description'])) ?></td>
                <td><?= e((string) ($pay['created_user'] ?? '')) ?></td>
                <td class="text-end fw-bold"><?= e(rupiah((float) $pay['amount'])) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-muted">Belum ada pembayaran tercatat.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Ketentuan & Catatan Invoice -->
    <div class="iv-terms">
        <div class="fw-bold" style="color:#0f2942;margin-bottom:3pt">Ketentuan &amp; Catatan Invoice</div>
        <?php if (!empty($inv['note'])): ?>
            <div style="margin-bottom:4pt"><?= nl2br(e((string) $inv['note'])) ?></div>
        <?php endif; ?>
        <div class="text-muted">
            Tagihan diterbitkan otomatis untuk pemesanan <strong><?= e((string) $inv['document_number']) ?></strong>. Pembayaran dianggap sah setelah dana diterima atau QRIS terverifikasi.
        </div>
    </div>

    <div class="text-muted" style="border-top:1px dashed #cbd5e1;margin-top:14pt;padding-top:7pt;font-size:8pt;text-align:center">
        Invoice ini diterbitkan oleh <?= e((string) ($company['name'] ?? COMPANY_NAME)) ?> melalui sistem <?= e(APP_NAME) ?>.
    </div>
<?php endif; ?>
</div>
<?php if ($print && $inv): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
<?php if ($pdf): ?>
<?php
$html = ob_get_clean();
require_once __DIR__ . '/../../../includes/pdf.php';
if ($inv) { audit('invoices.print', 'Invoice ' . $inv['invoice_no'] . ' (PDF)'); }
pdf_send_or_html(pdf_prepare_screen_html($html, '.iv-no-print'), 'Invoice ' . ($inv['invoice_no'] ?? $id));
?>
<?php endif; ?>
</body>
</html>
