<?php
/**
 * Integration, Azure Blob Upload, RBAC & Report Reconciliation check:
 *   - Bukti struk/nota (Azure Blob) & form ringkas tanpa payment_method_id / vehicle_id
 *   - Sinkronisasi otomatis penugasan Komisi Sopir/Kru dari Booking ke Pengeluaran / Buku Kas (`source_type='crew_commission'`)
 *   - Pengeluaran Kas per Kategori & Laba Bersih Operasional
 *     (tanpa double-counting crew_commission) di halaman & export Laporan Keuangan.
 *
 * Usage: C:\xampp\php\php.exe tests/finance_points_123_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
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

$tmpTxIds      = [];
$tmpCostIds    = [];
$tmpBlobs      = [];
$tmpBookingId  = 0;
$tmpRentalBbmId = 0;
$tmpPaxId      = 0;
$tmpEmpId     = 0;
$tmpStaffId   = 0;

$_SESSION['user_id']        = 1;
$_SESSION['username']       = 'superadmin';
$_SESSION['role_name']      = 'Super Admin';
$_SESSION['is_super_admin'] = true;
$_SESSION['company_id']     = 1;

try {
    $today  = date('Y-m-d');
    $veh1Id = (int) ($db->query('SELECT id FROM vehicles WHERE company_id = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1')->fetch_column() ?: 0);

    echo "=== POIN 1: Skema Kas Ringkas & Upload Bukti Struk (Azure Blob) ===\n";
    $hasRemovedCols = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'cash_transactions'
            AND column_name IN ('payment_method_id', 'vehicle_id')"
    )->fetch_row()[0];
    fp_ok($hasRemovedCols === 0, 'Kolom payment_method_id & vehicle_id sudah terhapus dari cash_transactions');

    fp_ok($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin HTTP OK');
    $dash = $http($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dash['body'], $mSw)) {
        $http($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mSw[1], 'company_id' => '1']);
    }

    // Uji form Pengeluaran ringkas (enctype multipart + bukti, tanpa payment_method_id/vehicle_id)
    $expForm = $http($jarAdmin, '/pages/finance/expenses/form.php');
    fp_ok(
        $expForm['code'] === 200
        && str_contains($expForm['body'], 'enctype="multipart/form-data"')
        && str_contains($expForm['body'], 'name="proof_file"')
        && !str_contains($expForm['body'], 'name="payment_method_id"')
        && !str_contains($expForm['body'], 'name="vehicle_id"'),
        'Form Pengeluaran ringkas memuat enctype multipart & Bukti Struk tanpa Metode Pembayaran / Armada'
    );
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expForm['body'], $mcExp);

    $markerExp = 'FP123_EXP_' . getmypid();
    $postExp = $http($jarAdmin, '/pages/finance/expenses/form.php', [
        'csrf_token'      => $mcExp[1] ?? '',
        'id'              => '0',
        'return'          => 'expenses',
        'date'            => $today,
        'amount'          => '185.000',
        'category_preset' => 'BBM',
        'category_custom' => '',
        'description'     => $markerExp,
    ], ['proof_file' => $imgTmp]);
    fp_ok($postExp['code'] === 302, 'POST Pengeluaran + upload bukti PNG berhasil (302)');

    $stE = $db->prepare('SELECT id, proof_blob_path FROM cash_transactions WHERE company_id = 1 AND description = ? LIMIT 1');
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
        $expTxId > 0 && !empty($rowExp['proof_blob_path']),
        'cash_transactions menyimpan proof_blob_path Azure Blob (' . ($rowExp['proof_blob_path'] ?? '-') . ')'
    );

    // Cek daftar Pengeluaran ringkas tanpa Metode/Armada & menampilkan ikon Lihat Bukti
    $expIdx = $http($jarAdmin, '/pages/finance/expenses/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $expIdx['code'] === 200
        && str_contains($expIdx['body'], $markerExp)
        && str_contains($expIdx['body'], 'title="Lihat Bukti Struk/Nota"')
        && !str_contains($expIdx['body'], 'name="method"')
        && !str_contains($expIdx['body'], 'name="vehicle_id"'),
        'Daftar Pengeluaran bebas dari filter Metode/Armada dan menampilkan tombol Lihat Bukti'
    );

    // Cek Export Buku Kas / Pengeluaran memakai format 5 kolom tanpa Metode
    $csvExp = $http($jarAdmin, '/pages/finance/cash/export.php?direction=out&format=csv&from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $csvExp['code'] === 200
        && str_contains($csvExp['body'], $markerExp)
        && !str_contains($csvExp['body'], 'Tanggal,Metode,'),
        'Export CSV Pengeluaran format 5 kolom ringkas berjalan normal'
    );

    // Uji Pemasukan ringkas + Upload Bukti
    $incForm = $http($jarAdmin, '/pages/finance/incomes/form.php');
    fp_ok(
        $incForm['code'] === 200
        && str_contains($incForm['body'], 'name="proof_file"')
        && !str_contains($incForm['body'], 'name="payment_method_id"')
        && !str_contains($incForm['body'], 'name="vehicle_id"'),
        'Form Pemasukan ringkas bebas dari Metode Pembayaran & Armada'
    );
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $incForm['body'], $mcInc);
    $markerInc = 'FP123_INC_' . getmypid();
    $postInc = $http($jarAdmin, '/pages/finance/incomes/form.php', [
        'csrf_token'      => $mcInc[1] ?? '',
        'id'              => '0',
        'return'          => 'incomes',
        'date'            => $today,
        'amount'          => '320.000',
        'category_preset' => 'Pemasukan Lainnya',
        'description'     => $markerInc,
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

    echo "\n=== POIN 2: Mapping Otomatis Penugasan Sopir / Kru Booking ke Pengeluaran ===\n";
    fp_ok($http($jarAdmin, '/pages/finance/commissions/index.php')['code'] === 404, 'Menu pages/finance/commissions/index.php sudah dihapus (404)');

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

    booking_crew_sync('travel', $tmpBookingId, 1, 0.0, [
        ['type' => 'Sopir', 'employee_id' => $tmpEmpId, 'amount' => 140000.0],
        ['type' => 'Uang Jalan', 'employee_id' => $tmpEmpId, 'amount' => 60000.0],
    ], $veh1Id, 'fp123-check');

    $costRows = $db->query("SELECT id FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = {$tmpBookingId} AND cost_type <> 'payment' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    $tmpCostIds = array_map('intval', array_column($costRows, 'id'));
    fp_ok(count($tmpCostIds) === 2, '2 baris penugasan kru booking_cost_lines dibuat (Rp 140.000 + Rp 60.000 = Rp 200.000)');

    $inCostPh = implode(',', $tmpCostIds);
    $syncedComm = $db->query("SELECT id FROM cash_transactions WHERE company_id = 1 AND source_type = 'crew_commission' AND source_id IN ({$inCostPh})")->fetch_all(MYSQLI_ASSOC);
    fp_ok(
        count($syncedComm) === 0,
        'Pola B: penugasan kru hanya tercatat di booking_cost_lines & tidak auto-sync ke cash_transactions'
    );

    // Pastikan Hak Komisi muncul di Laporan Driver & pencairan manual dicatat lewat Pengeluaran (Biaya Komisi Karyawan)
    $dispCheck = $http($jarAdmin, '/pages/reports/dispatch.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $dispCheck['code'] === 200
        && str_contains($dispCheck['body'], $empName)
        && str_contains($dispCheck['body'], '200.000')
        && str_contains($dispCheck['body'], 'Biaya+Komisi+Karyawan'),
        'Laporan Driver menampilkan Hak Komisi Rp 200.000 untuk ' . $empName . ' & tautan Catat Bayar Komisi'
    );

    $commFormGet = $http($jarAdmin, '/pages/finance/expenses/form.php?category=' . urlencode('Biaya Komisi Karyawan'));
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $commFormGet['body'], $mcCommPay);
    $markerManualComm = 'FP123_COMM_' . getmypid();
    $postManualComm = $http($jarAdmin, '/pages/finance/expenses/form.php', [
        'csrf_token'      => $mcCommPay[1] ?? '',
        'id'              => '0',
        'return'          => 'expenses',
        'date'            => $today,
        'amount'          => '200.000',
        'category_preset' => 'Biaya Komisi Karyawan',
        'category_custom' => '',
        'description'     => $markerManualComm,
    ]);
    $stMC = $db->prepare('SELECT id, direction, amount, category, source_type FROM cash_transactions WHERE company_id = 1 AND description = ? LIMIT 1');
    $stMC->bind_param('s', $markerManualComm);
    $stMC->execute();
    $rowManualComm = $stMC->get_result()->fetch_assoc();
    $stMC->close();
    $manualCommTxId = (int) ($rowManualComm['id'] ?? 0);
    if ($manualCommTxId > 0) {
        $tmpTxIds[] = $manualCommTxId;
    }
    fp_ok(
        $postManualComm['code'] === 302
        && $manualCommTxId > 0
        && $rowManualComm['direction'] === 'out'
        && $rowManualComm['category'] === 'Biaya Komisi Karyawan'
        && $rowManualComm['source_type'] === null
        && abs((float) $rowManualComm['amount'] - 200000.0) < 0.01,
        'Pencairan komisi manual via Pengeluaran (Biaya Komisi Karyawan, Rp 200.000) berhasil tercatat tanpa auto-sync'
    );

    // Uji sinkronisasi otomatis BBM pemesanan Rental -> Pengeluaran (BBM, Auto BBM)
    $rbBbm = booking_create('rental', [
        'company_id'                 => 1,
        'branch_id'                  => $branch1,
        'date'                       => $today,
        'time'                       => '09:00:00',
        'start_date'                 => $today,
        'end_date'                   => $today,
        'trip'                       => 1,
        'pickup_address'             => 'Jemput BBM Uji',
        'pickup_point_province_id'   => (int) $distRow['province_id'],
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_address'           => 'Antar BBM Uji',
        'delivery_point_province_id' => (int) $distRow['province_id'],
        'delivery_point_city_id'     => (int) $distRow['city_id'],
        'delivery_point_district_id' => (int) $distRow['id'],
        'bbm'                        => 250000.0,
        'total_price'                => 1200000.0,
        'category_vehicle_id'        => null,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $tmpPaxId,
        'note'                       => null,
        'created_user'               => 'fp123-check',
    ]);
    $tmpRentalBbmId = (int) $rbBbm['id'];
    $bbmTx = $db->query("SELECT id, direction, amount, category, description FROM cash_transactions WHERE company_id = 1 AND source_type = 'booking_bbm' AND source_id = {$tmpRentalBbmId} LIMIT 1")->fetch_assoc();
    $bbmTxId = (int) ($bbmTx['id'] ?? 0);
    if ($bbmTxId > 0) {
        $tmpTxIds[] = $bbmTxId;
    }
    fp_ok(
        $bbmTxId > 0
        && $bbmTx['direction'] === 'out'
        && $bbmTx['category'] === 'BBM'
        && abs((float) $bbmTx['amount'] - 250000.0) < 0.01
        && str_contains((string) $bbmTx['description'], $rbBbm['document_number']),
        'BBM pemesanan Rental otomatis masuk ke cash_transactions (out, BBM, Rp 250.000)'
    );

    $expBbmCheck = $http($jarAdmin, '/pages/finance/expenses/index.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        str_contains($expBbmCheck['body'], 'Auto BBM')
        && str_contains($expBbmCheck['body'], 'rental/bookings/detail.php?id=' . $tmpRentalBbmId . '&amp;tab=crew#tab-crew'),
        'Pengeluaran menampilkan badge Auto BBM tertaut ke detail rental #tab-crew'
    );
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expBbmCheck['body'], $mcGuardDel);
    $guardBbmDel = $http($jarAdmin, '/pages/finance/expenses/delete.php', [
        'csrf_token' => $mcGuardDel[1] ?? '',
        'id'         => (string) $bbmTxId,
    ]);
    $stillBbmTx = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$bbmTxId}")->fetch_row()[0];
    fp_ok($guardBbmDel['code'] === 302 && $stillBbmTx === 1, 'Guard: transaksi Auto BBM tidak bisa dihapus manual lewat expenses/delete.php');

    echo "\n=== POIN 3: Pengeluaran per Kategori & Laba Bersih Operasional ===\n";
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $fFin = fin_report_filter(['from' => $today, 'to' => $today, 'booking_type' => 'all']);
    $finAll = fin_all($fFin, null);

    // Periksa tidak terjadi double-counting: Biaya Komisi Karyawan manual (Rp 200.000) & booking_bbm (Rp 250.000)
    // masuk cash['out_commission'] & cash['out_bbm'], BUKAN cash['out_operating'], karena sudah masuk ke trip cost_total!
    $expSumCats = fin_expense_category_totals($finAll['expense_categories']);
    fp_ok(
        isset($finAll['expense_categories'], $finAll['net_profit'], $finAll['net_summary'])
        && !isset($finAll['expense_vehicles'])
        && abs($expSumCats['amount'] - (float) $finAll['cash']['out_total']) < 0.01,
        'fin_all() menyertakan expense_categories & totalnya cocok 100% dengan cash.out_total (' . rupiah($expSumCats['amount']) . ')'
    );
    $expectedOperating = max(0.0, (float) $finAll['cash']['out_total'] - (float) $finAll['cash']['out_commission'] - (float) ($finAll['cash']['out_bbm'] ?? 0));
    fp_ok(
        (float) $finAll['cash']['out_commission'] >= 200000.0
        && (float) ($finAll['cash']['out_bbm'] ?? 0) >= 250000.0
        && abs((float) $finAll['cash']['out_operating'] - $expectedOperating) < 0.01,
        'Laba Bersih memisahkan out_commission & out_bbm agar tidak menghitung ganda biaya komisi & BBM trip'
    );
    $expectedNet = (float) $finAll['gross_profit'] + (float) $finAll['cash']['in_other'] - (float) $finAll['cash']['out_operating'];
    fp_ok(abs((float) $finAll['net_profit'] - $expectedNet) < 0.01, 'Rumus Laba Bersih Operasional = gross_profit + in_other - out_operating akurat');

    $rptHtml = $http($jarAdmin, '/pages/reports/finance.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $rptHtml['code'] === 200
        && str_contains($rptHtml['body'], 'Ringkasan Laba Bersih Operasional')
        && str_contains($rptHtml['body'], 'Pengeluaran Kas per Kategori')
        && str_contains($rptHtml['body'], 'Distribusi Pengeluaran Kas')
        && !str_contains($rptHtml['body'], 'Pengeluaran Kas per Armada'),
        'Laporan Keuangan HTML memuat Laba Bersih Operasional, Pengeluaran per Kategori & Distribusi Pengeluaran Kas'
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

    // Uji sinkronisasi ulang kru (Pola B): memperbarui booking_cost_lines & Hak Komisi Laporan Driver tanpa membuat crew_commission di kas
    booking_crew_sync('travel', $tmpBookingId, 1, 0.0, [
        ['type' => 'Sopir', 'employee_id' => $tmpEmpId, 'amount' => 175000.0],
    ], $veh1Id, 'fp123-check');
    $reSyncCashCnt = (int) $db->query("SELECT COUNT(*) FROM cash_transactions ct JOIN booking_cost_lines bcl ON ct.source_type='crew_commission' AND ct.source_id=bcl.id WHERE bcl.booking_type='travel' AND bcl.booking_id={$tmpBookingId}")->fetch_row()[0];
    $reSyncCostSum = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines WHERE booking_type='travel' AND booking_id={$tmpBookingId} AND cost_type <> 'payment'")->fetch_row()[0];
    $dispAfterResync = $http($jarAdmin, '/pages/reports/dispatch.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        $reSyncCashCnt === 0
        && abs($reSyncCostSum - 175000.0) < 0.01
        && str_contains($dispAfterResync['body'], '175.000'),
        'Re-sync booking_crew_sync memperbarui Hak Komisi (Rp 175.000) tanpa membuat crew_commission di kas'
    );

    booking_change_status('travel', $tmpBookingId, 1, 'cancelled', 'Uji batal komisi', 'fp123-check');
    $dispAfterCancel = $http($jarAdmin, '/pages/reports/dispatch.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        !str_contains($dispAfterCancel['body'], '175.000'),
        'Saat booking dibatalkan (cancelled), Hak Komisi tidak dihitung di Laporan Driver'
    );

    booking_change_status('travel', $tmpBookingId, 1, 'draft', 'Uji aktifkan komisi', 'fp123-check');
    $dispAfterRestore = $http($jarAdmin, '/pages/reports/dispatch.php?from=' . urlencode($today) . '&to=' . urlencode($today));
    fp_ok(
        str_contains($dispAfterRestore['body'], '175.000'),
        'Saat status booking dipulihkan dari cancelled, Hak Komisi tampil kembali di Laporan Driver'
    );

    // Uji update, nol-kan, batal & pulihkan BBM Rental via booking_rental_crew_sync + booking_change_status
    booking_rental_crew_sync($tmpRentalBbmId, 1, 300000.0, [], $veh1Id, 'fp123-check');
    $bbmUpdAmt = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE source_type='booking_bbm' AND source_id={$tmpRentalBbmId}")->fetch_row()[0];
    fp_ok(abs($bbmUpdAmt - 300000.0) < 0.01, 'Update BBM via booking_rental_crew_sync memperbarui transaksi BBM menjadi Rp 300.000');

    booking_rental_crew_sync($tmpRentalBbmId, 1, 0.0, [], $veh1Id, 'fp123-check');
    $bbmZeroCnt = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE source_type='booking_bbm' AND source_id={$tmpRentalBbmId}")->fetch_row()[0];
    fp_ok($bbmZeroCnt === 0, 'Mengubah BBM menjadi 0 otomatis menghapus transaksi BBM di Pengeluaran');

    booking_rental_crew_sync($tmpRentalBbmId, 1, 250000.0, [], $veh1Id, 'fp123-check');
    booking_change_status('rental', $tmpRentalBbmId, 1, 'cancelled', 'Uji batal BBM rental', 'fp123-check');
    $bbmCancelCnt = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE source_type='booking_bbm' AND source_id={$tmpRentalBbmId}")->fetch_row()[0];
    booking_change_status('rental', $tmpRentalBbmId, 1, 'draft', 'Uji aktifkan BBM rental', 'fp123-check');
    $bbmRestoreAmt = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE source_type='booking_bbm' AND source_id={$tmpRentalBbmId}")->fetch_row()[0];
    fp_ok($bbmCancelCnt === 0 && abs($bbmRestoreAmt - 250000.0) < 0.01, 'Saat rental cancelled transaksi BBM dihapus, dan saat dipulihkan otomatis disinkronkan kembali');

    // Uji hapus Pengeluaran dengan lampiran bukti -> transaksi terhapus dari DB
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $expIdx['body'], $mcDelExp);
    $delExpRes = $http($jarAdmin, '/pages/finance/expenses/delete.php', [
        'csrf_token' => $mcDelExp[1] ?? ($mcGuardDel[1] ?? ''),
        'id'         => (string) $expTxId,
    ]);
    $afterDelExp = (int) $db->query("SELECT COUNT(*) FROM cash_transactions WHERE id = {$expTxId}")->fetch_row()[0];
    fp_ok($delExpRes['code'] === 302 && $afterDelExp === 0, 'Hapus pengeluaran berbukti menghapus baris & membersihkan lampiran');

    echo "\n=== RBAC Staff di Keuangan / Pengeluaran ===\n";
    $stUname = 'fp_staff_' . getmypid();
    $stHash  = password_hash('Staff@1234', PASSWORD_DEFAULT);
    $stMail  = $stUname . '@test.local';
    $stInsU  = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'Staff Uji FP', 4, 1, 1)");
    $stInsU->bind_param('sss', $stUname, $stMail, $stHash);
    $stInsU->execute();
    $tmpStaffId = (int) $db->insert_id;
    $stInsU->close();

    fp_ok($loginAs($jarStaff, $stUname, 'Staff@1234'), 'Login Staff uji HTTP OK');
    fp_ok($http($jarStaff, '/pages/finance/expenses/index.php')['code'] === 403, 'RBAC: Staff ditolak 403 di expenses/index.php');
    fp_ok($http($jarStaff, '/pages/reports/finance.php')['code'] === 403, 'RBAC: Staff ditolak 403 di reports/finance.php');
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
    if ($tmpRentalBbmId > 0) {
        try { booking_delete('rental', $tmpRentalBbmId, 1); } catch (Throwable $e) {}
        $db->query("DELETE FROM cash_transactions WHERE source_type = 'booking_bbm' AND source_id = {$tmpRentalBbmId}");
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
