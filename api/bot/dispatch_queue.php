<?php
/**
 * Dispatch Queue & Driver Assignments Endpoint untuk Agen AI Hermes:
 *
 * 1. GET /api/bot/dispatch_queue.php
 *    - Mode Pending (Scheduler Hermes): Mengambil penugasan trip aktif yang belum di-notifikasi (notified_at IS NULL).
 *    - Mode Driver (Interaktif): Mengambil jadwal tugas aktif milik driver berdasarkan nomor WA pengirim (phone).
 *
 * 2. POST /api/bot/dispatch_queue.php
 *    - Konfirmasi penerimaan notifikasi oleh scheduler:
 *      { "assignment_id": 12, "status": "sent" } -> UPDATE trip_assignments SET notified_at = NOW()
 */
require __DIR__ . '/_bootstrap.php';

$ctx     = bot_boot(['GET', 'POST']);
$db      = $ctx['db'];
$company = $ctx['company'];
$cid     = (int) $company['id'];
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $mode   = trim((string) ($_GET['mode'] ?? 'pending'));
    $phone  = $ctx['phone'] ?? null;
    $limit  = max(1, min(50, (int) ($_GET['limit'] ?? 20)));

    // Jika mode driver atau nomor telepon pengirim cocok dengan driver karyawan
    if ($mode === 'driver' || (isset($_GET['phone']) && $mode !== 'pending')) {
        $driverPhone = trim((string) ($_GET['phone'] ?? $phone));
        if ($driverPhone === '') {
            bot_error(400, 'PHONE_REQUIRED', 'Nomor telepon driver diperlukan.');
        }

        // Cari karyawan driver
        $cleanPhone = preg_replace('/[^0-9]/', '', $driverPhone);
        $st = $db->prepare('SELECT id, code, name, phone_number, job_position, status
            FROM employees
            WHERE company_id = ? AND status = \'aktif\' AND REPLACE(REPLACE(phone_number, \'-\', \'\'), \' \', \'\') LIKE ?
            LIMIT 1');
        $likePhone = '%' . substr($cleanPhone, -8);
        $st->bind_param('is', $cid, $likePhone);
        $st->execute();
        $driver = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$driver) {
            bot_reply(200, [
                'is_driver'   => false,
                'driver'      => null,
                'assignments' => [],
                'message'     => 'Nomor ini tidak terdaftar sebagai pengemudi/driver aktif.',
            ]);
        }

        $driverId = (int) $driver['id'];
        $sql = "SELECT a.id AS assignment_id, a.booking_type, a.booking_id, a.started_at, a.notified_at,
                       v.id AS vehicle_id, v.vehicle_number, v.ownership,
                       b.name AS brand_name, m.name AS model_name
                FROM trip_assignments a
                LEFT JOIN vehicles v ON v.id = a.vehicle_id
                LEFT JOIN brand_vehicles b ON b.id = v.brand_id
                LEFT JOIN model_units m ON m.id = v.model_unit_id
                WHERE a.company_id = ? AND a.employee_id = ? AND a.ended_at IS NULL
                ORDER BY a.started_at DESC LIMIT ?";
        $st = $db->prepare($sql);
        $st->bind_param('iii', $cid, $driverId, $limit);
        $st->execute();
        $assigns = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $items = [];
        foreach ($assigns as $as) {
            $items[] = format_assignment_item($db, $cid, $as, $driver);
        }

        bot_reply(200, [
            'is_driver'   => true,
            'driver'      => [
                'id'           => $driverId,
                'name'         => $driver['name'],
                'code'         => $driver['code'],
                'phone_number' => $driver['phone_number'],
            ],
            'assignments' => $items,
            'count'       => count($items),
        ]);
    }

    // Default: Mode Pending Queue untuk Scheduler Hermes
    $sql = "SELECT a.id AS assignment_id, a.booking_type, a.booking_id, a.started_at, a.notified_at,
                   e.id AS driver_id, e.code AS driver_code, e.name AS driver_name, e.phone_number AS driver_phone,
                   v.id AS vehicle_id, v.vehicle_number, v.ownership,
                   b.name AS brand_name, m.name AS model_name
            FROM trip_assignments a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN vehicles v ON v.id = a.vehicle_id
            LEFT JOIN brand_vehicles b ON b.id = v.brand_id
            LEFT JOIN model_units m ON m.id = v.model_unit_id
            WHERE a.company_id = ? AND a.ended_at IS NULL AND a.notified_at IS NULL
            ORDER BY a.started_at ASC LIMIT ?";
    $st = $db->prepare($sql);
    $st->bind_param('ii', $cid, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $items = [];
    foreach ($rows as $r) {
        $driver = [
            'id'           => (int) $r['driver_id'],
            'code'         => $r['driver_code'],
            'name'         => $r['driver_name'],
            'phone_number' => $r['driver_phone'],
        ];
        $items[] = format_assignment_item($db, $cid, $r, $driver);
    }

    bot_reply(200, [
        'mode'  => 'pending_queue',
        'items' => $items,
        'count' => count($items),
    ]);
}

