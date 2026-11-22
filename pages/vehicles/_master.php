<?php
/** Master kendaraan terpisah (Merek, Kategori, Model Unit): engine list, form ERP, hapus & export. */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';

/** Peta whitelist master kendaraan: nama tabel, folder route, dan relasi FK tidak pernah dari input. */
function vehicle_masters_map(): array
{
    return [
        'brand' => [
            'table' => 'brand_vehicles',
            'dir'   => 'brands',
            'label' => 'Merek Kendaraan',
            'short' => 'Merek',
            'icon'  => 'tags',
            'refs'  => [
                ['vehicles', 'brand_id', 'Armada Kendaraan'],
                ['model_units', 'brand_id', 'Model Unit'],
            ],
        ],
        'category' => [
            'table'  => 'category_vehicles',
            'dir'    => 'categories',
            'base'   => 'pages/transport/categories',
            'active' => 'categories',
            'label'  => 'Kategori Layanan',
            'short'  => 'Kategori',
            'icon'   => 'grid',
            'refs'   => [
                ['rutes', 'category_vehicle_id', 'Rute Travel'],
                ['rute_tariffs', 'category_vehicle_id', 'Tarif Rute'],
                ['rental_car_regulation_employees', 'category_vehicle_id', 'Regulasi Kru Rental'],
                ['premi_configs', 'category_vehicle_id', 'Konfigurasi Premi'],
                ['travel_ticket_bookings', 'category_vehicle_id', 'Pesanan Tiket Travel'],
                ['rental_car_bookings', 'category_vehicle_id', 'Pesanan Sewa Mobil'],
            ],
        ],
        'model' => [
            'table' => 'model_units',
            'dir'   => 'models',
            'label' => 'Model Unit',
            'short' => 'Model Unit',
            'icon'  => 'truck-front',
            'refs'  => [
                ['vehicles', 'model_unit_id', 'Armada Kendaraan'],
            ],
        ],
    ];
}

function vehicle_master_def(string $key): array
{
    $map = vehicle_masters_map();
    if (!isset($map[$key])) {
        http_response_code(404);
        exit('Master kendaraan tidak dikenal.');
    }
    return $map[$key];
}

function vehicle_master_route(array $vm, string $file = 'index.php', array $query = []): string
{
    $filtered = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
    $qs = $filtered ? ('?' . http_build_query($filtered)) : '';
    $base = $vm['base'] ?? ('pages/vehicles/' . $vm['dir']);
    return $base . '/' . $file . $qs;
}

function vehicle_master_can_view(string $key): bool
{
    if ($key === 'category') {
        return can('vehicles.view') || can('travel.view') || can('rental.view')
            || can('vehicles.manage') || can('travel.manage') || can('rental.manage');
    }
    return can('vehicles.view') || can('vehicles.manage');
}

function vehicle_master_can_manage(string $key): bool
{
    if ($key === 'category') {
        return can('vehicles.manage') || can('travel.manage') || can('rental.manage');
    }
    return can('vehicles.manage');
}

function vehicle_master_require_view(string $key): void
{
    if (!vehicle_master_can_view($key)) {
        require_permission('vehicles.view');
    }
}

function vehicle_master_require_manage(string $key): void
{
    if (!vehicle_master_can_manage($key)) {
        require_permission('vehicles.manage');
    }
}

/** Susun query string whitelist agar filter/halaman aktif tetap terjaga setelah aksi. */
function vehicle_master_query(array $overrides = []): string
{
    $base = [];
    foreach (['company_id', 'brand_id', 'service_type', 'pricing_unit', 'status', 'q', 'page'] as $param) {
        if (array_key_exists($param, $_GET) && $_GET[$param] !== '') {
            $base[$param] = (string) $_GET[$param];
        }
    }
    foreach ($overrides as $param => $val) {
        if ($val === null || $val === '') {
            unset($base[$param]);
        } else {
            $base[$param] = (string) $val;
        }
    }
    return http_build_query($base);
}

