<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.view');

[$where, $types, $params] = company_scope('c.id');

// ---------------------------------------------------------------------
// 1. Filter & Pencarian Server-Side (Aman untuk ratusan ribu baris)
// ---------------------------------------------------------------------
$q        = trim((string) ($_GET['q'] ?? ''));
$group_id = (int) ($_GET['group_id'] ?? 0);
$status   = trim((string) ($_GET['status'] ?? ''));
$req_per_page = (int) ($_GET['per_page'] ?? PER_PAGE);
$per_page     = in_array($req_per_page, [20, 50, 100], true) ? $req_per_page : PER_PAGE;

if ($q !== '') {
    $q_like = '%' . $q . '%';
    $where .= ' AND (c.code LIKE ? OR c.name LIKE ? OR c.city LIKE ? OR c.email LIKE ?)';
    $types .= 'ssss';
    array_push($params, $q_like, $q_like, $q_like, $q_like);
}
if ($group_id > 0) {
    $where .= ' AND c.group_id = ?';
    $types .= 'i';
    $params[] = $group_id;
}
if ($status === '1' || $status === '0') {
    $where .= ' AND c.is_active = ?';
    $types .= 'i';
    $params[] = (int) $status;
}

// Daftar grup untuk filter dropdown
$groups = db()->query('SELECT id, code, name FROM groups ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$group_map = array_column($groups, 'name', 'id');

// Hitung total baris sesuai filter
$st = db()->prepare("SELECT COUNT(*) FROM companies c WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

// ---------------------------------------------------------------------
// 2. Export Grid (Excel Streaming O(1) RAM & PDF dengan Smart Guard)
// ---------------------------------------------------------------------
$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $filter_notes = [];
    if ($q !== '') {
        $filter_notes[] = 'Cari: "' . $q . '"';
    }
    if ($group_id > 0 && isset($group_map[$group_id])) {
        $filter_notes[] = 'Grup: ' . $group_map[$group_id];
    }
    if ($status === '1' || $status === '0') {
        $filter_notes[] = 'Status: ' . ($status === '1' ? 'Aktif' : 'Nonaktif');
    }
    $subtitle = $filter_notes ? implode(' | ', $filter_notes) : 'Semua Data';

    $sql_export = "SELECT c.code, c.name, g.name AS group_name, c.phone_number, c.email, c.city, c.is_active
                   FROM companies c JOIN groups g ON g.id = c.group_id
                   WHERE {$where} ORDER BY c.name";

    $isPdf = $export_format === 'pdf';
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;
    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf): array {
            $city   = ($r['city'] ?? '') !== '' ? $r['city'] : '-';
            $status = $r['is_active'] ? 'Aktif' : 'Nonaktif';
            if ($isPdf) {
                return [$r['code'], $r['name'], $r['group_name'], $city, $status];
            }
            $phone = trim((string) ($r['phone_number'] ?? ''));
            return [
                $r['code'],
                $r['name'],
                $r['group_name'],
                $phone !== '' ? $phone : '-',
                ($r['email'] ?? '') !== '' ? $r['email'] : '-',
                $city,
                $status,
            ];
        },
        $max_export_rows
    );

    $headers = $isPdf
        ? ['Kode', 'Nama', 'Grup', 'Kota', 'Status']
        : ['Kode', 'Nama', 'Grup', 'Telepon', 'Email', 'Kota', 'Status'];
    grid_export_send(
        'Daftar Perusahaan',
        $headers,
        $row_stream,
        $export_format,
        'landscape',
        $subtitle,
        $total
    );
}

// ---------------------------------------------------------------------
// 3. Query Halaman Aktif (Server-Side Pagination LIMIT / OFFSET)
// ---------------------------------------------------------------------
$p = paginate($total, (int) ($_GET['page'] ?? 1), $per_page);
$st = db()->prepare("SELECT c.id, c.code, c.name, c.city, c.is_active, g.name AS group_name
                     FROM companies c JOIN groups g ON g.id = c.group_id
                     WHERE {$where} ORDER BY c.name LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$query_params = array_filter([
    'q'        => $q !== '' ? $q : null,
    'group_id' => $group_id > 0 ? $group_id : null,
    'status'   => ($status === '1' || $status === '0') ? $status : null,
    'per_page' => $per_page !== PER_PAGE ? $per_page : null,
], static fn ($v) => $v !== null);
$query_base = http_build_query($query_params);
$has_filter = !empty($query_params);

$header_actions = ui_export_actions();
if (is_super_admin() && can('companies.manage')) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/companies/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'companies';
$title  = 'Perusahaan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Perusahaan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Perusahaan' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Data', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <!-- Filter & Pencarian Server-Side -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar erp-filter-bar-wide">
                <div class="col-12 col-md-4 col-lg-3 erp-filter-q">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari kode, nama, kota, email..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3 col-lg-2 erp-filter-item">
                    <select name="group_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Semua Grup</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= $group_id === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['code'] . ' — ' . $g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 col-lg-2 erp-filter-item">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <div class="col-auto erp-filter-compact">
                    <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" title="Baris per halaman">
                        <?php foreach ([20, 50, 100] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / hal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/companies/index.php', (bool) $has_filter) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 52px;">#</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Grup</th>
                        <th>Kota</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td><span class="fw-semibold"><?= e($r['code']) ?></span></td>
                        <td><a class="text-decoration-none fw-medium" href="<?= e(APP_URL) ?>/pages/companies/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                        <td><?= e($r['group_name']) ?></td>
                        <td><?= e($r['city'] ?: '-') ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end text-nowrap">
                            <?php if (can('companies.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/companies/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <?php if (is_super_admin()): ?>
                                    <form method="post" action="<?= e(APP_URL) ?>/pages/companies/delete.php" class="d-inline" onsubmit="return confirm('Hapus perusahaan ini?')">
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
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data perusahaan<?= $has_filter ? ' yang sesuai filter' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small text-muted">
                <?php if ($total > 0):
                    $from = $p['offset'] + 1;
                    $to   = min($total, $p['offset'] + count($rows));
                ?>
                    Menampilkan <strong><?= number_format($from, 0, ',', '.') ?>&ndash;<?= number_format($to, 0, ',', '.') ?></strong> dari <strong><?= number_format($total, 0, ',', '.') ?></strong> data
                <?php else: ?>
                    Total <strong>0</strong> data
                <?php endif; ?>
            </div>
            <?= pagination_html($p, $query_base) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
