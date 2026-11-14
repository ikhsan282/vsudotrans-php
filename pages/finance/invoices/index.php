<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('invoices.view');
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$filter = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));

[$where, $types, $params] = company_scope('i.company_id');
$scope = $where;
$scope_types = $types;
$scope_params = $params;

if ($q !== '') {
    $scope .= ' AND (i.invoice_no LIKE ? OR i.document_number LIKE ? OR COALESCE(tp.name, rp.name, \'\') LIKE ?)';
    $scope_types .= 'sss';
    $q_like = '%' . $q . '%';
    array_push($scope_params, $q_like, $q_like, $q_like);
}

$pay_status_expr = "CASE
    WHEN i.status = 'cancelled' THEN 'cancelled'
    WHEN COALESCE(bcl.paid_total, 0) <= 0 THEN 'unpaid'
    WHEN COALESCE(bcl.paid_total, 0) + 0.001 >= i.total THEN 'paid'
    ELSE 'partial'
END";

$joins_sql = "FROM invoices i
    LEFT JOIN travel_ticket_bookings tb ON i.booking_type = 'travel' AND tb.id = i.booking_id
    LEFT JOIN passengers tp ON tp.id = tb.passenger_id
    LEFT JOIN rental_car_bookings rb ON i.booking_type = 'rental' AND rb.id = i.booking_id
    LEFT JOIN passengers rp ON rp.id = rb.passenger_id
    LEFT JOIN (
        SELECT description, SUM(amount) AS paid_total
        FROM booking_cost_lines
        WHERE cost_type = 'payment'
        GROUP BY description
    ) bcl ON bcl.description = CONCAT('INV#', i.id)";

$where_full = $scope;
$types_full = $scope_types;
$params_full = $scope_params;
if (in_array($filter, ['unpaid', 'partial', 'paid'], true)) {
    $where_full .= " AND ({$pay_status_expr}) = ?";
    $types_full .= 's';
    $params_full[] = $filter;
}

$sql_count = "SELECT COUNT(*) {$joins_sql} WHERE {$where_full}";
$st = db()->prepare($sql_count);
if ($types_full !== '') { $st->bind_param($types_full, ...$params_full); }
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT i.invoice_no AS invoice_number, i.document_number, i.issued_at,
                          COALESCE(tb.date, rb.start_date) AS due_date,
                          COALESCE(tp.name, rp.name, '-') AS customer_name,
                          i.total,
                          COALESCE(bcl.paid_total, 0) AS paid_total,
                          {$pay_status_expr} AS pay_status
                   {$joins_sql}
                   WHERE {$where_full}
                   ORDER BY i.issued_at DESC, i.id DESC";

    $isPdf = $export_format === 'pdf';
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;
    $row_stream = grid_query_stream(
        $sql_export,
        $types_full,
        $params_full,
        static fn (array $r): array => [
            $r['invoice_number'] ?: $r['document_number'],
            $r['issued_at'] ? date('d/m/Y', strtotime($r['issued_at'])) : '-',
            $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])) : '-',
            $r['customer_name'] ?: '-',
            $isPdf ? rupiah((float) $r['total']) : (float) $r['total'],
            $isPdf ? rupiah((float) $r['paid_total']) : (float) $r['paid_total'],
            ucfirst($r['pay_status']),
        ],
        $max_export_rows
    );

    $sub_parts = [];
    if ($q !== '') {
        $sub_parts[] = 'Pencarian: "' . $q . '"';
    }
    if ($filter !== '') {
        $sub_parts[] = 'Status: ' . ucfirst($filter);
    }
    $sub = $sub_parts ? implode(' | ', $sub_parts) : 'Semua Data';

    grid_export_send(
        'Daftar Invoice Tagihan',
        ['Nomor', 'Terbit', 'Jatuh Tempo', 'Pelanggan', 'Total', 'Dibayar', 'Status'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));

$sql = "SELECT i.*,
        i.invoice_no AS invoice_number,
        COALESCE(tb.date, rb.start_date) AS due_date,
        COALESCE(tp.name, rp.name, '-') AS customer_name,
        COALESCE(bcl.paid_total, 0) AS paid_total,
        {$pay_status_expr} AS pay_status
        {$joins_sql}
        WHERE {$where_full}
        ORDER BY i.issued_at DESC, i.id DESC LIMIT ? OFFSET ?";
$st = db()->prepare($sql);
$types2 = $types_full . 'ii';
$params2 = array_merge($params_full, [$p['per_page'], $p['offset']]);
$st->bind_param($types2, ...$params2);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('invoices.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Buat Invoice', 'icon' => 'plus-lg', 'href' => 'pages/finance/invoices/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$query_base = http_build_query(array_filter(['status' => $filter, 'q' => $q]));

$active = 'finance';
$title  = 'Invoice';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Invoice Tagihan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Keuangan' => null, 'Invoice' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Tagihan', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center erp-filter-bar">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari nomor invoice, pelanggan..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="unpaid" <?= $filter === 'unpaid' ? 'selected' : '' ?>>Belum Bayar</option>
                        <option value="partial" <?= $filter === 'partial' ? 'selected' : '' ?>>Sebagian</option>
                        <option value="paid" <?= $filter === 'paid' ? 'selected' : '' ?>>Lunas</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/finance/invoices/index.php', ($q !== '' || $filter !== '')) ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">#</th><th>Nomor</th><th>Terbit</th><th>Jatuh Tempo</th><th>Pelanggan</th><th class="text-end">Total</th><th class="text-end">Dibayar</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td><a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['invoice_number'] ?: $r['document_number']) ?></a></td>
                        <td><?= e($r['issued_at'] ? date('d/m/Y', strtotime($r['issued_at'])) : '-') ?></td>
                        <td><?= e($r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])) : '-') ?></td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td class="text-end fw-semibold"><?= e(rupiah((float) $r['total'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['paid_total'])) ?></td>
                        <td>
                            <?php
                            $badge = match ($r['pay_status']) {
                                'paid' => 'success',
                                'partial' => 'warning',
                                'cancelled' => 'dark',
                                default => 'danger',
                            };
                            ?>
                            <span class="badge bg-<?= $badge ?>"><?= ucfirst($r['pay_status']) ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $r['id'] ?>" title="Detail" aria-label="Detail"><i class="bi bi-eye"></i></a>
                            <a class="btn btn-sm btn-outline-danger" href="<?= e(APP_URL) ?>/pages/finance/invoices/pdf.php?id=<?= (int) $r['id'] ?>" target="_blank" title="Unduh PDF" aria-label="Unduh PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada invoice.</td></tr>
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
