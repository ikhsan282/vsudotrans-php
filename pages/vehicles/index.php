<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_permission('vehicles.view');

$today              = new DateTimeImmutable('today');
$q                  = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
$statusRaw          = trim((string) ($_GET['status'] ?? ''));
$status             = ($statusRaw === '1' || $statusRaw === '0') ? $statusRaw : '';
$filter_category_id = (int) ($_GET['category_vehicle_id'] ?? 0);
$filter_brand_id    = (int) ($_GET['brand_id'] ?? 0);

$cscope               = company_filter_scope('v.company_id');
$filter_company_id    = $cscope['filter_company_id'];
$companies            = $cscope['companies'];
$cid                  = $cscope['active_cid'];
$has_explicit_company = array_key_exists('company_id', $_GET);
$show_company_filter  = is_super_admin() || count($companies) > 1;
$show_company_col     = ($filter_company_id === 0);

$where  = $cscope['where'];
$types  = $cscope['types'];
$params = $cscope['params'];

// Opsi filter Kategori & Merek mengikuti perusahaan yang sedang dipilih.
$db              = db();
$catFilterOpts   = vehicle_filter_options($db, 'category_vehicles', $filter_category_id, $show_company_col);
$brandFilterOpts = vehicle_filter_options($db, 'brand_vehicles', $filter_brand_id, $show_company_col);
if ($filter_category_id > 0 && !in_array($filter_category_id, array_column($catFilterOpts, 'id'), true)) {
    $filter_category_id = 0;
}
if ($filter_brand_id > 0 && !in_array($filter_brand_id, array_column($brandFilterOpts, 'id'), true)) {
    $filter_brand_id = 0;
}