/** Eksekusi query COUNT(*) sederhana dengan parameter opsional. */
function vehicle_master_scalar(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $val = (int) ($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();
    return $val;
}

/** Tampilkan daftar master kendaraan terpisah (filter, paginasi, export XLSX/PDF). */
function vehicle_master_list(string $key): void
{
    vehicle_master_require_view($key);
    $vm         = vehicle_master_def($key);
    $t          = $vm['table'];
    $isModel    = ($key === 'model');
    $isCategory = ($key === 'category');
    if ($isCategory) {
        category_service_ensure_schema();
    }
    $canManage  = vehicle_master_can_manage($key);
    $db         = db();

    $cscope               = company_filter_scope('x.company_id');
    $filter_company_id    = $cscope['filter_company_id'];
    $vmCompanies          = $cscope['companies'];
    $cid                  = $cscope['active_cid'];
    $has_explicit_company = array_key_exists('company_id', $_GET);
    $show_company_filter  = is_super_admin() || count($vmCompanies) > 1;
    $show_company_col     = ($filter_company_id === 0);

    $q           = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
    $statusRaw   = trim((string) ($_GET['status'] ?? ''));
    $status      = ($statusRaw === '1' || $statusRaw === '0') ? $statusRaw : '';
    $brandFilter = $isModel ? (int) ($_GET['brand_id'] ?? 0) : 0;
    $svcRaw      = $isCategory ? trim((string) ($_GET['service_type'] ?? '')) : '';
    $svcFilter   = ($isCategory && isset(CATEGORY_SERVICE_TYPES[$svcRaw])) ? $svcRaw : '';
    $unitRaw     = $isCategory ? trim((string) ($_GET['pricing_unit'] ?? '')) : '';
    $unitFilter  = ($isCategory && isset(CATEGORY_PRICING_UNITS[$unitRaw])) ? $unitRaw : '';

    $brandFilterOpts   = $isModel ? vehicle_filter_options($db, 'brand_vehicles', $brandFilter, $show_company_col) : [];
    $selectedBrandName = '';
    foreach ($brandFilterOpts as $bf) {
        if ($bf['id'] === $brandFilter) {
            $selectedBrandName = $bf['label'];
            break;
        }
    }
    // Bersihkan brand_id bila sudah di luar scope perusahaan aktif (mis. pasca ganti perusahaan di topbar).
    if ($isModel && $brandFilter > 0 && $selectedBrandName === '') {
        $brandFilter = 0;
    }

    $where  = $cscope['where'];
    $types  = $cscope['types'];
    $params = $cscope['params'];

    if ($isModel && $brandFilter > 0) {
        $where .= ' AND x.brand_id = ?';
        $types .= 'i';
        $params[] = $brandFilter;
    }
    if ($isCategory && $svcFilter !== '') {
        $where .= ' AND x.service_type = ?';
        $types .= 's';
        $params[] = $svcFilter;
    }
    if ($isCategory && $unitFilter !== '') {
        $where .= ' AND x.pricing_unit = ?';
        $types .= 's';
        $params[] = $unitFilter;
    }
    if ($status === '1' || $status === '0') {
        $where .= ' AND x.is_active = ?';
        $types .= 'i';
        $params[] = (int) $status;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        if ($isModel) {
            $where .= ' AND (x.code LIKE ? OR x.name LIKE ? OR x.note LIKE ? OR b.name LIKE ?)';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        } else {
            $where .= ' AND (x.code LIKE ? OR x.name LIKE ? OR x.note LIKE ?)';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
    }

    $from_sql = $isModel
        ? "FROM {$t} x JOIN brand_vehicles b ON b.id = x.brand_id LEFT JOIN companies c ON c.id = x.company_id"
        : "FROM {$t} x LEFT JOIN companies c ON c.id = x.company_id";

    $total     = vehicle_master_scalar($db, "SELECT COUNT(*) {$from_sql} WHERE {$where}", $types, $params);
    $order_sql = $show_company_col ? 'ORDER BY c.code, x.name, x.code' : 'ORDER BY x.name, x.code';

    // Export XLSX & PDF (kolom sejajar 1:1 dengan tabel tampilan)
    $export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
    if (in_array($export_format, ['xlsx', 'pdf'], true)) {
        $isPdf           = ($export_format === 'pdf');
        $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;
        $select_cols     = $isModel
            ? 'x.code, x.name, b.name AS brand_name, c.code AS company_code, c.name AS company_name, x.note, x.is_active'
            : ($isCategory
                ? 'x.code, x.name, x.service_type, x.pricing_unit, c.code AS company_code, c.name AS company_name, x.note, x.is_active'
                : 'x.code, x.name, c.code AS company_code, c.name AS company_name, x.note, x.is_active');

        $row_stream = grid_query_stream(
            "SELECT {$select_cols} {$from_sql} WHERE {$where} {$order_sql}",
            $types,
            $params,
            static function (array $r) use ($isModel, $isCategory, $show_company_col): array {
                $line = [$r['code'], $r['name']];
                if ($isModel) {
                    $line[] = $r['brand_name'] ?: '-';
                }
                if ($isCategory) {
                    $line[] = category_service_type_label((string) ($r['service_type'] ?? 'travel'));
                    $line[] = category_pricing_unit_label((string) ($r['pricing_unit'] ?? 'per_seat'));
                }
                if ($show_company_col) {
                    $compLabel = trim(($r['company_code'] ? $r['company_code'] . ' - ' : '') . ($r['company_name'] ?? ''));
                    $line[] = $compLabel !== '' ? $compLabel : '-';
                }
                $line[] = $r['note'] ?: '-';
                $line[] = $r['is_active'] ? 'Aktif' : 'Nonaktif';
                return $line;
            },
            $max_export_rows
        );

        $headers = ['Kode', 'Nama'];
        if ($isModel) {
            $headers[] = 'Merek';
        }
        if ($isCategory) {
            $headers[] = 'Layanan';
            $headers[] = 'Satuan Tarif';
        }
        if ($show_company_col) {
            $headers[] = 'Perusahaan';
        }
        $headers[] = 'Catatan';
        $headers[] = 'Status';

        $subParts = [];
        if ($show_company_filter) {
            if ($filter_company_id === 0) {
                $subParts[] = 'Semua Perusahaan';
            } else {
                foreach ($vmCompanies as $vmComp) {
                    if ((int) $vmComp['id'] === $filter_company_id) {
                        $subParts[] = $vmComp['code'] . ' - ' . $vmComp['name'];
                        break;
                    }
                }
            }
        }
        if ($isModel && $selectedBrandName !== '') {
            $subParts[] = 'Merek: ' . $selectedBrandName;
        }
        if ($isCategory && $svcFilter !== '') {
            $subParts[] = 'Layanan: ' . category_service_type_label($svcFilter);
        }
        if ($isCategory && $unitFilter !== '') {
            $subParts[] = 'Satuan: ' . category_pricing_unit_label($unitFilter);
        }
        if ($status === '1' || $status === '0') {
            $subParts[] = 'Status: ' . ($status === '1' ? 'Aktif' : 'Nonaktif');
        }
        if ($q !== '') {
            $subParts[] = 'Cari: "' . $q . '"';
        }

        $orient = ($isModel || $isCategory || $show_company_col) ? 'landscape' : 'portrait';
        grid_export_send(
            'Master ' . $vm['label'],
            $headers,
            $row_stream,
            $export_format,
            $orient,
            $subParts ? implode(' · ', $subParts) : 'Semua Data',
            $total
        );
    }

    $p = paginate($total, (int) ($_GET['page'] ?? 1));
    $select_sql = $isModel
        ? "SELECT x.*, b.name AS brand_name, c.code AS company_code, c.name AS company_name {$from_sql}"
        : "SELECT x.*, c.code AS company_code, c.name AS company_name {$from_sql}";
    $st = $db->prepare("{$select_sql} WHERE {$where} {$order_sql} LIMIT ? OFFSET ?");
    $st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $has_active_filter = ($q !== '' || $status !== '' || $brandFilter > 0 || $svcFilter !== '' || $unitFilter !== '' || ($show_company_filter && $filter_company_id !== ($cid ?? 0)));
    $query_base        = vehicle_master_query([
        'page'         => null,
        'brand_id'     => $brandFilter > 0 ? (string) $brandFilter : null,
        'service_type' => $svcFilter !== '' ? $svcFilter : null,
        'pricing_unit' => $unitFilter !== '' ? $unitFilter : null,
        'status'       => $status !== '' ? $status : null,
    ]);
    $reset_href        = APP_URL . '/' . vehicle_master_route($vm);
    $delete_action     = APP_URL . '/' . vehicle_master_route($vm, 'delete.php');
    $colspan           = 6 + ($isModel ? 1 : 0) + ($isCategory ? 2 : 0) + ($show_company_col ? 1 : 0);

    $export_params = [
        'brand_id'     => ($isModel && $brandFilter > 0) ? (string) $brandFilter : null,
        'service_type' => ($isCategory && $svcFilter !== '') ? $svcFilter : null,
        'pricing_unit' => ($isCategory && $unitFilter !== '') ? $unitFilter : null,
        'status'       => $status !== '' ? $status : null,
    ];
    $header_actions = ui_export_actions($export_params);
    if ($canManage && $cid !== null) {
        $header_actions[] = [
            'label' => 'Tambah ' . $vm['short'],
            'icon'  => 'plus-lg',
            'href'  => vehicle_master_route($vm, 'form.php'),
            'class' => 'btn btn-primary btn-sm',
        ];
    }

    $breadcrumb = ($key === 'category')
        ? ['Dashboard' => 'pages/dashboard/index.php', 'Transportasi' => null, $vm['label'] => null]
        : ['Dashboard' => 'pages/dashboard/index.php', 'Kendaraan' => 'pages/vehicles/index.php', $vm['label'] => null];

    $active = $vm['active'] ?? 'vehicles';
    $title  = $vm['label'];
    require __DIR__ . '/../../includes/header.php';
    ?>
    <main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
        <?= ui_page_header(
            $vm['label'],
            $breadcrumb,
            [['text' => number_format($total, 0, ',', '.') . ' ' . $vm['short'], 'class' => 'bg-light text-secondary border']],
            $header_actions
        ) ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-2 px-3">
                <form method="get" class="row g-2 align-items-center erp-filter-bar">
                    <div class="col-12 col-sm-6 erp-filter-q">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" name="q" class="form-control" placeholder="<?= e($isModel ? 'Cari kode, nama, merek, atau catatan...' : 'Cari kode, nama, atau catatan...') ?>" value="<?= e($q) ?>">
                        </div>
                    </div>
                    <?php if ($show_company_filter): ?>
                        <div class="col-6 col-sm-4 erp-filter-item">
                            <select name="company_id" class="form-select form-select-sm" onchange="var b=this.form.querySelector('[name=brand_id]');if(b)b.value='';this.form.submit()" title="Pilih Perusahaan">
                                <?= ui_company_filter_options($vmCompanies, $filter_company_id, $has_explicit_company) ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($isModel): ?>
                        <div class="col-6 col-sm-4 erp-filter-item">
                            <select name="brand_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Merek">
                                <option value="">Semua Merek</option>
                                <?php foreach ($brandFilterOpts as $bf): ?>
                                    <option value="<?= (int) $bf['id'] ?>" <?= ((int) $bf['id'] === $brandFilter) ? 'selected' : '' ?>><?= e($bf['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($isCategory): ?>
                        <div class="col-6 col-sm-4 erp-filter-item">
                            <select name="service_type" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Layanan">
                                <option value="">Semua Layanan</option>
                                <?php foreach (CATEGORY_SERVICE_TYPES as $svcKey => $svcLabel): ?>
                                    <option value="<?= e($svcKey) ?>" <?= $svcFilter === $svcKey ? 'selected' : '' ?>><?= e($svcLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-4 erp-filter-item">
                            <select name="pricing_unit" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Satuan Tarif">
                                <option value="">Semua Satuan</option>
                                <?php foreach (CATEGORY_PRICING_UNITS as $uKey => $uLabel): ?>
                                    <option value="<?= e($uKey) ?>" <?= $unitFilter === $uKey ? 'selected' : '' ?>><?= e($uLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-6 col-sm-4 erp-filter-status">
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Status">
                            <option value="">Semua Status</option>
                            <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                            <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                    <?= ui_filter_actions($reset_href, $has_active_filter) ?>
                </form>
            </div>
        </div>

        <?php if ($canManage && $cid === null): ?>
            <div class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2">
                <i class="bi bi-building-exclamation"></i>
                <span>Pilih perusahaan aktif di pojok kanan atas terlebih dahulu untuk menambah <?= e(mb_strtolower($vm['label'])) ?> baru.</span>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width: 52px;">#</th>
                            <th style="width: 140px;">Kode</th>
                            <th>Nama</th>
                            <?php if ($isModel): ?><th>Merek</th><?php endif; ?>
                            <?php if ($isCategory): ?>
                                <th style="width: 110px;">Layanan</th>
                                <th style="width: 150px;">Satuan Tarif</th>
                            <?php endif; ?>
                            <?php if ($show_company_col): ?><th>Perusahaan</th><?php endif; ?>
                            <th>Catatan</th>
                            <th style="width: 100px;">Status</th>
                            <th class="text-end" style="width: 104px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $idx => $r):
                        $rowEditHref = APP_URL . '/' . vehicle_master_route($vm, 'form.php', ['id' => (int) $r['id']]);
                        $compText    = trim(($r['company_code'] ? $r['company_code'] . ' - ' : '') . ($r['company_name'] ?? ''));
                        $rowSvc      = (string) ($r['service_type'] ?? 'travel');
                        $rowUnit     = (string) ($r['pricing_unit'] ?? 'per_seat');
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($r['code']) ?></span></td>
                            <td class="fw-medium"><?= e($r['name']) ?></td>
                            <?php if ($isModel): ?>
                                <td><?= e($r['brand_name']) ?></td>
                            <?php endif; ?>
                            <?php if ($isCategory): ?>
                                <td>
                                    <span class="badge <?= $rowSvc === 'rental' ? 'bg-warning-subtle text-warning-emphasis border' : 'bg-primary-subtle text-primary border' ?>">
                                        <?= e(category_service_type_label($rowSvc)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e(category_pricing_unit_label($rowUnit)) ?></span>
                                </td>
                            <?php endif; ?>
                            <?php if ($show_company_col): ?>
                                <td class="small text-muted"><?= e($compText !== '' ? $compText : '-') ?></td>
                            <?php endif; ?>
                            <td class="small text-muted" style="max-width: 280px;">
                                <?php if (!empty($r['note'])): ?>
                                    <span class="d-inline-block text-truncate w-100 align-bottom" title="<?= e($r['note']) ?>"><?= e($r['note']) ?></span>
                                <?php else: ?>
                                    <span>&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                            <td class="text-end text-nowrap">
                                <?php if ($canManage): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e($rowEditHref) ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                    <form method="post" action="<?= e($delete_action) ?>" class="d-inline" onsubmit="return confirm('Hapus <?= e(mb_strtolower($vm['short'])) ?> <?= e($r['code']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= $colspan ?>" class="text-center text-muted py-4">Belum ada data <?= e(mb_strtolower($vm['label'])) ?>.</td></tr>
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
    <?php
    require __DIR__ . '/../../includes/footer.php';
}

/** Form tambah / ubah master kendaraan dengan layout ERP (Detail + Audit Trail). */
function vehicle_master_form(string $key): void
{
    vehicle_master_require_manage($key);
    $vm         = vehicle_master_def($key);
    $t          = $vm['table'];
    $isModel    = ($key === 'model');
    $isCategory = ($key === 'category');
    if ($isCategory) {
        category_service_ensure_schema();
    }
    $db         = db();
    $cid        = active_company_id();

    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id === 0 && $cid === null) {
        flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
        redirect(vehicle_master_route($vm));
    }

    $row = [
        'id'            => 0,
        'company_id'    => (int) ($cid ?? 0),
        'code'          => '',
        'name'          => '',
        'service_type'  => 'travel',
        'pricing_unit'  => 'per_seat',
        'note'          => '',
        'is_active'     => 1,
        'brand_id'      => 0,
        'created_user'  => null,
        'created_date'  => null,
        'modified_user' => null,
        'modified_date' => null,
    ];
    $companyLabel = '';
    $existingSvc  = 'travel';

    if ($id > 0) {
        $st = $db->prepare("SELECT x.*, c.code AS company_code, c.name AS company_name
                            FROM {$t} x
                            LEFT JOIN companies c ON c.id = x.company_id
                            WHERE x.id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $found = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$found) {
            flash('error', "{$vm['label']} tidak ditemukan.");
            redirect(vehicle_master_route($vm));
        }
        assert_company_access((int) $found['company_id']);
        $row         = $found + $row;
        $cid         = (int) $found['company_id'];
        $existingSvc = (string) ($found['service_type'] ?? 'travel');
        if (!empty($found['company_name'])) {
            $companyLabel = trim(($found['company_code'] ? $found['company_code'] . ' - ' : '') . $found['company_name']);
        }
    } elseif ($cid !== null) {
        $st = $db->prepare('SELECT code, name FROM companies WHERE id = ? LIMIT 1');
        $st->bind_param('i', $cid);
        $st->execute();
        $compRow = $st->get_result()->fetch_assoc();
        $st->close();
        if ($compRow) {
            $companyLabel = trim(($compRow['code'] ? $compRow['code'] . ' - ' : '') . $compRow['name']);
        }
    }

    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $posted = [
            'code'         => strtoupper(trim((string) ($_POST['code'] ?? ''))),
            'name'         => trim((string) ($_POST['name'] ?? '')),
            'service_type' => trim((string) ($_POST['service_type'] ?? $row['service_type'])),
            'pricing_unit' => trim((string) ($_POST['pricing_unit'] ?? $row['pricing_unit'])),
            'note'         => trim((string) ($_POST['note'] ?? '')),
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            'brand_id'     => (int) ($_POST['brand_id'] ?? 0),
        ];
        $row = array_merge($row, $posted);

        if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
            $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
        }
        if ($row['name'] === '') {
            $errors[] = 'Nama wajib diisi.';
        } elseif (mb_strlen($row['name']) > 255) {
            $errors[] = 'Nama maksimal 255 karakter.';
        }
        if (mb_strlen($row['note']) > 2000) {
            $errors[] = 'Catatan maksimal 2000 karakter.';
        }
        if ($isModel && !vehicle_owned($db, 'brand_vehicles', (int) $row['brand_id'], (int) $cid)) {
            $errors[] = 'Merek wajib dipilih dari perusahaan ini.';
        }
        if ($isCategory) {
            if (!isset(CATEGORY_SERVICE_TYPES[$row['service_type']])) {
                $errors[] = 'Lingkup layanan wajib dipilih (Travel atau Rental).';
            }
            if (!isset(CATEGORY_PRICING_UNITS[$row['pricing_unit']])) {
                $errors[] = 'Satuan hitung tarif tidak valid.';
            }
            if ($id > 0 && $row['service_type'] !== $existingSvc && isset(CATEGORY_SERVICE_TYPES[$row['service_type']])) {
                if ($existingSvc === 'travel' && $row['service_type'] === 'rental') {
                    $conflicts = [];
                    foreach ([['travel_ticket_bookings', 'Pesanan Tiket Travel']] as [$rt, $rl]) {
                        $cnt = vehicle_master_scalar($db, "SELECT COUNT(*) FROM {$rt} WHERE category_vehicle_id = ? AND company_id = ?", 'ii', [$id, $cid]);
                        if ($cnt > 0) {
                            $conflicts[] = "{$rl} ({$cnt})";
                        }
                    }
                    if ($conflicts) {
                        $errors[] = 'Lingkup layanan tidak dapat diubah ke Rental karena kategori ini masih dipakai pada ' . implode(', ', $conflicts) . '.';
                    }
                } elseif ($existingSvc === 'rental' && $row['service_type'] === 'travel') {
                    $cntRnt = vehicle_master_scalar($db, 'SELECT COUNT(*) FROM rental_car_bookings WHERE category_vehicle_id = ? AND company_id = ?', 'ii', [$id, $cid]);
                    if ($cntRnt > 0) {
                        $errors[] = "Lingkup layanan tidak dapat diubah ke Travel karena kategori ini masih dipakai pada Pesanan Sewa Mobil ({$cntRnt}).";
                    }
                }
            }
        }

        if (!$errors) {
            $noteVal = $row['note'] === '' ? null : $row['note'];
            $user    = $_SESSION['username'] ?? null;
            try {
                if ($id > 0) {
                    if ($isModel) {
                        $st = $db->prepare("UPDATE {$t} SET code = ?, name = ?, note = ?, is_active = ?, brand_id = ?,
                            modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
                        $st->bind_param(
                            'sssiisii',
                            $row['code'],
                            $row['name'],
                            $noteVal,
                            $row['is_active'],
                            $row['brand_id'],
                            $user,
                            $id,
                            $cid
                        );
                    } elseif ($isCategory) {
                        $st = $db->prepare("UPDATE {$t} SET code = ?, name = ?, service_type = ?, pricing_unit = ?, note = ?, is_active = ?,
                            modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
                        $st->bind_param(
                            'sssssisii',
                            $row['code'],
                            $row['name'],
                            $row['service_type'],
                            $row['pricing_unit'],
                            $noteVal,
                            $row['is_active'],
                            $user,
                            $id,
                            $cid
                        );
                    } else {
                        $st = $db->prepare("UPDATE {$t} SET code = ?, name = ?, note = ?, is_active = ?,
                            modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
                        $st->bind_param(
                            'sssisii',
                            $row['code'],
                            $row['name'],
                            $noteVal,
                            $row['is_active'],
                            $user,
                            $id,
                            $cid
                        );
                    }
                    $st->execute();
                    $st->close();
                    audit("vehicle_master.{$key}.update", "{$vm['label']} #{$id} {$row['code']}");
                    flash('success', "{$vm['label']} berhasil diperbarui.");
                } else {
                    if ($isModel) {
                        $st = $db->prepare("INSERT INTO {$t} (company_id, code, name, note, is_active, brand_id, created_user)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $st->bind_param(
                            'isssiis',
                            $cid,
                            $row['code'],
                            $row['name'],
                            $noteVal,
                            $row['is_active'],
                            $row['brand_id'],
                            $user
                        );
                    } elseif ($isCategory) {
                        $st = $db->prepare("INSERT INTO {$t} (company_id, code, name, service_type, pricing_unit, note, is_active, created_user)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $st->bind_param(
                            'isssssis',
                            $cid,
                            $row['code'],
                            $row['name'],
                            $row['service_type'],
                            $row['pricing_unit'],
                            $noteVal,
                            $row['is_active'],
                            $user
                        );
                    } else {
                        $st = $db->prepare("INSERT INTO {$t} (company_id, code, name, note, is_active, created_user)
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $st->bind_param('isssis', $cid, $row['code'], $row['name'], $noteVal, $row['is_active'], $user);
                    }
                    $st->execute();
                    $newId = $db->insert_id;
                    $st->close();
                    audit("vehicle_master.{$key}.create", "{$vm['label']} #{$newId} {$row['code']}");
                    flash('success', "{$vm['label']} berhasil ditambahkan.");
                }
                redirect(vehicle_master_route($vm));
            } catch (mysqli_sql_exception $ex) {
                if ((int) $ex->getCode() !== 1062) {
                    throw $ex;
                }
                $errors[] = vehicle_dup_message($ex->getMessage());
            }
        }
    }

    $brands      = $isModel ? vehicle_options($db, 'brand_vehicles', (int) $cid, (int) $row['brand_id']) : [];
    $listHref    = vehicle_master_route($vm);
    $formAction  = APP_URL . '/' . vehicle_master_route($vm, 'form.php', $id > 0 ? ['id' => $id] : []);
    $metaBadges  = $id > 0
        ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            $isCategory ? ['text' => category_service_type_label((string) ($row['service_type'] ?? 'travel')), 'class' => (($row['service_type'] ?? 'travel') === 'rental') ? 'bg-warning-subtle text-warning-emphasis border' : 'bg-info-subtle text-info-emphasis border'] : null,
            $isCategory ? ['text' => category_pricing_unit_label((string) ($row['pricing_unit'] ?? 'per_seat')), 'class' => 'bg-light text-dark border'] : null,
            $companyLabel !== '' ? ['text' => $companyLabel, 'class' => 'bg-light text-secondary border'] : null,
            ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'],
        ]))
        : array_values(array_filter([
            ['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border'],
            $companyLabel !== '' ? ['text' => $companyLabel, 'class' => 'bg-light text-secondary border'] : null,
        ]));

    $active = $vm['active'] ?? 'vehicles';
    $title  = ($id > 0 ? 'Ubah ' : 'Tambah ') . $vm['label'];
    require __DIR__ . '/../../includes/header.php';
    ?>
    <main class="p-2 p-md-3">
        <?= ui_erp_toolbar(
            $vm['label'],
            $vm['icon'],
            $id > 0 ? (string) $row['name'] : ('Tambah ' . $vm['label'] . ' Baru'),
            $metaBadges,
            [
                ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => $listHref, 'class' => 'btn btn-outline-secondary btn-sm'],
                ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formVehicleMaster', 'class' => 'btn btn-primary btn-sm px-3'],
            ]
        ) ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger py-2 px-3 small mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" id="vehMasterTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-vm-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-vm-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-vm-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-vm-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="erp-content-body">
                <form method="post" action="<?= e($formAction) ?>" id="formVehicleMaster">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="tab-content" id="vehMasterTabContent">
                        <div class="tab-pane fade show active" id="tab-vm-detail" role="tabpanel" aria-labelledby="tab-vm-detail-btn">
                            <div class="rnt-panel mb-0">
                                <div class="rnt-panel-header"><span>Informasi <?= e($vm['label']) ?></span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label" for="code">Kode <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm text-uppercase font-monospace" id="code" name="code" maxlength="15" required placeholder="Mis. <?= e($key === 'brand' ? 'TYT' : ($key === 'category' ? 'REG' : 'HIACE')) ?>" value="<?= e($row['code']) ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label" for="name">Nama <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                                        </div>
                                    </div>
                                    <?php if ($isCategory): ?>
                                        <div class="rnt-row">
                                            <label class="rnt-label" for="service_type">Lingkup Layanan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" id="service_type" name="service_type" required>
                                                    <option value="travel" <?= $row['service_type'] === 'travel' ? 'selected' : '' ?>>Travel (Rute &amp; 1x Jalan / Carter Drop)</option>
                                                    <option value="rental" <?= $row['service_type'] === 'rental' ? 'selected' : '' ?>>Rental (Sewa Harian / Durasi)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label" for="pricing_unit">Satuan Hitung Tarif <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" id="pricing_unit" name="pricing_unit" required>
                                                    <option value="per_seat" <?= $row['pricing_unit'] === 'per_seat' ? 'selected' : '' ?>>Per Kursi (Travel Reguler)</option>
                                                    <option value="per_vehicle" <?= $row['pricing_unit'] === 'per_vehicle' ? 'selected' : '' ?>>Per Mobil / Unit (Carter Drop / Borongan)</option>
                                                    <option value="per_package" <?= $row['pricing_unit'] === 'per_package' ? 'selected' : '' ?>>Per Paket (Dokumen / Barang / Hewan)</option>
                                                    <option value="per_day" <?= $row['pricing_unit'] === 'per_day' ? 'selected' : '' ?>>Per Hari (Sewa Harian / Rental)</option>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($isModel): ?>
                                        <div class="rnt-row">
                                            <label class="rnt-label" for="brand_id">Merek <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="brand_id" name="brand_id" required>
                                                    <option value="">— Pilih merek —</option>
                                                    <?php foreach ($brands as $b): ?>
                                                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $row['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1" for="note">Catatan</label>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="note" name="note" rows="2" maxlength="2000" placeholder="Keterangan tambahan (opsional)"><?= e((string) ($row['note'] ?? '')) ?></textarea>
                                        </div>
                                    </div>
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label" for="is_active">Status Aktif</label>
                                        <div class="rnt-field">
                                            <div class="rnt-check-wrap">
                                                <input class="form-check-input mt-0" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                                                <label class="form-check-label small fw-semibold" for="is_active"><?= e($vm['short']) ?> Aktif</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($id > 0): ?>
                            <div class="tab-pane fade" id="tab-vm-audit" role="tabpanel" aria-labelledby="tab-vm-audit-btn">
                                <?= ui_erp_audit_panel($row) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </main>
    <?php if ($isCategory && $id === 0): ?>
    <script>
    (function () {
        var svcEl  = document.getElementById('service_type');
        var unitEl = document.getElementById('pricing_unit');
        if (!svcEl || !unitEl) return;
        var unitTouched = false;
        unitEl.addEventListener('change', function () { unitTouched = true; });
        svcEl.addEventListener('change', function () {
            if (unitTouched) return;
            unitEl.value = svcEl.value === 'rental' ? 'per_day' : 'per_seat';
        });
    })();
    </script>
    <?php endif; ?>
    <?php
    require __DIR__ . '/../../includes/footer.php';
}

/** Hapus baris master kendaraan (POST + CSRF + validasi relasi FK). */
function vehicle_master_delete(string $key): void
{
    vehicle_master_require_manage($key);
    $vm = vehicle_master_def($key);
    $t  = $vm['table'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect(vehicle_master_route($vm));
    }
    verify_csrf();

    $db = db();
    $id = (int) ($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT id, company_id, code FROM {$t} WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$found) {
        flash('error', "{$vm['label']} tidak ditemukan.");
        redirect(vehicle_master_route($vm));
    }

    $rowCid = (int) $found['company_id'];
    assert_company_access($rowCid);

    if ($key === 'category') {
        rute_tariff_ensure_schema();
    }

    // Cek seluruh relasi FK sebelum menghapus agar pesan error ramah pengguna.
    $usedIn = [];
    foreach ($vm['refs'] as [$rt, $rc, $refLabel]) {
        $cnt = vehicle_master_scalar($db, "SELECT COUNT(*) FROM {$rt} WHERE {$rc} = ?", 'i', [$id]);
        if ($cnt > 0) {
            $usedIn[] = "{$refLabel} ({$cnt})";
        }
    }

    if ($usedIn) {
        flash('error', "{$vm['label']} tidak dapat dihapus: masih dipakai oleh " . implode(', ', $usedIn) . '.');
    } else {
        try {
            $st = $db->prepare("DELETE FROM {$t} WHERE id = ? AND company_id = ?");
            $st->bind_param('ii', $id, $rowCid);
            $st->execute();
            $affected = $st->affected_rows;
            $st->close();
            if ($affected > 0) {
                audit("vehicle_master.{$key}.delete", "{$vm['label']} #{$id} {$found['code']}");
                flash('success', "{$vm['label']} berhasil dihapus.");
            } else {
                flash('error', "{$vm['label']} tidak dapat dihapus.");
            }
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1451) {
                throw $ex;
            }
            flash('error', "{$vm['label']} tidak dapat dihapus karena masih digunakan modul lain.");
        }
    }

    redirect(vehicle_master_route($vm));
}
