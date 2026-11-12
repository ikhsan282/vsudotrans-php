<?php
/**
 * Self-check batas level Role & Hak Akses (tanpa HTTP).
 *   php -c /opt/data/cache/scratch/localdb/php.ini tests/roles_rbac_check.php
 *
 * assert_role_manageable() memanggil exit → diuji lewat subprocess probe
 * (tests/_roles_level_probe.php, yang menyiapkan fixture aktor sendiri).
 * Aturan: non-Super hanya boleh mengelola role berlevel LEBIH RENDAH (angka lebih besar).
 */
require_once __DIR__ . '/../config/config.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$probe = __DIR__ . '/_roles_level_probe.php';
$php   = PHP_BINARY;
$ini   = php_ini_loaded_file();
$nullDev = DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';
// Jalankan probe; true bila konten memuat ALLOWED (lolos), false bila 403 (ditolak).
$run = function (int $target_level) use ($probe, $php, $ini, $nullDev): bool {
    $cmd = escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' ' . escapeshellarg($probe) . ' ' . $target_level . ' ' . $nullDev;
    return str_contains((string) shell_exec($cmd), 'ALLOWED');
};

ok($run(4) === true,  'role di bawah aktor (lvl 4 > 3) LOLOS');
ok($run(3) === false, 'role setinggi aktor (lvl 3) DITOLAK');
ok($run(2) === false, 'role di atas aktor (lvl 2) DITOLAK');
ok($run(9) === true,  'role jauh di bawah (lvl 9) LOLOS');

// Bersihkan fixture probe.
$c = db();
$c->query("DELETE FROM users WHERE id = 9903");
$c->query("DELETE FROM roles WHERE id = 9903");

echo $fails === 0 ? "\nSEMUA LULUS\n" : "\n$fails GAGAL\n";
exit($fails === 0 ? 0 : 1);
