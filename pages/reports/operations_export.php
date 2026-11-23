<?php
/**
 * Export Laporan Operasional: ?format=csv | ?format=xlsx + filter yang sama
 * dengan halaman (pola sama dengan bookings_export.php).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_operations_query.php';

$f = report_ui_filter($_GET);
$fmt = (string) ($_GET['format'] ?? 'xlsx');
$fmt = in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true) ? $fmt : 'xlsx';
$sub = report_period_subtitle($f);
$data = ops_all($f);

$comp = $data['completion'];
$veh  = $data['vehicles'];
$drv  = $data['drivers'];
$stts = $data['status'];
$cats = $data['categories'] ?? [];
$vt   = ops_totals($veh, ['tugas', 'selesai', 'berjalan', 'hari']);
$dt   = ops_totals($drv, ['tugas', 'selesai', 'berjalan']);
$st   = ops_totals($stts, ['travel', 'rental', 'total']);
$ctOps = ops_totals($cats, ['total', 'berjalan', 'selesai', 'dibatalkan']);
$catActive = max(0, (int) $ctOps['total'] - (int) $ctOps['dibatalkan']);
$catPct    = $catActive > 0 ? round(((int) $ctOps['selesai'] * 100) / $catActive, 1) : 0.0;

$table = [];
array_unshift($table, ['Ringkasan', ''], ['Total Penugasan', $comp['total']],
    ['Selesai', $comp['selesai']], ['Masih Berjalan', $comp['berjalan']],
    ['Tingkat Selesai (%)', $comp['persen']], ['']);

$table[] = ['Tingkat Penyelesaian Tugas', ''];
$table[] = ['Total Penugasan', 'Selesai', 'Berjalan', 'Tingkat Selesai (%)'];
$table[] = [$comp['total'], $comp['selesai'], $comp['berjalan'], $comp['persen']];
$table[] = [''];

$table[] = ['Utilisasi Kendaraan', ''];
$table[] = ['No. Kendaraan', 'Warna', 'Tugas', 'Hari', 'Selesai', 'Berjalan'];
foreach ($veh as $r) {
    $table[] = [(string) $r['vehicle_number'], (string) $r['vehicle_color'],
        (int) $r['tugas'], (int) $r['hari'], (int) $r['selesai'], (int) $r['berjalan']];
}
$table[] = ['TOTAL', '', $vt['tugas'], $vt['hari'], $vt['selesai'], $vt['berjalan']];
$table[] = [''];

$table[] = ['Aktivitas Driver', ''];
$table[] = ['Karyawan', 'Kode', 'Tugas', 'Selesai', 'Berjalan'];
foreach ($drv as $r) {
    $table[] = [(string) $r['name'], (string) $r['code'],
        (int) $r['tugas'], (int) $r['selesai'], (int) $r['berjalan']];
}
$table[] = ['TOTAL', '', $dt['tugas'], $dt['selesai'], $dt['berjalan']];
$table[] = [''];

$table[] = ['Booking per Status', ''];
$table[] = ['Status', 'Travel', 'Rental', 'Total'];
foreach ($stts as $r) {
    $table[] = [(string) $r['status'], (int) $r['travel'], (int) $r['rental'], (int) $r['total']];
}
$table[] = ['TOTAL', $st['travel'], $st['rental'], $st['total']];
$table[] = [''];

$table[] = ['', 'Operasional per Kategori Layanan'];
$table[] = ['Kategori Layanan', 'Layanan', 'Booking', 'Berjalan', 'Selesai', 'Batal', 'Tingkat Selesai (%)'];
foreach ($cats as $r) {
    $table[] = [
        (string) $r['category_label'],
        (string) $r['service_label'],
        (int) $r['total'],
        (int) $r['berjalan'],
        (int) $r['selesai'],
        (int) $r['dibatalkan'],
        (float) $r['persen'],
    ];
}
$table[] = ['TOTAL', '', (int) $ctOps['total'], (int) $ctOps['berjalan'], (int) $ctOps['selesai'], (int) $ctOps['dibatalkan'], (float) $catPct];

$base = 'Laporan Operasional ' . $f['from'] . ' s-d ' . $f['to'];

if ($fmt === 'pdf' || $fmt === 'html') {
    require_once __DIR__ . '/_print.php';
    rpt_send($table, 'Laporan Operasional', $base, $sub, null, 'portrait', 'reports.operations.print');
}

if ($fmt === 'csv') {
    report_send_download_headers($base, 'csv', 'text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 agar Excel membaca karakter Indonesia dengan benar
    $out = fopen('php://output', 'w');
    foreach ($table as $row) {
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    audit('reports.operations.print', $base . ' (CSV)');
    exit;
}

require_once __DIR__ . '/../../includes/xlsx.php';
report_send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
audit('reports.operations.print', $base . ' (XLSX)');
echo xlsx_build($table, 'Laporan Operasional');
