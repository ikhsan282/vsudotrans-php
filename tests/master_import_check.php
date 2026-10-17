<?php
/**
 * Uji E2E Import CSV master data (lewat HTTP, curl + cookie jar).
 * Menjalankan: /opt/data/bin/php -c /opt/data/cache/scratch/localdb/php.ini tests/master_import_check.php
 *
 * Membuktikan:
 *  1. Template CSV dapat diunduh (header + 1 baris contoh).
 *  2. Impor valid: baris baru bertambah + tercatat di audit_trails.
 *  3. All-or-nothing: 1 baris rusak -> TIDAK ADA yang tersimpan (rollback).
 *  4. Upsert: kode yang sudah ada -> diperbarui (bukan duplikat).
 *  5. Mode insert: kode duplikat -> error, tidak menimpa.
 *  6. RBAC: pengguna tanpa masters.manage -> 403.
 *  7. Formula injection ( nilai diawali "=,+,-,@ ) disimpan sebagai teks, tak dieksekusi.
 */
$BASE = $argv[1] ?? 'http://127.0.0.1:8090/vsudotrans';
$img  = sys_get_temp_dir();

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok  $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

function dbc(): mysqli {
    return new mysqli('localhost', 'root', '', 'vsudotrans', 3306, '/opt/data/cache/scratch/localdb/mysql.sock');
}

function curl(string $url, array $opts = []): array {
    $cmd = 'curl -s -i ' . implode(' ', array_map('escapeshellarg', $opts)) . ' ' . escapeshellarg($url);
    $raw = (string) shell_exec($cmd);
    $code = 0;
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $raw, $m)) { $code = (int) $m[1]; }
    return ['code' => $code, 'raw' => $raw];
}

function login(string $base, string $jar, string $user, string $pass): bool {
    $tmp = sys_get_temp_dir();
    @unlink($jar);
    $g = curl($base . '/pages/auth/login.php', ['-c', $jar]);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $g['raw'], $m)) { return false; }
    $body = "$tmp/mi_body.txt";
    file_put_contents($body, http_build_query(['username' => $user, 'password' => $pass, 'csrf_token' => $m[1]]));
    $r = curl($base . '/pages/auth/login.php', ['-c', $jar, '-b', $jar,
        '-H', 'Content-Type: application/x-www-form-urlencoded', '--data-binary', '@' . $body]);
    @unlink($body);
    return $r['code'] === 302;
}

function csrf_of(string $raw): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $raw, $m) ? $m[1] : '';
}

$jar  = "$img/mi_admin.txt";
$jar2 = "$img/mi_viewer.txt";
$csv  = "$img/mi_test.csv";

// ---------------- login ----------------
ok(login($BASE, $jar, 'admin', 'P@ssw0rd'), 'login admin (Super Admin) sukses');
$isSuper = true; // 'admin' = super_admin di seed

$db = dbc();
$before = (int) $db->query('SELECT COUNT(*) FROM banks')->fetch_row()[0];

// ---------------- 1. template ----------------
$t = curl($BASE . '/pages/masters/banks/import.php?template=1', ['-b', $jar]);
ok($t['code'] === 200, 'unduh template CSV -> HTTP 200');
ok(stripos($t['raw'], 'text/csv') !== false, 'template ber-Content-Type text/csv');
ok(stripos($t['raw'], 'Kode,Nama,Catatan') !== false, 'template punya header Kode,Nama,Catatan');

// ---------------- 2. impor valid ----------------
$page = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar]);
$token = csrf_of($page['raw']);
ok($token !== '', 'form import memuat csrf_token');

file_put_contents($csv, "code,name,note\nUJI-A,Bank Uji A,catatan A\nUJI-B,Bank Uji B,catatan B\n");
$r = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar, '-F', 'csrf_token=' . $token, '-F', 'mode=upsert', '-F', 'file=@' . $csv . ';type=text/csv']);
ok($r['code'] === 200 && str_contains($r['raw'], '2</strong> baris baru'), 'impor 2 baris valid -> laporan "2 baris baru"');
$after = (int) $db->query('SELECT COUNT(*) FROM banks')->fetch_row()[0];
ok($after === $before + 2, "jumlah baris banks bertambah 2 ($before -> $after)");
$audit = (int) $db->query("SELECT COUNT(*) FROM audit_trails WHERE action='masters.import'")->fetch_row()[0];
ok($audit >= 1, 'audit_trails mencatat action=masters.import');
ok((int) $db->query("SELECT COUNT(*) FROM banks WHERE code IN ('UJI-A','UJI-B')")->fetch_row()[0] === 2, 'baris UJI-A & UJI-B tersimpan');

