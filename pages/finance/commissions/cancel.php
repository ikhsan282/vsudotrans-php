<?php
/**
 * Aksi POST pembatalan pencairan komisi sopir & kru.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/_query.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/finance/commissions/index.php');
}
verify_csrf();

$returnArr = [];
if (!empty($_POST['return_qs'])) {
    parse_str((string) $_POST['return_qs'], $returnArr);
}
$backFilter = comm_filter($returnArr ?: $_GET);
if (is_super_admin() && isset($returnArr['company_id'])) {
    $backFilter['company_id'] = max(0, (int) $returnArr['company_id']);
}
$backQs  = comm_build_qs($backFilter);
$backUrl = 'pages/finance/commissions/index.php' . ($backQs !== '' ? '?' . $backQs : '');

$cashTxId = (int) ($_POST['cash_tx_id'] ?? 0);
$txRow    = cash_tx_find($cashTxId);
if (!$txRow || ($txRow['source_type'] ?? '') !== 'crew_commission') {
    flash('error', 'Transaksi pencairan komisi tidak ditemukan.');
    redirect($backUrl);
}

$cid = (int) $txRow['company_id'];
try {
    $res = comm_cancel_payment($cid, $cashTxId, $_SESSION['username'] ?? null);
    audit(
        'kas.commission.cancel',
        'Batalkan pencairan komisi #' . $cashTxId . ' (' . $res['reset_lines'] . ' baris dikembalikan) ' . rupiah($res['amount'])
    );
    flash(
        'success',
        'Pencairan komisi #' . $cashTxId . ' (' . rupiah($res['amount']) . ') dibatalkan; ' . $res['reset_lines'] . ' baris kembali berstatus Belum Dibayar.'
    );
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

redirect($backUrl);
