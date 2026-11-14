<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('roles.manage');

$db  = db();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['name' => '', 'description' => '', 'level' => 0];
$is_super = false;

if ($id > 0) {
    $found = role_find($id);
    if (!$found) {
        flash('error', 'Role tidak ditemukan.');
        redirect('pages/roles/index.php');
    }
    $row = $found;
    $is_super = role_is_super($row);
    assert_role_manageable($row); // non-Super hanya boleh menyentuh role di bawah levelnya
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $row = array_merge($row, [
        'name'        => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 50),
        'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255),
        'level'       => (int) ($_POST['level'] ?? 0),
    ]);

    // Nama Super Admin tidak boleh diubah: is_super_admin() membandingkan nama role.
    if ($is_super) {
        $row['name'] = ROLE_SUPER_ADMIN;
    }

    if ($row['name'] === '') {
        $errors[] = 'Nama role wajib diisi.';
    }
    if ($row['level'] < 0 || $row['level'] > 999) {
        $errors[] = 'Level wajib angka 0–999.';
    }
    // Non-Super tidak boleh menetapkan level setinggi/setiinggi dirinya (cegah eskalasi).
    if (!is_super_admin() && $row['level'] <= actor_role_level()) {
        $errors[] = 'Level harus lebih rendah dari level role Anda.';
    }

    if (!$errors) {
        $st = $db->prepare('SELECT id FROM roles WHERE name = ? AND id <> ?');
        $st->bind_param('si', $row['name'], $id);
        $st->execute();
        $dup = $st->get_result()->num_rows > 0;
        $st->close();
        if ($dup) {
            $errors[] = 'Nama role sudah dipakai.';
        }
    }

    if (!$errors) {
        $desc = $row['description'] === '' ? null : $row['description'];
        try {
            if ($id > 0) {
                $st = $db->prepare('UPDATE roles SET name = ?, description = ?, level = ? WHERE id = ?');
                $st->bind_param('ssii', $row['name'], $desc, $row['level'], $id);
                $st->execute();
                $st->close();
                audit('role.update', "Role #{$id} {$row['name']}");
                flash('success', 'Role berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO roles (name, description, level) VALUES (?, ?, ?)');
                $st->bind_param('ssi', $row['name'], $desc, $row['level']);
                $st->execute();
                $new_id = $db->insert_id;
                $st->close();
                audit('role.create', "Role #{$new_id} {$row['name']}");
                flash('success', 'Role berhasil ditambahkan. Atur hak aksesnya sekarang.');
                redirect('pages/roles/permissions.php?id=' . $new_id);
            }
            redirect('pages/roles/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'Nama role sudah dipakai.';
            } else {
                throw $ex;
            }
        }
    }
}

$active = 'roles';
$title  = $id > 0 ? 'Ubah Role' : 'Tambah Role';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Role & Hak Akses',
        'shield-lock',
        $title,
        $is_super ? [['text' => 'Super Admin', 'class' => 'bg-warning-subtle text-warning']] : ($id > 0 ? ['ID #' . $id] : []),
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/pages/roles/index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formRole', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(APP_URL) ?>/pages/roles/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formRole">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-role-detail" data-bs-toggle="tab" data-bs-target="#pane-role-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-role-audit" data-bs-toggle="tab" data-bs-target="#pane-role-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-role-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="rnt-panel mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-shield"></i> Informasi Role</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row<?= $is_super ? ' rnt-row-top' : '' ?>">
                                        <div class="rnt-label">Nama Role <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="name" name="name" maxlength="50" required
                                                   value="<?= e($row['name']) ?>" <?= $is_super ? 'readonly' : '' ?>>
                                            <?php if ($is_super): ?>
                                                <div class="form-text small">Nama role Super Admin dikunci.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Level <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" type="number" id="level" name="level" min="0" max="999" required value="<?= (int) $row['level'] ?>">
                                            <div class="form-text small">Semakin kecil angkanya, semakin tinggi posisinya.</div>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Deskripsi</div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="description" name="description" maxlength="255" value="<?= e($row['description'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-role-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>