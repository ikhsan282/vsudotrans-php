<?php
/**
 * Bukti HTTP halaman Backup & Restore (butuh server dev hidup + DB dev).
 *   php tests/backup_http_check.php
 *
 * Uji:
 *  1. Belum login -> redirect login.
 *  2. admin (Admin Perusahaan) -> 403, dan menu "Sistem" tidak dirender.
 *  3. superadmin -> 200, ada tombol unduh + form restore.
 *  4. ?action=download -> 200, Content-Disposition attachment, nama file pola benar,
 *     isi dump memuat CREATE TABLE users + INSERT INTO.
 *  5. POST restore tanpa CSRF -> 403.
 *  6. Menu "Sistem" hanya muncul untuk superadmin.
 * Transport: curl CLI (ekstensi php-curl tidak tersedia di stack ini).
 */
require_once __DIR__ . '/../config/config.php';

$BASE = getenv('VSUDO_HTTP_BASE') ?: 'http://127.0.0.1:8090/vsudotrans';
$JAR  = sys_get_temp_dir() . '/vsudo_backup_cookies.txt';
$PAGE = $BASE . '/pages/settings/backup.php';
$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

/**
 * HTTP via curl CLI. Mengembalikan ['code','head','body'].
 * $out != null: simpan body ke file (untuk unduhan besar) + header ke $out.head.
 */
function http(string $url, $post = null, ?string $out = null): array
{
    $cmd = 'curl -s -i -b ' . escapeshellarg($GLOBALS['JAR']) . ' -c ' . escapeshellarg($GLOBALS['JAR']);
    if ($post !== null) {
        $cmd .= ' -d ' . escapeshellarg(is_array($post) ? http_build_query($post) : $post);
    }
    if ($out !== null) {
        $cmd .= ' -o ' . escapeshellarg($out) . ' -D ' . escapeshellarg($out . '.head');
        exec($cmd . ' ' . escapeshellarg($url), $ignored, $rc);
        $raw  = (string) file_get_contents($out . '.head');
        $body = (string) file_get_contents($out);
    } else {
        exec($cmd . ' ' . escapeshellarg($url), $lines, $rc);
        $raw  = implode("\n", $lines);
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $body = $parts[1] ?? '';
    }
    $head = trim((string) preg_replace("/\r?\n\r?\n.*/s", '', $raw));
    preg_match('/HTTP\/[\d.]+ (\d+)/', $head, $m);
    return ['code' => (int) ($m[1] ?? 0), 'head' => $head, 'body' => $body];
}

function login(string $user, string $pass): void
{
    @unlink($GLOBALS['JAR']);
    $g = http($GLOBALS['BASE'] . '/pages/auth/login.php');
    preg_match('/name="csrf_token" value="([^"]+)"/', $g['body'], $m);
    http($GLOBALS['BASE'] . '/pages/auth/login.php', ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => $pass]);
}

echo "-- Akses --\n";
@unlink($JAR);
// Anonim: langsung 403. Halaman ini sengaja menguji is_super_admin() SEBELUM require_login(),
// jadi anonim tidak pernah melihat UI backup — sama seperti anonim di dashboard (yang,
// lewat require_login() di header.php, justru me-redirect ke login).
$r = http($PAGE);
ok($r['code'] === 403, "anonim -> 403 tanpa membocorkan UI (HTTP {$r['code']})");
ok(!str_contains($r['body'], 'Unduh Backup'), 'anonim tidak melihat tombol unduh');
$anon = http($BASE . '/pages/dashboard/index.php');
ok($anon['code'] === 302 && str_contains($anon['head'], 'login.php'), 'pembanding: dashboard anonim -> redirect login');

login('admin', 'P@ssw0rd');
$r = http($PAGE);
ok($r['code'] === 403, "Admin Perusahaan -> 403 (HTTP {$r['code']})");
ok(!str_contains($r['body'], 'Backup & Restore'), 'halaman 403 tidak membocorkan UI backup');

login('superadmin', 'P@ssw0rd');
$r = http($PAGE);
ok($r['code'] === 200, "Super Admin -> 200 (HTTP {$r['code']})");
ok(str_contains($r['body'], 'Unduh Backup Sekarang'), 'tombol unduh backup ada');
ok(str_contains($r['body'], 'enctype="multipart/form-data"'), 'form restore multipart ada');
ok(str_contains($r['body'], 'name="confirm_overwrite"'), 'checkbox konfirmasi "Timpa seluruh data" ada');
ok(str_contains($r['body'], 'Timpa seluruh data'), 'teks konfirmasi eksplisit tampil');
preg_match_all('/<b>(Warning|Notice|Fatal error|Deprecated|Parse error)<\/b>/', $r['body'], $w);
ok($w[1] === [], 'tanpa warning/notice PHP' . ($w[1] ? ' (' . implode(',', $w[1]) . ')' : ''));
ok(str_contains($r['body'], 'id="menu-system"') && str_contains($r['body'], 'Backup &amp; Restore'), 'menu grup "Sistem" tampil di sidebar');

echo "-- Unduhan --\n";
$tmp = sys_get_temp_dir() . '/vsudo_dl_' . getmypid() . '.sql';
$d = http($PAGE . '?action=download', null, $tmp);
ok($d['code'] === 200, "GET ?action=download -> 200 (HTTP {$d['code']})");
ok(stripos($d['head'], 'Content-Disposition: attachment') !== false, 'header Content-Disposition: attachment');
ok(preg_match('/filename="vsudotrans-backup-\d{8}-\d{6}\.sql"/', $d['head']) === 1, 'nama file unduhan pola benar');
ok(str_contains($d['head'], 'application/sql'), 'Content-Type application/sql');
$body = (string) file_get_contents($tmp);
ok(filesize($tmp) > 10000, 'ukuran dump wajar (' . filesize($tmp) . ' byte)');
ok(str_contains($body, 'CREATE TABLE `users`') && str_contains($body, 'INSERT INTO `users`'), 'isi dump memuat CREATE + INSERT users');
ok(str_contains($body, 'SET FOREIGN_KEY_CHECKS=0;'), 'header FOREIGN_KEY_CHECKS ada');
ok(!preg_match('/^\s*DROP\s+TABLE/im', $body), 'dump TIDAK memuat pernyataan DROP TABLE (restore in-place aman)');
ok(substr_count($body, 'DELETE FROM `') >= 53, 'tiap tabel dikosongkan dengan DELETE (' . substr_count($body, 'DELETE FROM `') . ')');
ok(substr_count($body, 'CREATE TABLE') >= 53, 'semua tabel ikut (' . substr_count($body, 'CREATE TABLE') . ' CREATE TABLE)');
@unlink($tmp);

echo "-- Restore ditolak tanpa CSRF --\n";
$r = http($PAGE, ['action' => 'restore', 'confirm_overwrite' => 'yes']);
ok($r['code'] === 403, "POST restore tanpa CSRF -> 403 (HTTP {$r['code']})");

echo "-- Sidebar untuk non-Super --\n";
login('admin', 'P@ssw0rd');
$r = http($BASE . '/pages/dashboard/index.php');
ok(!str_contains($r['body'], 'Backup &amp; Restore'), 'menu Sistem TIDAK tampil untuk Admin Perusahaan (HTTP ' . $r['code'] . ')');
ok(!str_contains($r['body'], 'pages/settings/backup.php'), 'tautan backup tidak ada di halaman non-Super');
@unlink($JAR);

echo $fails ? "\n{$fails} GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
