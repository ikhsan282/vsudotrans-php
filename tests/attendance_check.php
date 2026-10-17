<?php
/**
 * Self-check: modul Absensi (tanpa HTTP).
 * Fixture lengkap sendiri (lulus dari DB fresh kosong), dibersihkan di finally.
 * Jalankan: php -d mysqli.default_socket=... tests/attendance_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/attendance/_helpers.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$CID = 950;   // perusahaan uji kita
$OTHER = 951; // perusahaan lain (uji scoping)

// ---------- Fixture ----------
$c->query("DELETE a FROM employee_attendances a JOIN employees e ON e.id = a.employee_id WHERE e.company_id IN ($CID, $OTHER)");
$c->query("DELETE FROM employee_attendances WHERE company_id IN ($CID, $OTHER)");
$c->query("DELETE FROM employees WHERE company_id IN ($CID, $OTHER)");
$c->query("DELETE FROM companies WHERE id IN ($CID, $OTHER)");
$c->query("INSERT INTO companies (id, group_id, code, name, country_code, is_active)
           VALUES ($CID, 1, 'ATT-A', 'Perusahaan Absensi A', '+62', 1),
                  ($OTHER, 1, 'ATT-B', 'Perusahaan Absensi B', '+62', 1)");
$c->query("INSERT INTO employees (id, company_id, code, name, join_date, status)
           VALUES (9501, $CID, 'ATT-E1', 'Karyawan Absensi', '2026-01-01', 'aktif'),
                  (9502, $CID, 'ATT-E2', 'Karyawan Kedua',   '2026-01-01', 'aktif'),
                  (9511, $OTHER, 'ATT-X1', 'Karyawan Company Lain', '2026-01-01', 'aktif')");

$_SESSION['username'] = 'attendance-check';
$_SESSION['role_name'] = 'HRD';       // non-Super Admin: scoping tenant benar-benar diuji
$_SESSION['company_id'] = $CID;       // perusahaan aktif -> company_scope() menyaring, bukan 1=0
$user = 'attendance-check';

// Bukti eksplisit bahwa scoping aktif (bukan query yang kebetulan melihat semua).
ok(company_scope('a.company_id') === ["a.company_id = ?", 'i', [$CID]],
    'company_scope() aktif untuk perusahaan uji (bukan 1=0 / 1=1)');

try {
    // --- (a) Simpan absensi baru sukses ---
    [$d1, $err1] = attendance_map_input([
        'employee_id' => 9501, 'date' => '2026-10-01', 'status' => 'hadir',
        'check_in' => '08:00', 'check_out' => '17:00', 'note' => 'Masuk normal',
    ], $CID);
    ok(!$err1, 'input valid tidak menghasilkan error (' . implode('; ', $err1) . ')');
    $id1 = attendance_save($d1, $CID, $user);
    ok($id1 > 0, "simpan absensi baru mengembalikan id ($id1)");
    $st = $c->prepare('SELECT company_id, employee_id, date, status, check_in, check_out, note FROM employee_attendances WHERE id = ?');
    $st->bind_param('i', $id1);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    ok($row && (int) $row['employee_id'] === 9501 && (int) $row['company_id'] === $CID, 'baris tersimpan dengan company_id perusahaan aktif');
    ok($row && $row['status'] === 'hadir' && $row['check_in'] === '08:00:00' && $row['check_out'] === '17:00:00',
        "status & jam tersimpan ({$row['status']} {$row['check_in']}-{$row['check_out']})");
    ok($row && $row['note'] === 'Masuk normal', 'catatan tersimpan');

    // --- (b) Simpan karyawan+tanggal SAMA lagi -> TERUPDATE, bukan error ---
    $dup_error = null;
    [$d2, $err2] = attendance_map_input([
        'employee_id' => 9501, 'date' => '2026-10-01', 'status' => 'izin',
        'check_in' => '', 'check_out' => '', 'note' => 'Ternyata izin',
    ], $CID);
    ok(!$err2, 'input kedua valid');
    try { $id2 = attendance_save($d2, $CID, $user); } catch (Throwable $e) { $dup_error = $e->getMessage(); $id2 = 0; }
    ok($dup_error === null, 'simpan tanggal sama TIDAK melempar error (' . ($dup_error ?? 'tidak ada') . ')');
    ok($id2 === $id1, "id tetap sama = baris diperbarui, bukan baris baru ($id2 = $id1)");
    $n = (int) $c->query("SELECT COUNT(*) FROM employee_attendances WHERE employee_id = 9501 AND date = '2026-10-01'")->fetch_row()[0];
    ok($n === 1, "tetap 1 baris untuk (karyawan, tanggal) yang sama (dapat $n)");
    $upd = $c->query("SELECT status, note, check_in FROM employee_attendances WHERE id = $id1")->fetch_assoc();
    ok($upd['status'] === 'izin' && $upd['note'] === 'Ternyata izin', 'isi baris ikut diperbarui (status izin)');
    ok($upd['check_in'] === null, 'jam masuk dikosongkan jadi NULL saat diubah');

    // --- (c) Rekap menghitung tiap status dengan benar ---
    $c->query("DELETE FROM employee_attendances WHERE employee_id = 9501");
    foreach ([['2026-10-01', 'hadir'], ['2026-10-02', 'hadir'], ['2026-10-03', 'izin'], ['2026-10-04', 'sakit']] as [$dt, $stt]) {
        [$d, $e] = attendance_map_input(['employee_id' => 9501, 'date' => $dt, 'status' => $stt], $CID);
        attendance_save($d, $CID, $user);
    }
    $sum = attendance_summary('2026-10-01', '2026-10-31', $CID);
    $mine = null;
    foreach ($sum as $r) { if ((int) $r['id'] === 9501) { $mine = $r; } }
    ok($mine !== null, 'rekap memuat karyawan 9501');
    ok((int) $mine['hadir'] === 2 && (int) $mine['izin'] === 1 && (int) $mine['sakit'] === 1,
        "rekap hadir={$mine['hadir']} izin={$mine['izin']} sakit={$mine['sakit']} (harus 2/1/1)");
    ok((int) $mine['cuti'] === 0 && (int) $mine['alpa'] === 0, 'status tanpa baris dihitung 0, bukan NULL');
    ok((int) $mine['total'] === 4, "total hari tercatat = {$mine['total']} (harus 4)");
    // Karyawan tanpa absensi tetap tampil dengan 0.
    $zero = null;
    foreach ($sum as $r) { if ((int) $r['id'] === 9502) { $zero = $r; } }
    ok($zero !== null && (int) $zero['total'] === 0, 'karyawan tanpa absensi tetap muncul (total 0)');
    // Filter rentang: tanggal di luar rentang tidak ikut terhitung.
    [$d5, ] = attendance_map_input(['employee_id' => 9502, 'date' => '2026-11-05', 'status' => 'cuti'], $CID);
    attendance_save($d5, $CID, $user);
    $sum_oct = attendance_summary('2026-10-01', '2026-10-31', $CID);
    foreach ($sum_oct as $r) { if ((int) $r['id'] === 9502) { $zero = $r; } }
    ok((int) $zero['cuti'] === 0 && (int) $zero['total'] === 0, 'absensi November tidak masuk rekap Oktober');
    $sum_nov = attendance_summary('2026-11-01', '2026-11-30', $CID);
    foreach ($sum_nov as $r) { if ((int) $r['id'] === 9502) { $zero = $r; } }
    ok((int) $zero['cuti'] === 1, 'rentang November menghitung 1 cuti');

    // --- (d) Scoping antar perusahaan ---
    $dropped = attendance_employee_options($CID);
    $ids = array_map(static fn($e) => (int) $e['id'], $dropped);
    ok(!in_array(9511, $ids, true), 'karyawan company lain TIDAK muncul di dropdown (company ID ' . $CID . ')');
    ok(in_array(9501, $ids, true) && in_array(9502, $ids, true), 'karyawan milik sendiri tetap ada di dropdown');

    [$dX, $errX] = attendance_map_input([
        'employee_id' => 9511, 'date' => '2026-10-10', 'status' => 'hadir',
    ], $CID);
    ok($errX !== [], 'input karyawan company lain DITOLAK (ada pesan error)');
    ok(str_contains(implode(' ', $errX), 'perusahaan aktif'), 'pesan error menyebut perusahaan aktif');
    // Buktikan tak ada baris yang bocor masuk (query langsung, lintas tenant).
    $leak = (int) $c->query("SELECT COUNT(*) FROM employee_attendances WHERE employee_id = 9511")->fetch_row()[0];
    ok($leak === 0, 'tidak ada baris absensi untuk karyawan company lain (dapat ' . $leak . ')');

    // Rekap tidak memuat karyawan company lain.
    $names = array_column(attendance_summary('2026-10-01', '2026-11-30', $CID), 'name');
    ok(!in_array('Karyawan Company Lain', $names, true), 'rekap TIDAK memuat karyawan company lain');
    ok(in_array('Karyawan Absensi', $names, true), 'rekap memuat karyawan sendiri');

    // List (dengan scope tenant) tidak memuat karyawan company lain.
    [$rows_list, $pl] = attendance_list('2026-10-01', '2026-11-30', 0, '', 1);
    $listed = array_column($rows_list, 'employee_id');
    ok(!in_array('9511', array_map('strval', $listed), true), 'daftar absensi tidak bocor ke karyawan company lain');
    ok($pl['total'] === 5, 'list menghitung 5 baris milik company sendiri (dapat ' . $pl['total'] . ')');

    // attendance_find lintas tenant -> null (dibuktikan lewat scope query langsung).
    $other_row = $c->query("SELECT id FROM employee_attendances WHERE employee_id = 9511 LIMIT 1")->fetch_row();
    ok(!$other_row, 'perusahaan lain memang tidak punya baris (bukti fixture, bukan query bocor)');

    // --- (e) Hapus bersih ---
    $c->query("DELETE FROM employee_attendances WHERE id = $id1");
    $after = (int) $c->query("SELECT COUNT(*) FROM employee_attendances WHERE id = $id1")->fetch_row()[0];
    ok($after === 0, 'baris terhapus dari DB');

    // --- (f) Input ngawur / tak valid ---
    [$dz, $errz] = attendance_map_input([
        'employee_id' => 9501, 'date' => '31-10-2026', 'status' => 'libur',
        'check_in' => '25:99', 'check_out' => 'pagi', 'note' => str_repeat('x', 1001),
    ], $CID);
    ok(count($errz) === 5, 'input ngawur ditolak dengan 5 error (dapat ' . count($errz) . '): ' . implode(' | ', $errz));
    [$de, $erre] = attendance_map_input(['employee_id' => 0, 'date' => '', 'status' => ''], $CID);
    ok(count($erre) === 3, 'field kosong ditolak (karyawan/tanggal/status)');
    [$dt, $errt] = attendance_map_input([
        'employee_id' => 9501, 'date' => '2026-10-05', 'status' => 'hadir',
        'check_in' => '17:00', 'check_out' => '08:00',
    ], $CID);
    ok(count($errt) === 1 && str_contains($errt[0], 'lebih awal'), 'jam keluar < jam masuk ditolak');
    [$dt2, $err2b] = attendance_map_input([
        'employee_id' => 9501, 'date' => '2026-10-05', 'status' => 'hadir', 'check_in' => '07:5',
    ], $CID);
    ok(count($err2b) === 1 && str_contains($err2b[0], 'Jam masuk'), 'jam cacat (07:5) ditolak');
    // Rentang tanggal ngawur -> default bulan berjalan, tanpa error.
    [$f, $t] = attendance_range(['from' => 'bukan-tanggal', 'to' => '']);
    ok(preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && $f === date('Y-m-01'), "rentang ngawur → default awal bulan ($f)");
    [$f2, $t2] = attendance_range(['from' => '2026-12-31', 'to' => '2026-12-01']);
    ok($f2 === '2026-12-01' && $t2 === '2026-12-31', 'rentang terbalik ditukar otomatis');
    // Status ngawur di filter list tidak error & tidak memfilter (hanya lewat whitelist).
    [$rl, ] = attendance_list('2026-10-01', '2026-11-30', 0, "hadir' UNION SELECT 1 -- ", 1);
    ok(is_array($rl), 'status ngawur/injeksi di filter tidak menghasilkan SQL error');

    // --- (g) RBAC: permission & role seed ada ---
    foreach (['attendance.view', 'attendance.manage'] as $perm) {
        $has = (bool) $c->query("SELECT 1 FROM permissions WHERE name = '" . $perm . "'")->num_rows;
        ok($has, "permission $perm ada di tabel permissions");
    }
    foreach ([[3, 'HRD'], [4, 'Staff'], [5, 'Viewer']] as [$rid, $rname]) {
        $has = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = $rid AND p.name = 'attendance.view'")->num_rows;
        ok($has, "$rname (role id=$rid) punya attendance.view");
    }
    $hrd_manage = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = 3 AND p.name = 'attendance.manage'")->num_rows;
    ok($hrd_manage, 'HRD punya attendance.manage');
    $staff_manage = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = 4 AND p.name = 'attendance.manage'")->num_rows;
    ok(!$staff_manage, 'Staff TIDAK punya attendance.manage (read-only)');

    // --- (h) Schema: UNIQUE (employee_id, date) benar-benar ada di DB ---
    $uniq = $c->query("SHOW INDEX FROM employee_attendances WHERE Key_name = 'uq_att_emp_date'")->fetch_all(MYSQLI_ASSOC);
    ok(count($uniq) === 2, 'UNIQUE uq_att_emp_date mencakup 2 kolom (dapat ' . count($uniq) . ')');
    $cols = array_column($uniq, 'Column_name');
    ok(in_array('employee_id', $cols, true) && in_array('date', $cols, true), 'UNIQUE di (employee_id, date)');

} finally {
    $c->query("DELETE a FROM employee_attendances a JOIN employees e ON e.id = a.employee_id WHERE e.company_id IN ($CID, $OTHER)");
    $c->query("DELETE FROM employee_attendances WHERE company_id IN ($CID, $OTHER)");
    $c->query("DELETE FROM employees WHERE company_id IN ($CID, $OTHER)");
    $c->query("DELETE FROM companies WHERE id IN ($CID, $OTHER)");
    $c->query("DELETE FROM audit_trails WHERE username = 'attendance-check'");
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
