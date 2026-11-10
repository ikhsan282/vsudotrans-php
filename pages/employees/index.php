<?php
/** Daftar karyawan — list + pagination + search + filter status, scoped company_scope(). */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.view');
employee_scope_cid();

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, ['aktif', 'resign'], true)) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

// ---------------------------------------------------------------------
// Export Grid (Excel & PDF)
// ---------------------------------------------------------------------
$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    [$wsql, $wtypes, $wparams] = company_scope('e.company_id');
    $where = [$wsql];
    $types = $wtypes;
    $params = $wparams;

    if ($q !== '') {
        $where[] = '(e.name LIKE ? OR e.code LIKE ?)';
        $types .= 'ss';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($status !== '') {
        $where[] = 'e.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $where_sql = implode(' AND ', $where);

    $sql_export = 'SELECT e.code, e.name, b.name AS branch_name, e.phone_number, e.email, e.job_title, e.join_date, e.status
                   FROM employees e
                   LEFT JOIN branches b ON b.id = e.branch_id
                   WHERE ' . $where_sql . '
                   ORDER BY e.code ASC';

    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;
    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['code'],
            $r['name'],
            $r['branch_name'] ?: '-',
            $r['phone_number'] ?: '-',
            $r['email'] ?: '-',
            $r['job_title'] ?: '-',
            $r['join_date'] ?: '-',
            ucfirst($r['status']),
        ],
        $max_export_rows
    );

    $sub_parts = [];
    if ($q !== '') {
        $sub_parts[] = 'Pencarian: "' . $q . '"';
    }
    if ($status !== '') {
        $sub_parts[] = 'Status: ' . ucfirst($status);
    }
    $sub = $sub_parts ? implode(' | ', $sub_parts) : 'Semua Data';

    // Hitung total untuk label smart guard PDF
    $st_cnt = db()->prepare('SELECT COUNT(*) FROM employees e WHERE ' . $where_sql);
    if ($params) {
        $st_cnt->bind_param($types, ...$params);
    }
    $st_cnt->execute();
    $tot_cnt = (int) $st_cnt->get_result()->fetch_row()[0];
    $st_cnt->close();

    grid_export_send(
        'Daftar Karyawan',
        ['NIK / Kode', 'Nama Karyawan', 'Cabang', 'Telepon', 'Email', 'Jabatan', 'Tgl Masuk', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $tot_cnt
    );
}

[$rows, $p] = employee_list($page, $q, $status);
$can_manage = can('employees.manage');
$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$header_actions = ui_export_actions();
if ($can_manage) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/employees/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'employees';
$title = 'Karyawan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
<?= ui_page_header(
    'Karyawan',
    ['Dashboard' => 'pages/dashboard/index.php', 'Karyawan' => null],
    [['text' => number_format($p['total'], 0, ',', '.') . ' Karyawan', 'class' => 'bg-light text-secondary border']],
    $header_actions
) ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Cari nama atau kode karyawan..." value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="resign" <?= $status === 'resign' ? 'selected' : '' ?>>Resign</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button class="btn btn-sm btn-secondary" type="submit">Filter</button>
                <?php if ($q !== '' || $status !== ''): ?>
                    <a href="<?= e(APP_URL) ?>/pages/employees/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center" style="width: 52px;">No</th>
                <th>Kode</th>
                <th>Nama</th>
                <th>Cabang</th>
                <th>Kontak</th>
                <th>Jabatan</th>
                <th>Masuk</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $idx => $r): ?>
                <tr>
                    <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                    <td class="fw-semibold"><?= e($r['code']) ?></td>
                    <td><a class="text-decoration-none fw-medium" href="detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                    <td><?= e($r['branch_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($r['email']): ?><div class="small"><?= e($r['email']) ?></div><?php endif; ?>
                        <?php if ($r['phone_number']): ?><div class="small text-muted"><?= e($r['phone_number']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($r['job_title'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="small"><?= e($r['join_date'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= $r['status'] === 'aktif' ? 'success' : 'secondary' ?>">
                            <?= e(ucfirst($r['status'])) ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Detail</a>
                        <?php if ($can_manage): ?>
                            <a href="form.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary">Ubah</a>
                            <form method="post" action="delete.php" class="d-inline"
                                  onsubmit="return confirm('Hapus karyawan <?= e($r['name']) ?>? Tindakan ini tidak dapat dibatalkan.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data karyawan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <div class="small text-muted">
            <?php if ($p['total'] > 0): ?>
                Menampilkan <strong><?= number_format($p['offset'] + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($p['total'], $p['offset'] + count($rows)), 0, ',', '.') ?></strong> dari <strong><?= number_format($p['total'], 0, ',', '.') ?></strong> data
            <?php else: ?>
                Total <strong>0</strong> data
            <?php endif; ?>
        </div>
        <?= pagination_html($p, $query_base) ?>
    </div>
</div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
