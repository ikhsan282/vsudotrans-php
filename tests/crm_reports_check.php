<?php
/**
 * Integration & HTTP check for Points 1, 2, and 5:
 *   1B: Sidebar shortcut `Manifest Gabungan` -> pages/dispatch/manifest_group.php
 *   2 : CRM Mini (`passengers/detail.php`), Global Search URL, Viewer RBAC,
 *       cross-company 403, & 1-click repeat order prefill (Travel + Rental)
 *   5 : Reports Period Presets & Category Breakdown + filtering + CSV/XLSX/PDF exports
 *
 * Usage: C:\xampp\php\php.exe tests/crm_reports_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/transport_helpers.php';
require_once __DIR__ . '/../pages/reports/_query.php';
require_once __DIR__ . '/../pages/reports/_operations_query.php';
require_once __DIR__ . '/../pages/reports/_finance_query.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();

$pass = 0;
$fail = 0;
function cr_check(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}\n";
    }
}

$jarAdmin  = tempnam(sys_get_temp_dir(), 'cr_adm_');
$jarViewer = tempnam(sys_get_temp_dir(), 'cr_vw_');
@unlink($jarAdmin);
@unlink($jarViewer);

$httpGet = static function (?string $jar, string $path) use ($base): array {
    $hdr = tempnam(sys_get_temp_dir(), 'cr_h_');
    $cArg = $jar !== null ? (' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)) : '';
    $body = (string) shell_exec('curl.exe -s -D ' . escapeshellarg($hdr) . $cArg . ' ' . escapeshellarg($base . $path));
    $hdrs = is_file($hdr) ? (string) file_get_contents($hdr) : '';
    @unlink($hdr);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrs, $m)) {
        $code = (int) $m[1];
    }
    return ['code' => $code, 'headers' => $hdrs, 'body' => $body, 'json' => json_decode($body, true)];
};

$httpPost = static function (string $jar, string $path, array $fields) use ($base): array {
    $hdr = tempnam(sys_get_temp_dir(), 'cr_ph_');
    $bf  = tempnam(sys_get_temp_dir(), 'cr_pb_');
    file_put_contents($bf, http_build_query($fields));
    $body = (string) shell_exec(
        'curl.exe -s -D ' . escapeshellarg($hdr)
        . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
        . ' -H "Content-Type: application/x-www-form-urlencoded"'
        . ' --data-binary @' . escapeshellarg($bf)
        . ' ' . escapeshellarg($base . $path)
    );
    $hdrs = is_file($hdr) ? (string) file_get_contents($hdr) : '';
    @unlink($hdr);
    @unlink($bf);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrs, $m)) {
        $code = (int) $m[1];
    }
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
};

$loginAs = static function (string $jar, string $username, string $password) use ($httpGet, $httpPost): bool {
    $lp = $httpGet($jar, '/pages/auth/login.php');
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lp['body'], $m)) {
        return false;
    }
    $res = $httpPost($jar, '/pages/auth/login.php', [
        'csrf_token' => $m[1],
        'username'   => $username,
        'password'   => $password,
    ]);
    return $res['code'] === 302 || $res['code'] === 200;
};

$tempPaxIds     = [];
$tempBookings   = []; // [['travel'|'rental', id, company_id]]
$tempViewerId   = 0;
$viewerUser     = 'crm_viewer_' . getmypid();

try {
    // ── Fixtures for CRM & Cross-Tenant Checks ──────────────────────────────
    $branch1 = (int) $db->query('SELECT id FROM branches WHERE company_id = 1 LIMIT 1')->fetch_column();
    $travelCat1 = (int) $db->query("SELECT id FROM category_vehicles WHERE company_id = 1 AND is_active = 1 AND service_type = 'travel' LIMIT 1")->fetch_column();
    $rentalCat1 = (int) $db->query("SELECT id FROM category_vehicles WHERE company_id = 1 AND is_active = 1 AND service_type = 'rental' LIMIT 1")->fetch_column();

    $distRow = $db->query("SELECT d.id, d.city_id, d.province_id, d.name AS dist_name, c.name AS city_name
                             FROM districts d JOIN cities c ON c.id = d.city_id
                            ORDER BY d.id ASC LIMIT 1")->fetch_assoc();
    $distRow2 = $db->query("SELECT d.id, d.city_id, d.province_id, d.name AS dist_name, c.name AS city_name
                              FROM districts d JOIN cities c ON c.id = d.city_id
                             WHERE d.city_id <> " . (int) $distRow['city_id'] . "
                             ORDER BY d.id ASC LIMIT 1")->fetch_assoc() ?: $distRow;

    $paxCode1 = 'CRMPX1' . random_int(100, 999);
    $paxName1 = 'CRM Test Pelanggan MTM';
    $stP = $db->prepare('INSERT INTO passengers (company_id, code, name, phone_number, email, note) VALUES (1, ?, ?, ?, ?, ?)');
    $pPhone = '6281234567801';
    $pEmail = 'crm.test@example.com';
    $pNote  = 'Catatan pelanggan uji CRM';
    $stP->bind_param('sssss', $paxCode1, $paxName1, $pPhone, $pEmail, $pNote);
    $stP->execute();
    $paxId1 = (int) $db->insert_id;
    $stP->close();
    $tempPaxIds[] = $paxId1;

    $otherCompId = (int) $db->query('SELECT id FROM companies WHERE id <> 1 AND is_active = 1 LIMIT 1')->fetch_column();
    $paxOtherId = 0;
    if ($otherCompId > 0) {
        $paxCode2 = 'CRMPX2' . random_int(100, 999);
        $paxName2 = 'CRM Cross Company Pax';
        $stP2 = $db->prepare('INSERT INTO passengers (company_id, code, name, phone_number) VALUES (?, ?, ?, ?)');
        $pPhone2 = '6281999900001';
        $stP2->bind_param('isss', $otherCompId, $paxCode2, $paxName2, $pPhone2);
        $stP2->execute();
        $paxOtherId = (int) $db->insert_id;
        $stP2->close();
        $tempPaxIds[] = $paxOtherId;
    }

    // Create 1 travel primary booking & 1 rental companion booking for $paxId1
    $pkAddr = 'Jl. Uji Jemput No. 88 CRM';
    $dlAddr = 'Jl. Uji Antar No. 99 CRM';
    $tb = booking_create('travel', [
        'company_id'                 => 1,
        'branch_id'                  => $branch1,
        'date'                       => date('Y-m-d'),
        'time'                       => '08:30:00',
        'pickup_address'             => $pkAddr,
        'pickup_point_province_id'   => (int) $distRow['province_id'],
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_address'           => $dlAddr,
        'delivery_point_province_id' => (int) $distRow2['province_id'],
        'delivery_point_city_id'     => (int) $distRow2['city_id'],
        'delivery_point_district_id' => (int) $distRow2['id'],
        'total_price'                => 450000,
        'total_payment'              => 150000,
        'category_vehicle_id'        => $travelCat1 ?: null,
        'passenger_id'               => $paxId1,
        'created_user'               => 'crm-check',
    ]);
    $tempBookings[] = ['travel', (int) $tb['id'], 1];

    $leadPax2Code = 'CRMLD' . random_int(100, 999);
    $stLd = $db->prepare("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, ?, 'Lead Rental CRM', '6281111222333')");
    $stLd->bind_param('s', $leadPax2Code);
    $stLd->execute();
    $leadPax2Id = (int) $db->insert_id;
    $stLd->close();
    $tempPaxIds[] = $leadPax2Id;

    $rb = booking_create('rental', [
        'company_id'                 => 1,
        'branch_id'                  => $branch1,
        'date'                       => date('Y-m-d'),
        'start_date'                 => date('Y-m-d'),
        'end_date'                   => date('Y-m-d', strtotime('+1 day')),
        'time'                       => '09:00:00',
        'trip'                       => 1,
        'pickup_address'             => 'Pool MTM CRM',
        'pickup_point_province_id'   => (int) $distRow['province_id'],
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_address'           => 'Tujuan Rental CRM',
        'delivery_point_province_id' => (int) $distRow2['province_id'],
        'delivery_point_city_id'     => (int) $distRow2['city_id'],
        'delivery_point_district_id' => (int) $distRow2['id'],
        'total_price'                => 1200000,
        'category_vehicle_id'        => $rentalCat1 ?: null,
        'passenger_id'               => $leadPax2Id,
        'passenger_ids'              => [$leadPax2Id, $paxId1],
        'created_user'               => 'crm-check',
    ]);
    $tempBookings[] = ['rental', (int) $rb['id'], 1];

    // Create temporary Viewer user scoped strictly to company 1
    $viewerRoleId = (int) $db->query("SELECT id FROM roles WHERE name = 'Viewer' LIMIT 1")->fetch_column();
    if ($viewerRoleId > 0) {
        $pwHash = password_hash('Viewer@1234', PASSWORD_BCRYPT);
        $vEmail = $viewerUser . '@example.test';
        $stU = $db->prepare('INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active, created_date, updated_date) VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), NOW())');
        $vFull = 'Viewer CRM Uji';
        $stU->bind_param('ssssi', $viewerUser, $vEmail, $pwHash, $vFull, $viewerRoleId);
        $stU->execute();
        $tempViewerId = (int) $db->insert_id;
        $stU->close();
        $stUc = $db->prepare('INSERT IGNORE INTO user_companies (user_id, company_id, created_date) VALUES (?, 1, NOW())');
        $stUc->bind_param('i', $tempViewerId);
        $stUc->execute();
        $stUc->close();
    }

    echo "1. Login Super Admin & Viewer...\n";
    cr_check($loginAs($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Login Super Admin berhasil');
    // Switch active company to 1 (MTM) for admin repeat-order tests
    $dashA = $httpGet($jarAdmin, '/pages/dashboard/index.php');
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $dashA['body'], $mC)) {
        $httpPost($jarAdmin, '/pages/companies/switch.php', ['csrf_token' => $mC[1], 'company_id' => '1']);
    }
    if ($tempViewerId > 0) {
        cr_check($loginAs($jarViewer, $viewerUser, 'Viewer@1234'), 'Login Viewer company 1 berhasil');
    }

    echo "2. Verifikasi Point 1B (Sidebar Manifest Gabungan)...\n";
    $dashAfter = $httpGet($jarAdmin, '/pages/dashboard/index.php');
    cr_check($dashAfter['code'] === 200, 'Dashboard HTTP 200');
    cr_check(
        str_contains($dashAfter['body'], 'pages/dispatch/manifest_group.php') && str_contains($dashAfter['body'], 'Manifest Gabungan'),
        'Sidebar menampilkan link Manifest Gabungan -> pages/dispatch/manifest_group.php'
    );

    echo "3. Verifikasi Point 2 (CRM Pelanggan + Viewer RBAC + Global Search + Repeat Order)...\n";
    $paxIdx = $httpGet($jarAdmin, '/pages/transport/passengers/index.php?q=' . urlencode($paxCode1));
    cr_check($paxIdx['code'] === 200, 'passengers/index.php HTTP 200');
    cr_check(
        str_contains($paxIdx['body'], 'pages/transport/passengers/detail.php?id=' . $paxId1),
        'passengers/index.php menautkan nama & tombol eye ke detail.php'
    );
    cr_check(
        str_contains($paxIdx['body'], 'title="Lihat Profil &amp; Riwayat"') && str_contains($paxIdx['body'], 'aria-label="Lihat Profil &amp; Riwayat"'),
        'passengers/index.php memiliki tombol eye icon-only dengan title & aria-label'
    );

    $gs = $httpGet($jarAdmin, '/pages/search/global.php?q=' . urlencode($paxCode1));
    $gsPaxUrl = '';
    foreach (($gs['json']['groups'] ?? []) as $g) {
        if (($g['key'] ?? '') === 'passengers') {
            $gsPaxUrl = (string) ($g['items'][0]['url'] ?? '');
        }
    }
    cr_check(
        str_contains($gsPaxUrl, '/pages/transport/passengers/detail.php?id=' . $paxId1),
        "global search mengarahkan penumpang ke detail.php (got: {$gsPaxUrl})"
    );

    $detAdmin = $httpGet($jarAdmin, '/pages/transport/passengers/detail.php?id=' . $paxId1);
    cr_check($detAdmin['code'] === 200, 'passengers/detail.php (Admin) HTTP 200');
    cr_check(
        str_contains($detAdmin['body'], $paxName1)
        && str_contains($detAdmin['body'], 'https://wa.me/6281234567801')
        && str_contains($detAdmin['body'], $tb['document_number'])
        && str_contains($detAdmin['body'], $rb['document_number'])
        && str_contains($detAdmin['body'], 'Penumpang Tambahan')
        && str_contains($detAdmin['body'], 'Pesan Travel Lagi')
        && str_contains($detAdmin['body'], 'Pesan Sewa Lagi'),
        'passengers/detail.php menampilkan profil WA, riwayat Travel+Rental (termasuk Penumpang Tambahan), & CTA Pesan Lagi'
    );
    cr_check(
        str_contains($detAdmin['body'], '/pages/dispatch/manifest.php?type=travel&amp;booking_id=' . (int) $tb['id'])
        && str_contains($detAdmin['body'], '/pages/dispatch/manifest.php?type=rental&amp;booking_id=' . (int) $rb['id'])
        && !str_contains($detAdmin['body'], 'surat_jalan.php'),
        'passengers/detail.php menautkan tombol Cetak Surat Jalan ke pages/dispatch/manifest.php?type=...'
    );
    $mjT = $httpGet($jarAdmin, '/pages/dispatch/manifest.php?type=travel&booking_id=' . (int) $tb['id']);
    $mjR = $httpGet($jarAdmin, '/pages/dispatch/manifest.php?type=rental&booking_id=' . (int) $rb['id']);
    cr_check($mjT['code'] === 200 && $mjR['code'] === 200, 'Endpoint manifest Surat Jalan (Travel & Rental) merespons HTTP 200');

    // Ensure icon-only actions in detail.php Aksi column have no visible text inside <a>
    $aksiClean = true;
    if (preg_match_all('/<td class="text-end text-nowrap">([\s\S]*?)<\/td>/', $detAdmin['body'], $mTds)) {
        foreach ($mTds[1] as $tdHtml) {
            if (preg_match_all('/<a\b[^>]*>([\s\S]*?)<\/a>/', $tdHtml, $mAs)) {
                foreach ($mAs[1] as $aInner) {
                    if (trim(strip_tags($aInner)) !== '') {
                        $aksiClean = false;
                    }
                }
            }
        }
    }
    cr_check($aksiClean, 'Kolom Aksi pada passengers/detail.php menggunakan tombol icon-only tanpa teks');

    if ($tempViewerId > 0) {
        $detViewer = $httpGet($jarViewer, '/pages/transport/passengers/detail.php?id=' . $paxId1);
        cr_check($detViewer['code'] === 200, 'Viewer dapat membuka passengers/detail.php (HTTP 200)');
        cr_check(
            !str_contains($detViewer['body'], 'Ubah Data')
            && !str_contains($detViewer['body'], 'Pesan Travel Lagi')
            && !str_contains($detViewer['body'], 'Pesan Sewa Lagi'),
            'Viewer tidak melihat tombol ubah / pesan ulang di passengers/detail.php'
        );
        if ($paxOtherId > 0) {
            $detCross = $httpGet($jarViewer, '/pages/transport/passengers/detail.php?id=' . $paxOtherId);
            cr_check($detCross['code'] === 403, 'Tenant isolation: Viewer company 1 ditolak (403) membuka pelanggan company lain');
        }
    }

    // 1-Click Repeat Order Prefill: Travel & Rental
    $trFormPre = $httpGet($jarAdmin, '/pages/travel/bookings/form.php?passenger_id=' . $paxId1 . '&prefill_last=1');
    cr_check($trFormPre['code'] === 200, 'travel/bookings/form.php?passenger_id=&prefill_last=1 HTTP 200');
    cr_check(
        str_contains($trFormPre['body'], 'Pesan ulang untuk')
        && str_contains($trFormPre['body'], $tb['document_number'])
        && str_contains($trFormPre['body'], $pkAddr)
        && str_contains($trFormPre['body'], $dlAddr)
        && (bool) preg_match('/<option value="' . $paxId1 . '" selected/', $trFormPre['body']),
        'Travel form mengisi otomatis pelanggan, banner pesan ulang, & alamat jemput/antar terakhir'
    );
    if ($travelCat1 > 0) {
        cr_check(
            (bool) preg_match('/<option value="' . $travelCat1 . '"[^>]*selected/', $trFormPre['body']),
            'Travel form mempertahankan kategori layanan terakhir yang sesuai'
        );
    }

    $rnFormPre = $httpGet($jarAdmin, '/pages/rental/bookings/form.php?passenger_id=' . $paxId1 . '&prefill_last=1');
    cr_check($rnFormPre['code'] === 200, 'rental/bookings/form.php?passenger_id=&prefill_last=1 HTTP 200');
    cr_check(
        str_contains($rnFormPre['body'], 'Pesan ulang untuk')
        && str_contains($rnFormPre['body'], $pkAddr)
        && str_contains($rnFormPre['body'], $dlAddr)
        && (bool) preg_match('/<option value="' . $paxId1 . '" selected/', $rnFormPre['body']),
        'Rental form mengisi otomatis pelanggan & lokasi jemput/antar terakhir'
    );

    if ($paxOtherId > 0) {
        $trCrossPre = $httpGet($jarAdmin, '/pages/travel/bookings/form.php?passenger_id=' . $paxOtherId . '&prefill_last=1');
        cr_check(
            !str_contains($trCrossPre['body'], 'Pesan ulang untuk') && !preg_match('/<option value="' . $paxOtherId . '" selected/', $trCrossPre['body']),
            'Cross-tenant ?passenger_id= company lain diabaikan di form pesan ulang'
        );
    }

    echo "4. Verifikasi Point 5 (Preset Periode & Breakdown Kategori Laporan)...\n";
    $_SESSION['role_name']  = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $fRange = report_filter(['from' => '2026-07-01', 'to' => '2026-12-31', 'booking_type' => 'all', 'category_id' => -9]);
    cr_check($fRange['category_id'] === 0, 'category_id negatif dinormalisasi menjadi 0');

    $repAll = report_all($fRange);
    $catTot = report_totals($repAll['category']);
    cr_check(
        $catTot['total_bookings'] === (int) $repAll['summary']['total_bookings']
        && abs($catTot['total_price'] - (float) $repAll['summary']['total_price']) < 0.01,
        "Laporan Booking: total dimensi Per Kategori cocok ringkasan ({$catTot['total_bookings']} = {$repAll['summary']['total_bookings']})"
    );

    $opsAll = ops_all($fRange);
    $opsCatMatch = true;
    $opsCatSum = 0;
    foreach ($opsAll['categories'] as $oc) {
        $opsCatSum += (int) $oc['total'];
        if ((int) $oc['berjalan'] + (int) $oc['selesai'] + (int) $oc['dibatalkan'] !== (int) $oc['total']) {
            $opsCatMatch = false;
        }
    }
    cr_check($opsCatMatch && $opsCatSum === (int) $repAll['summary']['total_bookings'],
        "Laporan Operasional: setiap kategori berjalan+selesai+batal=total & jumlah total={$opsCatSum}"
    );

    $finAll = fin_all($fRange, null);
    $finCatTot = fin_category_totals($finAll['categories']);
    cr_check(
        abs($finCatTot['revenue'] - (float) $finAll['revenue']['total']) < 0.01
        && abs($finCatTot['cost'] - (float) $finAll['cost_total']) < 0.01
        && abs($finCatTot['gross_profit'] - (float) $finAll['gross_profit']) < 0.01,
        'Laporan Keuangan: total kategori merekonsiliasi 100% pendapatan, biaya, & laba kotor'
    );

    // Filter by specific travel category
    if ($travelCat1 > 0) {
        $fCat = report_filter(['from' => '2026-07-01', 'to' => '2026-12-31', 'booking_type' => 'all', 'category_id' => $travelCat1]);
        $repCat = report_all($fCat);
        $finCatF = fin_all($fCat, null);
        cr_check(
            count($repCat['category']) <= 1 && (int) $repCat['summary']['total_bookings'] >= 1,
            'Filter category_id mempersempit Laporan Booking ke 1 kategori'
        );
        cr_check(
            abs(fin_category_totals($finCatF['categories'])['gross_profit'] - (float) $finCatF['gross_profit']) < 0.01,
            'Filter category_id tetap merekonsiliasi laba kotor pada Laporan Keuangan'
        );
    }

    // HTTP check for the 3 report HTML pages + CSV/XLSX/PDF exports
    $qs = 'from=2026-07-01&to=2026-12-31' . ($travelCat1 > 0 ? '&category_id=' . $travelCat1 : '');
    foreach ([
        ['reports/bookings.php',    'Per Kategori Layanan'],
        ['reports/operations.php',  'Operasional per Kategori Layanan'],
        ['reports/finance.php',     'Pendapatan &amp; Laba per Kategori Layanan'],
    ] as [$ep, $heading]) {
        $res = $httpGet($jarAdmin, '/pages/' . $ep . '?' . $qs);
        cr_check(
            $res['code'] === 200
            && str_contains($res['body'], 'rpt-preset-bar')
            && str_contains($res['body'], 'name="category_id"')
            && str_contains($res['body'], $heading),
            "{$ep} HTTP 200 & menampilkan preset periode, filter kategori, dan tabel {$heading}"
        );
    }

    foreach ([
        ['reports/bookings_export.php',   'Per Kategori Layanan'],
        ['reports/operations_export.php', 'Operasional per Kategori Layanan'],
        ['reports/finance_export.php',    'Pendapatan & Laba per Kategori Layanan'],
    ] as [$ep, $heading]) {
        $csv = $httpGet($jarAdmin, '/pages/' . $ep . '?' . $qs . '&format=csv');
        cr_check($csv['code'] === 200 && str_contains($csv['body'], $heading), "{$ep} CSV memuat bagian {$heading}");
        $xlsx = $httpGet($jarAdmin, '/pages/' . $ep . '?' . $qs . '&format=xlsx');
        cr_check($xlsx['code'] === 200 && str_starts_with($xlsx['body'], "PK\x03\x04"), "{$ep} XLSX valid (PK zip)");
        $pdf = $httpGet($jarAdmin, '/pages/' . $ep . '?' . $qs . '&format=pdf');
        cr_check($pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF-'), "{$ep} PDF valid (%PDF-)");
    }
} finally {
    foreach ($tempBookings as [$bt, $bid, $bcid]) {
        try { booking_delete($bt, (int) $bid, (int) $bcid); } catch (Throwable $e) {}
    }
    if ($tempViewerId > 0) {
        $db->query("DELETE FROM user_companies WHERE user_id = {$tempViewerId}");
        $db->query("DELETE FROM users WHERE id = {$tempViewerId}");
    }
    if ($tempPaxIds) {
        $inP = implode(',', array_map('intval', $tempPaxIds));
        $db->query("DELETE FROM passengers WHERE id IN ({$inP})");
    }
    @unlink($jarAdmin);
    @unlink($jarViewer);
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
