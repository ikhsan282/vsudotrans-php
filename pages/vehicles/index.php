<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('vehicles.view');

$today = new DateTimeImmutable('today');
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
$like = '%' . addcslashes($q, '%_\\') . '%';

[$where, $types, $params] = company_scope('v.company_id');
$where .= ' AND (v.vehicle_number LIKE ? OR v.frame_number LIKE ?)';
$types .= 'ss';
$params = [...$params, $like, $like];

$st = db()->prepare("SELECT COUNT(*) FROM vehicles v WHERE {$where}");
$st->bind_param($types, ...$params);
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT v.id, v.vehicle_number, v.vehicle_color, v.production_year, v.ownership, v.is_active,
                            v.tax_due, v.stnk_due, v.kir_due,
                            b.name AS brand_name, m.name AS model_name, c.name AS category_name
                     FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     JOIN category_vehicles c ON c.id = v.category_vehicle_id
                     WHERE {$where} ORDER BY v.vehicle_number LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$dueLabels = ['tax_due' => 'Pajak', 'stnk_due' => 'STNK', 'kir_due' => 'KIR'];

$active = 'vehicles';
$title  = 'Kendaraan';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Kendaraan</h4>
        <div class="d-flex gap-2">
            <?php if (can('vehicles.manage')): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/vehicles/master.php">Master</a>
                <?php if (active_company_id() !== null): ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/vehicles/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <form class="row g-2 mb-3" method="get">
        <div class="col-auto"><input class="form-control form-control-sm" name="q" placeholder="Cari nomor polisi / rangka" value="<?= e($q) ?>"></div>
        <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Cari</button></div>
    </form>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>No. Polisi</th><th>Merek / Model</th><th>Kategori</th><th>Warna</th><th>Tahun</th><th>Kepemilikan</th><th>Jatuh Tempo</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['vehicle_number']) ?></td>
                        <td><?= e($r['brand_name'] . ' ' . $r['model_name']) ?></td>
                        <td><?= e($r['category_name']) ?></td>
                        <td><?= e($r['vehicle_color']) ?></td>
                        <td><?= (int) $r['production_year'] ?></td>
                        <td><?= e($r['ownership']) ?></td>
                        <td>
                            <?php foreach ($dueLabels as $col => $label): ?>
                                <?php if (vehicle_due_soon($r[$col], $today)): ?>
                                    <span class="badge bg-warning text-dark" title="<?= e($r[$col]) ?>">⚠ <?= e($label) ?> <?= e($r[$col]) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/vehicles/form.php?id=<?= (int) $r['id'] ?>">Detail</a>
                            <?php if (can('vehicles.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/vehicles/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/delete.php" class="d-inline" onsubmit="return confirm('Hapus kendaraan ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, 'q=' . urlencode($q)) ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
