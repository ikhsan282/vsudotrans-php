<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    require_permission('travel.manage');
    verify_csrf();
    $delId = (int) ($_POST['id'] ?? 0);
    $delBk = $delId > 0 ? booking_find('travel', $delId) : null;
    if (!$delBk) {
        flash('error', 'Pemesanan tiket perjalanan tidak ditemukan.');
        redirect('pages/travel/bookings/index.php');
    }
    assert_company_access((int) $delBk['company_id']);
    try {
        $docNum = booking_delete('travel', $delId, (int) $delBk['company_id']);
        audit('booking.travel.delete', "Pemesanan #{$delId} ({$docNum})");
        flash('success', "Pemesanan tiket perjalanan {$docNum} berhasil dihapus.");
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/travel/bookings/index.php');
}

$status_sql = "(SELECT h.to_status FROM booking_status_history h
    WHERE h.booking_type = 'travel' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1)";

$companies  = accessible_companies();
$active_cid = active_company_id();

$q          = trim((string) ($_GET['q'] ?? ''));
$status     = trim((string) ($_GET['status'] ?? ''));
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date   = trim((string) ($_GET['end_date'] ?? ''));

if ($start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = '';
}
if ($end_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = '';
}

if (isset($_GET['company_id'])) {
    $filter_company_id = $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : 0;
} else {
    $filter_company_id = $active_cid ?? 0;
}

if ($filter_company_id > 0) {
    $allowed = is_super_admin() || in_array($filter_company_id, array_map('intval', array_column($companies, 'id')), true);
    if ($allowed) {
        $where  = 'b.company_id = ?';
        $types  = 'i';
        $params = [$filter_company_id];
    } else {
        [$where, $types, $params] = company_scope('b.company_id');
        $filter_company_id = $active_cid ?? 0;
    }
} elseif ($filter_company_id === 0 && is_super_admin()) {
    $where  = '1=1';
    $types  = '';
    $params = [];
} else {
    [$where, $types, $params] = company_scope('b.company_id');
    $filter_company_id = $active_cid ?? 0;
}

if ($start_date !== '') {
    $where .= ' AND b.date >= ?';
    $types .= 's';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where .= ' AND b.date <= ?';
    $types .= 's';
    $params[] = $end_date;
}

if ($q !== '') {
    $where .= ' AND ' . booking_passenger_search_sql('travel');
    $types .= 'sssss';
    $q_like = '%' . $q . '%';
    array_push($params, $q_like, $q_like, $q_like, $q_like, $q_like);
}
if ($status !== '') {
    $where .= " AND COALESCE({$status_sql}, 'draft') = ?";
    $types .= 's';
    $params[] = $status;
}

