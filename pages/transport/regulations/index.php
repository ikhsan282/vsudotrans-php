<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
transport_require_view();
require_once __DIR__ . '/../../../includes/regulation_service.php';

regulation_ensure_schema();

$companies  = accessible_companies();
$active_cid = active_company_id();
$canManage  = transport_can_manage();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    transport_require_manage();
    verify_csrf();
    $del_id  = (int) ($_POST['id'] ?? 0);
    $del_cid = (int) ($_POST['company_id'] ?? ($active_cid ?? 0));
    try {
        $allowed = is_super_admin() || in_array($del_cid, array_map('intval', array_column($companies, 'id')), true);
        if (!$allowed) {
            throw new RuntimeException('Anda tidak memiliki hak akses pada perusahaan ini.');
        }
        regulation_delete($del_id, $del_cid);
        audit('rental.regulation.delete', "Hapus Peraturan Komisi Karyawan #{$del_id}");
        flash('success', 'Peraturan Komisi Karyawan berhasil dihapus.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() ?: 'Gagal menghapus Peraturan Komisi Karyawan.');
    }
    redirect('pages/transport/regulations/index.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
if (isset($_GET['company_id'])) {
    $filter_company_id = $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : 0;
} else {
    $filter_company_id = $active_cid ?? 0;
}
$filter_category_id = (int) ($_GET['category_vehicle_id'] ?? 0);

if ($filter_company_id > 0) {
    $allowed = is_super_admin() || in_array($filter_company_id, array_map('intval', array_column($companies, 'id')), true);
    if ($allowed) {
        $where  = 'r.company_id = ?';
        $types  = 'i';
        $params = [$filter_company_id];
    } else {
        [$where, $types, $params] = company_scope('r.company_id');
        $filter_company_id = $active_cid ?? 0;
    }
} elseif ($filter_company_id === 0 && is_super_admin()) {
    $where  = '1=1';
    $types  = '';
    $params = [];
} else {
    [$where, $types, $params] = company_scope('r.company_id');
    $filter_company_id = $active_cid ?? 0;
}

if ($filter_category_id > 0) {
    $where   .= ' AND r.category_vehicle_id = ?';
    $types   .= 'i';
    $params[] = $filter_category_id;
}

if ($q !== '') {
    $where .= ' AND (r.note LIKE ? OR cv.name LIKE ? OR cv.code LIKE ?)';
    $types .= 'sss';
    $q_like = '%' . $q . '%';
    array_push($params, $q_like, $q_like, $q_like);
}

$st = db()->prepare("SELECT COUNT(*)
                       FROM rental_car_regulation_employees r
                       JOIN companies c ON c.id = r.company_id
                       JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
                      WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare(
    "SELECT r.*,
            c.code AS company_code, c.name AS company_name,
            cv.code AS category_code, cv.name AS category_name
       FROM rental_car_regulation_employees r
       JOIN companies c ON c.id = r.company_id
       JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
      WHERE {$where}
      ORDER BY c.code ASC, cv.code ASC, r.trip ASC, r.start_date DESC, r.id DESC
      LIMIT ? OFFSET ?"
);
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Ambil rincian tiap peraturan
$detailsByReg = [];
if ($rows) {
    $regIds = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
    $resD = db()->query(
        "SELECT regulation_id, employee_type, calc_type, amount
           FROM rental_car_regulation_employee_details
          WHERE regulation_id IN ({$regIds})
          ORDER BY regulation_id ASC, line_no ASC, id ASC"
    );
    if ($resD) {
        while ($d = $resD->fetch_assoc()) {
            $detailsByReg[(int) $d['regulation_id']][] = $d;
        }
    }
}

$categories = [];
if ($filter_company_id > 0) {
    $categories = array_map(
        static fn(array $cv): array => ['id' => (int) $cv['id'], 'label' => (string) ($cv['label'] ?? $cv['name'] ?? '')],
        opts_categories($filter_company_id, $filter_category_id > 0 ? [$filter_category_id] : [], 200)
    );
} else {
    $allowedCids = accessible_company_ids($active_cid ?? 0);
    if ($allowedCids) {
        $ph = implode(',', array_fill(0, count($allowedCids), '?'));
        $stCat = db()->prepare(
            "SELECT cv.id, CONCAT(c.code, ' | ', cv.code, ' - ', cv.name) AS label
               FROM category_vehicles cv
               LEFT JOIN companies c ON c.id = cv.company_id
              WHERE cv.company_id IN ({$ph})
                AND (cv.is_active = 1 OR cv.id = ?)
              ORDER BY c.code, cv.code, cv.name"
        );
        $stCat->bind_param(str_repeat('i', count($allowedCids)) . 'i', ...[...$allowedCids, $filter_category_id]);
        $stCat->execute();
        $categories = $stCat->get_result()->fetch_all(MYSQLI_ASSOC);
        $stCat->close();
    }
}

$header_actions = [];
if (can('travel.view')) {
    $header_actions[] = ['label' => 'Pemesanan Tiket', 'icon' => 'bus-front', 'href' => 'pages/travel/bookings/index.php', 'class' => 'btn btn-outline-secondary btn-sm'];
}
if (can('rental.view')) {
    $header_actions[] = ['label' => 'Pemesanan Sewa', 'icon' => 'car-front', 'href' => 'pages/rental/bookings/index.php', 'class' => 'btn btn-outline-secondary btn-sm'];
}
if ($canManage) {
    $header_actions[] = ['label' => 'Tambah Peraturan Komisi', 'icon' => 'plus-lg', 'href' => 'pages/transport/regulations/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_params = [];
if ($q !== '') $query_params['q'] = $q;
if (isset($_GET['company_id'])) $query_params['company_id'] = $_GET['company_id'];
if ($filter_category_id > 0) $query_params['category_vehicle_id'] = $filter_category_id;
$query_base = http_build_query($query_params);

$has_active_filter = ($q !== '' || $filter_category_id > 0 || (isset($_GET['company_id']) && (int) $_GET['company_id'] !== ($active_cid ?? 0)));

$active = 'regulations';
$title  = 'Daftar Peraturan Komisi Karyawan';
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-3 p-lg-4 w-100 flex-grow-1">

    <?= ui_page_header(
        'Daftar Peraturan Komisi Karyawan',
        [
            'Dashboard'        => 'pages/dashboard/index.php',
            'Transportasi'     => null,
            'Peraturan Komisi' => null,
        ],
        [['text' => number_format($total, 0, ',', '.') . ' Peraturan', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <!-- Filter -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari kategori kendaraan / catatan komisi..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="company_id" class="form-select form-select-sm" onchange="var f=this.form,c=f.querySelector('[name=category_vehicle_id]');if(c)c.value='0';f.submit()" title="Pilih Perusahaan">
                        <?= ui_company_filter_options($companies, $filter_company_id, isset($_GET['company_id'])) ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <select name="category_vehicle_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Kategori Kendaraan">
                        <option value="0">Semua Kategori Kendaraan</option>
                        <?php foreach ($categories as $cv): ?>
                            <option value="<?= (int) $cv['id'] ?>" <?= ((int) $cv['id'] === $filter_category_id) ? 'selected' : '' ?>>
                                <?= e($cv['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/transport/regulations/index.php', $has_active_filter) ?>
            </form>
        </div>
    </div>

    <!-- Tabel Peraturan Komisi Karyawan -->
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 52px;">#</th>
                        <th>Perusahaan</th>
                        <th>Kategori Kendaraan</th>
                        <th class="text-center">Trip</th>
                        <th>Masa Berlaku</th>
                        <th>Rincian Komisi &amp; Premi</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r):
                    $rid  = (int) $r['id'];
                    $dets = $detailsByReg[$rid] ?? [];
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td>
                            <span class="badge bg-light text-dark border fw-semibold"><?= e($r['company_code']) ?></span>
                        </td>
                        <td>
                            <a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/transport/regulations/form.php?id=<?= $rid ?>">
                                <?= e($r['category_code'] . ' - ' . $r['category_name']) ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary-subtle text-primary border"><?= (int) $r['trip'] ?></span>
                        </td>
                        <td class="small text-nowrap">
                            <?= !empty($r['start_date']) ? date('d-M-Y', strtotime($r['start_date'])) : '-' ?>
                            <span class="text-muted">s/d</span>
                            <?= !empty($r['end_date']) ? date('d-M-Y', strtotime($r['end_date'])) : '-' ?>
                        </td>
                        <td>
                            <?php if ($dets): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($dets as $d):
                                        $isPct = strcasecmp((string) $d['calc_type'], 'Persentase') === 0;
                                        $valTxt = $isPct
                                            ? rtrim(rtrim(number_format((float) $d['amount'], 2, '.', ''), '0'), '.') . '%'
                                            : 'Rp ' . number_format(round((float) $d['amount']), 0, ',', '.');
                                        $bCls = strcasecmp((string) $d['employee_type'], 'BBM') === 0
                                            ? 'bg-warning-subtle text-dark border'
                                            : 'bg-light text-secondary border';
                                    ?>
                                        <span class="badge reg-detail-badge <?= $bCls ?> fw-normal">
                                            <strong><?= e($d['employee_type']) ?>:</strong> <?= e($valTxt) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/transport/regulations/form.php?id=<?= $rid ?>" title="Detail / Ubah" aria-label="Detail / Ubah">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <?php if ($canManage): ?>
                                <form method="post" action="" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus peraturan komisi ini?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <input type="hidden" name="company_id" value="<?= (int) $r['company_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Peraturan Komisi" aria-label="Hapus Peraturan Komisi">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada Peraturan Komisi Karyawan. Klik <strong>Tambah Peraturan Komisi</strong> untuk menambahkan aturan baru.</td></tr>
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
<?php require __DIR__ . '/../../../includes/footer.php'; ?>

