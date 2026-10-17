<?php
/**
 * E2E restore lewat HTTP: unduh dump -> unggah kembali lewat form restore -> data utuh.
 *   php tests/backup_restore_http_check.php
 *
 * Alur: login superadmin -> unduh dump -> hapus 5 baris `users` (simulasi data rusak)
 * -> POST restore dengan file dump -> assert jumlah user & karyawan kembali + event audit.
 * Sesi browser ditutup saat restore (halaman render status), lalu login ulang bila perlu.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/backup.php';

$BASE = getenv('VSUDO_HTTP_BASE') ?: 'http://127.0.0.1:8090/vsudotrans';
$JAR  = sys_get_temp_dir() . '/vsudo_restore_cookies.txt';
$SQL  = sys_get_temp_dir() . '/vsudo_restore_http_' . getmypid() . '.sql';
$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$db = db();
$counts = static fn(): array => [
    'users'     => (int) $db->query('SELECT COUNT(*) FROM users')->fetch_row()[0],
    'employees' => (int) $db->query('SELECT COUNT(*) FROM employees')->fetch_row()[0],
    'roles'     => (int) $db->query('SELECT COUNT(*) FROM roles')->fetch_row()[0],
    'tabel'     => (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vsudotrans'")->fetch_row()[0],
];

function login(): void
{
    @unlink($GLOBALS['JAR']);
    exec('curl -s -c ' . escapeshellarg($GLOBALS['JAR']) . ' -b ' . escapeshellarg($GLOBALS['JAR'])
        . ' -o ' . escapeshellarg($GLOBALS['JAR'] . '.html') . ' '
        . escapeshellarg($GLOBALS['BASE'] . '/pages/auth/login.php'));
    preg_match('/name="csrf_token" value="([^"]+)"/', (string) file_get_contents($GLOBALS['JAR'] . '.html'), $m);
    file_put_contents($GLOBALS['JAR'] . '.body', http_build_query(['csrf_token' => $m[1] ?? '', 'username' => 'superadmin', 'password' => 'P@ssw0rd']));
    exec('curl -s -c ' . escapeshellarg($GLOBALS['JAR']) . ' -b ' . escapeshellarg($GLOBALS['JAR'])
        . ' -H ' . escapeshellarg('Content-Type: application/x-www-form-urlencoded')
        . ' --data-binary @' . escapeshellarg($GLOBALS['JAR'] . '.body') . ' -o ' . escapeshellarg($GLOBALS['JAR'] . '.login')
        . ' -w "%{http_code}" ' . escapeshellarg($GLOBALS['BASE'] . '/pages/auth/login.php'), $out);
    return;
}

function csrf_from(string $url): string
{
    exec('curl -s -b ' . escapeshellarg($GLOBALS['JAR']) . ' ' . escapeshellarg($url), $o);
    preg_match('/name="csrf_token" value="([^"]+)"/', implode("\n", $o), $m);
    return $m[1] ?? '';
}

echo "-- E2E restore --\n";
login();
$before = $counts();
ok($before['users'] === 5, "kondisi awal: {$before['users']} user");

// 1. Unduh dump lewat HTTP.
exec('curl -s -b ' . escapeshellarg($JAR) . ' -o ' . escapeshellarg($SQL) . ' '
    . escapeshellarg($BASE . '/pages/settings/backup.php?action=download'));
ok(filesize($SQL) > 10000, 'dump terunduh lewat HTTP (' . filesize($SQL) . ' byte)');

// 2. Rusak data: hapus semua user kecuali superadmin (simulasi kehilangan data).
$db->query('DELETE FROM users WHERE username <> \'superadmin\'');
ok($counts()['users'] === 1, 'data dirusak: user tersisa ' . $counts()['users']);

// 3. Restore lewat form (upload file .sql + checkbox konfirmasi + CSRF).
login();
$csrf = csrf_from($BASE . '/pages/settings/backup.php');
ok($csrf !== '', 'token CSRF form restore didapat');
exec('curl -s -b ' . escapeshellarg($JAR) . ' -c ' . escapeshellarg($JAR)
    . ' -F ' . escapeshellarg('csrf_token=' . $csrf)
    . ' -F ' . escapeshellarg('action=restore')
    . ' -F ' . escapeshellarg('confirm_overwrite=yes')
    . ' -F ' . escapeshellarg('sql_file=@' . $SQL . ';type=application/sql')
    . ' -o ' . escapeshellarg($JAR . '.restore') . ' -w "%{http_code}" -L '
    . escapeshellarg($BASE . '/pages/settings/backup.php'), $code);

$after = $counts();
ok($after['users'] === $before['users'], "restore lewat HTTP memulihkan user ({$after['users']}/{$before['users']})");
ok($after['employees'] === $before['employees'], "karyawan pulih ({$after['employees']})");
ok($after['roles'] === $before['roles'], "role pulih ({$after['roles']})");
ok($after['tabel'] === $before['tabel'], "tabel tetap {$after['tabel']}");

$page = (string) file_get_contents($JAR . '.restore');
ok(str_contains($page, 'Restore selesai'), 'halaman menampilkan jumlah statement yang dieksekusi');
preg_match('/Restore selesai: (\d+) statement/', $page, $m);
echo '     pesan: ' . ($m[0] ?? '(tidak ditemukan)') . "\n";

$log = $db->query("SELECT COUNT(*) FROM audit_trails WHERE action = 'database.restore'")->fetch_row()[0];
ok((int) $log >= 1, 'restore tercatat di audit_trails');

// 5. File temporer hasil restore tidak tertinggal (yang punya file uji sendiri, vsudo_restore_http_*, dikecualikan).
$left = array_filter(
    glob(sys_get_temp_dir() . '/vsudo_restore_*.sql') ?: [],
    static fn(string $f): bool => !str_contains(basename($f), 'vsudo_restore_http_')
);
ok($left === [], 'tidak ada file restore tertinggal di temp (' . count($left) . ')');

@unlink($SQL);
@unlink($JAR);
@unlink($JAR . '.html');
@unlink($JAR . '.body');
@unlink($JAR . '.login');
@unlink($JAR . '.restore');
echo $fails ? "\n{$fails} GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
