<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('groups.view');

$total = (int) db()->query('SELECT COUNT(*) FROM groups')->fetch_row()[0];
$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare('SELECT id, code, name, is_active FROM groups ORDER BY code LIMIT ? OFFSET ?');
$st->bind_param('ii', $p['per_page'], $p['offset']);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'groups';
$title  = 'Grup Perusahaan';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Grup Perusahaan</h4>
        <?php if (can('groups.manage')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/groups/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Kode</th><th>Nama</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <?php if (can('groups.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/groups/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/groups/delete.php" class="d-inline" onsubmit="return confirm('Hapus grup ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
