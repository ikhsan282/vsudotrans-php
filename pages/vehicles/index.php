<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('vehicles.view');

$today = new DateTimeImmutable('today');
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
$status = trim((string) ($_GET['status'] ?? ''));

[$where, $types, $params] = company_scope('v.company_id');

if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (v.vehicle_number LIKE ? OR v.frame_number LIKE ? OR b.name LIKE ? OR m.name LIKE ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($status === '1' || $status === '0') {
    $where .= ' AND v.is_active = ?';
    $types .= 'i';
    $params[] = (int) $status;
}

$st = db()->prepare("SELECT COUNT(*) FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT v.vehicle_number, b.name AS brand_name, m.name AS model_name, c.name AS category_name,
                          v.production_year, v.vehicle_color, v.ownership, v.is_active,
                          v.tax_due, v.stnk_due, v.kir_due
                   FROM vehicles v
                   JOIN brand_vehicles b ON b.id = v.brand_id
                   JOIN model_units m ON m.id = v.model_unit_id
                   JOIN category_vehicles c ON c.id = v.category_vehicle_id
                   WHERE {$where} ORDER BY v.vehicle_number";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['vehicle_number'],
            $r['brand_name'] . ' ' . $r['model_name'],
            $r['category_name'],
            $r['production_year'] ?: '-',
            $r['vehicle_color'] ?: '-',
            $r['ownership'] ?: '-',
            $r['tax_due'] ?: '-',
            $r['stnk_due'] ?: '-',
            $r['kir_due'] ?: '-',
            $r['is_active'] ? 'Aktif' : 'Nonaktif',
        ],
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    grid_export_send(
        'Daftar Armada Kendaraan',
        ['No Polisi', 'Merk & Model', 'Kategori', 'Tahun', 'Warna', 'Kepemilikan', 'Pajak', 'STNK', 'KIR', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT v.id, v.vehicle_number, v.vehicle_color, v.production_year, v.ownership, v.is_active,
                            v.tax_due, v.stnk_due, v.kir_due,
                            b.name AS brand_name, m.name AS model_name, c.name AS category_name
                     FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     JOIN category_vehicles c ON c.id = v.category_vehicle_id
                     WHERE {$where} ORDER BY v.vehicle_number LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('vehicles.manage')) {
    $header_actions[] = ['label' => 'Master Kendaraan', 'icon' => 'gear', 'href' => 'pages/vehicles/master.php', 'class' => 'btn btn-outline-secondary btn-sm'];
    if (active_company_id() !== null) {
        $header_actions[] = ['label' => 'Tambah Unit', 'icon' => 'plus-lg', 'href' => 'pages/vehicles/form.php', 'class' => 'btn btn-primary btn-sm'];
    }
}

$dueLabels = ['tax_due' => 'Pajak', 'stnk_due' => 'STNK', 'kir_due' => 'KIR'];
$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$active = 'vehicles';
$title  = 'Kendaraan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Armada Kendaraan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Kendaraan' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Unit', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari nopol, no rangka, merk..." value="<?= e($q) ?>">
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
                        <a href="<?= e(APP_URL) ?>/pages/vehicles/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 52px;">No</th>
                        <th>No Polisi</th>
                        <th>Unit</th>
                        <th>Kategori</th>
                        <th>Status</th>
                        <th>Masa Berlaku Dokumen</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td>
                            <a class="fw-bold text-decoration-none" href="<?= e(APP_URL) ?>/pages/vehicles/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['vehicle_number']) ?></a>
                            <div class="small text-muted"><?= e($r['ownership']) ?> · <?= e($r['production_year']) ?></div>
                        </td>
                        <td>
                            <div><?= e($r['brand_name']) ?> <?= e($r['model_name']) ?></div>
                            <small class="text-muted"><?= e($r['vehicle_color']) ?></small>
                        </td>
                        <td><?= e($r['category_name']) ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($dueLabels as $field => $lbl):
                                    $val = $r[$field];
                                    if (!$val) {
                                        echo '<span class="badge bg-light text-muted border">' . e($lbl) . ': -</span>';
                                        continue;
                                    }
                                    $d = new DateTimeImmutable($val);
                                    $diff = (int) $today->diff($d)->format('%r%a');
                                    $cls = $diff < 0 ? 'bg-danger' : ($diff <= 30 ? 'bg-warning text-dark' : 'bg-success');
                                ?>
                                    <span class="badge <?= $cls ?>" title="<?= e($val) ?> (<?= $diff < 0 ? 'lewat ' . abs($diff) . ' hari' : $diff . ' hari lagi' ?>)">
                                        <?= e($lbl) ?>: <?= e($d->format('d/m/y')) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/vehicles/detail.php?id=<?= (int) $r['id'] ?>">Detail</a>
                            <?php if (can('vehicles.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/vehicles/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/delete.php" class="d-inline" onsubmit="return confirm('Hapus kendaraan ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada unit kendaraan.</td></tr>
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
