<?php
/**
 * AJAX Endpoint: Cek ketersediaan armada (vehicles) & kru/driver (employees)
 * berdasarkan rentang tanggal pada perusahaan aktif.
 *
 * GET:
 * - company_id (opsional)
 * - booking_type ('travel'|'rental', opsional)
 * - exclude_booking_id (int, opsional — abaikan booking yang sedang diedit)
 * - start_date (YYYY-MM-DD)
 * - end_date (YYYY-MM-DD, opsional — default sama dengan start_date)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!transport_can_view() && !can('vehicles.view') && !can('dispatch.view')) {
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
    echo json_encode(['ok' => true, 'busy_vehicles' => (object) [], 'busy_employees' => (object) []]);
    exit;
}

$bookingType = ($_GET['booking_type'] ?? 'travel') === 'rental' ? 'rental' : 'travel';
$excludeId   = max(0, (int) ($_GET['exclude_booking_id'] ?? 0));
$startDate   = trim((string) ($_GET['start_date'] ?? $_GET['date'] ?? ''));
$endDate     = trim((string) ($_GET['end_date'] ?? $startDate));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    echo json_encode(['ok' => true, 'busy_vehicles' => (object) [], 'busy_employees' => (object) []]);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || $endDate < $startDate) {
    $endDate = $startDate;
}

$db = db();
$busyVehicles  = [];
$busyEmployees = [];

$fmtPeriod = static function (string $s, string $e): string {
    $ts1 = strtotime($s);
    $ts2 = strtotime($e);
    if ($ts1 === false) {
        return $s;
    }
    if ($s === $e || $ts2 === false) {
        return date('d/m/Y', $ts1);
    }
    return date('d/m/Y', $ts1) . ' s/d ' . date('d/m/Y', $ts2);
};

// 1. Cek jadwal Rental yang beririsan dengan [startDate, endDate]
$exRentalId = ($bookingType === 'rental') ? $excludeId : 0;
$sqlRental = "SELECT b.id, b.document_number, b.start_date, b.end_date, b.vehicle_id,
                     COALESCE(p.name, '') AS passenger_name,
                     COALESCE((SELECT h.to_status FROM booking_status_history h
                                WHERE h.booking_type = 'rental' AND h.booking_id = b.id
                                ORDER BY h.id DESC LIMIT 1), 'draft') AS cur_status
                FROM rental_car_bookings b
                LEFT JOIN passengers p ON p.id = b.passenger_id
               WHERE b.company_id = ?
                 AND b.id <> ?
                 AND b.start_date <= ?
                 AND b.end_date >= ?
              HAVING cur_status <> 'cancelled'
               ORDER BY b.start_date ASC, b.id DESC";
$stR = $db->prepare($sqlRental);
$stR->bind_param('iiss', $cid, $exRentalId, $endDate, $startDate);
$stR->execute();
$rentalHits = $stR->get_result()->fetch_all(MYSQLI_ASSOC);
$stR->close();

$rentalById = [];
foreach ($rentalHits as $rh) {
    $bid = (int) $rh['id'];
    $period = $fmtPeriod((string) $rh['start_date'], (string) $rh['end_date']);
    $meta = [
        'booking_id'   => $bid,
        'booking_type' => 'rental',
        'type_label'   => 'Rental',
        'doc_no'       => (string) $rh['document_number'],
        'period'       => $period,
        'passenger'    => (string) $rh['passenger_name'],
        'status'       => (string) $rh['cur_status'],
    ];
    $rentalById[$bid] = $meta;
    $vid = (int) ($rh['vehicle_id'] ?? 0);
    if ($vid > 0 && !isset($busyVehicles[$vid])) {
        $busyVehicles[$vid] = $meta;
    }
}

// 2. Cek jadwal Travel yang beririsan dengan [startDate, endDate]
$exTravelId = ($bookingType === 'travel') ? $excludeId : 0;
$sqlTravel = "SELECT b.id, b.document_number, b.date, b.time, b.vehicle_id,
                     COALESCE(p.name, '') AS passenger_name,
                     COALESCE((SELECT h.to_status FROM booking_status_history h
                                WHERE h.booking_type = 'travel' AND h.booking_id = b.id
                                ORDER BY h.id DESC LIMIT 1), 'draft') AS cur_status
                FROM travel_ticket_bookings b
                LEFT JOIN passengers p ON p.id = b.passenger_id
               WHERE b.company_id = ?
                 AND b.id <> ?
                 AND b.date BETWEEN ? AND ?
              HAVING cur_status <> 'cancelled'
               ORDER BY b.date ASC, b.id DESC";
$stT = $db->prepare($sqlTravel);
$stT->bind_param('iiss', $cid, $exTravelId, $startDate, $endDate);
$stT->execute();
$travelHits = $stT->get_result()->fetch_all(MYSQLI_ASSOC);
$stT->close();

$travelById = [];
foreach ($travelHits as $th) {
    $bid = (int) $th['id'];
    $period = $fmtPeriod((string) $th['date'], (string) $th['date']);
    $tm = substr((string) ($th['time'] ?? ''), 0, 5);
    if ($tm !== '') {
        $period .= ' ' . $tm;
    }
    $meta = [
        'booking_id'   => $bid,
        'booking_type' => 'travel',
        'type_label'   => 'Travel',
        'doc_no'       => (string) $th['document_number'],
        'period'       => $period,
        'passenger'    => (string) $th['passenger_name'],
        'status'       => (string) $th['cur_status'],
    ];
    $travelById[$bid] = $meta;
    $vid = (int) ($th['vehicle_id'] ?? 0);
    if ($vid > 0 && !isset($busyVehicles[$vid])) {
        $busyVehicles[$vid] = $meta;
    }
}

// 3. Cek karyawan/driver yang bertugas pada booking yang beririsan
$collectBusyCrew = static function (string $bType, array $mapById) use ($db, $cid, &$busyEmployees): void {
    if (!$mapById) {
        return;
    }
    $ids = array_map('intval', array_keys($mapById));
    $inList = implode(',', $ids);

    $sqlCost = "SELECT booking_id, employee_id, cost_type
                  FROM booking_cost_lines
                 WHERE company_id = ?
                   AND booking_type = ?
                   AND booking_id IN ({$inList})
                   AND employee_id IS NOT NULL
                   AND employee_id > 0
                   AND cost_type <> 'payment'";
    $stC = $db->prepare($sqlCost);
    $stC->bind_param('is', $cid, $bType);
    $stC->execute();
    foreach ($stC->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
        $eid = (int) $cr['employee_id'];
        $bid = (int) $cr['booking_id'];
        if ($eid > 0 && isset($mapById[$bid]) && !isset($busyEmployees[$eid])) {
            $m = $mapById[$bid];
            $m['role'] = (string) ($cr['cost_type'] ?: 'Kru');
            $busyEmployees[$eid] = $m;
        }
    }
    $stC->close();

    $sqlAssign = "SELECT booking_id, employee_id
                    FROM trip_assignments
                   WHERE company_id = ?
                     AND booking_type = ?
                     AND booking_id IN ({$inList})
                     AND ended_at IS NULL
                     AND employee_id > 0";
    $stA = $db->prepare($sqlAssign);
    $stA->bind_param('is', $cid, $bType);
    $stA->execute();
    foreach ($stA->get_result()->fetch_all(MYSQLI_ASSOC) as $ar) {
        $eid = (int) $ar['employee_id'];
        $bid = (int) $ar['booking_id'];
        if ($eid > 0 && isset($mapById[$bid]) && !isset($busyEmployees[$eid])) {
            $m = $mapById[$bid];
            $m['role'] = 'Sopir';
            $busyEmployees[$eid] = $m;
        }
    }
    $stA->close();
};

$collectBusyCrew('rental', $rentalById);
$collectBusyCrew('travel', $travelById);

echo json_encode([
    'ok'             => true,
    'start_date'     => $startDate,
    'end_date'       => $endDate,
    'busy_vehicles'  => (object) $busyVehicles,
    'busy_employees' => (object) $busyEmployees,
], JSON_UNESCAPED_UNICODE);
