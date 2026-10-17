<?php
/**
 * Export Laporan Keuangan: ?format=csv | ?format=xlsx + filter yang sama dengan
 * halaman. File terpisah dari view agar halaman laporan tetap ringkas
 * (pola sama dengan bookings_export.php).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('finance.view'); // laporan keuangan memuat pendapatan/biaya/laba — perlu izin Keuangan
require_once __DIR__ . '/_finance_query.php';

$f = report_filter($_GET);
$fmt = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
// Export memuat SEMUA tagihan belum lunas (tanpa limit tabel HTML).
$data = fin_all($f, null);

$rev    = $data['revenue'];
$costs  = $data['cost_types'];
$ct     = fin_cost_totals($costs);
$unpaid = $data['unpaid'];
$ut     = fin_unpaid_totals($unpaid);

$table = [];
array_unshift($table, ['Ringkasan', ''], ['Laba Kotor', (float) $data['gross_profit']],
    ['Pendapatan Total', (float) $rev['total']],
    ['Biaya Operasional Total', (float) $data['cost_total']],
    ['Terbayar', (float) $rev['paid']],
    ['Piutang', (float) $rev['receivable']], ['']);

$table[] = ['Pendapatan per Jenis', ''];
$table[] = ['Jenis', 'Pendapatan'];
$table[] = ['Travel', (float) $rev['travel']];
$table[] = ['Rental', (float) $rev['rental']];
$table[] = ['TOTAL', (float) $rev['total']];
$table[] = [''];

$table[] = ['Biaya Operasional per Jenis', ''];
$table[] = ['Jenis Biaya', 'Baris', 'Jumlah'];
foreach ($costs as $r) {
    $table[] = [(string) $r['cost_type'], (int) $r['n_lines'], (float) $r['amount']];
}
$table[] = ['TOTAL', (int) $ct['n_lines'], (float) $ct['amount']];
$table[] = [''];

$table[] = ['Tagihan Belum Lunas', ''];
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
echo xlsx_build($table, 'Laporan Keuangan');
