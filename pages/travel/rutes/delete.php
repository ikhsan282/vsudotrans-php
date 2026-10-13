<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/travel/rutes/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, company_id, code FROM rutes WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if (!$r) {
    flash('error', 'Rute tidak ditemukan.');
} else {
    assert_company_access((int) $r['company_id']);
    try {
        $st = db()->prepare('DELETE FROM rutes WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        audit('rute.delete', "Rute #{$id} {$r['code']}");
        flash('success', 'Rute berhasil dihapus.');
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', 'Rute tidak dapat dihapus: sudah dipakai data lain.');
        } else {
            throw $ex;
        }
    }
}
redirect('pages/travel/rutes/index.php');
