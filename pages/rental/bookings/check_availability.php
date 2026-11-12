<?php
/**
 * AJAX Endpoint: Pengecekan Ketersediaan Armada Rental (Anti Double-Booking)
 * GET /pages/rental/bookings/check_availability.php?vehicle_id=...&start_date=...&end_date=...[&exclude_id=...]
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');

header('Content-Type: application/json; charset=utf-8');

$cid        = (int) ($_GET['company_id'] ?? (active_company_id() ?? 0));
$vehicle_id = (int) ($_GET['vehicle_id'] ?? 0);
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date   = trim((string) ($_GET['end_date'] ?? ''));
$exclude_id = (int) ($_GET['exclude_id'] ?? 0);

if ($cid <= 0 || $vehicle_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    echo json_encode(['available' => true, 'checked' => false]);
    exit;
}
assert_company_access($cid);

if ($start_date > $end_date) {
    echo json_encode(['available' => false, 'message' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.']);
    exit;
}

$st = db()->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date, p.name AS passenger_name,
                            v.vehicle_number,
                            (SELECT to_status FROM booking_status_history WHERE booking_type = 'rental' AND booking_id = b.id ORDER BY id DESC LIMIT 1) AS cur_status
                     FROM rental_car_bookings b
                     LEFT JOIN passengers p ON p.id = b.passenger_id
                     LEFT JOIN vehicles v ON v.id = b.vehicle_id
                     WHERE b.company_id = ?
                       AND b.vehicle_id = ?
                       AND b.id != ?
                       AND b.start_date <= ?
                       AND b.end_date >= ?
                     HAVING cur_status != 'cancelled' OR cur_status IS NULL
                     LIMIT 1");
$st->bind_param('iiiss', $cid, $vehicle_id, $exclude_id, $end_date, $start_date);
$st->execute();
$conflict = $st->get_result()->fetch_assoc();
$st->close();

if ($conflict) {
    $msg = 'Armada ' . ($conflict['vehicle_number'] ?: '') . ' sudah ter-booking pada tanggal tersebut ('
         . $conflict['document_number'] . ' periode ' . date('d/m/Y', strtotime($conflict['start_date'])) . ' s.d ' . date('d/m/Y', strtotime($conflict['end_date']))
         . (!empty($conflict['passenger_name']) ? ' a.n ' . $conflict['passenger_name'] : '') . ').';
    echo json_encode([
        'available' => false,
        'message'   => $msg,
        'conflict'  => [
            'booking_id'      => (int) $conflict['id'],
            'document_number' => $conflict['document_number'],
            'start_date'      => $conflict['start_date'],
            'end_date'        => $conflict['end_date'],
            'passenger_name'  => $conflict['passenger_name'],
            'vehicle_number'  => $conflict['vehicle_number'],
        ],
    ]);
    exit;
}

echo json_encode(['available' => true, 'checked' => true]);

