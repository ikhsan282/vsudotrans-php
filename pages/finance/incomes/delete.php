<?php
/** Hapus transaksi pemasukan manual (POST + CSRF + tenant scoped). */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/finance/incomes/index.php');
}
verify_csrf();

$id  = (int) ($_POST['id'] ?? 0);
$row = cash_tx_find($id);
if (!$row) {
    flash('error', 'Data pemasukan tidak ditemukan.');
} elseif (($row['direction'] ?? '') !== 'in') {
    flash('error', 'Transaksi pengeluaran tidak dapat dihapus melalui menu Pemasukan.');
} elseif (!empty($row['source_type'])) {
    flash('error', 'Transaksi sinkronisasi otomatis tidak dapat dihapus manual.');
} else {
    $cid = (int) $row['company_id'];
    $st  = db()->prepare("DELETE FROM cash_transactions WHERE id = ? AND company_id = ? AND direction = 'in' AND source_type IS NULL");
    $st->bind_param('ii', $id, $cid);
    $st->execute();
    $deleted = $st->affected_rows;
    $st->close();
    if ($deleted > 0) {
        audit('kas.income.delete', 'Hapus pemasukan #' . $id . ' ' . ($row['date'] ?? '') . ' [' . ($row['category'] ?? '') . '] ' . rupiah((float) $row['amount']));
        flash('success', 'Data pemasukan berhasil dihapus.');
    } else {
        flash('error', 'Data pemasukan tidak ditemukan.');
    }
}
redirect('pages/finance/incomes/index.php');
