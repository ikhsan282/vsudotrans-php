<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('groups.manage');

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['code' => '', 'name' => '', 'note' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $db->prepare('SELECT code, name, note, is_active FROM groups WHERE id = ?');
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
    $row    = ['code' => $code, 'name' => $name, 'note' => $note, 'is_active' => $active];

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
$title  = $id > 0 ? 'Ubah Grup' : 'Tambah Grup';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 640px;">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/groups/form.php<?= $id > 0 ? '?id=' . $id : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="mb-3">
                    <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                    <input class="form-control text-uppercase" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="name">Nama <span class="text-danger">*</span></label>
                    <input class="form-control" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="note">Catatan</label>
                    <textarea class="form-control" id="note" name="note" rows="3" maxlength="2000"><?= e($row['note']) ?></textarea>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Aktif</label>
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/groups/index.php">Batal</a>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
