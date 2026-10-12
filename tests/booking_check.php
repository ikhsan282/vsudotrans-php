<?php
/** Self-check: transisi status booking & format resi. Jalankan: php tests/booking_check.php */
require_once __DIR__ . '/../includes/booking.php';

$ok = [['draft','confirmed'], ['confirmed','scheduled'], ['scheduled','on_trip'], ['on_trip','done'],
       ['draft','cancelled'], ['on_trip','rescheduled'], ['draft','rescheduled']];
$bad = [['draft','scheduled'], ['draft','done'], ['done','cancelled'], ['cancelled','draft'],
        ['confirmed','done'], ['scheduled','confirmed'], ['done','done']];
foreach ($ok as [$f, $t]) {
    assert(booking_transition_ok($f, $t) === true, "harus sah: $f>$t");
}
foreach ($bad as [$f, $t]) {
    assert(booking_transition_ok($f, $t) === false, "harus tolak: $f>$t");
}

$seen = [];
for ($i = 0; $i < 2000; $i++) {
    $d = booking_doc_number('TRV');
    assert(preg_match('/^TRV-[A-Z2-9]{8}$/', $d) === 1, "format salah: $d");
    $seen[$d] = true;
}
assert(count($seen) >= 1990, 'resi terlalu banyak duplikat');
echo "Semua self-check booking lolos.\n";
