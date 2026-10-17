<?php
/**
 * Export Laporan Penjadwalan Driver: ?format=csv | ?format=xlsx + filter yang
 * sama dengan halaman (pola sama dengan operations_export.php).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_dispatch_query.php';

$f   = report_filter($_GET);
$fmt = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$data = dispatch_all($f);

$sum = $data['summary'];
$drv = $data['drivers'];
$det = $data['details'];
$dt  = dispatch_totals($drv, ['tugas', 'selesai', 'berjalan', 'hari']);

$jenis = static fn(string $t): string => $t === 'rental' ? 'Rental' : 'Travel';

$table = [];
array_unshift($table, ['Ringkasan', ''], ['Periode', $f['from'] . ' s/d ' . $f['to']],
    ['Jenis Booking', $f['booking_type']], ['Total Penugasan', $sum['total']],
    ['Selesai', $sum['selesai']], ['Sedang Jalan', $sum['berjalan']],
    ['Driver Aktif', $sum['driver']], ['']);

$table[] = ['Beban Kerja per Driver', ''];
$table[] = ['Driver', 'Kode', 'Tugas', 'Selesai', 'Berjalan', 'Hari Bertugas'];
foreach ($drv as $r) {
    $table[] = [(string) $r['name'], (string) $r['code'], (int) $r['tugas'],
        (int) $r['selesai'], (int) $r['berjalan'], (int) $r['hari']];
}
$table[] = ['TOTAL', '', $dt['tugas'], $dt['selesai'], $dt['berjalan'], $dt['hari']];
$table[] = [''];

$table[] = ['Detail Penugasan', ''];
$table[] = ['Mulai', 'Driver', 'Kendaraan', 'Jenis', 'No. Dokumen', 'Status', 'Durasi (menit)', 'Catatan'];
foreach ($det as $r) {
    $table[] = [dispatch_when($r['started_at']), (string) ($r['driver_name'] ?? '-'),
        (string) ($r['vehicle_number'] ?? '-'), $jenis((string) $r['booking_type']),
        (string) ($r['document_number'] ?? '-'), dispatch_status_label($r['ended_at']),
        dispatch_duration($r['started_at'], $r['ended_at']) ?? '', (string) ($r['reason'] ?? '')];
}
$table[] = ['TOTAL PENUGASAN', '', '', '', '', '', count($det), ''];

$base = 'Laporan Penjadwalan Driver ' . $f['from'] . ' s-d ' . $f['to'];

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

if ($fmt === 'csv') {
    send_download_headers($base, 'csv', 'text/csv; charset=UTF-8');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 agar Excel membaca karakter Indonesia dengan benar
    $out = fopen('php://output', 'w');
    foreach ($table as $row) {
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/xlsx.php';
send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
echo xlsx_build($table, 'Penjadwalan Driver');
