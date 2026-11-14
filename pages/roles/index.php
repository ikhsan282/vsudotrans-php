<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('roles.manage');

$rows = db()->query('SELECT r.id, r.name, r.description, r.level,
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
        (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
    FROM roles r ORDER BY r.level, r.name')->fetch_all(MYSQLI_ASSOC);

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $export_rows = [];
    foreach ($rows as $r) {
        $export_rows[] = [
            $r['name'],
            $r['description'] ?: '-',
            $r['level'],
            $r['perm_count'],
            $r['user_count'],
        ];
    }
    grid_export_send(
        'Daftar Role dan Hak Akses',
        ['Nama', 'Deskripsi', 'Level', 'Permission', 'Pengguna'],
        $export_rows,
        $export_format,
        'portrait'
    );
}

$header_actions = ui_export_actions();
$header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/roles/form.php', 'class' => 'btn btn-primary btn-sm'];

$active = 'roles';
$title  = 'Role & Hak Akses';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Role & Hak Akses',
        ['Dashboard' => 'pages/dashboard/index.php', 'Role' => null],
        [['text' => count($rows) . ' Role', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">#</th><th>Nama</th><th>Deskripsi</th><th>Level</th><th>Permission</th><th>Pengguna</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <?php $is_super = $r['name'] === ROLE_SUPER_ADMIN; ?>
                    <tr>
                        <td class="text-center text-muted small"><?= $idx + 1 ?></td>
                        <td class="fw-semibold"><?= e($r['name']) ?><?= $is_super ? ' <span class="badge bg-dark">dikunci</span>' : '' ?></td>
                        <td><?= e($r['description'] ?? '') ?></td>
                        <td><?= (int) $r['level'] ?></td>
                        <td><?= (int) $r['perm_count'] ?></td>
                        <td><?= (int) $r['user_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (!$is_super): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/roles/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/roles/permissions.php?id=<?= (int) $r['id'] ?>" title="Hak Akses" aria-label="Hak Akses"><i class="bi bi-shield-lock"></i></a>
                            <?php if (!$is_super): ?>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/roles/delete.php" class="d-inline"
                                      onsubmit="return confirm('Hapus role ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
