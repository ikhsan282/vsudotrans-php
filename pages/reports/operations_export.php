<?php
/**
 * Export Laporan Operasional: ?format=csv | ?format=xlsx + filter yang sama
 * dengan halaman (pola sama dengan bookings_export.php).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_operations_query.php';

$f = report_filter($_GET);
$fmt = (string) ($_GET['format'] ?? 'xlsx');
$fmt = in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true) ? $fmt : 'xlsx';
$sub = 'Periode ' . $f['from'] . ' s/d ' . $f['to'];
$data = ops_all($f);

$comp = $data['completion'];
$veh  = $data['vehicles'];
$drv  = $data['drivers'];
$stts = $data['status'];
$vt   = ops_totals($veh, ['tugas', 'selesai', 'berjalan', 'hari']);
$dt   = ops_totals($drv, ['tugas', 'selesai', 'berjalan']);
$st   = ops_totals($stts, ['travel', 'rental', 'total']);

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

$base = 'Laporan Operasional ' . $f['from'] . ' s-d ' . $f['to'];

// Header download dual-form: ASCII fallback + filename* UTF-8 (spasi/karakter Indonesia aman).
function send_download_headers(string $base, string $ext, string $mime): void
{
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
    $ascii = str_replace('"', '', $ascii);
    $utf8  = rawurlencode($base . '.' . $ext);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii . '.' . $ext . '"; filename*=UTF-8\'\'' . $utf8);
    header('Cache-Control: no-store');
}

if ($fmt === 'pdf' || $fmt === 'html') {
    require_once __DIR__ . '/_print.php';
    rpt_send($table, 'Laporan Operasional', $base, $sub, null, 'portrait', 'reports.operations.print');
}

if ($fmt === 'csv') {
    send_download_headers($base, 'csv', 'text/csv; charset=UTF-8');
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
send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
audit('reports.operations.print', $base . ' (XLSX)');
echo xlsx_build($table, 'Laporan Operasional');
