<?php
/**
 * Export Payroll per periode: ?format=csv | xlsx | pdf.
 * Filter & validasi tenant sama dengan detail.php; hanya payroll.view.
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
$table = [[
    'Kode', 'Karyawan', 'Jabatan', 'Hari Hadir',
    'Gaji Pokok', 'Tunjangan Harian', 'Potongan', 'Gaji Bersih',
    'Bank', 'No. Rekening',
]];
$total_net = 0.0;
foreach ($items as $i) {
    $net = (float) $i['net_salary'];
    $total_net += $net;
    $table[] = [
        $i['employee_code'], $i['employee_name'], (string) ($i['job_position'] ?? ''),
        (int) $i['days_present'],
        (float) $i['basic_salary'], (float) $i['daily_allowance'],
        (float) $i['deductions'], $net,
        (string) ($i['bank_name'] ?? ''), (string) ($i['account_number'] ?? ''),
    ];
}
$table[] = ['', 'TOTAL', '', '', '', '', '', $total_net, '', ''];

$base = 'Payroll ' . payroll_period_label($period);
$fmt  = (string) ($_GET['format'] ?? 'xlsx');
$fmt  = in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true) ? $fmt : 'xlsx';
$sub  = 'Periode ' . payroll_period_label($period) . ' — ' . count($items) . ' karyawan';

if ($fmt === 'pdf' || $fmt === 'html') {
    require_once __DIR__ . '/../../reports/_print.php';
    rpt_send($table, 'Payroll', $base, $sub, null, 'landscape', 'payroll.export');
}

$ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
$utf8  = rawurlencode($base);

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $ascii) . '.csv"; filename*=UTF-8\'\'' . $utf8 . '.csv');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($table as $row) fputcsv($out, $row, ',', '"', '');
    fclose($out);
    audit('payroll.export', $base . ' (CSV)');
    exit;
}

require_once __DIR__ . '/../../../includes/xlsx.php';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $ascii) . '.xlsx"; filename*=UTF-8\'\'' . $utf8 . '.xlsx');
header('Cache-Control: no-store');
audit('payroll.export', $base . ' (XLSX)');
echo xlsx_build($table, 'Payroll');
