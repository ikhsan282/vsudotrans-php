<?php
/**
 * Export Laporan Keuangan: ?format=csv | ?format=xlsx + filter yang sama dengan
 * halaman. File terpisah dari view agar halaman laporan tetap ringkas
 * (pola sama dengan bookings_export.php).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('finance.view'); // laporan keuangan memuat pendapatan/biaya/laba — perlu izin Keuangan
require_once __DIR__ . '/_finance_query.php';

$f = fin_report_filter($_GET);
$fmt = (string) ($_GET['format'] ?? 'xlsx');
$fmt = in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true) ? $fmt : 'xlsx';
$sub = report_period_subtitle($f);
// Export memuat SEMUA tagihan belum lunas (tanpa limit tabel HTML).
$data = fin_all($f, null);

$rev     = $data['revenue'];
$costs   = $data['cost_types'];
$ct      = fin_cost_totals($costs);
$catRows = $data['categories'] ?? [];
$ftCat   = fin_category_totals($catRows);
$unpaid  = $data['unpaid'];
$ut     = fin_unpaid_totals($unpaid);
$daily  = $data['daily'] ?? [];
$dt     = fin_daily_totals($daily);
$cash   = $data['cash'] ?? ['in_total' => 0, 'out_total' => 0, 'net_cash_flow' => 0];

$table = [];
array_unshift($table, ['Ringkasan', ''], ['Laba Kotor Trip', (float) $data['gross_profit']],
    ['Pendapatan Booking Total', (float) $rev['total']],
    ['Biaya Operasional Trip Total', (float) $data['cost_total']],
    ['Terbayar Booking', (float) $rev['paid']],
    ['Piutang', (float) $rev['receivable']],
    ['Kas Masuk (Buku Kas)', (float) $cash['in_total']],
    ['Kas Keluar (Buku Kas)', (float) $cash['out_total']],
    ['Arus Kas Bersih', (float) $cash['net_cash_flow']], ['']);

$table[] = ['Pendapatan per Jenis', ''];
$table[] = ['Jenis', 'Pendapatan'];
$table[] = ['Travel', (float) $rev['travel']];
$table[] = ['Rental', (float) $rev['rental']];
$table[] = ['TOTAL', (float) $rev['total']];
$table[] = [''];

$table[] = ['', 'Pendapatan & Laba per Kategori Layanan'];
$table[] = ['Kategori Layanan', 'Layanan', 'Booking', 'Pendapatan', 'Terbayar', 'Piutang', 'Biaya Trip', 'Laba Kotor', 'Margin (%)'];
foreach ($catRows as $r) {
    $table[] = [
        (string) $r['category_label'],
        (string) $r['service_label'],
        (int) $r['booking_count'],
        (float) $r['revenue'],
        (float) $r['paid'],
        (float) $r['receivable'],
        (float) $r['cost'],
        (float) $r['gross_profit'],
        (float) $r['margin_pct'],
    ];
}
$table[] = [
    'TOTAL',
    '',
    (int) $ftCat['booking_count'],
    (float) $ftCat['revenue'],
    (float) $ftCat['paid'],
    (float) $ftCat['receivable'],
    (float) $ftCat['cost'],
    (float) $ftCat['gross_profit'],
    (float) $ftCat['margin_pct'],
];
$table[] = [''];

$table[] = ['Biaya Operasional per Jenis', ''];
$table[] = ['Jenis Biaya', 'Baris', 'Jumlah'];
foreach ($costs as $r) {
    $table[] = [(string) $r['cost_type'], (int) $r['n_lines'], (float) $r['amount']];
}
$table[] = ['TOTAL', (int) $ct['n_lines'], (float) $ct['amount']];
$table[] = [''];

$table[] = ['', 'Rekap Harian (Pemasukan & Pengeluaran)'];
$table[] = ['Tanggal', 'Kas Masuk', 'Kas Keluar', 'Arus Kas Bersih', 'Omzet Booking', 'Biaya Trip', 'Laba Kotor Trip'];
foreach ($daily as $r) {
    $table[] = [
        (string) $r['date'],
        (float) $r['in_total'],
        (float) $r['out_total'],
        (float) $r['net_cash_flow'],
        (float) $r['booking_total'],
        (float) $r['trip_cost'],
        (float) $r['gross_profit'],
    ];
}
$table[] = [
    'TOTAL',
    (float) $dt['in_total'],
    (float) $dt['out_total'],
    (float) $dt['net_cash_flow'],
    (float) $dt['booking_total'],
    (float) $dt['trip_cost'],
    (float) $dt['gross_profit'],
];
$table[] = [''];

$table[] = ['', 'Tagihan Belum Lunas'];
$table[] = ['No. Dokumen', 'Tanggal', 'Jenis', 'Nilai', 'Terbayar', 'Sisa'];
foreach ($unpaid as $r) {
    $table[] = [(string) $r['document_number'], (string) $r['date'], (string) $r['booking_type'],
        (float) $r['total_price'], (float) $r['total_payment'], (float) $r['outstanding']];
}
$table[] = ['TOTAL', '', '', (float) $ut['price'], (float) $ut['paid'], (float) $ut['outstanding']];

$base = 'Laporan Keuangan ' . $f['from'] . ' s-d ' . $f['to'];

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
    rpt_send($table, 'Laporan Keuangan', $base, $sub, null, 'landscape', 'reports.finance.print');
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
    audit('reports.finance.print', $base . ' (CSV)');
    exit;
}

require_once __DIR__ . '/../../includes/xlsx.php';
send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
audit('reports.finance.print', $base . ' (XLSX)');
echo xlsx_build($table, 'Laporan Keuangan');