// ---------------- 3. all-or-nothing ----------------
$page = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar]);
$token = csrf_of($page['raw']);
$n0 = (int) $db->query('SELECT COUNT(*) FROM banks')->fetch_row()[0];
file_put_contents($csv, "code,name\nUJI-C,Bank Uji C\nUJI-D,\n");
$r = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar, '-F', 'csrf_token=' . $token, '-F', 'file=@' . $csv . ';type=text/csv']);
$n1 = (int) $db->query('SELECT COUNT(*) FROM banks')->fetch_row()[0];
ok($r['code'] === 200 && str_contains($r['raw'], 'dibatalkan'), '1 baris rusak -> laporan "dibatalkan"');
ok($n1 === $n0, "rollback: tidak ada baris tersimpan ($n0 -> $n1)");
ok((int) $db->query("SELECT COUNT(*) FROM banks WHERE code='UJI-C'")->fetch_row()[0] === 0, 'baris valid UJI-C pun tidak tersimpan (all-or-nothing)');

// ---------------- 4. upsert ----------------
$page = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar]);
$token = csrf_of($page['raw']);
file_put_contents($csv, "code,name\nUJI-A,Bank Uji A (diubah)\n");
$r = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar, '-F', 'csrf_token=' . $token, '-F', 'mode=upsert', '-F', 'file=@' . $csv . ';type=text/csv']);
$name = (string) $db->query("SELECT name FROM banks WHERE code='UJI-A'")->fetch_row()[0];
$cnt  = (int) $db->query("SELECT COUNT(*) FROM banks WHERE code='UJI-A'")->fetch_row()[0];
ok($name === 'Bank Uji A (diubah)' && $cnt === 1, 'upsert: baris UJI-A diperbarui, tidak duplikat');

// ---------------- 5. mode insert ----------------
$page = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar]);
$token = csrf_of($page['raw']);
file_put_contents($csv, "code,name\nUJI-A,Coba Timpa\n");
$r = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar, '-F', 'csrf_token=' . $token, '-F', 'mode=insert', '-F', 'file=@' . $csv . ';type=text/csv']);
$name = (string) $db->query("SELECT name FROM banks WHERE code='UJI-A'")->fetch_row()[0];
ok(str_contains($r['raw'], 'dibatalkan') && $name === 'Bank Uji A (diubah)', 'mode insert: kode duplikat -> error, tidak menimpa');

// ---------------- 6. RBAC ----------------
if (login($BASE, $jar2, 'staff', 'P@ssw0rd')) {
    $r = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar2]);
    ok($r['code'] === 403, "pengguna tanpa masters.manage -> 403 (dapat {$r['code']})");
} else {
    ok(false, 'login staff gagal (tidak dapat menguji RBAC)');
}

// ---------------- 7. formula injection ----------------
$page = curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar]);
$token = csrf_of($page['raw']);
file_put_contents($csv, "code,name\nUJI-F,=1+1\n");
curl($BASE . '/pages/masters/banks/import.php', ['-b', $jar, '-F', 'csrf_token=' . $token, '-F', 'file=@' . $csv . ';type=text/csv']);
$note = (string) $db->query("SELECT name FROM banks WHERE code='UJI-F'")->fetch_row()[0];
ok($note === '=1+1', 'nilai formula disimpan sebagai teks apa adanya (tidak dieksekusi)');

// ---------------- bersih-bersih ----------------
$db->query("DELETE FROM banks WHERE code IN ('UJI-A','UJI-B','UJI-C','UJI-F')");
$db->close();
@unlink($csv); @unlink($jar); @unlink($jar2);

echo "\n$pass lulus, $fail gagal\n";
exit($fail === 0 ? 0 : 1);
