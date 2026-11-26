<?php
/**
 * Self-check & HTTP RBAC check: Modul Input Pengeluaran, Integrasi ke Buku Kas,
 * Laporan Keuangan, Dashboard, Export, dan Proteksi Akses.
 *
 * Usage: C:\xampp\php\php.exe tests/expenses_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/finance/_cash_query.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();

$pass = 0;
$fail = 0;
function exp_ok(bool $cond, string $label): void
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

echo "1. Verifikasi Unit Helper cash_parse_amount & Kategori Pengeluaran...\n";
exp_ok(abs(cash_parse_amount('250.000') - 250000.0) < 0.01, 'cash_parse_amount("250.000") === 250000.0');
exp_ok(abs(cash_parse_amount('250.000,50') - 250000.5) < 0.01, 'cash_parse_amount("250.000,50") === 250000.5');
exp_ok(abs(cash_parse_amount('250000.50') - 250000.5) < 0.01, 'cash_parse_amount("250000.50") === 250000.5');
exp_ok(abs(cash_parse_amount('Rp 1.250.000') - 1250000.0) < 0.01, 'cash_parse_amount("Rp 1.250.000") === 1250000.0');
exp_ok(cash_parse_amount('abc') === 0.0 && cash_parse_amount('-5') === -5.0, 'cash_parse_amount("abc" / "-5") mengembalikan <= 0 untuk ditolak validator');

$cats = cash_expense_categories();
$masterOut = cash_master_categories('out', 1, true);
$outCodes  = array_column($masterOut, 'code');
exp_ok(
    isset($cats['BBM'], $cats['Biaya Gaji Karyawan'], $cats['Biaya Komisi Karyawan'], $cats['Pengeluaran Sparepart'], $cats['Pengeluaran Lainnya'])
    && in_array('GJKRY', $outCodes, true)
    && in_array('CROKARY', $outCodes, true)
    && in_array('BBM', $outCodes, true)
    && in_array('PGSP', $outCodes, true)
    && in_array('PGSL', $outCodes, true),
    'Master Kategori Kas pengeluaran (5 inti: CROKARY, BBM, PGSP, GJKRY, PGSL) tersedia lengkap'
);

$tmpIds      = [];
$tmpCatIds   = [];
$tmpStaffId  = 0;
$testCatCode = 'TSTEX' . substr((string) getmypid(), -6);
$jarAdmin   = sys_get_temp_dir() . '/exp_adm_' . getmypid() . '.txt';
$jarStaff   = sys_get_temp_dir() . '/exp_stf_' . getmypid() . '.txt';
@unlink($jarAdmin);
@unlink($jarStaff);

$_SESSION['user_id']        = 1;
$_SESSION['username']       = 'superadmin';
$_SESSION['role_name']      = 'Super Admin';
$_SESSION['is_super_admin'] = true;

try {
    echo "2. Verifikasi Unit Filter & Breakdown Kategori Pengeluaran...\n";
    $today = date('Y-m-d');
    $marker1 = 'EXP_CHK_BBM_' . getmypid();
    $marker2 = 'EXP_CHK_CUS_' . getmypid();

    $uid1 = cash_tx_save(0, [
        'date'        => $today,
        'direction'   => 'out',
        'amount'      => '123456',
        'category'    => 'BBM',
        'description' => $marker1,
    ], 1, 'exp-check');
    $tmpIds[] = $uid1;
    exp_ok($uid1 > 0, "Simpan pengeluaran unit #{$uid1} berhasil");

    $fQ = cash_filter(['from' => $today, 'to' => $today, 'direction' => 'out', 'category' => 'BBM', 'q' => $marker1, 'company_id' => '1']);
    $listQ = cash_tx_list($fQ);
    exp_ok(count($listQ) === 1 && (int) $listQ[0]['id'] === $uid1, 'Filter category + q menemukan pengeluaran uji tepat');

    $bd = cash_expense_category_breakdown($fQ);
    exp_ok(count($bd) === 1 && $bd[0]['category'] === 'BBM' && abs((float) $bd[0]['total_amount'] - 123456.0) < 0.01, 'cash_expense_category_breakdown menghitung total per kategori akurat');

    $db->query("DELETE FROM cash_transactions WHERE id = {$uid1}");
    $tmpIds = [];

    echo "3. Verifikasi HTTP E2E Pengeluaran -> Buku Kas -> Laporan Keuangan -> Dashboard...\n";
    $http = static function (string $jar, string $path, ?array $post = null) use ($base): array {
        $hdr = sys_get_temp_dir() . '/exp_h_' . getmypid() . '.txt';
        $pf  = null;
        $cmd = 'curl.exe -s -D ' . escapeshellarg($hdr) . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
        if ($post !== null) {
            $pf = sys_get_temp_dir() . '/exp_p_' . getmypid() . '.txt';
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
        return ['code' => (int) ($m[1] ?? 0), 'body' => $body, 'headers' => $h];
    };
    $loginAs = static function (string $jar, string $user, string $pw) use ($http): bool {
        $lp = $http($jar, '/pages/auth/login.php');
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lp['body'], $mc);
        $res = $http($jar, '/pages/auth/login.php', ['csrf_token' => $mc[1] ?? '', 'username' => $user, 'password' => $pw]);
        return $res['code'] === 302;
    };

    exp_ok($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin HTTP OK');
    $dash0 = $http($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dash0['body'], $mSw)) {
        $http($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mSw[1], 'company_id' => '1']);
    }

    $expIdx0 = $http($jarAdmin, '/pages/finance/expenses/index.php');
    exp_ok(
        $expIdx0['code'] === 200
        && str_contains($expIdx0['body'], 'Pengeluaran')
        && str_contains($expIdx0['body'], 'Buku Kas'),
        'GET /pages/finance/expenses/index.php HTTP 200 memuat halaman Pengeluaran & sidebar Keuangan'
    );

    $expFrm = $http($jarAdmin, '/pages/finance/expenses/form.php');
    exp_ok(
        $expFrm['code'] === 200
        && str_contains($expFrm['body'], 'formExpense')
        && str_contains($expFrm['body'], '[BBM] BBM')
        && str_contains($expFrm['body'], '[PGSP] Pengeluaran Sparepart')
        && str_contains($expFrm['body'], '[GJKRY] Biaya Gaji Karyawan')
        && str_contains($expFrm['body'], '[PGSL] Pengeluaran Lainnya'),
        'GET /pages/finance/expenses/form.php HTTP 200 memuat dropdown Master Kategori Kas'
    );

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expFrm['body'], $mCsrf1);
    $csrf = $mCsrf1[1] ?? '';

    $postPreset = $http($jarAdmin, '/pages/finance/expenses/form.php', [
        'csrf_token'      => $csrf,
        'id'              => '0',
        'return'          => 'expenses',
        'date'            => $today,
        'amount'          => '275.000',
        'category_preset' => 'BBM',
        'description'     => $marker1,
    ]);
    exp_ok($postPreset['code'] === 302, 'POST form pengeluaran kategori master (BBM Rp 275.000) berhasil 302');

    $catFrmGet = $http($jarAdmin, '/pages/finance/cash_categories/form.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $catFrmGet['body'], $mCsrfCat);
    $postCat = $http($jarAdmin, '/pages/finance/cash_categories/form.php', [
        'csrf_token' => $mCsrfCat[1] ?? $csrf,
        'id'         => '0',
        'code'       => $testCatCode,
        'name'       => 'Cuci Armada Uji',
        'direction'  => 'out',
        'sort_order' => '999',
        'is_active'  => '1',
        'note'       => 'Kategori uji E2E pengeluaran',
    ]);
    $stCat = $db->prepare('SELECT id FROM cash_categories WHERE company_id = 1 AND code = ? LIMIT 1');
    $stCat->bind_param('s', $testCatCode);
    $stCat->execute();
    $testCatId = (int) ($stCat->get_result()->fetch_row()[0] ?? 0);
    $stCat->close();
    if ($testCatId > 0) {
        $tmpCatIds[] = $testCatId;
    }
    exp_ok($postCat['code'] === 302 && $testCatId > 0, "POST Master Kategori Kas ({$testCatCode} - Cuci Armada Uji) berhasil disimpan");

    $expFrm2 = $http($jarAdmin, '/pages/finance/expenses/form.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expFrm2['body'], $mCsrf2);
    exp_ok(str_contains($expFrm2['body'], "[{$testCatCode}] Cuci Armada Uji"), 'Kategori master baru langsung muncul di dropdown Pengeluaran');

    $postMasterNew = $http($jarAdmin, '/pages/finance/expenses/form.php', [
        'csrf_token'      => $mCsrf2[1] ?? $csrf,
        'id'              => '0',
        'return'          => 'expenses',
        'date'            => $today,
        'amount'          => '85.000',
        'category_preset' => 'Cuci Armada Uji',
        'description'     => $marker2,
    ]);
    exp_ok($postMasterNew['code'] === 302, 'POST form pengeluaran kategori master baru (Cuci Armada Uji Rp 85.000) berhasil 302');

    $stFind = $db->prepare('SELECT id, direction, category, amount FROM cash_transactions WHERE company_id = 1 AND description IN (?, ?) ORDER BY id ASC');
    $stFind->bind_param('ss', $marker1, $marker2);
    $stFind->execute();
    $savedRows = $stFind->get_result()->fetch_all(MYSQLI_ASSOC);
    $stFind->close();
    foreach ($savedRows as $sr) {
        $tmpIds[] = (int) $sr['id'];
    }
    exp_ok(
        count($savedRows) === 2
        && $savedRows[0]['direction'] === 'out' && $savedRows[0]['category'] === 'BBM' && abs((float) $savedRows[0]['amount'] - 275000.0) < 0.01
        && $savedRows[1]['direction'] === 'out' && $savedRows[1]['category'] === 'Cuci Armada Uji' && abs((float) $savedRows[1]['amount'] - 85000.0) < 0.01,
        'Kedua pengeluaran tersimpan di cash_transactions dengan direction=out & nominal akurat'
    );

    $expIdx1 = $http($jarAdmin, '/pages/finance/expenses/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    exp_ok(
        $expIdx1['code'] === 200
        && str_contains($expIdx1['body'], $marker1)
        && str_contains($expIdx1['body'], 'Cuci Armada Uji')
        && str_contains($expIdx1['body'], '360.000'),
        'Daftar Pengeluaran menampilkan kedua baris & total Rp 360.000'
    );

    $cashIdx = $http($jarAdmin, '/pages/finance/cash/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    exp_ok(
        $cashIdx['code'] === 200
        && str_contains($cashIdx['body'], $marker1)
        && str_contains($cashIdx['body'], 'Pemasukan')
        && str_contains($cashIdx['body'], 'Pengeluaran'),
        'Buku Kas menampilkan entri pengeluaran otomatis & tombol Pemasukan / Pengeluaran'
    );

    $rptFin = $http($jarAdmin, '/pages/reports/finance.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    exp_ok(
        $rptFin['code'] === 200
        && str_contains($rptFin['body'], 'pages/finance/expenses/index.php'),
        'Laporan Keuangan HTTP 200 & terhubung ke Pengeluaran'
    );

    $dash1 = $http($jarAdmin, '/pages/dashboard/index.php');
    exp_ok(
        $dash1['code'] === 200
        && str_contains($dash1['body'], 'pages/finance/expenses/form.php?return=dashboard')
        && str_contains($dash1['body'], 'pages/finance/expenses/index.php'),
        'Dashboard menampilkan tombol cepat Pengeluaran'
    );

    $csvExp = $http($jarAdmin, '/pages/finance/cash/export.php?direction=out&format=csv&from=' . urlencode($today) . '&to=' . urlencode($today));
    exp_ok(
        $csvExp['code'] === 200
        && str_contains($csvExp['body'], 'Daftar Pengeluaran')
        && str_contains($csvExp['body'], $marker1),
        'Export CSV Pengeluaran (direction=out) berfungsi normal'
    );

    // Guard: jangan izinkan edit pemasukan lewat expenses/form.php
    $inId = (int) ($db->query("SELECT id FROM cash_transactions WHERE direction = 'in' LIMIT 1")->fetch_row()[0] ?? 0);
    if ($inId > 0) {
        $guardEdit = $http($jarAdmin, '/pages/finance/expenses/form.php?id=' . $inId);
        exp_ok($guardEdit['code'] === 302, "Guard: edit pemasukan #{$inId} lewat expenses/form.php ditolak (302)");
    }

    // Guard hapus kategori master + hapus baris pengeluaran via HTTP POST
    $delRowId = (int) ($savedRows[1]['id'] ?? 0);
    if ($testCatId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expIdx1['body'], $mCatDel);
        $blkDel = $http($jarAdmin, '/pages/finance/cash_categories/delete.php', [
            'csrf_token' => $mCatDel[1] ?? $csrf,
            'id'         => (string) $testCatId,
        ]);
        $catStill = (int) $db->query("SELECT COUNT(*) FROM cash_categories WHERE id = {$testCatId}")->fetch_row()[0];
        exp_ok($blkDel['code'] === 302 && $catStill === 1, 'Guard: hapus Master Kategori Kas yang masih dipakai transaksi ditolak');
    }
    if ($delRowId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expIdx1['body'], $mDel);
        $delRes = $http($jarAdmin, '/pages/finance/expenses/delete.php', [
            'csrf_token' => $mDel[1] ?? $csrf,
            'id'         => (string) $delRowId,
        ]);
        $still = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$delRowId}")->fetch_row()[0];
        exp_ok($delRes['code'] === 302 && $still === 0, 'POST expenses/delete.php menghapus baris pengeluaran dengan aman');
    }
    if ($testCatId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expIdx1['body'], $mCatDel2);
        $http($jarAdmin, '/pages/finance/cash_categories/delete.php', [
            'csrf_token' => $mCatDel2[1] ?? $csrf,
            'id'         => (string) $testCatId,
        ]);
        $catAfter = (int) $db->query("SELECT COUNT(*) FROM cash_categories WHERE id = {$testCatId}")->fetch_row()[0];
        exp_ok($catAfter === 0, 'Master Kategori Kas uji berhasil dihapus setelah transaksinya dihapus');
    }

    echo "4. Verifikasi RBAC (Staff tanpa finance.view/manage)...\n";
    $stUname = 'exp_staff_' . getmypid();
    $stHash  = password_hash('Staff@1234', PASSWORD_DEFAULT);
    $stInsU  = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'Staff Uji Exp', 4, 1, 1)");
    $stMail  = $stUname . '@test.local';
    $stInsU->bind_param('sss', $stUname, $stMail, $stHash);
    $stInsU->execute();
    $tmpStaffId = (int) $db->insert_id;
    $stInsU->close();

    exp_ok($loginAs($jarStaff, $stUname, 'Staff@1234'), 'Login Staff uji HTTP OK');
    exp_ok($http($jarStaff, '/pages/finance/expenses/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/finance/expenses/index.php');
    exp_ok($http($jarStaff, '/pages/finance/expenses/form.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/finance/expenses/form.php');
} finally {
    if ($tmpStaffId > 0) {
        $db->query("DELETE FROM users WHERE id = {$tmpStaffId}");
        $db->query("DELETE FROM login_attempts WHERE username LIKE 'exp_staff_%'");
    }
    foreach ($tmpIds as $tid) {
        $db->query("DELETE FROM cash_transactions WHERE id = " . (int) $tid);
    }
    foreach ($tmpCatIds as $cidDel) {
        $db->query("DELETE FROM cash_categories WHERE id = " . (int) $cidDel);
    }
    $db->query("DELETE FROM cash_transactions WHERE description LIKE 'EXP_CHK_%'");
    $db->query("DELETE FROM cash_categories WHERE code LIKE 'TSTEX%'");
    $db->query("DELETE FROM audit_trails WHERE (action LIKE 'kas.expense.%' OR action LIKE 'finance.%') AND (description LIKE '%Cuci Armada Uji%' OR description LIKE '%TSTEX%' OR (description LIKE '%275.000%' AND created_date >= NOW() - INTERVAL 15 MINUTE))");
    @unlink($jarAdmin);
    @unlink($jarStaff);
}

echo "\nRingkasan Expenses Check: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
