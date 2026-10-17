<?php
/**
 * Bukti HTTP modul Profil Saya (butuh server dev hidup + DB dev).
 * Jalankan: php tests/profile_http_check.php
 * Uji: login -> GET halaman profil (200, tanpa warning) -> POST ganti password benar-benar mengubah hash di DB.
 * Transport: curl CLI (ekstensi php-curl tidak tersedia di stack ini).
 * Server dijalankan dari -t /opt/data/projects, jadi worktree diakses lewat symlink /opt/data/projects/vsudotrans-profile.
 */
require_once __DIR__ . '/../config/config.php';

$BASE = getenv('VSUDO_HTTP_BASE') ?: 'http://127.0.0.1:8090/vsudotrans-profile';
$JAR  = sys_get_temp_dir() . '/vsudo_profile_cookies.txt';
$db   = db();
$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

/** HTTP via curl CLI: mengembalikan ['code','head','body']. $post = array|string|null. */
function http(string $url, $post = null): array
{
    $cmd = 'curl -s -i -b ' . escapeshellarg($GLOBALS['JAR']) . ' -c ' . escapeshellarg($GLOBALS['JAR']);
    if ($post !== null) {
        $cmd .= ' -d ' . escapeshellarg(is_array($post) ? http_build_query($post) : $post);
    }
    exec($cmd . ' ' . escapeshellarg($url), $out, $rc);
    $raw = implode("\n", $out);
    unset($out);
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    preg_match('/HTTP\/[\d.]+ (\d+)/', $parts[0] ?? '', $m);
    return ['code' => (int) ($m[1] ?? 0), 'head' => $parts[0] ?? '', 'body' => $parts[1] ?? ''];
}

$probe = http($BASE . '/pages/profile/index.php');
ok($probe['code'] === 302, "belum login -> redirect ke login (dapat {$probe['code']})");

@unlink($JAR); // mulai tanpa cookie sisa
$login = http($BASE . '/pages/auth/login.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
ok(!empty($m[1]), 'token CSRF login ditemukan');

$post = http($BASE . '/pages/auth/login.php', ['csrf_token' => $m[1], 'username' => 'admin', 'password' => 'P@ssw0rd']);
ok($post['code'] === 302 && str_contains($post['head'], 'dashboard'), "login admin sukses (HTTP {$post['code']})");
$db->query("DELETE FROM login_attempts WHERE username = 'admin'"); // bersihkan jejak uji

// Halaman profil: 200 tanpa warning PHP.
$page = http($BASE . '/pages/profile/index.php');
ok($page['code'] === 200, "GET /pages/profile/index.php -> HTTP {$page['code']}");
preg_match_all('/<b>(Warning|Notice|Fatal error|Deprecated|Parse error)<\/b>/', $page['body'], $w);
ok($w[1] === [], 'tanpa warning/notice PHP' . ($w[1] ? ' (' . implode(',', $w[1]) . ')' : ''));
ok(str_contains($page['body'], 'Profil Saya'), 'halaman merender judul "Profil Saya"');
ok(str_contains($page['body'], 'Ganti Password'), 'form ganti password ada');
ok(str_contains($page['body'], 'Administrator Perusahaan'), 'data akun sendiri tampil (nama)');
ok(str_contains($page['body'], 'Admin Perusahaan'), 'nama role dari roles.name tampil');
ok(!preg_match('/\$2y\$|\$2b\$|password_hash/', $page['body']), 'tidak ada hash password di HTML');
preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $c2);
ok(!empty($c2[1]), 'token CSRF form profil ditemukan');

$hash_before = (string) $db->query("SELECT password FROM users WHERE username = 'admin'")->fetch_row()[0];
$PERM = 'P@ssw0rd';
$BARU = 'RahasiaBaru2026';
$cur  = fn (): string => (string) $db->query("SELECT password FROM users WHERE username = 'admin'")->fetch_row()[0];

// (b) password baru < 8 lewat HTTP -> ditolak, hash tetap.
$r = http($BASE . '/pages/profile/index.php', ['csrf_token' => $c2[1], 'password_lama' => $PERM, 'password_baru' => 'Pendek1', 'konfirmasi' => 'Pendek1']);
ok(str_contains($r['body'], 'minimal 8 karakter'), 'HTTP: password < 8 ditolak dengan pesan');
ok($cur() === $hash_before, 'HTTP: hash tetap setelah password pendek');

// (a) password lama salah lewat HTTP -> ditolak, hash tetap.
$r = http($BASE . '/pages/profile/index.php', ['csrf_token' => $c2[1], 'password_lama' => 'salah', 'password_baru' => $BARU, 'konfirmasi' => $BARU]);
ok(str_contains($r['body'], 'Password lama tidak sesuai'), 'HTTP: password lama salah ditolak dengan pesan');
ok($cur() === $hash_before, 'HTTP: hash tetap setelah password lama salah');

// (c) konfirmasi beda lewat HTTP -> ditolak.
$r = http($BASE . '/pages/profile/index.php', ['csrf_token' => $c2[1], 'password_lama' => $PERM, 'password_baru' => $BARU, 'konfirmasi' => 'beda']);
ok(str_contains($r['body'], 'Konfirmasi password tidak cocok'), 'HTTP: konfirmasi beda ditolak');

// CSRF wajib: POST tanpa token -> 403.
$r = http($BASE . '/pages/profile/index.php', ['password_lama' => $PERM, 'password_baru' => $BARU, 'konfirmasi' => $BARU]);
ok($r['code'] === 403, "HTTP: POST tanpa CSRF ditolak (HTTP {$r['code']})");

// (d) sukses -> redirect + hash di DB berubah.
$r = http($BASE . '/pages/profile/index.php', ['csrf_token' => $c2[1], 'password_lama' => $PERM, 'password_baru' => $BARU, 'konfirmasi' => $BARU]);
ok($r['code'] === 302, "HTTP: ganti password sukses -> redirect (HTTP {$r['code']})");
$hash_after = $cur();
ok($hash_after !== $hash_before, 'hash di DB berubah');
ok(password_verify($BARU, $hash_after), 'password_verify(password baru) = true pada hash di DB');
ok(!password_verify($PERM, $hash_after), 'password lama tidak berlaku lagi');

// Login ulang pakai password baru (bukti end-to-end), lalu kembalikan ke semula.
@unlink($JAR);
$login = http($BASE . '/pages/auth/login.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m2);
$post = http($BASE . '/pages/auth/login.php', ['csrf_token' => $m2[1], 'username' => 'admin', 'password' => $BARU]);
ok($post['code'] === 302 && str_contains($post['head'], 'dashboard'), 'login dengan password baru berhasil');
$db->query("DELETE FROM login_attempts WHERE username = 'admin'");

// Kembalikan password admin ke semula (bind_param, bukan quote()).
$st = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$st->bind_param('s', $hash_before);
$st->execute();
$st->close();
ok(password_verify($PERM, $cur()), 'password admin dikembalikan ke P@ssw0rd');
@unlink($JAR);

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
