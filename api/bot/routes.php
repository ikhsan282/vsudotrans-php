<?php
/**
 * GET /api/bot/routes.php
 * Daftar rute travel aktif (`rutes`), jadwal keberangkatan (`schedules`),
 * kategori kendaraan (`category_vehicles`), dan cabang (`branches`) untuk perusahaan ini.
 * Dipakai Hermes untuk menjawab pertanyaan harga/jadwal/rute maupun sebelum memanggil `booking_create.php`.
 */
require __DIR__ . '/_bootstrap.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$cid     = (int) $company['id'];

// 1. Rute Travel Aktif
$st = $db->prepare(
    "SELECT r.id, r.code, r.name, r.pickup_point_city_id, r.delivery_point_city_id,
            r.category_vehicle_id, r.min_price, r.price, r.max_price,
            r.start_capacity_seat, r.end_capacity_seat, r.note,
            c1.name AS origin_city, c2.name AS destination_city,
            cv.name AS vehicle_category
       FROM rutes r
       LEFT JOIN cities c1 ON c1.id = r.pickup_point_city_id
       LEFT JOIN cities c2 ON c2.id = r.delivery_point_city_id
       LEFT JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
      WHERE r.company_id = ? AND r.is_active = 1
      ORDER BY r.name ASC, r.id ASC"
);
$st->bind_param('i', $cid);
$st->execute();
$routes = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $routes[] = [
        'rute_id'             => (int) $r['id'],
        'code'                => $r['code'],
        'name'                => $r['name'],
        'origin_city_id'      => (int) $r['pickup_point_city_id'],
        'origin_city'         => $r['origin_city'],
        'destination_city_id' => (int) $r['delivery_point_city_id'],
        'destination_city'    => $r['destination_city'],
        'category_vehicle_id' => (int) $r['category_vehicle_id'],
        'vehicle_category'    => $r['vehicle_category'],
        'price'               => bot_money((float) $r['price']),
        'min_price'           => bot_money((float) $r['min_price']),
        'max_price'           => bot_money((float) $r['max_price']),
        'capacity_seats'      => (int) $r['end_capacity_seat'],
        'note'                => $r['note'],
    ];
}
$st->close();

// 2. Jadwal Keberangkatan
$st = $db->prepare('SELECT id, start, end, duration, total_duration FROM schedules WHERE company_id = ? ORDER BY start ASC');
$st->bind_param('i', $cid);
$st->execute();
$schedules = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $schedules[] = [
        'schedule_id' => (int) $s['id'],
        'departure'   => substr((string) $s['start'], 0, 5),
        'arrival'     => substr((string) $s['end'], 0, 5),
        'duration_hr' => $s['total_duration'] !== null ? (float) $s['total_duration'] : null,
    ];
}
$st->close();

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

