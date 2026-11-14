<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('users.view');

[$where, $types, $params] = company_scope('u.company_id');

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

if ($q !== '') {
    $q_like = '%' . $q . '%';
    $where .= ' AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR r.name LIKE ? OR c.name LIKE ?)';
    $types .= 'sssss';
    array_push($params, $q_like, $q_like, $q_like, $q_like, $q_like);
}
if ($status === '1' || $status === '0') {
    $where .= ' AND u.is_active = ?';
    $types .= 'i';
    $params[] = (int) $status;
}

$st = db()->prepare("SELECT COUNT(*) FROM users u
                     JOIN roles r ON r.id = u.role_id
                     LEFT JOIN companies c ON c.id = u.company_id
                     WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT u.username, u.full_name, u.email, u.phone_number, r.name AS role_name,
                          c.name AS company_name, u.is_active, u.last_login
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                   LEFT JOIN companies c ON c.id = u.company_id
                   WHERE {$where} ORDER BY u.username";
    $isPdf = $export_format === 'pdf';
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf): array {
            $company = $r['company_name'] ?: 'Lintas Perusahaan';
            $status  = $r['is_active'] ? 'Aktif' : 'Nonaktif';
            if ($isPdf) {
                return [
                    $r['username'],
                    $r['full_name'],
                    $r['email'] ?: '-',
                    $r['role_name'],
                    $company,
                    $status,
                ];
            }
            return [
                $r['username'],
                $r['full_name'],
                $r['email'] ?: '-',
                $r['phone_number'] ?: '-',
                $r['role_name'],
                $company,
                $r['last_login'] ?: '-',
                $status,
            ];
        },
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    $headers = $isPdf
        ? ['Username', 'Nama', 'Email', 'Role', 'Perusahaan', 'Status']
        : ['Username', 'Nama', 'Email', 'Telepon', 'Role', 'Perusahaan', 'Login Terakhir', 'Status'];
    grid_export_send(
        'Daftar Pengguna',
        $headers,
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT u.id, u.username, u.full_name, u.email, u.phone_number, u.is_active,
                            r.name AS role_name, c.name AS company_name
                     FROM users u
                     JOIN roles r ON r.id = u.role_id
                     LEFT JOIN companies c ON c.id = u.company_id
                     WHERE {$where} ORDER BY u.username LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('users.manage')) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/users/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$active = 'users';
$title  = 'Pengguna';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Pengguna',
        ['Dashboard' => 'pages/dashboard/index.php', 'Pengguna' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Pengguna', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari username, nama, email..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/users/index.php', ($q !== '' || $status !== '')) ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">#</th><th>Username</th><th>Nama</th><th>Email</th><th>Role</th><th>Perusahaan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="fw-semibold"><?= e($r['username']) ?></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['email'] ?: '-') ?></td>
                        <td><span class="badge bg-secondary"><?= e($r['role_name']) ?></span></td>
                        <td><?= e($r['company_name'] ?? '— lintas perusahaan —') ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end text-nowrap">
                            <?php if (can('users.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/users/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <?php if ($r['username'] !== 'superadmin'): ?>
                                    <form method="post" action="<?= e(APP_URL) ?>/pages/users/delete.php" class="d-inline" onsubmit="return confirm('Hapus user ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada user.</td></tr>
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