if ($filter_category_id > 0) {
    $where .= ' AND v.category_vehicle_id = ?';
    $types .= 'i';
    $params[] = $filter_category_id;
}
if ($filter_brand_id > 0) {
    $where .= ' AND v.brand_id = ?';
    $types .= 'i';
    $params[] = $filter_brand_id;
}
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
    $isPdf = $export_format === 'pdf';
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;
    $dueMap = ['tax_due' => 'Pajak', 'stnk_due' => 'STNK', 'kir_due' => 'KIR'];

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf, $dueMap): array {
            $statusLbl = $r['is_active'] ? 'Aktif' : 'Nonaktif';
            if ($isPdf) {
                $nopol = $r['vehicle_number'] . "\n" . ($r['ownership'] ?: '-') . ' · ' . ($r['production_year'] ?: '-');
                $unit  = trim($r['brand_name'] . ' ' . $r['model_name']) . (!empty($r['vehicle_color']) ? "\n" . $r['vehicle_color'] : '');
                $docs  = [];
                foreach ($dueMap as $field => $lbl) {
                    $val = $r[$field] ?? '';
                    $docs[] = $lbl . ': ' . ($val ? date('d/m/y', strtotime($val)) : '-');
                }
                return [
                    $nopol,
                    $unit,
                    $r['category_name'],
                    $statusLbl,
                    implode("\n", $docs),
                ];
            }
            return [
                $r['vehicle_number'],
                $r['brand_name'] . ' ' . $r['model_name'],
                $r['category_name'],
                $r['production_year'] ?: '-',
                $r['vehicle_color'] ?: '-',
                $r['ownership'] ?: '-',
                $r['tax_due'] ?: '-',
                $r['stnk_due'] ?: '-',
                $r['kir_due'] ?: '-',
                $statusLbl,
            ];
        },
        $max_export_rows
    );

    $subParts = [];
    if ($filter_category_id > 0) {
        foreach ($catFilterOpts as $cf) {
            if ($cf['id'] === $filter_category_id) {
                $subParts[] = 'Kategori: ' . $cf['label'];
                break;
            }
        }
    }
    if ($filter_brand_id > 0) {
        foreach ($brandFilterOpts as $bf) {
            if ($bf['id'] === $filter_brand_id) {
                $subParts[] = 'Merek: ' . $bf['label'];
                break;
            }
        }
    }
    if ($status === '1' || $status === '0') {
        $subParts[] = 'Status: ' . ($status === '1' ? 'Aktif' : 'Nonaktif');
    }
    if ($q !== '') {
        $subParts[] = 'Cari: "' . $q . '"';
    }
    $sub = $subParts ? implode(' · ', $subParts) : 'Semua Data';
    $headers = $isPdf
        ? ['No Polisi', 'Unit', 'Kategori', 'Status', 'Masa Berlaku Dokumen']
        : ['No Polisi', 'Merk & Model', 'Kategori', 'Tahun', 'Warna', 'Kepemilikan', 'Pajak', 'STNK', 'KIR', 'Status'];
    grid_export_send(
        'Daftar Armada Kendaraan',
        $headers,
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
                            b.name AS brand_name, m.name AS model_name, c.name AS category_name, co.code AS company_code
                     FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     JOIN category_vehicles c ON c.id = v.category_vehicle_id
                     LEFT JOIN companies co ON co.id = v.company_id
                     WHERE {$where} ORDER BY v.vehicle_number LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions([
    'category_vehicle_id' => $filter_category_id > 0 ? (string) $filter_category_id : null,
    'brand_id'            => $filter_brand_id > 0 ? (string) $filter_brand_id : null,
    'status'              => $status !== '' ? $status : null,
]);
if (can('vehicles.manage') && $cid !== null) {
    $header_actions[] = ['label' => 'Tambah Unit', 'icon' => 'plus-lg', 'href' => 'pages/vehicles/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$dueLabels         = ['tax_due' => 'Pajak', 'stnk_due' => 'STNK', 'kir_due' => 'KIR'];
$has_active_filter = ($q !== '' || $status !== '' || $filter_category_id > 0 || $filter_brand_id > 0 || ($show_company_filter && $filter_company_id !== ($cid ?? 0)));
$query_base        = http_build_query(array_filter([
    'company_id'          => $has_explicit_company ? (string) $filter_company_id : '',
    'category_vehicle_id' => $filter_category_id > 0 ? (string) $filter_category_id : '',
    'brand_id'            => $filter_brand_id > 0 ? (string) $filter_brand_id : '',
    'status'              => $status,
    'q'                   => $q,
], static fn (string $v): bool => $v !== ''));

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
            <form method="get" class="row g-2 align-items-center erp-filter-bar erp-filter-bar-wide">
                <div class="col-12 col-sm-6 col-md-4 erp-filter-q">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari nopol, no rangka, merk..." value="<?= e($q) ?>">
                    </div>
                </div>
                <?php if ($show_company_filter): ?>
                    <div class="col-6 col-sm-4 col-md-4 erp-filter-item">
                        <select name="company_id" class="form-select form-select-sm" onchange="var f=this.form;['category_vehicle_id','brand_id'].forEach(function(n){var el=f.querySelector('[name='+n+']');if(el)el.value='';});f.submit()" title="Pilih Perusahaan">
                            <?= ui_company_filter_options($companies, $filter_company_id, $has_explicit_company) ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-6 col-sm-4 col-md-4 erp-filter-item">
                    <select name="category_vehicle_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Kategori">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($catFilterOpts as $cf): ?>
                            <option value="<?= (int) $cf['id'] ?>" <?= ((int) $cf['id'] === $filter_category_id) ? 'selected' : '' ?>><?= e($cf['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-md-4 erp-filter-item">
                    <select name="brand_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Merek">
                        <option value="">Semua Merek</option>
                        <?php foreach ($brandFilterOpts as $bf): ?>
                            <option value="<?= (int) $bf['id'] ?>" <?= ((int) $bf['id'] === $filter_brand_id) ? 'selected' : '' ?>><?= e($bf['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-md-4 erp-filter-status">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Status">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/vehicles/index.php', $has_active_filter) ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 52px;">#</th>
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
                            <div class="small text-muted"><?= e($r['ownership']) ?> · <?= e($r['production_year']) ?><?= ($show_company_col && !empty($r['company_code'])) ? ' · ' . e($r['company_code']) : '' ?></div>
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
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/vehicles/detail.php?id=<?= (int) $r['id'] ?>" title="Detail" aria-label="Detail"><i class="bi bi-eye"></i></a>
                            <?php if (can('vehicles.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/vehicles/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/delete.php" class="d-inline" onsubmit="return confirm('Hapus kendaraan ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
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
