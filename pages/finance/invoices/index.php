<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('invoices.view');
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$filter = (string) ($_GET['status'] ?? '');
[$where, $types, $params] = company_scope('i.company_id');
$scope = $where;
$scope_types = $types;
$scope_params = $params;

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

$active = 'finance';
$title  = 'Invoice';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Invoice</h4>
        <div class="btn-group btn-group-sm">
            <?php foreach (['' => 'Semua', 'unpaid' => 'Belum Bayar', 'partial' => 'Sebagian', 'paid' => 'Lunas'] as $k => $lbl): ?>
                <a class="btn btn-<?= $filter === $k ? 'primary' : 'outline-secondary' ?>" href="?status=<?= e($k) ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>No. Invoice</th><th>Resi</th><th>Tipe</th><th>Terbit</th>
                    <th class="text-end">Total</th><th class="text-end">Dibayar</th><th>Status</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['invoice_no']) ?></td>
                        <td><?= e($r['document_number']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= e($r['booking_type']) ?></span></td>
                        <td><?= e(substr((string) $r['issued_at'], 0, 16)) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['paid_total'])) ?></td>
                        <td><?= invoice_pay_badge($r['pay_status']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $r['id'] ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada invoice.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, 'status=' . urlencode($filter)) ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
