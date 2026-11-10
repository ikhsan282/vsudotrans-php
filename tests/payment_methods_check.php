<?php
/**
 * Self-check modul Metode Pembayaran (tanpa framework, butuh DB lokal + Azure Blob).
 * Fokus: field gambar QR tersimpan murni di Azure Blob (qr_blob_path), kolom LONGBLOB lama sudah dihapus.
 * Jalankan: php tests/payment_methods_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/masters/_engine.php';

$fail = 0;
function ok(bool $cond, string $label): void
{
    global $fail;
    if ($cond) {
        echo "  OK   $label\n";
    } else {
        echo "  GAGAL $label\n";
        $fail++;
    }
}

echo "1. Spec terdaftar\n";
$spec = master_spec('payment_methods');
ok($spec['table'] === 'payment_methods', 'tabel = payment_methods');
ok($spec['perm_view'] === 'finance.view', 'permission view = finance.view');
ok($spec['perm_manage'] === 'finance.manage', 'permission manage = finance.manage');
ok(!empty($spec['company']), 'ber-company_id (tenant scoped)');
ok(($spec['unique'] ?? '') === 'company_or_null', 'unik company_or_null');

echo "2. Field gambar QR (Azure Blob)\n";
$by_col = [];
foreach ($spec['fields'] as $f) {
    $by_col[$f['col']] = $f;
}
ok(($by_col['qr_blob_path']['type'] ?? '') === 'blob', 'qr_blob_path bertipe blob');
ok(($by_col['qr_blob_path']['module'] ?? '') === 'finance', 'qr_blob_path.module = finance');
ok(($by_col['qr_blob_path']['sub'] ?? '') === 'qris', 'qr_blob_path.sub = qris');
ok(!isset($by_col['qr_image']) && !isset($by_col['qr_mime']), 'field qr_image/qr_mime lama tidak ada');
ok(($by_col['instructions']['type'] ?? '') === 'textarea', 'instructions textarea');

echo "3. Kolom schema\n";
ok(column_exists('payment_methods', 'qr_blob_path'), 'kolom qr_blob_path ada');
ok(!column_exists('payment_methods', 'qr_image'), 'kolom qr_image (LONGBLOB) sudah dihapus');
ok(!column_exists('payment_methods', 'qr_mime'), 'kolom qr_mime sudah dihapus');

echo "4. Round-trip Azure Blob\n";
if (!azure_blob_is_enabled()) {
    ok(false, 'Azure Blob belum dikonfigurasi (.env BLOB_STORAGE_CONNECTION_STRING)');
} else {
    $cid  = active_company_id() ?: 1;
    $bin  = random_bytes(512);
    $path = azure_blob_build_path($cid, 'finance', 'qris', 'chk-test', 'png');
    ok(str_starts_with($path, azure_blob_company_prefix($cid) . '/finance/qris/'), 'path = {company_code}/finance/qris/... (' . $path . ')');
    ok(azure_blob_put($path, $bin, 'image/png'), 'upload ke Azure');
    $got = azure_blob_get($path);
    ok($got !== null && $got['data'] === $bin, 'unduh kembali utuh (512 byte)');
    ok(azure_blob_delete($path), 'hapus blob');
    ok(azure_blob_get($path) === null, 'blob sudah tidak ada');
}

echo $fail === 0 ? "\nSemua kasus metode pembayaran lolos.\n" : "\n$fail kasus GAGAL.\n";
exit($fail === 0 ? 0 : 1);
