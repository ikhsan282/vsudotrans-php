<?php
/**
 * Integration & HTTP verification for the operational upgrade suite:
 *   1. Seeded MTM Multi-Day Rental Bookings
 *   2. Calendar Quick Assign (CSRF, 409 overlap check, save & restore)
 *   3. Penghapusan Manifest Gabungan Armada (berkas & endpoint sudah tidak ada / 404)
 *   4. Calendar Drag & Drop Reschedule (CSRF, done-lock, 409 overlap, save & restore)
 *   5. Travel & Rental index filters (category_id, pay_status, badges, icon-only print)
 *   6. Dashboard KPI drill-downs, Calendar status pills, Perlu Tindakan widget
 *   7. Penghapusan Global Quick Search (topbar trigger/modal & endpoint global.php 404)
 *
 * Usage: php tests/ops_upgrades_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/transport_helpers.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();

$pass = 0;
$fail = 0;
function ou_check(bool $ok, string $label): void
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

$jar = sys_get_temp_dir() . '/ops_upg_admin_' . getmypid() . '.txt';
$jarAnon = sys_get_temp_dir() . '/ops_upg_anon_' . getmypid() . '.txt';
@unlink($jar);
@unlink($jarAnon);

$httpGet = static function (?string $cookieJar, string $path) use ($base): array {
    $hdr = sys_get_temp_dir() . '/ou_ghdr_' . getmypid() . '.txt';
    $cookieArg = $cookieJar !== null
        ? (' -c ' . escapeshellarg($cookieJar) . ' -b ' . escapeshellarg($cookieJar))
        : '';
    $out = (string) shell_exec(
        'curl.exe -s -D ' . escapeshellarg($hdr) . $cookieArg . ' ' . escapeshellarg($base . $path)
    );
    $hdrTxt = is_file($hdr) ? (string) file_get_contents($hdr) : '';
    @unlink($hdr);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrTxt, $m)) {
        $code = (int) $m[1];
    }
    return [
        'code' => $code,
        'body' => $out,
        'json' => json_decode($out, true),
    ];
};

$httpPost = static function (string $cookieJar, string $path, array $fields) use ($base): array {
    $bodyFile = sys_get_temp_dir() . '/ou_pbody_' . getmypid() . '.txt';
    $hdr      = sys_get_temp_dir() . '/ou_phdr_' . getmypid() . '.txt';
    file_put_contents($bodyFile, http_build_query($fields));
    $out = (string) shell_exec(
        'curl.exe -s -D ' . escapeshellarg($hdr)
        . ' -c ' . escapeshellarg($cookieJar) . ' -b ' . escapeshellarg($cookieJar)
        . ' -H "Content-Type: application/x-www-form-urlencoded"'
        . ' --data-binary @' . escapeshellarg($bodyFile)
        . ' ' . escapeshellarg($base . $path)
    );
    $hdrTxt = is_file($hdr) ? (string) file_get_contents($hdr) : '';
    @unlink($bodyFile);
    @unlink($hdr);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrTxt, $m)) {
        $code = (int) $m[1];
    }
    return [
        'code' => $code,
        'body' => $out,
        'json' => json_decode($out, true),
    ];
};

echo "1. Verifikasi data contoh Rental multi-hari...\n";
$seededRows = $db->query(
    "SELECT b.id, b.document_number, b.start_date, b.end_date, b.time, b.vehicle_id, b.category_vehicle_id,
            COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS st
       FROM rental_car_bookings b
      WHERE b.created_user = 'rental_sample_seed'
      ORDER BY b.start_date ASC"
)->fetch_all(MYSQLI_ASSOC);

ou_check(count($seededRows) === 7, '7 booking Rental contoh (rental_sample_seed) tersedia di database');
$byDoc = [];
foreach ($seededRows as $sr) {
    $byDoc[$sr['document_number']] = $sr;
}
ou_check(
    isset($byDoc['115420261128']) && (int) ($byDoc['115420261128']['vehicle_id'] ?? 0) === 0 && $byDoc['115420261128']['st'] === 'draft',
    'Booking sample #5 (115420261128) berstatus draft & belum punya armada'
);
ou_check(
    isset($byDoc['115020261118']) && $byDoc['115020261118']['st'] === 'done',
    'Booking sample #1 (115020261118) berstatus done'
);

echo "2. Verifikasi Login Super Admin & Halaman Kalender...\n";

$loginPage = $httpGet($jar, '/pages/auth/login.php');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $mCsrf);
$csrfLogin = $mCsrf[1] ?? '';
$httpPost($jar, '/pages/auth/login.php', [
    'csrf_token' => $csrfLogin,
    'username'   => 'superadmin',
    'password'   => 'P@ssw0rd',
]);

// Pastikan aktif pada MTM (company_id = 1) dan ambil csrf token aktif dari kalender
$calHtml = $httpGet($jar, '/pages/dispatch/calendar.php?date=2026-11-23');
ou_check($calHtml['code'] === 200 && str_contains($calHtml['body'], 'calModalQuickAssignBox'), 'Kalender Operasional HTTP 200 memuat panel Quick Assign & Modal Reschedule');
preg_match('/const csrfToken = "([^"]+)"/', $calHtml['body'], $mCalCsrf);
$csrf = $mCalCsrf[1] ?? '';
ou_check($csrf !== '', 'Token CSRF aktif terbaca dari halaman Kalender');

if ($csrf !== '' && isset($byDoc['115420261128'], $byDoc['115120261122'])) {
    echo "3. Verifikasi Quick Assign & Conflict 409...\n";
    $badCsrf = $httpPost($jar, '/pages/dispatch/calendar_assign.php', [
        'csrf_token' => 'invalid-token',
        'kind'       => 'rental',
        'booking_id' => $byDoc['115420261128']['id'],
        'vehicle_id' => 0,
        'driver_id'  => 0,
    ]);
    ou_check($badCsrf['code'] === 403, 'calendar_assign menolak token CSRF tidak valid (403)');

    // Bentrok sengaja: 115320261126 (26-29 Nov) memakai kendaraan milik 115120261122 -> test overlap pada 1154 (28-30 Nov)
    $conflictVehId = (int) $byDoc['115320261126']['vehicle_id'];
    if ($conflictVehId > 0) {
        $confRes = $httpPost($jar, '/pages/dispatch/calendar_assign.php', [
            'csrf_token' => $csrf,
            'kind'       => 'rental',
            'booking_id' => $byDoc['115420261128']['id'],
            'vehicle_id' => $conflictVehId,
            'driver_id'  => 0,
        ]);
        ou_check($confRes['code'] === 409 && empty($confRes['json']['ok']), 'calendar_assign menolak armada bentrok Rental pada tanggal tumpang tindih (409)');
    }

    // Assign valid pada 1154 lalu kembalikan ke kondisi semula
    $freeVeh = $db->query("SELECT id FROM vehicles WHERE company_id = 1 AND is_active = 1 AND vehicle_category LIKE '%Medium%' ORDER BY id ASC LIMIT 1")->fetch_assoc();
    $drv = $db->query("SELECT id FROM employees WHERE company_id = 1 AND status = 'aktif' AND LOWER(COALESCE(job_position,'')) IN ('driver','sopir') ORDER BY id ASC LIMIT 1")->fetch_assoc();
    if ($freeVeh && $drv) {
        $okAssign = $httpPost($jar, '/pages/dispatch/calendar_assign.php', [
            'csrf_token' => $csrf,
            'kind'       => 'rental',
            'booking_id' => $byDoc['115420261128']['id'],
            'vehicle_id' => (int) $freeVeh['id'],
            'driver_id'  => (int) $drv['id'],
        ]);
        ou_check($okAssign['code'] === 200 && !empty($okAssign['json']['ok']) && $okAssign['json']['status'] === 'scheduled', 'calendar_assign berhasil menugaskan armada+sopir dan auto-advance draft -> scheduled');

        // Restore 1154 ke state seed awal (unassigned draft) + hapus histori uji
        $id1154 = (int) $byDoc['115420261128']['id'];
        $db->query("UPDATE rental_car_bookings SET vehicle_id = NULL WHERE id = {$id1154}");
        $db->query("DELETE FROM trip_assignments WHERE booking_type = 'rental' AND booking_id = {$id1154}");
        $db->query("DELETE FROM booking_status_history WHERE booking_type = 'rental' AND booking_id = {$id1154} AND to_status <> 'draft'");
    }

    echo "4. Verifikasi Drag & Drop Reschedule Endpoint...\n";
    $doneRes = $httpPost($jar, '/pages/dispatch/calendar_reschedule.php', [
        'csrf_token' => $csrf,
        'kind'       => 'rental',
        'booking_id' => $byDoc['115020261118']['id'],
        'start_date' => '2026-11-19',
        'end_date'   => '2026-11-21',
    ]);
    ou_check($doneRes['code'] === 422, 'calendar_reschedule menolak menggeser booking berstatus done (422)');

    // Move 115620261210 dari 2026-12-10..12 ke 2026-12-14..16 lalu kembalikan lagi
    $id1156 = (int) $byDoc['115620261210']['id'];
    $origStart = (string) $byDoc['115620261210']['start_date'];
    $origEnd   = (string) $byDoc['115620261210']['end_date'];
    $origTime  = substr((string) $byDoc['115620261210']['time'], 0, 5);
    $okMove = $httpPost($jar, '/pages/dispatch/calendar_reschedule.php', [
        'csrf_token' => $csrf,
        'kind'       => 'rental',
        'booking_id' => $id1156,
        'start_date' => '2026-12-14',
        'end_date'   => '2026-12-16',
        'time'       => '08:30',
        'reason'     => 'Uji Reschedule Otomatis',
    ]);
    ou_check($okMove['code'] === 200 && !empty($okMove['json']['ok']) && $okMove['json']['starts_on'] === '2026-12-14', 'calendar_reschedule berhasil memindahkan rentang tanggal Rental');
    // Kembalikan jadwal semula
    $httpPost($jar, '/pages/dispatch/calendar_reschedule.php', [
        'csrf_token' => $csrf,
        'kind'       => 'rental',
        'booking_id' => $id1156,
        'start_date' => $origStart,
        'end_date'   => $origEnd,
        'time'       => $origTime,
        'reason'     => 'Restore jadwal uji',
    ]);
}

echo "5. Verifikasi Penghapusan Manifest Gabungan Armada...\n";
ou_check(!is_file(__DIR__ . '/../pages/dispatch/manifest_group.php'), 'Berkas pages/dispatch/manifest_group.php sudah dihapus');
$mgRemoved = $httpGet($jar, '/pages/dispatch/manifest_group.php?date=2026-11-23');
ou_check($mgRemoved['code'] === 404, 'Endpoint manifest_group.php mengembalikan HTTP 404');

echo "6. Verifikasi Filter Kategori & Pembayaran pada Daftar Travel & Rental...\n";
$trIdx = $httpGet($jar, '/pages/travel/bookings/index.php?pay_status=paid');
ou_check(
    $trIdx['code'] === 200
    && str_contains($trIdx['body'], 'name="category_id"')
    && str_contains($trIdx['body'], 'name="pay_status"')
    && str_contains($trIdx['body'], 'title="Cetak Surat Jalan"'),
    'Daftar Pemesanan Travel menampilkan filter Kategori Layanan, Pembayaran, dan tombol Cetak Surat Jalan'
);
$rnIdx = $httpGet($jar, '/pages/rental/bookings/index.php?pay_status=partial');
ou_check(
    $rnIdx['code'] === 200
    && str_contains($rnIdx['body'], 'name="category_id"')
    && str_contains($rnIdx['body'], 'name="pay_status"')
    && str_contains($rnIdx['body'], '115320261126')
    && str_contains($rnIdx['body'], 'DP / Sebagian'),
    'Daftar Pemesanan Rental menyaring pay_status=partial dan menampilkan badge pembayaran & kategori'
);

echo "7. Verifikasi Dashboard Action Hub & Penghapusan Global Quick Search...\n";
$dash = $httpGet($jar, '/pages/dashboard/index.php');
ou_check(
    $dash['code'] === 200
    && str_contains($dash['body'], 'dash-kpi-card')
    && str_contains($dash['body'], 'Perlu Tindakan Operasional')
    && str_contains($dash['body'], '115420261128')
    && str_contains($dash['body'], 'calendar.php?view=listMonth&amp;status=scheduled')
    && !str_contains($dash['body'], 'globalSearchTrigger')
    && !str_contains($dash['body'], 'globalSearchModal'),
    'Dashboard memuat KPI drill-down, pill ke Kalender, widget Perlu Tindakan, tanpa Global Quick Search trigger/modal'
);

ou_check(!is_file(__DIR__ . '/../pages/search/global.php'), 'Berkas pages/search/global.php sudah dihapus');
$gsRemoved = $httpGet($jar, '/pages/search/global.php?q=115120261122');
ou_check($gsRemoved['code'] === 404, 'Endpoint pages/search/global.php mengembalikan HTTP 404');

@unlink($jar);
@unlink($jarAnon);

echo "\nRingkasan Ops Upgrades Check: {$pass} lulus, {$fail} gagal.\n";
exit($fail === 0 ? 0 : 1);
