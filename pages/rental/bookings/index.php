<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');
require_once __DIR__ . '/../../../pages/travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$status_sql = "(SELECT h.to_status FROM booking_status_history h
    WHERE h.booking_type = 'rental' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1)";

$companies  = accessible_companies();
$active_cid = active_company_id();

// Aksi Hapus Booking Rental
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    require_permission('rental.manage');
    verify_csrf();
    $del_id  = (int) ($_POST['id'] ?? 0);
    $del_cid = (int) ($_POST['company_id'] ?? ($active_cid ?? 0));
    try {
        $allowed = is_super_admin() || in_array($del_cid, array_map('intval', array_column($companies, 'id')), true);
        if (!$allowed) {
            throw new RuntimeException('Anda tidak memiliki hak akses pada perusahaan ini.');
        }
        $doc_no = booking_delete('rental', $del_id, $del_cid);
        audit('booking.rental.delete', "Hapus pemesanan {$doc_no} (#{$del_id})");
        flash('success', "Pemesanan sewa kendaraan {$doc_no} berhasil dihapus.");
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() ?: 'Gagal menghapus pemesanan sewa kendaraan.');
    }
    redirect('pages/rental/bookings/index.php');
}

// Parameter Filter
$q          = trim((string) ($_GET['q'] ?? ''));
$status     = trim((string) ($_GET['status'] ?? ''));
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date   = trim((string) ($_GET['end_date'] ?? ''));

// Filter Perusahaan (Company)
if (isset($_GET['company_id'])) {
    $filter_company_id = $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : 0;
} else {
    $filter_company_id = $active_cid ?? 0;
}

// Scoping company
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

// Filter Rentang Tanggal (Mulai & Akhir)
if ($start_date !== '' && $end_date !== '') {
    $where .= ' AND (b.start_date <= ? AND b.end_date >= ?)';
    $types .= 'ss';
    $params[] = $end_date;
    $params[] = $start_date;
} elseif ($start_date !== '') {
    $where .= ' AND b.end_date >= ?';
    $types .= 's';
    $params[] = $start_date;
} elseif ($end_date !== '') {
    $where .= ' AND b.start_date <= ?';
    $types .= 's';
    $params[] = $end_date;
}

// Filter Pencarian Resi / Penumpang
if ($q !== '') {
    $where .= ' AND (b.document_number LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ?)';
    $types .= 'sss';
    $q_like = '%' . $q . '%';
    array_push($params, $q_like, $q_like, $q_like);
}

// Filter Status
if ($status !== '') {
    $where .= " AND COALESCE({$status_sql}, 'draft') = ?";
    $types .= 's';
    $params[] = $status;
}