$st = db()->prepare("SELECT COUNT(*) FROM travel_ticket_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
                     WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $isPdf     = $export_format === 'pdf';
    $pax_subq  = $isPdf ? booking_extra_passenger_count_sql('travel') : booking_extra_passengers_export_sql('travel');
    $pax_alias = $isPdf ? 'extra_cnt' : 'extra_passengers';
    $sql_export = "SELECT b.document_number, b.date, b.time,
                          p.name AS passenger_name, p.phone_number AS passenger_phone,
                          {$pax_subq} AS {$pax_alias},
                          bv.name AS brand_name, mu.name AS model_name,
                          v.vehicle_number, cv.name AS category_name,
                          pc.name AS pickup_city_name, dc.name AS delivery_city_name,
                          b.total_price, COALESCE(b.total_payment, 0) AS total_payment,
                          COALESCE({$status_sql}, 'draft') AS status
                     FROM travel_ticket_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
                     LEFT JOIN vehicles v ON v.id = b.vehicle_id
                     LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                     LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                     LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
                     LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                     LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                     WHERE {$where} ORDER BY b.date DESC, b.id DESC";
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf): array {
            $dateLabel = !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '-';
            return booking_export_row($r, $isPdf, $dateLabel);
        },
        $max_export_rows
    );

    $subParts = [];
    if ($q !== '') $subParts[] = 'Pencarian: "' . $q . '"';
    if ($start_date !== '' || $end_date !== '') $subParts[] = 'Tgl: ' . ($start_date ?: '...') . ' s/d ' . ($end_date ?: '...');
    if ($status !== '') $subParts[] = 'Status: ' . ucfirst($status);
    $sub = $subParts ? implode(' | ', $subParts) : 'Semua Data';

    grid_export_send(
        'Pemesanan Tiket Perjalanan',
        booking_export_headers($isPdf, 'Asal', 'Tujuan'),
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.company_id, c.code AS company_code, c.name AS company_name,
                     b.document_number, b.date, b.time, b.total_price,
                     COALESCE(b.total_payment, 0) AS total_payment,
                     p.name AS passenger_name, p.phone_number AS passenger_phone,
                     bv.name AS brand_name, mu.name AS model_name,
                     v.vehicle_number, cv.name AS category_name,
                     pc.name AS pickup_city_name, dc.name AS delivery_city_name,
                     COALESCE({$status_sql}, 'draft') AS status
                     FROM travel_ticket_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
                     LEFT JOIN vehicles v ON v.id = b.vehicle_id
                     LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                     LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                     LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
                     LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                     LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                     WHERE {$where} ORDER BY b.date DESC, b.id DESC LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$extraCounts = booking_extra_passenger_counts('travel', array_column($rows, 'id'));

$header_actions = ui_export_actions();
if (can('travel.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/bookings/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_params = [];
if ($q !== '') $query_params['q'] = $q;
if ($status !== '') $query_params['status'] = $status;
if ($start_date !== '') $query_params['start_date'] = $start_date;
if ($end_date !== '') $query_params['end_date'] = $end_date;
if (isset($_GET['company_id'])) $query_params['company_id'] = $_GET['company_id'];
$query_base = http_build_query($query_params);

$has_active_filter = ($q !== '' || $status !== '' || $start_date !== '' || $end_date !== '' || (isset($_GET['company_id']) && (int) $_GET['company_id'] !== ($active_cid ?? 0)));

$active = 'travel';
$title  = 'Pemesanan Tiket Perjalanan';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Pemesanan Tiket Perjalanan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Pemesanan Tiket Perjalanan' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Pemesanan', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar erp-filter-bar-wide">
                <div class="col-12 col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari resi / penumpang / no. hp..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <input type="text" name="start_date" class="form-control form-control-sm bg-white flatpickr-date" value="<?= e($start_date) ?>" placeholder="Tgl Mulai">
                </div>
                <div class="col-6 col-md-2">
                    <input type="text" name="end_date" class="form-control form-control-sm bg-white flatpickr-date" value="<?= e($end_date) ?>" placeholder="Tgl Akhir">
                </div>
                <div class="col-6 col-md-2">
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Perusahaan">
                        <?php if (is_super_admin()): ?>
                            <option value="0" <?= ($filter_company_id === 0 && isset($_GET['company_id'])) ? 'selected' : '' ?>>Semua Perusahaan</option>
                        <?php endif; ?>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === $filter_company_id) ? 'selected' : '' ?>>
                                <?= e($c['code'] . ' - ' . $c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Status">
                        <option value="">Semua Status</option>
                        <?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'paid' => 'Paid', 'assigned' => 'Assigned', 'on_the_way' => 'On The Way', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $sk => $sl): ?>
                            <option value="<?= $sk ?>" <?= $status === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/travel/bookings/index.php', $has_active_filter) ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 48px;">#</th>
                        <th>Resi</th>
                        <th>Penumpang</th>
                        <th class="text-center">Kendaraan</th>
                        <th class="text-center">Jam</th>
                        <th>Tanggal</th>
                        <th>Asal</th>
                        <th>Tujuan</th>
                        <th class="text-end">Total Harga</th>
                        <th class="text-end">Total Pembayaran</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 100px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r):
                    $plate = trim((string) ($r['vehicle_number'] ?? ''));
                    $brand_model = trim(implode(' - ', array_filter([$r['brand_name'] ?? '', $r['model_name'] ?? ''])));
                    $jam = !empty($r['time']) ? substr($r['time'], 0, 5) : '-';
                    $tgl = !empty($r['date']) ? date('d-M-Y', strtotime($r['date'])) : '-';
                    $exCnt = $extraCounts[(int) $r['id']] ?? 0;
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td>
                            <a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/travel/bookings/detail.php?id=<?= (int) $r['id'] ?>">
                                <?= e($r['document_number']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="small fw-semibold text-dark d-flex flex-wrap align-items-center gap-1">
                                <span><?= e($r['passenger_name']) ?></span>
                                <?php if ($exCnt > 0): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border">+<?= $exCnt ?> tambahan</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.8rem;"><?= e($r['passenger_phone'] ?: '-') ?></div>
                        </td>
                        <td class="text-center">
                            <?php if ($plate !== ''): ?>
                                <div class="small fw-semibold text-dark text-nowrap"><?= e($plate) ?></div>
                                <?php if ($brand_model !== ''): ?>
                                    <div class="text-muted text-nowrap" style="font-size: 0.8rem;"><?= e($brand_model) ?></div>
                                <?php elseif (!empty($r['category_name'])): ?>
                                    <div class="text-muted text-nowrap" style="font-size: 0.8rem;"><?= e($r['category_name']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($brand_model !== ''): ?>
                                <div class="small fw-semibold text-dark text-nowrap"><?= e($brand_model) ?></div>
                                <?php if (!empty($r['category_name'])): ?>
                                    <div class="text-muted text-nowrap" style="font-size: 0.8rem;"><?= e($r['category_name']) ?></div>
                                <?php endif; ?>
                            <?php elseif (!empty($r['category_name'])): ?>
                                <div class="small text-muted text-nowrap"><?= e($r['category_name']) ?></div>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap"><?= e($jam) ?></td>
                        <td class="text-nowrap small"><?= e($tgl) ?></td>
                        <td class="small"><?= e($r['pickup_city_name'] ?: '-') ?></td>
                        <td class="small"><?= e($r['delivery_city_name'] ?: '-') ?></td>
                        <td class="text-end fw-semibold text-nowrap"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td class="text-end fw-semibold text-nowrap text-success"><?= e(rupiah((float) $r['total_payment'])) ?></td>
                        <td class="text-center"><?= status_badge($r['status']) ?></td>
                        <td class="text-center text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/bookings/detail.php?id=<?= (int) $r['id'] ?>" title="Lihat Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (can('travel.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/bookings/detail.php?id=<?= (int) $r['id'] ?>" title="Ubah Pemesanan">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus pemesanan <?= e($r['document_number']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Pemesanan">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">Belum ada pemesanan tiket perjalanan yang cocok dengan filter yang dipilih.</td></tr>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof flatpickr !== 'undefined') {
        document.querySelectorAll('.flatpickr-date').forEach(function (el) {
            flatpickr(el, {
                altInput: true,
                altFormat: 'd-M-Y',
                dateFormat: 'Y-m-d',
                altInputClass: el.className,
                allowInput: false
            });
        });
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
