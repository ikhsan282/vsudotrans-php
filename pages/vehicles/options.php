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
    $cid = $reqCid;
} elseif ($activeCid > 0) {
    $cid = $activeCid;
} elseif (is_super_admin()) {
    $cid = 0;
} else {
    echo '[]';
    exit;
}

$displayCid = $cid;
$catId      = max(0, (int) ($_GET['category_id'] ?? 0));
$q          = trim((string) ($_GET['q'] ?? ''));
$limit      = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
$db         = db();

$baseSelect = "SELECT v.id, v.company_id, v.vehicle_category, v.vehicle_number, v.vehicle_color, v.seat,
                      bv.name AS brand_name, mu.name AS model_name,
                      COALESCE(NULLIF(v.vehicle_category, ''), cv.name, '') AS category_name,
                      c.code AS company_code,
                      CONCAT(v.vehicle_number, ' - ', COALESCE(bv.name, ''), ' ', COALESCE(mu.name, ''),
                             IF(v.vehicle_color != '' OR v.seat > 0, CONCAT(' (', CONCAT_WS(', ', NULLIF(v.vehicle_color, ''), IF(v.seat > 0, CONCAT(v.seat, ' Kursi'), NULL)), ')'), ''),
                             IF(v.company_id != ?, CONCAT(' [', c.code, ']'), '')) AS name
                 FROM vehicles v
                 LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                 LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                 LEFT JOIN category_vehicles cv ON cv.id = v.category_vehicle_id
                 LEFT JOIN companies c ON c.id = v.company_id";

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

$runQuery = static function (string $extraWhere, string $extraTypes, array $extraParams, int $lim) use ($db, $baseSelect, $displayCid, $cid, $catId, $append): void {
    $compCond = $cid > 0 ? ' AND v.company_id = ?' : '';
    $st = $db->prepare(
        "{$baseSelect}
         WHERE v.is_active = 1{$compCond}{$extraWhere}
         ORDER BY (CASE WHEN ? > 0 AND v.category_vehicle_id = ? THEN 0 ELSE 1 END) ASC, v.vehicle_number ASC
         LIMIT ?"
    );
    if ($cid > 0) {
        $types  = 'ii' . $extraTypes . 'iii';
        $params = array_merge([$displayCid, $cid], $extraParams, [$catId, $catId, $lim]);
    } else {
        $types  = 'i' . $extraTypes . 'iii';
        $params = array_merge([$displayCid], $extraParams, [$catId, $catId, $lim]);
    }
    $st->bind_param($types, ...$params);
    $st->execute();
    $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
};

if ($q === '') {
    $runQuery('', '', [], $limit);
} else {
    $escaped = addcslashes($q, '%_\\');
    $prefix  = $escaped . '%';

    // Query 1: prefix match pada nomor polisi (memakai ix_vehicles_comp_active_*)
    $runQuery(' AND v.vehicle_number LIKE ?', 's', [$prefix], $limit);

    // Fallback pencarian merk / model / plat tengah / warna / kategori kendaraan bila hasil masih kurang dari limit
    if (count($rows) < $limit && mb_strlen($q) >= 2) {
        $rem  = $limit - count($rows);
        $like = '%' . $escaped . '%';
        $runQuery(
            ' AND (v.vehicle_number LIKE ? OR bv.name LIKE ? OR mu.name LIKE ? OR v.vehicle_color LIKE ? OR v.vehicle_category LIKE ?)',
            'sssss',
            [$like, $like, $like, $like, $like],
            $rem
        );
    }
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'value'         => (string) (int) $r['id'],
        'id'            => (int) $r['id'],
        'text'          => (string) $r['name'],
        'name'          => (string) $r['name'],
        'category'      => '',
        'plate'         => (string) ($r['vehicle_number'] ?? ''),
        'brand'         => (string) ($r['brand_name'] ?? ''),
        'model'         => (string) ($r['model_name'] ?? ''),
        'color'         => (string) ($r['vehicle_color'] ?? ''),
        'seat'          => (int) ($r['seat'] ?? 0),
        'category_name' => (string) ($r['category_name'] ?? ''),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
