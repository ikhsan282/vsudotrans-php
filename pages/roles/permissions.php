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
// Non-Super hanya boleh mengatur hak akses role di bawah levelnya (cegah eskalasi diri).
assert_role_manageable($role);

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
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Role & Hak Akses',
        'shield-check',
        'Hak Akses — ' . $role['name'],
        ['Level ' . (int) ($role['level'] ?? 0)],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/pages/roles/index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan Hak Akses', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formPermissions', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(APP_URL) ?>/pages/roles/permissions.php?id=<?= $id ?>" id="formPermissions">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-perm-list" data-bs-toggle="tab" data-bs-target="#pane-perm-list" type="button" role="tab">Hak Akses Modul</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-perm-audit" data-bs-toggle="tab" data-bs-target="#pane-perm-audit" type="button" role="tab">Audit Trail</button>
                </li>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-perm-list" role="tabpanel">
                    <div class="row g-3">
                        <?php foreach ($grouped as $module => $items): ?>
                            <div class="col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header justify-content-between">
                                        <span class="d-inline-flex align-items-center gap-1 text-capitalize"><i class="bi bi-grid"></i> <?= e($module) ?></span>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" id="all-<?= e($module) ?>"
                                                   onclick="document.querySelectorAll('.perm-<?= e($module) ?>').forEach(c => c.checked = this.checked)">
                                            <label class="form-check-label small fw-normal text-transform-none" for="all-<?= e($module) ?>">Pilih semua</label>
                                        </div>
                                    </div>
                                    <div class="rnt-panel-body p-3">
                                        <div class="row g-2">
                                            <?php foreach ($items as $p): ?>
                                                <div class="col-md-6">
                                                    <div class="form-check mb-0">
                                                        <input class="form-check-input perm-<?= e($p['module']) ?>" type="checkbox"
                                                               name="permissions[]" value="<?= (int) $p['id'] ?>"
                                                               id="perm-<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $checked, true) ? 'checked' : '' ?>
                                                               <?= (!$can_edit_restricted && in_array($p['name'], $restricted, true)) ? 'disabled' : '' ?>>
                                                        <label class="form-check-label small" for="perm-<?= (int) $p['id'] ?>">
                                                            <span class="fw-medium"><?= e($p['name']) ?></span>
                                                            <?php if (in_array($p['name'], $restricted, true)): ?>
                                                                <span class="badge bg-warning text-dark">khusus Super Admin</span>
                                                            <?php endif; ?>
                                                            <?php if ($p['description']): ?><div class="text-muted"><?= e($p['description']) ?></div><?php endif; ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tab-pane fade" id="pane-perm-audit" role="tabpanel">
                    <?= ui_erp_audit_panel($role) ?>
                </div>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>