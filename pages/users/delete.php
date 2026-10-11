<?php
/** Hapus pengguna (POST saja). Tidak boleh hapus diri sendiri; Super Admin hanya oleh Super Admin. */
require_once __DIR__ . '/../../config/config.php';
require_permission('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/users/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
    flash('error', 'Anda tidak dapat menghapus akun sendiri.');
    redirect('pages/users/index.php');
}

$st = db()->prepare('SELECT u.id, u.username, u.company_id, r.name AS role_name
                     FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
$st->bind_param('i', $id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u) {
    flash('error', 'Pengguna tidak ditemukan.');
    redirect('pages/users/index.php');
}
assert_company_access($u['company_id'] !== null ? (int) $u['company_id'] : null);
if ($u['role_name'] === 'Super Admin' && !is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

// user_companies, user_permissions ikut terhapus (ON DELETE CASCADE).
$st = db()->prepare('DELETE FROM users WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$st->close();

audit('user.delete', "Pengguna #{$id} {$u['username']}");
flash('success', 'Pengguna berhasil dihapus.');
redirect('pages/users/index.php');
