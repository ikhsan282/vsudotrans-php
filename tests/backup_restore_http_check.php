<?php
/**
 * E2E restore lewat HTTP: unduh dump -> hapus 1 baris -> unggah ulang lewat form restore.
 *
 * DESTRUKTIF (menimpa seluruh isi DB yang dilayani web server), jadi dua lapis guard:
 *  1. VSUDO_ALLOW_DESTRUCTIVE=1 wajib; tanpa itu -> SKIP, exit 0, tanpa menyentuh apa pun.
 *  2. DB uji harus terpisah: VSUDO_DB_NAME (default DB_NAME) != 'vsudotrans'. Sebelum aksi
 *     destruktif, header dump dari server uji dicek — kalau server ternyata melayani DB
 *     lain, test SKIP. Jadi salah setelan tidak bisa menimpa DB kerja.
 *
 * Contoh (web server uji dijalankan dengan DB uji vsudotrans_dev):
 *   VSUDO_ALLOW_DESTRUCTIVE=1 VSUDO_DB_NAME=vsudotrans_dev VSUDO_HTTP_BASE=http://127.0.0.1:8092 \
 *     /opt/data/bin/php -c .../php.ini tests/backup_restore_http_check.php
 *
 * Yang dibuktikan: user yang dihapus kembali, user yang login tetap valid (tanpa DROP TABLE),
 * halaman menampilkan "Restore selesai: N statement", dan audit_trails terisi.
 */
require_once __DIR__ . '/../config/config.php';

$BASE   = getenv('VSUDO_HTTP_BASE') ?: 'http://127.0.0.1:8090/vsudotrans';
$DB     = getenv('VSUDO_DB_NAME') ?: DB_NAME;
$WORKDB = 'vsudotrans'; // DB yang dipakai manusia
$JAR = sys_get_temp_dir() . '/vsudo_restore_cookies.txt';
$SQL = sys_get_temp_dir() . '/vsudo_restore_http_' . getmypid() . '.sql';
$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }
function skip(string $why): void { echo "SKIP: {$why}\n"; exit(0); }

if (getenv('VSUDO_ALLOW_DESTRUCTIVE') !== '1') {
    skip('test ini menimpa seluruh isi database. Set VSUDO_ALLOW_DESTRUCTIVE=1 + '
        . 'VSUDO_DB_NAME=<db uji> + VSUDO_HTTP_BASE=<server uji> untuk menjalankannya.');
}
if ($DB === $WORKDB) {
    skip("VSUDO_DB_NAME = '{$WORKDB}' (DB kerja). Pakai database uji terpisah, mis. vsudotrans_dev.");
}

/** GET/POST via curl CLI (ekstensi php-curl tidak ada di stack ini). */
function http(string $url, array $extra = []): array
{
    $cmd = 'curl -s -b ' . escapeshellarg($GLOBALS['JAR']) . ' -c ' . escapeshellarg($GLOBALS['JAR']);
    foreach ($extra as $e) {
        $cmd .= ' ' . $e;
    }
    exec($cmd . ' ' . escapeshellarg($url), $o);
    return ['out' => implode("\n", $o)];
}

function login(): void
{
    @unlink($GLOBALS['JAR']);
    $g = http($GLOBALS['BASE'] . '/pages/auth/login.php');
    preg_match('/name="csrf_token" value="([^"]+)"/', $g['out'], $m);
    file_put_contents($GLOBALS['JAR'] . '.body', http_build_query([
        'csrf_token' => $m[1] ?? '', 'username' => 'superadmin', 'password' => 'P@ssw0rd',
    ]));
    http($GLOBALS['BASE'] . '/pages/auth/login.php', [
        '-H ' . escapeshellarg('Content-Type: application/x-www-form-urlencoded'),
        '--data-binary ' . escapeshellarg('@' . $GLOBALS['JAR'] . '.body'),
    ]);
}

echo "-- E2E restore lewat HTTP (DB uji {$DB}) --\n";
login();

