<?php
/**
 * GET /api/bot/routes.php
 * Daftar rute travel aktif (`rutes`), jadwal keberangkatan per rute (`rute_schedules`),
 * kategori kendaraan (`category_vehicles`), dan cabang (`branches`) untuk perusahaan ini.
 * Dipakai Hermes untuk menjawab pertanyaan harga/jadwal/rute maupun sebelum memanggil `booking_create.php`.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';

$ctx     = bot_boot(['GET'], false);
$db      = $ctx['db'];
$company = $ctx['company'];
$cid     = (int) $company['id'];
rute_ensure_schema();

// 1. Rute Travel Aktif
$st = $db->prepare(
    "SELECT r.id, r.code, r.name, r.pickup_point_city_id, r.delivery_point_city_id,
            r.category_vehicle_id, r.min_price, r.price, r.max_price,
            r.start_capacity_seat, r.end_capacity_seat, r.seat_capacity,
            r.pickup_free_radius_km, r.pickup_max_radius_km, r.pickup_extra_price_per_km,
            r.delivery_free_radius_km, r.delivery_max_radius_km, r.delivery_extra_price_per_km,
            r.note,
            c1.name AS origin_city, p1.name AS origin_province,
            c2.name AS destination_city, p2.name AS destination_province,
            cv.name AS vehicle_category
       FROM rutes r
       LEFT JOIN cities c1 ON c1.id = r.pickup_point_city_id
       LEFT JOIN provinces p1 ON p1.id = c1.province_id
       LEFT JOIN cities c2 ON c2.id = r.delivery_point_city_id
       LEFT JOIN provinces p2 ON p2.id = c2.province_id
       LEFT JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
      WHERE r.company_id = ? AND r.is_active = 1
      ORDER BY r.name ASC, r.id ASC"
);
$st->bind_param('i', $cid);
$st->execute();
$rawRoutes = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$tariffsByRoute = rute_tariffs_by_rute_ids(array_column($rawRoutes, 'id'), $cid);
$radiusByRoute  = rute_radius_by_rute_ids(array_column($rawRoutes, 'id'), $cid);
$mapRadiusDist  = static function (array $items): array {
    return array_map(static fn (array $d): array => [
        'district_id' => (int) $d['district_id'],
        'district'    => $d['district_name'],
        'city_id'     => (int) $d['city_id'],
        'city'        => $d['city_name'],
        'extra_price' => bot_money((float) $d['extra_price']),
        'note'        => $d['note'],
    ], $items);
};
$routes = [];
foreach ($rawRoutes as $r) {
    $rid = (int) $r['id'];
    $radItems = $radiusByRoute[$rid] ?? ['pickup' => [], 'delivery' => []];
    $tariffItems = [];
    foreach ($tariffsByRoute[$rid] ?? [] as $tf) {
        $tfSeat = (int) (($tf['seat_capacity'] ?? 0) > 0 ? $tf['seat_capacity'] : ($tf['end_capacity_seat'] ?? 0));
        $tariffItems[] = [
            'category_vehicle_id' => (int) $tf['category_vehicle_id'],
            'category_code'       => $tf['category_code'],
            'vehicle_category'    => $tf['category_name'],
            'price'               => bot_money((float) $tf['price']),
            'min_price'           => bot_money((float) $tf['min_price']),
            'max_price'           => bot_money((float) $tf['max_price']),
            'seat_capacity'       => $tfSeat,
            'note'                => $tf['note'],
        ];
    }
    $effSeat = (int) (($r['seat_capacity'] ?? 0) > 0 ? $r['seat_capacity'] : ($r['end_capacity_seat'] ?? 0));
    $routes[] = [
        'rute_id'              => $rid,
        'code'                 => $r['code'],
        'name'                 => $r['name'],
        'origin_city_id'       => (int) $r['pickup_point_city_id'],
        'origin_city'          => $r['origin_city'],
        'origin_province'      => $r['origin_province'],
        'destination_city_id'  => (int) $r['delivery_point_city_id'],
        'destination_city'     => $r['destination_city'],
        'destination_province' => $r['destination_province'],
        'category_vehicle_id'  => (int) $r['category_vehicle_id'],
        'vehicle_category'     => $r['vehicle_category'],
        'price'                => bot_money((float) $r['price']),
        'min_price'            => bot_money((float) $r['min_price']),
        'max_price'            => bot_money((float) $r['max_price']),
        'seat_capacity'        => $effSeat,
        'capacity_seats'       => $effSeat,
        'tariffs'              => $tariffItems,
        'pickup_radius'        => [
            'free_km'            => (float) $r['pickup_free_radius_km'],
            'max_km'             => (float) $r['pickup_max_radius_km'],
            'extra_price_per_km' => bot_money((float) $r['pickup_extra_price_per_km']),
            'districts'          => $mapRadiusDist($radItems['pickup']),
        ],
        'delivery_radius'      => [
            'free_km'            => (float) $r['delivery_free_radius_km'],
            'max_km'             => (float) $r['delivery_max_radius_km'],
            'extra_price_per_km' => bot_money((float) $r['delivery_extra_price_per_km']),
            'districts'          => $mapRadiusDist($radItems['delivery']),
        ],
        'note'                 => $r['note'],
    ];
}

// 2. Jadwal Keberangkatan per Rute (`rute_schedules`)
$st = $db->prepare(
    'SELECT rs.id, rs.rute_id, rs.departure_time, r.code AS rute_code, r.name AS rute_name
       FROM rute_schedules rs
       JOIN rutes r ON r.id = rs.rute_id
      WHERE rs.company_id = ? AND r.is_active = 1
      ORDER BY r.id ASC, rs.departure_time ASC'
);
$st->bind_param('i', $cid);
$st->execute();
$schedules = [];
$byRoute   = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $rid  = (int) $s['rute_id'];
    $dep  = (string) $s['departure_time'];
    $byRoute[$rid][] = $dep;
    $schedules[] = [
        'schedule_id' => (int) $s['id'],
        'rute_id'     => $rid,
        'rute_code'   => $s['rute_code'],
        'rute_name'   => $s['rute_name'],
        'departure'   => $dep,
    ];
}
$st->close();
foreach ($routes as &$routeRow) {
    $routeRow['schedules'] = $byRoute[$routeRow['rute_id']] ?? [];
}
unset($routeRow);

// 3. Kategori Kendaraan (untuk Travel & Rental)
$st = $db->prepare('SELECT id, code, name, note FROM category_vehicles WHERE company_id = ? AND is_active = 1 ORDER BY name ASC');
$st->bind_param('i', $cid);
$st->execute();
$categories = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
    $categories[] = [
        'category_vehicle_id' => (int) $c['id'],
        'code'                => $c['code'],
        'name'                => $c['name'],
        'note'                => $c['note'],
    ];
}
$st->close();

// 4. Cabang Aktif
$st = $db->prepare('SELECT id, code, name, phone_number FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY id ASC');
$st->bind_param('i', $cid);
$st->execute();
$branches = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

bot_reply(200, [
    'company'            => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'routes'             => $routes,
    'schedules'          => $schedules,
    'vehicle_categories' => $categories,
    'branches'           => $branches,
]);

