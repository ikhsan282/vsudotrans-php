<?php
/**
 * Antrean notifikasi WhatsApp keluar (`wa_outbox`) untuk mode pull Hermes:
 * - GET  /api/bot/outbox.php?limit=20  -> ambil event `pending` yang siap dikirim oleh Hermes.
 * - POST /api/bot/outbox.php           -> konfirmasi pengiriman `{ "event_id": "...", "status": "sent"|"failed", "error": "..." }`
 */
require __DIR__ . '/_bootstrap.php';

$ctx     = bot_boot(['GET', 'POST'], false);
$db      = $ctx['db'];
$company = $ctx['company'];
$cid     = (int) $company['id'];
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $st = $db->prepare(
        "SELECT id, company_id, booking_type, booking_id, event_id, event_type, phone, message, payload, attempts, created_date
           FROM wa_outbox
          WHERE company_id = ? AND status = 'pending' AND (next_try_at IS NULL OR next_try_at <= NOW())
          ORDER BY id ASC LIMIT ?"
    );
    $st->bind_param('ii', $cid, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'           => (int) $r['id'],
            'event_id'     => $r['event_id'] ?: ('outbox-' . $r['id']),
            'event_type'   => $r['event_type'] ?: 'notification',
            'booking_type' => $r['booking_type'],
            'booking_id'   => (int) $r['booking_id'],
            'phone'        => $r['phone'],
            'message'      => $r['message'],
            'payload'      => !empty($r['payload']) ? json_decode((string) $r['payload'], true) : null,
            'attempts'     => (int) $r['attempts'],
            'created_at'   => $r['created_date'],
        ];
    }
    bot_reply(200, ['items' => $items, 'count' => count($items)]);
}

// POST: konfirmasi pengiriman
$body    = $ctx['body'];
$eventId = trim((string) ($body['event_id'] ?? ''));
$rowId   = (int) ($body['id'] ?? 0);
$status  = (string) ($body['status'] ?? '');

if (($eventId === '' && $rowId <= 0) || !in_array($status, ['sent', 'failed'], true)) {
    bot_error(400, 'INVALID_INPUT', 'Wajib menyertakan event_id (atau id) dan status (sent|failed).');
}

if ($status === 'sent') {
    if ($eventId !== '') {
        $st = $db->prepare("UPDATE wa_outbox SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE company_id = ? AND event_id = ?");
        $st->bind_param('is', $cid, $eventId);
    } else {
        $st = $db->prepare("UPDATE wa_outbox SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE company_id = ? AND id = ?");
        $st->bind_param('ii', $cid, $rowId);
    }
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();
} else {
    $err = substr(trim((string) ($body['error'] ?? 'failed by bot')), 0, 500);
    if ($eventId !== '') {
        $st = $db->prepare(
            "UPDATE wa_outbox
                SET attempts = attempts + 1,
                    status = IF(attempts >= 5, 'failed', 'pending'),
                    next_try_at = DATE_ADD(NOW(), INTERVAL POW(2, LEAST(attempts, 6)) MINUTE),
                    last_error = ?
              WHERE company_id = ? AND event_id = ?"
        );
        $st->bind_param('sis', $err, $cid, $eventId);
    } else {
        $st = $db->prepare(
            "UPDATE wa_outbox
                SET attempts = attempts + 1,
                    status = IF(attempts >= 5, 'failed', 'pending'),
                    next_try_at = DATE_ADD(NOW(), INTERVAL POW(2, LEAST(attempts, 6)) MINUTE),
                    last_error = ?
              WHERE company_id = ? AND id = ?"
        );
        $st->bind_param('sii', $err, $cid, $rowId);
    }
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();
}

if ($affected === 0) {
    bot_error(404, 'EVENT_NOT_FOUND', 'Event outbox tidak ditemukan.');
}
bot_reply(200, ['event_id' => $eventId ?: ('outbox-' . $rowId), 'status' => $status]);

