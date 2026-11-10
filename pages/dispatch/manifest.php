<?php
/**
 * Surat Jalan & Manifest Penumpang Driver:
 * GET /pages/dispatch/manifest.php?id=<assignment_id>[&print=1][&format=pdf]
 *
 * Menampilkan dokumen resmi operasional perjalanan untuk dibawa driver:
 * identitas tenant/perusahaan, nomor penugasan, armada, driver, rute,
 * dan daftar urutan penumpang beserta titik jemput/antar dan status pembayaran.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.view');
require_once __DIR__ . '/../../includes/booking_service.php';
require_once __DIR__ . '/../../includes/pdf.php';

$id    = (int) ($_GET['id'] ?? 0);
$print = isset($_GET['print']);
$pdf   = ($_GET['format'] ?? '') === 'pdf';
$cid   = require_active_company();

if ($pdf) {
    ob_start();
}

$st = db()->prepare("SELECT a.*, e.code AS employee_code, e.name AS employee_name, e.phone_number AS employee_phone,
                            v.vehicle_number, v.vehicle_color,
                            b.name AS brand_name, m.name AS model_name
                     FROM trip_assignments a
                     JOIN employees e ON e.id = a.employee_id
                     LEFT JOIN vehicles v ON v.id = a.vehicle_id
                     LEFT JOIN brand_vehicles b ON b.id = v.brand_id
                     LEFT JOIN model_units m ON m.id = v.model_unit_id
                     WHERE a.id = ? AND a.company_id = ? LIMIT 1");
$st->bind_param('ii', $id, $cid);
$st->execute();
$assign = $st->get_result()->fetch_assoc();
$st->close();

if (!$assign) {
    if ($pdf) ob_end_clean();
    flash('error', 'Penugasan tidak ditemukan.');
    redirect('pages/dispatch/index.php');
}

// Data Perusahaan
$st = db()->prepare('SELECT code, name, address, city, phone_number, email, logo FROM companies WHERE id = ?');
$st->bind_param('i', $cid);
$st->execute();
$company = $st->get_result()->fetch_assoc();
$st->close();

$type = $assign['booking_type'];
$bid  = (int) $assign['booking_id'];
$booking = booking_find($type, $bid);

if (!$booking) {
    if ($pdf) ob_end_clean();
    flash('error', 'Data booking perjalanan tidak ditemukan.');
    redirect('pages/dispatch/index.php');
}

// Penumpang lengkap
$passengers = [];
$pst = db()->prepare("SELECT p.id, p.name, p.phone_number, bp.is_primary
                      FROM booking_passengers bp
                      JOIN passengers p ON p.id = bp.passenger_id
                      WHERE bp.booking_type = ? AND bp.booking_id = ?
                      ORDER BY bp.is_primary DESC, bp.id ASC");
$pst->bind_param('si', $type, $bid);
$pst->execute();
$passengers = $pst->get_result()->fetch_all(MYSQLI_ASSOC);
$pst->close();

// Jika daftar penumpang kosong, fallback ke penumpang utama booking
if (!$passengers && !empty($booking['passenger_id'])) {
    $pst = db()->prepare('SELECT id, name, phone_number FROM passengers WHERE id = ?');
    $pst->bind_param('i', $booking['passenger_id']);
    $pst->execute();
    $pRow = $pst->get_result()->fetch_assoc();
    $pst->close();
    if ($pRow) {
        $pRow['is_primary'] = 1;
        $passengers[] = $pRow;
    }
}

$sjNumber = 'SJ/' . strtoupper($type) . '/' . date('Ymd', strtotime($assign['started_at'])) . '/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
$total = (float) ($booking['total_price'] ?? 0);
$paid  = (float) ($booking['total_payment'] ?? 0);
$sisa  = max(0, $total - $paid);
$isLunas = $sisa <= 0;

$vehicleDesc = trim(($assign['brand_name'] ?? '') . ' ' . ($assign['model_name'] ?? ''));
if (!empty($assign['vehicle_number'])) {
    $vehicleDesc .= ' [' . $assign['vehicle_number'] . ']';
}
if (!empty($assign['vehicle_color'])) {
    $vehicleDesc .= ' (' . $assign['vehicle_color'] . ')';
}

$backUrl = APP_URL . '/pages/dispatch/detail.php?type=' . urlencode($type) . '&id=' . $bid;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan — <?= e($sjNumber) ?></title>
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1e293b;
            background: #f1f5f9;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .company-title {
            font-size: 16pt;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .company-meta {
            font-size: 9pt;
            color: #475569;
            line-height: 1.4;
        }
        .doc-badge {
            text-align: right;
        }
        .doc-title {
            font-size: 14pt;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-no {
            font-family: monospace;
            font-size: 10pt;
            color: #334155;
            margin-top: 4px;
            font-weight: bold;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .info-row {
            display: flex;
            font-size: 9.5pt;
            margin-bottom: 4px;
        }
        .info-label {
            width: 130px;
            color: #64748b;
            flex-shrink: 0;
        }
        .info-val {
            font-weight: 600;
            color: #0f172a;
        }
        .manifest-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9.5pt;
        }
        .manifest-table th, .manifest-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
        }
        .manifest-table th {
            background: #f1f5f9;
            font-weight: bold;
            color: #1e293b;
        }
        .tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
        }
        .tag-success { background: #dcfce7; color: #166534; }
        .tag-warning { background: #fef3c7; color: #92400e; }
        .sig-section {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .sig-box {
            width: 45%;
            text-align: center;
            font-size: 9.5pt;
        }
        .sig-line {
            margin-top: 65px;
            border-top: 1px dashed #475569;
            padding-top: 4px;
            font-weight: bold;
        }
        .actions-bar {
            max-width: 800px;
            margin: 0 auto 16px auto;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-primary { background: #0f172a; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-secondary { background: #fff; border-color: #cbd5e1; color: #334155; }
        @media print {
            body { background: #fff; padding: 0; }
            .container { box-shadow: none; padding: 0; width: 100%; max-width: 100%; }
            .actions-bar { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="actions-bar">
        <a href="<?= e($backUrl) ?>" class="btn btn-secondary">&larr; Kembali</a>
        <a href="?id=<?= $id ?>&format=pdf" class="btn btn-danger">&#128196; Unduh PDF</a>
        <button onclick="window.print()" class="btn btn-primary">&#128438; Cetak Surat Jalan</button>
    </div>

    <div class="container">
        <div class="header">
            <div>
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= e(resolve_asset_url($company['logo'])) ?>" alt="Logo" style="max-height: 45px; margin-bottom: 6px;"><br>
                <?php endif; ?>
                <div class="company-title"><?= e($company['name'] ?: APP_NAME) ?></div>
                <div class="company-meta">
                    <?= e((string) $company['address']) ?><?= !empty($company['city']) ? ', ' . e($company['city']) : '' ?><br>
                    Telp / WhatsApp: <?= e((string) $company['phone_number']) ?> &middot; Email: <?= e((string) $company['email']) ?>
                </div>
            </div>
            <div class="doc-badge">
                <div class="doc-title">Surat Jalan Driver</div>
                <div class="doc-no"><?= e($sjNumber) ?></div>
                <div style="font-size: 8.5pt; color: #64748b; margin-top: 4px;">Tgl Cetak: <?= date('d/m/Y H:i') ?></div>
            </div>
        </div>

        <div class="info-grid">
            <div>
                <div class="info-row"><span class="info-label">No. Resi Booking:</span><span class="info-val"><?= e((string) $booking['document_number']) ?></span></div>
                <div class="info-row"><span class="info-label">Layanan:</span><span class="info-val"><?= strtoupper(e($type)) ?></span></div>
                <div class="info-row"><span class="info-label">Tgl Berangkat:</span><span class="info-val"><?= e(date('d F Y', strtotime((string) $booking['date']))) ?></span></div>
                <div class="info-row"><span class="info-label">Jam Keberangkatan:</span><span class="info-val"><?= e(substr((string) $booking['time'], 0, 5)) ?> WIB</span></div>
            </div>
            <div>
                <div class="info-row"><span class="info-label">Nama Driver:</span><span class="info-val"><?= e($assign['employee_name']) ?> (<?= e($assign['employee_code']) ?>)</span></div>
                <div class="info-row"><span class="info-label">No. HP Driver:</span><span class="info-val"><?= e((string) $assign['employee_phone'] ?: '-') ?></span></div>
                <div class="info-row"><span class="info-label">Armada Unit:</span><span class="info-val"><?= e($vehicleDesc ?: '-') ?></span></div>
                <div class="info-row"><span class="info-label">Waktu Mulai Tugas:</span><span class="info-val"><?= e(date('d/m/Y H:i', strtotime($assign['started_at']))) ?></span></div>
            </div>
        </div>

        <div style="margin-bottom: 8px; font-weight: bold; font-size: 10pt; color: #0f172a;">
            DAFTAR MANIFEST PENUMPANG (<?= count($passengers) ?> ORANG)
        </div>
        <table class="manifest-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">No</th>
                    <th>Nama Penumpang</th>
                    <th style="width: 120px;">No. Telepon</th>
                    <th>Titik Jemput (Pickup)</th>
                    <th>Titik Antar (Drop-off)</th>
                    <th style="width: 90px; text-align: center;">Status Bayar</th>
                    <th style="width: 70px; text-align: center;">Paraf</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($passengers as $idx => $p): ?>
                <tr>
                    <td style="text-align: center;"><?= $idx + 1 ?></td>
                    <td style="font-weight: 600;">
                        <?= e($p['name']) ?>
                        <?php if (!empty($p['is_primary'])): ?><span style="font-size: 7.5pt; color: #64748b;">(Pemesan)</span><?php endif; ?>
                    </td>
                    <td><?= e($p['phone_number'] ?: '-') ?></td>
                    <td style="font-size: 8.5pt;"><?= e((string) $booking['pickup_address']) ?></td>
                    <td style="font-size: 8.5pt;"><?= e((string) $booking['delivery_address']) ?></td>
                    <td style="text-align: center;">
                        <?php if ($isLunas): ?>
                            <span class="tag tag-success">LUNAS</span>
                        <?php else: ?>
                            <span class="tag tag-warning">COD: <?= e(rupiah($sisa)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$passengers): ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #64748b; padding: 16px;">Tidak ada penumpang tercatat.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($booking['note']) || !empty($assign['reason'])): ?>
        <div style="font-size: 9pt; background: #fffbeb; border: 1px solid #fde68a; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
            <strong>Catatan Operasional:</strong><br>
            <?= nl2br(e(trim(($booking['note'] ? "Booking: " . $booking['note'] : '') . ($assign['reason'] ? "\nPenugasan: " . $assign['reason'] : '')))) ?>
        </div>
        <?php endif; ?>

        <div class="sig-section">
            <div class="sig-box">
                <div>Pengemudi / Driver,</div>
                <div class="sig-line"><?= e($assign['employee_name']) ?></div>
            </div>
            <div class="sig-box">
                <div>Koordinator Operasional / Dispatcher,</div>
                <div class="sig-line"><?= e($assign['created_user'] ?: 'Petugas Operasional') ?></div>
            </div>
        </div>
    </div>

    <?php if ($print): ?>
    <script>window.addEventListener('load', function() { window.print(); });</script>
    <?php endif; ?>

    <?php if ($pdf): ?>
    <?php
    $html = ob_get_clean();
    pdf_send_or_html(pdf_prepare_screen_html($html, '.actions-bar'), 'Surat-Jalan-' . $id);
    ?>
    <?php endif; ?>
</body>
</html>

