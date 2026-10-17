<?php
/**
 * Self-check urut naik/turun item master (butuh DB dev):
 *   php -c /opt/data/cache/scratch/localdb/php.ini tests/masters_reorder_check.php
 *
 * Fixture: 3 metode pembayaran milik perusahaan 1 + 2 milik perusahaan 990 (tenant lain).
 * Menguji master_normalize_order() (duplikat/NULL → 10,20,30…), master_move() naik & turun
 * (urutan bertukar di dalam scope), dan tenant lain tidak tersentuh.
 * Diperiksa lewat urutan efektif pada ORDER BY yang sama dengan master_list().
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/masters/_engine.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }
ob_start(); // tahan output: config.php memanggil session_start() (butuh header bersih)
$csrf_token = 'reorder-check-token';

$c = db();
$spec = master_spec('payment_methods');
$order = $spec['order']; // 'sort_order'

// ---- Fixture (id tetap + dibersihkan dulu supaya aman diulang) ----
$A = 5011;  // perusahaan 1, urutan 30 → target utama
$B = 5012;  // perusahaan 1, urutan 20 → tetangga di atas
$C = 5013;  // perusahaan 1, urutan 40 → tetangga di bawah
$X = 5014;  // perusahaan 990, urutan 10
$Y = 5015;  // perusahaan 990, urutan 20
$ids = [$A, $B, $C, $X, $Y];

// Urutan efektif = persis ORDER BY master_list() (`` `col` `` ASC), id sebagai pemecah seri.
$list = static function (int $cid) use ($c, $order, $spec): array {
    $mode = (($spec['unique'] ?? '') === 'company_or_null') ? '(company_id = ? OR company_id IS NULL)' : 'company_id = ?';
    $st = $c->prepare("SELECT id FROM `payment_methods` WHERE {$mode} ORDER BY `{$order}`, id");
    $st->bind_param('i', $cid);
    $st->execute();
    $out = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $st->close();
    return $out;
};
$vals = static function (array $ids) use ($c, $order): array {
    $in = implode(',', $ids);
    $rows = $c->query("SELECT id, `{$order}` FROM `payment_methods` WHERE id IN ($in) ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['id']] = $r[$order] === null ? null : (int) $r[$order]; }
    return $out;
};

// Sesi uji: role non-Super-Admin di perusahaan 1, supaya scope tenant benar-benar diuji
// (Super Admin memakai 1=1 sehingga tetangga lintas perusahaan ikut — bukan yang ingin diuji).
$_SESSION['username'] = 'reorder-check';
$_SESSION['csrf_token'] = 'reorder-check-token';
$_SESSION['role_name'] = 'Admin';
$_SESSION['company_id'] = 1;
$_SESSION['permissions'] = ['finance.view', 'finance.manage'];
$_SERVER['REQUEST_METHOD'] = 'POST';

$post = static function (int $id, string $dir): void {
    $_POST = ['id' => $id, 'dir' => $dir, 'csrf_token' => 'reorder-check-token'];
};

/**
 * Jalankan master_move() di proses PHP terpisah. Aksi berakhir dengan redirect() → exit,
 * jadi menjalankannya inline akan membunuh proses tes; hasilnya dinilai dari isi tabel.
 */
$run_move = static function (int $id, string $dir, bool $csrf_ok = true): string {
    $payload = json_encode([
        'id' => $id, 'dir' => $dir, 'csrf' => $csrf_ok ? 'reorder-check-token' : 'salah',
        'role' => $_SESSION['role_name'], 'cid' => $_SESSION['company_id'],
    ]);
    $f = sys_get_temp_dir() . '/vsudo_move_payload.json';
    file_put_contents($f, $payload);
    @unlink($f . '.out');
    // Interpreter + php.ini yang sama dengan proses tes (PHP_BINARY menerima -c).
    $cmd = escapeshellarg(PHP_BINARY);
    if (php_ini_loaded_file() !== false) {
        $cmd .= ' -c ' . escapeshellarg((string) php_ini_loaded_file());
    }
    $cmd .= ' ' . escapeshellarg(__DIR__ . '/_move_run.php') . ' ' . escapeshellarg($f);
    shell_exec($cmd . ' >/dev/null 2>&1');
    return (string) @file_get_contents($f . '.out');
};

