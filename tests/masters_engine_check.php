<?php
// Self-check validator murni engine masters (tanpa MySQL). Jalankan: php tests/masters_engine_check.php
// Fungsi yang diuji diambil dari _engine.php; tidak memanggil DB.
require_once __DIR__ . '/../pages/masters/_engine.php';

assert(master_code_ok('BCA-01') === true);
assert(master_code_ok('bca') === false);          // huruf kecil ditolak
assert(master_code_ok('') === false);
assert(master_code_ok(str_repeat('A', 16)) === false); // lebih dari 15
assert(master_num_ok('12.5') === true);
assert(master_num_ok('abc') === false);
assert(master_num_ok('') === false);

// siklus: node 1 -> induk 2, node 2 -> induk 3 ; set induk node 3 = 1 membentuk siklus
$parents = [1 => 2, 2 => 3, 3 => null];
assert(master_cycle_detected($parents, 3, 1) === true);
assert(master_cycle_detected($parents, 1, 1) === true);   // induk = diri sendiri
assert(master_cycle_detected($parents, 3, null) === false);
assert(master_cycle_detected($parents, 1, 3) === false);  // tidak siklus

echo "Semua kasus masters lolos.\n";
