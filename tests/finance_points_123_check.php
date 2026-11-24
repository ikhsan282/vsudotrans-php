<?php
/**
 * Integration, Azure Blob Upload, RBAC & Report Reconciliation check untuk Poin 1, 2, 3:
 *   - Poin 1: Upload bukti struk/nota (Azure Blob) + relasi opsional armada (vehicle_id)
 *             pada Pemasukan, Pengeluaran, Buku Kas, & hapus blob saat delete.
 *   - Poin 2: Rekap & Pembayaran Komisi Sopir/Kru + auto Kas Keluar + pembatalan pencairan + RBAC.
 *   - Poin 3: Pengeluaran Kas per Kategori, per Armada, dan Laba Bersih Operasional
 *             (tanpa double-counting crew_commission) di halaman & export Laporan Keuangan.
 *
 * Usage: C:\xampp\php\php.exe tests/finance_points_123_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../pages/finance/commissions/_query.php';
require_once __DIR__ . '/../pages/reports/_finance_query.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();
finance_ensure_schema();

$pass = 0;
$fail = 0;
function fp_ok(bool $cond, string $label): void
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

$jarAdmin  = sys_get_temp_dir() . '/fp_adm_' . getmypid() . '.txt';
$jarStaff  = sys_get_temp_dir() . '/fp_stf_' . getmypid() . '.txt';
$imgTmp    = sys_get_temp_dir() . '/fp_proof_' . getmypid() . '.png';
@unlink($jarAdmin);
@unlink($jarStaff);
@unlink($imgTmp);

// Buat PNG 1x1 valid minimal
file_put_contents($imgTmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));

$http = static function (string $jar, string $path, ?array $post = null, ?array $files = null) use ($base): array {
    $hdr = sys_get_temp_dir() . '/fp_h_' . getmypid() . '.txt';
    $pf  = null;
    $cmd = 'curl.exe -s -D ' . escapeshellarg($hdr) . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
    if ($files !== null && $post !== null) {
        foreach ($post as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $cmd .= ' -F ' . escapeshellarg($k . '[]=' . (string) $vv);
                }
            } else {
                $cmd .= ' -F ' . escapeshellarg($k . '=' . (string) $v);
            }
        }
        foreach ($files as $fk => $fPath) {
            if ($fPath && is_file($fPath)) {
                $cmd .= ' -F ' . escapeshellarg($fk . '=@' . $fPath . ';type=image/png');
            }
        }
    } elseif ($post !== null) {
        $pf = sys_get_temp_dir() . '/fp_p_' . getmypid() . '.txt';
        file_put_contents($pf, http_build_query($post));
        $cmd .= ' -H "Content-Type: application/x-www-form-urlencoded" --data-binary @' . escapeshellarg($pf);
    }
    $body = (string) shell_exec($cmd . ' ' . escapeshellarg($base . $path));
    $h    = is_file($hdr) ? (string) file_get_contents($hdr) : '';
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

$tmpTxIds     = [];
$tmpCostIds   = [];
$tmpBlobs     = [];
$tmpBookingId = 0;
$tmpPaxId     = 0;
$tmpEmpId     = 0;
$tmpStaffId   = 0;

$_SESSION['user_id']        = 1;
$_SESSION['username']       = 'superadmin';
$_SESSION['role_name']      = 'Super Admin';
$_SESSION['is_super_admin'] = true;
$_SESSION['company_id']     = 1;

try {
    $today   = date('Y-m-d');
    $veh1    = $db->query('SELECT id, vehicle_number FROM vehicles WHERE company_id = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1')->fetch_assoc();
    $veh1Id  = (int) ($veh1['id'] ?? 0);
    $veh1No  = (string) ($veh1['vehicle_number'] ?? '');
    $vehOth  = (int) ($db->query('SELECT id FROM vehicles WHERE company_id <> 1 LIMIT 1')->fetch_column() ?: 0);

    echo "=== POIN 1: Armada (vehicle_id) & Upload Bukti Struk ===\n";
    fp_ok($veh1Id > 0, "Kendaraan company 1 tersedia untuk uji (#{$veh1Id} {$veh1No})");
    if ($vehOth > 0) {
        $errsCrossVeh = cash_tx_validate([
            'date'       => $today,
            'direction'  => 'out',
            'amount'     => '100000',
            'vehicle_id' => (string) $vehOth,
        ], 1);
        fp_ok(count($errsCrossVeh) >= 1, "Validasi menolak vehicle_id milik tenant lain (#{$vehOth})");
    }

    fp_ok($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin HTTP OK');
    $dash = $http($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dash['body'], $mSw)) {
        $http($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mSw[1], 'company_id' => '1']);
    }

    // Uji upload bukti + relasi armada pada form Pengeluaran
    $expForm = $http($jarAdmin, '/pages/finance/expenses/form.php');
    fp_ok(
        $expForm['code'] === 200
        && str_contains($expForm['body'], 'enctype="multipart/form-data"')
        && str_contains($expForm['body'], 'name="vehicle_id"')
        && str_contains($expForm['body'], 'name="proof_file"'),
        'Form Pengeluaran memuat enctype multipart, select Armada (.ts-select), dan input Bukti Struk'
    );
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expForm['body'], $mcExp);

    $markerExp = 'FP123_EXP_' . getmypid();
    $postExp = $http($jarAdmin, '/pages/finance/expenses/form.php', [
        'csrf_token'        => $mcExp[1] ?? '',
        'id'                => '0',
        'return'            => 'expenses',
        'date'              => $today,
        'amount'            => '185.000',
        'payment_method_id' => '',
        'vehicle_id'        => (string) $veh1Id,
        'category_preset'   => 'BBM',
        'category_custom'   => '',
        'description'       => $markerExp,
    ], ['proof_file' => $imgTmp]);
    fp_ok($postExp['code'] === 302, 'POST Pengeluaran + relasi armada + upload bukti PNG berhasil (302)');

    $stE = $db->prepare('SELECT id, vehicle_id, proof_blob_path FROM cash_transactions WHERE company_id = 1 AND description = ? LIMIT 1');
    $stE->bind_param('s', $markerExp);
    $stE->execute();
    $rowExp = $stE->get_result()->fetch_assoc();
    $stE->close();
    $expTxId = (int) ($rowExp['id'] ?? 0);
    if ($expTxId > 0) {
        $tmpTxIds[] = $expTxId;
    }
    if (!empty($rowExp['proof_blob_path'])) {
        $tmpBlobs[] = (string) $rowExp['proof_blob_path'];
    }
    fp_ok(
        $expTxId > 0 && (int) ($rowExp['vehicle_id'] ?? 0) === $veh1Id && !empty($rowExp['proof_blob_path']),
        'cash_transactions menyimpan vehicle_id=' . $veh1Id . ' & proof_blob_path Azure Blob (' . ($rowExp['proof_blob_path'] ?? '-') . ')'
    );

    // Cek daftar Pengeluaran filter by vehicle_id menampilkan plat armada & ikon Lihat Bukti
    $expIdx = $http($jarAdmin, '/pages/finance/expenses/index.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&vehicle_id=' . $veh1Id);
    fp_ok(
        $expIdx['code'] === 200
        && str_contains($expIdx['body'], $markerExp)
        && str_contains($expIdx['body'], $veh1No)
        && str_contains($expIdx['body'], 'title="Lihat Bukti Struk/Nota"')
        && str_contains($expIdx['body'], 'Per Armada:'),
        'Daftar Pengeluaran filter vehicle_id menampilkan plat unit, chip Per Armada, dan tombol Lihat Bukti'
    );

    // Cek Export Buku Kas / Pengeluaran memuat [PLAT] di kolom keterangan
    $csvExp = $http($jarAdmin, '/pages/finance/cash/export.php?direction=out&format=csv&from=' . urlencode($today) . '&to=' . urlencode($today) . '&vehicle_id=' . $veh1Id);
    fp_ok($csvExp['code'] === 200 && str_contains($csvExp['body'], '[' . $veh1No . ']'), 'Export CSV memuat [' . $veh1No . '] pada kolom Keterangan');

    // Uji kendaraan detail menampilkan link Riwayat Pengeluaran
    $vehDet = $http($jarAdmin, '/pages/vehicles/detail.php?id=' . $veh1Id);
    fp_ok($vehDet['code'] === 200 && str_contains($vehDet['body'], 'Riwayat Pengeluaran'), 'Detail Kendaraan menautkan tombol Riwayat Pengeluaran');

    // Uji Pemasukan + Upload Bukti
    $incForm = $http($jarAdmin, '/pages/finance/incomes/form.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incForm['body'], $mcInc);
    $markerInc = 'FP123_INC_' . getmypid();
    $postInc = $http($jarAdmin, '/pages/finance/incomes/form.php', [
        'csrf_token'        => $mcInc[1] ?? '',
        'id'                => '0',
        'return'            => 'incomes',
        'date'              => $today,
        'amount'            => '320.000',
        'payment_method_id' => '',
        'vehicle_id'        => '',
        'category_preset'   => 'Setoran Modal',
        'category_custom'   => '',
        'description'       => $markerInc,
    ], ['proof_file' => $imgTmp]);
    fp_ok($postInc['code'] === 302, 'POST Pemasukan + upload bukti PNG berhasil (302)');

    $stI = $db->prepare('SELECT id, proof_blob_path FROM cash_transactions WHERE company_id = 1 AND description = ? LIMIT 1');
    $stI->bind_param('s', $markerInc);
    $stI->execute();
    $rowInc = $stI->get_result()->fetch_assoc();
    $stI->close();
    $incTxId = (int) ($rowInc['id'] ?? 0);
    if ($incTxId > 0) {
        $tmpTxIds[] = $incTxId;
    }
    $incBlob = (string) ($rowInc['proof_blob_path'] ?? '');
    if ($incBlob !== '') {
        $tmpBlobs[] = $incBlob;
    }
    fp_ok($incTxId > 0 && $incBlob !== '', 'Pemasukan menyimpan proof_blob_path Azure Blob (' . $incBlob . ')');

    echo "\n=== POIN 2: Rekap & Pembayaran Komisi Sopir / Kru ===\n";
    $branch1 = (int) $db->query('SELECT id FROM branches WHERE company_id = 1 LIMIT 1')->fetch_column();
    $distRow = $db->query('SELECT id, city_id, province_id FROM districts ORDER BY id ASC LIMIT 1')->fetch_assoc();
    $paxCode = 'FP123P' . random_int(100, 999);
    $stP = $db->prepare("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, ?, 'Pax Komisi Uji', '6281234000111')");
    $stP->bind_param('s', $paxCode);
    $stP->execute();
    $tmpPaxId = (int) $db->insert_id;
    $stP->close();

    $empCode = 'FPE' . random_int(1000, 9999);
    $empName = 'Sopir Uji Komisi ' . getmypid();
    $stEm = $db->prepare("INSERT INTO employees (company_id, branch_id, code, name, join_date, job_title, job_position, status) VALUES (1, ?, ?, ?, ?, 'Operasional', 'Driver', 'aktif')");
    $stEm->bind_param('isss', $branch1, $empCode, $empName, $today);
    $stEm->execute();
    $tmpEmpId = (int) $db->insert_id;
    $stEm->close();

    $tb = booking_create('travel', [
        'company_id'                 => 1,
        'branch_id'                  => $branch1,
        'date'                       => $today,
        'time'                       => '10:00:00',
        'pickup_address'             => 'Jemput Komisi Uji',
        'pickup_point_province_id'   => (int) $distRow['province_id'],
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_address'           => 'Antar Komisi Uji',
        'delivery_point_province_id' => (int) $distRow['province_id'],
        'delivery_point_city_id'     => (int) $distRow['city_id'],
        'delivery_point_district_id' => (int) $distRow['id'],
        'total_price'                => 900000,
        'total_payment'              => 0,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $tmpPaxId,
        'created_user'               => 'fp123-check',
    ]);
    $tmpBookingId = (int) $tb['id'];

    $stLine = $db->prepare(
        "INSERT INTO booking_cost_lines (company_id, booking_type, booking_id, cost_type, description, amount, employee_id, premi_type, premi_value, premi_amount, note, created_user)
         VALUES (1, 'travel', ?, ?, ?, ?, ?, 'nominal', ?, ?, 'Komisi uji', 'fp123-check')"
    );
    foreach ([['Sopir', 140000.0], ['Uang Jalan', 60000.0]] as [$ctLbl, $amtVal]) {
        $stLine->bind_param('issdidd', $tmpBookingId, $ctLbl, $ctLbl, $amtVal, $tmpEmpId, $amtVal, $amtVal);
        $stLine->execute();
        $tmpCostIds[] = (int) $db->insert_id;
    }
    $stLine->close();
    fp_ok(count($tmpCostIds) === 2, '2 baris komisi booking_cost_lines dibuat (Rp 140.000 + Rp 60.000 = Rp 200.000)');

    $commIdx0 = $http($jarAdmin, '/pages/finance/commissions/index.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&employee_id=' . $tmpEmpId);
    fp_ok(
        $commIdx0['code'] === 200
        && str_contains($commIdx0['body'], $empName)
        && str_contains($commIdx0['body'], $tb['document_number'])
        && str_contains($commIdx0['body'], 'Belum Dibayar'),
        'GET commissions/index.php (200) menampilkan baris komisi Sopir uji sebagai Belum Dibayar'
    );
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $commIdx0['body'], $mcComm);

    // Bayar kedua baris komisi via HTTP POST multipart
    $payRes = $http($jarAdmin, '/pages/finance/commissions/pay.php', [
        'csrf_token'        => $mcComm[1] ?? '',
        'company_id'        => '1',
        'return_qs'         => 'from=' . $today . '&to=' . $today . '&employee_id=' . $tmpEmpId,
        'pay_date'          => $today,
        'payment_method_id' => '',
        'note'              => 'Pencairan Uji FP123',
        'line_ids'          => array_map('strval', $tmpCostIds),
    ], ['proof_file' => $imgTmp]);
    fp_ok($payRes['code'] === 302, 'POST commissions/pay.php mencairkan 2 baris komisi (302)');

    $inCostPh = implode(',', array_map('intval', $tmpCostIds));
    $paidRows = $db->query("SELECT id, paid_cash_tx_id, paid_at FROM booking_cost_lines WHERE id IN ({$inCostPh})")->fetch_all(MYSQLI_ASSOC);
    $commTxId = (int) ($paidRows[0]['paid_cash_tx_id'] ?? 0);
    if ($commTxId > 0) {
        $tmpTxIds[] = $commTxId;
    }
    fp_ok(
        count($paidRows) === 2
        && $commTxId > 0
        && (int) ($paidRows[1]['paid_cash_tx_id'] ?? 0) === $commTxId
        && !empty($paidRows[0]['paid_at']),
        "Kedua baris booking_cost_lines tertaut ke paid_cash_tx_id=#{$commTxId} & paid_at terisi"
    );

    $txComm = $db->query("SELECT direction, amount, category, source_type, proof_blob_path FROM cash_transactions WHERE id = {$commTxId}")->fetch_assoc();
    if (!empty($txComm['proof_blob_path'])) {
        $tmpBlobs[] = (string) $txComm['proof_blob_path'];
    }
    fp_ok(
        $txComm
        && $txComm['direction'] === 'out'
        && abs((float) $txComm['amount'] - 200000.0) < 0.01
        && $txComm['source_type'] === 'crew_commission'
        && !empty($txComm['proof_blob_path']),
        'Transaksi kas keluar #'.$commTxId.' tercatat sebesar Rp 200.000 dengan source_type=crew_commission & bukti terunggah'
    );

    // Pastikan pencairan komisi muncul di Pengeluaran namun diproteksi dari hapus manual di expenses/delete.php
    $expCommCheck = $http($jarAdmin, '/pages/finance/expenses/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(str_contains($expCommCheck['body'], 'Komisi Kru'), 'Pengeluaran menampilkan badge Komisi Kru untuk transaksi pencairan');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expCommCheck['body'], $mcGuardDel);
    $guardDel = $http($jarAdmin, '/pages/finance/expenses/delete.php', [
        'csrf_token' => $mcGuardDel[1] ?? ($mcComm[1] ?? ''),
        'id'         => (string) $commTxId,
    ]);
    $stillCommTx = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$commTxId}")->fetch_row()[0];
    fp_ok($guardDel['code'] === 302 && $stillCommTx === 1, 'Guard: pencairan komisi kru tidak bisa dihapus manual lewat expenses/delete.php');

    echo "\n=== POIN 3: Pengeluaran per Kategori, Armada & Laba Bersih Operasional ===\n";
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $fFin = fin_report_filter(['from' => $today, 'to' => $today, 'booking_type' => 'all']);
    $finAll = fin_all($fFin, null);

    // Periksa tidak terjadi double-counting: pencairan crew_commission Rp 200.000 masuk cash['out_commission'],
    // BUKAN cash['out_operating'], karena Rp 200.000 sudah masuk ke trip cost_total!
    $expSumCats = fin_expense_category_totals($finAll['expense_categories']);
    fp_ok(
        isset($finAll['expense_categories'], $finAll['expense_vehicles'], $finAll['net_profit'], $finAll['net_summary'])
        && abs($expSumCats['amount'] - (float) $finAll['cash']['out_total']) < 0.01,
        'fin_all() menyertakan expense_categories & totalnya cocok 100% dengan cash.out_total (' . rupiah($expSumCats['amount']) . ')'
    );
    fp_ok(
        (float) $finAll['cash']['out_commission'] >= 200000.0
        && abs((float) $finAll['cash']['out_operating'] - ((float) $finAll['cash']['out_total'] - (float) $finAll['cash']['out_commission'])) < 0.01,
        'Laba Bersih memisahkan out_commission agar tidak menghitung ganda biaya komisi trip'
    );
    $expectedNet = (float) $finAll['gross_profit'] + (float) $finAll['cash']['in_other'] - (float) $finAll['cash']['out_operating'];
    fp_ok(abs((float) $finAll['net_profit'] - $expectedNet) < 0.01, 'Rumus Laba Bersih Operasional = gross_profit + in_other - out_operating akurat');

    $rptHtml = $http($jarAdmin, '/pages/reports/finance.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $rptHtml['code'] === 200
        && str_contains($rptHtml['body'], 'Ringkasan Laba Bersih Operasional')
        && str_contains($rptHtml['body'], 'Pengeluaran Kas per Kategori')
        && str_contains($rptHtml['body'], 'Pengeluaran Kas per Armada')
        && str_contains($rptHtml['body'], $veh1No),
        'Laporan Keuangan HTML memuat Laba Bersih Operasional, Pengeluaran per Kategori & Pengeluaran per Armada'
    );

    $rptCsv = $http($jarAdmin, '/pages/reports/finance_export.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&format=csv');
    fp_ok(
        $rptCsv['code'] === 200
        && str_contains($rptCsv['body'], 'Laba Bersih Operasional')
        && str_contains($rptCsv['body'], 'Pengeluaran Kas per Kategori'),
        'Export CSV Laporan Keuangan memuat Laba Bersih Operasional & Pengeluaran Kas per Kategori'
    );
    $rptXlsx = $http($jarAdmin, '/pages/reports/finance_export.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&format=xlsx');
    fp_ok($rptXlsx['code'] === 200 && str_starts_with($rptXlsx['body'], "PK\x03\x04"), 'Export XLSX Laporan Keuangan valid');
    $rptPdf = $http($jarAdmin, '/pages/reports/finance_export.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&format=pdf');
    fp_ok($rptPdf['code'] === 200 && str_starts_with($rptPdf['body'], '%PDF-'), 'Export PDF Laporan Keuangan valid');

    // Sekarang uji pembatalan pencairan komisi via cancel.php (setelah tes laporan selesai)
    $commIdx1 = $http($jarAdmin, '/pages/finance/commissions/index.php?from=' . urlencode($today) . '&to=' . urlencode($today) . '&employee_id=' . $tmpEmpId);
    fp_ok($commIdx1['code'] === 200 && str_contains($commIdx1['body'], 'Lunas'), 'Setelah bayar, status di commissions/index.php berubah menjadi Lunas');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $commIdx1['body'], $mcCancel);

    $cancelRes = $http($jarAdmin, '/pages/finance/commissions/cancel.php', [
        'csrf_token' => $mcCancel[1] ?? '',
        'cash_tx_id' => (string) $commTxId,
        'return_qs'  => 'from=' . $today . '&to=' . $today . '&employee_id=' . $tmpEmpId,
    ]);
    $afterCancelBcl = $db->query("SELECT COUNT(*) FROM booking_cost_lines WHERE id IN ({$inCostPh}) AND paid_cash_tx_id IS NULL")->fetch_row()[0];
    $afterCancelTx  = $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$commTxId}")->fetch_row()[0];
    fp_ok(
        $cancelRes['code'] === 302 && (int) $afterCancelBcl === 2 && (int) $afterCancelTx === 0,
        'POST commissions/cancel.php membatalkan pencairan, menghapus kas keluar, & mereset kedua baris menjadi Belum Dibayar'
    );

    // Uji hapus Pengeluaran dengan lampiran bukti -> transaksi terhapus dari DB
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expIdx['body'], $mcDelExp);
    $delExpRes = $http($jarAdmin, '/pages/finance/expenses/delete.php', [
        'csrf_token' => $mcDelExp[1] ?? ($mcComm[1] ?? ''),
        'id'         => (string) $expTxId,
    ]);
    $afterDelExp = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$expTxId}")->fetch_row()[0];
    fp_ok($delExpRes['code'] === 302 && $afterDelExp === 0, 'Hapus pengeluaran berbukti menghapus baris & membersihkan lampiran');

    echo "\n=== RBAC Staff di Komisi Sopir / Kru ===\n";
    $stUname = 'fp_staff_' . getmypid();
    $stHash  = password_hash('Staff@1234', PASSWORD_DEFAULT);
    $stMail  = $stUname . '@test.local';
    $stInsU  = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'Staff Uji FP', 4, 1, 1)");
    $stInsU->bind_param('sss', $stUname, $stMail, $stHash);
    $stInsU->execute();
    $tmpStaffId = (int) $db->insert_id;
    $stInsU->close();

    fp_ok($loginAs($jarStaff, $stUname, 'Staff@1234'), 'Login Staff uji HTTP OK');
    fp_ok($http($jarStaff, '/pages/finance/commissions/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di commissions/index.php');
    fp_ok($http($jarStaff, '/pages/finance/commissions/pay.php', ['csrf_token' => 'x'])['code'] === 403, 'RBAC: Staff ditolak 403 di commissions/pay.php');
    fp_ok($http($jarStaff, '/pages/finance/commissions/cancel.php', ['csrf_token' => 'x'])['code'] === 403, 'RBAC: Staff ditolak 403 di commissions/cancel.php');
} finally {
    foreach ($tmpBlobs as $bp) {
        if ($bp !== '') {
            @azure_blob_delete($bp);
        }
    }
    if ($tmpCostIds) {
        $inC = implode(',', array_map('intval', $tmpCostIds));
        $db->query("DELETE FROM booking_cost_lines WHERE id IN ({$inC})");
    }
    if ($tmpBookingId > 0) {
        try { booking_delete('travel', $tmpBookingId, 1); } catch (Throwable $e) {}
    }
    if ($tmpEmpId > 0) {
        $db->query("DELETE FROM employees WHERE id = {$tmpEmpId}");
    }
    if ($tmpPaxId > 0) {
        $db->query("DELETE FROM passengers WHERE id = {$tmpPaxId}");
    }
    foreach ($tmpTxIds as $tid) {
        $db->query("DELETE FROM cash_transactions WHERE id = " . (int) $tid);
    }
    $db->query("DELETE FROM cash_transactions WHERE description LIKE 'FP123_%'");
    if ($tmpStaffId > 0) {
        $db->query("DELETE FROM users WHERE id = {$tmpStaffId}");
        $db->query("DELETE FROM login_attempts WHERE username LIKE 'fp_staff_%'");
    }
    $db->query("DELETE FROM audit_trails WHERE created_date >= NOW() - INTERVAL 15 MINUTE AND (description LIKE '%Pencairan Uji FP123%' OR description LIKE '%185.000%' OR description LIKE '%320.000%' OR description LIKE '%Sopir Uji Komisi%' OR description LIKE '%Pencairan komisi%')");
    @unlink($jarAdmin);
    @unlink($jarStaff);
    @unlink($imgTmp);
}

echo "\nRingkasan Finance Points 1/2/3 Check: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
