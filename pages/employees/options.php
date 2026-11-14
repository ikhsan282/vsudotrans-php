<?php
/**
 * AJAX Endpoint: Pencarian karyawan berbasis index + LIMIT untuk Tom Select remote search (aman jutaan data).
 * GET: q, limit (maks 50), company_id (opsional), job_position (opsional, mis. Driver), status (aktif|all, default aktif)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../travel/_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$allowedPerms = [
    'employees.view', 'employees.manage',
    'attendance.view', 'attendance.manage',
    'dispatch.view', 'dispatch.manage',
    'travel.view', 'travel.manage',
    'rental.view', 'rental.manage',
    'masters.view', 'hr.view', 'hr.manage',
];
$hasPerm = false;
foreach ($allowedPerms as $perm) {
    if (can($perm)) {
        $hasPerm = true;
        break;
    }
}
if (!$hasPerm) {
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
    $cid = 0; // Super Admin mode semua perusahaan
} else {
    echo '[]';
    exit;
}

$q       = trim((string) ($_GET['q'] ?? ''));
$jobPos  = trim((string) ($_GET['job_position'] ?? ''));
$stMode  = strtolower(trim((string) ($_GET['status'] ?? 'aktif')));
$limit   = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
$db      = db();

$whereParts  = [];
$baseTypes   = '';
$baseParams  = [];

if ($cid > 0) {
    $whereParts[] = 'company_id = ?';
    $baseTypes   .= 'i';
    $baseParams[] = $cid;
}
if ($jobPos !== '') {
    $whereParts[] = 'job_position = ?';
    $baseTypes   .= 's';
    $baseParams[] = $jobPos;
}
if ($stMode !== 'all') {
    $whereParts[] = "status = 'aktif'";
}

$baseWhereSql = $whereParts ? implode(' AND ', $whereParts) : '1=1';

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

$runQuery = static function (string $extraWhere, string $extraTypes, array $extraParams, string $orderCol, int $lim) use ($db, $baseWhereSql, $baseTypes, $baseParams, $append): void {
    $sql = "SELECT id, code, name, phone_number, COALESCE(job_title, '') AS job_title, COALESCE(job_position, '') AS job_position
              FROM employees
             WHERE {$baseWhereSql}{$extraWhere}
             ORDER BY {$orderCol} ASC
             LIMIT ?";
    $types  = $baseTypes . $extraTypes . 'i';
    $params = array_merge($baseParams, $extraParams, [$lim]);
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
    $st->close();
};

if ($q === '') {
    $runQuery('', '', [], 'name', $limit);
} else {
    $escaped     = addcslashes($q, '%_\\');
    $prefix      = $escaped . '%';
    $isPhoneLike = (bool) preg_match('/^[0-9+]/', $q);

    if ($isPhoneLike) {
        $phoneNormPfx = phone_search_prefix($q);
        $phonePrefix  = $phoneNormPfx !== null ? (addcslashes($phoneNormPfx, '%_\\') . '%') : $prefix;
        // Query 1: prefix nomor telepon (ix_employees_company_phone)
        $runQuery(' AND phone_number LIKE ?', 's', [$phonePrefix], 'phone_number', $limit);
        if ($phonePrefix !== $prefix && count($rows) < $limit) {
            $runQuery(' AND phone_number LIKE ?', 's', [$prefix], 'phone_number', $limit - count($rows));
        }

        // Query 2: prefix kode/NIK karyawan (uq_employees_company_code)
        if (count($rows) < $limit) {
            $runQuery(' AND code LIKE ?', 's', [$prefix], 'code', $limit - count($rows));
        }

        // Query 3: prefix nama (jika user mengetik huruf/angka campuran)
        if (count($rows) < $limit) {
            $runQuery(' AND name LIKE ?', 's', [$prefix], 'name', $limit - count($rows));
        }
    } else {
        // Query 1: prefix nama karyawan via ix_employees_company_status_name / ix_employees_company_pos_status_name
        $runQuery(' AND name LIKE ?', 's', [$prefix], 'name', $limit);

        // Query 2: prefix NIK/kode via uq_employees_company_code
        if (count($rows) < $limit && mb_strlen($q) <= 20) {
            $runQuery(' AND code LIKE ?', 's', [$prefix], 'code', $limit - count($rows));
        }
    }

    // Fallback substring terindeks-limit hanya bila hasil prefix masih sedikit & query >= 3 karakter
    if (count($rows) < min(10, $limit) && mb_strlen($q) >= 3) {
        $like = '%' . $escaped . '%';
        $runQuery(' AND (name LIKE ? OR code LIKE ? OR phone_number LIKE ?)', 'sss', [$like, $like, $like], 'name', $limit - count($rows));
    }
}

$out = [];
foreach ($rows as $r) {
    $code = trim((string) ($r['code'] ?? ''));
    $rawName = (string) $r['name'];
    $out[] = [
        'value'        => (string) (int) $r['id'],
        'id'           => (int) $r['id'],
        'text'         => $code !== '' ? ($code . ' - ' . $rawName) : $rawName,
        'code'         => $code,
        'raw_name'     => $rawName,
        'phone'        => trim((string) ($r['phone_number'] ?? '')),
        'job_title'    => (string) ($r['job_title'] ?? ''),
        'job_position' => (string) ($r['job_position'] ?? ''),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
