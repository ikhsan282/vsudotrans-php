<?php
/** Hapus transaksi kas (POST + CSRF). Baris perusahaan lain → 403 oleh cash_tx_find(). */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/finance/cash/index.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$row = cash_tx_find($id);
if (!$row) {
    flash('error', 'Transaksi tidak ditemukan.');
} else {
    $st = db()->prepare('DELETE FROM cash_transactions WHERE id = ? AND company_id = ?');
    $cid = (int) active_company_id();
    $st->bind_param('ii', $id, $cid);
    $st->execute();
    $deleted = $st->affected_rows;
    $st->close();
    if ($deleted > 0) {
        audit('kas.delete', 'Transaksi kas #' . $id . ' ' . $row['date'] . ' '
            . $row['direction'] . ' ' . rupiah((float) $row['amount']));
        flash('success', 'Transaksi berhasil dihapus.');
    } else {
        flash('error', 'Transaksi tidak ditemukan.');
    }
}
redirect('pages/finance/cash/index.php');
