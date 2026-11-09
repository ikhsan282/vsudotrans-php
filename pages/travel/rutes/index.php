<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

[$where, $types, $params] = company_scope('r.company_id');
$st = db()->prepare("SELECT COUNT(*) FROM rutes r WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT r.id, r.code, r.name, r.price, r.is_active, pc.name AS pickup, dc.name AS delivery
                     FROM rutes r
                     JOIN cities pc ON pc.id = r.pickup_point_city_id
                     JOIN cities dc ON dc.id = r.delivery_point_city_id
                     WHERE {$where} ORDER BY r.code LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'travel';
$title  = 'Rute';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Rute Travel',
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Rute' => null],
        [],
        can('travel.manage') && active_company_id() !== null ? [
            ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/rutes/form.php', 'class' => 'btn btn-primary btn-sm'],
        ] : []
    ) ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Kode</th><th>Nama</th><th>Asal</th><th>Tujuan</th><th class="text-end">Harga</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e($r['pickup']) ?></td>
                        <td><?= e($r['delivery']) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['price'])) ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <?php if (can('travel.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/rutes/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/travel/rutes/delete.php" class="d-inline" onsubmit="return confirm('Hapus rute ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
