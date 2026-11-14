<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('invoices.view');
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/booking.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cid = require_active_company();
$inv = invoice_find($id);
if (!$inv) {
    flash('error', 'Invoice tidak ditemukan.');
    redirect('pages/finance/invoices/index.php');
}
assert_company_access((int) $inv['company_id']);

$user = $_SESSION['username'] ?? null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_permission('invoices.manage');
    $act = (string) ($_POST['act'] ?? '');
    try {
        if ($act === 'pay') {
            $amount = parse_rupiah_input($_POST['amount'] ?? 0);
            $method = (int) ($_POST['payment_method_id'] ?? 0);
            $note   = trim((string) ($_POST['note'] ?? ''));
            $proof_path = null;
            if (!empty($_FILES['proof_file']['tmp_name'])) {
                $proof_path = azure_blob_handle_upload(
                    'proof_file',
                    (int) $inv['company_id'],
                    'finance/payment-proofs',
                    date('Y/m'),
                    'inv-' . $id,
                    true,
                    5
                );
            }
            $cash_account_id = (int) ($_POST['cash_account_id'] ?? 0);
            $res = invoice_add_payment(
                $id,
                (int) $inv['company_id'],
                $amount,
                $method > 0 ? $method : null,
                $note === '' ? null : $note,
                $user,
                false,
                $proof_path,
                $cash_account_id > 0 ? $cash_account_id : null
            );
            audit('invoice.pay', "Invoice #{$id} " . rupiah($amount) . " -> {$res['status']}");
            flash('success', 'Pembayaran ' . rupiah($amount) . ' dicatat' . ($cash_account_id > 0 ? ' & kas disinkronkan' : '') . '. Status: ' . $res['status'] . '.');
        } elseif ($act === 'cancel') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $db = db();
            $st = $db->prepare("UPDATE invoices SET status='cancelled', note = ?, modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?");
            $st->bind_param('ssii', $reason, $user, $id, $inv['company_id']);
            $st->execute();
            $st->close();
            audit('invoice.cancel', "Invoice #{$id}");
            flash('success', 'Invoice dibatalkan.');
        }
        redirect('pages/finance/invoices/detail.php?id=' . $id);
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
    $inv = invoice_find($id); // segarkan
}

$payments = invoice_payments($id);
$methods = [];
$mst = db()->prepare('SELECT id, name, account_name, account_number FROM payment_methods
    WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL) ORDER BY sort_order, name');
$mst->bind_param('i', $inv['company_id']);
$mst->execute();
$methods = $mst->get_result()->fetch_all(MYSQLI_ASSOC);
$mst->close();

$cash_accounts = [];
$cst = db()->prepare('SELECT id, name, account_number FROM cash_accounts WHERE is_active = 1 AND company_id = ? ORDER BY name');
$cst->bind_param('i', $inv['company_id']);
$cst->execute();
$cash_accounts = $cst->get_result()->fetch_all(MYSQLI_ASSOC);
$cst->close();

$remaining = max(0, round((float) $inv['total'] - (float) $inv['paid_total'], 2));
$qrisChannels = $remaining > 0 ? gateway_active_channels((int) $inv['company_id']) : [];
$link = $inv['booking_type'] === 'rental' ? 'pages/rental/bookings/detail.php' : 'pages/travel/bookings/detail.php';

$active = 'finance';
$active = 'finance';
$title  = 'Detail Invoice - ' . $inv['invoice_no'];
require __DIR__ . '/../../../includes/header.php';

$invActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/finance/invoices/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ['label' => 'Lihat Booking', 'icon' => 'box-arrow-up-right', 'href' => $link . '?id=' . (int) $inv['booking_id'], 'class' => 'btn btn-outline-secondary btn-sm'],
];
foreach ($qrisChannels as $qch) {
    $invActions[] = [
        'label' => 'Bayar ' . $qch['name'],
        'icon'  => 'qr-code-scan',
        'href'  => 'pages/finance/invoices/gateway_pay.php?invoice_id=' . $id . '&provider=' . urlencode((string) $qch['provider']),
        'class' => 'btn btn-primary btn-sm',
    ];
}
$invActions[] = ['label' => 'Cetak Invoice', 'icon' => 'printer', 'href' => 'pages/finance/invoices/print.php?id=' . $id . '&print=1', 'target' => '_blank', 'class' => 'btn btn-dark btn-sm'];
$invActions[] = ['label' => 'PDF', 'icon' => 'filetype-pdf', 'href' => 'pages/finance/invoices/print.php?id=' . $id . '&format=pdf', 'class' => 'btn btn-danger btn-sm'];
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Invoice',
        'receipt',
        (string) $inv['invoice_no'],
        [
            ['html' => invoice_pay_badge($inv['pay_status'])],
            ['text' => (string) $inv['document_number'], 'class' => 'bg-light text-secondary border font-monospace'],
        ],
        $invActions
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="invoiceDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-inv-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-inv-detail" type="button" role="tab">
                    Detail &amp; Pembayaran<?php if ($payments): ?> <span class="badge bg-secondary-subtle text-dark border ms-1"><?= count($payments) ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-inv-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-inv-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="invoiceDetailTabContent">
                <div class="tab-pane fade show active" id="tab-inv-detail" role="tabpanel" aria-labelledby="tab-inv-detail-btn">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-lg-7">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Informasi Invoice</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('No. Invoice', (string) $inv['invoice_no'], false, true) ?>
                                    <?= ui_erp_ro_row('Resi Booking', (string) $inv['document_number'], false, true) ?>
                                    <?= ui_erp_ro_row('Tipe Booking', ucfirst((string) $inv['booking_type'])) ?>
                                    <?= ui_erp_ro_row('Tanggal Terbit', (string) $inv['issued_at']) ?>
                                    <?= ui_erp_ro_row('Status Invoice', ucfirst((string) $inv['status'])) ?>
                                    <?php if (!empty($inv['note'])): ?>
                                        <?= ui_erp_ro_row('Catatan', (string) $inv['note'], true) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-5">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Ringkasan Tagihan</span></div>
                                <div class="rnt-panel-body p-0">
                                    <table class="erp-grid-table border-0">
                                        <tr><td class="border-top-0 border-start-0">Subtotal</td><td class="text-end border-top-0 border-end-0"><?= e(rupiah((float) $inv['subtotal'])) ?></td></tr>
                                        <tr><td class="border-start-0">Pajak (<?= e((string) $inv['tax_percent']) ?>%)</td><td class="text-end border-end-0"><?= e(rupiah((float) $inv['tax_amount'])) ?></td></tr>
                                        <tr class="fw-bold bg-light"><td class="border-start-0">Total Tagihan</td><td class="text-end border-end-0"><?= e(rupiah((float) $inv['total'])) ?></td></tr>
                                        <tr class="text-success"><td class="border-start-0">Sudah Dibayar</td><td class="text-end border-end-0"><?= e(rupiah((float) $inv['paid_total'])) ?></td></tr>
                                        <tr class="fw-bold <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>"><td class="border-bottom-0 border-start-0">Sisa Tagihan</td><td class="text-end border-bottom-0 border-end-0"><?= e(rupiah($remaining)) ?></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (can('invoices.manage') && $inv['status'] !== 'cancelled'): ?>
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Catat Pembayaran</span></div>
                        <div class="rnt-panel-body">
                            <?php if ($remaining <= 0): ?>
                                <div class="alert alert-success py-2 px-3 small mb-0">
                                    <i class="bi bi-check-circle me-1"></i>Tagihan invoice ini sudah lunas (sisa Rp 0). Pembayaran baru tidak diperlukan.
                                </div>
                            <?php else: ?>
                                <form method="post" action="" id="invPayForm" class="row g-2 align-items-end" enctype="multipart/form-data" data-remaining="<?= e((string) $remaining) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="act" value="pay">
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1 fw-medium">Nominal</label>
                                        <input type="hidden" id="invPayAmountValue" name="amount" value="<?= (int) round($remaining) ?>">
                                        <input class="form-control form-control-sm" id="invPayAmount" inputmode="numeric" required autocomplete="off"
                                               value="<?= e(format_nominal((float) round($remaining))) ?>" placeholder="0">
                                        <div class="invalid-feedback" id="invPayAmountErr">Nominal melebihi sisa tagihan.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1 fw-medium">Metode Pembayaran</label>
                                        <select class="form-select form-select-sm" name="payment_method_id">
                                            <option value="">— pilih metode —</option>
                                            <?php foreach ($methods as $m): ?>
                                                <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?><?= $m['account_number'] ? ' · ' . e($m['account_number']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1 fw-medium">Masuk ke Buku Kas/Bank</label>
                                        <select class="form-select form-select-sm" name="cash_account_id">
                                            <option value="">— Jangan catat ke Kas/Bank —</option>
                                            <?php foreach ($cash_accounts as $ca): ?>
                                                <option value="<?= (int) $ca['id'] ?>"><?= e($ca['name']) ?><?= $ca['account_number'] ? ' (' . e($ca['account_number']) . ')' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1 fw-medium">Bukti Bayar (Blob)</label>
                                        <input type="file" class="form-control form-control-sm" name="proof_file" accept="image/*,application/pdf">
                                    </div>
                                    <div class="col-md-10">
                                        <label class="form-label small mb-1 fw-medium">Catatan</label>
                                        <input class="form-control form-control-sm" name="note" maxlength="2000" placeholder="mis. transfer BCA 12:30">
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-sm btn-success w-100" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Simpan</button>
                                    </div>
                                </form>
                                <?php if (!$methods): ?><p class="text-muted small mb-0 mt-2">Belum ada metode pembayaran aktif — tambahkan di menu Keuangan → Metode Pembayaran.</p><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php $hasCancelPanel = can('invoices.manage') && $inv['status'] !== 'cancelled'; ?>
                    <div class="rnt-panel<?= $hasCancelPanel ? '' : ' mb-0' ?>">
                        <div class="rnt-panel-header"><span>Riwayat Pembayaran</span></div>
                        <div class="rnt-panel-body p-0">
                            <div class="table-responsive">
                                <table class="erp-grid-table">
                                    <thead><tr><th>Waktu</th><th class="text-end">Nominal</th><th>Catatan</th><th>Bukti</th><th>Oleh</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($payments as $pay): ?>
                                        <tr>
                                            <td><?= e((string) $pay['created_date']) ?></td>
                                            <td class="text-end fw-semibold text-success"><?= e(rupiah((float) $pay['amount'])) ?></td>
                                            <td><?= e((string) ($pay['note'] ?: $pay['description'])) ?></td>
                                            <td>
                                                <?php if (!empty($pay['proof_blob_path'])): ?>
                                                    <a href="<?= e(resolve_asset_url($pay['proof_blob_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-file-earmark-check me-1"></i>Lihat Bukti</a>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e((string) $pay['created_user']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$payments): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Belum ada pembayaran.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php if ($hasCancelPanel): ?>
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Batalkan Invoice</span></div>
                        <div class="rnt-panel-body">
                            <form method="post" action="" class="d-flex gap-2" onsubmit="return confirm('Batalkan invoice ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="act" value="cancel">
                                <input class="form-control form-control-sm" name="reason" placeholder="Alasan pembatalan" maxlength="2000">
                                <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">Batalkan Invoice</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade" id="tab-inv-audit" role="tabpanel" aria-labelledby="tab-inv-audit-btn">
                    <?= ui_erp_audit_panel($inv) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form   = document.getElementById('invPayForm');
    const inp    = document.getElementById('invPayAmount');
    const hidden = document.getElementById('invPayAmountValue');
    const err    = document.getElementById('invPayAmountErr');
    if (!form || !inp || !err) return;

    const remaining = parseFloat(form.getAttribute('data-remaining') || '0');
    function parseIndoAmount(raw) {
        const cleaned = String(raw || '').trim().replace(/\./g, '').replace(',', '.');
        const n = parseFloat(cleaned);
        return Number.isFinite(n) ? n : 0;
    }
    function formatId(n) {
        return Math.round(n).toLocaleString('id-ID');
    }
    function validateAmount(num) {
        const val = typeof num === 'number' ? num : parseIndoAmount(inp.value);
        if (hidden) hidden.value = String(Math.round(val));
        if (val <= 0) {
            inp.classList.add('is-invalid');
            err.textContent = 'Nominal pembayaran harus lebih dari Rp 0.';
            return false;
        }
        if (val > remaining + 0.001) {
            inp.classList.add('is-invalid');
            err.textContent = 'Nominal (Rp ' + formatId(val) + ') tidak boleh melebihi sisa tagihan (Rp ' + formatId(remaining) + ').';
            return false;
        }
        inp.classList.remove('is-invalid');
        return true;
    }
    if (typeof window.attachRupiahInput === 'function') {
        window.attachRupiahInput(inp, hidden, validateAmount);
    } else {
        inp.addEventListener('input', function () { validateAmount(); });
    }
    form.addEventListener('submit', function (e) {
        const val = parseIndoAmount(inp.value);
        if (!validateAmount(val)) {
            e.preventDefault();
            inp.focus();
            return false;
        }
    });
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