try {
foreach ($ids as $id) { $c->query("DELETE FROM payment_methods WHERE id = $id"); }
$c->query("DELETE FROM payment_methods WHERE code LIKE 'CHK-R%'");
$c->query("INSERT IGNORE INTO `groups` (id, code, name) VALUES (1, 'CHK-GRP', 'Grup Cek Urut')");
$c->query("INSERT IGNORE INTO companies (id, group_id, code, name, country_code, is_active)
           VALUES (990, 1, 'CHK-URUT', 'Perusahaan Cek Urut', '+62', 1)");

$mk = static function (int $id, int $cid, string $code, string $name, int $sort) use ($c): void {
    $st = $c->prepare('INSERT INTO payment_methods (id, company_id, code, name, sort_order, created_user) VALUES (?, ?, ?, ?, ?, ?)');
    $marker = 'reorder-check';
    $st->bind_param('iissis', $id, $cid, $code, $name, $sort, $marker);
    $st->execute();
    $st->close();
};
$mk($A, 1,   'CHK-RA', 'Cek A', 30);
$mk($B, 1,   'CHK-RB', 'Cek B', 20);
$mk($C, 1,   'CHK-RC', 'Cek C', 40);
$mk($X, 990, 'CHK-RX', 'Cek X', 10);
$mk($Y, 990, 'CHK-RY', 'Cek Y', 20);

// --- (1) Tabrakan data lama: duplikat & NULL → tidak ada lagi setelah normalisasi ---
$c->query("ALTER TABLE payment_methods MODIFY sort_order INT NULL"); // hanya di uji: schema kolomnya NOT NULL
$c->query("UPDATE payment_methods SET sort_order = 20 WHERE id = $A");   // duplikat dengan B
$c->query("UPDATE payment_methods SET sort_order = NULL WHERE id = $C"); // NULL data lama
ok($vals([$A, $B, $C]) === [$A => 20, $B => 20, $C => null],
    'fixture kotor: A=20 & B=20 duplikat, C=NULL');

// Normalisasi sebagai user perusahaan 1: baris company 1 (+ global) dinomori ulang
// menurut urutan efektif daftar (ORDER BY sort_order, id) → C(10), A(20), B(30),
// dan tenant 990 tidak tersentuh.
$_SESSION['role_name'] = 'Admin';
master_normalize_order($spec);
ok($vals([$A, $B, $C]) === [$A => 20, $B => 30, $C => 10],
    'normalisasi (perusahaan 1) → C,A,B = 10,20,30 (dapat ' . json_encode($vals([$A, $B, $C])) . ')');
ok($vals([$X, $Y]) === [$X => 10, $Y => 20], 'normalisasi tidak menyentuh tenant lain (990)');
ok($list(990) === [$X, $Y], 'list tenant 990 utuh: ' . implode(',', $list(990)));

// Super Admin (scope lintas tenant, 1=1): normalisasi menomori semua baris yang terlihat,
// jadi tidak ada lagi duplikat/NULL dan tiap nilai unik.
$_SESSION['role_name'] = 'Super Admin';
master_normalize_order($spec);
$all = array_values($vals([$A, $B, $X, $Y, $C]));
ok(count(array_unique($all)) === count($all) && !in_array(null, $all, true),
    'lintas tenant: nilai urut unik, tanpa NULL (dapat ' . json_encode($all) . ')');

// Kembali ke role ber-scope tenant untuk uji naik/turun: scope Super Admin (1=1) akan
// membuat baris tenant lain ikut jadi tetangga, sedangkan yang diuji adalah scoping.
$_SESSION['role_name'] = 'Admin';

    // --- (2) Turun: baris pertama daftar ↔ tetangga di bawahnya ---
    // Urutan awal diambil dari list (bukan ditebak) dan nilai awalnya dibaca dari DB; engine
    // diuji apa adanya, sedangkan nilai urut hasil normalisasi tidak diasumsikan 10/20/30.
    $l1 = $list(1);
    $first = $l1[0];
    $second = $l1[1];
    $third = $l1[2];
    // master_move() menormalisasi lebih dulu, jadi nilai urut awal dibaca tepat sebelum aksi
    // (bukan dari $v0 yang diambil sebelum normalisasi).
    $va = $vals([$first, $second]);
    $r1 = $run_move($first, 'down');
    $vb = $vals([$first, $second]);
    $expect = [$second => $va[$first], $first => $va[$second]]; // nilai kedua baris ditukar
    ksort($expect);
    $vb_sorted = $vb;
    ksort($vb_sorted);
    ok($list(1) === [$second, $first, $third],
        'turun: baris 1 & 2 bertukar (dapat ' . implode(',', $list(1)) . '; hasil=' . $r1 . ')');
    ok($vb_sorted === $expect, 'nilai urut bertukar: ' . json_encode($va) . ' → ' . json_encode($vb));

    // --- (3) Naik: kembali seperti semula ---
    $run_move($first, 'up');
    ok($list(1) === $l1, 'naik: daftar kembali seperti semula (dapat ' . implode(',', $list(1)) . ')');
    ok($vals([$first, $second]) === $va, 'nilai kembali seperti semula: ' . json_encode($vals([$first, $second])));

    // --- (4) Tepi daftar: naik dari baris teratas = no-op ---
    $run_move($first, 'up');
    ok($list(1) === $l1, 'naik dari puncak daftar: tidak ada perubahan');

    // --- (5) Arah ngawur / id tidak ada → tidak mengubah apa pun ---
    $run_move($first, 'sideways');
    $run_move(99999999, 'up');
    ok($list(1) === $l1, 'dir/id ngawur: urutan tetap');

    // --- (6) CSRF salah ditolak (403) dan urutan tidak berubah ---
    $run_move($first, 'down', false);
    ok($list(1) === $l1, 'CSRF salah: urutan tidak berubah');
} finally {
    foreach ($ids as $id) { $c->query("DELETE FROM payment_methods WHERE id = $id"); }
    $c->query("DELETE FROM payment_methods WHERE code LIKE 'CHK-R%'");
    $c->query("ALTER TABLE payment_methods MODIFY sort_order INT(11) NOT NULL DEFAULT 0"); // kembalikan bentuk schema
    $c->query("DELETE FROM companies WHERE id = 990 OR code = 'CHK-URUT'");
    $left = (int) $c->query("SELECT COUNT(*) FROM payment_methods WHERE id IN (" . implode(',', $ids) . ") OR code LIKE 'CHK-R%'")->fetch_row()[0];
    ok($left === 0, 'fixture dibersihkan (sisa ' . $left . ' baris)');
    $col = $c->query("SHOW COLUMNS FROM payment_methods LIKE 'sort_order'")->fetch_assoc();
    ok(stripos($col['Null'], 'NO') !== false, 'kolom sort_order kembali NOT NULL (' . $col['Type'] . ' ' . $col['Null'] . ')');
}

echo $fails === 0 ? "\nSemua kasus urut master lolos.\n" : "\n$fails kasus GAGAL.\n";
flush();
exit($fails === 0 ? 0 : 1);
