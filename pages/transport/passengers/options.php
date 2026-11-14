<?php
/**
 * AJAX Endpoint: Pencarian penumpang berbasis index + LIMIT untuk Tom Select remote search (aman untuk jutaan data).
 * GET: q, limit (maks 50), company_id (opsional jika punya akses)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

if (!transport_can_view()) {
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

$append = static function (array $batch) use (&$rows, &$seen, $limit): void {
    foreach ($batch as $r) {
        $id = (int) $r['id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $rows[] = $r;
        if (count($rows) >= $limit) {
            break;
        }
    }
};

if ($q === '') {
    $st = $db->prepare(
        "SELECT id, code, name, phone_number
           FROM passengers
          WHERE company_id = ?
          ORDER BY name ASC
          LIMIT ?"
    );
    $st->bind_param('ii', $cid, $limit);
    $st->execute();
    $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
} else {
    $escaped = addcslashes($q, '%_\\');
    $prefix  = $escaped . '%';
    $isPhoneLike = (bool) preg_match('/^[0-9+]/', $q);

    if ($isPhoneLike) {
        $phoneNormPfx = phone_search_prefix($q);
        $phonePrefix  = $phoneNormPfx !== null ? (addcslashes($phoneNormPfx, '%_\\') . '%') : $prefix;
        // Query 1: prefix match via ix_passengers_company_phone
        $st = $db->prepare(
            "SELECT id, code, name, phone_number
               FROM passengers
              WHERE company_id = ? AND phone_number LIKE ?
              ORDER BY phone_number ASC
              LIMIT ?"
        );
        $st->bind_param('isi', $cid, $phonePrefix, $limit);
        $st->execute();
        $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();

        if ($phonePrefix !== $prefix && count($rows) < $limit) {
            $rem = $limit - count($rows);
            $st = $db->prepare(
                "SELECT id, code, name, phone_number
                   FROM passengers
                  WHERE company_id = ? AND phone_number LIKE ?
                  ORDER BY phone_number ASC
                  LIMIT ?"
            );
            $st->bind_param('isi', $cid, $prefix, $rem);
            $st->execute();
            $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
            $st->close();
        }

        if (count($rows) < $limit) {
            $rem = $limit - count($rows);
            $st2 = $db->prepare(
                "SELECT id, code, name, phone_number
                   FROM passengers
                  WHERE company_id = ? AND name LIKE ?
                  ORDER BY name ASC
                  LIMIT ?"
            );
            $st2->bind_param('isi', $cid, $prefix, $rem);
            $st2->execute();
            $append($st2->get_result()->fetch_all(MYSQLI_ASSOC));
            $st2->close();
        }
    } else {
        // Query 1: prefix match via ix_passengers_company_name
        $st = $db->prepare(
            "SELECT id, code, name, phone_number
               FROM passengers
              WHERE company_id = ? AND name LIKE ?
              ORDER BY name ASC
              LIMIT ?"
        );
        $st->bind_param('isi', $cid, $prefix, $limit);
        $st->execute();
        $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();

        // Query 2: kode pelanggan via uq_passengers_code (bila input pendek/kode)
        if (count($rows) < $limit && mb_strlen($q) <= 15) {
            $rem = $limit - count($rows);
            $st2 = $db->prepare(
                "SELECT id, code, name, phone_number
                   FROM passengers
                  WHERE company_id = ? AND code LIKE ?
                  ORDER BY code ASC
                  LIMIT ?"
            );
            $st2->bind_param('isi', $cid, $prefix, $rem);
            $st2->execute();
            $append($st2->get_result()->fetch_all(MYSQLI_ASSOC));
            $st2->close();
        }
    }

    // Fallback substring hanya bila hasil prefix masih sedikit & query minimal 3 karakter
    if (count($rows) < min(10, $limit) && mb_strlen($q) >= 3) {
        $rem  = $limit - count($rows);
        $like = '%' . $escaped . '%';
        $st3 = $db->prepare(
            "SELECT id, code, name, phone_number
               FROM passengers
              WHERE company_id = ? AND (name LIKE ? OR phone_number LIKE ?)
              ORDER BY name ASC
              LIMIT ?"
        );
        $st3->bind_param('issi', $cid, $like, $like, $rem);
        $st3->execute();
        $append($st3->get_result()->fetch_all(MYSQLI_ASSOC));
        $st3->close();
    }
}

$out = [];
foreach ($rows as $r) {
    $phone = trim((string) ($r['phone_number'] ?? ''));
    $label = (string) $r['name'] . ($phone !== '' ? ' - ' . $phone : '');
    $out[] = [
        'value' => (string) (int) $r['id'],
        'id'    => (int) $r['id'],
        'text'  => $label,
        'code'  => (string) ($r['code'] ?? ''),
        'phone' => $phone,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
