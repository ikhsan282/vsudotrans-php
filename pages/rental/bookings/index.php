<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');
require_once __DIR__ . '/../../../pages/travel/_helpers.php';

$status_sql = "(SELECT h.to_status FROM booking_status_history h
    WHERE h.booking_type = 'rental' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1)";
[$where, $types, $params] = company_scope('b.company_id');

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

if ($q !== '') {
    $where .= ' AND (b.document_number LIKE ? OR p.name LIKE ?)';
    $types .= 'ss';
    $q_like = '%' . $q . '%';
    array_push($params, $q_like, $q_like);
}
if ($status !== '') {
    $where .= " AND COALESCE({$status_sql}, 'draft') = ?";
    $types .= 's';
    $params[] = $status;
}

$st = db()->prepare("SELECT COUNT(*) FROM rental_car_bookings b
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
    $sql_export = "SELECT b.document_number, b.start_date, b.end_date, p.name AS passenger, b.bbm, b.total_price,
                          COALESCE({$status_sql}, 'draft') AS status
                   FROM rental_car_bookings b
                   JOIN passengers p ON p.id = b.passenger_id
                   WHERE {$where} ORDER BY b.start_date DESC, b.id DESC";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            $r['document_number'],
            $r['start_date'],
            $r['end_date'],
            $r['passenger'],
            (float) $r['bbm'],
            (float) $r['total_price'],
            ucfirst($r['status']),
        ],
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    grid_export_send(
        'Daftar Booking Rental',
        ['No Resi / Dokumen', 'Tgl Mulai', 'Tgl Selesai', 'Penyewa', 'Biaya BBM', 'Total Biaya', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date, b.total_price, b.bbm, p.name AS passenger,
                     COALESCE({$status_sql}, 'draft') AS status
                     FROM rental_car_bookings b
                     JOIN passengers p ON p.id = b.passenger_id
                     WHERE {$where} ORDER BY b.start_date DESC, b.id DESC LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('rental.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/rental/bookings/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$active = 'rental';
$title  = 'Booking Rental';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Booking Rental',
        ['Dashboard' => 'pages/dashboard/index.php', 'Rental' => null, 'Booking' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Booking', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari resi atau nama penyewa..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'paid' => 'Paid', 'assigned' => 'Assigned', 'on_the_way' => 'On The Way', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $sk => $sl): ?>
                            <option value="<?= $sk ?>" <?= $status === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <?php if ($q !== '' || $status !== ''): ?>
                        <a href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Resi</th><th>Mulai</th><th>Selesai</th><th>Penumpang</th><th class="text-end">BBM</th><th class="text-end">Total</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td><a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['document_number']) ?></a></td>
                        <td><?= e($r['start_date']) ?></td>
                        <td><?= e($r['end_date']) ?></td>
                        <td><?= e($r['passenger']) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['bbm'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= (int) $r['id'] ?>">Detail</a>
                            <?php if (can('rental.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/rental/bookings/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada booking rental.</td></tr>
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
