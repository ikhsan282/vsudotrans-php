<?php
/**
 * AJAX Endpoint: Pencarian booking siap ditugaskan (rental & travel tanpa assignment aktif)
 * berbasis index + LIMIT untuk Tom Select remote search (aman untuk jutaan booking).
 * GET: q (opsional), limit (maks 50), company_id (opsional)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/dispatch_service.php';
require_once __DIR__ . '/../travel/_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

if (!can('dispatch.view') && !can('dispatch.manage')) {
    http_response_code(403);
    echo json_encode([]);
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
    echo '[]';
    exit;
}

$q     = trim((string) ($_GET['q'] ?? ''));
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
$db    = db();

$rows = [];
$seen = [];
$append = static function (string $type, array $batch) use (&$rows, &$seen, $limit): void {
    foreach ($batch as $r) {
        $key = $type . ':' . (int) $r['id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $label = strtoupper($type) . ' · ' . $r['document_number'] . ' · ' . $r['date'] . ' ' . substr((string) $r['time'], 0, 5);
        $rows[] = [
            'value' => $key,
            'id'    => $key,
            'text'  => $label,
            'code'  => (string) $r['document_number'],
            'date'  => (string) ($r['date'] ?? ''),
        ];
        if (count($rows) >= $limit) {
            break;
        }
    }
};

if ($q === '') {
    $perTypeLim = max(1, min(25, (int) ceil($limit / 2)));
    foreach (dispatch_assignable($cid, $perTypeLim) as $b) {
        $rows[] = [
            'value' => $b['booking_type'] . ':' . $b['booking_id'],
            'id'    => $b['booking_type'] . ':' . $b['booking_id'],
            'text'  => $b['label'],
            'code'  => (string) $b['document_number'],
            'date'  => (string) ($b['date'] ?? ''),
        ];
        if (count($rows) >= $limit) {
            break;
        }
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

// Prefix nomor dokumen (menggunakan uq_rental_bookings_doc / uq_travel_bookings_doc)
$cleanQ   = preg_replace('/^(RENTAL|TRAVEL)\s*[·:\-\s]+/i', '', $q) ?? $q;
$escaped  = addcslashes(trim($cleanQ), '%_\\');
$prefix   = $escaped . '%';
$perType  = max(1, (int) ceil($limit / count(DISPATCH_TYPES)));

foreach (DISPATCH_TYPES as $type) {
    if (count($rows) >= $limit) {
        break;
    }
    $table = booking_table($type);
    $rem   = min($perType, $limit - count($rows));
    $st = $db->prepare(
        "SELECT b.id, b.document_number, b.date, b.time
           FROM {$table} b
          WHERE b.company_id = ?
            AND b.document_number LIKE ?
            AND NOT EXISTS (
                SELECT 1 FROM trip_assignments t
                 WHERE t.booking_type = ? AND t.booking_id = b.id AND t.ended_at IS NULL
            )
          ORDER BY b.date DESC, b.id DESC
          LIMIT ?"
    );
    $st->bind_param('issi', $cid, $prefix, $type, $rem);
    $st->execute();
    $append($type, $st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
}

// Fallback nama penumpang berbasis prefix (memakai ix_passengers_company_name) bila hasil masih sedikit & q >= 2 huruf
if (count($rows) < min(10, $limit) && mb_strlen($cleanQ) >= 2 && preg_match('/[a-zA-Z]/', $cleanQ)) {
    foreach (DISPATCH_TYPES as $type) {
        if (count($rows) >= $limit) {
            break;
        }
        $table = booking_table($type);
        $rem   = $limit - count($rows);
        $st = $db->prepare(
            "SELECT b.id, b.document_number, b.date, b.time
               FROM {$table} b
               JOIN passengers p ON p.id = b.passenger_id AND p.company_id = b.company_id
              WHERE b.company_id = ?
                AND p.name LIKE ?
                AND NOT EXISTS (
                    SELECT 1 FROM trip_assignments t
                     WHERE t.booking_type = ? AND t.booking_id = b.id AND t.ended_at IS NULL
                )
              ORDER BY b.date DESC, b.id DESC
              LIMIT ?"
        );
        $st->bind_param('issi', $cid, $prefix, $type, $rem);
        $st->execute();
        $append($type, $st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();
    }
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
