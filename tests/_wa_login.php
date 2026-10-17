<?php
/**
 * Helper uji HTTP (bukan bagian aplikasi): login admin + simpan cookie jar.
 * Dipakai tests/wa_check.php. Usage: php _wa_login.php <base_url> <cookie_jar>
 */
$base = $argv[1] ?? 'http://127.0.0.1:8090/vsudotrans';
$jar  = $argv[2] ?? sys_get_temp_dir() . '/wa_jar.txt';

function curl(string $url, array $opts = []): string
{
    $cmd = 'curl -s -c ' . escapeshellarg($GLOBALS['jar']) . ' -b ' . escapeshellarg($GLOBALS['jar']);
    foreach ($opts as $o) {
        $cmd .= ' ' . $o;
    }
    return (string) shell_exec($cmd . ' ' . escapeshellarg($url));
}

$login = curl($base . '/pages/auth/login.php');
if (!preg_match('/name="csrf_token" value="([^"]+)"/', $login, $m)) {
    echo "LOGIN_FAIL tidak menemukan token\n";
    exit(1);
}
$body = sys_get_temp_dir() . '/wa_login_body.txt';
file_put_contents($body, http_build_query(['username' => 'admin', 'password' => 'P@ssw0rd', 'csrf_token' => $m[1]]));

$code = trim((string) shell_exec('curl -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
    . ' -o /dev/null -w "%{http_code}" -H "Content-Type: application/x-www-form-urlencoded" --data-binary @'
    . escapeshellarg($body) . ' ' . escapeshellarg($base . '/pages/auth/login.php')));
@unlink($body);

$dash = curl($base . '/pages/dashboard/index.php');
// Sukses dinilai dari redirect ke halaman detail/form booking berikutnya; halaman dashboard
// sendiri tetap 200 walau belum login (layout dashboard memuat datanya sendiri), jadi tidak dipakai.
echo ($code === '302' && !str_contains($dash, 'name="password"')) ? "LOGIN_OK http=$code\n" : "LOGIN_FAIL http=$code\n";
exit($code === '302' && !str_contains($dash, 'name="password"') ? 0 : 1);
