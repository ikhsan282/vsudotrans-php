<?php
/**
 * Self-check: tarik hari hadir Payroll dari Absensi (tanpa HTTP).
 * Fixture sendiri (perusahaan 952 + 2 karyawan + 4 absensi), dibersihkan di finally.
 * Jalankan: php -d mysqli.default_socket=... tests/payroll_attendance_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payroll_service.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$CID = 952;
$YEAR = 2098; // tahun jauh di depan supaya tidak bertabrakan dengan data nyata
$MONTH = 3;

$c->query("DELETE i FROM payroll_items i JOIN payroll_periods p ON p.id = i.payroll_period_id WHERE p.company_id = $CID");
$c->query("DELETE FROM payroll_periods WHERE company_id = $CID");
$c->query("DELETE FROM employee_attendances WHERE company_id = $CID");
$c->query("DELETE FROM employees WHERE company_id = $CID");
$c->query("DELETE FROM companies WHERE id = $CID");
$c->query("INSERT INTO companies (id, group_id, code, name, is_active)
           VALUES ($CID, 1, 'PAY-A', 'Perusahaan Payroll Absensi', 1)");
$c->query("INSERT INTO job_grades (id, company_id, code, name, basic_salary, daily_allowance) VALUES
           (9521, $CID, 'PAY-G1', 'Grade Payroll', 3000000.00, 50000.00)");
$c->query("INSERT INTO employees (company_id, code, name, job_grade, join_date, status) VALUES
           ($CID, 'PAY-E1', 'Karyawan Hadir Izin', 'Grade Payroll', '2026-01-01', 'aktif'),
           ($CID, 'PAY-E2', 'Karyawan Alpa Tanpa Catatan', 'Grade Payroll', '2026-01-01', 'aktif')");

$_SESSION['username'] = 'payroll-att-check';
$_SESSION['role_name'] = 'HRD'; // non-Super Admin: scoping tenant diuji
$_SESSION['company_id'] = $CID;

try {
    // --- Fixture absensi: E1 = 1 hadir + 1 izin + 1 alpa; E2 = 1 alpa saja ---
    $c->query("INSERT INTO employee_attendances (company_id, employee_id, date, status, created_user) VALUES
        ($CID, (SELECT id FROM employees WHERE code = 'PAY-E1'), '$YEAR-03-02', 'hadir', 'test'),
        ($CID, (SELECT id FROM employees WHERE code = 'PAY-E1'), '$YEAR-03-03', 'izin',  'test'),
        ($CID, (SELECT id FROM employees WHERE code = 'PAY-E1'), '$YEAR-03-04', 'alpa',  'test'),
        ($CID, (SELECT id FROM employees WHERE code = 'PAY-E2'), '$YEAR-03-05', 'alpa',  'test')");

    // --- Periode uji (draft) ---
    $g = payroll_generate($CID, $YEAR, $MONTH, 'test');
    $pid = (int) $g['period_id'];
    ok($g['items'] === 2, "generate periode: 2 item karyawan (dapat {$g['items']})");

    // Isi hari hadir dulu supaya perubahan benar-benar terlihat (bukan 0 -> 0).
    $c->query("UPDATE payroll_items SET days_present = 30, net_salary = 0 WHERE payroll_period_id = $pid");

    // --- (1) Tarik dari Absensi ---
    $r = payroll_pull_attendance($pid, $CID, 'test');
    ok($r['updated'] === 2, "2 item diperbarui (dapat {$r['updated']})");
    // Angka payroll dulu (untuk cek idempoten di langkah 3).
    $first = [];
    foreach (payroll_items($pid) as $it) { $first[$it['employee_code']] = (int) $it['days_present']; }
    ok($first['PAY-E1'] === 2, "E1 hadir+izin = 2 hari (dapat {$first['PAY-E1']}, alpa & hari tanpa catatan tidak dihitung)");
    ok($first['PAY-E2'] === 0, "E2 alpa = 0 hari (dapat {$first['PAY-E2']}, hari tanpa catatan TIDAK diasumsikan hadir)");

    // --- (2) net_salary dihitung ulang lewat payroll_net(), potongan manual dipertahankan ---
    $items = [];
    foreach (payroll_items($pid) as $it) { $items[$it['employee_code']] = $it; }
    $e1 = $items['PAY-E1'];
    $c->query("UPDATE payroll_items SET days_present = 30, deductions = 100000 WHERE id = {$e1['id']}");
    payroll_pull_attendance($pid, $CID, 'test');
    $e1 = $c->query("SELECT days_present, deductions, net_salary FROM payroll_items WHERE id = {$e1['id']}")->fetch_assoc();
    $expect = payroll_net((float) $items['PAY-E1']['basic_salary'], (float) $items['PAY-E1']['daily_allowance'], 2, 100000.0);
    ok(abs((float) $e1['net_salary'] - $expect) < 0.001,
        "net_salary = pokok + harian x 2 - potongan 100.000 = {$e1['net_salary']} (harus $expect)");
    ok((int) $e1['days_present'] === 2 && abs((float) $e1['deductions'] - 100000.0) < 0.001,
        'hari hadir ditimpa jadi 2, potongan manual tetap 100.000');

    // --- (3) Idempoten: jalan ulang tidak mengubah angka apa pun ---
    payroll_pull_attendance($pid, $CID, 'test'); // sinkronkan dulu setelah potongan manual di atas
    $before = $c->query("SELECT employee_id, days_present, net_salary FROM payroll_items WHERE payroll_period_id = $pid ORDER BY employee_id")->fetch_all(MYSQLI_ASSOC);
    $again = payroll_pull_attendance($pid, $CID, 'test');
    $after = $c->query("SELECT employee_id, days_present, net_salary FROM payroll_items WHERE payroll_period_id = $pid ORDER BY employee_id")->fetch_all(MYSQLI_ASSOC);
    ok($again['updated'] === 0, "jalan ulang idempoten — 0 item berubah (dapat {$again['updated']})");
    ok($before === $after, 'jalan ulang tidak mengubah satu pun days_present / net_salary');

    // --- (4) Absensi bulan lain & perusahaan lain tidak ikut terhitung ---
    $c->query("INSERT INTO employee_attendances (company_id, employee_id, date, status, created_user)
               VALUES ($CID, (SELECT id FROM employees WHERE code = 'PAY-E2'), '$YEAR-04-02', 'hadir', 'test')");
    payroll_pull_attendance($pid, $CID, 'test');
    $e2 = $c->query("SELECT days_present FROM payroll_items WHERE payroll_period_id = $pid AND employee_id = (SELECT id FROM employees WHERE code = 'PAY-E2')")->fetch_row()[0];
    ok((int) $e2 === 0, "absensi bulan lain (April) tidak masuk periode Maret (dapat $e2)");

    // --- (5) Periode non-draft ditolak (final & paid) ---
    payroll_period_set_status($pid, $CID, 'final', 'test');
    $thrown = false;
    try { payroll_pull_attendance($pid, $CID, 'test'); }
    catch (RuntimeException $e) { $thrown = true; }
    ok($thrown, 'periode final ditolak (throw)');
    payroll_period_set_status($pid, $CID, 'paid', 'test');
    $thrown = false;
    try { payroll_pull_attendance($pid, $CID, 'test'); }
    catch (RuntimeException $e) { $thrown = true; }
    ok($thrown, 'periode paid ditolak (throw)');

    // --- (6) Scope tenant: periode perusahaan lain ditolak ---
    $thrown = false;
    try { payroll_pull_attendance($pid, $CID + 999, 'test'); }
    catch (RuntimeException $e) { $thrown = true; }
    ok($thrown, 'periode perusahaan lain ditolak (scope company_id)');
} finally {
    $c->query("DELETE i FROM payroll_items i JOIN payroll_periods p ON p.id = i.payroll_period_id WHERE p.company_id = $CID");
    $c->query("DELETE FROM payroll_periods WHERE company_id = $CID");
    $c->query("DELETE FROM employee_attendances WHERE company_id = $CID");
    $c->query("DELETE FROM employees WHERE company_id = $CID");
    $c->query("DELETE FROM job_grades WHERE company_id = $CID");
    $c->query("DELETE FROM companies WHERE id = $CID");
    $c->query("DELETE FROM audit_trails WHERE username = 'payroll-att-check'");
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
