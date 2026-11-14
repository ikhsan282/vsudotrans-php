<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('groups.manage');

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['code' => '', 'name' => '', 'note' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM groups WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Grup tidak ditemukan.');
        redirect('pages/groups/index.php');
    }
    $row = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code   = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $name   = trim((string) ($_POST['name'] ?? ''));
    $note   = trim((string) ($_POST['note'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $row    = array_merge($row, ['code' => $code, 'name' => $name, 'note' => $note, 'is_active' => $active]);

    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $code)) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($name === '' || mb_strlen($name) > 255) {
        $errors[] = 'Nama wajib diisi, maksimal 255 karakter.';
    }
    if (mb_strlen($note) > 2000) {
        $errors[] = 'Catatan maksimal 2000 karakter.';
    }

    if (!$errors) {
        $st = $db->prepare('SELECT id FROM groups WHERE code = ? AND id <> ?');
        $st->bind_param('si', $code, $id);
        $st->execute();
        $dup = $st->get_result()->num_rows > 0;
        $st->close();
        if ($dup) {
            $errors[] = 'Kode sudah dipakai grup lain.';
        }
    }

    if (!$errors) {
        $user      = $_SESSION['username'] ?? null;
        $note_v    = $note === '' ? null : $note;
        try {
            if ($id > 0) {
                $st = $db->prepare('UPDATE groups SET code = ?, name = ?, note = ?, is_active = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                $st->bind_param('sssisi', $code, $name, $note_v, $active, $user, $id);
                $st->execute();
                $st->close();
                audit('group.update', "Grup #{$id} {$code}");
                flash('success', 'Grup berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO groups (code, name, note, is_active, created_user) VALUES (?, ?, ?, ?, ?)');
                $st->bind_param('sssis', $code, $name, $note_v, $active, $user);
                $st->execute();
                $new_id = $db->insert_id;
                $st->close();
                audit('group.create', "Grup #{$new_id} {$code}");
                flash('success', 'Grup berhasil ditambahkan.');
            }
            redirect('pages/groups/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'Kode sudah dipakai grup lain.';
            } else {
                throw $ex;
            }
        }
    }
}

$active = 'groups';
$title  = $id > 0 ? ('Ubah Grup - ' . $row['name']) : 'Tambah Grup';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Grup Holding',
        'collection',
        $id > 0 ? (string) $row['name'] : 'Tambah Grup Baru',
        $id > 0 ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            isset($row['is_active']) ? ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'] : null,
        ])) : [['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border']],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'pages/groups/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
            ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formGroup', 'class' => 'btn btn-primary btn-sm px-3'],
        ]
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="groupFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-grp-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-grp-detail" type="button" role="tab">Detail</button>
            </li>
            <?php if ($id > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-grp-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-grp-audit" type="button" role="tab">Audit Trail</button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/groups/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formGroup">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="tab-content" id="groupFormTabContent">
                    <div class="tab-pane fade show active" id="tab-grp-detail" role="tabpanel" aria-labelledby="tab-grp-detail-btn">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header"><span>Informasi Grup Holding</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" for="code">Kode <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm text-uppercase font-monospace" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="name">Nama Grup <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1" for="note">Catatan</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm rnt-ta-compact" id="note" name="note" rows="3" maxlength="2000"><?= e($row['note']) ?></textarea>
                                    </div>
                                </div>
                                <div class="rnt-row mb-0">
                                    <label class="rnt-label" for="is_active">Status Aktif</label>
                                    <div class="rnt-field">
                                        <div class="rnt-check-wrap">
                                            <input class="form-check-input mt-0" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold" for="is_active">Grup Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($id > 0): ?>
                        <div class="tab-pane fade" id="tab-grp-audit" role="tabpanel" aria-labelledby="tab-grp-audit-btn">
                            <?= ui_erp_audit_panel($row) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
