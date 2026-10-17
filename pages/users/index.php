<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('users.view');

[$where, $types, $params] = company_scope('u.company_id');

$st = db()->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT u.id, u.username, u.full_name, u.email, u.phone_number, u.is_active,
                            r.name AS role_name, c.name AS company_name
                     FROM users u
                     JOIN roles r ON r.id = u.role_id
                     LEFT JOIN companies c ON c.id = u.company_id
                     WHERE {$where} ORDER BY u.username LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'users';
$title  = 'Pengguna';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Pengguna</h4>
        <?php if (can('users.manage')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/users/form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
        <?php endif; ?>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Username</th><th>Nama</th><th>Email</th><th>Role</th><th>Perusahaan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['username']) ?></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td><span class="badge bg-secondary"><?= e($r['role_name']) ?></span></td>
                        <td><?= e($r['company_name'] ?? '— lintas perusahaan —') ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <?php $is_super_row = $r['role_name'] === 'Super Admin'; ?>
                            <?php if (can('users.manage') && (is_super_admin() || !$is_super_row)): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/users/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <?php if (is_super_admin()): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/users/companies.php?id=<?= (int) $r['id'] ?>">Akses</a>
                                <?php endif; ?>
                                <?php if ((int) $r['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                    <form method="post" action="<?= e(APP_URL) ?>/pages/users/delete.php" class="d-inline" onsubmit="return confirm('Hapus pengguna ini?')">
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
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
