<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.view');

$companyId = active_company_id();
if ($companyId === null && !is_super_admin()) {
    http_response_code(403);
    exit('Pilih perusahaan terlebih dahulu.');
}

$from = trim((string) ($_GET['start'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['end'] ?? date('Y-m-t')));
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$validDate($from) || !$validDate($to) || $from > $to || (strtotime($to) - strtotime($from)) > 370 * 86400) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Rentang tanggal tidak valid.']);
    exit;
}

$companySql = $companyId === null ? '' : ' AND b.company_id = ?';
$companyTypes = $companyId === null ? '' : 'i';
$sql = "SELECT 'travel' AS kind, b.id, b.company_id, b.document_number, b.date AS starts_on, b.time AS starts_at,
               NULL AS ends_on, NULL AS ends_at, p.name AS passenger,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='travel' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               v.vehicle_number, e.name AS driver
        FROM travel_ticket_bookings b
        JOIN passengers p ON p.id=b.passenger_id
        LEFT JOIN vehicles v ON v.id=b.vehicle_id
        LEFT JOIN trip_assignments ta ON ta.id=(SELECT MAX(t2.id) FROM trip_assignments t2 WHERE t2.booking_type='travel' AND t2.booking_id=b.id AND t2.ended_at IS NULL)
        LEFT JOIN employees e ON e.id=ta.employee_id
        WHERE b.date >= ? AND b.date < ?{$companySql}
        UNION ALL
        SELECT 'rental' AS kind, b.id, b.company_id, b.document_number, b.start_date AS starts_on, b.time AS starts_at,
               DATE_ADD(b.end_date, INTERVAL 1 DAY) AS ends_on, NULL AS ends_at, p.name AS passenger,
               COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type='rental' AND h.booking_id=b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
               v.vehicle_number, e.name AS driver
        FROM rental_car_bookings b
        JOIN passengers p ON p.id=b.passenger_id
        LEFT JOIN vehicles v ON v.id=b.vehicle_id
        LEFT JOIN trip_assignments ta ON ta.id=(SELECT MAX(t2.id) FROM trip_assignments t2 WHERE t2.booking_type='rental' AND t2.booking_id=b.id AND t2.ended_at IS NULL)
        LEFT JOIN employees e ON e.id=ta.employee_id
        WHERE b.start_date < ? AND b.end_date >= ?{$companySql}
        ORDER BY starts_on, starts_at";
$st = db()->prepare($sql);
$types = 'ssss' . $companyTypes . 'ss' . $companyTypes;
$params = $companyId === null ? [$from, $to, $to, $from] : [$from, $to, $companyId, $to, $from, $companyId];
$st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$events = [];
foreach ($rows as $row) {
    $title = strtoupper($row['kind']) . ' · ' . $row['document_number'] . ' · ' . $row['passenger'];
    $details = array_filter([
        'Status: ' . $row['status'],
        $row['vehicle_number'] ? 'Armada: ' . $row['vehicle_number'] : null,
        $row['driver'] ? 'Driver: ' . $row['driver'] : null,
    ]);
    $events[] = [
        'id' => $row['kind'] . '-' . $row['id'], 'title' => $title,
        'start' => $row['starts_on'] . 'T' . $row['starts_at'],
        'end' => $row['kind'] === 'rental' ? $row['ends_on'] : null,
        'url' => APP_URL . '/pages/' . $row['kind'] . '/bookings/detail.php?id=' . (int) $row['id'],
        'backgroundColor' => $row['kind'] === 'rental' ? '#198754' : '#0d6efd',
        'borderColor' => $row['kind'] === 'rental' ? '#198754' : '#0d6efd',
        'extendedProps' => ['details' => implode(' · ', $details)],
    ];
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
