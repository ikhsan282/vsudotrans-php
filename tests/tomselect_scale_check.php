<?php
/**
 * Self-test Tom Select v2.3.1 (Kendaraan, Pelanggan, Provinsi/Kota/Kecamatan)
 * dan proteksi skala jutaan data (LIMIT preload, endpoint remote search, DB indexes, EXPLAIN).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/travel/_helpers.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/invoice_service.php';
require_once __DIR__ . '/../includes/dispatch_service.php';
require_once __DIR__ . '/../pages/attendance/_helpers.php';

$pass = 0;
$fail = 0;
function ts_check(bool $cond, string $label): void
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

echo "=== Test Tom Select v2.3.1 & Proteksi Jutaan Data ===\n";

$db = db();

// 1. Cek seluruh composite/search index terpasang di database
$expectedIndexes = [
    ['passengers', 'ix_passengers_company_name'],
    ['vehicles', 'ix_vehicles_comp_active_num'],
    ['vehicles', 'ix_vehicles_comp_active_cat_num'],
    ['provinces', 'ix_provinces_country_name'],
    ['provinces', 'ix_provinces_name'],
    ['cities', 'ix_cities_prov_name'],
    ['cities', 'ix_cities_name'],
    ['districts', 'ix_districts_city_name'],
    ['districts', 'ix_districts_name'],
    ['villages', 'ix_villages_district_name'],
    ['villages', 'ix_villages_name'],
    ['rutes', 'ix_rutes_lookup'],
    ['booking_passengers', 'ix_bp_booking'],
    ['employees', 'ix_employees_company_status_name'],
    ['employees', 'ix_employees_company_pos_status_name'],
];
foreach ($expectedIndexes as [$tbl, $idxName]) {
    $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->bind_param('ss', $tbl, $idxName);
    $st->execute();
    $okIdx = $st->get_result()->num_rows > 0;
    $st->close();
    ts_check($okIdx, "DB Index {$tbl}.{$idxName} exists");
}

// 2. Seed sementara 120 passengers & 70 vehicles dulu, lalu uji EXPLAIN + LIMIT cap
$createdPaxIds = [];
$createdVehIds = [];
$createdRuteIds = [];
$createdEmpIds = [];
$createdOrders = [];
$tempBookingId = 0;
$tempTravelId = 0;
$tempTravelInvId = 0;
$tempViewerUserId = 0;
$jar = tempnam(sys_get_temp_dir(), 'ts_jar_');
$viewerJar = tempnam(sys_get_temp_dir(), 'ts_vjar_');

try {
    $brandId = (int) $db->query('SELECT id FROM brand_vehicles LIMIT 1')->fetch_column();
    $modelId = (int) $db->query('SELECT id FROM model_units LIMIT 1')->fetch_column();
    $engId   = (int) $db->query('SELECT id FROM type_engines LIMIT 1')->fetch_column();
    $catId   = (int) $db->query('SELECT id FROM category_vehicles WHERE company_id = 1 AND is_active = 1 LIMIT 1')->fetch_column();
    $branchId = (int) $db->query('SELECT id FROM branches WHERE company_id = 1 LIMIT 1')->fetch_column();

    for ($i = 1; $i <= 120; $i++) {
        $code = sprintf('PT-%04d-%d', $i, random_int(10, 99));
        $name = sprintf('ZZZScale Test Passenger %03d', $i);
        $phone = sprintf('0899%07d', $i);
        $st = $db->prepare('INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, ?, ?, ?)');
        $st->bind_param('sss', $code, $name, $phone);
        $st->execute();
        $createdPaxIds[] = (int) $db->insert_id;
        $st->close();
    }

    // EXPLAIN pada tabel besar (83k villages) + verifikasi index passengers bebas filesort
    $sampleDistId = (int) $db->query('SELECT district_id FROM villages LIMIT 1')->fetch_column();
    $expVill = $db->query("EXPLAIN SELECT id, name FROM villages WHERE district_id = {$sampleDistId} ORDER BY name ASC LIMIT 50")->fetch_assoc();
    ts_check(($expVill['key'] ?? '') === 'ix_villages_district_name' && !str_contains((string) ($expVill['Extra'] ?? ''), 'Using filesort'), 'EXPLAIN 83k-row villages ORDER BY name LIMIT 50 uses covering ix_villages_district_name without filesort');

    $expForce = $db->query("EXPLAIN SELECT id, code, name, phone_number FROM passengers FORCE INDEX (ix_passengers_company_name) WHERE company_id = 1 ORDER BY name ASC LIMIT 50")->fetch_assoc();
    ts_check(($expForce['key'] ?? '') === 'ix_passengers_company_name' && !str_contains((string) ($expForce['Extra'] ?? ''), 'Using filesort'), 'EXPLAIN passengers ix_passengers_company_name satisfies WHERE company_id ORDER BY name without filesort');

    $expPref = $db->query("EXPLAIN SELECT id, code, name, phone_number FROM passengers WHERE company_id = 1 AND name LIKE 'ZZZScale%' ORDER BY name ASC LIMIT 30")->fetch_assoc();
    $expPrefExtra = (string) ($expPref['Extra'] ?? '');
    ts_check(($expPref['key'] ?? '') === 'ix_passengers_company_name' && !str_contains($expPrefExtra, 'Using filesort'), "EXPLAIN passengers prefix LIKE 'ZZZScale%' uses ix_passengers_company_name without filesort (key=" . ($expPref['key'] ?? '') . ")");

    for ($i = 1; $i <= 70; $i++) {
        $plate = sprintf('ZZ %04d SC', $i);
        $frame = sprintf('FRMZZSC%05d%d', $i, random_int(10, 99));
        $mach  = sprintf('MCHZZSC%05d%d', $i, random_int(10, 99));
        $st = $db->prepare("INSERT INTO vehicles (company_id, brand_id, model_unit_id, type_engine_id, category_vehicle_id, frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, ownership, is_active) VALUES (1, ?, ?, ?, ?, ?, ?, 7, 2024, 'Hitam', ?, 'Milik Sendiri', 1)");
        $st->bind_param('iiiisss', $brandId, $modelId, $engId, $catId, $frame, $mach, $plate);
        $st->execute();
        $createdVehIds[] = (int) $db->insert_id;
        $st->close();
    }

    $lastPaxId = end($createdPaxIds);
    $lastVehId = end($createdVehIds);

    $resPax = opts_passengers(1, [$lastPaxId], 50);
    ts_check(count($resPax) <= 51, 'opts_passengers caps initial rows <= 51 even with 120+ rows (got ' . count($resPax) . ')');
    ts_check(in_array($lastPaxId, array_map('intval', array_column($resPax, 'id')), true), 'opts_passengers preserves selected ID outside first LIMIT 50');

    $resVeh = opts_vehicles_detailed(1, [1], $lastVehId, null, 50);
    ts_check(count($resVeh) <= 51, 'opts_vehicles_detailed caps initial rows <= 51 even with 70+ rows (got ' . count($resVeh) . ')');
    ts_check(in_array($lastVehId, array_map('intval', array_column($resVeh, 'id')), true), 'opts_vehicles_detailed preserves selected ID outside first LIMIT 50');

    $bandungCityId = (int) $db->query("SELECT id FROM cities WHERE name = 'Kota Bandung' LIMIT 1")->fetch_column();
    $resCities = opts_cities(null, [$bandungCityId], 50);
    ts_check(count($resCities) <= 51, 'opts_cities(null) caps initial rows <= 51 instead of loading all cities (got ' . count($resCities) . ')');
    ts_check($bandungCityId > 0 && in_array($bandungCityId, array_map('intval', array_column($resCities, 'id')), true), "opts_cities preserves selected city ID #{$bandungCityId}");

    // Buat 1 booking rental sementara untuk uji detail.php
    $distRow = $db->query("SELECT id, city_id, province_id FROM districts LIMIT 1")->fetch_assoc();
    $bk = booking_create('rental', [
        'company_id'                 => 1,
        'branch_id'                  => $branchId,
        'date'                       => date('Y-m-d'),
        'time'                       => '09:00:00',
        'start_date'                 => date('Y-m-d'),
        'end_date'                   => date('Y-m-d'),
        'trip'                       => 1,
        'pickup_point_province_id'   => (int) $distRow['province_id'],
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_point_province_id' => (int) $distRow['province_id'],
        'delivery_point_city_id'     => (int) $distRow['city_id'],
        'delivery_point_district_id' => (int) $distRow['id'],
        'total_price'                => 500000,
        'category_vehicle_id'        => $catId,
        'vehicle_id'                 => $lastVehId,
        'passenger_id'               => $lastPaxId,
        'note'                       => 'Test TomSelect Scale',
        'created_user'               => 'test',
    ]);
    $tempBookingId = (int) $bk['id'];

    // 4. Uji HTTP Login + Rendered HTML & AJAX Remote Search Endpoints
    $base = 'http://127.0.0.1:8080/vsudotrans-php';
    $loginOut = (string) shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(__DIR__ . '/_wa_login.php') . ' ' . escapeshellarg($base) . ' ' . escapeshellarg($jar));
    ts_check(str_contains($loginOut, 'LOGIN_OK'), 'HTTP admin login succeeded');

    $httpGet = static function (string $path) use ($base, $jar): array {
        $hdrFile = tempnam(sys_get_temp_dir(), 'ts_hdr_');
        $body = (string) shell_exec('curl.exe -s -b ' . escapeshellarg($jar) . ' -D ' . escapeshellarg($hdrFile) . ' ' . escapeshellarg($base . $path));
        $hdrs = (string) @file_get_contents($hdrFile);
        @unlink($hdrFile);
        $code = 0;
        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrs, $m)) {
            $code = (int) $m[1];
        }
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    };

    // Check Rental Form HTML
    $rf = $httpGet('/pages/rental/bookings/form.php');
    ts_check($rf['code'] === 200, 'rental/bookings/form.php returns HTTP 200');
    foreach (['rental_category_select', 'rental_vehicle_select', 'select_passenger_id', 'extraPassengersSelect', 'rental_pickup_province', 'rental_pickup_city', 'rental_pickup_district', 'rental_delivery_province', 'rental_delivery_city', 'rental_delivery_district'] as $selId) {
        ts_check((bool) preg_match('/<select[^>]*class="[^"]*ts-select[^"]*"[^>]*id="' . preg_quote($selId, '/') . '"|<select[^>]*id="' . preg_quote($selId, '/') . '"[^>]*class="[^"]*ts-select[^"]*"/', $rf['body']), "rental/form.php #{$selId} uses class ts-select");
    }
    ts_check(!str_contains($rf['body'], 'var allVehicles ='), 'rental/form.php no longer embeds giant allVehicles JSON dump');

    // Check Rental Detail HTML
    $rd = $httpGet('/pages/rental/bookings/detail.php?id=' . $tempBookingId);
    ts_check($rd['code'] === 200, 'rental/bookings/detail.php returns HTTP 200');
    foreach (['categoryVehicleSelect', 'vehicleSelect', 'primaryPassengerSelect', 'extraPassengersSelect', 'pickupProvince', 'pickupCity', 'pickupDistrict', 'deliveryProvince', 'deliveryCity', 'deliveryDistrict'] as $selId) {
        ts_check((bool) preg_match('/<select[^>]*class="[^"]*ts-select[^"]*"[^>]*id="' . preg_quote($selId, '/') . '"|<select[^>]*id="' . preg_quote($selId, '/') . '"[^>]*class="[^"]*ts-select[^"]*"/', $rd['body']), "rental/detail.php #{$selId} uses class ts-select");
    }
    ts_check(!str_contains($rd['body'], 'const allVehicles =') && !str_contains($rd['body'], 'const allCities ='), 'rental/detail.php no longer embeds full allVehicles/allCities JSON dumps');
    ts_check(str_contains($rd['body'], 'ZZZScale Test Passenger 120'), 'rental/detail.php still pre-renders selected passenger #120 outside LIMIT 50');

    // Check Travel Form HTML
    $tf = $httpGet('/pages/travel/bookings/form.php');
    ts_check($tf['code'] === 200, 'travel/bookings/form.php returns HTTP 200');
    foreach (['travel_category_select', 'travel_vehicle_select', 'select_passenger_id', 'select_passenger_ids', 'travel_pickup_province', 'pickup_city', 'pickup_district', 'travel_delivery_province', 'delivery_city', 'delivery_district'] as $selId) {
        ts_check((bool) preg_match('/<select[^>]*class="[^"]*ts-select[^"]*"[^>]*id="' . preg_quote($selId, '/') . '"|<select[^>]*id="' . preg_quote($selId, '/') . '"[^>]*class="[^"]*ts-select[^"]*"/', $tf['body']), "travel/form.php #{$selId} uses class ts-select");
    }
    ts_check(!str_contains($tf['body'], 'var allVehicles =') && !str_contains($tf['body'], 'var allCitiesData ='), 'travel/form.php no longer embeds full allVehicles/allCitiesData JSON dumps');

    // Check Remote Endpoints
    $pAjax = $httpGet('/pages/travel/passengers/options.php?q=ZZZScale&limit=30');
    ts_check($pAjax['code'] === 200 && is_array($pAjax['json']) && count($pAjax['json']) === 30, 'passengers/options.php?q=ZZZScale caps at LIMIT 30 with HTTP 200');
    ts_check(!empty($pAjax['json'][0]['code']) && !empty($pAjax['json'][0]['phone']), 'passengers/options.php returns code & phone metadata');

    $vAjax = $httpGet('/pages/vehicles/options.php?q=' . urlencode('ZZ 0070') . '&limit=30');
    ts_check($vAjax['code'] === 200 && is_array($vAjax['json']) && count($vAjax['json']) >= 1, 'vehicles/options.php remote search finds ZZ 0070 SC');
    ts_check(!empty($vAjax['json'][0]['plate']) && array_key_exists('category_name', $vAjax['json'][0]), 'vehicles/options.php returns preview metadata (plate, category, brand, model, seat)');

    $provAjax = $httpGet('/pages/masters/provinces/options.php?q=Jawa&with_code=1&limit=20');
    ts_check($provAjax['code'] === 200 && is_array($provAjax['json']) && count($provAjax['json']) >= 3, 'provinces/options.php?q=Jawa&with_code=1 returns Jawa provinces');
    ts_check(str_contains((string) ($provAjax['json'][0]['text'] ?? ''), ' - '), 'provinces/options.php with_code=1 formats code - name');

    $cityGlobal = $httpGet('/pages/masters/cities/options.php?q=Bandung&limit=20');
    ts_check($cityGlobal['code'] === 200 && is_array($cityGlobal['json']) && count($cityGlobal['json']) >= 1 && !empty($cityGlobal['json'][0]['province_id']), 'cities/options.php global search works without province_id and includes province_id');

    $cityProv = $httpGet('/pages/masters/cities/options.php?province_id=32&limit=50');
    ts_check($cityProv['code'] === 200 && is_array($cityProv['json']) && count($cityProv['json']) >= 10, 'cities/options.php cascading ?province_id=32 works');

    $distAjax = $httpGet('/pages/masters/districts/options.php?city_id=' . (int) ($cityGlobal['json'][0]['id'] ?? 3204) . '&limit=50');
    ts_check($distAjax['code'] === 200 && is_array($distAjax['json']) && count($distAjax['json']) >= 1 && !empty($distAjax['json'][0]['city_id']), 'districts/options.php returns districts with city_id metadata');

    // Check delegated multi-select remove_button protection & detail.php qrisRemainingDisp init order
    $appJsSrc = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
    ts_check(
        str_contains($appJsSrc, "ts.control.addEventListener('click'") && str_contains($appJsSrc, "e.target.closest('.remove')") && str_contains($appJsSrc, 'ts.removeItem(itemEl)'),
        'assets/js/app.js registers delegated capture-phase click handler for multi-select .remove buttons'
    );
    $detailSrc = (string) file_get_contents(__DIR__ . '/../pages/rental/bookings/detail.php');
    $qrisDeclPos = strpos($detailSrc, 'const qrisRemainingDisp');
    $recalcCallPos = preg_match('/^\s+recalcPayTotals\(\);\r?$/m', $detailSrc, $mRecalc, PREG_OFFSET_CAPTURE) ? $mRecalc[0][1] : false;
    ts_check(
        $qrisDeclPos !== false && $recalcCallPos !== false && $qrisDeclPos < $recalcCallPos,
        'rental/detail.php declares qrisRemainingDisp before initial recalcPayTotals() call'
    );

    // Check HTTP POST persistence of Penumpang Tambahan: 3 -> 2 -> 0
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $rd['body'], $csrfM)) {
        $csrfTok = $csrfM[1];
        $extra3 = array_slice($createdPaxIds, 0, 3);
        $extra2 = [$extra3[0], $extra3[2]];
        $httpPostDetail = static function (array $extraIds) use ($base, $jar, $tempBookingId, $branchId, $distRow, $catId, $lastVehId, $lastPaxId, $csrfTok): void {
            $postFields = [
                'csrf_token'                 => $csrfTok,
                'id'                         => (string) $tempBookingId,
                'act'                        => 'update_detail',
                'branch_id'                  => (string) $branchId,
                'start_date'                 => date('Y-m-d'),
                'end_date'                   => date('Y-m-d'),
                'time'                       => '09:00',
                'passenger_id'               => (string) $lastPaxId,
                'category_vehicle_id'        => (string) $catId,
                'vehicle_id'                 => (string) $lastVehId,
                'total_price'                => '500000',
                'trip'                       => '1',
                'bbm'                        => '0',
                'pickup_point_province_id'   => (string) $distRow['province_id'],
                'pickup_point_city_id'       => (string) $distRow['city_id'],
                'pickup_point_district_id'   => (string) $distRow['id'],
                'delivery_point_province_id' => (string) $distRow['province_id'],
                'delivery_point_city_id'     => (string) $distRow['city_id'],
                'delivery_point_district_id' => (string) $distRow['id'],
            ];
            $parts = [http_build_query($postFields)];
            foreach ($extraIds as $eid) {
                $parts[] = 'passenger_ids%5B%5D=' . rawurlencode((string) $eid);
            }
            $tmpBody = tempnam(sys_get_temp_dir(), 'ts_post_');
            file_put_contents($tmpBody, implode('&', $parts));
            shell_exec('curl.exe -s -L -b ' . escapeshellarg($jar) . ' --data-binary ' . escapeshellarg('@' . $tmpBody) . ' ' . escapeshellarg($base . '/pages/rental/bookings/detail.php?id=' . $tempBookingId));
            @unlink($tmpBody);
        };

        $httpPostDetail($extra3);
        $bp3 = booking_passengers_list('rental', $tempBookingId, $lastPaxId);
        ts_check(count($bp3) === 4, 'HTTP update saves 1 primary + 3 extra passengers (got ' . count($bp3) . ')');

        $httpPostDetail($extra2);
        $bp2 = booking_passengers_list('rental', $tempBookingId, $lastPaxId);
        $bp2Ids = array_map(static fn($r) => (int) $r['passenger_id'], $bp2);
        ts_check(count($bp2) === 3 && !in_array((int) $extra3[1], $bp2Ids, true), 'HTTP update removes 1 of 3 extra passengers -> 2 extra remain');

        $httpPostDetail([]);
        $bp0 = booking_passengers_list('rental', $tempBookingId, $lastPaxId);
        ts_check(count($bp0) === 1 && (int) $bp0[0]['passenger_id'] === (int) $lastPaxId, 'HTTP update clears all extra passengers -> only primary remains');
    } else {
        ts_check(false, 'CSRF token found in rental/detail.php for HTTP passenger update check');
    }

    // 5. Cache-busting (?v=filemtime) pada style.css & app.js
    ts_check((bool) preg_match('/assets\/css\/style\.css\?v=\d+/', $tf['body']), 'includes/header.php appends ?v=<mtime> cache-buster on style.css');
    ts_check((bool) preg_match('/assets\/js\/app\.js\?v=\d+/', $tf['body']), 'includes/footer.php appends ?v=<mtime> cache-buster on app.js');

    // 6. Rute form: kota remote search + LIMIT 50
    $ruteForm = $httpGet('/pages/travel/rutes/form.php');
    ts_check($ruteForm['code'] === 200, 'travel/rutes/form.php returns HTTP 200');
    ts_check(substr_count($ruteForm['body'], 'pages/masters/cities/options.php') >= 2, 'travel/rutes/form.php attaches cities/options.php remote search to pickup & delivery cities');
    if (preg_match('/<select[^>]*name="pickup_point_city_id"[^>]*>([\s\S]*?)<\/select>/', $ruteForm['body'], $mRuteSel)) {
        $optCnt = substr_count($mRuteSel[1], '<option');
        ts_check($optCnt <= 52, "travel/rutes/form.php caps pickup_point_city_id initial options <= 52 (got {$optCnt})");
    } else {
        ts_check(false, 'travel/rutes/form.php renders pickup_point_city_id select');
    }

    // 7. Dispatch assign: booking dropdown menggunakan Tom Select
    $dispForm = $httpGet('/pages/dispatch/assign.php');
    ts_check($dispForm['code'] === 200, 'dispatch/assign.php returns HTTP 200');
    ts_check((bool) preg_match('/<select[^>]*class="[^"]*ts-select[^"]*"[^>]*name="booking"/', $dispForm['body']), 'dispatch/assign.php booking dropdown uses ts-select');

    // 8. Masters engine (villages/form.php): Tom Select bertingkat & tidak memuat 7.200+ district sekaligus
    $vilForm = $httpGet('/pages/masters/villages/form.php');
    ts_check($vilForm['code'] === 200, 'masters/villages/form.php returns HTTP 200');
    ts_check((bool) preg_match('/<select[^>]*class="[^"]*ts-select[^"]*"[^>]*id="district_id"/', $vilForm['body']), 'masters/villages/form.php #district_id uses ts-select + remote search');
    if (preg_match('/<select[^>]*id="district_id"[^>]*>([\s\S]*?)<\/select>/', $vilForm['body'], $mDistSel)) {
        $distOptCnt = substr_count($mDistSel[1], '<option');
        ts_check($distOptCnt <= 52, "masters/villages/form.php caps initial district options <= 52 instead of 7.200+ (got {$distOptCnt})");
    } else {
        ts_check(false, 'masters/villages/form.php renders #district_id select');
    }
    ts_check(str_contains($vilForm['body'], 'window.setTsOptions') && str_contains($vilForm['body'], 'window.bindTsChange'), 'masters/_engine.php cascading script uses window.bindTsChange + window.setTsOptions');
    ts_check(str_contains($vilForm['body'], 'UPWARD_PARENTS') && str_contains($vilForm['body'], 'syncParentsUpward'), 'masters/villages/form.php includes upward geo parent sync script');

    // 8b. Poin 4 Geo Upward Metadata & EXPLAIN bebas filesort
    ts_check(
        !empty($cityGlobal['json'][0]['country_id']) && !empty($cityGlobal['json'][0]['province_name']) && !empty($cityGlobal['json'][0]['country_name']),
        'cities/options.php returns full upward hierarchy (province_id/name + country_id/name)'
    );
    $distGlobal = $httpGet('/pages/masters/districts/options.php?q=Coblong&limit=20');
    ts_check(
        $distGlobal['code'] === 200 && is_array($distGlobal['json']) && count($distGlobal['json']) >= 1
        && !empty($distGlobal['json'][0]['city_id']) && !empty($distGlobal['json'][0]['province_id']) && !empty($distGlobal['json'][0]['country_id']),
        'districts/options.php global search returns full upward hierarchy (city + province + country)'
    );
    $expCitiesJoin = $db->query("EXPLAIN SELECT t.id, t.name, t.province_id, p.name AS province_name, p.country_id, cn.name AS country_name FROM cities t LEFT JOIN provinces p ON p.id = t.province_id LEFT JOIN countries cn ON cn.id = p.country_id WHERE t.name LIKE 'Bandung%' ORDER BY t.name ASC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
    ts_check(
        ($expCitiesJoin[0]['key'] ?? '') === 'ix_cities_name' && !str_contains((string) ($expCitiesJoin[0]['Extra'] ?? ''), 'Using filesort'),
        'EXPLAIN cities upward JOIN query uses ix_cities_name without filesort'
    );
    $expDistJoin = $db->query("EXPLAIN SELECT t.id, t.name, t.city_id, ct.name AS city_name, t.province_id, p.name AS province_name, p.country_id, cn.name AS country_name FROM districts t LEFT JOIN cities ct ON ct.id = t.city_id LEFT JOIN provinces p ON p.id = t.province_id LEFT JOIN countries cn ON cn.id = p.country_id WHERE t.name LIKE 'Coblong%' ORDER BY t.name ASC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
    ts_check(
        ($expDistJoin[0]['key'] ?? '') === 'ix_districts_name' && !str_contains((string) ($expDistJoin[0]['Extra'] ?? ''), 'Using filesort'),
        'EXPLAIN districts upward JOIN query uses ix_districts_name without filesort'
    );

    // 8c. Poin 2 & Poin 3: Shared 2-way guard, capacity warning, CSS consolidation & Route lookup endpoint
    ts_check(
        str_contains($appJsSrc, 'syncTsLastVal') && str_contains($appJsSrc, 'bindExclusivePrimaryExtra') && str_contains($appJsSrc, 'updateSeatCapacityWarning'),
        'assets/js/app.js defines syncTsLastVal, bindExclusivePrimaryExtra & updateSeatCapacityWarning'
    );
    ts_check(str_contains($rf['body'], 'window.bindExclusivePrimaryExtra') && str_contains($rf['body'], 'id="seatCapacityWarning"') && str_contains($rf['body'], 'data-seat="'), 'rental/form.php uses shared 2-way guard, #seatCapacityWarning & data-seat');
    ts_check(str_contains($rd['body'], 'window.bindExclusivePrimaryExtra') && str_contains($rd['body'], 'id="seatCapacityWarning"'), 'rental/detail.php uses shared 2-way guard & #seatCapacityWarning');
    ts_check(str_contains($tf['body'], 'window.bindExclusivePrimaryExtra') && str_contains($tf['body'], 'id="seatCapacityWarning"') && str_contains($tf['body'], 'data-seat="'), 'travel/form.php uses shared 2-way guard, #seatCapacityWarning & data-seat');
    ts_check(str_contains($tf['body'], 'pages/travel/rutes/lookup.php') && !str_contains($tf['body'], 'var activeRoutes ='), 'travel/form.php uses rutes/lookup.php instead of inline LIMIT 500 route JSON dump');

    $styleCssSrc = (string) file_get_contents(__DIR__ . '/../assets/css/style.css');
    ts_check(str_contains($styleCssSrc, '.rnt-tabs') && str_contains($styleCssSrc, '@media (max-width: 767.98px)'), 'assets/css/style.css consolidates .rnt-tabs & mobile .rnt-* rules');
    foreach ([
        'pages/rental/bookings/form.php',
        'pages/rental/bookings/detail.php',
        'pages/rental/regulations/form.php',
        'pages/travel/bookings/form.php',
        'pages/travel/bookings/detail.php',
    ] as $cssCheckFile) {
        $src = (string) file_get_contents(__DIR__ . '/../' . $cssCheckFile);
        ts_check(!str_contains($src, '.rnt-panel-header'), "{$cssCheckFile} has no duplicate inline .rnt-panel* CSS");
    }

    // Seed 2 temporary routes on unused high city IDs & test EXPLAIN ix_rutes_lookup + HTTP rutes/lookup.php
    $cityPair = $db->query('SELECT id FROM cities ORDER BY id DESC LIMIT 2')->fetch_all(MYSQLI_ASSOC);
    $rCityA = (int) ($cityPair[0]['id'] ?? 1101);
    $rCityB = (int) ($cityPair[1]['id'] ?? 1102);
    $otherCatId = (int) $db->query("SELECT id FROM category_vehicles WHERE id <> {$catId} LIMIT 1")->fetch_column();
    if ($otherCatId <= 0) {
        $otherCatId = $catId;
    }
    $rCode1 = 'RT-' . random_int(1000, 9999);
    $rCode2 = 'RT-' . random_int(1000, 9999);
    $stRt = $db->prepare('INSERT INTO rutes (company_id, code, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, min_price, price, max_price, is_active, created_user) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $rtName1 = 'Test Lookup Route Fallback';
    $p1Min = 200000.0; $p1Val = 275000.0; $p1Max = 350000.0; $uTest = 'test';
    $stRt->bind_param('ssiiiddds', $rCode1, $rtName1, $rCityA, $rCityB, $otherCatId, $p1Min, $p1Val, $p1Max, $uTest);
    $stRt->execute();
    $createdRuteIds[] = (int) $db->insert_id;
    $rtName2 = 'Test Lookup Route Exact Cat';
    $p2Min = 300000.0; $p2Val = 425000.0; $p2Max = 500000.0;
    $stRt->bind_param('ssiiiddds', $rCode2, $rtName2, $rCityA, $rCityB, $catId, $p2Min, $p2Val, $p2Max, $uTest);
    $stRt->execute();
    $createdRuteIds[] = (int) $db->insert_id;
    $stRt->close();

    $expRute = $db->query("EXPLAIN SELECT id, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, price FROM rutes FORCE INDEX (ix_rutes_lookup) WHERE company_id = 1 AND pickup_point_city_id = {$rCityA} AND delivery_point_city_id = {$rCityB} AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc();
    ts_check(($expRute['key'] ?? '') === 'ix_rutes_lookup' && !str_contains((string) ($expRute['Extra'] ?? ''), 'Using filesort'), 'EXPLAIN rutes lookup uses ix_rutes_lookup without filesort');

    $lkExact = $httpGet("/pages/travel/rutes/lookup.php?company_id=1&pickup_point_city_id={$rCityA}&delivery_point_city_id={$rCityB}&category_vehicle_id={$catId}");
    ts_check($lkExact['code'] === 200 && !empty($lkExact['json']['ok']) && (int) round((float) ($lkExact['json']['route']['price'] ?? 0)) === 425000, 'rutes/lookup.php returns exact category-matched route price 425000');

    $lkFallback = $httpGet("/pages/travel/rutes/lookup.php?company_id=1&pickup_point_city_id={$rCityA}&delivery_point_city_id={$rCityB}&category_vehicle_id=999999");
    ts_check($lkFallback['code'] === 200 && !empty($lkFallback['json']['ok']) && (int) round((float) ($lkFallback['json']['route']['price'] ?? 0)) === 275000, 'rutes/lookup.php falls back cleanly to first active route price 275000 when category unmatched');

    $lkMiss = $httpGet("/pages/travel/rutes/lookup.php?company_id=1&pickup_point_city_id={$rCityB}&delivery_point_city_id=999999");
    ts_check($lkMiss['code'] === 200 && !empty($lkMiss['json']['ok']) && array_key_exists('route', $lkMiss['json']) && $lkMiss['json']['route'] === null, 'rutes/lookup.php returns null cleanly when no active route matches');

    // 9. Travel Detail ERP UI + Multi-Penumpang + Payment Manual Overpay/Valid + QRIS travel + Index Multi-Pax Search
    $extraTravelPax = [$createdPaxIds[0], $createdPaxIds[1]];
    $tbk = booking_create('travel', [
        'company_id'                 => 1,
        'branch_id'                  => $branchId,
        'date'                       => date('Y-m-d'),
        'time'                       => '10:30:00',
        'pickup_point_city_id'       => (int) $distRow['city_id'],
        'pickup_point_district_id'   => (int) $distRow['id'],
        'delivery_point_city_id'     => (int) $distRow['city_id'],
        'delivery_point_district_id' => (int) $distRow['id'],
        'total_price'                => 350000,
        'category_vehicle_id'        => $catId,
        'vehicle_id'                 => $lastVehId,
        'passenger_id'               => $lastPaxId,
        'passenger_ids'              => array_merge([$lastPaxId], $extraTravelPax),
        'note'                       => 'Catatan travel multi-penumpang',
        'created_user'               => 'test',
    ]);
    $tempTravelId = (int) $tbk['id'];
    $tempTravelInvId = (int) ($tbk['invoice_id'] ?? 0);
    $td = $httpGet('/pages/travel/bookings/detail.php?id=' . $tempTravelId);
    ts_check($td['code'] === 200, 'travel/bookings/detail.php returns HTTP 200');
    ts_check(str_contains($td['body'], 'erp-window-card') && str_contains($td['body'], 'erp-sub-tabs'), 'travel/bookings/detail.php uses ERP window card & sub-tabs UI');
    foreach (['#tab-detail', '#tab-ops', '#tab-cost', '#tab-note', '#tab-audit'] as $tabHref) {
        ts_check(str_contains($td['body'], $tabHref), "travel/bookings/detail.php renders tab {$tabHref}");
    }
    ts_check(str_contains($td['body'], '3 Penumpang') && str_contains($td['body'], 'ZZZScale Test Passenger 120') && str_contains($td['body'], 'ZZZScale Test Passenger 001') && str_contains($td['body'], 'ZZZScale Test Passenger 002'), 'travel/bookings/detail.php displays primary + 2 extra passengers + count badge');
    ts_check(str_contains($td['body'], 'id="travelManualPayForm"') && str_contains($td['body'], 'id="btnPayQrisAuto"') && str_contains($td['body'], 'id="qrisAutoModal"'), 'travel/bookings/detail.php #tab-cost renders manual payment form & QRIS auto modal');

    // HTTP POST Helper
    $httpPostForm = static function (string $path, array $fields) use ($base, $jar): array {
        $tmpBody = tempnam(sys_get_temp_dir(), 'ts_pf_');
        file_put_contents($tmpBody, http_build_query($fields));
        $hdrFile = tempnam(sys_get_temp_dir(), 'ts_ph_');
        $body = (string) shell_exec('curl.exe -s -L -b ' . escapeshellarg($jar) . ' -D ' . escapeshellarg($hdrFile) . ' --data-binary ' . escapeshellarg('@' . $tmpBody) . ' ' . escapeshellarg($base . $path));
        $hdrs = (string) @file_get_contents($hdrFile);
        @unlink($tmpBody);
        @unlink($hdrFile);
        $code = 0;
        if (preg_match_all('/HTTP\/\S+\s+(\d{3})/', $hdrs, $ms) && !empty($ms[1])) {
            $code = (int) end($ms[1]);
        }
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    };

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $td['body'], $tCsrfM)) {
        $tCsrf = $tCsrfM[1];
        // Reject manual overpay (400k > 350k)
        $resTBad = $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token' => $tCsrf,
            'id'         => $tempTravelId,
            'act'        => 'pay_manual',
            'active_tab' => 'cost',
            'pay_type'   => 'Pembayaran Penuh (Full)',
            'amount'     => '400000',
            'pay_note'   => 'Test overpay',
        ]);
        ts_check(abs(invoice_paid_total($tempTravelInvId)) < 0.01 && stripos($resTBad['body'], 'melebihi sisa tagihan') !== false, 'travel/detail.php act=pay_manual rejects overpay > invoice total');

        // Accept valid manual partial payment (150k of 350k)
        $resTOk = $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token' => $tCsrf,
            'id'         => $tempTravelId,
            'act'        => 'pay_manual',
            'active_tab' => 'cost',
            'pay_type'   => 'Down Payment (DP)',
            'amount'     => '150000',
            'pay_note'   => 'DP Valid 150k',
        ]);
        $tInvNow = invoice_find($tempTravelInvId);
        ts_check(
            abs(invoice_paid_total($tempTravelInvId) - 150000.0) < 0.01 && ($tInvNow['pay_status'] ?? '') === 'partial',
            'travel/detail.php act=pay_manual accepts valid 150k DP -> paid=150000 & pay_status=partial'
        );

        // Generalized qris_ajax.php with booking_type=travel: reject overpay 250k (> 200k remaining)
        $qTBad = $httpPostForm('/pages/rental/bookings/qris_ajax.php', [
            'csrf_token'   => $tCsrf,
            'act'          => 'create',
            'booking_type' => 'travel',
            'booking_id'   => $tempTravelId,
            'pay_type'     => 'Pelunasan',
            'amount'       => '250000',
        ]);
        ts_check($qTBad['code'] === 422 && empty($qTBad['json']['ok']), 'qris_ajax.php (booking_type=travel) rejects QRIS > remaining bill with HTTP 422');

        // Generalized qris_ajax.php with booking_type=travel: create valid 100k QRIS + poll
        $qTOk = $httpPostForm('/pages/rental/bookings/qris_ajax.php', [
            'csrf_token'   => $tCsrf,
            'act'          => 'create',
            'booking_type' => 'travel',
            'booking_id'   => $tempTravelId,
            'pay_type'     => 'Pelunasan',
            'amount'       => '100000',
        ]);
        $qTOrd = (string) ($qTOk['json']['order_id'] ?? '');
        if ($qTOrd !== '') $createdOrders[] = $qTOrd;
        ts_check(
            $qTOk['code'] === 200 && !empty($qTOk['json']['ok']) && $qTOrd !== '' && abs((float) ($qTOk['json']['remaining'] ?? 0) - 200000.0) < 0.01,
            'qris_ajax.php (booking_type=travel) creates dynamic QRIS order cleanly'
        );

        $qTPoll = $httpPostForm('/pages/rental/bookings/qris_ajax.php', [
            'csrf_token'   => $tCsrf,
            'act'          => 'poll',
            'booking_type' => 'travel',
            'booking_id'   => $tempTravelId,
            'order_id'     => $qTOrd,
        ]);
        ts_check(
            $qTPoll['code'] === 200 && !empty($qTPoll['json']['ok']) && ($qTPoll['json']['booking_type'] ?? '') === 'travel' && abs((float) ($qTPoll['json']['remaining'] ?? 0) - 200000.0) < 0.01,
            'qris_ajax.php (booking_type=travel) act=poll returns booking_type=travel & remaining=200000'
        );
    } else {
        ts_check(false, 'CSRF token extracted from travel/detail.php');
    }

    // 10. Multi-Passenger Search & +N tambahan Badge on Rental & Travel Index
    booking_passengers_set('rental', $tempBookingId, 1, [$lastPaxId, $createdPaxIds[0]], $lastPaxId, 'test');
    $rIdx = $httpGet('/pages/rental/bookings/index.php?company_id=1&q=' . urlencode('ZZZScale Test Passenger 001'));
    ts_check(
        $rIdx['code'] === 200 && str_contains($rIdx['body'], (string) $bk['document_number']) && str_contains($rIdx['body'], '+1 tambahan'),
        'rental/bookings/index.php finds booking via extra passenger & shows +1 tambahan badge'
    );

    $tIdx = $httpGet('/pages/travel/bookings/index.php?company_id=1&q=' . urlencode('ZZZScale Test Passenger 001'));
    ts_check(
        $tIdx['code'] === 200 && str_contains($tIdx['body'], (string) $tbk['document_number']) && str_contains($tIdx['body'], '+2 tambahan') && str_contains($tIdx['body'], 'Total Pembayaran') && str_contains($tIdx['body'], 'ZZ 0070 SC'),
        'travel/bookings/index.php finds booking via extra passenger, shows +2 tambahan badge & Rental-parity columns'
    );

    // 11. Point 4 — Employees & Dispatch Scalability (Composite Index, EXPLAIN, Bounded Options, Remote Search & UI Hooks)
    $stEmpIns = $db->prepare("INSERT INTO employees (company_id, branch_id, code, name, phone_number, join_date, job_title, job_position, status, created_user, created_date) VALUES (1, ?, ?, ?, ?, '2026-01-01', 'Staff Operasional', ?, 'aktif', 'test', NOW())");
    for ($e = 1; $e <= 65; $e++) {
        $eCode  = sprintf('EMPZ%04d-%d', $e, random_int(10, 99));
        $eName  = sprintf('ZZZScale Test Employee %03d', $e);
        $ePhone = sprintf('0888%07d', $e);
        $ePos   = $e > 50 ? 'Driver' : 'Staff';
        $stEmpIns->bind_param('issss', $branchId, $eCode, $eName, $ePhone, $ePos);
        $stEmpIns->execute();
        $createdEmpIds[] = (int) $db->insert_id;
    }
    $stEmpIns->close();
    $lastEmpId = (int) $createdEmpIds[49]; // 050 Staff (outside first 50 with default real rows)
    $lastDrvId = (int) end($createdEmpIds); // 065 Driver

    $expEmpAll = $db->query("EXPLAIN SELECT id, code, name FROM employees FORCE INDEX (ix_employees_company_status_name) WHERE company_id = 1 AND status = 'aktif' ORDER BY name ASC LIMIT 50")->fetch_assoc();
    ts_check(($expEmpAll['key'] ?? '') === 'ix_employees_company_status_name' && !str_contains((string) ($expEmpAll['Extra'] ?? ''), 'Using filesort'), 'EXPLAIN employees list uses ix_employees_company_status_name without filesort');

    $expEmpDrv = $db->query("EXPLAIN SELECT id, code, name FROM employees FORCE INDEX (ix_employees_company_pos_status_name) WHERE company_id = 1 AND job_position = 'Driver' AND status = 'aktif' ORDER BY name ASC LIMIT 50")->fetch_assoc();
    ts_check(($expEmpDrv['key'] ?? '') === 'ix_employees_company_pos_status_name' && !str_contains((string) ($expEmpDrv['Extra'] ?? ''), 'Using filesort'), 'EXPLAIN employees drivers uses ix_employees_company_pos_status_name without filesort');

    $optsEmp = opts_employees(1, [$lastEmpId], 50);
    $optsEmpIds = array_map(static fn($r) => (int) ($r['id'] ?? 0), $optsEmp);
    ts_check(count($optsEmp) <= 51 && in_array($lastEmpId, $optsEmpIds, true), 'opts_employees() caps at 50 + preserved include_ids');

    $drvOpts = driver_list(1, [$lastDrvId], 50);
    $drvOptIds = array_map(static fn($r) => (int) ($r['id'] ?? 0), $drvOpts);
    ts_check(count($drvOpts) <= 51 && in_array($lastDrvId, $drvOptIds, true), 'driver_list() caps at 50 + preserved include_ids');

    $attOpts = attendance_employee_options(1, [$lastEmpId], 50);
    $attOptIds = array_map(static fn($r) => (int) ($r['id'] ?? 0), $attOpts);
    ts_check(count($attOpts) <= 51 && in_array($lastEmpId, $attOptIds, true), 'attendance_employee_options() caps at 50 + preserved include_ids');

    $empSearch = $httpGet('/pages/employees/options.php?company_id=1&q=ZZZScale');
    ts_check(
        $empSearch['code'] === 200 && is_array($empSearch['json']) && count($empSearch['json']) > 0 && count($empSearch['json']) <= 30 && isset($empSearch['json'][0]['value'], $empSearch['json'][0]['text'], $empSearch['json'][0]['job_position']),
        'employees/options.php remote search returns bounded JSON metadata'
    );

    $drvSearch = $httpGet('/pages/employees/options.php?company_id=1&job_position=Driver&q=ZZZScale');
    $onlyDrivers = is_array($drvSearch['json']) && count($drvSearch['json']) === 15;
    if ($onlyDrivers) {
        foreach ($drvSearch['json'] as $dr) {
            if (($dr['job_position'] ?? '') !== 'Driver') {
                $onlyDrivers = false;
                break;
            }
        }
    }
    ts_check($drvSearch['code'] === 200 && $onlyDrivers, 'employees/options.php job_position=Driver filters strictly to 15 seeded drivers');

    $dispDocSearch = $httpGet('/pages/dispatch/booking_options.php?company_id=1&q=' . urlencode((string) $tbk['document_number']));
    $foundTravelKey = false;
    foreach ((array) ($dispDocSearch['json'] ?? []) as $di) {
        if (($di['value'] ?? '') === 'travel:' . $tempTravelId) {
            $foundTravelKey = true;
            break;
        }
    }
    ts_check($dispDocSearch['code'] === 200 && $foundTravelKey, 'dispatch/booking_options.php finds unassigned travel booking by doc prefix');

    $dispPaxSearch = $httpGet('/pages/dispatch/booking_options.php?company_id=1&q=ZZZScale');
    $foundDispPax = false;
    foreach ((array) ($dispPaxSearch['json'] ?? []) as $di) {
        if (in_array((string) ($di['value'] ?? ''), ['rental:' . $tempBookingId, 'travel:' . $tempTravelId], true)) {
            $foundDispPax = true;
            break;
        }
    }
    ts_check($dispPaxSearch['code'] === 200 && $foundDispPax, 'dispatch/booking_options.php finds unassigned bookings via passenger name prefix');

    foreach ([
        ['/pages/attendance/form.php', 'pages/employees/options.php', 'attendance/form.php'],
        ['/pages/attendance/index.php', 'pages/employees/options.php', 'attendance/index.php'],
        ['/pages/dispatch/assign.php', 'pages/dispatch/booking_options.php', 'dispatch/assign.php (booking)'],
        ['/pages/dispatch/assign.php', 'pages/employees/options.php?job_position=Driver', 'dispatch/assign.php (driver)'],
        ['/pages/hr/employee_recognitions/form.php', 'pages/employees/options.php?status=all', 'hr/employee_recognitions/form.php'],
        ['/pages/travel/bookings/detail.php?id=' . $tempTravelId, 'pages/employees/options.php', 'travel/detail.php #tab-ops'],
        ['/pages/rental/bookings/detail.php?id=' . $tempBookingId, 'crew-emp-select', 'rental/detail.php crew'],
        ['/pages/rental/bookings/form.php', 'crew-emp-select', 'rental/form.php crew'],
    ] as [$chkUrl, $needle, $chkLbl]) {
        $pg = $httpGet($chkUrl);
        ts_check($pg['code'] === 200 && str_contains($pg['body'], $needle), "{$chkLbl} renders remote Tom Select hook ({$needle})");
    }

    // 12. Point 1 — Finance Invoice Detail RBAC POST Guard & attachRupiahInput
    $adminInvPg = $httpGet('/pages/finance/invoices/detail.php?id=' . $tempTravelInvId);
    ts_check(
        $adminInvPg['code'] === 200 && str_contains($adminInvPg['body'], 'id="invPayAmountValue"') && str_contains($adminInvPg['body'], 'window.attachRupiahInput'),
        'finance/invoices/detail.php renders #invPayAmountValue hidden input & window.attachRupiahInput'
    );

    $vUser = 'zzzviewer_' . random_int(1000, 9999);
    $vPass = 'ViewerPass123!';
    $vHash = password_hash($vPass, PASSWORD_BCRYPT);
    $vEmail = $vUser . '@example.test';
    $stV = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active, created_date, updated_date) VALUES (?, ?, ?, 'ZZZ Test Viewer', 5, 1, 1, NOW(), NOW())");
    $stV->bind_param('sss', $vUser, $vEmail, $vHash);
    $stV->execute();
    $tempViewerUserId = (int) $db->insert_id;
    $stV->close();
    $db->query("INSERT INTO user_companies (user_id, company_id, created_date) VALUES ({$tempViewerUserId}, 1, NOW())");

    $vLoginPg = (string) shell_exec('curl.exe -s -c ' . escapeshellarg($viewerJar) . ' -b ' . escapeshellarg($viewerJar) . ' ' . escapeshellarg($base . '/pages/auth/login.php'));
    $vOk = false;
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $vLoginPg, $vM)) {
        $vBodyF = tempnam(sys_get_temp_dir(), 'ts_vl_');
        file_put_contents($vBodyF, http_build_query(['username' => $vUser, 'password' => $vPass, 'csrf_token' => $vM[1]]));
        $nullDev = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';
        $vCode = trim((string) shell_exec('curl.exe -s -c ' . escapeshellarg($viewerJar) . ' -b ' . escapeshellarg($viewerJar) . ' -o ' . $nullDev . ' -w "%{http_code}" --data-binary @' . escapeshellarg($vBodyF) . ' ' . escapeshellarg($base . '/pages/auth/login.php')));
        @unlink($vBodyF);
        $vOk = ($vCode === '302');
    }
    ts_check($vOk, 'HTTP Viewer (role 5, no invoices.manage) login succeeded');

    $vGetInv = (string) shell_exec('curl.exe -s -b ' . escapeshellarg($viewerJar) . ' -D ' . escapeshellarg($viewerJar . '.hdr') . ' ' . escapeshellarg($base . '/pages/finance/invoices/detail.php?id=' . $tempTravelInvId));
    $vGetHdr = (string) @file_get_contents($viewerJar . '.hdr');
    @unlink($viewerJar . '.hdr');
    $vGetCode = preg_match('/HTTP\/\S+\s+(\d{3})/', $vGetHdr, $vhm) ? (int) $vhm[1] : 0;
    ts_check($vGetCode === 200 && !str_contains($vGetInv, 'id="invPayForm"'), 'Viewer GET finance/invoices/detail.php returns HTTP 200 & hides payment form');

    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $vGetInv, $vCsrfM)) {
        $vPost = static function (array $fields) use ($base, $viewerJar, $tempTravelInvId): int {
            $tmpB = tempnam(sys_get_temp_dir(), 'ts_vp_');
            $tmpH = tempnam(sys_get_temp_dir(), 'ts_vh_');
            file_put_contents($tmpB, http_build_query($fields));
            shell_exec('curl.exe -s -b ' . escapeshellarg($viewerJar) . ' -D ' . escapeshellarg($tmpH) . ' -o ' . (DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null') . ' --data-binary @' . escapeshellarg($tmpB) . ' ' . escapeshellarg($base . '/pages/finance/invoices/detail.php?id=' . $tempTravelInvId));
            $h = (string) @file_get_contents($tmpH);
            @unlink($tmpB);
            @unlink($tmpH);
            return preg_match('/HTTP\/\S+\s+(\d{3})/', $h, $m) ? (int) $m[1] : 0;
        };
        $paidBefore = invoice_paid_total($tempTravelInvId);
        $codePay = $vPost(['csrf_token' => $vCsrfM[1], 'id' => $tempTravelInvId, 'act' => 'pay', 'amount' => '10000']);
        $codeCancel = $vPost(['csrf_token' => $vCsrfM[1], 'id' => $tempTravelInvId, 'act' => 'cancel', 'reason' => 'unauthorized']);
        $invAfterV = invoice_find($tempTravelInvId);
        ts_check(
            $codePay === 403 && $codeCancel === 403 && abs(invoice_paid_total($tempTravelInvId) - $paidBefore) < 0.01 && ($invAfterV['status'] ?? '') !== 'cancelled',
            'Viewer POST act=pay & act=cancel rejected with HTTP 403 and zero state mutation'
        );
    } else {
        ts_check(false, 'Viewer header CSRF token extracted for RBAC POST test');
    }

    // 13. Point 2 — Travel Detail Inline Edit, Route Estimation Hook, Quick Modal & Invoice/ExtraPax Sync
    $tdDetail = $httpGet('/pages/travel/bookings/detail.php?id=' . $tempTravelId);
    foreach (['id="travelDetailForm"', 'id="primaryPassengerSelect"', 'id="extraPassengersSelect"', 'id="quickPassengerModal"', 'id="seatCapacityWarning"', 'rutes/lookup.php'] as $tdNeedle) {
        ts_check(str_contains($tdDetail['body'], $tdNeedle), "travel/bookings/detail.php contains {$tdNeedle}");
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $tdDetail['body'], $tdCsrfM)) {
        $tdCsrf = $tdCsrfM[1];
        $updOk = $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token'                 => $tdCsrf,
            'id'                         => $tempTravelId,
            'act'                        => 'update_detail',
            'active_tab'                 => 'detail',
            'branch_id'                  => $branchId,
            'date'                       => date('Y-m-d'),
            'time'                       => '11:30',
            'passenger_id'               => $lastPaxId,
            'passenger_ids'              => [$createdPaxIds[0], $createdPaxIds[1], $createdPaxIds[2]],
            'category_vehicle_id'        => $catId,
            'vehicle_id'                 => $lastVehId,
            'total_price'                => '600.000',
            'pickup_point_district_id'   => (int) $distRow['id'],
            'delivery_point_district_id' => (int) $distRow['id'],
            'pickup_address'             => 'Jl. Jemput Baru 123',
            'delivery_address'           => 'Jl. Antar Baru 456',
            'note'                       => 'Catatan detail travel updated',
        ]);
        $bkRowUp = $db->query("SELECT total_price, time, note, pickup_address FROM travel_ticket_bookings WHERE id = {$tempTravelId}")->fetch_assoc();
        $invSynced = invoice_find($tempTravelInvId);
        $bpCnt3 = (int) $db->query("SELECT COUNT(*) FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$tempTravelId} AND is_primary = 0")->fetch_column();
        ts_check(
            $updOk['code'] === 200 && abs((float) ($bkRowUp['total_price'] ?? 0) - 600000.0) < 0.01 && ($bkRowUp['time'] ?? '') === '11:30:00' && ($bkRowUp['note'] ?? '') === 'Catatan detail travel updated' && abs((float) ($invSynced['total'] ?? 0) - 600000.0) < 0.01 && $bpCnt3 === 3,
            'travel/detail.php act=update_detail updates booking, syncs 3 extra passengers & invoice total=600000'
        );

        // Reject lowering price < paid_total (100k < 150k)
        $updLow = $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token'                 => $tdCsrf,
            'id'                         => $tempTravelId,
            'act'                        => 'update_detail',
            'active_tab'                 => 'detail',
            'branch_id'                  => $branchId,
            'date'                       => date('Y-m-d'),
            'time'                       => '11:30',
            'passenger_id'               => $lastPaxId,
            'passenger_ids'              => [$createdPaxIds[0], $createdPaxIds[1]],
            'category_vehicle_id'        => $catId,
            'vehicle_id'                 => $lastVehId,
            'total_price'                => '100000',
            'pickup_point_district_id'   => (int) $distRow['id'],
            'delivery_point_district_id' => (int) $distRow['id'],
            'note'                       => 'Should rollback',
        ]);
        $priceAfterLow = (float) $db->query("SELECT total_price FROM travel_ticket_bookings WHERE id = {$tempTravelId}")->fetch_column();
        ts_check(abs($priceAfterLow - 600000.0) < 0.01 && stripos($updLow['body'], 'sudah dibayar') !== false, 'travel/detail.php act=update_detail rejects total_price < paid_total cleanly');

        // Verify extra passenger reduction 3 -> 2 -> 0
        $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token'                 => $tdCsrf,
            'id'                         => $tempTravelId,
            'act'                        => 'update_detail',
            'active_tab'                 => 'detail',
            'branch_id'                  => $branchId,
            'date'                       => date('Y-m-d'),
            'time'                       => '11:30',
            'passenger_id'               => $lastPaxId,
            'passenger_ids'              => [$createdPaxIds[0], $createdPaxIds[1]],
            'category_vehicle_id'        => $catId,
            'vehicle_id'                 => $lastVehId,
            'total_price'                => '600000',
            'pickup_point_district_id'   => (int) $distRow['id'],
            'delivery_point_district_id' => (int) $distRow['id'],
            'note'                       => '2 extra left',
        ]);
        $bpCnt2 = (int) $db->query("SELECT COUNT(*) FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$tempTravelId} AND is_primary = 0")->fetch_column();

        $httpPostForm('/pages/travel/bookings/detail.php?id=' . $tempTravelId, [
            'csrf_token'                 => $tdCsrf,
            'id'                         => $tempTravelId,
            'act'                        => 'update_detail',
            'active_tab'                 => 'detail',
            'branch_id'                  => $branchId,
            'date'                       => date('Y-m-d'),
            'time'                       => '11:30',
            'passenger_id'               => $lastPaxId,
            'category_vehicle_id'        => $catId,
            'vehicle_id'                 => $lastVehId,
            'total_price'                => '600000',
            'pickup_point_district_id'   => (int) $distRow['id'],
            'delivery_point_district_id' => (int) $distRow['id'],
            'note'                       => '0 extra left',
        ]);
        $bpCnt0 = (int) $db->query("SELECT COUNT(*) FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$tempTravelId} AND is_primary = 0")->fetch_column();
        ts_check($bpCnt2 === 2 && $bpCnt0 === 0, 'travel/detail.php act=update_detail cleanly removes extra passengers (3 -> 2 -> 0)');
    }

    // 14. Point 3 — Export XLSX & PDF Includes Penumpang Tambahan on Travel & Rental Index
    booking_passengers_set('travel', $tempTravelId, 1, [$lastPaxId, $createdPaxIds[0], $createdPaxIds[1]], $lastPaxId, 'test');
    booking_passengers_set('rental', $tempBookingId, 1, [$lastPaxId, $createdPaxIds[0]], $lastPaxId, 'test');

    $downloadExport = static function (string $path) use ($base, $jar): array {
        $outF = tempnam(sys_get_temp_dir(), 'ts_exp_');
        $hdrF = tempnam(sys_get_temp_dir(), 'ts_eh_');
        shell_exec('curl.exe -s -b ' . escapeshellarg($jar) . ' -D ' . escapeshellarg($hdrF) . ' -o ' . escapeshellarg($outF) . ' ' . escapeshellarg($base . $path));
        $hdr = (string) @file_get_contents($hdrF);
        @unlink($hdrF);
        $code = preg_match('/HTTP\/\S+\s+(\d{3})/', $hdr, $m) ? (int) $m[1] : 0;
        $bytes = (string) @file_get_contents($outF);
        $size = is_file($outF) ? (int) filesize($outF) : 0;
        return ['code' => $code, 'file' => $outF, 'size' => $size, 'bytes' => $bytes];
    };

    $readXlsxXml = static function (string $bin): array {
        $eocd = strrpos($bin, "PK\x05\x06");
        if ($eocd === false || strlen($bin) < $eocd + 22) {
            return [false, ''];
        }
        $cdCount  = unpack('v', substr($bin, $eocd + 10, 2))[1];
        $cdOffset = unpack('V', substr($bin, $eocd + 16, 4))[1];
        $pos = $cdOffset;
        $xml = '';
        for ($i = 0; $i < $cdCount; $i++) {
            if (substr($bin, $pos, 4) !== "PK\x01\x02") {
                return [false, $xml];
            }
            $method   = unpack('v', substr($bin, $pos + 10, 2))[1];
            $compSize = unpack('V', substr($bin, $pos + 20, 4))[1];
            $nameLen  = unpack('v', substr($bin, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($bin, $pos + 30, 2))[1];
            $commLen  = unpack('v', substr($bin, $pos + 32, 2))[1];
            $locOff   = unpack('V', substr($bin, $pos + 42, 4))[1];
            $name     = substr($bin, $pos + 46, $nameLen);
            $pos     += 46 + $nameLen + $extraLen + $commLen;
            if (substr($bin, $locOff, 4) !== "PK\x03\x04") {
                continue;
            }
            $lLens = unpack('vn/ve', substr($bin, $locOff + 26, 4));
            $data  = substr($bin, $locOff + 30 + $lLens['n'] + $lLens['e'], $compSize);
            if ($method === 8) {
                $data = (string) @gzinflate($data);
            }
            if (str_ends_with($name, '.xml')) {
                $xml .= $data;
            }
        }
        return [true, $xml];
    };

    foreach ([
        ['travel', '/pages/travel/bookings/index.php'],
        ['rental', '/pages/rental/bookings/index.php'],
    ] as [$expType, $expPath]) {
        $qStr = '?company_id=1&q=' . urlencode('ZZZScale Test Passenger 001');
        $xRes = $downloadExport($expPath . $qStr . '&export=xlsx');
        [$zipOk, $xText] = $xRes['size'] > 0 ? $readXlsxXml($xRes['bytes']) : [false, ''];
        @unlink($xRes['file']);
        ts_check(
            $xRes['code'] === 200 && $zipOk && str_contains($xText, 'Penumpang Tambahan') && str_contains($xText, 'ZZZScale Test Passenger 001'),
            "{$expType}/bookings/index.php export=xlsx includes Penumpang Tambahan column & extra passenger name"
        );

        $pRes = $downloadExport($expPath . $qStr . '&export=pdf');
        @unlink($pRes['file']);
        ts_check(
            $pRes['code'] === 200 && str_starts_with($pRes['bytes'], '%PDF-') && $pRes['size'] > 1000,
            "{$expType}/bookings/index.php export=pdf returns valid PDF (>1KB)"
        );
    }
} finally {
    foreach ($createdOrders as $oid) {
        $esc = $db->real_escape_string($oid);
        $db->query("DELETE FROM wa_outbox WHERE event_id = 'pay-{$esc}'");
        $db->query("DELETE FROM payment_gateway_orders WHERE order_id = '{$esc}'");
    }
    if (!empty($tempTravelId) && $tempTravelId > 0) {
        $db->query("DELETE FROM payment_gateway_orders WHERE booking_type = 'travel' AND booking_id = {$tempTravelId}");
        try { booking_delete('travel', $tempTravelId, 1); } catch (Throwable $ex) {}
    }
    if ($tempBookingId > 0) {
        try { booking_delete('rental', $tempBookingId, 1); } catch (Throwable $ex) {}
    }
    if (!empty($tempViewerUserId) && $tempViewerUserId > 0) {
        $db->query("DELETE FROM user_companies WHERE user_id = {$tempViewerUserId}");
        $db->query("DELETE FROM users WHERE id = {$tempViewerUserId}");
    }
    if (!empty($createdEmpIds)) {
        $inE = implode(',', array_map('intval', $createdEmpIds));
        $db->query("DELETE FROM employees WHERE id IN ({$inE})");
    }
    if ($createdRuteIds) {
        $inR = implode(',', array_map('intval', $createdRuteIds));
        $db->query("DELETE FROM rutes WHERE id IN ({$inR})");
    }
    if ($createdVehIds) {
        $inV = implode(',', array_map('intval', $createdVehIds));
        $db->query("DELETE FROM vehicles WHERE id IN ({$inV})");
    }
    if ($createdPaxIds) {
        $inP = implode(',', array_map('intval', $createdPaxIds));
        $db->query("DELETE FROM passengers WHERE id IN ({$inP})");
    }
    @unlink($jar);
    @unlink($viewerJar);
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
