<?php
/**
 * Export Buku Kas: ?format=csv | ?format=xlsx + filter yang sama dengan halaman.
 * Isi: tanggal, akun, arah, kategori, keterangan, nominal bertanda (keluar minus),
 * ditutup baris Total Masuk / Total Keluar / Saldo Akhir.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$f = cash_filter($_GET);
$fmt = (string) ($_GET['format'] ?? 'xlsx');
$fmt = in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true) ? $fmt : 'xlsx';
$sub = 'Periode ' . $f['from'] . ' s/d ' . $f['to'];
$table = cash_export_table($f);
$base = 'Buku Kas ' . $f['from'] . ' s-d ' . $f['to'];

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
    require_once __DIR__ . '/../../reports/_print.php';
    rpt_send($table, 'Buku Kas', $base, $sub, null, 'portrait', 'finance.cash.print');
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
    audit('finance.cash.print', $base . ' (CSV)');
    exit;
}

require_once __DIR__ . '/../../../includes/xlsx.php';
send_download_headers($base, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
audit('finance.cash.print', $base . ' (XLSX)');
echo xlsx_build($table, 'Buku Kas');
