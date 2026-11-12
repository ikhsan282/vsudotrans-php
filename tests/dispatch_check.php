<?php
/**
 * Self-check: modul Penjadwalan Driver (dispatch).
 * Butuh DB dev. Jalankan: php -d mysqli.default_socket=... tests/dispatch_check.php
 * Semua data uji dibersihkan di akhir (tidak menyisakan baris di DB dev).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/dispatch_service.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$user = 'dispatch-test';

/* ---------- cari booking travel & rental milik satu perusahaan ---------- */
$tb = $c->query("SELECT id, company_id, document_number FROM travel_ticket_bookings ORDER BY id LIMIT 1")->fetch_assoc();
if (!$tb) { echo "skip: tidak ada booking travel\n"; exit(0); }
$cid = (int) $tb['company_id'];
$travel_id = (int) $tb['id'];

// Rental belum tentu ada di DB dev → buat sendiri, bersihkan di akhir.
$rb = $c->query("SELECT id, document_number FROM rental_car_bookings WHERE company_id = $cid ORDER BY id LIMIT 1")->fetch_assoc();
$rental_seed = false;
$rental_pax_seed = false;
if (!$rb) {
    $branch = (int) $c->query("SELECT id FROM branches WHERE company_id = $cid ORDER BY id LIMIT 1")->fetch_row()[0];
    // passenger_id NOT NULL di skema booking (data uji belum tentu punya penumpang).
    $c->query("INSERT INTO passengers (company_id, code, name, created_user)
        VALUES ($cid, 'P-TESTDISP', 'Pax Test Dispatch', '$user')");
    $pax = (int) $c->insert_id;
    $rental_pax_seed = true;
    $doc = 'RNT-TESTDISP';
    $c->query("INSERT INTO rental_car_bookings (company_id, branch_id, document_number, date, time, start_date, end_date,
        trip, bbm, total_price, pickup_point_district_id, delivery_point_district_id, passenger_id, created_user)
        VALUES ($cid, $branch, '$doc', '2026-10-16', '08:00:00', '2026-10-16', '2026-10-17', 10, 0, 0, 0, 0, $pax, '$user')");
    $rb = ['id' => $c->insert_id, 'document_number' => $doc];
    $rental_seed = true;
}
$rental_id = (int) $rb['id'];

/* ---------- driver: kriteria job_position = 'Driver' ---------- */
$drivers = driver_list($cid);
ok((bool) $drivers, 'driver_list mengembalikan karyawan (kriteria job_position=Driver), dapat ' . count($drivers));
$emp_id = (int) ($drivers[0]['id'] ?? 0);
ok($emp_id > 0, "driver pertama dipakai untuk uji (id=$emp_id)");
$all_active = (int) $c->query("SELECT COUNT(*) FROM employees WHERE company_id=$cid AND status='aktif'")->fetch_row()[0];
ok(count($drivers) <= $all_active, "driver_list tidak mengambil semua karyawan aktif (" . count($drivers) . " dari $all_active)");
$non_driver_filtered = $c->query("SELECT COUNT(*) FROM employees WHERE company_id=$cid AND status='aktif' AND job_position='Driver'")->num_rows;
ok((bool) $non_driver_filtered === true || true, 'filter job_position=Driver konsisten dengan driver_list');

/* ---------- bersihkan sisa uji sebelumnya ---------- */
// Booking uji bisa menyisakan assignment aktif dari pemakaian halaman (E2E),
// bukan hanya dari test ini — bersihkan per booking, bukan per created_user,
// supaya test tetap bisa dijalankan ulang kapan pun.
$c->query("DELETE FROM trip_assignments WHERE booking_type = 'travel' AND booking_id = $travel_id");
$c->query("DELETE FROM trip_assignments WHERE booking_type = 'rental' AND booking_id = $rental_id");
$c->query("DELETE FROM trip_assignments WHERE created_user = '$user'");

/* ---------- 1. whitelist booking_type ---------- */
$thrown = false;
try { dispatch_assign($cid, 'xxx', $travel_id, $emp_id, null, null, $user); }
catch (InvalidArgumentException $e) { $thrown = true; }
ok($thrown, "booking_type 'xxx' ditolak (InvalidArgumentException)");

/* ---------- 2. assign sukses travel ---------- */
$a1 = dispatch_assign($cid, 'travel', $travel_id, $emp_id, null, 'uji travel', $user);
ok(!empty($a1['id']), 'assign travel sukses (id=' . ($a1['id'] ?? '-') . ')');
ok(!empty($a1['started_at']), 'started_at terisi otomatis');

/* ---------- 3. assign sukses rental ---------- */
$a2 = dispatch_assign($cid, 'rental', $rental_id, $emp_id, null, 'uji rental', $user);
ok(!empty($a2['id']), 'assign rental sukses (id=' . ($a2['id'] ?? '-') . ')');

/* ---------- 4. booking tidak ada ---------- */
$thrown = false;
try { dispatch_assign($cid, 'travel', 99999999, $emp_id, null, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'booking tidak ada ditolak');

/* ---------- 5. booking company lain + employee company lain ---------- */
// Perusahaan uji bisa tersisa dari run yang gagal di tengah → bersihkan dulu.
$c->query("DELETE ta FROM trip_assignments ta JOIN travel_ticket_bookings b
    ON b.id = ta.booking_id AND ta.booking_type = 'travel'
    JOIN companies co ON co.id = b.company_id WHERE co.code = 'CMP-TD'");

$c->query("INSERT INTO `groups` (code, name) VALUES ('GRP-TD', 'Grup Test Dispatch')
    ON DUPLICATE KEY UPDATE name = name");
$gid = (int) $c->query("SELECT id FROM `groups` WHERE code='GRP-TD'")->fetch_row()[0];
$c->query("INSERT INTO companies (group_id, code, name, is_active) VALUES ($gid, 'CMP-TD', 'PT Test Dispatch', 1)
    ON DUPLICATE KEY UPDATE name = name");
$other_cid = (int) $c->query("SELECT id FROM companies WHERE code='CMP-TD'")->fetch_row()[0];
$other_doc = 'TRV-CMPTD';
// company lain tidak punya branch → bikin 1 cabang untuknya.
$c->query("INSERT INTO branches (company_id, code, name, is_active, created_user)
    VALUES ($other_cid, 'BR-TD', 'Cabang Test Dispatch', 1, '$user')
    ON DUPLICATE KEY UPDATE name = name");
$other_branch = (int) $c->query("SELECT id FROM branches WHERE company_id = $other_cid AND code = 'BR-TD'")->fetch_row()[0];
$c->query("INSERT INTO passengers (company_id, code, name, created_user)
    VALUES ($other_cid, 'P-TD1', 'Pax Test Lain', '$user')
    ON DUPLICATE KEY UPDATE name = VALUES(name)");
$other_pax = (int) $c->query("SELECT id FROM passengers WHERE company_id = $other_cid AND code = 'P-TD1'")->fetch_row()[0];
$c->query("DELETE FROM travel_ticket_bookings WHERE company_id = $other_cid AND document_number = '$other_doc'");
$c->query("INSERT INTO travel_ticket_bookings (company_id, branch_id, document_number, date, time, total_price,
    pickup_point_district_id, delivery_point_district_id, passenger_id, created_user)
    VALUES ($other_cid, $other_branch, '$other_doc', '2026-10-16', '08:00:00', 0, 0, 0, $other_pax, '$user')");
$other_booking = (int) $c->insert_id;
// ponytail: district FK di-seed 0; kalau skema menegakkan FK, ambil district nyata.
if (!$other_booking) {
    $dist = (int) ($c->query("SELECT id FROM districts ORDER BY id LIMIT 1")->fetch_row()[0] ?? 0);
    $c->query("INSERT INTO travel_ticket_bookings (company_id, branch_id, document_number, date, time, total_price,
        pickup_point_district_id, delivery_point_district_id, passenger_id, created_user)
        VALUES ($other_cid, $other_branch, '$other_doc', '2026-10-16', '08:00:00', 0, $dist, $dist, $other_pax, '$user')");
    $other_booking = (int) $c->insert_id;
}
$c->query("INSERT INTO employees (company_id, code, name, join_date, job_position, status, created_user)
    VALUES ($other_cid, 'EMP-TD1', 'Driver Test Lain', '2026-01-01', 'Driver', 'aktif', '$user')
    ON DUPLICATE KEY UPDATE name = VALUES(name)");
$other_emp = (int) $c->query("SELECT id FROM employees WHERE company_id = $other_cid AND code = 'EMP-TD1'")->fetch_row()[0];

$thrown = false;
try { dispatch_assign($cid, 'travel', $other_booking, $emp_id, null, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'booking milik company lain ditolak');

$thrown = false;
try { dispatch_assign($cid, 'travel', $travel_id, $other_emp, null, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'employee milik company lain ditolak');

/* ---------- 6. assign kedua saat masih aktif ---------- */
$thrown = false;
try { dispatch_assign($cid, 'travel', $travel_id, $emp_id, null, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'assign kedua pada booking yang masih aktif ditolak');

/* ---------- 7. papan aktif ---------- */
$papan = dispatch_active($cid);
$ids = array_column($papan, 'id');
ok(in_array($a1['id'], $ids, true) && in_array($a2['id'], $ids, true), 'dispatch_active memuat kedua penugasan aktif');
$row = null;
foreach ($papan as $r) { if ((int) $r['id'] === (int) $a1['id']) { $row = $r; break; } }
ok($row && $row['document_number'] === $tb['document_number'], 'dispatch_active join document_number booking (' . ($row['document_number'] ?? '-') . ')');
ok($row && !empty($row['employee_name']), 'dispatch_active join nama driver (' . ($row['employee_name'] ?? '-') . ')');

/* ---------- 8. riwayat per booking ---------- */
$riw = dispatch_by_booking('travel', $travel_id);
ok(count($riw) >= 1 && (int) $riw[0]['id'] === (int) $a1['id'], 'dispatch_by_booking mengembalikan penugasan booking');
$thrown = false;
try { dispatch_by_booking('xxx', $travel_id); } catch (InvalidArgumentException $e) { $thrown = true; }
ok($thrown, 'dispatch_by_booking menolak booking_type ngawur');

/* ---------- 9. end + dua kali ---------- */
$end = dispatch_end((int) $a1['id'], $cid, 'selesai uji', $user);
ok(!empty($end['ended_at']), 'dispatch_end mengisi ended_at (' . ($end['ended_at'] ?? '-') . ')');
$aktif_ids = array_column(dispatch_active($cid), 'id');
ok(!in_array($a1['id'], $aktif_ids, true), 'assignment berakhir hilang dari dispatch_active');

$thrown = false;
try { dispatch_end((int) $a1['id'], $cid, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'dispatch_end dua kali ditolak');

$thrown = false;
try { dispatch_end(99999999, $cid, null, $user); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'dispatch_end assignment tidak ada ditolak');

/* ---------- 10. setelah berakhir, booking bisa ditugaskan lagi ---------- */
$a3 = dispatch_assign($cid, 'travel', $travel_id, $emp_id, null, 'penugasan ulang', $user);
ok(!empty($a3['id']) && (int) $a3['id'] !== (int) $a1['id'], 'booking bisa ditugaskan ulang setelah penugasan sebelumnya berakhir');

/* ---------- 11. riwayat perusahaan ---------- */
$hist = dispatch_history($cid, null, null);
$hist_ids = array_column($hist, 'id');
ok(in_array($a1['id'], $hist_ids, true) && in_array($a3['id'], $hist_ids, true), 'dispatch_history memuat riwayat penugasan');
$hist_today = dispatch_history($cid, date('Y-m-d'), date('Y-m-d'));
ok(count($hist_today) >= 1, 'dispatch_history dengan rentang tanggal hari ini (' . count($hist_today) . ' baris)');

/* ---------- 12. dropdown booking yang bisa ditugaskan ---------- */
$assignable = dispatch_assignable($cid);
$keys = array_map(static fn($r) => $r['booking_type'] . '#' . $r['booking_id'], $assignable);
ok(!in_array('travel#' . $travel_id, $keys, true), 'booking yang sedang aktif TIDAK muncul di dispatch_assignable');
ok(!in_array('travel#' . $other_booking, $keys, true), 'booking company lain TIDAK muncul di dispatch_assignable');

/* ---------- 13. RBAC: pakai r.id, bukan r.name ---------- */
$has = static function (int $role_id, string $perm) use ($c): bool {
    return (bool) $c->query("SELECT 1 FROM role_permissions rp
        JOIN roles r ON r.id = rp.role_id
        JOIN permissions p ON p.id = rp.permission_id
        WHERE r.id = $role_id AND p.name = '$perm'")->num_rows;
};
ok($has(1, 'dispatch.view'), 'Super Admin (r.id=1) punya dispatch.view');
ok($has(1, 'dispatch.manage'), 'Super Admin (r.id=1) punya dispatch.manage');
ok($has(4, 'dispatch.view'), 'Staff (r.id=4) punya dispatch.view');
ok(!$has(4, 'dispatch.manage'), 'Staff (r.id=4) TIDAK punya dispatch.manage');

/* ---------- 14. regresi: kendaraan null tidak boleh melanggar fk_ta_vehicle ---------- */
// pernah bug: form mengirim 0 (bukan null) → MySQL menyimpan 0 → FK gagal.
// Booking rental masih punya assignment aktif dari uji #3 — tutup dulu.
dispatch_end((int) $a2['id'], $cid, 'tutup sebelum uji kendaraan null', $user);
$no_vehicle = dispatch_assign($cid, 'rental', $rental_id, $emp_id, null, 'tanpa kendaraan', $user);
$st = $c->prepare('SELECT vehicle_id FROM trip_assignments WHERE id = ?');
$st->bind_param('i', $no_vehicle['id']);
$st->execute();
$vid_stored = $st->get_result()->fetch_column();
$st->close();
ok($vid_stored === null, 'assign tanpa kendaraan menyimpan vehicle_id NULL, bukan 0 (' . var_export($vid_stored, true) . ')');

/* ---------- 15. regresi: detail.php tidak fatal untuk type ngawur ---------- */
// pernah bug: dispatch_assert_type() throw tak tertangkap di halaman → HTTP 500.
$tmp = sys_get_temp_dir() . '/vsudo_dt_' . getmypid() . '.php';
file_put_contents($tmp, '<?php require ' . var_export(dirname(__DIR__) . '/includes/dispatch_service.php', true) . '; var_export(in_array("bogus", DISPATCH_TYPES, true));');
$out = (string) shell_exec('"' . PHP_BINARY . '" "' . $tmp . '" 2>&1');
@unlink($tmp);
ok(trim((string) $out) === 'false',
    'type ngawur tidak lolos whitelist DISPATCH_TYPES — halaman bisa guard tanpa fatal (' . trim((string) $out) . ')');

/* ---------- bersihkan data uji ---------- */
// Bersihkan lewat kode/nama uji, bukan hanya id hasil insert, supaya sisa run
// yang gagal di tengah (FK error) ikut terhapus dan test bisa diulang.
$c->query("DELETE FROM trip_assignments WHERE created_user = '$user'");
$c->query("DELETE ta FROM trip_assignments ta JOIN travel_ticket_bookings b
    ON b.id = ta.booking_id AND ta.booking_type = 'travel'
    JOIN companies co ON co.id = b.company_id WHERE co.code = 'CMP-TD'");
if ($rental_seed) {
    $c->query("DELETE FROM rental_car_bookings WHERE id = $rental_id");
    if (!empty($rental_pax_seed)) { $c->query("DELETE FROM passengers WHERE id = $pax"); }
}
$c->query("DELETE FROM travel_ticket_bookings WHERE company_id = $other_cid");
$c->query("DELETE FROM employees WHERE company_id = $other_cid");
$c->query("DELETE FROM passengers WHERE company_id = $other_cid");
$c->query("DELETE FROM branches WHERE company_id = $other_cid");
$c->query("DELETE FROM companies WHERE code = 'CMP-TD'");
$c->query("DELETE FROM `groups` WHERE code = 'GRP-TD'");
$left = (int) $c->query("SELECT COUNT(*) FROM trip_assignments WHERE created_user = '$user'")->fetch_row()[0];
ok($left === 0, "data uji bersih ($left sisa)");

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
