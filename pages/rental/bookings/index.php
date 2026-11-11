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
        audit('booking.rental.delete', "Hapus booking {$doc_no} (#{$del_id})");
        flash('success', "Booking rental {$doc_no} berhasil dihapus.");
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() ?: 'Gagal menghapus booking rental.');
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
    $where .= ' AND (b.document_number LIKE ? OR p.name LIKE ?)';
    $types .= 'ss';
    $q_like = '%' . $q . '%';
    array_push($params, $q_like, $q_like);
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
    $sql_export = "SELECT b.document_number, c.code AS company_code, b.start_date, b.end_date, p.name AS passenger, b.bbm, b.total_price,
                          COALESCE({$status_sql}, 'draft') AS status
                   FROM rental_car_bookings b
                   JOIN companies c ON c.id = b.company_id
                   JOIN passengers p ON p.id = b.passenger_id
                   WHERE {$where} ORDER BY b.start_date DESC, b.id DESC";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['document_number'],
            $r['company_code'],
            !empty($r['start_date']) ? date('d-M-Y', strtotime($r['start_date'])) : '-',
            !empty($r['end_date']) ? date('d-M-Y', strtotime($r['end_date'])) : '-',
            $r['passenger'],
            (float) $r['bbm'],
            (float) $r['total_price'],
            ucfirst($r['status']),
        ],
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
        'Daftar Booking Rental',
        ['No Resi / Dokumen', 'Perusahaan', 'Tgl Mulai', 'Tgl Selesai', 'Penyewa', 'Biaya BBM', 'Total Biaya', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.company_id, c.code AS company_code, c.name AS company_name,
                     b.document_number, b.start_date, b.end_date, b.total_price, b.bbm, p.name AS passenger,
                     COALESCE({$status_sql}, 'draft') AS status
                     FROM rental_car_bookings b
                     JOIN companies c ON c.id = b.company_id
                     JOIN passengers p ON p.id = b.passenger_id
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
$title  = 'Booking Rental';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Booking Rental',
        ['Dashboard' => 'pages/dashboard/index.php', 'Rental' => null, 'Booking' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Booking', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <!-- Filter Ringkas (1 Baris Sederhana) -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-12 col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari resi / penyewa..." value="<?= e($q) ?>">
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
                        <th class="text-center" style="width: 52px;">No</th>
                        <th>Resi &amp; Perusahaan</th>
                        <th>Mulai</th>
                        <th>Selesai</th>
                        <th>Penumpang</th>
                        <th class="text-end">BBM</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td>
                            <a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>">
                                <?= e($r['document_number']) ?>
                            </a>
                            <div class="small">
                                <span class="badge bg-light text-secondary border"><?= e($r['company_code']) ?></span>
                            </div>
                        </td>
                        <td><?= !empty($r['start_date']) ? date('d-M-Y', strtotime($r['start_date'])) : '-' ?></td>
                        <td><?= !empty($r['end_date']) ? date('d-M-Y', strtotime($r['end_date'])) : '-' ?></td>
                        <td><?= e($r['passenger']) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['bbm'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>">
                                <i class="bi bi-pencil-square me-1"></i>Detail / Ubah
                            </a>
                            <?php if (can('rental.manage')): ?>
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
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada booking rental yang cocok dengan filter yang dipilih.</td></tr>
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
