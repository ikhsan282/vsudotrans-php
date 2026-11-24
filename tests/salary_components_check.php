<?php
/**
 * Self-check & HTTP RBAC check: Master Komponen Gaji, Penugasan Bermasa Berlaku,
 * Penghapusan Periode Payroll & Gaji Pokok/Uang Harian di form Karyawan.
 *
 * Usage: C:\xampp\php\php.exe tests/salary_components_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payroll_service.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();

$pass = 0;
$fail = 0;
function sc_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}\n";
    }
}

payroll_ensure_salary_schema();

echo "1. Verifikasi Skema DB, Penghapusan Tabel Payroll & Migrasi Data Awal...\n";
$tbls = array_column($db->query("SHOW TABLES")->fetch_all(MYSQLI_NUM), 0);
sc_ok(in_array('salary_components', $tbls, true), 'Tabel salary_components tersedia');
sc_ok(in_array('employee_salary_components', $tbls, true), 'Tabel employee_salary_components tersedia');
sc_ok(
    !in_array('payroll_periods', $tbls, true)
    && !in_array('payroll_items', $tbls, true)
    && !in_array('payroll_item_components', $tbls, true),
    'Tabel periode payroll (payroll_periods, payroll_items, payroll_item_components) sudah dihapus'
);

$empCols = array_column($db->query("SHOW COLUMNS FROM `employees`")->fetch_all(MYSQLI_ASSOC), 'Field');
sc_ok(!in_array('basic_salary', $empCols, true) && !in_array('daily_allowance', $empCols, true), 'Kolom employees.basic_salary & daily_allowance sudah dihapus');

$budiEff = payroll_employee_effective_components(1, 1, '2026-11-01', '2026-11-30');
sc_ok(
    abs($budiEff['monthly_earning'] - 3200000.0) < 0.01 && abs($budiEff['daily_earning'] - 60000.0) < 0.01,
    'Data gaji awal Budi Santoso (id=1) termigrasi akurat: 3.200.000 + 60.000/hari'
);
$migCnt = (int) $db->query("SELECT COUNT(*) FROM employee_salary_components WHERE company_id = 1")->fetch_row()[0];
sc_ok($migCnt >= 12, "Minimal 12 penugasan komponen gaji hasil migrasi tersedia (got: {$migCnt})");

$tmpCompId    = 0;
$tmpAssignIds = [];
$tmpStaffId   = 0;
$jarAdmin     = sys_get_temp_dir() . '/sc_adm_' . getmypid() . '.txt';
$jarStaff     = sys_get_temp_dir() . '/sc_stf_' . getmypid() . '.txt';
@unlink($jarAdmin);
@unlink($jarStaff);

$_SESSION['user_id']        = 1;
$_SESSION['username']       = 'superadmin';
$_SESSION['role_name']      = 'Super Admin';
$_SESSION['is_super_admin'] = true;

try {
    echo "2. Verifikasi CRUD Komponen & Delete Blocker...\n";
    $tmpCode = 'TST-SC-' . getmypid();
    $tmpCompId = payroll_component_save(0, [
        'code' => $tmpCode, 'name' => 'Tunjangan Uji Efektif', 'category' => 'earning', 'calc_basis' => 'monthly', 'is_active' => 1, 'note' => 'uji',
    ], 1, 'test');
    sc_ok($tmpCompId > 0, "Komponen gaji uji {$tmpCode} berhasil dibuat (#{$tmpCompId})");

    $dupThrown = false;
    try {
        payroll_component_save(0, ['code' => $tmpCode, 'name' => 'Duplikat', 'category' => 'earning', 'calc_basis' => 'monthly'], 1, 'test');
    } catch (InvalidArgumentException $e) {
        $dupThrown = true;
    }
    sc_ok($dupThrown, 'Kode komponen duplikat dalam 1 perusahaan ditolak');

    echo "3. Verifikasi Masa Berlaku, Validasi Overlap & Auto Close H-1...\n";
    $badDateThrown = false;
    try {
        payroll_assignment_save(0, 1, 1, $tmpCompId, 500000.0, '2098-05-10', '2098-05-01', null, 'test', false);
    } catch (InvalidArgumentException $e) {
        $badDateThrown = true;
    }
    sc_ok($badDateThrown, 'Tanggal berakhir < tanggal mulai ditolak');

    $aid1 = payroll_assignment_save(0, 1, 1, $tmpCompId, 500000.0, '2098-01-01', null, 'Fase 1', 'test', false);
    $tmpAssignIds[] = $aid1;
    sc_ok($aid1 > 0, "Assign fase 1 berhasil (#{$aid1})");

    $delBlocked = false;
    try {
        payroll_component_delete($tmpCompId);
    } catch (RuntimeException $e) {
        $delBlocked = true;
    }
    sc_ok($delBlocked, 'Hapus komponen ditolak saat masih ter-assign pada karyawan');

    $overlapThrown = false;
    try {
        payroll_assignment_save(0, 1, 1, $tmpCompId, 750000.0, '2098-06-01', '2098-06-30', 'Bentrok', 'test', false);
    } catch (RuntimeException $e) {
        $overlapThrown = true;
    }
    sc_ok($overlapThrown, 'Penugasan bentrok ditolak saat auto_close_prev = false');

    $aid2 = payroll_assignment_save(0, 1, 1, $tmpCompId, 750000.0, '2098-06-01', '2098-06-30', 'Fase 2 Juni 2098', 'test', true);
    $tmpAssignIds[] = $aid2;
    $prevAfterClose = payroll_assignment_find($aid1);
    sc_ok($prevAfterClose !== null && $prevAfterClose['effective_to'] === '2098-05-31', 'auto_close_prev otomatis menutup penugasan sebelumnya ke H-1 (2098-05-31)');

    echo "4. Verifikasi Kalkulasi Masa Berlaku Komponen Efektif...\n";
    $effJun = payroll_employee_effective_components(1, 1, '2098-06-01', '2098-06-30');
    $junCodes = array_column($effJun['components'], 'component_code');
    sc_ok(
        abs($effJun['monthly_earning'] - 3950000.0) < 0.01 && in_array($tmpCode, $junCodes, true),
        'Periode Juni 2098 menjumlahkan Gapok 3.200.000 + Komponen Juni 750.000 = 3.950.000'
    );

    $effJul = payroll_employee_effective_components(1, 1, '2098-07-01', '2098-07-31');
    $julCodes = array_column($effJul['components'], 'component_code');
    sc_ok(
        abs($effJul['monthly_earning'] - 3200000.0) < 0.01 && !in_array($tmpCode, $julCodes, true),
        'Periode Juli 2098 mengecualikan komponen yang berakhir 2098-06-30 (kembali 3.200.000)'
    );

    echo "5. Verifikasi HTTP, Sidebar Master HR, Route Removal & RBAC...\n";
    $http = static function (string $jar, string $path, ?array $post = null) use ($base): array {
        $hdr = sys_get_temp_dir() . '/sc_h_' . getmypid() . '.txt';
        $pf  = null;
        $cmd = 'curl.exe -s -D ' . escapeshellarg($hdr) . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
        if ($post !== null) {
            $pf = sys_get_temp_dir() . '/sc_p_' . getmypid() . '.txt';
            file_put_contents($pf, http_build_query($post));
            $cmd .= ' -H "Content-Type: application/x-www-form-urlencoded" --data-binary @' . escapeshellarg($pf);
        }
        $body = (string) shell_exec($cmd . ' ' . escapeshellarg($base . $path));
        $h = is_file($hdr) ? (string) file_get_contents($hdr) : '';
        @unlink($hdr);
        if ($pf !== null) {
            @unlink($pf);
        }
        preg_match('/HTTP\/\S+\s+(\d{3})/', $h, $m);
        return ['code' => (int) ($m[1] ?? 0), 'body' => $body];
    };
    $loginAs = static function (string $jar, string $user, string $pw) use ($http): bool {
        $lp = $http($jar, '/pages/auth/login.php');
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lp['body'], $mc);
        $res = $http($jar, '/pages/auth/login.php', ['csrf_token' => $mc[1] ?? '', 'username' => $user, 'password' => $pw]);
        return $res['code'] === 302;
    };

    sc_ok($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin HTTP OK');
    $dash = $http($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dash['body'], $mSw)) {
        $http($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mSw[1], 'company_id' => '1']);
    }

    $empDet = $http($jarAdmin, '/pages/employees/detail.php?id=1');
    sc_ok(
        $empDet['code'] === 200
        && !str_contains($empDet['body'], 'name="basic_salary"')
        && !str_contains($empDet['body'], 'name="daily_allowance"')
        && str_contains($empDet['body'], 'Komponen Gaji')
        && str_contains($empDet['body'], '3.200.000')
        && str_contains($empDet['body'], 'pages/hr/salary_components/index.php'),
        'employees/detail.php?id=1 HTTP 200 tanpa input Gaji Pokok/Uang Harian lama & terhubung ke pages/hr/salary_components/'
    );

    $cmpIdx = $http($jarAdmin, '/pages/hr/salary_components/index.php');
    sc_ok(
        $cmpIdx['code'] === 200
        && str_contains($cmpIdx['body'], 'GAPOK')
        && str_contains($cmpIdx['body'], 'POT-BPJS')
        && str_contains($cmpIdx['body'], 'Master HR')
        && str_contains($cmpIdx['body'], 'Komponen Gaji')
        && str_contains($cmpIdx['body'], 'Penugasan Komponen')
        && !str_contains($cmpIdx['body'], 'Periode Payroll')
        && !str_contains($cmpIdx['body'], 'pages/payroll/periods'),
        'pages/hr/salary_components/index.php HTTP 200 & sidebar tampil di Master HR tanpa Periode Payroll'
    );

    $cmpFrm = $http($jarAdmin, '/pages/hr/salary_components/form.php');
    sc_ok($cmpFrm['code'] === 200 && str_contains($cmpFrm['body'], 'formSalaryComponent'), 'pages/hr/salary_components/form.php HTTP 200');

    $asgPg = $http($jarAdmin, '/pages/hr/salary_components/assignments.php');
    sc_ok(
        $asgPg['code'] === 200
        && str_contains($asgPg['body'], 'Budi Santoso')
        && str_contains($asgPg['body'], '<form')
        && !str_contains($asgPg['body'], 'Periode Payroll'),
        'pages/hr/salary_components/assignments.php HTTP 200 memuat daftar penugasan tanpa tombol Periode Payroll'
    );

    $oldPayroll = $http($jarAdmin, '/pages/payroll/periods/index.php');
    sc_ok($oldPayroll['code'] === 404, 'Route lama /pages/payroll/periods/index.php sudah dihapus (HTTP 404)');

    // Buat user Staff sementara (role_id = 4) -> tidak punya payroll.view
    $stUname = 'sc_staff_' . getmypid();
    $stHash  = password_hash('Staff@1234', PASSWORD_DEFAULT);
    $stInsU  = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'Staff Uji SC', 4, 1, 1)");
    $stMail  = $stUname . '@test.local';
    $stInsU->bind_param('sss', $stUname, $stMail, $stHash);
    $stInsU->execute();
    $tmpStaffId = (int) $db->insert_id;
    $stInsU->close();

    sc_ok($loginAs($jarStaff, $stUname, 'Staff@1234'), 'Login Staff uji HTTP OK');
    $empStaff = $http($jarStaff, '/pages/employees/detail.php?id=1');
    sc_ok(
        $empStaff['code'] === 200
        && !str_contains($empStaff['body'], 'tab-empf-salary')
        && !str_contains($empStaff['body'], '3.200.000'),
        'RBAC: Staff melihat employees/detail.php?id=1 TANPA tab Komponen Gaji maupun nominal gaji'
    );
    sc_ok($http($jarStaff, '/pages/hr/salary_components/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/hr/salary_components/index.php');
    sc_ok($http($jarStaff, '/pages/hr/salary_components/assignments.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/hr/salary_components/assignments.php');
} finally {
    if ($tmpStaffId > 0) {
        $db->query("DELETE FROM users WHERE id = {$tmpStaffId}");
        $db->query("DELETE FROM login_attempts WHERE username LIKE 'sc_staff_%'");
    }
    foreach ($tmpAssignIds as $aid) {
        $db->query("DELETE FROM employee_salary_components WHERE id = {$aid}");
    }
    if ($tmpCompId > 0) {
        $db->query("DELETE FROM salary_components WHERE id = {$tmpCompId}");
    }
    @unlink($jarAdmin);
    @unlink($jarStaff);
}

echo "\nRingkasan Salary Components Check: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
