<?php
/**
 * Comprehensive verification: Travel & Rental strict CompanyId scoping.
 * Tests HTTP responses, JSON option endpoints, topbar switcher propagation,
 * cross-company POST rejection, and restricted user company boundary.
 * Usage: php tests/company_scope_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/regulation_service.php';
require_once __DIR__ . '/../pages/travel/_helpers.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();
regulation_ensure_schema();
rute_schedule_ensure_schema();

$pass = 0;
$fail = 0;
function cs_check(bool $ok, string $label): void
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

$tempVehIds    = [];
$tempPaxIds    = [];
$tempEmpIds    = [];
$tempRuteIds   = [];
$tempSchedIds  = [];
$tempRegIds    = [];
$tempTravelIds = [];
$tempRentalIds = [];
$tempUserId    = 0;

$jarAdmin      = sys_get_temp_dir() . '/cscope_admin_' . getmypid() . '.txt';
$jarRestricted = sys_get_temp_dir() . '/cscope_restr_' . getmypid() . '.txt';
@unlink($jarAdmin);
@unlink($jarRestricted);

$httpGet = static function (string $jar, string $path) use ($base): array {
    $hdr = sys_get_temp_dir() . '/cscope_ghdr_' . getmypid() . '.txt';
    $out = (string) shell_exec(
        'curl.exe -s -D ' . escapeshellarg($hdr)
        . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
        . ' ' . escapeshellarg($base . $path)
    );
    $hdrTxt = is_file($hdr) ? (string) file_get_contents($hdr) : '';
    @unlink($hdr);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrTxt, $m)) {
        $code = (int) $m[1];
    }
    return [
        'code'     => $code,
        'headers'  => $hdrTxt,
        'body'     => $out,
        'json'     => json_decode($out, true),
        'location' => preg_match('/^Location:\s*(.+)$/mi', $hdrTxt, $ml) ? trim($ml[1]) : '',
    ];
};

$httpPost = static function (string $jar, string $path, array $fields) use ($base): array {
    $bodyFile = sys_get_temp_dir() . '/cscope_pbody_' . getmypid() . '.txt';
    $hdr      = sys_get_temp_dir() . '/cscope_phdr_' . getmypid() . '.txt';
    file_put_contents($bodyFile, http_build_query($fields));
    $out = (string) shell_exec(
        'curl.exe -s -D ' . escapeshellarg($hdr)
        . ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
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
        'code'     => $code,
        'headers'  => $hdrTxt,
        'body'     => $out,
        'json'     => json_decode($out, true),
        'location' => preg_match('/^Location:\s*(.+)$/mi', $hdrTxt, $ml) ? trim($ml[1]) : '',
    ];
};

$loginJar = static function (string $jar, string $username, string $password) use ($httpGet, $httpPost): bool {
    $g = $httpGet($jar, '/pages/auth/login.php');
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $g['body'], $m)) {
        return false;
    }
    $p = $httpPost($jar, '/pages/auth/login.php', [
        'username'   => $username,
        'password'   => $password,
        'csrf_token' => $m[1],
    ]);
    $d = $httpGet($jar, '/pages/dashboard/index.php');
    return $p['code'] === 302 && !str_contains($d['body'], 'name="password"');
};

$extractCsrf = static function (string $html): string {
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
};

$switchCompany = static function (string $jar, int $companyId) use ($httpGet, $httpPost, $extractCsrf): bool {
    $d    = $httpGet($jar, '/pages/dashboard/index.php');
    $csrf = $extractCsrf($d['body']);
    if ($csrf === '') {
        return false;
    }
    $p = $httpPost($jar, '/pages/companies/switch.php', [
        'csrf_token' => $csrf,
        'company_id' => $companyId,
    ]);
    return $p['code'] === 302;
};

echo "=== Test CompanyId Isolation (Travel & Rental) ===\n";

try {
    $comp1 = 1; // MTM
    $comp5 = 5; // LIZ
    $br1   = 1;
    $br5   = 5;
    $cat1  = 2; // Bus Besar (MTM)
    $cat5  = 5; // INNOVA (LIZ)

    $refVeh = $db->query("SELECT brand_id, model_unit_id, type_engine_id FROM vehicles WHERE company_id = 1 LIMIT 1")->fetch_assoc();
    $brandId = (int) ($refVeh['brand_id'] ?? 4);
    $modelId = (int) ($refVeh['model_unit_id'] ?? 3);
    $engId   = (int) ($refVeh['type_engine_id'] ?? 2);

    $dists = $db->query("SELECT id, city_id FROM districts ORDER BY id DESC LIMIT 2")->fetch_all(MYSQLI_ASSOC);
    $distA = (int) $dists[0]['id'];
    $cityA = (int) $dists[0]['city_id'];
    $distB = (int) $dists[1]['id'];
    $cityB = (int) $dists[1]['city_id'];

    $uniq = random_int(1000, 9999);
    $uTest = 'cscope_test';

    // 1. Seed vehicles for MTM (comp 1) & LIZ (comp 5)
    $stV = $db->prepare(
        "INSERT INTO vehicles (company_id, brand_id, model_unit_id, type_engine_id, category_vehicle_id, frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, ownership, is_active, created_user)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 2024, ?, ?, 'Milik Sendiri', 1, ?)"
    );
    $v1Frame = "FRM1{$uniq}"; $v1Eng = "ENG1{$uniq}"; $v1Seat = 45; $v1Col = 'Putih'; $v1Plate = "B {$uniq} MTM";
    $stV->bind_param('iiiiississs', $comp1, $brandId, $modelId, $engId, $cat1, $v1Frame, $v1Eng, $v1Seat, $v1Col, $v1Plate, $uTest);
    $stV->execute();
    $veh1Id = (int) $db->insert_id;
    $tempVehIds[] = $veh1Id;

    $v5Frame = "FRM5{$uniq}"; $v5Eng = "ENG5{$uniq}"; $v5Seat = 7; $v5Col = 'Hitam'; $v5Plate = "B {$uniq} LIZ";
    $stV->bind_param('iiiiississs', $comp5, $brandId, $modelId, $engId, $cat5, $v5Frame, $v5Eng, $v5Seat, $v5Col, $v5Plate, $uTest);
    $stV->execute();
    $veh5Id = (int) $db->insert_id;
    $tempVehIds[] = $veh5Id;
    $stV->close();

    // 2. Seed passengers for MTM (comp 1) & LIZ (comp 5)
    $stP = $db->prepare("INSERT INTO passengers (company_id, code, name, country_code, phone_number, created_user) VALUES (?, ?, ?, '+62', ?, ?)");
    $p1Code = "CSM1{$uniq}"; $p1Name = "CSCOPE Pax MTM {$uniq}"; $p1Tel = "81111{$uniq}";
    $stP->bind_param('issss', $comp1, $p1Code, $p1Name, $p1Tel, $uTest);
    $stP->execute();
    $pax1Id = (int) $db->insert_id;
    $tempPaxIds[] = $pax1Id;

    $p5Code = "CSL5{$uniq}"; $p5Name = "CSCOPE Pax LIZ {$uniq}"; $p5Tel = "85555{$uniq}";
    $stP->bind_param('issss', $comp5, $p5Code, $p5Name, $p5Tel, $uTest);
    $stP->execute();
    $pax5Id = (int) $db->insert_id;
    $tempPaxIds[] = $pax5Id;
    $stP->close();

    // 3. Seed employees for MTM (comp 1) & LIZ (comp 5)
    $stE = $db->prepare("INSERT INTO employees (company_id, branch_id, code, name, join_date, job_position, status, created_user) VALUES (?, ?, ?, ?, '2025-01-01', 'Sopir', 'aktif', ?)");
    $e1Code = "EM1{$uniq}"; $e1Name = "CSCOPE Driver MTM {$uniq}";
    $stE->bind_param('iisss', $comp1, $br1, $e1Code, $e1Name, $uTest);
    $stE->execute();
    $emp1Id = (int) $db->insert_id;
    $tempEmpIds[] = $emp1Id;

    $e5Code = "EM5{$uniq}"; $e5Name = "CSCOPE Driver LIZ {$uniq}";
    $stE->bind_param('iisss', $comp5, $br5, $e5Code, $e5Name, $uTest);
    $stE->execute();
    $emp5Id = (int) $db->insert_id;
    $tempEmpIds[] = $emp5Id;
    $stE->close();

    // 4. Seed rutes for MTM & LIZ with same city pair, distinct prices
    $stR = $db->prepare("INSERT INTO rutes (company_id, code, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, min_price, price, max_price, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, 100000, ?, 900000, 1, ?)");
    $r1Code = "RM{$uniq}"; $r1Name = "CSCOPE Route MTM {$uniq}"; $r1Price = 310000.0;
    $stR->bind_param('issiiids', $comp1, $r1Code, $r1Name, $cityA, $cityB, $cat1, $r1Price, $uTest);
    $stR->execute();
    $r1Id = (int) $db->insert_id;
    $tempRuteIds[] = $r1Id;

    $r5Code = "RL{$uniq}"; $r5Name = "CSCOPE Route LIZ {$uniq}"; $r5Price = 590000.0;
    $stR->bind_param('issiiids', $comp5, $r5Code, $r5Name, $cityA, $cityB, $cat5, $r5Price, $uTest);
    $stR->execute();
    $r5Id = (int) $db->insert_id;
    $tempRuteIds[] = $r5Id;
    $stR->close();

    // 5. Seed separated ENUM rute_schedules for MTM (04:00) & LIZ (21:00)
    $stS = $db->prepare("INSERT INTO rute_schedules (company_id, rute_id, departure_time, created_user) VALUES (?, ?, ?, ?)");
    $s1Time = '04:00';
    $stS->bind_param('iiss', $comp1, $r1Id, $s1Time, $uTest);
    $stS->execute();
    $tempSchedIds[] = (int) $db->insert_id;

    $s5Time = '21:00';
    $stS->bind_param('iiss', $comp5, $r5Id, $s5Time, $uTest);
    $stS->execute();
    $tempSchedIds[] = (int) $db->insert_id;
    $stS->close();

    // 6. Seed rental regulations for MTM & LIZ (trip 99 so never collides with trip 1)
    $reg1Id = regulation_save(0, [
        'company_id'          => $comp1,
        'category_vehicle_id' => $cat1,
        'trip'                => 99,
        'start_date'          => '2026-01-01',
        'end_date'            => '9999-12-31',
        'note'                => "CSCOPE Reg MTM {$uniq}",
    ], [['employee_type' => 'Sopir', 'calc_type' => 'Tetap', 'amount' => 111000]], $uTest);
    $tempRegIds[] = $reg1Id;

    $reg5Id = regulation_save(0, [
        'company_id'          => $comp5,
        'category_vehicle_id' => $cat5,
        'trip'                => 99,
        'start_date'          => '2026-01-01',
        'end_date'            => '9999-12-31',
        'note'                => "CSCOPE Reg LIZ {$uniq}",
    ], [['employee_type' => 'Sopir', 'calc_type' => 'Tetap', 'amount' => 555000]], $uTest);
    $tempRegIds[] = $reg5Id;

    // 7. Seed one MTM travel booking & one MTM rental booking for detail-page cross-company testing
    $tbk = booking_create('travel', [
        'company_id'                 => $comp1,
        'branch_id'                  => $br1,
        'date'                       => '2027-08-15',
        'time'                       => '08:00:00',
        'pickup_address'             => 'Pickup MTM Test',
        'pickup_point_city_id'       => $cityA,
        'pickup_point_district_id'   => $distA,
        'delivery_address'           => 'Delivery MTM Test',
        'delivery_point_city_id'     => $cityB,
        'delivery_point_district_id' => $distB,
        'total_price'                => 350000,
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $pax1Id,
        'passenger_ids'              => [$pax1Id],
        'note'                       => "CSCOPE Travel MTM {$uniq}",
        'created_user'               => $uTest,
    ]);
    $travel1Id = (int) $tbk['id'];
    $tempTravelIds[] = $travel1Id;

    $rbk = booking_create('rental', [
        'company_id'                 => $comp1,
        'branch_id'                  => $br1,
        'date'                       => '2027-08-16',
        'time'                       => '08:00:00',
        'start_date'                 => '2027-08-16',
        'end_date'                   => '2027-08-17',
        'trip'                       => 1,
        'bbm'                        => 0,
        'pickup_address'             => 'Rental MTM Pickup',
        'pickup_point_city_id'       => $cityA,
        'pickup_point_district_id'   => $distA,
        'delivery_address'           => 'Rental MTM Drop',
        'delivery_point_city_id'     => $cityB,
        'delivery_point_district_id' => $distB,
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $pax1Id,
        'passenger_ids'              => [$pax1Id],
        'total_price'                => 1200000,
        'note'                       => "CSCOPE Rental MTM {$uniq}",
        'created_user'               => $uTest,
    ]);
    $rental1Id = (int) $rbk['id'];
    $tempRentalIds[] = $rental1Id;

    // 8. Login as Super Admin and explicitly select Company 1 (MTM) in topbar switcher
    cs_check($loginJar($jarAdmin, 'superadmin', 'P@ssw0rd'), 'Super Admin HTTP login succeeded');
    cs_check($switchCompany($jarAdmin, 1), 'Header company switch POST -> Company 1 (MTM) succeeded');

    // A. JSON option endpoints under Company 1
    $vo1 = $httpGet($jarAdmin, "/pages/vehicles/options.php?q={$uniq}");
    $vo1Json = is_array($vo1['json']) ? json_encode($vo1['json']) : '';
    cs_check($vo1['code'] === 200 && str_contains($vo1Json, $v1Plate) && !str_contains($vo1Json, $v5Plate),
        'vehicles/options.php returns ONLY Company 1 vehicle when active_company_id=1');

    $vo5 = $httpGet($jarAdmin, "/pages/vehicles/options.php?q={$uniq}&company_id=5");
    $vo5Json = is_array($vo5['json']) ? json_encode($vo5['json']) : '';
    cs_check($vo5['code'] === 200 && str_contains($vo5Json, $v5Plate) && !str_contains($vo5Json, $v1Plate),
        'vehicles/options.php?company_id=5 returns ONLY Company 5 vehicle');

    $po1 = $httpGet($jarAdmin, "/pages/transport/passengers/options.php?q={$uniq}");
    $po1Json = is_array($po1['json']) ? json_encode($po1['json']) : '';
    cs_check($po1['code'] === 200 && str_contains($po1Json, $p1Name) && !str_contains($po1Json, $p5Name),
        'passengers/options.php returns ONLY Company 1 passenger when active=1');

    $po5 = $httpGet($jarAdmin, "/pages/transport/passengers/options.php?q={$uniq}&company_id=5");
    $po5Json = is_array($po5['json']) ? json_encode($po5['json']) : '';
    cs_check($po5['code'] === 200 && str_contains($po5Json, $p5Name) && !str_contains($po5Json, $p1Name),
        'passengers/options.php?company_id=5 returns ONLY Company 5 passenger');

    $eo1 = $httpGet($jarAdmin, "/pages/employees/options.php?q={$uniq}");
    $eo1Json = is_array($eo1['json']) ? json_encode($eo1['json']) : '';
    cs_check($eo1['code'] === 200 && str_contains($eo1Json, $e1Name) && !str_contains($eo1Json, $e5Name),
        'employees/options.php returns ONLY Company 1 employee when active=1');

    $eo5 = $httpGet($jarAdmin, "/pages/employees/options.php?q={$uniq}&company_id=5");
    $eo5Json = is_array($eo5['json']) ? json_encode($eo5['json']) : '';
    cs_check($eo5['code'] === 200 && str_contains($eo5Json, $e5Name) && !str_contains($eo5Json, $e1Name),
        'employees/options.php?company_id=5 returns ONLY Company 5 employee');

    $rl1 = $httpGet($jarAdmin, "/pages/travel/rutes/lookup.php?company_id=1&pickup_point_city_id={$cityA}&delivery_point_city_id={$cityB}&category_vehicle_id={$cat1}");
    $rl5 = $httpGet($jarAdmin, "/pages/travel/rutes/lookup.php?company_id=5&pickup_point_city_id={$cityA}&delivery_point_city_id={$cityB}&category_vehicle_id={$cat5}");
    cs_check(
        ($rl1['json']['route']['price'] ?? 0) == 310000 && ($rl5['json']['route']['price'] ?? 0) == 590000,
        'rutes/lookup.php isolates MTM (310k) vs LIZ (590k) prices cleanly'
    );

    $maValid = $httpGet($jarAdmin, "/pages/transport/regulations/match_api.php?company_id=5&category_vehicle_id={$cat5}&trip=99&date=2026-06-01");
    $maCross = $httpGet($jarAdmin, "/pages/transport/regulations/match_api.php?company_id=1&regulation_id={$reg5Id}");
    cs_check(
        !empty($maValid['json']['ok']) && (int) ($maValid['json']['regulation']['id'] ?? 0) === $reg5Id,
        'regulations/match_api.php matches Company 5 regulation when company_id=5'
    );
    cs_check(
        $maCross['code'] === 200 && empty($maCross['json']['ok']),
        'regulations/match_api.php rejects loading Company 5 regulation_id under company_id=1'
    );

    // Quick create passenger scoped to requested company_id=5
    $tfPage1 = $httpGet($jarAdmin, '/pages/travel/bookings/form.php');
    $csrf1   = $extractCsrf($tfPage1['body']);
    $qc5     = $httpPost($jarAdmin, '/pages/transport/passengers/quick_create.php', [
        'csrf_token' => $csrf1,
        'company_id' => 5,
        'name'       => "CSCOPE Quick LIZ {$uniq}",
        'phone_number' => '812999000',
    ]);
    $qc5Id = (int) ($qc5['json']['data']['id'] ?? 0);
    if ($qc5Id > 0) {
        $tempPaxIds[] = $qc5Id;
    }
    $qc5Row = $qc5Id > 0 ? $db->query("SELECT company_id FROM passengers WHERE id = {$qc5Id}")->fetch_assoc() : null;
    cs_check($qc5['code'] === 200 && !empty($qc5['json']['ok']) && (int) ($qc5Row['company_id'] ?? 0) === 5,
        'passengers/quick_create.php persists posted company_id=5 cleanly');

    // B. Switch header active company -> 5 (LIZ)
    cs_check($switchCompany($jarAdmin, 5), 'Header company switch POST -> Company 5 (LIZ) succeeded');

    // vehicles/options.php with NO company_id param now defaults to Company 5!
    $voAfterSwitch = $httpGet($jarAdmin, "/pages/vehicles/options.php?q={$uniq}");
    $voAfterJson   = is_array($voAfterSwitch['json']) ? json_encode($voAfterSwitch['json']) : '';
    cs_check($voAfterSwitch['code'] === 200 && str_contains($voAfterJson, $v5Plate) && !str_contains($voAfterJson, $v1Plate),
        'After switching header to Company 5, vehicles/options.php returns ONLY LIZ vehicle');

    // Travel & Rental Create Forms under Company 5
    $tf5 = $httpGet($jarAdmin, '/pages/travel/bookings/form.php');
    cs_check(
        $tf5['code'] === 200
        && str_contains($tf5['body'], 'Toyota Innova Reborn')
        && !str_contains($tf5['body'], 'Bus Besar')
        && !str_contains($tf5['body'], 'Head Office Cipondoh')
        && str_contains($tf5['body'], 'Kantor Pusat')
        && str_contains($tf5['body'], $v5Plate)
        && !str_contains($tf5['body'], $v1Plate),
        'travel/bookings/form.php (active=5) renders ONLY Company 5 branches/categories/vehicles'
    );

    $rf5 = $httpGet($jarAdmin, '/pages/rental/bookings/form.php');
    cs_check(
        $rf5['code'] === 200
        && str_contains($rf5['body'], 'Toyota Innova Reborn')
        && !str_contains($rf5['body'], 'Bus Besar')
        && !str_contains($rf5['body'], 'Head Office Cipondoh')
        && str_contains($rf5['body'], $v5Plate)
        && !str_contains($rf5['body'], $v1Plate)
        && !str_contains($rf5['body'], $e1Name),
        'rental/bookings/form.php (active=5) renders ONLY Company 5 branches/categories/vehicles/employees'
    );

    // Travel & Rental List Pages default to Company 5
    $paxIdx5 = $httpGet($jarAdmin, '/pages/transport/passengers/index.php');
    cs_check($paxIdx5['code'] === 200 && str_contains($paxIdx5['body'], $p5Name) && !str_contains($paxIdx5['body'], $p1Name),
        'transport/passengers/index.php defaults to active Company 5');

    $ruteIdx5 = $httpGet($jarAdmin, '/pages/travel/rutes/index.php');
    cs_check($ruteIdx5['code'] === 200 && str_contains($ruteIdx5['body'], $r5Name) && !str_contains($ruteIdx5['body'], $r1Name),
        'travel/rutes/index.php defaults to active Company 5');
    cs_check(str_contains($ruteIdx5['body'], '21:00') && !str_contains($ruteIdx5['body'], '>04:00<')
        && !str_contains($ruteIdx5['body'], 'pages/travel/schedules'),
        'travel/rutes/index.php shows Company 5 rute_schedules (21:00), hides Company 1 (04:00), and sidebar removes Jadwal menu');

    $regIdx5 = $httpGet($jarAdmin, '/pages/transport/regulations/index.php');
    cs_check(
        $regIdx5['code'] === 200
        && !str_contains($regIdx5['body'], 'Undefined array key')
        && str_contains($regIdx5['body'], 'INNOVA - Toyota Innova Reborn')
        && str_contains($regIdx5['body'], "CSCOPE Reg LIZ {$uniq}")
        && !str_contains($regIdx5['body'], "CSCOPE Reg MTM {$uniq}"),
        'transport/regulations/index.php defaults to Company 5 with valid category labels & zero PHP warnings'
    );

    $regForm5 = $httpGet($jarAdmin, '/pages/transport/regulations/form.php');
    cs_check(
        $regForm5['code'] === 200
        && (bool) preg_match('/<select[^>]*id="regCategorySelect"[^>]*>([\s\S]*?)<\/select>/', $regForm5['body'], $mRegCat)
        && str_contains($mRegCat[1], 'INNOVA - Toyota Innova Reborn')
        && !str_contains($mRegCat[1], 'Bus Besar'),
        'transport/regulations/form.php (active=5) renders ONLY Company 5 category options'
    );

    // Crucial Detail Page Check: while active session = 5, viewing MTM booking #1 MUST stay strictly scoped to MTM ($booking_cid = 1)!
    $td1 = $httpGet($jarAdmin, "/pages/travel/bookings/detail.php?id={$travel1Id}");
    cs_check(
        $td1['code'] === 200
        && str_contains($td1['body'], 'Head Office Cipondoh')
        && str_contains($td1['body'], $v1Plate)
        && !str_contains($td1['body'], $v5Plate)
        && str_contains($td1['body'], $p1Name)
        && !str_contains($td1['body'], $p5Name),
        'travel/bookings/detail.php (MTM booking while header=LIZ) stays strictly scoped to MTM'
    );

    $rd1 = $httpGet($jarAdmin, "/pages/rental/bookings/detail.php?id={$rental1Id}");
    cs_check(
        $rd1['code'] === 200
        && str_contains($rd1['body'], 'Head Office Cipondoh')
        && str_contains($rd1['body'], $v1Plate)
        && !str_contains($rd1['body'], $v5Plate)
        && str_contains($rd1['body'], $p1Name)
        && !str_contains($rd1['body'], $p5Name),
        'rental/bookings/detail.php (MTM booking while header=LIZ) stays strictly scoped to MTM'
    );

    // Switch header back -> Company 1 (MTM) for POST rejection checks
    cs_check($switchCompany($jarAdmin, 1), 'Header company switch POST -> Company 1 (MTM) succeeded');

    // C. Cross-company POST validation rejections
    $tf1Again = $httpGet($jarAdmin, '/pages/travel/bookings/form.php');
    $csrfTf1  = $extractCsrf($tf1Again['body']);
    $beforeTravelCnt = (int) $db->query('SELECT COUNT(*) FROM travel_ticket_bookings')->fetch_row()[0];

    $baseTravelPost = [
        'csrf_token'                 => $csrfTf1,
        'branch_id'                  => $br1,
        'date'                       => '2027-09-01',
        'time'                       => '09:00',
        'pickup_address'             => 'Test Jl 1',
        'pickup_point_district_id'   => $distA,
        'delivery_address'           => 'Test Jl 2',
        'delivery_point_district_id' => $distB,
        'total_price'                => '350000',
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $pax1Id,
    ];

    $pCrossBranch = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, ['branch_id' => $br5]));
    $pCrossPax    = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, ['passenger_id' => $pax5Id]));
    $pCrossVeh    = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, ['vehicle_id' => $veh5Id]));
    $pCrossExtra  = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, ['passenger_ids' => [$pax5Id]]));
    $afterTravelCnt = (int) $db->query('SELECT COUNT(*) FROM travel_ticket_bookings')->fetch_row()[0];

    cs_check(
        $pCrossBranch['code'] === 200 && $pCrossPax['code'] === 200 && $pCrossVeh['code'] === 200 && $pCrossExtra['code'] === 200
        && $beforeTravelCnt === $afterTravelCnt,
        'travel/bookings/form.php POST rejects cross-company branch, primary pax, vehicle & extra pax without DB insert'
    );

    // Travel detail update_detail with Company 5 vehicle
    $tdPage = $httpGet($jarAdmin, "/pages/travel/bookings/detail.php?id={$travel1Id}");
    $csrfTd = $extractCsrf($tdPage['body']);
    $pTdCross = $httpPost($jarAdmin, "/pages/travel/bookings/detail.php?id={$travel1Id}", [
        'csrf_token'                 => $csrfTd,
        'act'                        => 'update_detail',
        'branch_id'                  => $br1,
        'date'                       => '2027-08-15',
        'time'                       => '08:00',
        'pickup_address'             => 'Pickup MTM Test',
        'pickup_point_district_id'   => $distA,
        'delivery_address'           => 'Delivery MTM Test',
        'delivery_point_district_id' => $distB,
        'total_price'                => '350000',
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh5Id, // Company 5 vehicle!
        'passenger_id'               => $pax1Id,
    ]);
    $tdRowAfter = $db->query("SELECT vehicle_id FROM travel_ticket_bookings WHERE id = {$travel1Id}")->fetch_assoc();
    cs_check((int) ($tdRowAfter['vehicle_id'] ?? 0) === $veh1Id && str_contains($pTdCross['location'], 'detail.php'),
        'travel/bookings/detail.php act=update_detail rejects Company 5 vehicle_id and preserves MTM vehicle_id');

    // Rental form POST cross-company vehicle & crew
    $rf1Page  = $httpGet($jarAdmin, '/pages/rental/bookings/form.php');
    $csrfRf1  = $extractCsrf($rf1Page['body']);
    $beforeRentalCnt = (int) $db->query('SELECT COUNT(*) FROM rental_car_bookings')->fetch_row()[0];
    $baseRentalPost = [
        'csrf_token'                 => $csrfRf1,
        'branch_id'                  => $br1,
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh1Id,
        'passenger_id'               => $pax1Id,
        'start_date'                 => '2027-10-01',
        'end_date'                   => '2027-10-02',
        'time'                       => '08:00',
        'pickup_point_district_id'   => $distA,
        'pickup_address'             => 'Rental Jl 1',
        'delivery_point_district_id' => $distB,
        'delivery_address'           => 'Rental Jl 2',
        'total_price'                => '1000000',
    ];
    $rCrossVeh  = $httpPost($jarAdmin, '/pages/rental/bookings/form.php', array_merge($baseRentalPost, ['vehicle_id' => $veh5Id]));
    $rCrossCrew = $httpPost($jarAdmin, '/pages/rental/bookings/form.php', array_merge($baseRentalPost, [
        'crew_type'        => ['Sopir'],
        'crew_employee_id' => [$emp5Id],
        'crew_amount'      => ['150000'],
    ]));
    $afterRentalCnt = (int) $db->query('SELECT COUNT(*) FROM rental_car_bookings')->fetch_row()[0];
    cs_check($rCrossVeh['code'] === 200 && $rCrossCrew['code'] === 200 && $beforeRentalCnt === $afterRentalCnt,
        'rental/bookings/form.php POST rejects cross-company vehicle & crew without DB insert');

    // Rental detail POST cross-company vehicle & driver
    $rdPage = $httpGet($jarAdmin, "/pages/rental/bookings/detail.php?id={$rental1Id}");
    $csrfRd = $extractCsrf($rdPage['body']);
    $pRdCrossVeh = $httpPost($jarAdmin, "/pages/rental/bookings/detail.php?id={$rental1Id}", [
        'csrf_token'                 => $csrfRd,
        'act'                        => 'update_detail',
        'branch_id'                  => $br1,
        'start_date'                 => '2027-08-16',
        'end_date'                   => '2027-08-17',
        'time'                       => '08:00',
        'pickup_address'             => 'Rental MTM Pickup',
        'pickup_point_district_id'   => $distA,
        'delivery_address'           => 'Rental MTM Drop',
        'delivery_point_district_id' => $distB,
        'total_price'                => '1200000',
        'category_vehicle_id'        => $cat1,
        'vehicle_id'                 => $veh5Id,
        'passenger_id'               => $pax1Id,
    ]);
    $rdRowAfter = $db->query("SELECT vehicle_id FROM rental_car_bookings WHERE id = {$rental1Id}")->fetch_assoc();
    $beforeDrvLogCnt = (int) $db->query("SELECT COUNT(*) FROM trip_assignments WHERE booking_type = 'rental' AND booking_id = {$rental1Id}")->fetch_row()[0];
    $pRdCrossDrv = $httpPost($jarAdmin, "/pages/rental/bookings/detail.php?id={$rental1Id}", [
        'csrf_token'  => $csrfRd,
        'act'         => 'driver',
        'employee_id' => $emp5Id,
        'vehicle_id'  => $veh5Id,
    ]);
    $rdRowAfter2 = $db->query("SELECT vehicle_id FROM rental_car_bookings WHERE id = {$rental1Id}")->fetch_assoc();
    $afterDrvLogCnt = (int) $db->query("SELECT COUNT(*) FROM trip_assignments WHERE booking_type = 'rental' AND booking_id = {$rental1Id}")->fetch_row()[0];
    cs_check(
        (int) $rdRowAfter['vehicle_id'] === $veh1Id
        && (int) $rdRowAfter2['vehicle_id'] === $veh1Id
        && $beforeDrvLogCnt === $afterDrvLogCnt
        && str_contains($pRdCrossVeh['location'], 'detail.php')
        && str_contains($pRdCrossDrv['location'], 'detail.php'),
        'rental/bookings/detail.php rejects cross-company vehicle & driver in update_detail and driver actions'
    );

    // Regulation & Rute form POST cross-company category
    $regF1 = $httpGet($jarAdmin, '/pages/transport/regulations/form.php');
    $csrfReg1 = $extractCsrf($regF1['body']);
    $beforeRegCnt = (int) $db->query('SELECT COUNT(*) FROM rental_car_regulation_employees')->fetch_row()[0];
    $pRegCross = $httpPost($jarAdmin, '/pages/transport/regulations/form.php', [
        'csrf_token'          => $csrfReg1,
        'id'                  => 0,
        'act'                 => 'save',
        'company_id'          => 1,
        'category_vehicle_id' => $cat5, // Company 5 category!
        'trip'                => 1,
        'start_date'          => '2026-01-01',
        'end_date'            => '9999-12-31',
    ]);
    $afterRegCnt = (int) $db->query('SELECT COUNT(*) FROM rental_car_regulation_employees')->fetch_row()[0];
    cs_check($pRegCross['code'] === 200 && $beforeRegCnt === $afterRegCnt,
        'transport/regulations/form.php rejects Company 5 category for Company 1');

    $rtF1 = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1 = $extractCsrf($rtF1['body']);
    $beforeRtCnt = (int) $db->query('SELECT COUNT(*) FROM rutes')->fetch_row()[0];
    $pRtCross = $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'             => $csrfRt1,
        'code'                   => "X{$uniq}",
        'name'                   => 'Cross Cat Route',
        'pickup_point_city_id'   => $cityA,
        'delivery_point_city_id' => $cityB,
        'category_vehicle_id'    => $cat5, // Company 5 category!
        'price'                  => '100000',
    ]);
    $afterRtCnt = (int) $db->query('SELECT COUNT(*) FROM rutes')->fetch_row()[0];
    cs_check($pRtCross['code'] === 200 && $beforeRtCnt === $afterRtCnt,
        'travel/rutes/form.php rejects Company 5 category when active=1');

    // Reject invalid non-ENUM departure_times[] slot & persist valid ENUM slots into separated rute_schedules
    $rtF1b = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1b = $extractCsrf($rtF1b['body']);
    $pRtBadSlot = $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'             => $csrfRt1b,
        'code'                   => "XBAD{$uniq}",
        'name'                   => 'Invalid Slot Route',
        'pickup_point_city_id'   => $cityA,
        'delivery_point_city_id' => $cityB,
        'category_vehicle_id'    => $cat1,
        'price'                  => '100000',
        'departure_times'        => ['07:30'],
    ]);
    $afterRtBadCnt = (int) $db->query('SELECT COUNT(*) FROM rutes')->fetch_row()[0];
    cs_check($pRtBadSlot['code'] === 200 && $afterRtBadCnt === $afterRtCnt,
        'travel/rutes/form.php rejects invalid non-ENUM departure_times[] (07:30)');

    $cat1RowB = $db->query("SELECT id FROM category_vehicles WHERE company_id = 1 AND is_active = 1 AND id <> {$cat1} ORDER BY id ASC LIMIT 1")->fetch_assoc();
    $cat1b = (int) ($cat1RowB['id'] ?? 0);

    $rtF1c = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    cs_check(
        str_contains($rtF1c['body'], 'id="tab-rute-detail"')
        && str_contains($rtF1c['body'], 'id="tab-rute-schedules"')
        && str_contains($rtF1c['body'], 'id="tab-rute-radius"')
        && str_contains($rtF1c['body'], 'id="tab-rute-audit"')
        && str_contains($rtF1c['body'], 'name="pickup_point_province_id"')
        && str_contains($rtF1c['body'], 'name="delivery_point_province_id"')
        && str_contains($rtF1c['body'], 'id="ruteTariffBody"')
        && str_contains($rtF1c['body'], 'name="tariff_category_vehicle_id[]"')
        && str_contains($rtF1c['body'], 'name="tariff_seat_capacity[]"')
        && str_contains($rtF1c['body'], 'id="ruteRadiusBody_pickup"')
        && str_contains($rtF1c['body'], 'id="ruteRadiusBody_delivery"')
        && str_contains($rtF1c['body'], 'btn-fill-city-districts')
        && str_contains($rtF1c['body'], 'Jumlah Kursi')
        && !str_contains($rtF1c['body'], 'Kursi Awal')
        && !str_contains($rtF1c['body'], 'Kursi Akhir'),
        'travel/rutes/form.php renders 4 tabs, origin/destination provinces, tariff list, district radius lists, and single Jumlah Kursi column'
    );
    cs_check(
        preg_match('/<button\b(?=[^>]*\bid="btnAddRuteTariff")(?=[^>]*\bclass="[^"]*\brnt-header-action\b)[^>]*>/i', $rtF1c['body']) === 1
        && (!str_contains($rtF1c['body'], 'id="btnFillAllTariffCats"') || preg_match('/<button\b(?=[^>]*\bid="btnFillAllTariffCats")(?=[^>]*\bclass="[^"]*\brnt-header-action\b)[^>]*>/i', $rtF1c['body']) === 1)
        && preg_match('/<button\b[^>]*\bclass="[^"]*\brnt-header-action\b[^"]*\bbtn-fill-city-districts\b/i', $rtF1c['body']) === 1
        && preg_match('/<button\b[^>]*\bclass="[^"]*\brnt-header-action\b[^"]*\bbtn-clear-radius-area\b/i', $rtF1c['body']) === 1
        && preg_match('/<button\b[^>]*\bclass="[^"]*\brnt-header-action\b[^"]*\bbtn-add-radius-row\b/i', $rtF1c['body']) === 1
        && preg_match('/<button\b[^>]*\bclass="[^"]*\brnt-header-action\b[^"]*\bbtn-rute-preset\b/i', $rtF1c['body']) === 1,
        'travel/rutes/form.php Tariff, Schedule & Radius panel header actions use .rnt-header-action inline styling'
    );
    $posRadiusPickup   = strpos($rtF1c['body'], 'id="ruteRadiusBody_pickup"');
    $posRadiusDelivery = strpos($rtF1c['body'], 'id="ruteRadiusBody_delivery"');
    cs_check(
        preg_match('/<div[^>]*\bid="tab-rute-radius"[^>]*>.*?<div class="row g-2">.*?<div class="col-12 col-lg-6">/s', $rtF1c['body']) === 1
        && $posRadiusPickup !== false
        && $posRadiusDelivery !== false
        && $posRadiusPickup < $posRadiusDelivery
        && str_contains($rtF1c['body'], 'id="ruteTariffDupWarn"')
        && str_contains($rtF1c['body'], 'data-no-autodismiss="1"')
        && str_contains($rtF1c['body'], 'id="ruteTariffDupMsg"')
        && str_contains($rtF1c['body'], 'syncTariffOptionsAvailability'),
        'travel/rutes/form.php renders Radius Penjemputan left & Radius Pengantaran right (col-12 col-lg-6) and enforces client duplicate-category validation'
    );
    $csrfRt1c = $extractCsrf($rtF1c['body']);
    $enumCode = "ENM{$uniq}";
    $postTariffCats  = [$cat1];
    $postTariffMins  = ['150000'];
    $postTariffPrcs  = ['180.000'];
    $postTariffMaxs  = ['220000'];
    $postTariffSeats = ['50'];
    $postTariffNotes = ['Catatan Cat 1'];
    if ($cat1b > 0) {
        $postTariffCats[]  = $cat1b;
        $postTariffMins[]  = '120000';
        $postTariffPrcs[]  = '145.000';
        $postTariffMaxs[]  = '180000';
        $postTariffSeats[] = '30';
        $postTariffNotes[] = 'Catatan Cat 2';
    }
    $pRtEnumOk = $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'                  => $csrfRt1c,
        'code'                        => $enumCode,
        'name'                        => "CSCOPE Enum Route {$uniq}",
        'pickup_point_city_id'        => $cityA,
        'delivery_point_city_id'      => $cityB,
        'tariff_category_vehicle_id'  => $postTariffCats,
        'tariff_min_price'            => $postTariffMins,
        'tariff_price'                => $postTariffPrcs,
        'tariff_max_price'            => $postTariffMaxs,
        'tariff_seat_capacity'        => $postTariffSeats,
        'tariff_note'                 => $postTariffNotes,
        'pickup_free_radius_km'       => '5',
        'pickup_max_radius_km'        => '15',
        'pickup_extra_price_per_km'   => '5000',
        'delivery_free_radius_km'     => '3',
        'delivery_max_radius_km'      => '10',
        'delivery_extra_price_per_km' => '7500',
        'radius_pickup_district_id'   => [$distA],
        'radius_pickup_extra_price'   => ['30.000'],
        'radius_pickup_note'          => ['Pinggiran jemput'],
        'radius_delivery_district_id' => [$distB],
        'radius_delivery_extra_price' => ['50.000'],
        'radius_delivery_note'        => ['Pinggiran antar'],
        'is_active'                   => '1',
        'departure_times'             => ['09:00', '07:00', '08:00', '07:00'],
    ]);
    $savedEnumId = (int) $db->query("SELECT id FROM rutes WHERE company_id = 1 AND code = '" . $db->real_escape_string($enumCode) . "' LIMIT 1")->fetch_column();
    if ($savedEnumId > 0) {
        $tempRuteIds[] = $savedEnumId;
    }
    $savedEnumSlots = $savedEnumId > 0 ? rute_schedule_times($savedEnumId, 1) : [];
    cs_check($savedEnumId > 0 && $savedEnumSlots === ['07:00', '08:00', '09:00'],
        'travel/rutes/form.php saves sorted ENUM slots [07:00, 08:00, 09:00] into rute_schedules');

    $savedTariffs = $savedEnumId > 0 ? rute_tariffs_for_rute($savedEnumId, 1) : [];
    $expectedTariffCount = $cat1b > 0 ? 2 : 1;
    cs_check(
        count($savedTariffs) === $expectedTariffCount
        && abs((float) ($savedTariffs[0]['price'] ?? 0) - 180000.0) < 0.01
        && (int) ($savedTariffs[0]['seat_capacity'] ?? 0) === 50
        && ($cat1b <= 0 || (abs((float) ($savedTariffs[1]['price'] ?? 0) - 145000.0) < 0.01 && (int) ($savedTariffs[1]['seat_capacity'] ?? 0) === 30)),
        'travel/rutes/form.php persists multi-category tariffs (prices, seat_capacity, notes) into rute_tariffs'
    );

    $savedRadiusMap = $savedEnumId > 0 ? rute_radius_for_rute($savedEnumId, 1) : ['pickup' => [], 'delivery' => []];
    cs_check(
        count($savedRadiusMap['pickup']) === 1
        && (int) ($savedRadiusMap['pickup'][0]['district_id'] ?? 0) === $distA
        && abs((float) ($savedRadiusMap['pickup'][0]['extra_price'] ?? 0) - 30000.0) < 0.01
        && count($savedRadiusMap['delivery']) === 1
        && (int) ($savedRadiusMap['delivery'][0]['district_id'] ?? 0) === $distB
        && abs((float) ($savedRadiusMap['delivery'][0]['extra_price'] ?? 0) - 50000.0) < 0.01,
        'travel/rutes/form.php saves pickup & delivery kecamatan radius list & extra_price into rute_radius_districts'
    );

    if ($cat1b > 0) {
        $lkCatB = $httpGet($jarAdmin, "/pages/travel/rutes/lookup.php?company_id=1&pickup_point_city_id={$cityA}&delivery_point_city_id={$cityB}&pickup_point_district_id={$distA}&delivery_point_district_id={$distB}&category_vehicle_id={$cat1b}");
        cs_check(
            $lkCatB['code'] === 200
            && !empty($lkCatB['json']['ok'])
            && !empty($lkCatB['json']['route']['category_matched'])
            && (int) ($lkCatB['json']['route']['id'] ?? 0) === $savedEnumId
            && abs((float) ($lkCatB['json']['route']['price'] ?? 0) - 145000.0) < 0.01
            && (int) ($lkCatB['json']['route']['seat_capacity'] ?? 0) === 30
            && count($lkCatB['json']['route']['tariffs'] ?? []) === 2
            && abs((float) ($lkCatB['json']['route']['pickup_district_extra'] ?? 0) - 30000.0) < 0.01
            && abs((float) ($lkCatB['json']['route']['delivery_district_extra'] ?? 0) - 50000.0) < 0.01
            && !empty($lkCatB['json']['route']['pickup_district_in_radius'])
            && !empty($lkCatB['json']['route']['delivery_district_in_radius'])
            && count($lkCatB['json']['route']['pickup_radius']['districts'] ?? []) === 1
            && count($lkCatB['json']['route']['delivery_radius']['districts'] ?? []) === 1
            && !empty($lkCatB['json']['route']['pickup_province_name']),
            'rutes/lookup.php matches secondary category tariff, seat_capacity, province metadata, and kecamatan radius extra fees'
        );

        // Enforce Harga Min & Harga Maks bounds (plus district radius surcharge 30k + 50k = 80k) on travel/bookings/form.php & detail.php
        $tfBoundPg = $httpGet($jarAdmin, '/pages/travel/bookings/form.php');
        $csrfBound = $extractCsrf($tfBoundPg['body']);
        $cntBeforeB = (int) $db->query('SELECT COUNT(*) FROM travel_ticket_bookings')->fetch_column();
        $postBoundLow = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, [
            'csrf_token'          => $csrfBound,
            'category_vehicle_id' => $cat1b,
            'vehicle_id'          => '',
            'total_price'         => '180000', // < Min 200.000 (120k + 80k radius)
            'date'                => '2027-09-10',
        ]));
        $postBoundHigh = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, [
            'csrf_token'          => $csrfBound,
            'category_vehicle_id' => $cat1b,
            'vehicle_id'          => '',
            'total_price'         => '280000', // > Max 260.000 (180k + 80k radius)
            'date'                => '2027-09-10',
        ]));
        $cntAfterInvalidB = (int) $db->query('SELECT COUNT(*) FROM travel_ticket_bookings')->fetch_column();
        cs_check(
            $postBoundLow['code'] === 200 && $postBoundHigh['code'] === 200
            && str_contains($postBoundLow['body'], 'Harga Min')
            && str_contains($postBoundHigh['body'], 'Harga Maks')
            && $cntBeforeB === $cntAfterInvalidB,
            'travel/bookings/form.php rejects prices below Harga Min + radius (180k < 200k) or above Harga Maks + radius (280k > 260k)'
        );

        $postBoundOk = $httpPost($jarAdmin, '/pages/travel/bookings/form.php', array_merge($baseTravelPost, [
            'csrf_token'          => $csrfBound,
            'category_vehicle_id' => $cat1b,
            'vehicle_id'          => '',
            'total_price'         => '225000', // 145k + 30k + 50k
            'date'                => '2027-09-11',
            'note'                => "CSCOPE_BOUND_{$uniq}",
        ]));
        $boundBkId = (int) $db->query("SELECT id FROM travel_ticket_bookings WHERE company_id = 1 AND note = '" . $db->real_escape_string("CSCOPE_BOUND_{$uniq}") . "' ORDER BY id DESC LIMIT 1")->fetch_column();
        if ($boundBkId > 0) {
            $tempTravelIds[] = $boundBkId;
            $tdBoundPg = $httpGet($jarAdmin, "/pages/travel/bookings/detail.php?id={$boundBkId}");
            $csrfTdB   = $extractCsrf($tdBoundPg['body']);
            $httpPost($jarAdmin, "/pages/travel/bookings/detail.php?id={$boundBkId}", [
                'csrf_token'                 => $csrfTdB,
                'act'                        => 'update_detail',
                'branch_id'                  => $br1,
                'date'                       => '2027-09-11',
                'time'                       => '09:00',
                'pickup_address'             => 'Test Jl 1',
                'pickup_point_district_id'   => $distA,
                'delivery_address'           => 'Test Jl 2',
                'delivery_point_district_id' => $distB,
                'total_price'                => '150000', // < Min 200.000
                'category_vehicle_id'        => $cat1b,
                'vehicle_id'                 => '',
                'passenger_id'               => $pax1Id,
            ]);
            $boundPriceAfter = (float) $db->query("SELECT total_price FROM travel_ticket_bookings WHERE id = {$boundBkId}")->fetch_column();
            cs_check(abs($boundPriceAfter - 225000.0) < 0.01,
                'travel/bookings/detail.php act=update_detail preserves 225k (145k + 80k radius) when rejecting out-of-range 150k');
        } else {
            cs_check(false, 'travel/bookings/form.php saves valid in-range 225k booking (145k + 80k radius) for detail bound check');
        }
    }

    $savedRadiusRow = $savedEnumId > 0
        ? $db->query("SELECT pickup_free_radius_km, pickup_max_radius_km, pickup_extra_price_per_km, delivery_free_radius_km, delivery_max_radius_km, delivery_extra_price_per_km FROM rutes WHERE id = {$savedEnumId} LIMIT 1")->fetch_assoc()
        : null;
    cs_check(
        $savedRadiusRow
        && abs((float) $savedRadiusRow['pickup_free_radius_km'] - 5.0) < 0.001
        && abs((float) $savedRadiusRow['pickup_max_radius_km'] - 15.0) < 0.001
        && abs((float) $savedRadiusRow['pickup_extra_price_per_km'] - 5000.0) < 0.001
        && abs((float) $savedRadiusRow['delivery_free_radius_km'] - 3.0) < 0.001
        && abs((float) $savedRadiusRow['delivery_max_radius_km'] - 10.0) < 0.001
        && abs((float) $savedRadiusRow['delivery_extra_price_per_km'] - 7500.0) < 0.001,
        'travel/rutes/form.php preserves legacy KM columns when supplied'
    );
    $rtIdx1 = $httpGet($jarAdmin, '/pages/travel/rutes/index.php?q=' . urlencode($enumCode));
    $distAName = (string) ($db->query("SELECT name FROM districts WHERE id = {$distA} LIMIT 1")->fetch_column() ?: '');
    $rtIdxDist = $distAName !== '' ? $httpGet($jarAdmin, '/pages/travel/rutes/index.php?q=' . urlencode($distAName)) : ['code' => 200, 'body' => $enumCode];
    cs_check(
        $rtIdx1['code'] === 200
        && str_contains($rtIdx1['body'], 'Radius Jemput / Antar')
        && str_contains($rtIdx1['body'], 'Tarif per Kategori Kendaraan')
        && str_contains($rtIdx1['body'], 'Rp 180.000')
        && str_contains($rtIdx1['body'], '(50 kursi)')
        && ($cat1b <= 0 || (str_contains($rtIdx1['body'], 'Rp 145.000') && str_contains($rtIdx1['body'], '(30 kursi)')))
        && str_contains($rtIdx1['body'], '1 kec.')
        && str_contains($rtIdx1['body'], '+Rp 30.000')
        && str_contains($rtIdx1['body'], '+Rp 50.000')
        && str_contains($rtIdxDist['body'], $enumCode),
        'travel/rutes/index.php renders Tarif per Kategori, Kecamatan Radius summary, and searches covered district names'
    );

    $rtF1dup = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1dup = $extractCsrf($rtF1dup['body']);
    $dupCode = "DUPCAT{$uniq}";
    $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'                 => $csrfRt1dup,
        'code'                       => $dupCode,
        'name'                       => "CSCOPE Dup Cat {$uniq}",
        'pickup_point_city_id'       => $cityA,
        'delivery_point_city_id'     => $cityB,
        'tariff_category_vehicle_id' => [$cat1, $cat1],
        'tariff_price'               => ['100000', '120000'],
        'is_active'                  => '1',
    ]);
    $dupId = (int) $db->query("SELECT id FROM rutes WHERE company_id = 1 AND code = '" . $db->real_escape_string($dupCode) . "' LIMIT 1")->fetch_column();
    if ($dupId > 0) {
        $tempRuteIds[] = $dupId;
    }
    cs_check($dupId === 0, 'travel/rutes/form.php rejects duplicate category_vehicle_id rows in tariff list');

    $rtF1d = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1d = $extractCsrf($rtF1d['body']);
    $badRadCode = "RADERR{$uniq}";
    $pRtBadRad = $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'             => $csrfRt1d,
        'active_tab'             => 'radius',
        'code'                   => $badRadCode,
        'name'                   => "CSCOPE Bad Radius {$uniq}",
        'pickup_point_city_id'   => $cityA,
        'delivery_point_city_id' => $cityB,
        'category_vehicle_id'    => $cat1,
        'price'                  => '180000',
        'pickup_free_radius_km'  => '20',
        'pickup_max_radius_km'   => '10',
        'is_active'              => '1',
    ]);
    $badRadId = (int) $db->query("SELECT id FROM rutes WHERE company_id = 1 AND code = '" . $db->real_escape_string($badRadCode) . "' LIMIT 1")->fetch_column();
    if ($badRadId > 0) {
        $tempRuteIds[] = $badRadId;
    }
    cs_check($badRadId === 0, 'travel/rutes/form.php rejects pickup_free_radius_km > pickup_max_radius_km');

    $rtF1e = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1e = $extractCsrf($rtF1e['body']);
    $dupRadCode = "RDUP{$uniq}";
    $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'                  => $csrfRt1e,
        'active_tab'                  => 'radius',
        'code'                        => $dupRadCode,
        'name'                        => "CSCOPE Dup Rad {$uniq}",
        'pickup_point_city_id'        => $cityA,
        'delivery_point_city_id'      => $cityB,
        'category_vehicle_id'         => $cat1,
        'price'                       => '180000',
        'radius_pickup_district_id'   => [$distA, $distA],
        'radius_pickup_extra_price'   => ['30000', '50000'],
        'is_active'                   => '1',
    ]);
    $dupRadId = (int) $db->query("SELECT id FROM rutes WHERE company_id = 1 AND code = '" . $db->real_escape_string($dupRadCode) . "' LIMIT 1")->fetch_column();
    if ($dupRadId > 0) {
        $tempRuteIds[] = $dupRadId;
    }
    cs_check($dupRadId === 0, 'travel/rutes/form.php rejects duplicate district_id in radius_pickup_district_id[]');

    $rtF1f = $httpGet($jarAdmin, '/pages/travel/rutes/form.php');
    $csrfRt1f = $extractCsrf($rtF1f['body']);
    $negRadCode = "RNEG{$uniq}";
    $httpPost($jarAdmin, '/pages/travel/rutes/form.php', [
        'csrf_token'                  => $csrfRt1f,
        'active_tab'                  => 'radius',
        'code'                        => $negRadCode,
        'name'                        => "CSCOPE Neg Rad {$uniq}",
        'pickup_point_city_id'        => $cityA,
        'delivery_point_city_id'      => $cityB,
        'category_vehicle_id'         => $cat1,
        'price'                       => '180000',
        'radius_pickup_district_id'   => [$distA],
        'radius_pickup_extra_price'   => ['-10000'],
        'is_active'                   => '1',
    ]);
    $negRadId = (int) $db->query("SELECT id FROM rutes WHERE company_id = 1 AND code = '" . $db->real_escape_string($negRadCode) . "' LIMIT 1")->fetch_column();
    if ($negRadId > 0) {
        $tempRuteIds[] = $negRadId;
    }
    cs_check($negRadId === 0, 'travel/rutes/form.php rejects negative extra_price in radius_pickup_extra_price[]');

    $rolesRes = $httpGet($jarAdmin, '/pages/roles/index.php');
    $rolesOk = $rolesRes['code'] === 200;
    $rolesBtnCnt = 0;
    if ($rolesOk && preg_match('#<tbody\b[^>]*>(.*?)</tbody>#is', $rolesRes['body'], $rtb)
        && preg_match_all('#<td\b[^>]*text-end[^>]*>(.*?)</td>#is', $rtb[1], $rtds)) {
        foreach ($rtds[1] as $rtd) {
            if (preg_match_all('#<(a|button)\b([^>]*)>(.*?)</\1>#is', $rtd, $rbm, PREG_SET_ORDER)) {
                foreach ($rbm as $m) {
                    $rolesBtnCnt++;
                    $txt = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
                    if ($txt !== '' || stripos($m[2], 'title=') === false) {
                        $rolesOk = false;
                    }
                }
            }
        }
    }
    cs_check($rolesOk && $rolesBtnCnt > 0, "roles/index.php renders icon-only Aksi buttons ({$rolesBtnCnt} buttons checked)");

    // D. Restricted User (only Company 1 access) cannot force ?company_id=5 or quick_create into 5
    $rUname = "cscope_u_{$uniq}";
    $rEmail = "cscope_u_{$uniq}@example.test";
    $rPass  = 'Cscope@123';
    $rHash  = password_hash($rPass, PASSWORD_BCRYPT);
    $stU = $db->prepare("INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, 'CSCOPE Restricted User', 2, 1, 1)");
    $stU->bind_param('sss', $rUname, $rEmail, $rHash);
    $stU->execute();
    $tempUserId = (int) $db->insert_id;
    $stU->close();
    $db->query("INSERT INTO user_companies (user_id, company_id) VALUES ({$tempUserId}, 1)");

    cs_check($loginJar($jarRestricted, $rUname, $rPass), 'Restricted Company-1 Admin HTTP login succeeded');
    $restrVo5 = $httpGet($jarRestricted, "/pages/vehicles/options.php?q={$uniq}&company_id=5");
    $restrVo5Json = is_array($restrVo5['json']) ? json_encode($restrVo5['json']) : '';
    cs_check($restrVo5['code'] === 200 && str_contains($restrVo5Json, $v1Plate) && !str_contains($restrVo5Json, $v5Plate),
        'Restricted Company-1 user requesting vehicles/options.php?company_id=5 falls back to Company 1 and never leaks Company 5');
    $restrPax5 = $httpGet($jarRestricted, '/pages/transport/passengers/index.php?company_id=5');
    cs_check($restrPax5['code'] === 200 && !str_contains($restrPax5['body'], $p5Name),
        'Restricted Company-1 user requesting passengers/index.php?company_id=5 never sees Company 5 data');
    $restrTf = $httpGet($jarRestricted, '/pages/travel/bookings/form.php');
    $restrQc5 = $httpPost($jarRestricted, '/pages/transport/passengers/quick_create.php', [
        'csrf_token'   => $extractCsrf($restrTf['body']),
        'company_id'   => 5,
        'name'         => "CSCOPE RestrQuick {$uniq}",
        'phone_number' => '812888000',
    ]);
    $restrQc5Id = (int) ($restrQc5['json']['data']['id'] ?? 0);
    if ($restrQc5Id > 0) {
        $tempPaxIds[] = $restrQc5Id;
    }
    $restrQcRow = $restrQc5Id > 0 ? $db->query("SELECT company_id FROM passengers WHERE id = {$restrQc5Id}")->fetch_assoc() : null;
    cs_check($restrQc5['code'] === 200 && (int) ($restrQcRow['company_id'] ?? 0) === 1,
        'Restricted Company-1 user quick_create with spoofed company_id=5 falls back to active company 1');
} finally {
    foreach ($tempTravelIds as $id) {
        try { booking_delete('travel', $id, 1); } catch (Throwable $ex) {}
    }
    foreach ($tempRentalIds as $id) {
        try { booking_delete('rental', $id, 1); } catch (Throwable $ex) {}
    }
    if ($tempRegIds) {
        $in = implode(',', array_map('intval', $tempRegIds));
        $db->query("DELETE FROM rental_car_regulation_employee_details WHERE regulation_id IN ({$in})");
        $db->query("DELETE FROM rental_car_regulation_employees WHERE id IN ({$in})");
    }
    if ($tempSchedIds) {
        $in = implode(',', array_map('intval', $tempSchedIds));
        $db->query("DELETE FROM rute_schedules WHERE id IN ({$in})");
    }
    if ($tempRuteIds) {
        $in = implode(',', array_map('intval', $tempRuteIds));
        $db->query("DELETE FROM rute_schedules WHERE rute_id IN ({$in})");
        $db->query("DELETE FROM rutes WHERE id IN ({$in})");
    }
    if ($tempEmpIds) {
        $in = implode(',', array_map('intval', $tempEmpIds));
        $db->query("DELETE FROM employees WHERE id IN ({$in})");
    }
    if ($tempPaxIds) {
        $in = implode(',', array_map('intval', $tempPaxIds));
        foreach ($db->query("SELECT DISTINCT tb.id, tb.company_id FROM travel_ticket_bookings tb LEFT JOIN booking_passengers bp ON bp.booking_id = tb.id AND bp.booking_type = 'travel' WHERE tb.passenger_id IN ({$in}) OR bp.passenger_id IN ({$in})")->fetch_all(MYSQLI_ASSOC) as $ob) {
            try { booking_delete('travel', (int) $ob['id'], (int) $ob['company_id']); } catch (Throwable $ex) {}
        }
        $db->query("DELETE FROM passengers WHERE id IN ({$in})");
    }
    if ($tempVehIds) {
        $in = implode(',', array_map('intval', $tempVehIds));
        $db->query("DELETE FROM vehicles WHERE id IN ({$in})");
    }
    if ($tempUserId > 0) {
        $db->query("DELETE FROM user_companies WHERE user_id = {$tempUserId}");
        $db->query("DELETE FROM users WHERE id = {$tempUserId}");
    }
    @unlink($jarAdmin);
    @unlink($jarRestricted);
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
