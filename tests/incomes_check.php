<?php
/**
 * Self-check & HTTP RBAC check: Modul CRUD Pemasukan, Integrasi ke Buku Kas,
 * Laporan Keuangan, Dashboard, Export, Guard Auto-Booking, dan Proteksi Akses.
 *
 * Usage: C:\xampp\php\php.exe tests/incomes_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/finance/_cash_query.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();

$pass = 0;
$fail = 0;
function inc_ok(bool $cond, string $label): void
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

echo "1. Verifikasi Unit Helper cash_income_categories & Master Kategori Kas...\n";
$cats = cash_income_categories();
$masterIn = cash_master_categories('in', 1, true);
$inCodes  = array_column($masterIn, 'code');
inc_ok(
    isset($cats['Pendapatan Travel'], $cats['Pendapatan Rental'], $cats['Pengembalian Gaji Karyawan'], $cats['Pemasukan Lainnya'])
    && in_array('PDTRV', $inCodes, true)
    && in_array('PDRNT', $inCodes, true)
    && in_array('CROPGK', $inCodes, true)
    && in_array('PMSL', $inCodes, true),
    'Master Kategori Kas pemasukan (4 inti: PDTRV, PDRNT, CROPGK, PMSL) tersedia lengkap'
);

$tmpIds     = [];
$tmpCatIds  = [];
$tmpStaffId = 0;
$testCatCode = 'TSTIN' . substr((string) getmypid(), -6);
$jarAdmin   = sys_get_temp_dir() . '/inc_adm_' . getmypid() . '.txt';
$jarStaff   = sys_get_temp_dir() . '/inc_stf_' . getmypid() . '.txt';
@unlink($jarAdmin);
@unlink($jarStaff);

$_SESSION['user_id']        = 1;
$_SESSION['username']       = 'superadmin';
$_SESSION['role_name']      = 'Super Admin';
$_SESSION['is_super_admin'] = true;

try {
    $today   = date('Y-m-d');
    $marker1 = 'INC_CHK_MOD_' . getmypid();
    $marker2 = 'INC_CHK_CUS_' . getmypid();

    $uid1 = cash_tx_save(0, [
        'date'        => $today,
        'direction'   => 'in',
        'amount'      => '345000',
        'category'    => 'Pengembalian Gaji Karyawan',
        'description' => $marker1,
    ], 1, 'inc-check');
    $tmpIds[] = $uid1;

    $fQ = cash_filter(['from' => $today, 'to' => $today, 'direction' => 'in', 'category' => 'Pengembalian Gaji Karyawan', 'q' => $marker1, 'company_id' => '1']);
    $bd = cash_income_category_breakdown($fQ);
    inc_ok(count($bd) === 1 && $bd[0]['category'] === 'Pengembalian Gaji Karyawan' && abs((float) $bd[0]['total_amount'] - 345000.0) < 0.01, 'cash_income_category_breakdown menghitung total per kategori akurat');

    $db->query("DELETE FROM cash_transactions WHERE id = {$uid1}");
    $tmpIds = [];

    echo "2. Verifikasi HTTP CRUD Pemasukan -> Buku Kas -> Laporan Keuangan -> Dashboard...\n";
    $http = static function (string $jar, string $path, ?array $post = null) use ($base): array {
        $hdr = sys_get_temp_dir() . '/inc_h_' . getmypid() . '.txt';
        $pf  = null;
        $cmd = 'curl.exe -s -D ' . escapeshellarg($hdr) . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
        if ($post !== null) {
            $pf = sys_get_temp_dir() . '/inc_p_' . getmypid() . '.txt';
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

    inc_ok($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin HTTP OK');
    $dash0 = $http($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dash0['body'], $mSw)) {
        $http($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mSw[1], 'company_id' => '1']);
    }

    $incIdx0 = $http($jarAdmin, '/pages/finance/incomes/index.php');
    inc_ok(
        $incIdx0['code'] === 200
        && str_contains($incIdx0['body'], 'Pemasukan')
        && str_contains($incIdx0['body'], 'Buku Kas'),
        'GET /pages/finance/incomes/index.php HTTP 200 memuat halaman Pemasukan & sidebar Keuangan'
    );

    $incFrm = $http($jarAdmin, '/pages/finance/incomes/form.php');
    inc_ok(
        $incFrm['code'] === 200
        && str_contains($incFrm['body'], 'formIncome')
        && str_contains($incFrm['body'], '[PDTRV] Pendapatan Travel')
        && str_contains($incFrm['body'], '[PDRNT] Pendapatan Rental')
        && str_contains($incFrm['body'], '[CROPGK] Pengembalian Gaji Karyawan')
        && str_contains($incFrm['body'], '[PMSL] Pemasukan Lainnya'),
        'GET /pages/finance/incomes/form.php HTTP 200 memuat dropdown Master Kategori Kas'
    );

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incFrm['body'], $mCsrf1);
    $csrf = $mCsrf1[1] ?? '';

    $postPreset = $http($jarAdmin, '/pages/finance/incomes/form.php', [
        'csrf_token'      => $csrf,
        'id'              => '0',
        'return'          => 'incomes',
        'date'            => $today,
        'amount'          => '500.000',
        'category_preset' => 'Pengembalian Gaji Karyawan',
        'description'     => $marker1,
    ]);
    inc_ok($postPreset['code'] === 302, 'POST form pemasukan master (Pengembalian Gaji Karyawan Rp 500.000) berhasil 302');

    // Buat kategori baru lewat Master Kategori Kas lalu pakai di form Pemasukan
    $catFrmGet = $http($jarAdmin, '/pages/finance/cash_categories/form.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $catFrmGet['body'], $mCsrfCat);
    $postCat = $http($jarAdmin, '/pages/finance/cash_categories/form.php', [
        'csrf_token' => $mCsrfCat[1] ?? $csrf,
        'id'         => '0',
        'code'       => $testCatCode,
        'name'       => 'Komisi Mitra Uji',
        'direction'  => 'in',
        'sort_order' => '999',
        'is_active'  => '1',
        'note'       => 'Kategori uji E2E pemasukan',
    ]);
    $stCat = $db->prepare('SELECT id FROM cash_categories WHERE company_id = 1 AND code = ? LIMIT 1');
    $stCat->bind_param('s', $testCatCode);
    $stCat->execute();
    $testCatId = (int) ($stCat->get_result()->fetch_row()[0] ?? 0);
    $stCat->close();
    if ($testCatId > 0) {
        $tmpCatIds[] = $testCatId;
    }
    inc_ok($postCat['code'] === 302 && $testCatId > 0, "POST Master Kategori Kas ({$testCatCode} - Komisi Mitra Uji) berhasil disimpan");

    $incFrm2 = $http($jarAdmin, '/pages/finance/incomes/form.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incFrm2['body'], $mCsrf2);
    inc_ok(str_contains($incFrm2['body'], "[{$testCatCode}] Komisi Mitra Uji"), 'Kategori master baru langsung muncul di dropdown Pemasukan');

    $postMasterNew = $http($jarAdmin, '/pages/finance/incomes/form.php', [
        'csrf_token'      => $mCsrf2[1] ?? $csrf,
        'id'              => '0',
        'return'          => 'incomes',
        'date'            => $today,
        'amount'          => '125.000',
        'category_preset' => 'Komisi Mitra Uji',
        'description'     => $marker2,
    ]);
    inc_ok($postMasterNew['code'] === 302, 'POST form pemasukan kategori master baru (Komisi Mitra Uji Rp 125.000) berhasil 302');

    $stFind = $db->prepare('SELECT id, direction, category, amount FROM cash_transactions WHERE company_id = 1 AND description IN (?, ?) ORDER BY id ASC');
    $stFind->bind_param('ss', $marker1, $marker2);
    $stFind->execute();
    $savedRows = $stFind->get_result()->fetch_all(MYSQLI_ASSOC);
    $stFind->close();
    foreach ($savedRows as $sr) {
        $tmpIds[] = (int) $sr['id'];
    }
    inc_ok(
        count($savedRows) === 2
        && $savedRows[0]['direction'] === 'in' && $savedRows[0]['category'] === 'Pengembalian Gaji Karyawan' && abs((float) $savedRows[0]['amount'] - 500000.0) < 0.01
        && $savedRows[1]['direction'] === 'in' && $savedRows[1]['category'] === 'Komisi Mitra Uji' && abs((float) $savedRows[1]['amount'] - 125000.0) < 0.01,
        'Kedua pemasukan tersimpan di cash_transactions dengan direction=in & nominal akurat'
    );

    // Uji Edit (Update) via HTTP POST pada baris ke-1
    $editTargetId = (int) ($savedRows[0]['id'] ?? 0);
    if ($editTargetId > 0) {
        $editGet = $http($jarAdmin, '/pages/finance/incomes/form.php?id=' . $editTargetId);
        inc_ok($editGet['code'] === 200 && str_contains($editGet['body'], '500.000'), 'GET incomes/form.php?id=' . $editTargetId . ' HTTP 200 memuat nilai awal');
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $editGet['body'], $mCsrfUpd);
        $updRes = $http($jarAdmin, '/pages/finance/incomes/form.php?id=' . $editTargetId, [
            'csrf_token'      => $mCsrfUpd[1] ?? $csrf,
            'id'              => (string) $editTargetId,
            'return'          => 'incomes',
            'date'            => $today,
            'amount'          => '550.000',
            'category_preset' => 'Pemasukan Lainnya',
            'description'     => $marker1,
        ]);
        $afterUpd = $db->query("SELECT category, amount FROM cash_transactions WHERE id = {$editTargetId}")->fetch_assoc();
        inc_ok(
            $updRes['code'] === 302 && $afterUpd && $afterUpd['category'] === 'Pemasukan Lainnya' && abs((float) $afterUpd['amount'] - 550000.0) < 0.01,
            'POST update pemasukan mengubah kategori menjadi Pemasukan Lainnya & nominal 550.000'
        );
    }

    $incIdx1 = $http($jarAdmin, '/pages/finance/incomes/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    inc_ok(
        $incIdx1['code'] === 200
        && str_contains($incIdx1['body'], $marker1)
        && str_contains($incIdx1['body'], 'Komisi Mitra Uji')
        && str_contains($incIdx1['body'], '550.000'),
        'Daftar Pemasukan menampilkan hasil update & baris kustom'
    );

    $cashIdx = $http($jarAdmin, '/pages/finance/cash/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    inc_ok(
        $cashIdx['code'] === 200
        && str_contains($cashIdx['body'], $marker1)
        && str_contains($cashIdx['body'], 'pages/finance/incomes/form.php?return=cash')
        && str_contains($cashIdx['body'], 'pages/finance/expenses/form.php?return=cash'),
        'Buku Kas menampilkan entri pemasukan & mengarahkan tombol Pemasukan/Pengeluaran ke form masing-masing'
    );

    $rptFin = $http($jarAdmin, '/pages/reports/finance.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    inc_ok(
        $rptFin['code'] === 200
        && str_contains($rptFin['body'], 'pages/finance/incomes/index.php'),
        'Laporan Keuangan HTTP 200 & terhubung ke Pemasukan'
    );

    $dash1 = $http($jarAdmin, '/pages/dashboard/index.php');
    inc_ok(
        $dash1['code'] === 200
        && str_contains($dash1['body'], 'pages/finance/incomes/form.php?return=dashboard')
        && str_contains($dash1['body'], 'pages/finance/incomes/index.php'),
        'Dashboard menampilkan tombol cepat & link Pemasukan'
    );

    $csvInc = $http($jarAdmin, '/pages/finance/cash/export.php?direction=in&format=csv&from=' . urlencode($today) . '&to=' . urlencode($today));
    inc_ok(
        $csvInc['code'] === 200
        && str_contains($csvInc['body'], 'Daftar Pemasukan')
        && str_contains($csvInc['body'], $marker1),
        'Export CSV Pemasukan (direction=in) berjudul Daftar Pemasukan & memuat baris uji'
    );

    // Guard 1: jangan izinkan edit/hapus baris Auto Booking
    $autoId = (int) ($db->query("SELECT id FROM cash_transactions WHERE direction = 'in' AND source_type IS NOT NULL LIMIT 1")->fetch_row()[0] ?? 0);
    if ($autoId > 0) {
        $guardAutoEdit = $http($jarAdmin, '/pages/finance/incomes/form.php?id=' . $autoId);
        inc_ok($guardAutoEdit['code'] === 302, "Guard: edit Auto Booking #{$autoId} lewat incomes/form.php ditolak (302)");

        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incIdx1['body'], $mGuardDel);
        $guardAutoDel = $http($jarAdmin, '/pages/finance/incomes/delete.php', [
            'csrf_token' => $mGuardDel[1] ?? $csrf,
            'id'         => (string) $autoId,
        ]);
        $autoStill = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$autoId}")->fetch_row()[0];
        inc_ok($guardAutoDel['code'] === 302 && $autoStill === 1, "Guard: hapus Auto Booking #{$autoId} lewat incomes/delete.php ditolak & data tetap utuh");
    }

    // Pastikan master kategori yang masih dipakai transaksi tidak bisa dihapus, lalu hapus transaksi & kategori uji
    $delRowId = (int) ($savedRows[1]['id'] ?? 0);
    if ($testCatId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incIdx1['body'], $mCatDel);
        $blkDel = $http($jarAdmin, '/pages/finance/cash_categories/delete.php', [
            'csrf_token' => $mCatDel[1] ?? $csrf,
            'id'         => (string) $testCatId,
        ]);
        $catStill = (int) $db->query("SELECT COUNT(*) FROM cash_categories WHERE id = {$testCatId}")->fetch_row()[0];
        inc_ok($blkDel['code'] === 302 && $catStill === 1, 'Guard: hapus Master Kategori Kas yang masih dipakai transaksi ditolak');
    }
    if ($delRowId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incIdx1['body'], $mDel);
        $delRes = $http($jarAdmin, '/pages/finance/incomes/delete.php', [
            'csrf_token' => $mDel[1] ?? $csrf,
            'id'         => (string) $delRowId,
        ]);
        $still = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$delRowId}")->fetch_row()[0];
        inc_ok($delRes['code'] === 302 && $still === 0, 'POST incomes/delete.php menghapus pemasukan manual dengan aman');
    }
    if ($testCatId > 0) {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incIdx1['body'], $mCatDel2);
        $http($jarAdmin, '/pages/finance/cash_categories/delete.php', [
            'csrf_token' => $mCatDel2[1] ?? $csrf,
            'id'         => (string) $testCatId,
        ]);
        $catAfter = (int) $db->query("SELECT COUNT(*) FROM cash_categories WHERE id = {$testCatId}")->fetch_row()[0];
        inc_ok($catAfter === 0, 'Master Kategori Kas uji berhasil dihapus setelah transaksinya dihapus');
    }

    echo "3. Verifikasi RBAC (Staff tanpa finance.view/manage)...\n";
    $stUname = 'inc_staff_' . getmypid();
    $stHash  = password_hash('Staff@1234', PASSWORD_DEFAULT);
    $stInsU  = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'Staff Uji Inc', 4, 1, 1)");
    $stMail  = $stUname . '@test.local';
    $stInsU->bind_param('sss', $stUname, $stMail, $stHash);
    $stInsU->execute();
    $tmpStaffId = (int) $db->insert_id;
    $stInsU->close();

    inc_ok($loginAs($jarStaff, $stUname, 'Staff@1234'), 'Login Staff uji HTTP OK');
    inc_ok($http($jarStaff, '/pages/finance/incomes/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/finance/incomes/index.php');
    inc_ok($http($jarStaff, '/pages/finance/incomes/form.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/finance/incomes/form.php');
    inc_ok($http($jarStaff, '/pages/finance/cash_categories/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di pages/finance/cash_categories/index.php');
} finally {
    if ($tmpStaffId > 0) {
        $db->query("DELETE FROM users WHERE id = {$tmpStaffId}");
        $db->query("DELETE FROM login_attempts WHERE username LIKE 'inc_staff_%'");
    }
    foreach ($tmpIds as $tid) {
        $db->query("DELETE FROM cash_transactions WHERE id = " . (int) $tid);
    }
    foreach ($tmpCatIds as $cidDel) {
        $db->query("DELETE FROM cash_categories WHERE id = " . (int) $cidDel);
    }
    $db->query("DELETE FROM cash_transactions WHERE description LIKE 'INC_CHK_%'");
    $db->query("DELETE FROM cash_categories WHERE code LIKE 'TSTIN%'");
    $db->query("DELETE FROM audit_trails WHERE (action LIKE 'kas.income.%' OR action LIKE 'finance.%') AND (description LIKE '%Komisi Mitra Uji%' OR description LIKE '%TSTIN%' OR (description LIKE '%Pengembalian Gaji Karyawan%' AND created_date >= NOW() - INTERVAL 15 MINUTE) OR (description LIKE '%Pemasukan Lainnya%' AND created_date >= NOW() - INTERVAL 15 MINUTE))");
    @unlink($jarAdmin);
    @unlink($jarStaff);
}

echo "\nRingkasan Incomes Check: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
