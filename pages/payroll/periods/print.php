<?php
/**
 * Payroll — halaman cetak satu periode (standalone, tanpa sidebar).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.view');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? 0);
$period = payroll_period_find($id);
if (!$period) {
    flash('error', 'Periode payroll tidak ditemukan.');
    redirect('pages/payroll/periods/index.php');
}
if ((int) $period['company_id'] !== $cid) {
    http_response_code(403);
    include __DIR__ . '/../../../includes/403.php';
    exit;
}

$items = payroll_items($id);
$total_net = 0.0;
foreach ($items as $i) {
    $total_net += (float) $i['net_salary'];
}
?>
<?php if (($_GET['format'] ?? '') === 'pdf') { ob_start(); } ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll <?= e(payroll_period_label($period)) ?> — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size: 12px; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 11px; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body class="p-3">
<div class="no-print mb-3">
    <button class="btn btn-sm btn-primary" onclick="window.print()">Cetak / Simpan PDF</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/payroll/periods/detail.php?id=<?= $id ?>">Kembali</a>
</div>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="mb-0"><?= e(COMPANY_NAME) ?></h5>
        <div class="text-muted"><?= e(COMPANY_ADDRESS) ?> · <?= e(COMPANY_PHONE) ?></div>
    </div>
    <div class="text-end">
        <div class="fw-bold">DAFTAR GAJI KARYAWAN</div>
        <div>Periode <?= e(payroll_period_label($period)) ?></div>
        <div class="text-muted">Status: <?= e($period['status']) ?></div>
    </div>
</div>

<table class="table table-sm table-bordered">
    <thead class="table-light">
        <tr>
            <th style="width:36px">No</th>
            <th>NIK</th><th>Nama Karyawan</th>
            <th class="text-end">Gaji Pokok</th>
            <th class="text-end">Uang Harian</th>
            <th class="text-end">Hari Hadir</th>
            <th class="text-end">Potongan</th>
            <th class="text-end">Gaji Bersih</th>
            <th>Rekening</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $n => $it): ?>
        <tr>
            <td><?= $n + 1 ?></td>
            <td><?= e((string) $it['employee_code']) ?></td>
            <td><?= e($it['employee_name']) ?></td>
            <td class="text-end"><?= e(rupiah((float) $it['basic_salary'])) ?></td>
            <td class="text-end"><?= e(rupiah((float) $it['daily_allowance'])) ?></td>
            <td class="text-end"><?= (int) $it['days_present'] ?></td>
            <td class="text-end"><?= e(rupiah((float) $it['deductions'])) ?></td>
            <td class="text-end"><?= e(rupiah((float) $it['net_salary'])) ?></td>
            <td><?= e(trim((string) $it['bank_name'] . ' ' . (string) $it['account_number'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
        <tr><td colspan="9" class="text-center text-muted">Belum ada item pada periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
        <tr>
            <td colspan="7" class="text-end">Total Gaji Bersih</td>
            <td class="text-end"><?= e(rupiah($total_net)) ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>

<div class="row mt-5">
    <div class="col-6 text-center">
        <div>Dibuat oleh</div>
        <div style="height:60px"></div>
        <div><?= e((string) $period['created_user']) ?></div>
    </div>
    <div class="col-6 text-center">
        <div>Disetujui oleh</div>
        <div style="height:60px"></div>
        <div>.................................</div>
    </div>
</div>
<?php if (($_GET['format'] ?? '') === 'pdf'): ?>
<?php
$html = ob_get_clean();
require_once __DIR__ . '/../../../includes/pdf.php';
audit('payroll.print', 'Slip Payroll periode #' . $id . ' (PDF)');
pdf_send_or_html(pdf_prepare_screen_html($html, '.no-print'), 'Slip Payroll ' . $id);
?>
<?php endif; ?>
</body>
</html>
