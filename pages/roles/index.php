<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('roles.manage');

$rows = db()->query('SELECT r.id, r.name, r.description, r.level,
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
        (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
    FROM roles r ORDER BY r.level, r.name')->fetch_all(MYSQLI_ASSOC);

$active = 'roles';
$title  = 'Role & Hak Akses';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Role &amp; Hak Akses</h4>
        <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/roles/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Nama</th><th>Deskripsi</th><th>Level</th><th>Permission</th><th>Pengguna</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $is_super = $r['name'] === ROLE_SUPER_ADMIN; ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['name']) ?><?= $is_super ? ' <span class="badge bg-dark">dikunci</span>' : '' ?></td>
                        <td><?= e($r['description'] ?? '') ?></td>
                        <td><?= (int) $r['level'] ?></td>
                        <td><?= (int) $r['perm_count'] ?></td>
                        <td><?= (int) $r['user_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (!$is_super): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/roles/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/roles/permissions.php?id=<?= (int) $r['id'] ?>">Hak Akses</a>
                            <?php if (!$is_super): ?>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/roles/delete.php" class="d-inline"
                                      onsubmit="return confirm('Hapus role ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
