<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('branches.view');

[$where, $types, $params] = company_scope('b.company_id');

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

if ($q !== '') {
    $q_like = '%' . $q . '%';
    $where .= ' AND (b.code LIKE ? OR b.name LIKE ? OR b.phone_number LIKE ? OR c.name LIKE ?)';
    $types .= 'ssss';
    array_push($params, $q_like, $q_like, $q_like, $q_like);
}
if ($status === '1' || $status === '0') {
    $where .= ' AND b.is_active = ?';
    $types .= 'i';
    $params[] = (int) $status;
}

$st = db()->prepare("SELECT COUNT(*) FROM branches b JOIN companies c ON c.id = b.company_id WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT b.code, b.name, c.name AS company_name, b.phone_number, b.address, b.is_active
                   FROM branches b JOIN companies c ON c.id = b.company_id
                   WHERE {$where} ORDER BY b.name";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['code'],
            $r['name'],
            $r['company_name'],
            $r['phone_number'] ?: '-',
            $r['address'] ?: '-',
            $r['is_active'] ? 'Aktif' : 'Nonaktif',
        ],
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    grid_export_send(
        'Daftar Cabang Perusahaan',
        ['Kode', 'Nama Cabang', 'Perusahaan', 'Telepon', 'Alamat', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.code, b.name, b.phone_number, b.is_active, c.name AS company_name
                     FROM branches b JOIN companies c ON c.id = b.company_id
                     WHERE {$where} ORDER BY b.name LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('branches.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/branches/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$active = 'branches';
$title  = 'Cabang';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Cabang Perusahaan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Cabang' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Data', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari kode, nama, telepon..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <?php if ($q !== '' || $status !== ''): ?>
                        <a href="<?= e(APP_URL) ?>/pages/branches/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Kode</th><th>Nama</th><th>Perusahaan</th><th>Telepon</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="fw-semibold"><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e($r['company_name']) ?></td>
                        <td><?= e($r['phone_number'] ?: '-') ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end text-nowrap">
                            <?php if (can('branches.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/branches/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/branches/delete.php" class="d-inline" onsubmit="return confirm('Hapus cabang ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data.</td></tr>
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
