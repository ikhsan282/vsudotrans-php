<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

$status_sql = "(SELECT h.to_status FROM booking_status_history h
    WHERE h.booking_type = 'travel' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1)";
[$where, $types, $params] = company_scope('b.company_id');
$st = db()->prepare("SELECT COUNT(*) FROM travel_ticket_bookings b WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT b.id, b.document_number, b.date, b.time, b.total_price, p.name AS passenger,
                     COALESCE({$status_sql}, 'draft') AS status
                     FROM travel_ticket_bookings b
                     JOIN passengers p ON p.id = b.passenger_id
                     WHERE {$where} ORDER BY b.date DESC, b.id DESC LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'travel';
$title  = 'Booking Travel';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Booking Travel</h4>
        <?php if (can('travel.manage') && active_company_id() !== null): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/form.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah</a>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Resi</th><th>Tanggal</th><th>Penumpang</th><th class="text-end">Total</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['document_number']) ?></td>
                        <td><?= e($r['date']) ?> <?= e(substr($r['time'], 0, 5)) ?></td>
                        <td><?= e($r['passenger']) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/bookings/detail.php?id=<?= (int) $r['id'] ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2"><span class="small text-muted"><?= $total ?> booking</span><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
