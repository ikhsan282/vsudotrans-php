<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
transport_require_view();

$can_manage = transport_can_manage();

$scope = company_filter_scope('p.company_id');
$filter_company_id = $scope['filter_company_id'];
$where             = $scope['where'];
$types             = $scope['types'];
$params            = $scope['params'];
$companies         = $scope['companies'];
$active_cid        = $scope['active_cid'];

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $phoneNorm = phone_search_prefix($q);
    if ($phoneNorm !== null && $phoneNorm !== $q) {
        $where .= ' AND (p.code LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ? OR p.phone_number LIKE ? OR p.email LIKE ?)';
        $types .= 'sssss';
        $q_like = '%' . $q . '%';
        $p_like = '%' . $phoneNorm . '%';
        array_push($params, $q_like, $q_like, $q_like, $p_like, $q_like);
    } else {
        $where .= ' AND (p.code LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ? OR p.email LIKE ?)';
        $types .= 'ssss';
        $q_like = '%' . $q . '%';
        array_push($params, $q_like, $q_like, $q_like, $q_like);
    }
}

$st = db()->prepare("SELECT COUNT(*) FROM passengers p WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT p.code, p.name, p.phone_number, p.email
                   FROM passengers p WHERE {$where} ORDER BY p.name";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r): array {
            $phone = trim((string) ($r['phone_number'] ?? ''));
            return [
                $r['code'],
                $r['name'],
                $phone !== '' ? $phone : '-',
                $r['email'] ?: '-',
            ];
        },
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    grid_export_send(
        'Daftar Penumpang',
        ['Kode', 'Nama', 'Telepon', 'Email'],
        $row_stream,
        $export_format,
        'portrait',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT p.id, p.code, p.name, p.phone_number, p.email
                     FROM passengers p WHERE {$where} ORDER BY p.name LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if ($can_manage && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/transport/passengers/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$has_explicit_company = isset($_GET['company_id']);
$query_base = http_build_query(array_filter(
    ['q' => $q, 'company_id' => $has_explicit_company ? (string) $filter_company_id : null],
    static fn($v) => $v !== null && $v !== ''
));
$has_active_filter = ($q !== '' || ($has_explicit_company && $filter_company_id !== ($active_cid ?? 0)));

$active = 'passengers';
$title  = 'Penumpang';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Data Penumpang',
        ['Dashboard' => 'pages/dashboard/index.php', 'Transportasi' => null, 'Penumpang' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Orang', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari nama, kode, telepon..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Perusahaan">
                        <?= ui_company_filter_options($companies, $filter_company_id, $has_explicit_company) ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/transport/passengers/index.php', $has_active_filter) ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">#</th><th>Kode</th><th>Nama</th><th>Telepon</th><th>Email</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="fw-semibold"><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e(trim((string) ($r['phone_number'] ?? ''))) ?: '-' ?></td>
                        <td><?= e($r['email'] ?: '-') ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($can_manage): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/transport/passengers/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/transport/passengers/delete.php" class="d-inline" onsubmit="return confirm('Hapus penumpang ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data penumpang.</td></tr>
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
