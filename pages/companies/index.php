<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.view');

[$where, $types, $params] = company_scope('c.id');

$st = db()->prepare("SELECT COUNT(*) FROM companies c WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT c.id, c.code, c.name, c.city, c.is_active, g.name AS group_name
                     FROM companies c JOIN groups g ON g.id = c.group_id
                     WHERE {$where} ORDER BY c.name LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'companies';
$title  = 'Perusahaan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Perusahaan</h4>
        <?php if (is_super_admin() && can('companies.manage')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/companies/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Kode</th><th>Nama</th><th>Grup</th><th>Kota</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?></td>
                        <td><a href="<?= e(APP_URL) ?>/pages/companies/detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                        <td><?= e($r['group_name']) ?></td>
                        <td><?= e($r['city']) ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <?php if (can('companies.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/companies/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <?php if (is_super_admin()): ?>
                                    <form method="post" action="<?= e(APP_URL) ?>/pages/companies/delete.php" class="d-inline" onsubmit="return confirm('Hapus perusahaan ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
