<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/companies/index.php');
}
verify_csrf();

// Hapus perusahaan: hanya Super Admin.
if (!is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, code FROM companies WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$c = $st->get_result()->fetch_assoc();
$st->close();

if (!$c) {
    flash('error', 'Perusahaan tidak ditemukan.');
} else {
    assert_company_access((int) $c['id']);
    try {
        $st = db()->prepare('DELETE FROM companies WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        audit('company.delete', "Perusahaan #{$id} {$c['code']}");
        flash('success', 'Perusahaan berhasil dihapus.');
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', 'Perusahaan tidak dapat dihapus: masih memiliki cabang atau karyawan.');
        } else {
            throw $ex;
        }
    }
}
redirect('pages/companies/index.php');
