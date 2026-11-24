<?php
/**
 * Aksi POST pembayaran / pencairan komisi sopir & kru terpilih.
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

$activeCid = active_company_id();
$cid = $activeCid !== null ? (int) $activeCid : max(0, (int) ($_POST['company_id'] ?? 0));
if ($cid <= 0) {
    flash('error', 'Pilih satu perusahaan terlebih dahulu untuk mencairkan komisi kru.');
    redirect($backUrl);
}
assert_company_access($cid);

$lineIds = (array) ($_POST['line_ids'] ?? []);
if (!$lineIds) {
    flash('error', 'Pilih minimal 1 baris komisi yang belum dibayar.');
    redirect($backUrl);
}

$proofRes = cash_handle_proof_upload($cid, 'commissions', null, false);
if (!empty($proofRes['error'])) {
    flash('error', $proofRes['error']);
    redirect($backUrl);
}

try {
    $res = comm_pay_lines($cid, $lineIds, [
        'pay_date'          => trim((string) ($_POST['pay_date'] ?? date('Y-m-d'))),
        'payment_method_id' => trim((string) ($_POST['payment_method_id'] ?? '')),
        'note'              => trim((string) ($_POST['note'] ?? '')),
        'proof_blob_path'   => $proofRes['path'] ?? null,
    ], $_SESSION['username'] ?? null);

    audit(
        'kas.commission.pay',
        'Pencairan komisi #' . $res['cash_tx_id'] . ' (' . $res['paid_count'] . ' baris: ' . ($res['crew_preview'] ?: '-') . ') ' . rupiah($res['total_amount'])
    );
    flash(
        'success',
        'Berhasil mencairkan ' . $res['paid_count'] . ' baris komisi (' . rupiah($res['total_amount']) . ') dan dicatat ke Kas Keluar (#' . $res['cash_tx_id'] . ').'
    );
} catch (Throwable $e) {
    if (!empty($proofRes['path'])) {
        azure_blob_delete((string) $proofRes['path']);
    }
    flash('error', $e->getMessage());
}

redirect($backUrl);
