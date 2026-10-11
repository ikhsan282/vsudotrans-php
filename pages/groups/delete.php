<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('groups.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/groups/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT code FROM groups WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$found = $st->get_result()->fetch_assoc();
$st->close();

if (!$found) {
    flash('error', 'Grup tidak ditemukan.');
} else {
    try {
        $st = db()->prepare('DELETE FROM groups WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        audit('group.delete', "Grup #{$id} {$found['code']}");
        flash('success', 'Grup berhasil dihapus.');
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', 'Grup tidak dapat dihapus: masih memiliki perusahaan.');
        } else {
            throw $ex;
        }
    }
}
redirect('pages/groups/index.php');
