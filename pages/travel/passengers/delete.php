<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/travel/passengers/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, company_id, code FROM passengers WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if (!$r) {
    flash('error', 'Penumpang tidak ditemukan.');
} else {
    assert_company_access((int) $r['company_id']);
    try {
        $st = db()->prepare('DELETE FROM passengers WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        audit('penumpang.delete', "Penumpang #{$id} {$r['code']}");
        flash('success', 'Penumpang berhasil dihapus.');
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', 'Penumpang tidak dapat dihapus: sudah dipakai booking.');
        } else {
            throw $ex;
        }
    }
}
redirect('pages/travel/passengers/index.php');
