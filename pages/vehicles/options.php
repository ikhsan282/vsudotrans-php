<?php
/**
 * AJAX Endpoint: Pencarian kendaraan aktif berbasis index + LIMIT untuk Tom Select remote search (aman jutaan data).
 * GET: category_id (opsional), q (opsional), limit (maks 50), company_id (opsional)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../travel/_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

if (!can('vehicles.view') && !can('rental.view') && !can('travel.view') && !can('dispatch.view')) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$activeCid = (int) (active_company_id() ?? 0);
$reqCid    = (int) ($_GET['company_id'] ?? $activeCid);
$allowed   = accessible_company_ids($activeCid);

if ($reqCid > 0 && (is_super_admin() || in_array($reqCid, $allowed, true))) {
    $displayCid = $reqCid;
    if (!in_array($displayCid, $allowed, true)) {
        $allowed[] = $displayCid;
    }
} else {
    $displayCid = $activeCid;
}

$allowed = array_values(array_unique(array_filter($allowed, static fn($x) => $x > 0)));
if (!$allowed) {
    echo '[]';
    exit;
}

$compIn = implode(',', $allowed);
$catId  = (int) ($_GET['category_id'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));
$limit  = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
$db     = db();

$baseSelect = "SELECT v.id, v.company_id, v.category_vehicle_id, v.vehicle_number, v.vehicle_color, v.seat,
                      bv.name AS brand_name, mu.name AS model_name, cv.name AS category_name, c.code AS company_code,
                      CONCAT(v.vehicle_number, ' - ', COALESCE(bv.name, ''), ' ', COALESCE(mu.name, ''),
                             IF(v.vehicle_color != '' OR v.seat > 0, CONCAT(' (', CONCAT_WS(', ', NULLIF(v.vehicle_color, ''), IF(v.seat > 0, CONCAT(v.seat, ' Kursi'), NULL)), ')'), ''),
                             IF(v.company_id != ?, CONCAT(' [', c.code, ']'), '')) AS name
                 FROM vehicles v
                 LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                 LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                 LEFT JOIN category_vehicles cv ON cv.id = v.category_vehicle_id
                 LEFT JOIN companies c ON c.id = v.company_id";

$catWhere = $catId > 0 ? " AND v.category_vehicle_id = {$catId}" : '';

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
        "{$baseSelect}
         WHERE v.is_active = 1 AND v.company_id IN ({$compIn}){$catWhere}
         ORDER BY v.vehicle_number ASC
         LIMIT ?"
    );
    $st->bind_param('ii', $displayCid, $limit);
    $st->execute();
    $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
} else {
    $escaped = addcslashes($q, '%_\\');
    $prefix  = $escaped . '%';

    // Query 1: prefix match pada nomor polisi (memakai ix_vehicles_comp_active_*)
    $st = $db->prepare(
        "{$baseSelect}
         WHERE v.is_active = 1 AND v.company_id IN ({$compIn}){$catWhere} AND v.vehicle_number LIKE ?
         ORDER BY v.vehicle_number ASC
         LIMIT ?"
    );
    $st->bind_param('isi', $displayCid, $prefix, $limit);
    $st->execute();
    $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();

    // Fallback pencarian merk / model / plat tengah / warna bila hasil masih kurang dari limit
    if (count($rows) < $limit && mb_strlen($q) >= 2) {
        $rem  = $limit - count($rows);
        $like = '%' . $escaped . '%';
        $st2 = $db->prepare(
            "{$baseSelect}
             WHERE v.is_active = 1 AND v.company_id IN ({$compIn}){$catWhere}
               AND (v.vehicle_number LIKE ? OR bv.name LIKE ? OR mu.name LIKE ? OR v.vehicle_color LIKE ?)
             ORDER BY v.vehicle_number ASC
             LIMIT ?"
        );
        $st2->bind_param('issssi', $displayCid, $like, $like, $like, $like, $rem);
        $st2->execute();
        $append($st2->get_result()->fetch_all(MYSQLI_ASSOC));
        $st2->close();
    }
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'value'         => (string) (int) $r['id'],
        'id'            => (int) $r['id'],
        'text'          => (string) $r['name'],
        'name'          => (string) $r['name'],
        'category'      => (string) ($r['category_vehicle_id'] ?? ''),
        'plate'         => (string) ($r['vehicle_number'] ?? ''),
        'brand'         => (string) ($r['brand_name'] ?? ''),
        'model'         => (string) ($r['model_name'] ?? ''),
        'color'         => (string) ($r['vehicle_color'] ?? ''),
        'seat'          => (int) ($r['seat'] ?? 0),
        'category_name' => (string) ($r['category_name'] ?? ''),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
