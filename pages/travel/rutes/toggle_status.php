<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');

$acceptJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($acceptJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    redirect('pages/travel/rutes/index.php');
}

verify_csrf();

$allowedQueryKeys = ['q', 'status', 'company_id', 'pickup_city_id', 'delivery_city_id', 'category_vehicle_id', 'page'];
$parsedQuery = [];
parse_str(ltrim(trim((string) ($_POST['return_query'] ?? '')), '?'), $rawQuery);
if (is_array($rawQuery)) {
    foreach ($allowedQueryKeys as $qk) {
        if (isset($rawQuery[$qk]) && !is_array($rawQuery[$qk]) && (string) $rawQuery[$qk] !== '') {
            $parsedQuery[$qk] = (string) $rawQuery[$qk];
        }
    }
}
$backUrl = 'pages/travel/rutes/index.php' . ($parsedQuery ? ('?' . http_build_query($parsedQuery)) : '');

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, company_id, code, is_active FROM rutes WHERE id = ? LIMIT 1');
$st->bind_param('i', $id);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if (!$r) {
    if ($acceptJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Rute tidak ditemukan.']);
        exit;
    }
    flash('error', 'Rute tidak ditemukan.');
    redirect($backUrl);
}

$recCid = (int) $r['company_id'];
if ($acceptJson && !is_super_admin() && $recCid !== active_company_id()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Anda tidak berhak mengubah status rute perusahaan lain.']);
    exit;
}
assert_company_access($recCid);

$newActive = empty($r['is_active']) ? 1 : 0;
$user = $_SESSION['username'] ?? null;

$stUp = db()->prepare('UPDATE rutes SET is_active = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
$stUp->bind_param('isii', $newActive, $user, $id, $recCid);
$stUp->execute();
$stUp->close();

audit('rute.toggle_status', "Rute #{$id} {$r['code']} -> " . ($newActive ? 'Aktif' : 'Nonaktif'));
$msg = 'Status rute ' . $r['code'] . ' diubah menjadi ' . ($newActive ? 'Aktif' : 'Nonaktif') . '.';

if ($acceptJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'        => true,
        'id'        => $id,
        'is_active' => $newActive,
        'message'   => $msg,
    ]);
    exit;
}

flash('success', $msg);
redirect($backUrl);
