<?php
/** Hapus transaksi pengeluaran (POST + CSRF + tenant scoped). */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/finance/expenses/index.php');
}
verify_csrf();

$id  = (int) ($_POST['id'] ?? 0);
$row = cash_tx_find($id);
if (!$row) {
    flash('error', 'Data pengeluaran tidak ditemukan.');
} elseif (($row['direction'] ?? '') !== 'out') {
    flash('error', 'Transaksi pemasukan tidak dapat dihapus melalui menu Pengeluaran.');
} elseif (($row['source_type'] ?? '') === 'crew_commission') {
    flash('error', 'Pencairan komisi kru hanya dapat dibatalkan melalui menu Komisi Sopir & Kru.');
} elseif (!empty($row['source_type'])) {
    flash('error', 'Transaksi sinkronisasi otomatis tidak dapat dihapus manual.');
} else {
    $cid = (int) $row['company_id'];
    $st  = db()->prepare("DELETE FROM cash_transactions WHERE id = ? AND company_id = ? AND direction = 'out'");
    $st->bind_param('ii', $id, $cid);
    $st->execute();
    $deleted = $st->affected_rows;
    $st->close();
    if ($deleted > 0) {
        if (!empty($row['proof_blob_path'])) {
            azure_blob_delete((string) $row['proof_blob_path']);
        }
        audit('kas.expense.delete', 'Hapus pengeluaran #' . $id . ' ' . ($row['date'] ?? '') . ' [' . ($row['category'] ?? '') . '] ' . rupiah((float) $row['amount']));
        flash('success', 'Data pengeluaran berhasil dihapus.');
    } else {
        flash('error', 'Data pengeluaran tidak ditemukan.');
    }
}
redirect('pages/finance/expenses/index.php');
