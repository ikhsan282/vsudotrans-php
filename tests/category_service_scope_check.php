<?php
/**
 * Self-test pemisahan Kategori Layanan (`service_type` = travel|rental) & satuan tarif (`pricing_unit`).
 * Jalankan: php tests/category_service_scope_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/transport_helpers.php';
require_once __DIR__ . '/../includes/bot_auth.php';

$pass = 0;
$fail = 0;

function check_cat(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function call_routes_bot(array $query, string $secret): array
{
    $php     = PHP_BINARY;
    $target  = realpath(__DIR__ . '/../api/bot/routes.php');
    $runner  = sys_get_temp_dir() . '/vsudo_cat_routes_' . getmypid() . '.php';
    $code    = '<?php' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
        . '$_SERVER["HTTP_HOST"] = "localhost:8080";' . "\n"
        . '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";' . "\n"
        . '$_SERVER["HTTP_X_BOT_PHONE"] = "6281200000002";' . "\n"
        . '$_SERVER["HTTP_AUTHORIZATION"] = ' . var_export('Bearer ' . $secret, true) . ';' . "\n"
        . '$_GET = ' . var_export($query, true) . ';' . "\n"
        . 'register_shutdown_function(function(){ echo "\n__HTTP_CODE__:" . http_response_code(); });' . "\n"
        . 'require ' . var_export($target, true) . ';';
    file_put_contents($runner, $code);
    $out = (string) shell_exec('"' . $php . '" "' . $runner . '" 2>&1');
    @unlink($runner);
    $httpCode = 0;
    if (preg_match('/__HTTP_CODE__:(\d+)\s*$/', $out, $m)) {
        $httpCode = (int) $m[1];
        $out      = substr($out, 0, -strlen($m[0]));
    }
    $pos = strpos($out, '{"ok":');
    $dec = json_decode(trim($pos !== false ? substr($out, $pos) : $out), true);
    return ['status' => $httpCode, 'body' => is_array($dec) ? $dec : ['raw' => $out]];
}

echo "=== Test Pemisahan Kategori Layanan (Travel vs Rental) & Satuan Tarif ===\n";

$db = db();
category_service_ensure_schema();

// 1. Skema DB & Index
$colSvc  = $db->query("SHOW COLUMNS FROM category_vehicles LIKE 'service_type'")->fetch_assoc();
$colUnit = $db->query("SHOW COLUMNS FROM category_vehicles LIKE 'pricing_unit'")->fetch_assoc();
$idxSvc  = $db->query("SHOW INDEX FROM category_vehicles WHERE Key_name = 'ix_category_vehicles_service'")->num_rows;
check_cat(!empty($colSvc) && !empty($colUnit) && $idxSvc > 0, 'category_vehicles has service_type, pricing_unit columns & index');

// 2. Backfill akurat di semua 5 perusahaan
$allCats = $db->query("SELECT id, company_id, code, service_type, pricing_unit FROM category_vehicles")->fetch_all(MYSQLI_ASSOC);
$expectedUnits = [
    'REG' => ['travel', 'per_seat'],
    'PKT' => ['travel', 'per_package'],
    'HWN' => ['travel', 'per_package'],
    'CMB' => ['travel', 'per_vehicle'],
    'CHC' => ['travel', 'per_vehicle'],
    'CEL' => ['travel', 'per_vehicle'],
    'SMV' => ['rental', 'per_day'],
    'SHC' => ['rental', 'per_day'],
    'SEL' => ['rental', 'per_day'],
    'SMB' => ['rental', 'per_day'],
    'SBB' => ['rental', 'per_day'],
];
$backfillOk = count($allCats) >= 55;
$badItems   = [];
foreach ($allCats as $cv) {
    $code = strtoupper((string) $cv['code']);
    if (isset($expectedUnits[$code])) {
        [$expSvc, $expUnit] = $expectedUnits[$code];
        if ($cv['service_type'] !== $expSvc || $cv['pricing_unit'] !== $expUnit) {
            $backfillOk = false;
            $badItems[] = "{$code}: got {$cv['service_type']}/{$cv['pricing_unit']}";
        }
    }
}
check_cat($backfillOk, 'All seeded categories across 5 companies backfilled with exact service_type & pricing_unit', implode('; ', $badItems));

// 3. Filter dropdown opts_categories
$travelCats = opts_categories(1, [], 200, 'travel');
$rentalCats = opts_categories(1, [], 200, 'rental');
$trvSvcs    = array_unique(array_column($travelCats, 'service_type'));
$rntSvcs    = array_unique(array_column($rentalCats, 'service_type'));
check_cat($trvSvcs === ['travel'] && count($travelCats) === 6, 'opts_categories(..., travel) returns strictly 6 travel categories', json_encode($trvSvcs));
check_cat($rntSvcs === ['rental'] && count($rentalCats) === 5, 'opts_categories(..., rental) returns strictly 5 rental categories', json_encode($rntSvcs));

// Ambil ID spesifik di MTM (company 1)
$mtmByCode = [];
foreach ($allCats as $cv) {
    if ((int) $cv['company_id'] === 1) {
        $mtmByCode[strtoupper((string) $cv['code'])] = (int) $cv['id'];
    }
}
$idReg = $mtmByCode['REG'] ?? 0;
$idCmb = $mtmByCode['CMB'] ?? 0;
$idPkt = $mtmByCode['PKT'] ?? 0;
$idSbb = $mtmByCode['SBB'] ?? 0;

$mtmBranch = (int) $db->query("SELECT id FROM branches WHERE company_id = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_column();
$mtmPax    = (int) $db->query("SELECT id FROM passengers WHERE company_id = 1 ORDER BY id ASC LIMIT 1")->fetch_column();

// 4. Validasi cross-scope pada validate_booking_company_scope & rute_tariffs_normalize_and_validate
$rejectedSbbOnTravel = false;
try {
    $catTmp = $idSbb;
    validate_booking_company_scope(1, $mtmBranch, $mtmPax, [], $catTmp, null, [], 'travel');
} catch (InvalidArgumentException $ex) {
    $rejectedSbbOnTravel = str_contains($ex->getMessage(), 'Rental');
}
check_cat($rejectedSbbOnTravel, 'validate_booking_company_scope rejects SBB (rental) when booking travel');

$rejectedRegOnRental = false;
try {
    $catTmp = $idReg;
    validate_booking_company_scope(1, $mtmBranch, $mtmPax, [], $catTmp, null, [], 'rental');
} catch (InvalidArgumentException $ex) {
    $rejectedRegOnRental = str_contains($ex->getMessage(), 'Travel');
}
check_cat($rejectedRegOnRental, 'validate_booking_company_scope rejects REG (travel) when booking rental');

$acceptedValid = true;
try {
    $catT = $idReg;
    validate_booking_company_scope(1, $mtmBranch, $mtmPax, [], $catT, null, [], 'travel');
    $catR = $idSbb;
    validate_booking_company_scope(1, $mtmBranch, $mtmPax, [], $catR, null, [], 'rental');
} catch (Throwable $ex) {
    $acceptedValid = false;
}
check_cat($acceptedValid, 'validate_booking_company_scope accepts matching travel & rental categories');

$acceptedTravelAndRentalRute = false;
try {
    $normMix = rute_tariffs_normalize_and_validate([
        ['category_vehicle_id' => $idReg, 'price' => 150000],
        ['category_vehicle_id' => $idSbb, 'price' => 3500000],
    ], 1);
    $acceptedTravelAndRentalRute = count($normMix) === 2;
} catch (Throwable $ex) {
    $acceptedTravelAndRentalRute = false;
}
check_cat($acceptedTravelAndRentalRute, 'rute_tariffs_normalize_and_validate accepts both Travel (REG) and Rental (SBB) categories for company 1');

$otherCidCatId = (int) $db->query("SELECT id FROM category_vehicles WHERE company_id <> 1 AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_column();
if ($otherCidCatId > 0) {
    $rejectedCrossCompanyRute = false;
    try {
        rute_tariffs_normalize_and_validate([['category_vehicle_id' => $otherCidCatId, 'price' => 500000]], 1);
    } catch (InvalidArgumentException $ex) {
        $rejectedCrossCompanyRute = true;
    }
    check_cat($rejectedCrossCompanyRute, 'rute_tariffs_normalize_and_validate rejects cross-company category IDs');
}

// 5. Quote & price bounds per pricing_unit pada rute MTM
$routeRow = $db->query("SELECT * FROM rutes WHERE company_id = 1 AND code = 'TNG-BDG' AND is_active = 1 LIMIT 1")->fetch_assoc();
if (!$routeRow) {
    $routeRow = $db->query("SELECT r.* FROM rutes r JOIN rute_tariffs rt1 ON rt1.rute_id = r.id AND rt1.category_vehicle_id = {$idReg} JOIN rute_tariffs rt2 ON rt2.rute_id = r.id AND rt2.category_vehicle_id = {$idCmb} JOIN rute_tariffs rt3 ON rt3.rute_id = r.id AND rt3.category_vehicle_id = {$idPkt} WHERE r.company_id = 1 AND r.is_active = 1 LIMIT 1")->fetch_assoc();
}
check_cat(is_array($routeRow) && !empty($routeRow['id']), 'Found active MTM route with REG, CMB, and PKT tariffs');

if ($routeRow) {
    $qReg = rute_calculate_travel_quote(1, $routeRow, $idReg, 4, 0, 0);
    check_cat(
        $qReg['pricing_unit'] === 'per_seat' && (int) $qReg['billable_qty'] === 4 && abs($qReg['subtotal'] - ($qReg['unit_price'] * 4)) < 0.01,
        'rute_calculate_travel_quote multiplies unit_price x 4 for REG (per_seat)',
        json_encode($qReg)
    );

    $qCmb = rute_calculate_travel_quote(1, $routeRow, $idCmb, 4, 0, 0);
    check_cat(
        $qCmb['pricing_unit'] === 'per_vehicle' && (int) $qCmb['billable_qty'] === 1 && abs($qCmb['subtotal'] - $qCmb['unit_price']) < 0.01,
        'rute_calculate_travel_quote keeps billable_qty=1 for CMB (per_vehicle) even with 4 seats',
        json_encode($qCmb)
    );

    $qPkt = rute_calculate_travel_quote(1, $routeRow, $idPkt, 4, 0, 0);
    check_cat(
        $qPkt['pricing_unit'] === 'per_package' && (int) $qPkt['billable_qty'] === 4 && abs($qPkt['subtotal'] - ($qPkt['unit_price'] * 4)) < 0.01,
        'rute_calculate_travel_quote multiplies unit_price x 4 for PKT (per_package)',
        json_encode($qPkt)
    );

    $lkCmb = travel_lookup_route_tariff(1, (int) $routeRow['pickup_point_city_id'], (int) $routeRow['delivery_point_city_id'], $idCmb);
    check_cat(
        is_array($lkCmb) && ($lkCmb['pricing_unit'] ?? '') === 'per_vehicle' && ($lkCmb['pricing_unit_short'] ?? '') === '/mobil',
        'travel_lookup_route_tariff returns pricing_unit=per_vehicle and pricing_unit_short=/mobil',
        json_encode(['unit' => $lkCmb['pricing_unit'] ?? null, 'short' => $lkCmb['pricing_unit_short'] ?? null])
    );

    // Uji batas harga min/max rute untuk per_vehicle (4 penumpang bayar harga 1 mobil harus lolos)
    $cmbBoundsOk = true;
    try {
        travel_validate_route_price_bounds(
            1,
            (int) $routeRow['pickup_point_city_id'],
            (int) $routeRow['delivery_point_city_id'],
            $idCmb,
            4,
            (float) $qCmb['unit_price']
        );
    } catch (Throwable $ex) {
        $cmbBoundsOk = false;
    }
    check_cat($cmbBoundsOk, 'travel_validate_route_price_bounds accepts 1-vehicle price for CMB with 4 passengers');

    // Jika rute punya min_price > 0 untuk REG, pastikan 4 penumpang dengan harga 1 kursi ditolak
    $lkReg = travel_lookup_route_tariff(1, (int) $routeRow['pickup_point_city_id'], (int) $routeRow['delivery_point_city_id'], $idReg);
    if (!empty($lkReg['min_price']) && (float) $lkReg['min_price'] > 0) {
        $regBelowMinRejected = false;
        try {
            travel_validate_route_price_bounds(
                1,
                (int) $routeRow['pickup_point_city_id'],
                (int) $routeRow['delivery_point_city_id'],
                $idReg,
                4,
                (float) $lkReg['min_price']
            );
        } catch (InvalidArgumentException $ex) {
            $regBelowMinRejected = true;
        }
        check_cat($regBelowMinRejected, 'travel_validate_route_price_bounds rejects 1-seat total when REG has 4 passengers');
    }

    // 6. Bot /api/bot/routes.php?quote=1
    $secretMtm = (string) $db->query("SELECT bot_secret FROM companies WHERE id = 1 LIMIT 1")->fetch_column();
    $botRes = call_routes_bot([
        'quote'               => 1,
        'rute_id'             => (int) $routeRow['id'],
        'category_vehicle_id' => $idCmb,
        'seats'               => 4,
    ], $secretMtm);
    $botData  = $botRes['body']['data'] ?? [];
    $botQuote = $botData['quote'] ?? [];
    $catHasScope = !empty($botData['vehicle_categories'][0]['service_type']) && !empty($botData['vehicle_categories'][0]['pricing_unit']);
    $tfHasUnit   = !empty($botData['routes'][0]['tariffs'][0]['pricing_unit_short']);
    check_cat(
        $botRes['status'] === 200
        && $catHasScope
        && $tfHasUnit
        && ($botQuote['pricing_unit'] ?? '') === 'per_vehicle'
        && (int) ($botQuote['billable_qty'] ?? 0) === 1
        && abs((float) ($botQuote['subtotal']['raw'] ?? 0) - (float) ($botQuote['unit_price']['raw'] ?? -1)) < 0.01,
        'GET /api/bot/routes.php exposes service_type/pricing_unit & computes CMB 4-seat quote with billable_qty=1',
        json_encode($botQuote)
    );
}

// 7. Uji Rute & Tarif untuk Rental (durasi hari inklusif + biaya radius kecamatan + bot quote)
check_cat(
    rental_duration_days('2026-11-20', '2026-11-20') === 1 && rental_duration_days('2026-11-20', '2026-11-22') === 3,
    'rental_duration_days uses inclusive day count (same date=1, 20..22=3)'
);

if ($idSbb > 0) {
    $pairRow = $db->query("
        SELECT c1.id AS pk_city_id, c2.id AS dl_city_id, d.id AS dl_dist_id
        FROM cities c1
        JOIN cities c2 ON c2.id <> c1.id
        JOIN districts d ON d.city_id = c2.id
        WHERE NOT EXISTS (
            SELECT 1 FROM rutes r
            WHERE r.company_id = 1
              AND r.pickup_point_city_id = c1.id
              AND r.delivery_point_city_id = c2.id
        )
        ORDER BY c1.id ASC, c2.id ASC, d.id ASC
        LIMIT 1
    ")->fetch_assoc();

    $tmpPkCity = (int) ($pairRow['pk_city_id'] ?? 0);
    $tmpDlCity = (int) ($pairRow['dl_city_id'] ?? 0);
    $tmpDistId = (int) ($pairRow['dl_dist_id'] ?? 0);
    check_cat($tmpPkCity > 0 && $tmpDlCity > 0 && $tmpDistId > 0, 'Found unused city pair with delivery district for temporary rental route test');

    if ($tmpPkCity > 0 && $tmpDlCity > 0 && $tmpDistId > 0) {
        $tmpRuteId = 0;
        try {
            $tmpCode = 'TMP' . substr((string) time(), -6);
            $stInsR = $db->prepare('INSERT INTO rutes (company_id, code, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, min_price, price, max_price, is_active, created_user) VALUES (1, ?, ?, ?, ?, ?, 3000000, 3500000, 4500000, 1, "test")');
            $tmpName = 'Temp Rental Test Route';
            $stInsR->bind_param('ssiii', $tmpCode, $tmpName, $tmpPkCity, $tmpDlCity, $idSbb);
            $stInsR->execute();
            $tmpRuteId = (int) $db->insert_id;
            $stInsR->close();

            $stInsT = $db->prepare('INSERT INTO rute_tariffs (company_id, rute_id, category_vehicle_id, min_price, price, max_price, sort_order, created_user) VALUES (1, ?, ?, 3000000, 3500000, 4500000, 0, "test")');
            $stInsT->bind_param('ii', $tmpRuteId, $idSbb);
            $stInsT->execute();
            $stInsT->close();

            $stInsD = $db->prepare('INSERT INTO rute_radius_districts (company_id, rute_id, radius_type, district_id, extra_price, note, sort_order, created_user) VALUES (1, ?, "delivery", ?, 300000, "Area Puncak Test", 0, "test")');
            $stInsD->bind_param('ii', $tmpRuteId, $tmpDistId);
            $stInsD->execute();
            $stInsD->close();

            $tmpRouteRow = $db->query("SELECT * FROM rutes WHERE id = {$tmpRuteId} LIMIT 1")->fetch_assoc();
            $qSbb = rute_calculate_travel_quote(1, $tmpRouteRow, $idSbb, 1, 0, $tmpDistId, 3);
            check_cat(
                ($qSbb['pricing_unit'] ?? '') === 'per_day'
                && (int) ($qSbb['billable_qty'] ?? 0) === 3
                && abs((float) ($qSbb['subtotal'] ?? 0) - 10500000.0) < 0.01
                && abs((float) ($qSbb['radius_extra'] ?? 0) - 300000.0) < 0.01
                && abs((float) ($qSbb['total'] ?? 0) - 10800000.0) < 0.01,
                'rute_calculate_travel_quote computes 3 days per_day (10.5M) + delivery radius (300k) = 10.8M',
                json_encode($qSbb)
            );

            $lkRental = travel_lookup_route_tariff(1, $tmpPkCity, $tmpDlCity, $idSbb, 0, $tmpDistId, 'rental');
            check_cat(
                is_array($lkRental)
                && (int) ($lkRental['category_vehicle_id'] ?? 0) === $idSbb
                && abs((float) ($lkRental['delivery_district_extra'] ?? 0) - 300000.0) < 0.01,
                'travel_lookup_route_tariff returns SBB tariff and delivery_district_extra=300000 for rental service_type',
                json_encode($lkRental)
            );

            if ($routeRow) {
                $lkTravelOnlyAsRental = travel_lookup_route_tariff(
                    1,
                    (int) $routeRow['pickup_point_city_id'],
                    (int) $routeRow['delivery_point_city_id'],
                    $idSbb,
                    0,
                    0,
                    'rental'
                );
                check_cat(
                    $lkTravelOnlyAsRental === null,
                    'travel_lookup_route_tariff returns null on Travel-only route when queried with service_type=rental'
                );
            }

            $acceptedExact = true;
            try {
                travel_validate_route_price_bounds(1, $tmpPkCity, $tmpDlCity, $idSbb, 3, 10800000.0, 0, $tmpDistId, 'rental');
            } catch (Throwable $ex) {
                $acceptedExact = false;
            }
            $rejectedTooLow = false;
            try {
                travel_validate_route_price_bounds(1, $tmpPkCity, $tmpDlCity, $idSbb, 3, 9000000.0, 0, $tmpDistId, 'rental');
            } catch (InvalidArgumentException $ex) {
                $rejectedTooLow = true;
            }
            $rejectedTooHigh = false;
            try {
                travel_validate_route_price_bounds(1, $tmpPkCity, $tmpDlCity, $idSbb, 3, 14000000.0, 0, $tmpDistId, 'rental');
            } catch (InvalidArgumentException $ex) {
                $rejectedTooHigh = true;
            }
            check_cat(
                $acceptedExact && $rejectedTooLow && $rejectedTooHigh,
                'travel_validate_route_price_bounds(..., rental) accepts 10.8M and rejects 9M / 14M',
                json_encode(['exact' => $acceptedExact, 'low' => $rejectedTooLow, 'high' => $rejectedTooHigh])
            );

            if ($secretMtm !== '') {
                $botRentalRes = call_routes_bot([
                    'quote'                => 1,
                    'rute_id'              => $tmpRuteId,
                    'category_vehicle_id'  => $idSbb,
                    'start_date'           => '2026-11-20',
                    'end_date'             => '2026-11-22',
                    'delivery_district_id' => $tmpDistId,
                ], $secretMtm);
                $botRentalQuote = $botRentalRes['body']['data']['quote'] ?? [];
                check_cat(
                    $botRentalRes['status'] === 200
                    && ($botRentalQuote['pricing_unit'] ?? '') === 'per_day'
                    && (int) ($botRentalQuote['billable_qty'] ?? 0) === 3
                    && (int) ($botRentalQuote['rental_days'] ?? 0) === 3
                    && abs((float) ($botRentalQuote['radius_extra']['raw'] ?? 0) - 300000.0) < 0.01
                    && abs((float) ($botRentalQuote['total']['raw'] ?? 0) - 10800000.0) < 0.01,
                    'GET /api/bot/routes.php?quote=1 calculates 3-day rental + 300k delivery district radius = 10.8M',
                    json_encode($botRentalQuote)
                );
            }
        } finally {
            if ($tmpRuteId > 0) {
                $db->query("DELETE FROM rute_tariffs WHERE rute_id = {$tmpRuteId}");
                $db->query("DELETE FROM rute_radius_districts WHERE rute_id = {$tmpRuteId}");
                $db->query("DELETE FROM rutes WHERE id = {$tmpRuteId}");
            }
        }
    }
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
