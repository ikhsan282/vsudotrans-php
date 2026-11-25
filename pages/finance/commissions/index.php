<?php
/**
 * Rekap & Pembayaran Komisi Sopir / Kru:
 * memantau komisi/uang jalan trip dari `booking_cost_lines`, rekap per sopir/kru,
 * dan pencairan langsung yang otomatis masuk ke Pengeluaran & Buku Kas.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/_query.php';

$compScope     = company_filter_scope('bcl.company_id');
$filter_cid    = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;

$f = comm_filter($_GET);
if (is_super_admin()) {
    $f['company_id'] = $filter_cid;
}

$p    = paginate(comm_count($f), (int) ($_GET['page'] ?? 1));
$rows = comm_list($f, $p['per_page'], $p['offset']);
$sum  = comm_summary($f);
$empRecap = comm_by_employee($f);

$payCid   = active_company_id() ?? $filter_cid;
$empOpts  = comm_employee_options($filter_cid);
$curQs    = comm_build_qs($f);

$empChipBase = $f;
$empChipBase['employee_id'] = 0;
$empChipQs = comm_build_qs($empChipBase);

$hasUnpaidInPage = false;
foreach ($rows as $r) {
    if (empty($r['paid_cash_tx_id'])) {
        $hasUnpaidInPage = true;
        break;
    }
}

$cards = [
    ['Belum Dibayar', rupiah($sum['unpaid_amount']), 'warning', 'hourglass-split', number_format($sum['unpaid_lines'], 0, ',', '.') . ' baris komisi'],
    ['Sudah Dibayar', rupiah($sum['paid_amount']),   'success', 'check2-circle',   number_format($sum['paid_lines'], 0, ',', '.') . ' baris lunas'],
    ['Total Komisi Periode', rupiah($sum['total_amount']), 'primary', 'cash-stack', number_format($sum['total_lines'], 0, ',', '.') . ' baris trip'],
    ['Jumlah Sopir / Kru', number_format($sum['employee_count'], 0, ',', '.'), 'info', 'people', 'Karyawan dengan komisi'],
];

$header_actions = [
    ['label' => 'Pengeluaran', 'icon' => 'arrow-up-circle', 'href' => 'pages/finance/expenses/index.php', 'class' => 'btn btn-outline-danger btn-sm'],
    ['label' => 'Buku Kas',    'icon' => 'wallet2',         'href' => 'pages/finance/cash/index.php',     'class' => 'btn btn-outline-primary btn-sm'],
];

$active = 'commissions';
$title  = 'Komisi Sopir & Kru';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        'Komisi Sopir & Kru',
        ['Dashboard' => 'pages/dashboard/index.php', 'Keuangan' => null, 'Komisi Sopir & Kru' => null],
        [['text' => number_format((int) $p['total'], 0, ',', '.') . ' Baris Komisi', 'class' => 'bg-light text-secondary border']],
        $header_actions,
        'Pantau komisi perjalanan sopir & kru lalu cairkan langsung — otomatis masuk Pengeluaran & Buku Kas'
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar erp-filter-bar-wide" method="get">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-sm-6 col-lg-2 erp-filter-item">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-sm-6 col-lg-2 erp-filter-date">
                    <label class="form-label small mb-1">Dari Tanggal Trip</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-6 col-sm-6 col-lg-2 erp-filter-date">
                    <label class="form-label small mb-1">Sampai Tanggal Trip</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-2 erp-filter-item">
                    <label class="form-label small mb-1">Sopir / Kru</label>
                    <select class="form-select form-select-sm ts-select" name="employee_id" onchange="this.form.submit()">
                        <option value="0">Semua sopir / kru</option>
                        <?php foreach ($empOpts as $eo):
                            $eIdOpt = (int) $eo['id'];
                            $eLbl   = (string) $eo['name'];
                            if (!empty($eo['code'])) {
                                $eLbl .= ' (' . $eo['code'] . ')';
                            }
                        ?>
                            <option value="<?= $eIdOpt ?>"<?= $eIdOpt === (int) $f['employee_id'] ? ' selected' : '' ?>><?= e($eLbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-lg-1 erp-filter-status">
                    <label class="form-label small mb-1">Layanan</label>
                    <select class="form-select form-select-sm" name="booking_type" onchange="this.form.submit()">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-4 col-lg-2 erp-filter-item">
                    <label class="form-label small mb-1">Status Bayar</label>
                    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                        <?php foreach (['all' => 'Semua status', 'unpaid' => 'Belum Dibayar', 'paid' => 'Sudah Dibayar'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 erp-filter-q">
                    <label class="form-label small mb-1">Pencarian</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q" placeholder="Nama sopir, dokumen, plat..." value="<?= e($f['q']) ?>">
                    </div>
                </div>
                <?= ui_filter_actions(
                    APP_URL . '/pages/finance/commissions/index.php',
                    ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['employee_id'] > 0 || $f['booking_type'] !== 'all' || $f['status'] !== 'all' || $f['q'] !== '' || (is_super_admin() && $filter_cid !== (active_company_id() ?? 0)))
                ) ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ($cards as [$lbl, $val, $clr, $icon, $subLbl]): ?>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100 overflow-hidden">
                    <div class="card-body d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="text-muted small"><?= e($lbl) ?></div>
                            <div class="fs-5 fw-bold text-<?= e($clr) ?> text-truncate" title="<?= e($val) ?>"><?= e($val) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= e($subLbl) ?></div>
                        </div>
                        <i class="bi bi-<?= e($icon) ?> fs-3 text-<?= e($clr) ?> opacity-50 flex-shrink-0"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($empRecap): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-people-fill me-1 text-primary"></i>Rekap Komisi per Sopir / Kru</span>
            <?php if ($f['employee_id'] > 0): ?>
                <a href="<?= e(APP_URL . '/pages/finance/commissions/index.php' . ($empChipQs !== '' ? '?' . $empChipQs : '')) ?>"
                   class="btn btn-outline-secondary btn-sm py-0 px-2">Tampilkan Semua Kru</a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Sopir / Kru</th>
                        <th>Rekening</th>
                        <th class="text-end">Baris Trip</th>
                        <th class="text-end">Total Komisi</th>
                        <th class="text-end">Belum Dibayar</th>
                        <th class="text-end">Sudah Dibayar</th>
                        <th class="text-end">Filter</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($empRecap as $er):
                    $erEid  = (int) $er['employee_id'];
                    $isSel  = $erEid > 0 && $erEid === (int) $f['employee_id'];
                    $erHref = APP_URL . '/pages/finance/commissions/index.php?' . ($empChipQs !== '' ? $empChipQs . '&' : '') . 'employee_id=' . $erEid;
                    $acct   = trim(($er['bank_name'] ?? '') . ' ' . ($er['account_number'] ?? ''));
                ?>
                    <tr class="<?= $isSel ? 'table-primary' : '' ?>">
                        <td>
                            <div class="fw-semibold"><?= e((string) $er['employee_name']) ?></div>
                            <?php if (!empty($er['employee_code']) || !empty($er['job_position'])): ?>
                                <div class="small text-muted"><?= e(trim(($er['employee_code'] ?? '') . ($er['job_position'] ? ' · ' . $er['job_position'] : ''))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $acct !== '' ? e($acct) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end">
                            <?= (int) $er['total_lines'] ?>
                            <?php if ((int) $er['unpaid_lines'] > 0): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border ms-1"><?= (int) $er['unpaid_lines'] ?> belum</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-medium"><?= e(rupiah((float) $er['total_amount'])) ?></td>
                        <td class="text-end fw-semibold <?= (float) $er['unpaid_amount'] > 0 ? 'text-warning-emphasis' : 'text-muted' ?>"><?= e(rupiah((float) $er['unpaid_amount'])) ?></td>
                        <td class="text-end text-success"><?= e(rupiah((float) $er['paid_amount'])) ?></td>
                        <td class="text-end">
                            <?php if ($erEid > 0): ?>
                                <a href="<?= e($erHref) ?>" class="btn btn-sm <?= $isSel ? 'btn-primary' : 'btn-outline-primary' ?> py-0 px-2" title="Filter kru ini">
                                    <?= $isSel ? 'Dipilih' : 'Pilih' ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (can('finance.manage') && $payCid === 0): ?>
        <div class="alert alert-info py-2 px-3 small mb-3" data-no-autodismiss>
            <i class="bi bi-info-circle me-1"></i>Anda sedang melihat <strong>Semua Perusahaan</strong>. Pilih satu perusahaan di filter di atas untuk mencairkan komisi sopir/kru.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <?php if (can('finance.manage') && $payCid > 0): ?>
        <form method="post" action="pay.php" id="formPayCommission" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="company_id" value="<?= (int) $payCid ?>">
            <input type="hidden" name="return_qs" value="<?= e($curQs) ?>">

            <?php if ($hasUnpaidInPage): ?>
            <div class="card-header bg-light-subtle border-bottom py-2 px-3">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-3">
                        <div class="small text-muted">Komisi Dipilih untuk Dicairkan</div>
                        <div class="fw-bold text-primary">
                            <span id="commSelCount">0</span> baris · <span id="commSelTotal">Rp 0</span>
                        </div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small mb-1" for="pay_date">Tanggal Bayar</label>
                        <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto id="pay_date" name="pay_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small mb-1" for="comm_proof_file">Bukti Transfer</label>
                        <input type="file" class="form-control form-control-sm" id="comm_proof_file" name="proof_file" accept="image/*,application/pdf">
                    </div>
                    <div class="col-12 col-lg-3">
                        <label class="form-label small mb-1" for="comm_note">Catatan</label>
                        <input type="text" class="form-control form-control-sm" id="comm_note" name="note" maxlength="300" placeholder="mis. Transfer BCA batch 1">
                    </div>
                    <div class="col-12 col-lg-1 d-grid">
                        <button type="submit" class="btn btn-sm btn-primary text-nowrap" id="btnPayCommission" disabled>
                            <i class="bi bi-cash-coin me-1"></i>Bayar
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <?php if (can('finance.manage') && $payCid > 0): ?>
                        <th style="width:36px;" class="text-center">
                            <input class="form-check-input" type="checkbox" id="commSelectAll" title="Pilih semua yang belum dibayar" <?= $hasUnpaidInPage ? '' : 'disabled' ?>>
                        </th>
                        <?php endif; ?>
                        <th>Tgl Trip</th>
                        <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
                        <th>Booking &amp; Unit</th>
                        <th>Sopir / Kru</th>
                        <th>Jenis Biaya</th>
                        <th class="text-end">Komisi</th>
                        <th>Status Pembayaran</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $isPaid   = !empty($r['paid_cash_tx_id']);
                    $commAmt  = (float) ($r['commission_amount'] ?? 0);
                    $bookHref = !empty($r['booking_exists'])
                        ? ('../../' . ($r['booking_type'] === 'rental' ? 'rental' : 'travel') . '/bookings/detail.php?id=' . (int) $r['booking_id'])
                        : '';
                ?>
                    <tr>
                        <?php if (can('finance.manage') && $payCid > 0): ?>
                        <td class="text-center">
                            <?php if (!$isPaid): ?>
                                <input class="form-check-input comm-row-cb" type="checkbox" name="line_ids[]" value="<?= (int) $r['id'] ?>" data-amount="<?= e((string) $commAmt) ?>">
                            <?php else: ?>
                                <i class="bi bi-check-circle-fill text-success" title="Sudah dibayar"></i>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-nowrap"><?= e(tanggal_indo((string) $r['trip_date'])) ?></td>
                        <?php if ($show_comp_col): ?>
                            <td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td>
                        <?php endif; ?>
                        <td>
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <span class="badge <?= $r['booking_type'] === 'rental' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>">
                                    <?= e(ucfirst((string) $r['booking_type'])) ?>
                                </span>
                                <?php if ($bookHref !== ''): ?>
                                    <a href="<?= e($bookHref) ?>" class="fw-medium text-decoration-none font-monospace"><?= e((string) $r['document_number']) ?></a>
                                <?php else: ?>
                                    <span class="font-monospace text-muted"><?= e((string) $r['document_number']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($r['vehicle_number'])): ?>
                                <div class="small text-muted font-monospace"><i class="bi bi-truck me-1"></i><?= e((string) $r['vehicle_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= !empty($r['employee_name']) ? e((string) $r['employee_name']) : '<span class="text-muted">(Belum ditautkan)</span>' ?></div>
                            <?php if (!empty($r['employee_code'])): ?>
                                <div class="small text-muted"><?= e((string) $r['employee_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= e((string) $r['cost_type']) ?></span>
                            <?php if (!empty($r['note'])): ?>
                                <div class="small text-muted text-truncate" style="max-width:200px;" title="<?= e((string) $r['note']) ?>"><?= e((string) $r['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(rupiah($commAmt)) ?></td>
                        <td>
                            <?php if ($isPaid): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Lunas</span>
                                <div class="small text-muted">
                                    <?= e(!empty($r['cash_paid_date']) ? tanggal_indo((string) $r['cash_paid_date']) : '-') ?>
                                    <span class="font-monospace">(#<?= (int) $r['paid_cash_tx_id'] ?>)</span>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum Dibayar</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($isPaid): ?>
                                <?php if (!empty($r['cash_proof_blob_path'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(resolve_asset_url((string) $r['cash_proof_blob_path'])) ?>" target="_blank" rel="noopener" title="Lihat Bukti Transfer" aria-label="Lihat Bukti"><i class="bi bi-paperclip"></i></a>
                                <?php endif; ?>
                                <?php if (can('finance.manage')): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Batalkan pencairan #<?= (int) $r['paid_cash_tx_id'] ?>"
                                            aria-label="Batalkan pencairan" onclick="cancelCommTx(<?= (int) $r['paid_cash_tx_id'] ?>)">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= (can('finance.manage') && $payCid > 0 ? 1 : 0) + ($show_comp_col ? 8 : 7) ?>" class="text-center text-muted py-4">
                            Belum ada data komisi sopir/kru pada periode &amp; filter ini.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (can('finance.manage') && $payCid > 0): ?>
        </form>
        <?php endif; ?>

        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <span class="small text-muted">Periode Trip <?= e(tanggal_indo($f['from'])) ?> s/d <?= e(tanggal_indo($f['to'])) ?></span>
            <?= pagination_html($p, $curQs) ?>
        </div>
    </div>

    <?php if (can('finance.manage')): ?>
    <form method="post" action="cancel.php" id="formCancelComm" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="cash_tx_id" id="cancelCashTxId" value="">
        <input type="hidden" name="return_qs" value="<?= e($curQs) ?>">
    </form>
    <?php endif; ?>
</main>
<script>
function cancelCommTx(txId) {
    if (!confirm('Batalkan pencairan kas #' + txId + '? Semua baris komisi pada pencairan ini akan kembali berstatus Belum Dibayar dan transaksi kasnya dihapus.')) {
        return;
    }
    var inp = document.getElementById('cancelCashTxId');
    var frm = document.getElementById('formCancelComm');
    if (inp && frm) {
        inp.value = String(txId);
        frm.submit();
    }
}
document.addEventListener('DOMContentLoaded', function () {
    var selAll = document.getElementById('commSelectAll');
    var cbs    = Array.from(document.querySelectorAll('.comm-row-cb'));
    var elCnt  = document.getElementById('commSelCount');
    var elTot  = document.getElementById('commSelTotal');
    var btnPay = document.getElementById('btnPayCommission');
    if (!cbs.length) return;

    function fmtRp(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }
    function syncSelection() {
        var cnt = 0, tot = 0;
        cbs.forEach(function (cb) {
            if (cb.checked) {
                cnt++;
                tot += parseFloat(cb.getAttribute('data-amount') || '0') || 0;
            }
        });
        if (elCnt) elCnt.textContent = String(cnt);
        if (elTot) elTot.textContent = fmtRp(tot);
        if (btnPay) btnPay.disabled = cnt === 0;
        if (selAll) {
            selAll.checked = cnt > 0 && cnt === cbs.length;
            selAll.indeterminate = cnt > 0 && cnt < cbs.length;
        }
    }
    if (selAll) {
        selAll.addEventListener('change', function () {
            cbs.forEach(function (cb) { cb.checked = selAll.checked; });
            syncSelection();
        });
    }
    cbs.forEach(function (cb) {
        cb.addEventListener('change', syncSelection);
    });
    syncSelection();
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
