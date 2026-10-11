<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('roles.manage');

$db   = db();
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$role = $id > 0 ? role_find($id) : null;

if (!$role) {
    flash('error', 'Role tidak ditemukan.');
    redirect('pages/roles/index.php');
}

// Super Admin selalu lolos can() (lihat is_super_admin()), jadi daftar izinnya tidak perlu diatur.
if (role_is_super($role)) {
    flash('error', 'Role Super Admin selalu memiliki semua hak akses dan tidak dapat diubah.');
    redirect('pages/roles/index.php');
}

// Daftar permission selalu dari tabel permissions — jangan hardcode.
$perms = $db->query('SELECT id, name, description, module FROM permissions ORDER BY module, name')->fetch_all(MYSQLI_ASSOC);
$grouped = [];
foreach ($perms as $p) {
    $grouped[$p['module']][] = $p;
}
// Permission khusus Super Admin hanya boleh diatur oleh Super Admin (ditegakkan di server, bukan cuma UI).
$restricted = ['companies.manage', 'groups.manage', 'roles.manage'];
$valid_ids = array_map('intval', array_column(
    array_filter($perms, fn ($p) => is_super_admin() || !in_array($p['name'], $restricted, true)),
    'id'
));

$st = $db->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
$st->bind_param('i', $id);
$st->execute();
$checked = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'permission_id'));
$st->close();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $posted = array_values(array_intersect($valid_ids, array_map('intval', (array) ($_POST['permissions'] ?? []))));
    // Checkbox restricted disabled untuk non-Super Admin tidak terkirim: pertahankan nilai lamanya agar tidak terhapus.
    $kept = array_values(array_filter($checked, fn ($pid) => !in_array($pid, $valid_ids, true)));
    $checked = array_values(array_unique(array_merge($posted, $kept)));

    try {
        $db->begin_transaction();
        $st = $db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();

        if ($checked) {
            $st = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($checked as $pid) {
                $st->bind_param('ii', $id, $pid);
                $st->execute();
            }
            $st->close();
        }
        $db->commit();

        audit('role.permissions', "Role #{$id} {$role['name']}: " . count($checked) . ' permission');

        // Sesi menyimpan daftar izin saat login; kalau role ini milik user yang sedang login, muat ulang.
        $own = ($role['name'] === ($_SESSION['role_name'] ?? ''));
        if ($own) {
            $_SESSION['permissions'] = load_permissions((int) $_SESSION['user_id'], $role['name']);
        }
        flash('success', 'Hak akses role ' . $role['name'] . ' tersimpan.'
            . ($own ? '' : ' Berlaku untuk penggunanya pada login berikutnya.'));
        redirect('pages/roles/permissions.php?id=' . $id);
    } catch (mysqli_sql_exception $ex) {
        $db->rollback();
        error_log('role.permissions gagal: ' . $ex->getMessage());
        $errors[] = 'Gagal menyimpan hak akses. Coba lagi.';
    }
}

$can_edit_restricted = is_super_admin();
$active = 'roles';
$title  = 'Hak Akses — ' . $role['name'];
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Hak Akses — <?= e($role['name']) ?></h4>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/roles/index.php">Kembali</a>
    </div>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(APP_URL) ?>/pages/roles/permissions.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php foreach ($grouped as $module => $items): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-capitalize"><?= e($module) ?></span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="all-<?= e($module) ?>"
                               onclick="document.querySelectorAll('.perm-<?= e($module) ?>').forEach(c => c.checked = this.checked)">
                        <label class="form-check-label small" for="all-<?= e($module) ?>">Pilih semua</label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                    <?php foreach ($items as $p): ?>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input perm-<?= e($p['module']) ?>" type="checkbox"
                                       name="permissions[]" value="<?= (int) $p['id'] ?>"
                                       id="perm-<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $checked, true) ? 'checked' : '' ?>
                                       <?= (!$can_edit_restricted && in_array($p['name'], $restricted, true)) ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="perm-<?= (int) $p['id'] ?>">
                                    <?= e($p['name']) ?>
                                    <?php if (in_array($p['name'], $restricted, true)): ?>
                                        <span class="badge bg-warning text-dark">khusus Super Admin</span>
                                    <?php endif; ?>
                                    <?php if ($p['description']): ?><div class="small text-muted"><?= e($p['description']) ?></div><?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">Simpan Hak Akses</button>
        <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/roles/index.php">Batal</a>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>