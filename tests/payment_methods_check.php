<?php
/**
 * Self-check modul Metode Pembayaran (tanpa framework, butuh DB lokal).
 * Fokus: tipe field baru (bytes/hidden) di engine + kolom schema.
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

echo "2. Field bytes & hidden\n";
$by_col = [];
foreach ($spec['fields'] as $f) {
    $by_col[$f['col']] = $f;
}
ok(($by_col['qr_image']['type'] ?? '') === 'bytes', 'qr_image bertipe bytes');
ok(($by_col['qr_image']['mime_col'] ?? '') === 'qr_mime', 'qr_image.mime_col = qr_mime');
ok(($by_col['qr_mime']['type'] ?? '') === 'hidden', 'qr_mime bertipe hidden');
ok(($by_col['instructions']['type'] ?? '') === 'textarea', 'instructions textarea');

echo "3. Kolom schema\n";
ok(column_exists('payment_methods', 'qr_image'), 'kolom qr_image ada');
ok(column_exists('payment_methods', 'qr_mime'), 'kolom qr_mime ada');

$t = db()->query("SHOW COLUMNS FROM payment_methods LIKE 'qr_image'")->fetch_assoc();
ok(stripos($t['Type'], 'longblob') !== false, 'qr_image LONGBLOB (bukan VARCHAR)');

echo "4. Blob round-trip (tipe s untuk kolom biner)\n";
$bin = random_bytes(512);
$st = db()->prepare('INSERT INTO payment_methods (company_id, code, name, qr_image, qr_mime) VALUES (?, ?, ?, ?, ?)');
$code = 'CHK-' . strtoupper(bin2hex(random_bytes(3)));
$mime = 'image/png';
$cid = active_company_id() ?: 1;
$name = 'Uji blob';
$blob = $bin;
$st->bind_param('issss', $cid, $code, $name, $blob, $mime);
$st->execute();
$st->close();

$row = db()->prepare('SELECT LENGTH(qr_image) AS len, qr_mime FROM payment_methods WHERE code = ?');
$row->bind_param('s', $code);
$row->execute();
$r = $row->get_result()->fetch_assoc();
$row->close();
ok((int) $r['len'] === 512, 'blob tersimpan utuh (512 byte), dapat ' . ($r['len'] ?? '-'));
ok($r['qr_mime'] === 'image/png', 'mime tersimpan');

db()->query("DELETE FROM payment_methods WHERE code = '$code'");

echo $fail === 0 ? "\nSemua kasus metode pembayaran lolos.\n" : "\n$fail kasus GAGAL.\n";
exit($fail === 0 ? 0 : 1);
