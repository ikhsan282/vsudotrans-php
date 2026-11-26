<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_once __DIR__ . '/../../includes/invoice_service.php';
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

$requiredPerm = $kind === 'rental' ? 'rental.manage' : 'travel.manage';
if (!can($requiredPerm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Anda tidak memiliki izin menjadwalkan ulang pemesanan ini.']);
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

$curStatus = booking_current_status($kind, $bookingId);
if (in_array($curStatus, ['done', 'cancelled'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan berstatus ' . booking_status_label($curStatus) . ' tidak dapat digeser dari kalender.']);
    exit;
}

$newStart = parse_valid_ymd($_POST['start_date'] ?? null);
if ($newStart === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tanggal baru tidak valid (wajib YYYY-MM-DD).']);
    exit;
}

$reqEnd = parse_valid_ymd($_POST['end_date'] ?? null);
$reqTime = trim((string) ($_POST['time'] ?? ''));
if ($reqTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $reqTime)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Format jam keberangkatan tidak valid (HH:MM).']);
    exit;
}

$origTimeFull = trim((string) ($b['time'] ?? '05:00:00'));
if (preg_match('/^\d{2}:\d{2}$/', $origTimeFull)) {
    $origTimeFull .= ':00';
} elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $origTimeFull)) {
    $origTimeFull = '05:00:00';
}
$newTimeFull = $reqTime !== '' ? ($reqTime . ':00') : $origTimeFull;

if ($kind === 'rental') {
    $origDays = rental_duration_days((string) ($b['start_date'] ?: $b['date']), (string) ($b['end_date'] ?: $b['date']));
    $newEnd = ($reqEnd !== null && $reqEnd >= $newStart)
        ? $reqEnd
        : date('Y-m-d', strtotime($newStart . ' +' . ($origDays - 1) . ' days'));
} else {
    $newEnd = $newStart;
}

$reason = trim((string) ($_POST['reason'] ?? ''));
if (mb_strlen($reason) > 200) {
    $reason = mb_substr($reason, 0, 200);
}
if ($reason === '') {
    $reason = 'Reschedule dari Kalender Operasional';
}

$db = db();
$vehId = (int) ($b['vehicle_id'] ?? 0);
if ($kind === 'rental' && $vehId > 0) {
    $stC = $db->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date,
                                COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type = 'rental' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS cur_status
                           FROM rental_car_bookings b
                          WHERE b.company_id = ?
                            AND b.vehicle_id = ?
                            AND b.id <> ?
                            AND b.start_date <= ?
                            AND b.end_date >= ?
                         HAVING cur_status <> 'cancelled'
                          LIMIT 1");
    $stC->bind_param('iiiss', $bookingCid, $vehId, $bookingId, $newEnd, $newStart);
    $stC->execute();
    $conflict = $stC->get_result()->fetch_assoc();
    $stC->close();
    if ($conflict) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => 'Bentrok armada dengan Rental ' . $conflict['document_number'] . ' (' . $conflict['start_date'] . ' s/d ' . $conflict['end_date'] . ').',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$user = $_SESSION['username'] ?? null;
$db->begin_transaction();
try {
    if ($kind === 'rental') {
        $stUp = $db->prepare('UPDATE rental_car_bookings SET date = ?, start_date = ?, end_date = ?, time = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
        $stUp->bind_param('sssssii', $newStart, $newStart, $newEnd, $newTimeFull, $user, $bookingId, $bookingCid);
    } else {
        $stUp = $db->prepare('UPDATE travel_ticket_bookings SET date = ?, time = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
        $stUp->bind_param('sssii', $newStart, $newTimeFull, $user, $bookingId, $bookingCid);
    }
    $stUp->execute();
    $stUp->close();

    $stInv = $db->prepare('SELECT id FROM invoices WHERE booking_type = ? AND booking_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1');
    $stInv->bind_param('sii', $kind, $bookingId, $bookingCid);
    $stInv->execute();
    $inv = $stInv->get_result()->fetch_assoc();
    $stInv->close();
    if ($inv) {
        invoice_snapshot((int) $inv['id'], 'Reschedule: ' . $reason, $user);
        $invNote = 'Reschedule ke ' . $newStart . ($kind === 'rental' && $newEnd !== $newStart ? ' s/d ' . $newEnd : '') . ' ' . substr($newTimeFull, 0, 5);
        $stUN = $db->prepare('UPDATE invoices SET note = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
        $stUN->bind_param('ssi', $invNote, $user, $inv['id']);
        $stUN->execute();
        $stUN->close();
    }

    if ($kind === 'rental') {
        finance_sync_booking_bbm_to_cash($bookingId, $bookingCid, $user);
    }
    $db->commit();
    audit('booking.' . $kind . '.reschedule', "Pemesanan #{$bookingId} -> {$newStart}" . ($kind === 'rental' ? "..{$newEnd}" : '') . ' ' . substr($newTimeFull, 0, 5) . ' (Kalender)');

    echo json_encode([
        'ok'        => true,
        'starts_on' => $newStart,
        'ends_on'   => $newEnd,
        'starts_at' => substr($newTimeFull, 0, 5),
        'message'   => 'Jadwal ' . ($b['document_number'] ?? '') . ' berhasil dipindah ke ' . $newStart . ($kind === 'rental' && $newEnd !== $newStart ? ' – ' . $newEnd : '') . '.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $db->rollback();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
