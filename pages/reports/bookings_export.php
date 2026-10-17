<?php
/**
 * Export Laporan Booking: ?format=csv | ?format=xlsx + filter yang sama dengan halaman.
 * File terpisah dari view agar halaman laporan tetap ringkas.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_query.php';

$f = report_filter($_GET);
$fmt = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$data = report_all($f);

$dims = [
    ['title' => 'Per Periode',   'rows' => $data['period'],  'cols' => [['Tanggal', 'period']]],
    ['title' => 'Per Wilayah',   'rows' => $data['route'],   'cols' => [['Wilayah', 'route_label']]],
    ['title' => 'Per Kendaraan', 'rows' => $data['vehicle'], 'cols' => [['No. Kendaraan', 'vehicle_number'], ['Warna', 'vehicle_color']]],
    ['title' => 'Per Driver',    'rows' => $data['driver'],  'cols' => [['Driver', 'driver_name']]],
];

// Baris siap-tulis untuk satu dimensi: [tanggal, jenis, label…, booking, nilai, terbayar, sisa].
$table = [];
foreach ($dims as $d) {
    $table[] = ['', $d['title']];
    $table[] = array_merge(
        ['Tanggal', 'Jenis'],
        array_column($d['cols'], 0),
        ['Jumlah Booking', 'Total Nilai', 'Total Terbayar', 'Sisa Piutang']
    );
    foreach ($d['rows'] as $r) {
        $row = [$f['from'] . ' s/d ' . $f['to'], $f['booking_type']];
        foreach ($d['cols'] as [, $key]) {
            $row[] = (string) $r[$key];
        }
        $table[] = array_merge($row, [
            (int) $r['total_bookings'],
            (float) $r['total_price'],
            (float) $r['total_payment'],
            (float) $r['total_receivable'],
        ]);
    }
    $t = report_totals($d['rows']);
    $table[] = array_merge(['', 'TOTAL'], array_fill(0, count($d['cols']), ''), [
        (int) $t['total_bookings'], $t['total_price'], $t['total_payment'], $t['total_receivable'],
    ]);
    $table[] = [''];
}

$s = $data['summary'];
array_unshift($table, ['Ringkasan', ''], [
    'Total Booking', (int) $s['total_bookings'],
], [
    'Total Nilai', (float) $s['total_price'],
], [
    'Terbayar', (float) $s['total_payment'],
], [
    'Sisa Piutang', (float) $s['total_receivable'],
], ['']);

$base = 'Laporan Booking ' . $f['from'] . ' s-d ' . $f['to'];

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
    echo "\xEF\xBB\xBF"; // BOM UTF-8: Excel membaca aksen & karakter Indonesia dengan benar
    $out = fopen('php://output', 'w');
    foreach ($table as $row) {
        // $escape='' → PHP 8.4 wajib eksplisit; '' juga lebih benar (tanpa backslash escaping).
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/xlsx.php';
send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
echo xlsx_build($table, 'Laporan Booking');