// 1. Unduh dump lewat HTTP.
http($BASE . '/pages/settings/backup.php?action=download', ['-o ' . escapeshellarg($SQL)]);
$dump = (string) @file_get_contents($SQL);
if ($dump === '') {
    skip("tidak bisa mengunduh dump dari {$BASE} — apakah web server uji hidup dan login berhasil?");
}
ok(filesize($SQL) > 10000, 'dump terunduh lewat HTTP (' . filesize($SQL) . ' byte)');
// Pre-flight: server yang diuji HARUS melayani DB uji, bukan DB lain.
if (!str_contains($dump, '-- Database: ' . $DB . "\n")) {
    skip("server di {$BASE} tidak melayani DB '{$DB}'. Tidak ada aksi destruktif dijalankan.");
}
ok(true, "server uji melayani DB '{$DB}'");
ok(!preg_match('/^\s*DROP\s+TABLE/im', $dump), 'dump terunduh tidak memuat pernyataan DROP TABLE');

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, $DB);
$db->set_charset('utf8mb4');
$counts = static fn(): array => [
    'users'     => (int) $db->query("SELECT COUNT(*) FROM `{$GLOBALS['DB']}`.users")->fetch_row()[0],
    'employees' => (int) $db->query("SELECT COUNT(*) FROM `{$GLOBALS['DB']}`.employees")->fetch_row()[0],
    'roles'     => (int) $db->query("SELECT COUNT(*) FROM `{$GLOBALS['DB']}`.roles")->fetch_row()[0],
    'tabel'     => (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$GLOBALS['DB']}'")->fetch_row()[0],
];

$before = $counts();
ok($before['users'] >= 5, "kondisi awal: {$before['users']} user");

// 2. Rusak data: hapus 1 user selain superadmin (sesi login Super Admin tetap ada — skenario nyata).
$db->query("DELETE FROM `{$DB}`.users WHERE username = 'viewer'");
ok($counts()['users'] === $before['users'] - 1, 'data dirusak: user tersisa ' . $counts()['users'] . " ('viewer' dihapus)");

// 3. Restore lewat form (CSRF + checkbox + file .sql). -L: ikuti redirect ke halaman hasil.
login();
preg_match('/name="csrf_token" value="([^"]+)"/', http($BASE . '/pages/settings/backup.php')['out'], $m);
ok(($m[1] ?? '') !== '', 'token CSRF form restore didapat');
http($BASE . '/pages/settings/backup.php', [
    '-F ' . escapeshellarg('csrf_token=' . ($m[1] ?? '')),
    '-F ' . escapeshellarg('action=restore'),
    '-F ' . escapeshellarg('confirm_overwrite=yes'),
    '-F ' . escapeshellarg('sql_file=@' . $SQL . ';type=application/sql'),
    '-L', '-o ' . escapeshellarg($JAR . '.restore'),
]);

$after = $counts();
ok($after['users'] === $before['users'], "restore lewat HTTP memulihkan user ({$after['users']}/{$before['users']})");
ok($after['employees'] === $before['employees'], "karyawan pulih ({$after['employees']})");
ok($after['roles'] === $before['roles'], "role pulih ({$after['roles']})");
ok($after['tabel'] === $before['tabel'], "tabel tetap {$after['tabel']} (tidak ada DROP)");

$page = (string) file_get_contents($JAR . '.restore');
ok(str_contains($page, 'Restore selesai'), 'halaman menampilkan "Restore selesai" — sesi Super Admin tetap valid');
preg_match('/Restore selesai: (\d+) statement/', $page, $m2);
echo '     pesan: ' . ($m2[0] ?? '(tidak ditemukan)') . "\n";
ok(($m2[1] ?? 0) > 0, 'jumlah statement dilaporkan di halaman');

$log = $db->query("SELECT COUNT(*) FROM `{$DB}`.audit_trails WHERE action = 'database.restore'")->fetch_row()[0];
ok((int) $log >= 1, 'restore tercatat di audit_trails');
$row = $db->query("SELECT description FROM `{$DB}`.audit_trails WHERE action = 'database.restore' ORDER BY id DESC LIMIT 1")->fetch_row();
echo '     audit: ' . ($row[0] ?? '(tidak ada)') . "\n";

$left = array_filter(
    glob(sys_get_temp_dir() . '/vsudo_restore_*.sql') ?: [],
    static fn(string $f): bool => !str_contains(basename($f), 'vsudo_restore_http_')
);
ok($left === [], 'file sementara restore tidak tertinggal (' . count($left) . ')');

@unlink($SQL);
@unlink($JAR . '.body');
@unlink($JAR . '.restore');
@unlink($JAR);
echo $fails ? "\n{$fails} GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
