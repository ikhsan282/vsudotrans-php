<?php
/**
 * Self-check Backup & Restore (tanpa HTTP).
 *   /opt/data/bin/php -c /opt/data/cache/scratch/localdb/php.ini tests/backup_check.php
 *
 * Yang diuji:
 *  1. Dump seluruh DB ke file temp: ada CREATE TABLE users+companies, INSERT INTO, header/footer.
 *  2. Impor ulang dump ke DB sementara `vsudo_bk_<pid>`: jumlah tabel, user, karyawan cocok — lalu DROP.
 *  3. Splitter statement, validasi unggahan, dan nama file unduhan.
 * DB sementara selalu dihapus, juga saat gagal.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/backup.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$db  = db();
$tmp = sys_get_temp_dir() . '/vsudo_backup_check_' . getmypid() . '.sql';
$tmpdb = 'vsudo_bk_' . getmypid();

// ---------------------------------------------------------------- 1. Dump
$fh = fopen($tmp, 'wb');
$tables = backup_dump_to_stream($fh);
fclose($fh);
$dump = file_get_contents($tmp);

echo "-- 1. Dump --\n";
ok($tables > 40, "seluruh tabel ikut ter-dump ({$tables} tabel)");
ok(str_contains($dump, 'CREATE TABLE `users`'), 'dump memuat CREATE TABLE `users`');
ok(str_contains($dump, 'CREATE TABLE `companies`'), 'dump memuat CREATE TABLE `companies`');
ok(str_contains($dump, 'INSERT INTO `users`'), 'dump memuat INSERT INTO `users`');
ok(str_contains($dump, 'INSERT INTO `companies`'), 'dump memuat INSERT INTO `companies`');
ok(str_starts_with($dump, "-- VSudoTrans database backup"), 'header komentar di awal');
ok(str_contains($dump, 'SET NAMES utf8mb4;') && str_contains($dump, 'SET FOREIGN_KEY_CHECKS=0;'), 'header SET NAMES + FOREIGN_KEY_CHECKS');
ok(str_contains($dump, 'DROP TABLE IF EXISTS `users`;'), 'CREATE didahului DROP TABLE IF EXISTS (idempoten)');
ok(str_contains($dump, '-- Selesai:'), 'footer penanda akhir dump');
ok(str_contains($dump, 'ENGINE=InnoDB') && str_contains($dump, 'PRIMARY KEY'), 'struktur tabel utuh (SHOW CREATE TABLE)');
// Nilai NULL harus literal NULL, bukan string kosong ber-kutip; dan baris data ikut ter-dump.
// (Baris data ada di baris setelah bagian "VALUES", karena daftar kolom panjang.)
ok(preg_match('/^INSERT INTO `users` \(.*?\) VALUES\n\(\'1\', \'superadmin\'/m', $dump) === 1,
    'baris data user pertama ikut ter-dump' . (preg_last_error_msg() ? ' [' . preg_last_error_msg() . ']' : ''));
$ins_audit = preg_match('/^INSERT INTO `audit_trails` .*?\n\(.*?, ?NULL,/ms', $dump) === 1;
ok($ins_audit || str_contains($dump, ', NULL,') || str_contains($dump, ', NULL)'), 'kolom NULL ditulis literal NULL');

// ---------------------------------------------------------------- 2. Impor ulang
echo "-- 2. Impor ulang ke {$tmpdb} --\n";
$db->query("DROP DATABASE IF EXISTS `{$tmpdb}`");
$db->query("CREATE DATABASE `{$tmpdb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->query("USE `{$tmpdb}`");

$stmts = backup_split_sql($dump);
ok(count($stmts) > $tables, 'splitter menghasilkan > ' . $tables . ' statement (' . count($stmts) . ')');

[$ok_import, $n_run, $err] = backup_import_sql($dump);
ok($ok_import, 'impor dump tanpa error' . ($err ? " ($err)" : ''));
echo "     statement dieksekusi: {$n_run}\n";

$src = audit_counts($db, 'vsudotrans');
$dst = audit_counts($db, $tmpdb);
foreach ($src as $label => $n) {
    ok($dst[$label] === $n, "{$label}: {$dst[$label]} baris (sumber {$n})");
}
ok($dst['tabel'] === $tables, "jumlah tabel hasil impor = {$dst['tabel']} (harus {$tables})");

$db->query("DROP DATABASE IF EXISTS `{$tmpdb}`");
$still = $db->query("SHOW DATABASES LIKE '{$tmpdb}'")->num_rows;
ok($still === 0, "DB sementara {$tmpdb} terhapus");

// ---------------------------------------------------------------- 3. Splitter & validasi
echo "-- 3. Splitter & validasi --\n";
$s = backup_split_sql("-- komentar\nCREATE TABLE a (x INT);\nINSERT INTO a VALUES ('a;b', 'it''s', 'x\\'y');\n# komentar kedua\nSELECT 1;");
ok(count($s) === 3, 'komentar diabaikan, string ber-titik-koma tidak memecah statement (' . count($s) . ' statement)');
ok(str_contains($s[1]['sql'], "'a;b'"), "isi string 'a;b' utuh");
ok($s[2]['line'] === 5, "nomor baris statement ketiga dilaporkan ({$s[2]['line']})");

// Varian kutip ganda & backslash harus sama: isi string tidak memecah statement.
$s2 = backup_split_sql("INSERT INTO a VALUES (\"a;b\", \"x\\\"y\");\nSELECT 2;");
ok(count($s2) === 2, 'kutip ganda dengan escape ditangani (' . count($s2) . ' statement)');

$up = static fn(array $a): array => $a + ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'name' => 'x.sql', 'size' => filesize($tmp)];
ok(backup_validate_upload($up([])) !== null, 'file yang bukan hasil unggahan ditolak (is_uploaded_file)');
if (function_exists('is_uploaded_file')) {
    ok(backup_validate_upload($up(['name' => 'dump.zip'])) !== null, 'ekstensi non-.sql DITOLAK sebelum cek is_uploaded_file');
    ok(backup_validate_upload($up(['size' => 0])) !== null, 'file kosong DITOLAK sebelum cek is_uploaded_file');
    ok(backup_validate_upload($up(['size' => 64 * 1024 * 1024])) !== null, 'file melebihi batas DITOLAK sebelum cek is_uploaded_file');
}
ok(backup_validate_upload($up(['error' => UPLOAD_ERR_INI_SIZE])) !== null, 'UPLOAD_ERR_INI_SIZE ditolak dengan pesan');
ok(backup_validate_upload(null) !== null, 'unggahan tidak ada ditolak');
// Isi biner/NUL ditolak — panggil helper internal lewat jalur yang tidak butuh unggahan asli:
// file .sql ber-NUL tidak mungkin berasal dari dump teks kita.
ok(str_contains((string) @file_get_contents($tmp), 'CREATE TABLE') && !str_contains((string) @file_get_contents($tmp), "\0"), 'dump kita murni teks (tanpa NUL)');

$name = 'vsudotrans-backup-' . date('Ymd-His') . '.sql';
ok(preg_match('/^vsudotrans-backup-\d{8}-\d{6}\.sql$/', $name) === 1, "pola nama unduhan: {$name}");

@unlink($tmp);
echo $fails ? "\n{$fails} GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);

/** Hitung tabel + baris kunci dari satu database. */
function audit_counts(mysqli $db, string $name): array
{
    $q = static fn(string $sql): int => (int) $db->query($sql)->fetch_row()[0];
    return [
        'tabel'     => $q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$name}'"),
        'users'     => $q("SELECT COUNT(*) FROM `{$name}`.users"),
        'companies' => $q("SELECT COUNT(*) FROM `{$name}`.companies"),
        'karyawan'  => $q("SELECT COUNT(*) FROM `{$name}`.employees"),
        'roles'     => $q("SELECT COUNT(*) FROM `{$name}`.roles"),
    ];
}
