<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesi berakhir. Silakan login kembali.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$activeCid = active_company_id();
if ($activeCid === null && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Pilih perusahaan aktif terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'groups' => [], 'total' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($q) > 80) {
    $q = mb_substr($q, 0, 80);
}

$db = db();
$like = '%' . addcslashes($q, '%_\\') . '%';
$phonePref = phone_search_prefix($q);
$phoneLike = $phonePref !== null ? ($phonePref . '%') : null;

$companySqlB = $activeCid !== null ? ' AND b.company_id = ?' : '';
$companySqlT = $activeCid !== null ? ' AND company_id = ?' : '';
$companySqlV = $activeCid !== null ? ' AND v.company_id = ?' : '';
$companySqlE = $activeCid !== null ? ' AND e.company_id = ?' : '';
$compType    = $activeCid !== null ? 'i' : '';
$compParam   = $activeCid !== null ? [$activeCid] : [];
$multiComp   = $activeCid === null;

$groups = [];
$totalItems = 0;

// 1. Travel Bookings
if (can('travel.view')) {
    $phoneClause = $phoneLike !== null ? ' OR p.phone_number LIKE ? OR EXISTS (SELECT 1 FROM booking_passengers bpp JOIN passengers pxp ON pxp.id = bpp.passenger_id WHERE bpp.booking_type = \'travel\' AND bpp.booking_id = b.id AND pxp.phone_number LIKE ?)' : '';
    $sqlT = "SELECT b.id, b.document_number, b.date, b.time,
                    p.name AS passenger_name, COALESCE(p.phone_number, '') AS passenger_phone,
                    COALESCE(v.vehicle_number, '') AS vehicle_number,
                    COALESCE(oc.name, '') AS orig_city, COALESCE(dc.name, '') AS dest_city,
                    c.code AS company_code,
                    COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='travel' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status
               FROM travel_ticket_bookings b
               JOIN companies c ON c.id = b.company_id
               JOIN passengers p ON p.id = b.passenger_id
               LEFT JOIN vehicles v ON v.id = b.vehicle_id
               LEFT JOIN cities oc ON oc.id = b.pickup_point_city_id
               LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
              WHERE 1=1 {$companySqlB}
                AND (
                    b.document_number LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ?
                    OR v.vehicle_number LIKE ? OR oc.name LIKE ? OR dc.name LIKE ?
                    OR EXISTS (SELECT 1 FROM booking_passengers bp JOIN passengers px ON px.id = bp.passenger_id WHERE bp.booking_type='travel' AND bp.booking_id=b.id AND (px.name LIKE ? OR px.phone_number LIKE ?))
                    {$phoneClause}
                )
              ORDER BY b.date DESC, b.id DESC
              LIMIT 6";
    $stT = $db->prepare($sqlT);
    $types = $compType . str_repeat('s', 8) . ($phoneLike !== null ? 'ss' : '');
    $params = array_merge($compParam, array_fill(0, 8, $like), $phoneLike !== null ? [$phoneLike, $phoneLike] : []);
    $stT->bind_param($types, ...$params);
    $stT->execute();
    $rowsT = $stT->get_result()->fetch_all(MYSQLI_ASSOC);
    $stT->close();

    if ($rowsT) {
        $items = [];
        foreach ($rowsT as $r) {
            $route = ($r['orig_city'] && $r['dest_city']) ? ($r['orig_city'] . ' → ' . $r['dest_city']) : ($r['orig_city'] ?: $r['dest_city']);
            $subBits = array_filter([
                !empty($r['date']) ? date('d-M-Y', strtotime((string) $r['date'])) . (!empty($r['time']) ? ' ' . substr((string) $r['time'], 0, 5) : '') : '',
                $route,
                $r['vehicle_number'],
                $multiComp && !empty($r['company_code']) ? '[' . $r['company_code'] . ']' : '',
            ]);
            $items[] = [
                'title'    => $r['document_number'] . ' · ' . $r['passenger_name'],
                'subtitle' => implode(' · ', $subBits),
                'badge'    => booking_status_label((string) $r['status']),
                'url'      => APP_URL . '/pages/travel/bookings/detail.php?id=' . (int) $r['id'],
            ];
        }
        $groups[] = ['key' => 'travel', 'label' => 'Pemesanan Travel', 'icon' => 'bi-bus-front', 'items' => $items];
        $totalItems += count($items);
    }
}

