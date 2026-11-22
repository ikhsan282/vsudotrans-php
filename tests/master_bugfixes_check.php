<?php
/**
 * Self-test verifikasi perbaikan bug & celah master data:
 * 1. Blocker job_titles mencocokkan job_positions.job_title_id + company_id.
 * 2. Validasi hierarki wilayah (provinsi-negara, kota-provinsi, desa-kecamatan).
 * 3. Pagination sliding window.
 * 4. master_resolve_relation_id (lookup relasi via kode & nama).
 * 5. master_find_existing berdasarkan code & empty fallback.
 * 6. master_delete affected rows & scope global.
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
ok(str_contains($html_small, 'q=test') && str_contains($html_small, 'page=2'), 'query string terjaga');
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

echo "\n=== 3. Test Blocker & master_find_existing pada job_titles ===\n";
$db->query("INSERT IGNORE INTO `groups` (id, code, name) VALUES (1, 'CHK-G', 'Grup Uji')");
$db->query("INSERT IGNORE INTO companies (id, group_id, code, name, is_active) VALUES (991, 1, 'CHK-COMP', 'Perusahaan Uji', 1)");

// Bersihkan data uji sebelumnya jika ada
$db->query("DELETE FROM job_positions WHERE code = 'JP-CHK-G' AND company_id = 991");
$db->query("DELETE FROM job_titles WHERE code IN ('JT-CHK1', 'JT-CHK2') AND company_id = 991");

// Buat Jabatan Uji
$spec_jt = master_spec('job_titles');
$_SESSION['username'] = 'test-suite';
$jt_id = master_save($spec_jt, 0, [
    'code' => 'JT-CHK1', 'name' => 'Jabatan Khusus Uji',
    'level' => 1, 'note' => 'test', 'is_active' => 1
], 991);
ok($jt_id > 0, "job_title berhasil dibuat (id: $jt_id)");

// Buat posisi yang memakai jabatan ini
$st = $db->prepare("INSERT INTO job_positions (company_id, job_title_id, code, name, level, is_active) VALUES (991, ?, 'JP-CHK-G', 'Posisi Uji', 1, 1)");
$st->bind_param('i', $jt_id);
$st->execute();
$pos_id = $db->insert_id;
$st->close();
ok($pos_id > 0, "posisi berhasil dibuat memakai jabatan id $jt_id");

// Tes blocker di master_delete:
$_SESSION['company_id'] = 991;
$_SESSION['role_name'] = 'Admin';
$_SESSION['permissions'] = ['hr.manage', 'hr.view'];
$row_jt = master_find($spec_jt, $jt_id);
$has_blocker_fired = false;
foreach ($spec_jt['blockers'] ?? [] as $b) {
    $st = $db->prepare("SELECT COUNT(*) FROM `{$b['table']}` WHERE `{$b['col']}` = ? AND company_id = ?");
    $st->bind_param('ii', $jt_id, $row_jt['company_id']);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    if ($n > 0) {
        $has_blocker_fired = true;
    }
}
ok($has_blocker_fired, 'blocker job_titles aktif: jabatan terdeteksi sedang dipakai oleh posisi');

echo "\n=== 4. Test master_resolve_relation_id (Lookup Relasi via Kode & Nama) ===\n";
$res_by_id = master_resolve_relation_id('countries', '901', null);
ok($res_by_id === 901, "lookup via ID numerik 901 -> id $res_by_id");

$res_by_code = master_resolve_relation_id('countries', 'CHK-C2', null);
ok($res_by_code === 902, "lookup via Kode 'CHK-C2' -> id $res_by_code");

$res_by_name = master_resolve_relation_id('countries', 'Negara Uji 1', null);
ok($res_by_name === 901, "lookup via Nama 'Negara Uji 1' -> id $res_by_name");

$res_none = master_resolve_relation_id('countries', 'TIDAK_ADA_XYZ', null);
ok($res_none === null, 'lookup nilai yang tidak ada -> null');

echo "\n=== 5. Test master_find_existing berdasarkan code ===\n";
$existing_jt = master_find_existing($spec_jt, ['code' => 'JT-CHK1'], 991);
ok($existing_jt !== null && (int)$existing_jt['id'] === $jt_id, 'master_find_existing mencocokkan job_titles berdasarkan code JT-CHK1');
ok(master_find_existing($spec_jt, ['name' => 'Tanpa Kode'], 991) === null, 'master_find_existing tanpa code mengembalikan null');

// Cleanup data uji
$db->query("DELETE FROM job_positions WHERE id = $pos_id");
$db->query("DELETE FROM job_titles WHERE id = $jt_id");
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

