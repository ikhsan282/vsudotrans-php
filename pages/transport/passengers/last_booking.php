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
$hit = passenger_last_booking_locations($cid, $passengerId, $prefType);

if (!$hit) {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

echo json_encode([
    'ok'           => true,
    'found'        => true,
    'doc_no'       => $hit['doc_no'],
    'booking_type' => $hit['booking_type'],
    'date'         => $hit['date'],
    'pickup'       => $hit['pickup'],
    'delivery'     => $hit['delivery'],
], JSON_UNESCAPED_UNICODE);
