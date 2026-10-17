<?php
/**
 * Hapus akun kas/bank (POST + CSRF).
 * Akun yang masih punya transaksi tidak dihapus — buku kas tidak boleh kehilangan
 * riwayat tanpa jejak; nonaktifkan saja akun itu. Tabel tanpa FK, jadi cek manual.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/finance/cash_accounts/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$row = cash_account_find($id); // 403 bila akun milik perusahaan lain
if (!$row) {
    flash('error', 'Akun kas/bank tidak ditemukan.');
    redirect('pages/finance/cash_accounts/index.php');
}

$st = db()->prepare('SELECT COUNT(*) FROM cash_transactions WHERE cash_account_id = ? AND company_id = ?');
$cid = (int) active_company_id();
$st->bind_param('ii', $id, $cid);
$st->execute();
$used = (int) $st->get_result()->fetch_row()[0];
$st->close();

if ($used > 0) {
    flash('error', 'Akun tidak dapat dihapus: masih dipakai oleh ' . $used . ' transaksi kas. Nonaktifkan saja akun ini.');
} else {
    $st = db()->prepare('DELETE FROM cash_accounts WHERE id = ? AND company_id = ?');
    $st->bind_param('ii', $id, $cid);
    $st->execute();
    $deleted = $st->affected_rows;
    $st->close();
    if ($deleted > 0) {
        audit('kas_account.delete', 'Akun kas/bank #' . $id . ' ' . $row['code']);
        flash('success', 'Akun kas/bank berhasil dihapus.');
    } else {
        flash('error', 'Akun kas/bank tidak ditemukan.');
    }
}
redirect('pages/finance/cash_accounts/index.php');
