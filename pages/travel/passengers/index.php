<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

[$where, $types, $params] = company_scope('p.company_id');
$st = db()->prepare("SELECT COUNT(*) FROM passengers p WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT p.id, p.code, p.name, p.country_code, p.phone_number, p.email
                     FROM passengers p WHERE {$where} ORDER BY p.name LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'travel';
$title  = 'Penumpang';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Penumpang</h4>
        <?php if (can('travel.manage') && active_company_id() !== null): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/passengers/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Kode</th><th>Nama</th><th>Telepon</th><th>Email</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e(trim($r['country_code'] . ' ' . (string) $r['phone_number'])) ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td class="text-end">
                            <?php if (can('travel.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/passengers/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/travel/passengers/delete.php" class="d-inline" onsubmit="return confirm('Hapus penumpang ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
