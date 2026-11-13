<?php
/**
 * Self-test Tom Select v2.3.1 (Kendaraan, Pelanggan, Provinsi/Kota/Kecamatan)
 * dan proteksi skala jutaan data (LIMIT preload, endpoint remote search, DB indexes, EXPLAIN).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/travel/_helpers.php';
require_once __DIR__ . '/../includes/booking_service.php';

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
$tempBookingId = 0;
$jar = tempnam(sys_get_temp_dir(), 'ts_jar_');

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
} finally {
    if ($tempBookingId > 0) {
        try { booking_delete('rental', $tempBookingId, 1); } catch (Throwable $ex) {}
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
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
