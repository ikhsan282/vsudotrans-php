<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('roles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/roles/index.php');
}
verify_csrf();

$id   = (int) ($_POST['id'] ?? 0);
$role = $id > 0 ? role_find($id) : null;

if (!$role) {
    flash('error', 'Role tidak ditemukan.');
    redirect('pages/roles/index.php');
}

if (role_is_super($role)) {
    flash('error', 'Role Super Admin tidak dapat dihapus.');
    redirect('pages/roles/index.php');
}

$st = db()->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
$st->bind_param('i', $id);
$st->execute();
$used = (int) $st->get_result()->fetch_row()[0];
$st->close();

if ($used > 0) {
    flash('error', "Role tidak dapat dihapus: masih dipakai {$used} pengguna. Pindahkan pengguna tersebut ke role lain dulu.");
    redirect('pages/roles/index.php');
}

try {
    $st = db()->prepare('DELETE FROM roles WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
} catch (mysqli_sql_exception $ex) {
    if ((int) $ex->getCode() === 1451) { // FK users.role_id: ada user baru yang memakai role ini
        flash('error', 'Role tidak dapat dihapus: masih dipakai pengguna.');
        redirect('pages/roles/index.php');
    }
    throw $ex;
}

audit('role.delete', "Role #{$id} {$role['name']}");
flash('success', 'Role berhasil dihapus.');
redirect('pages/roles/index.php');