// Hitung Total Data
$st = db()->prepare("SELECT COUNT(*) FROM rental_car_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
                     WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

// Export XLSX / PDF
$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT b.document_number, c.code AS company_code,
                          p.name AS passenger_name, p.phone_number AS passenger_phone,
                          CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number) AS vehicle_name,
                          v.vehicle_number, cv.name AS category_name,
                          b.time, b.start_date, b.end_date,
                          pc.name AS pickup_city_name, dc.name AS delivery_city_name,
                          b.total_price, COALESCE(b.total_payment, 0) AS total_payment,
                          COALESCE({$status_sql}, 'draft') AS status
                   FROM rental_car_bookings b
                   JOIN companies c ON c.id = b.company_id
                   JOIN passengers p ON p.id = b.passenger_id
                   LEFT JOIN vehicles v ON v.id = b.vehicle_id
                   LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                   LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                   LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
                   LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                   LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                   WHERE {$where} ORDER BY b.start_date DESC, b.id DESC";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r): array {
            $veh = $r['vehicle_name'] ?: ($r['vehicle_number'] ?: ($r['category_name'] ?: '-'));
            $jam = !empty($r['time']) ? substr($r['time'], 0, 5) : '-';
            $tgl = !empty($r['start_date']) ? date('d-M-Y', strtotime($r['start_date'])) : '-';
            if (!empty($r['end_date']) && $r['end_date'] !== $r['start_date']) {
                $tgl .= ' s/d ' . date('d-M-Y', strtotime($r['end_date']));
            }
            return [
                $r['document_number'],
                $r['passenger_name'] . ($r['passenger_phone'] ? ' (' . $r['passenger_phone'] . ')' : ''),
                $veh,
                $jam,
                $tgl,
                $r['pickup_city_name'] ?: '-',
                $r['delivery_city_name'] ?: '-',
                (float) $r['total_price'],
                (float) $r['total_payment'],
                ucfirst($r['status']),
            ];
        },
        $max_export_rows
    );

    $sub_parts = [];
    if ($filter_company_id > 0) {
        foreach ($companies as $c) {
            if ((int) $c['id'] === $filter_company_id) {
                $sub_parts[] = 'Perusahaan: ' . $c['code'] . ' - ' . $c['name'];
                break;
            }
        }
    } elseif ($filter_company_id === 0 && is_super_admin()) {
        $sub_parts[] = 'Perusahaan: Semua Perusahaan';
    }
    if ($start_date !== '' || $end_date !== '') {
        $d1 = $start_date ? date('d-M-Y', strtotime($start_date)) : '...';
        $d2 = $end_date ? date('d-M-Y', strtotime($end_date)) : '...';
        $sub_parts[] = "Periode: {$d1} s/d {$d2}";
    }
    if ($status !== '') {
        $sub_parts[] = 'Status: ' . ucfirst($status);
    }
    if ($q !== '') {
        $sub_parts[] = 'Pencarian: "' . $q . '"';
    }
    $sub = $sub_parts ? implode(' | ', $sub_parts) : 'Semua Data';

    grid_export_send(
        'Daftar Pemesanan Sewa Kendaraan',
        ['Resi', 'Penumpang (Nama & HP)', 'Kendaraan', 'Jam', 'Tanggal', 'Penjemputan', 'Pengantaran', 'Total Harga', 'Total Pembayaran', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.company_id, c.code AS company_code, c.name AS company_name,
                     b.document_number, b.time, b.start_date, b.end_date, b.total_price,
                     COALESCE(b.total_payment, 0) AS total_payment,
                     p.name AS passenger_name, p.phone_number AS passenger_phone,
                     CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number) AS vehicle_name,
                     v.vehicle_number, cv.name AS category_name,
                     pc.name AS pickup_city_name, dc.name AS delivery_city_name,
                     COALESCE({$status_sql}, 'draft') AS status
                     FROM rental_car_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
                     LEFT JOIN vehicles v ON v.id = b.vehicle_id
                     LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                     LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                     LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
                     LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                     LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                     WHERE {$where} ORDER BY b.start_date DESC, b.id DESC LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
$header_actions[] = ['label' => 'Peraturan Komisi', 'icon' => 'sliders', 'href' => 'pages/rental/regulations/index.php', 'class' => 'btn btn-outline-secondary btn-sm'];
if (can('rental.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/rental/bookings/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_params = [];
if ($q !== '') $query_params['q'] = $q;
if ($status !== '') $query_params['status'] = $status;
if ($start_date !== '') $query_params['start_date'] = $start_date;
if ($end_date !== '') $query_params['end_date'] = $end_date;
if (isset($_GET['company_id'])) $query_params['company_id'] = $_GET['company_id'];
$query_base = http_build_query($query_params);

$has_active_filter = ($q !== '' || $status !== '' || $start_date !== '' || $end_date !== '' || (isset($_GET['company_id']) && (int) $_GET['company_id'] !== ($active_cid ?? 0)));

$active = 'rental';
$title  = 'Daftar Pemesanan Sewa Kendaraan';
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-3 p-lg-4 w-100 flex-grow-1">

    <?= ui_page_header(
        'Daftar Pemesanan Sewa Kendaraan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Rental' => null, 'Pemesanan' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Pemesanan', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <!-- Filter Ringkas (1 Baris Sederhana) -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
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
                <div class="col-auto d-flex gap-1 ms-auto">
                    <button type="submit" class="btn btn-sm btn-primary" title="Terapkan Filter">
                        <i class="bi bi-funnel-fill me-1"></i>Filter
                    </button>
                    <?php if ($has_active_filter): ?>
                        <a href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php" class="btn btn-sm btn-outline-danger" title="Reset filter">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabel Data Booking Rental -->
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 48px;">No</th>
                        <th>Resi</th>
                        <th>Penumpang</th>
                        <th>Kendaraan</th>
                        <th>Jam</th>
                        <th>Tanggal</th>
                        <th>Penjemputan</th>
                        <th>Pengantaran</th>
                        <th class="text-end">Total Harga</th>
                        <th class="text-end">Total Pembayaran</th>
                        <th class="text-center">Status</th>
                        <th class="text-end" style="width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): 
                    $veh_name = $r['vehicle_name'] ?: ($r['vehicle_number'] ?: ($r['category_name'] ?: '-'));
                    $jam = !empty($r['time']) ? substr($r['time'], 0, 5) : '-';
                    $tgl_mulai = !empty($r['start_date']) ? date('d-M-Y', strtotime($r['start_date'])) : '-';
                    $tgl_selesai = !empty($r['end_date']) ? date('d-M-Y', strtotime($r['end_date'])) : '';
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td>
                            <a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>">
                                <?= e($r['document_number']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="small fw-semibold text-dark"><?= e($r['passenger_name']) ?></div>
                            <div class="text-muted" style="font-size: 0.8rem;"><?= e($r['passenger_phone'] ?: '-') ?></div>
                        </td>
                        <td>
                            <span class="text-nowrap"><?= e($veh_name) ?></span>
                        </td>
                        <td class="text-nowrap">
                            <?= e($jam) ?>
                        </td>
                        <td class="text-nowrap">
                            <div><?= e($tgl_mulai) ?></div>
                            <?php if ($tgl_selesai !== '' && $tgl_selesai !== $tgl_mulai): ?>
                                <div class="small text-muted">s/d <?= e($tgl_selesai) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['pickup_city_name'] ?: '-') ?></td>
                        <td><?= e($r['delivery_city_name'] ?: '-') ?></td>
                        <td class="text-end fw-semibold text-nowrap"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td class="text-end fw-semibold text-nowrap text-success"><?= e(rupiah((float) $r['total_payment'])) ?></td>
                        <td class="text-center"><?= status_badge($r['status']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>" title="Lihat Detail">
                                <i class="bi bi-eye me-1"></i>Detail
                            </a>
                            <?php if (can('rental.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>" title="Ubah Pemesanan">
                                    <i class="bi bi-pencil-square me-1"></i>Ubah
                                </a>
                                <form method="post" action="" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus permanen booking <?= e($r['document_number']) ?> beserta invoice dan riwayat pembayarannya?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="act" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="company_id" value="<?= (int) $r['company_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Booking">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">Belum ada pemesanan sewa kendaraan yang cocok dengan filter yang dipilih.</td></tr>
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
    document.querySelectorAll('.flatpickr-date').forEach(function (el) {
        flatpickr(el, {
            altInput: true,
            altFormat: "d-M-Y",
            dateFormat: "Y-m-d",
            altInputClass: el.className,
            allowInput: false
        });
    });
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