// 2. Rental Bookings
if (can('rental.view')) {
    $phoneClause = $phoneLike !== null ? ' OR p.phone_number LIKE ?' : '';
    $sqlR = "SELECT b.id, b.document_number, b.start_date, b.end_date, b.time,
                    p.name AS passenger_name, COALESCE(p.phone_number, '') AS passenger_phone,
                    COALESCE(v.vehicle_number, '') AS vehicle_number,
                    COALESCE(cv.name, '') AS category_name,
                    c.code AS company_code,
                    COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status
               FROM rental_car_bookings b
               JOIN companies c ON c.id = b.company_id
               JOIN passengers p ON p.id = b.passenger_id
               LEFT JOIN vehicles v ON v.id = b.vehicle_id
               LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
              WHERE 1=1 {$companySqlB}
                AND (
                    b.document_number LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ?
                    OR v.vehicle_number LIKE ? OR cv.name LIKE ?
                    {$phoneClause}
                )
              ORDER BY b.start_date DESC, b.id DESC
              LIMIT 6";
    $stR = $db->prepare($sqlR);
    $types = $compType . str_repeat('s', 5) . ($phoneLike !== null ? 's' : '');
    $params = array_merge($compParam, array_fill(0, 5, $like), $phoneLike !== null ? [$phoneLike] : []);
    $stR->bind_param($types, ...$params);
    $stR->execute();
    $rowsR = $stR->get_result()->fetch_all(MYSQLI_ASSOC);
    $stR->close();

    if ($rowsR) {
        $items = [];
        foreach ($rowsR as $r) {
            $d1 = !empty($r['start_date']) ? date('d-M-Y', strtotime((string) $r['start_date'])) : '';
            $d2 = !empty($r['end_date']) ? date('d-M-Y', strtotime((string) $r['end_date'])) : '';
            $period = ($d1 && $d2 && $d2 !== $d1) ? ($d1 . ' s/d ' . $d2) : $d1;
            $subBits = array_filter([
                $period,
                $r['category_name'],
                $r['vehicle_number'],
                $multiComp && !empty($r['company_code']) ? '[' . $r['company_code'] . ']' : '',
            ]);
            $items[] = [
                'title'    => $r['document_number'] . ' · ' . $r['passenger_name'],
                'subtitle' => implode(' · ', $subBits),
                'badge'    => booking_status_label((string) $r['status']),
                'url'      => APP_URL . '/pages/rental/bookings/detail.php?id=' . (int) $r['id'],
            ];
        }
        $groups[] = ['key' => 'rental', 'label' => 'Sewa Kendaraan', 'icon' => 'bi-car-front', 'items' => $items];
        $totalItems += count($items);
    }
}

// 3. Penumpang
if (can('travel.view') || can('rental.view')) {
    $phoneClause = $phoneLike !== null ? ' OR phone_number LIKE ?' : '';
    $sqlP = "SELECT id, code, name, COALESCE(phone_number, '') AS phone_number, COALESCE(email, '') AS email
               FROM passengers
              WHERE 1=1 {$companySqlT}
                AND (name LIKE ? OR code LIKE ? OR phone_number LIKE ? OR email LIKE ?{$phoneClause})
              ORDER BY name ASC
              LIMIT 5";
    $stP = $db->prepare($sqlP);
    $types = $compType . 'ssss' . ($phoneLike !== null ? 's' : '');
    $params = array_merge($compParam, [$like, $like, $like, $like], $phoneLike !== null ? [$phoneLike] : []);
    $stP->bind_param($types, ...$params);
    $stP->execute();
    $rowsP = $stP->get_result()->fetch_all(MYSQLI_ASSOC);
    $stP->close();

    if ($rowsP) {
        $items = [];
        foreach ($rowsP as $r) {
            $subBits = array_filter([$r['code'], $r['phone_number'], $r['email']]);
            $items[] = [
                'title'    => $r['name'],
                'subtitle' => implode(' · ', $subBits) ?: 'Penumpang',
                'badge'    => 'Pelanggan',
                'url'      => APP_URL . '/pages/transport/passengers/detail.php?id=' . (int) $r['id'],
            ];
        }
        $groups[] = ['key' => 'passengers', 'label' => 'Penumpang / Pelanggan', 'icon' => 'bi-people', 'items' => $items];
        $totalItems += count($items);
    }
}

