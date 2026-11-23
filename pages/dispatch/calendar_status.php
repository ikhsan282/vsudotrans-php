<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_permission('dispatch.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman lalu coba lagi.']);
    exit;
}

$kind = strtolower(trim((string) ($_POST['kind'] ?? '')));
if (!in_array($kind, ['travel', 'rental'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Jenis pemesanan tidak valid.']);
    exit;
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID pemesanan tidak valid.']);
    exit;
}

$toStatus = strtolower(trim((string) ($_POST['to_status'] ?? '')));
$validStatuses = array_keys(booking_status_options());
if (!in_array($toStatus, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Status tujuan tidak valid.']);
    exit;
}

$requiredPerm = $kind === 'rental' ? 'rental.manage' : 'travel.manage';
if (!can($requiredPerm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Anda tidak memiliki izin mengubah status pemesanan ini.']);
    exit;
}

$activeCid = active_company_id();
if ($activeCid === null && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Pilih perusahaan aktif terlebih dahulu.']);
    exit;
}

$b = booking_find($kind, $bookingId);
if (!$b) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan tidak ditemukan.']);
    exit;
}

$bookingCid = (int) $b['company_id'];
if (!is_super_admin() && $bookingCid !== $activeCid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan bukan milik perusahaan aktif.']);
    exit;
}

$reason = trim((string) ($_POST['reason'] ?? ''));
if (mb_strlen($reason) > 200) {
    $reason = mb_substr($reason, 0, 200);
}
if ($reason === '') {
    $reason = 'Ubah status cepat dari Kalender Operasional';
}

try {
    $user = $_SESSION['username'] ?? null;
    booking_change_status($kind, $bookingId, $bookingCid, $toStatus, $reason, $user);
    audit('booking.' . $kind . '.status', "Pemesanan #{$bookingId} -> {$toStatus} (Kalender)");
    $label = booking_status_label($toStatus);
    echo json_encode([
        'ok'           => true,
        'status'       => $toStatus,
        'status_label' => $label,
        'message'      => 'Status pemesanan diperbarui menjadi ' . $label . '.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
