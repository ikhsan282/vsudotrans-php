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
    $scope .= ' AND (i.invoice_number LIKE ? OR i.customer_name LIKE ?)';
    $scope_types .= 'ss';
    $q_like = '%' . $q . '%';
    array_push($scope_params, $q_like, $q_like);
}

$having = '';
if (in_array($filter, ['unpaid', 'partial', 'paid'], true)) {
    $having = ' HAVING pay_status = ?';
}

$sql_count = "SELECT COUNT(*) FROM invoices i WHERE {$scope}";
$st = db()->prepare($sql_count);
if ($scope_types !== '') { $st->bind_param($scope_types, ...$scope_params); }
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT i.invoice_number, i.issued_at, i.due_date, i.customer_name, i.total,
                   (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines
                    WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) AS paid_total,
                   CASE
                     WHEN i.status='cancelled' THEN 'cancelled'
                     WHEN (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) <= 0 THEN 'unpaid'
                     WHEN (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) + 0.001 >= i.total THEN 'paid'
                     ELSE 'partial'
                   END AS pay_status
                   FROM invoices i WHERE {$scope}{$having}
                   ORDER BY i.issued_at DESC, i.id DESC";

    $types_exp = $scope_types;
    $params_exp = $scope_params;
    if ($having !== '') {
        $types_exp .= 's';
        $params_exp[] = $filter;
    }

    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;
    $row_stream = grid_query_stream(
        $sql_export,
        $types_exp,
        $params_exp,
        static fn (array $r): array => [
            $r['invoice_number'],
            $r['issued_at'] ? date('d/m/Y', strtotime($r['issued_at'])) : '-',
            $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])) : '-',
            $r['customer_name'] ?: '-',
            (float) $r['total'],
            (float) $r['paid_total'],
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
        ['No Invoice', 'Tgl Terbit', 'Jatuh Tempo', 'Pelanggan', 'Total Tagihan', 'Total Dibayar', 'Status Bayar'],
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));

$sql = "SELECT i.*,
        (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines
         WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) AS paid_total,
        CASE
          WHEN i.status='cancelled' THEN 'cancelled'
          WHEN (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) <= 0 THEN 'unpaid'
          WHEN (SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines WHERE cost_type='payment' AND description = CONCAT('INV#', i.id)) + 0.001 >= i.total THEN 'paid'
          ELSE 'partial'
        END AS pay_status
        FROM invoices i WHERE {$scope}{$having}
        ORDER BY i.issued_at DESC, i.id DESC LIMIT ? OFFSET ?";
$st = db()->prepare($sql);
$types2 = $scope_types;
$params2 = $scope_params;
if ($having !== '') { $types2 .= 's'; $params2[] = $filter; }
$types2 .= 'ii';
$params2[] = $p['per_page'];
$params2[] = $p['offset'];
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
            <form method="get" class="row g-2 align-items-center">
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
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <?php if ($q !== '' || $filter !== ''): ?>
                        <a href="<?= e(APP_URL) ?>/pages/finance/invoices/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Nomor</th><th>Terbit</th><th>Jatuh Tempo</th><th>Pelanggan</th><th class="text-end">Total</th><th class="text-end">Dibayar</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td><a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['invoice_number']) ?></a></td>
                        <td><?= e(date('d/m/Y', strtotime($r['issued_at']))) ?></td>
                        <td><?= e(date('d/m/Y', strtotime($r['due_date']))) ?></td>
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
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $r['id'] ?>">Detail</a>
                            <a class="btn btn-sm btn-outline-danger" href="<?= e(APP_URL) ?>/pages/finance/invoices/pdf.php?id=<?= (int) $r['id'] ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a>
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