// 4. Kendaraan
if (can('vehicles.view')) {
    $sqlV = "SELECT v.id, v.vehicle_number, v.vehicle_category, v.seat,
                    COALESCE(bv.name, '') AS brand_name, COALESCE(mu.name, '') AS model_name,
                    v.is_active
               FROM vehicles v
               LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
               LEFT JOIN model_units mu ON mu.id = v.model_unit_id
              WHERE 1=1 {$companySqlV}
                AND (v.vehicle_number LIKE ? OR v.vehicle_category LIKE ? OR bv.name LIKE ? OR mu.name LIKE ?)
              ORDER BY v.is_active DESC, v.vehicle_number ASC
              LIMIT 5";
    $stV = $db->prepare($sqlV);
    $types = $compType . 'ssss';
    $params = array_merge($compParam, [$like, $like, $like, $like]);
    $stV->bind_param($types, ...$params);
    $stV->execute();
    $rowsV = $stV->get_result()->fetch_all(MYSQLI_ASSOC);
    $stV->close();

    if ($rowsV) {
        $items = [];
        foreach ($rowsV as $r) {
            $bm = format_vehicle_brand_model($r['brand_name'], $r['model_name']);
            $subBits = array_filter([
                $r['vehicle_category'],
                $bm,
                ((int) $r['seat'] > 0) ? ($r['seat'] . ' seat') : '',
            ]);
            $items[] = [
                'title'    => $r['vehicle_number'],
                'subtitle' => implode(' · ', $subBits) ?: 'Kendaraan',
                'badge'    => ((int) $r['is_active'] === 1) ? 'Aktif' : 'Nonaktif',
                'url'      => APP_URL . '/pages/vehicles/detail.php?id=' . (int) $r['id'],
            ];
        }
        $groups[] = ['key' => 'vehicles', 'label' => 'Armada & Kendaraan', 'icon' => 'bi-truck', 'items' => $items];
        $totalItems += count($items);
    }
}

// 5. Karyawan / Sopir
if (can('employees.view')) {
    $phoneClause = $phoneLike !== null ? ' OR e.phone_number LIKE ?' : '';
    $sqlE = "SELECT e.id, e.code, e.name, COALESCE(e.job_position, '') AS job_position,
                    COALESCE(e.job_title, '') AS job_title, COALESCE(e.phone_number, '') AS phone_number,
                    e.status
               FROM employees e
              WHERE 1=1 {$companySqlE}
                AND (e.name LIKE ? OR e.code LIKE ? OR e.phone_number LIKE ? OR e.job_position LIKE ?{$phoneClause})
              ORDER BY (e.status = 'aktif') DESC, e.name ASC
              LIMIT 5";
    $stE = $db->prepare($sqlE);
    $types = $compType . 'ssss' . ($phoneLike !== null ? 's' : '');
    $params = array_merge($compParam, [$like, $like, $like, $like], $phoneLike !== null ? [$phoneLike] : []);
    $stE->bind_param($types, ...$params);
    $stE->execute();
    $rowsE = $stE->get_result()->fetch_all(MYSQLI_ASSOC);
    $stE->close();

    if ($rowsE) {
        $items = [];
        foreach ($rowsE as $r) {
            $subBits = array_filter([$r['code'], $r['job_position'] ?: $r['job_title'], $r['phone_number']]);
            $items[] = [
                'title'    => $r['name'],
                'subtitle' => implode(' · ', $subBits) ?: 'Karyawan',
                'badge'    => ucfirst((string) $r['status']),
                'url'      => APP_URL . '/pages/employees/detail.php?id=' . (int) $r['id'],
            ];
        }
        $groups[] = ['key' => 'employees', 'label' => 'Karyawan & Sopir', 'icon' => 'bi-person-badge', 'items' => $items];
        $totalItems += count($items);
    }
}

echo json_encode([
    'ok'     => true,
    'query'  => $q,
    'total'  => $totalItems,
    'groups' => $groups,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
