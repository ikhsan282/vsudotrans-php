<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('branches.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/branches/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, company_id, code FROM branches WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$b = $st->get_result()->fetch_assoc();
$st->close();

if (!$b) {
    flash('error', 'Cabang tidak ditemukan.');
} else {
    assert_company_access((int) $b['company_id']);
    try {
        $st = db()->prepare('DELETE FROM branches WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        audit('branch.delete', "Cabang #{$id} {$b['code']}");
        flash('success', 'Cabang berhasil dihapus.');
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', 'Cabang tidak dapat dihapus: masih memiliki karyawan.');
        } else {
            throw $ex;
        }
    }
}
redirect('pages/branches/index.php');
