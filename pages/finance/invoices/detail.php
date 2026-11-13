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
$title  = 'Detail Invoice';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h4 class="fw-bold mb-0"><?= e($inv['invoice_no']) ?> <?= invoice_pay_badge($inv['pay_status']) ?></h4>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($qrisChannels as $qch): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/finance/invoices/gateway_pay.php?invoice_id=<?= $id ?>&provider=<?= e($qch['provider']) ?>">
                    <i class="bi bi-qr-code-scan me-1"></i> Bayar <?= e($qch['name']) ?>
                </a>
            <?php endforeach; ?>
            <a class="btn btn-dark btn-sm" target="_blank" href="<?= e(APP_URL) ?>/pages/finance/invoices/print.php?id=<?= $id ?>&print=1">Cetak Invoice</a>
            <a class="btn btn-danger btn-sm" href="<?= e(APP_URL) ?>/pages/finance/invoices/print.php?id=<?= $id ?>&format=pdf"><i class="bi bi-filetype-pdf"></i> PDF</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/<?= e($link) ?>?id=<?= (int) $inv['booking_id'] ?>">Lihat Booking</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/finance/invoices/index.php">Kembali</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-7"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <div class="row g-3 small">
                <div class="col-6"><div class="text-muted">Resi Booking</div><div class="fw-semibold"><?= e($inv['document_number']) ?></div></div>
                <div class="col-6"><div class="text-muted">Tipe</div><div class="fw-semibold"><?= e($inv['booking_type']) ?></div></div>
                <div class="col-6"><div class="text-muted">Terbit</div><div><?= e((string) $inv['issued_at']) ?></div></div>
                <div class="col-6"><div class="text-muted">Status Invoice</div><div><?= e($inv['status']) ?></div></div>
                <?php if (!empty($inv['note'])): ?>
                <div class="col-12"><div class="text-muted">Catatan</div><div><?= e((string) $inv['note']) ?></div></div>
                <?php endif; ?>
            </div>
        </div></div></div>
        <div class="col-md-5"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><td>Subtotal</td><td class="text-end"><?= e(rupiah((float) $inv['subtotal'])) ?></td></tr>
                <tr><td>Pajak (<?= e((string) $inv['tax_percent']) ?>%)</td><td class="text-end"><?= e(rupiah((float) $inv['tax_amount'])) ?></td></tr>
                <tr class="fw-bold border-top"><td>Total</td><td class="text-end"><?= e(rupiah((float) $inv['total'])) ?></td></tr>
                <tr class="text-success"><td>Dibayar</td><td class="text-end"><?= e(rupiah((float) $inv['paid_total'])) ?></td></tr>
                <tr class="fw-bold <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>"><td>Sisa</td><td class="text-end"><?= e(rupiah($remaining)) ?></td></tr>
            </table>
        </div></div></div>
    </div>

    <?php if (can('invoices.manage') && $inv['status'] !== 'cancelled'): ?>
    <div class="card shadow-sm border-0 mb-3"><div class="card-body">
        <h6 class="fw-bold">Catat Pembayaran</h6>
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
                <label class="form-label small mb-1">Nominal</label>
                <input type="hidden" id="invPayAmountValue" name="amount" value="<?= (int) round($remaining) ?>">
                <input class="form-control form-control-sm" id="invPayAmount" inputmode="numeric" required autocomplete="off"
                       value="<?= e(format_nominal((float) round($remaining))) ?>" placeholder="0">
                <div class="invalid-feedback" id="invPayAmountErr">Nominal melebihi sisa tagihan.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Metode Pembayaran</label>
                <select class="form-select form-select-sm" name="payment_method_id">
                    <option value="">— pilih metode —</option>
                    <?php foreach ($methods as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?><?= $m['account_number'] ? ' · ' . e($m['account_number']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Masuk ke Buku Kas/Bank</label>
                <select class="form-select form-select-sm" name="cash_account_id">
                    <option value="">— Jangan catat ke Kas/Bank —</option>
                    <?php foreach ($cash_accounts as $ca): ?>
                        <option value="<?= (int) $ca['id'] ?>"><?= e($ca['name']) ?><?= $ca['account_number'] ? ' (' . e($ca['account_number']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Bukti Bayar (Blob)</label>
                <input type="file" class="form-control form-control-sm" name="proof_file" accept="image/*,application/pdf">
            </div>
            <div class="col-md-10">
                <label class="form-label small mb-1">Catatan</label>
                <input class="form-control form-control-sm" name="note" maxlength="2000" placeholder="mis. transfer BCA 12:30">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-success w-100" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Simpan</button>
            </div>
        </form>
        <?php if (!$methods): ?><p class="text-muted small mb-0 mt-2">Belum ada metode pembayaran aktif — tambahkan di menu Keuangan → Metode Pembayaran.</p><?php endif; ?>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Riwayat Pembayaran</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle text-nowrap">
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
                                <span class="text-muted small">—</span>
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

    <?php if (can('invoices.manage') && $inv['status'] !== 'cancelled'): ?>
    <div class="card shadow-sm border-0"><div class="card-body">
        <h6 class="fw-bold">Batalkan Invoice</h6>
        <form method="post" action="" class="d-flex gap-2" onsubmit="return confirm('Batalkan invoice ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="cancel">
            <input class="form-control form-control-sm" name="reason" placeholder="Alasan pembatalan" maxlength="2000">
            <button class="btn btn-sm btn-outline-danger" type="submit">Batalkan</button>
        </form>
    </div></div>
    <?php endif; ?>
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
