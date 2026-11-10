<?php
/**
 * Audit Trail — daftar log aktivitas (READ-ONLY), terpartisi per bulan (retensi 12 bulan),
 * dan di-scope per perusahaan (company_id).
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('audit.view');

$part_info = audit_maintain_partitions(12);

$from   = trim((string) ($_GET['from'] ?? ''));
$to     = trim((string) ($_GET['to'] ?? ''));
$f_user = trim((string) ($_GET['user'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));

$valid_date = static fn(string $d): bool => (bool) (($dt = DateTime::createFromFormat('Y-m-d', $d)) && $dt->format('Y-m-d') === $d);
if ($from !== '' && !$valid_date($from)) {
    $from = '';
}
if ($to !== '' && !$valid_date($to)) {
    $to = '';
}
$like = static fn(string $s): string => '%' . addcslashes($s, '%_\\') . '%';

$companies = accessible_companies();
$active_cid = active_company_id();

if (is_super_admin()) {
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $f_company = max(0, (int) $_GET['company_id']);
    } elseif ($active_cid !== null) {
        $f_company = $active_cid;
    } else {
        $f_company = !empty($companies) ? (int) $companies[0]['id'] : 0;
    }

    if ($f_company > 0) {
        $where  = ['a.company_id = ?'];
        $types  = 'i';
        $params = [$f_company];
    } else {
        $where  = ['1=1'];
        $types  = '';
        $params = [];
    }
} else {
    $f_company = (int) ($active_cid ?? 0);
    [$wsql, $wtypes, $wparams] = company_scope('a.company_id');
    $where  = [$wsql];
    $types  = $wtypes;
    $params = $wparams;
}

if ($from !== '') {
    $where[] = 'a.created_date >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.created_date < ?';
    $types .= 's';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
}
if ($f_user !== '') {
    $where[] = 'a.username LIKE ?';
    $types .= 's';
    $params[] = $like($f_user);
}
if ($action !== '') {
    $where[] = 'a.action LIKE ?';
    $types .= 's';
    $params[] = $like($action);
}
$where_sql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM audit_trails a WHERE {$where_sql}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT a.created_date, a.username, a.action, a.description, a.ip_address, c.name AS company_name
                   FROM audit_trails a
                   LEFT JOIN companies c ON c.id = a.company_id
                   WHERE {$where_sql} ORDER BY a.created_date DESC, a.id DESC";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['created_date'],
            $r['username'] ?: 'Sistem',
            $r['company_name'] ?: 'Lintas Perusahaan',
            $r['action'],
            $r['description'] ?: '-',
            $r['ip_address'] ?: '-',
        ],
        $max_export_rows
    );

    $sub_parts = [];
    if ($f_company > 0) {
        foreach ($companies as $comp) {
            if ((int) $comp['id'] === $f_company) {
                $sub_parts[] = 'Perusahaan: ' . $comp['name'];
                break;
            }
        }
    }
    if ($from !== '' || $to !== '') {
        $sub_parts[] = 'Periode: ' . ($from ?: '...') . ' s/d ' . ($to ?: '...');
    }
    if ($f_user !== '') {
        $sub_parts[] = 'User: ' . $f_user;
    }
    if ($action !== '') {
        $sub_parts[] = 'Aksi: ' . $action;
    }
    $sub = $sub_parts ? implode(' | ', $sub_parts) : 'Semua Data';

    grid_export_send(
        'Daftar Log Audit Aktivitas',
        ['Waktu', 'Pengguna', 'Perusahaan', 'Aksi', 'Deskripsi / Detail', 'Alamat IP'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$p = paginate($total, $page);

$sql = "SELECT a.id, a.created_date, a.username, a.action, a.description, a.ip_address, c.name AS company_name
    FROM audit_trails a
    LEFT JOIN companies c ON c.id = a.company_id
    WHERE {$where_sql}
    ORDER BY a.created_date DESC, a.id DESC
    LIMIT ? OFFSET ?";
$stmt = db()->prepare($sql);
$stmt->bind_param($types . 'ii', ...array_merge($params, [$p['per_page'], $p['offset']]));
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$header_actions = ui_export_actions();
$q_params = ['from' => $from, 'to' => $to, 'user' => $f_user, 'action' => $action];
if (is_super_admin() && isset($_GET['company_id'])) {
    $q_params['company_id'] = (string) $f_company;
}
$query_base = http_build_query(array_filter($q_params, static fn($v) => $v !== ''));

$part_count = count($part_info['partitions']);
$badges = [
    ['text' => number_format($total, 0, ',', '.') . ' Log', 'class' => 'bg-light text-secondary border'],
    ['text' => 'Retensi 1 Tahun (' . $part_count . ' Partisi Bulanan)', 'class' => 'bg-info-subtle text-info-emphasis border border-info-subtle'],
];

$active = 'audit';
$title  = 'Audit Trail';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Audit Trail',
        ['Dashboard' => 'pages/dashboard/index.php', 'Audit' => null],
        $badges,
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-md-3">
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="0" <?= $f_company === 0 ? 'selected' : '' ?>>— Semua Perusahaan —</option>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= (int) $comp['id'] ?>" <?= $f_company === (int) $comp['id'] ? 'selected' : '' ?>>
                                <?= e($comp['name']) ?> (<?= e($comp['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-2">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" title="Dari Tanggal">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" title="Sampai Tanggal">
                </div>
                <div class="col-6 col-md-2">
                    <input type="text" name="user" class="form-control form-control-sm" placeholder="Username..." value="<?= e($f_user) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <input type="text" name="action" class="form-control form-control-sm" placeholder="Aksi (login, create)..." value="<?= e($action) ?>">
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <?php if ($from !== '' || $to !== '' || $f_user !== '' || $action !== '' || isset($_GET['company_id'])): ?>
                        <a href="<?= e(APP_URL) ?>/pages/audit/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Waktu</th><th>User</th><th>Perusahaan</th><th>Aksi</th><th>Deskripsi</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="small text-nowrap"><?= e($r['created_date']) ?></td>
                        <td><?= e($r['username'] ?: '—') ?></td>
                        <td><?= e($r['company_name'] ?: '—') ?></td>
                        <td><span class="badge bg-secondary"><?= e($r['action']) ?></span></td>
                        <td><?= e($r['description']) ?></td>
                        <td class="small text-muted font-monospace"><?= e($r['ip_address'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada log aktivitas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small text-muted">
                <?php if ($total > 0): ?>
                    Menampilkan <strong><?= number_format($p['offset'] + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($total, $p['offset'] + count($rows)), 0, ',', '.') ?></strong> dari <strong><?= number_format($total, 0, ',', '.') ?></strong> data
                <?php else: ?>
                    Total <strong>0</strong> data
                <?php endif; ?>
            </div>
            <?= pagination_html($p, $query_base) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
