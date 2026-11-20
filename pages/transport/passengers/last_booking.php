<?php
/**
 * AJAX Endpoint: Ambil lokasi & alamat jemput/antar terakhir pelanggan untuk auto-fill form booking.
 * GET: passenger_id, company_id (opsional), type ('travel'|'rental', opsional)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!transport_can_view()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$activeCid = (int) (active_company_id() ?? 0);
$reqCid    = (int) ($_GET['company_id'] ?? $activeCid);
$allowed   = accessible_company_ids($activeCid);

if ($reqCid > 0 && (is_super_admin() || in_array($reqCid, $allowed, true))) {
    $cid = $reqCid;
} elseif ($activeCid > 0) {
    $cid = $activeCid;
} else {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$passengerId = (int) ($_GET['passenger_id'] ?? 0);
if ($passengerId <= 0) {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$prefType = ($_GET['type'] ?? 'travel') === 'rental' ? 'rental' : 'travel';
$orderTables = $prefType === 'rental'
    ? ['rental' => 'rental_car_bookings', 'travel' => 'travel_ticket_bookings']
    : ['travel' => 'travel_ticket_bookings', 'rental' => 'rental_car_bookings'];

$db = db();
$hit = null;
$hitType = '';

foreach ($orderTables as $bType => $tbl) {
    $sql = "SELECT b.id, b.document_number, b.date,
                   b.pickup_point_province_id, b.pickup_point_city_id, b.pickup_point_district_id, b.pickup_address,
                   b.delivery_point_province_id, b.delivery_point_city_id, b.delivery_point_district_id, b.delivery_address,
                   CONCAT(pp.code, ' - ', pp.name) AS pickup_province_name,
                   pc.name AS pickup_city_name,
                   pd.name AS pickup_district_name,
                   CONCAT(dp.code, ' - ', dp.name) AS delivery_province_name,
                   dc.name AS delivery_city_name,
                   dd.name AS delivery_district_name
              FROM {$tbl} b
              LEFT JOIN provinces pp ON pp.id = b.pickup_point_province_id
              LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
              LEFT JOIN districts pd ON pd.id = b.pickup_point_district_id
              LEFT JOIN provinces dp ON dp.id = b.delivery_point_province_id
              LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
              LEFT JOIN districts dd ON dd.id = b.delivery_point_district_id
             WHERE b.company_id = ? AND b.passenger_id = ?
               AND COALESCE((SELECT h.to_status FROM booking_status_history h
                              WHERE h.booking_type = ? AND h.booking_id = b.id
                              ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'
               AND (b.pickup_point_district_id IS NOT NULL OR b.delivery_point_district_id IS NOT NULL
                    OR COALESCE(b.pickup_address, '') <> '' OR COALESCE(b.delivery_address, '') <> '')
             ORDER BY b.date DESC, b.id DESC
             LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param('iis', $cid, $passengerId, $bType);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $hit = $row;
        $hitType = $bType;
        break;
    }
}

if (!$hit) {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$packSide = static function (string $pfx) use ($hit): array {
    return [
        'province_id'   => (int) ($hit["{$pfx}_point_province_id"] ?? 0),
        'province_name' => (string) ($hit["{$pfx}_province_name"] ?? ''),
        'city_id'       => (int) ($hit["{$pfx}_point_city_id"] ?? 0),
        'city_name'     => (string) ($hit["{$pfx}_city_name"] ?? ''),
        'district_id'   => (int) ($hit["{$pfx}_point_district_id"] ?? 0),
        'district_name' => (string) ($hit["{$pfx}_district_name"] ?? ''),
        'address'       => trim((string) ($hit["{$pfx}_address"] ?? '')),
    ];
};

echo json_encode([
    'ok'           => true,
    'found'        => true,
    'doc_no'       => (string) ($hit['document_number'] ?? ''),
    'booking_type' => $hitType,
    'date'         => (string) ($hit['date'] ?? ''),
    'pickup'       => $packSide('pickup'),
    'delivery'     => $packSide('delivery'),
], JSON_UNESCAPED_UNICODE);
