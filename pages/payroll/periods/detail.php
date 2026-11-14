<?php
/**
 * Payroll — detail periode: item karyawan, edit hari hadir/potongan, transisi status.
 *
 * Tabel edit memakai satu <form> per baris: form kosong diletakkan di dalam <td>
 * (HTML tidak mengizinkan form membungkus <tr>), lalu input & tombol
 * dihubungkan dengan atribut `form="row-<item_id>"`.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.view');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $_SESSION['username'] ?? null;
$errors = [];

$period = payroll_period_find($id);
if (!$period) {
    flash('error', 'Periode payroll tidak ditemukan.');
    redirect('pages/payroll/periods/index.php');
}
if ((int) $period['company_id'] !== $cid) {
    http_response_code(403);
    include __DIR__ . '/../../../includes/403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('payroll.manage');
    verify_csrf();
    $act = (string) ($_POST['act'] ?? '');
    try {
        if ($act === 'save_item') {
            $item_id = (int) ($_POST['item_id'] ?? 0);
            $days    = (int) ($_POST['days_present'] ?? 0);
            $ded     = (float) str_replace(['.', ','], ['', '.'], (string) ($_POST['deductions'] ?? '0'));
            $note    = trim((string) ($_POST['note'] ?? ''));
            $res = payroll_item_update($id, $cid, $item_id, $days, $ded, $note === '' ? null : $note, $user);
            audit('payroll.item', "Periode #{$id} item #{$item_id} net " . rupiah((float) $res['net_salary']));
            flash('success', 'Item gaji diperbarui: ' . rupiah((float) $res['net_salary']) . '.');
        } elseif ($act === 'pull_attendance') {
            $res = payroll_pull_attendance($id, $cid, $user);
            audit('payroll.pull_attendance', "Periode #{$id} ({$period['period_year']}-{$period['period_month']}): {$res['updated']} item diperbarui dari Absensi");
            flash('success', $res['updated'] . ' item gaji diperbarui dari Absensi.');
        } elseif ($act === 'final' || $act === 'pay') {
            $next = $act === 'final' ? 'final' : 'paid';
            $cash_account_id = (int) ($_POST['cash_account_id'] ?? 0);
            payroll_period_set_status($id, $cid, $next, $user, $cash_account_id > 0 ? $cash_account_id : null);
            audit('payroll.' . $act, "Periode #{$id} -> {$next}");
            flash('success', $act === 'final' ? 'Periode difinalkan.' : 'Periode ditandai sudah dibayar' . ($cash_account_id > 0 ? ' & pengeluaran kas dicatat.' : '.'));
        }
        redirect('pages/payroll/periods/detail.php?id=' . $id);
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
    $period = payroll_period_find($id);
}

$items = payroll_items($id);
$total_net = 0.0;
foreach ($items as $i) {
    $total_net += (float) $i['net_salary'];
}
$can_manage = can('payroll.manage');
$editable = $can_manage && $period['status'] !== 'paid';
$cols = $editable ? 8 : 7;

$cash_accounts = [];
if ($can_manage && $period['status'] === 'final') {
    $cst = db()->prepare('SELECT id, name, account_number FROM cash_accounts WHERE is_active = 1 AND company_id = ? ORDER BY name');
    $cst->bind_param('i', $cid);
    $cst->execute();
    $cash_accounts = $cst->get_result()->fetch_all(MYSQLI_ASSOC);
    $cst->close();
}

$active = 'payroll';
$title  = 'Detail Payroll — ' . payroll_period_label($period);
require __DIR__ . '/../../../includes/header.php';

$payActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/payroll/periods/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($can_manage && $period['status'] === 'draft') {
    $payActions[] = [
        'html' => '<form method="post" action="" class="d-inline" onsubmit="return confirm(\'Tarik hari hadir dari Absensi dan timpa Hari Hadir semua item periode ini?\')">'
            . csrf_field()
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="act" value="pull_attendance">'
            . '<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-calendar-check me-1"></i>Tarik dari Absensi</button></form>',
    ];
}
$payActions[] = ['label' => 'Cetak', 'icon' => 'printer', 'href' => 'pages/payroll/periods/print.php?id=' . $id, 'target' => '_blank', 'class' => 'btn btn-dark btn-sm'];
$payActions[] = ['label' => 'CSV', 'icon' => 'filetype-csv', 'href' => 'pages/payroll/periods/export.php?id=' . $id . '&format=csv', 'class' => 'btn btn-outline-success btn-sm'];
$payActions[] = ['label' => 'Excel', 'icon' => 'file-earmark-excel', 'href' => 'pages/payroll/periods/export.php?id=' . $id . '&format=xlsx', 'class' => 'btn btn-outline-success btn-sm'];
$payActions[] = ['label' => 'PDF', 'icon' => 'filetype-pdf', 'href' => 'pages/payroll/periods/print.php?id=' . $id . '&format=pdf', 'class' => 'btn btn-danger btn-sm'];
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Payroll',
        'cash-coin',
        'Periode ' . payroll_period_label($period),
        [
            ['html' => payroll_status_badge($period['status'])],
            ['text' => (int) $period['item_count'] . ' Karyawan', 'class' => 'bg-info-subtle text-info-emphasis border'],
        ],
        $payActions
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="payrollDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-pay-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-pay-detail" type="button" role="tab">Detail Payroll</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-pay-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-pay-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="payrollDetailTabContent">
                <div class="tab-pane fade show active" id="tab-pay-detail" role="tabpanel" aria-labelledby="tab-pay-detail-btn">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Ringkasan Periode Payroll</span>
                            <?php if ($period['status'] === 'paid'): ?>
                                <span class="text-success small fw-normal"><i class="bi bi-lock-fill me-1"></i>Periode terkunci</span>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <?= ui_erp_ro_row('Periode', payroll_period_label($period)) ?>
                                    <?= ui_erp_ro_row('Jumlah Karyawan', (string) ((int) $period['item_count']) . ' Orang') ?>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <?= ui_erp_ro_row('Total Gaji Bersih', rupiah($total_net)) ?>
                                    <?= ui_erp_ro_html_row('Status Periode', payroll_status_badge($period['status']) . ($period['status'] === 'paid' ? '<span class="small text-success">Periode terkunci — item tidak dapat diubah.</span>' : '')) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($can_manage && $period['status'] !== 'paid'): ?>
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Proses Status &amp; Pembayaran Payroll</span></div>
                        <div class="rnt-panel-body d-flex gap-2 align-items-center flex-wrap">
                            <?php if ($period['status'] === 'draft'): ?>
                                <form method="post" action="" onsubmit="return confirm('Finalkan periode ini? Pastikan angka sudah benar.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="act" value="final">
                                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-lock me-1"></i>Finalkan</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($period['status'] === 'final'): ?>
                                <form method="post" action="" class="d-inline-flex align-items-center gap-2 flex-wrap" onsubmit="return confirm('Tandai periode ini SUDAH DIBAYAR? Data payroll akan dikunci.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="act" value="pay">
                                    <div class="input-group input-group-sm" style="max-width: 320px;">
                                        <span class="input-group-text bg-light text-muted"><i class="bi bi-wallet2"></i></span>
                                        <select class="form-select form-select-sm" name="cash_account_id" title="Pilih akun Kas/Bank untuk mencatat pengeluaran otomatis">
                                            <option value="">— Jangan potong Kas/Bank —</option>
                                            <?php foreach ($cash_accounts as $ca): ?>
                                                <option value="<?= (int) $ca['id'] ?>"><?= e($ca['name']) ?><?= $ca['account_number'] ? ' (' . e($ca['account_number']) . ')' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-cash-coin me-1"></i>Tandai Dibayar</button>
                                </form>
                            <?php endif; ?>
                            <span class="text-muted small">Alur: draft → final → dibayar. Nilai bersih: gaji pokok + (uang harian × hari hadir) − potongan.</span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Rincian Item Gaji Karyawan</span></div>
                        <div class="rnt-panel-body p-0">
                            <div class="table-responsive">
                                <table class="erp-grid-table">
                                    <thead><tr>
                                        <th>Karyawan</th>
                                        <th class="text-end">Gaji Pokok</th>
                                        <th class="text-end">Uang Harian</th>
                                        <th class="text-end">Hari Hadir</th>
                                        <th class="text-end">Potongan</th>
                                        <th class="text-end">Gaji Bersih</th>
                                        <th>Catatan</th>
                                        <?php if ($editable): ?><th class="text-end">Aksi</th><?php endif; ?>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($items as $it): $fid = 'row-' . (int) $it['id']; ?>
                                        <tr>
                                            <td>
                                                <?php if ($editable): ?>
                                                    <form id="<?= e($fid) ?>" method="post" action=""></form>
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>" form="<?= e($fid) ?>">
                                                    <input type="hidden" name="id" value="<?= $id ?>" form="<?= e($fid) ?>">
                                                    <input type="hidden" name="act" value="save_item" form="<?= e($fid) ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>" form="<?= e($fid) ?>">
                                                <?php endif; ?>
                                                <div class="fw-semibold"><?= e($it['employee_name']) ?></div>
                                                <div class="small text-muted"><?= e((string) $it['employee_code']) ?><?= $it['account_number'] ? ' · ' . e((string) $it['bank_name']) . ' ' . e((string) $it['account_number']) : '' ?></div>
                                            </td>
                                            <td class="text-end"><?= e(rupiah((float) $it['basic_salary'])) ?></td>
                                            <td class="text-end"><?= e(rupiah((float) $it['daily_allowance'])) ?></td>
                                            <td class="text-end">
                                                <?php if ($editable): ?>
                                                    <input type="number" name="days_present" class="form-control form-control-sm text-end ms-auto" style="width: 90px"
                                                           min="0" max="31" value="<?= (int) $it['days_present'] ?>" form="<?= e($fid) ?>">
                                                <?php else: ?>
                                                    <?= (int) $it['days_present'] ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($editable): ?>
                                                    <input type="text" inputmode="numeric" name="deductions" class="form-control form-control-sm text-end ms-auto" style="width: 130px"
                                                           value="<?= e(number_format((float) $it['deductions'], 0, ',', '.')) ?>" form="<?= e($fid) ?>">
                                                <?php else: ?>
                                                    <?= e(rupiah((float) $it['deductions'])) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-semibold"><?= e(rupiah((float) $it['net_salary'])) ?></td>
                                            <td>
                                                <?php if ($editable): ?>
                                                    <input type="text" name="note" class="form-control form-control-sm" maxlength="2000"
                                                           value="<?= e((string) $it['note']) ?>" placeholder="—" form="<?= e($fid) ?>">
                                                <?php else: ?>
                                                    <?= e((string) $it['note']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($editable): ?>
                                                <td class="text-end text-nowrap">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit" form="<?= e($fid) ?>" title="Simpan" aria-label="Simpan"><i class="bi bi-check2"></i></button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$items): ?>
                                        <tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4">Belum ada item pada periode ini.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="5" class="text-end">Total Gaji Bersih</td>
                                            <td class="text-end"><?= e(rupiah($total_net)) ?></td>
                                            <td colspan="<?= $editable ? 2 : 1 ?>"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-pay-audit" role="tabpanel" aria-labelledby="tab-pay-audit-btn">
                    <?= ui_erp_audit_panel($period) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
