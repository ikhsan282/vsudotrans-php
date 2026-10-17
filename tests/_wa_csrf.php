<?php
/**
 * Helper uji HTTP (bukan bagian aplikasi): ambil csrf_token dari form halaman.
 * Usage: php _wa_csrf.php <base_url> <path> <cookie_jar>
 *
 * Catatan: binary php di sesi ini menyemburkan startup warning ke STDOUT, jadi token
 * disaring HANYA dari 64 hex yang tidak menempel pada hex lain — bukan baris apa pun.
 */
$base = $argv[1] ?? 'http://127.0.0.1:8090/vsudotrans-notifwa';
$path = $argv[2] ?? '/pages/travel/bookings/form.php';
$jar  = $argv[3] ?? sys_get_temp_dir() . '/wa_jar.txt';

$html = (string) shell_exec('curl -s -b ' . escapeshellarg($jar) . ' ' . escapeshellarg($base . $path));

if (!preg_match('/(?<![0-9a-f])([0-9a-f]{64})(?![0-9a-f])/', $html, $m)) {
    echo str_contains($html, 'name="password"') ? "NOCSRF_LOGIN\n" : "NOCSRF\n";
    exit(1);
}
echo $m[1] . "\n";
exit(0);
