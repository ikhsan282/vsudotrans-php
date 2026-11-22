<?php
/** Daftar karyawan — list + pagination + search + filter status, scoped company_scope(). */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.view');
employee_scope_cid();

$compScope     = company_filter_scope('e.company_id');
$filter_cid    = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;

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
    $where  = [$compScope['where']];
    $types  = $compScope['types'];
    $params = $compScope['params'];

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

    employee_ensure_hr_schema();
    $sql_export = 'SELECT e.code, e.name, co.code AS company_code, b.name AS branch_name, e.phone_number, e.email, e.job_title, e.job_position, e.organization_structure, e.join_date, e.status
                   FROM employees e
                   LEFT JOIN branches b ON b.id = e.branch_id
                   LEFT JOIN companies co ON co.id = e.company_id
                   WHERE ' . $where_sql . '
                   ORDER BY e.code ASC';

    $isPdf = $export_format === 'pdf';
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;
    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf, $show_comp_col): array {
            $base = [$r['code'], $r['name']];
            if ($show_comp_col) {
                $base[] = $r['company_code'] ?: '-';
            }
            $base[] = $r['branch_name'] ?: '-';
            if ($isPdf) {
                $kontak = implode("\n", array_filter([
                    trim((string) ($r['email'] ?? '')),
                    trim((string) ($r['phone_number'] ?? '')),
                ]));
                $base[] = $kontak !== '' ? $kontak : '-';
                $jabPos = implode("\n", array_filter([
                    trim((string) ($r['job_title'] ?? '')),
                    trim((string) ($r['job_position'] ?? '')),
                    trim((string) ($r['organization_structure'] ?? '')),
                ]));
                $base[] = $jabPos !== '' ? $jabPos : '-';
            } else {
                $base[] = $r['phone_number'] ?: '-';
                $base[] = $r['email'] ?: '-';
                $base[] = $r['job_title'] ?: '-';
                $base[] = $r['job_position'] ?: '-';
                $base[] = $r['organization_structure'] ?: '-';
            }
            $base[] = $r['join_date'] ?: '-';
            $base[] = ucfirst($r['status']);
            return $base;
        },
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

    $headers = ['Kode', 'Nama'];
    if ($show_comp_col) {
        $headers[] = 'Perusahaan';
    }
    $headers[] = 'Cabang';
    if ($isPdf) {
        array_push($headers, 'Kontak', 'Jabatan & Posisi', 'Masuk', 'Status');
    } else {
        array_push($headers, 'Telepon', 'Email', 'Jabatan', 'Posisi', 'Struktur Organisasi', 'Masuk', 'Status');
    }
    grid_export_send(
        'Daftar Karyawan',
        $headers,
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $tot_cnt
    );
}

[$rows, $p] = employee_list($page, $q, $status, is_super_admin() ? $filter_cid : null);
$can_manage = can('employees.manage');
$query_base = http_build_query(array_filter([
    'company_id' => is_super_admin() ? (string) $filter_cid : '',
    'q'          => $q,
    'status'     => $status,
], static fn($v): bool => $v !== ''));

$header_actions = ui_export_actions();
if ($can_manage && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/employees/detail.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'employees';
$title = 'Karyawan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
<?= ui_page_header(
    'Karyawan',
    ['Dashboard' => 'pages/dashboard/index.php', 'Karyawan' => null],
    [['text' => number_format($p['total'], 0, ',', '.') . ' Karyawan', 'class' => 'bg-light text-secondary border']],
    $header_actions
) ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="row g-2 align-items-center erp-filter-bar">
            <?php if (is_super_admin()): ?>
            <div class="col-12 col-md-3">
                <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                    <?php foreach ($compScope['companies'] as $co): ?>
                        <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                            <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Cari nama atau kode karyawan..." value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="resign" <?= $status === 'resign' ? 'selected' : '' ?>>Resign</option>
                </select>
            </div>
            <?= ui_filter_actions(APP_URL . '/pages/employees/index.php', ($q !== '' || $status !== '' || (is_super_admin() && $filter_cid > 0))) ?>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="text-center" style="width: 52px;">#</th>
                <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
                <th>Kode</th>
                <th>Nama</th>
                <th>Cabang</th>
                <th>Kontak</th>
                <th>Jabatan &amp; Posisi</th>
                <th>Masuk</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $idx => $r): ?>
                <tr>
                    <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                    <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                    <td class="fw-semibold"><?= e($r['code']) ?></td>
                    <td><a class="text-decoration-none fw-medium" href="detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                    <td><?= e($r['branch_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?php if ($r['email']): ?><div class="small"><?= e($r['email']) ?></div><?php endif; ?>
                        <?php if ($r['phone_number']): ?><div class="small text-muted"><?= e($r['phone_number']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['job_title']) || !empty($r['job_position']) || !empty($r['organization_structure'])): ?>
                            <?php if (!empty($r['job_title'])): ?><div class="fw-medium small"><?= e($r['job_title']) ?></div><?php endif; ?>
                            <?php if (!empty($r['job_position'])): ?><span class="badge bg-light text-dark border"><?= e($r['job_position']) ?></span><?php endif; ?>
                            <?php if (!empty($r['organization_structure'])): ?><div class="small text-muted mt-1"><i class="bi bi-diagram-3 me-1"></i><?= e($r['organization_structure']) ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= !empty($r['join_date']) ? e(date('d-M-Y', strtotime((string) $r['join_date']))) : '-' ?></td>
                    <td>
                        <span class="badge bg-<?= $r['status'] === 'aktif' ? 'success' : 'secondary' ?>">
                            <?= e(ucfirst($r['status'])) ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Detail" aria-label="Detail"><i class="bi bi-eye"></i></a>
                        <?php if ($can_manage): ?>
                            <a href="detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                            <form method="post" action="delete.php" class="d-inline"
                                  onsubmit="return confirm('Hapus karyawan <?= e($r['name']) ?>? Tindakan ini tidak dapat dibatalkan.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $show_comp_col ? 10 : 9 ?>" class="text-center text-muted py-4">Tidak ada data karyawan.</td></tr>
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