// POST: Konfirmasi ACK Notifikasi (Tandai notified_at)
$body = $ctx['body'] ?? [];
$assignmentId = (int) ($body['assignment_id'] ?? $body['id'] ?? 0);
$status = strtolower(trim((string) ($body['status'] ?? 'sent')));

if ($assignmentId <= 0) {
    bot_error(400, 'INVALID_INPUT', 'Wajib menyertakan assignment_id.');
}

if ($status === 'sent' || $status === 'ack') {
    $st = $db->prepare('UPDATE trip_assignments SET notified_at = NOW(), modified_user = \'hermes_bot\', modified_date = NOW()
        WHERE id = ? AND company_id = ?');
    $st->bind_param('ii', $assignmentId, $cid);
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();

    bot_reply(200, [
        'acknowledged'  => $affected > 0,
        'assignment_id' => $assignmentId,
        'status'        => 'notified',
    ]);
}

bot_error(400, 'INVALID_STATUS', 'Status harus "sent" atau "ack".');

/**
 * Format payload lengkap penugasan untuk dikonsumsi Hermes AI
 */
function format_assignment_item(mysqli $db, int $cid, array $as, array $driver): array
{
    $bt = $as['booking_type'];
    $bid = (int) $as['booking_id'];

    $tripInfo = [
        'booking_type'    => $bt,
        'booking_id'      => $bid,
        'document_number' => '',
        'date'            => '',
        'time'            => '',
        'start_date'      => null,
        'end_date'        => null,
        'route_label'     => '',
        'pickup_address'  => '',
        'delivery_address'=> '',
        'total_price'     => 0,
        'total_payment'   => 0,
        'pay_status'      => 'unpaid',
    ];

    if ($bt === 'travel') {
        $st = $db->prepare("SELECT t.document_number, t.date, t.time, t.pickup_address, t.delivery_address,
                                   t.total_price, t.total_payment,
                                   r.name AS route_name, c1.name AS origin_city, c2.name AS dest_city
                            FROM travel_ticket_bookings t
                            LEFT JOIN rutes r ON r.id = (SELECT rute_id FROM trip_schedules WHERE id = (SELECT trip_schedule_id FROM travel_ticket_bookings WHERE id = t.id LIMIT 1))
                            LEFT JOIN cities c1 ON c1.id = t.pickup_point_city_id
                            LEFT JOIN cities c2 ON c2.id = t.delivery_point_city_id
                            WHERE t.id = ? AND t.company_id = ?");
        $st->bind_param('ii', $bid, $cid);
        $st->execute();
        $bRow = $st->get_result()->fetch_assoc();
        $st->close();
        if ($bRow) {
            $tripInfo['document_number'] = $bRow['document_number'];
            $tripInfo['date']            = $bRow['date'];
            $tripInfo['time']            = substr((string) $bRow['time'], 0, 5);
            $tripInfo['route_label']     = $bRow['route_name'] ?: (($bRow['origin_city'] ?? '') . ' → ' . ($bRow['dest_city'] ?? ''));
            $tripInfo['pickup_address']  = (string) $bRow['pickup_address'];
            $tripInfo['delivery_address']= (string) $bRow['delivery_address'];
            $tripInfo['total_price']     = (float) $bRow['total_price'];
            $tripInfo['total_payment']   = (float) $bRow['total_payment'];
            $tripInfo['pay_status']      = $tripInfo['total_payment'] >= $tripInfo['total_price'] ? 'paid' : ($tripInfo['total_payment'] > 0 ? 'partial' : 'unpaid');
        }
    } else {
        $st = $db->prepare("SELECT r.document_number, r.date, r.time, r.start_date, r.end_date,
                                   r.pickup_address, r.delivery_address, r.total_price, r.total_payment,
                                   c1.name AS origin_city, c2.name AS dest_city
                            FROM rental_car_bookings r
                            LEFT JOIN cities c1 ON c1.id = r.pickup_point_city_id
                            LEFT JOIN cities c2 ON c2.id = r.delivery_point_city_id
                            WHERE r.id = ? AND r.company_id = ?");
        $st->bind_param('ii', $bid, $cid);
        $st->execute();
        $bRow = $st->get_result()->fetch_assoc();
        $st->close();
        if ($bRow) {
            $tripInfo['document_number'] = $bRow['document_number'];
            $tripInfo['date']            = $bRow['date'];
            $tripInfo['time']            = substr((string) $bRow['time'], 0, 5);
            $tripInfo['start_date']      = $bRow['start_date'];
            $tripInfo['end_date']        = $bRow['end_date'];
            $tripInfo['route_label']     = 'Rental (' . ($bRow['origin_city'] ?? 'Kota Asal') . ' → ' . ($bRow['dest_city'] ?? 'Tujuan') . ')';
            $tripInfo['pickup_address']  = (string) $bRow['pickup_address'];
            $tripInfo['delivery_address']= (string) $bRow['delivery_address'];
            $tripInfo['total_price']     = (float) $bRow['total_price'];
            $tripInfo['total_payment']   = (float) $bRow['total_payment'];
            $tripInfo['pay_status']      = $tripInfo['total_payment'] >= $tripInfo['total_price'] ? 'paid' : ($tripInfo['total_payment'] > 0 ? 'partial' : 'unpaid');
        }
    }

    // Daftar Penumpang
    $passengers = [];
    $pst = $db->prepare("SELECT p.name, p.phone_number, bp.is_primary
        FROM booking_passengers bp
        JOIN passengers p ON p.id = bp.passenger_id
        WHERE bp.booking_type = ? AND bp.booking_id = ?
        ORDER BY bp.is_primary DESC, bp.id ASC");
    $pst->bind_param('si', $bt, $bid);
    $pst->execute();
    $passengers = $pst->get_result()->fetch_all(MYSQLI_ASSOC);
    $pst->close();

    $vehicleLabel = trim(($as['brand_name'] ?? '') . ' ' . ($as['model_name'] ?? ''));
    if (!empty($as['vehicle_number'])) {
        $vehicleLabel .= ($vehicleLabel !== '' ? ' - ' : '') . $as['vehicle_number'];
    }

    return [
        'assignment_id'   => (int) $as['assignment_id'],
        'started_at'      => $as['started_at'],
        'notified_at'     => $as['notified_at'],
        'driver'          => $driver,
        'vehicle'         => [
            'id'             => (int) ($as['vehicle_id'] ?? 0),
            'vehicle_number' => $as['vehicle_number'] ?? '-',
            'label'          => $vehicleLabel ?: 'Unit Belum Ditentukan',
        ],
        'trip'            => $tripInfo,
        'passengers'      => $passengers,
        'passenger_count' => count($passengers),
    ];
}

