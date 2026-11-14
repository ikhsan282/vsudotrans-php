<?php
/**
 * AJAX Endpoint: Lookup tarif rute aktif berbasis pasangan kota asal & tujuan + indeks ix_rutes_lookup.
 * Aman hingga jutaan data karena hanya mengambil 1 baris paling cocok via prepared statement LIMIT 1.
 * GET: pickup_point_city_id (atau pickup_city_id), delivery_point_city_id (atau delivery_city_id), company_id (opsional), category_vehicle_id (opsional)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!can('travel.view')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$activeCid = (int) (active_company_id() ?? 0);
$reqCid    = (int) ($_GET['company_id'] ?? $activeCid);
$allowed   = accessible_company_ids($activeCid);

if ($reqCid > 0 && (is_super_admin() || in_array($reqCid, $allowed, true))) {
    $companyId = $reqCid;
} else {
    $companyId = $activeCid;
}

$pickupCityId   = (int) ($_GET['pickup_point_city_id'] ?? $_GET['pickup_city_id'] ?? 0);
$deliveryCityId = (int) ($_GET['delivery_point_city_id'] ?? $_GET['delivery_city_id'] ?? 0);
$pickupDistId   = (int) ($_GET['pickup_point_district_id'] ?? $_GET['pickup_district_id'] ?? 0);
$deliveryDistId = (int) ($_GET['delivery_point_district_id'] ?? $_GET['delivery_district_id'] ?? 0);
$catId          = (int) ($_GET['category_vehicle_id'] ?? 0);

$route = travel_lookup_route_tariff($companyId, $pickupCityId, $deliveryCityId, $catId, $pickupDistId, $deliveryDistId);

echo json_encode(['ok' => true, 'route' => $route], JSON_UNESCAPED_UNICODE);
