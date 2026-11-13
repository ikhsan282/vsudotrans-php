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
$catId          = (int) ($_GET['category_vehicle_id'] ?? 0);

if ($companyId <= 0 || $pickupCityId <= 0 || $deliveryCityId <= 0) {
    echo json_encode(['ok' => true, 'route' => null]);
    exit;
}

$db = db();
$route = null;

if ($catId > 0) {
    $stCat = $db->prepare(
        'SELECT id, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, price
           FROM rutes
          WHERE company_id = ? AND pickup_point_city_id = ? AND delivery_point_city_id = ? AND is_active = 1 AND category_vehicle_id = ?
          ORDER BY id ASC
          LIMIT 1'
    );
    $stCat->bind_param('iiii', $companyId, $pickupCityId, $deliveryCityId, $catId);
    $stCat->execute();
    $route = $stCat->get_result()->fetch_assoc() ?: null;
    $stCat->close();
}

if (!$route) {
    $st = $db->prepare(
        'SELECT id, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, price
           FROM rutes
          WHERE company_id = ? AND pickup_point_city_id = ? AND delivery_point_city_id = ? AND is_active = 1
          ORDER BY id ASC
          LIMIT 1'
    );
    $st->bind_param('iii', $companyId, $pickupCityId, $deliveryCityId);
    $st->execute();
    $route = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}

if ($route) {
    $route = [
        'id'                     => (int) $route['id'],
        'name'                   => (string) $route['name'],
        'pickup_point_city_id'   => (int) $route['pickup_point_city_id'],
        'delivery_point_city_id' => (int) $route['delivery_point_city_id'],
        'category_vehicle_id'    => (int) $route['category_vehicle_id'],
        'price'                  => (float) $route['price'],
    ];
}

echo json_encode(['ok' => true, 'route' => $route], JSON_UNESCAPED_UNICODE);
