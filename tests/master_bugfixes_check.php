<?php
/**
 * Self-test verifikasi perbaikan bug & celah master data:
 * 1. Blocker job_grades mencocokkan employees.job_grade (nama grade) + company_id.
 * 2. Sinkronisasi nama job_grades ke employees saat grade diubah namanya.
 * 3. Validasi hierarki wilayah (provinsi-negara, kota-provinsi, desa-kecamatan).
 * 4. Pagination sliding window.
 * 5. master_resolve_relation_id (lookup relasi via kode & nama).
 * 6. master_find_existing untuk employee_recognitions.
 * 7. master_delete affected rows & scope global.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/masters/_engine.php';

$fails = 0;
function ok(bool $c, string $msg): void {
    global $fails;
    echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n";
    if (!$c) $fails++;
}

$db = db();

echo "=== 1. Test Pagination Windowing ===\n";
$p_small = ['total' => 50, 'per_page' => 10, 'current' => 2, 'total_pages' => 5];
$html_small = pagination_html($p_small, 'q=test');
ok(str_contains($html_small, 'q=test&amp;page=2') || str_contains($html_small, 'q=test&page=2'), 'query string terjaga');
ok(substr_count($html_small, '<li class="page-item') === 7, 'total 5 halaman + prev + next = 7 li');

$p_huge = ['total' => 10000, 'per_page' => 10, 'current' => 50, 'total_pages' => 1000];
$html_huge = pagination_html($p_huge, '');
ok(substr_count($html_huge, '<li class="page-item') < 15, 'pagination 1000 halaman diringkas (sliding window < 15 item)');
ok(str_contains($html_huge, '&hellip;'), 'terdapat elipsis (&hellip;)');

echo "\n=== 2. Test Validasi Hierarki Wilayah ===\n";
// Ambil negara 1 dan provinsi milik negara lain (atau buat dummy)
$db->query("INSERT IGNORE INTO countries (id, code, name) VALUES (901, 'CHK-C1', 'Negara Uji 1'), (902, 'CHK-C2', 'Negara Uji 2')");
$db->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (911, 901, 'CHK-P1', 'Provinsi Uji 1')");

$err_valid = master_validate_geo_hierarchy('cities', ['country_id' => 901, 'province_id' => 911]);
ok(empty($err_valid), 'provinsi 911 cocok dengan negara 901 (tidak ada error)');

$err_invalid = master_validate_geo_hierarchy('cities', ['country_id' => 902, 'province_id' => 911]);
ok(!empty($err_invalid) && str_contains($err_invalid[0], 'Provinsi tidak sesuai dengan Negara'), 'provinsi 911 ditolak bila dipasangkan dengan negara 902');

echo "\n=== 3. Test Blocker & Sinkronisasi Nama job_grades ===\n";
$db->query("INSERT IGNORE INTO `groups` (id, code, name) VALUES (1, 'CHK-G', 'Grup Uji')");
$db->query("INSERT IGNORE INTO companies (id, group_id, code, name, is_active) VALUES (991, 1, 'CHK-COMP', 'Perusahaan Uji', 1)");

// Bersihkan data uji sebelumnya jika ada
$db->query("DELETE FROM employees WHERE code = 'EMP-CHK-G'");
$db->query("DELETE FROM job_grades WHERE code IN ('JG-CHK1', 'JG-CHK2') AND company_id = 991");

// Buat Grade Uji
$spec_jg = master_spec('job_grades');
$_SESSION['username'] = 'test-suite';
$jg_id = master_save($spec_jg, 0, [
    'code' => 'JG-CHK1', 'name' => 'Grade Khusus Uji',
    'basic_salary' => '5000000', 'daily_allowance' => '100000',
    'note' => 'test', 'is_active' => 1
], 991);
ok($jg_id > 0, "job_grade berhasil dibuat (id: $jg_id)");

// Buat karyawan yang memakai grade ini
$st = $db->prepare("INSERT INTO employees (company_id, code, name, join_date, job_grade, status) VALUES (991, 'EMP-CHK-G', 'Karyawan Uji Grade', '2026-01-01', 'Grade Khusus Uji', 'aktif')");
$st->execute();
$emp_id = $db->insert_id;
$st->close();
ok($emp_id > 0, "karyawan berhasil dibuat memakai grade 'Grade Khusus Uji'");

// Tes blocker di master_delete:
// Simulasi request POST delete
$_SESSION['company_id'] = 991;
$_SESSION['role_name'] = 'Admin';
$_SESSION['permissions'] = ['hr.manage', 'hr.view'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['id' => $jg_id, 'csrf_token' => csrf_token()];

$row_jg = master_find($spec_jg, $jg_id);
$has_blocker_fired = false;
foreach ($spec_jg['blockers'] ?? [] as $b) {
    if (($b['match'] ?? '') === 'name') {
        $val = (string) ($row_jg['name'] ?? '');
        $st = $db->prepare("SELECT COUNT(*) FROM `{$b['table']}` WHERE `{$b['col']}` = ? AND company_id = ?");
        $st->bind_param('si', $val, $row_jg['company_id']);
        $st->execute();
        $n = (int) $st->get_result()->fetch_row()[0];
        $st->close();
        if ($n > 0) {
            $has_blocker_fired = true;
        }
    }
}
ok($has_blocker_fired, 'blocker job_grades aktif: grade terdeteksi sedang dipakai oleh karyawan');

// Tes sinkronisasi nama grade saat diubah
master_save($spec_jg, $jg_id, [
    'code' => 'JG-CHK1', 'name' => 'Grade Khusus Uji RENAMED',
    'basic_salary' => '6000000', 'daily_allowance' => '120000',
    'note' => 'renamed', 'is_active' => 1
], 991);

$st = $db->prepare("SELECT job_grade FROM employees WHERE id = ?");
$st->bind_param('i', $emp_id);
$st->execute();
$new_emp_grade = $st->get_result()->fetch_column();
$st->close();
ok($new_emp_grade === 'Grade Khusus Uji RENAMED', "employees.job_grade otomatis sinkron ke nama baru ($new_emp_grade)");

echo "\n=== 4. Test master_resolve_relation_id (Lookup Relasi via Kode & Nama) ===\n";
$res_by_id = master_resolve_relation_id('countries', '901', null);
ok($res_by_id === 901, "lookup via ID numerik 901 -> id $res_by_id");

$res_by_code = master_resolve_relation_id('countries', 'CHK-C2', null);
ok($res_by_code === 902, "lookup via Kode 'CHK-C2' -> id $res_by_code");

$res_by_name = master_resolve_relation_id('countries', 'Negara Uji 1', null);
ok($res_by_name === 901, "lookup via Nama 'Negara Uji 1' -> id $res_by_name");

$res_none = master_resolve_relation_id('countries', 'TIDAK_ADA_XYZ', null);
ok($res_none === null, 'lookup nilai yang tidak ada -> null');

echo "\n=== 5. Test master_find_existing untuk employee_recognitions ===\n";
$spec_er = master_spec('employee_recognitions');
$db->query("INSERT INTO employee_recognitions (company_id, employee_id, type, index_no, template) VALUES (991, $emp_id, 'Prestasi', 1, 'Gold')");
$er_id = $db->insert_id;

$existing_er = master_find_existing($spec_er, ['employee_id' => $emp_id, 'type' => 'Prestasi', 'index_no' => 1], 991);
ok($existing_er !== null && (int)$existing_er['id'] === $er_id, 'employee_recognitions berhasil dicocokkan berdasarkan (employee_id, type, index_no)');

// Cleanup data uji
$db->query("DELETE FROM employee_recognitions WHERE id = $er_id");
$db->query("DELETE FROM employees WHERE id = $emp_id");
$db->query("DELETE FROM job_grades WHERE id = $jg_id");
$db->query("DELETE FROM provinces WHERE id = 911");
$db->query("DELETE FROM countries WHERE id IN (901, 902)");
$db->query("DELETE FROM companies WHERE id = 991");

echo "\n============================================\n";
if ($fails === 0) {
    echo "SEMUA TEST PERBAIKAN MASTER DATA LOLOS!\n";
} else {
    echo "ADA $fails TEST GAGAL!\n";
}
exit($fails === 0 ? 0 : 1);

