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
        } elseif ($act === 'final' || $act === 'pay') {
            $next = $act === 'final' ? 'final' : 'paid';
            payroll_period_set_status($id, $cid, $next, $user);
            audit('payroll.' . $act, "Periode #{$id} -> {$next}");
            flash('success', $act === 'final' ? 'Periode difinalkan.' : 'Periode ditandai sudah dibayar.');
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

$active = 'payroll';
$title  = 'Detail Payroll — ' . payroll_period_label($period);
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Payroll <?= e(payroll_period_label($period)) ?> <?= payroll_status_badge($period['status']) ?></h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/payroll/periods/print.php?id=<?= $id ?>" target="_blank">
                <i class="bi bi-printer"></i> Cetak
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/payroll/periods/index.php">Kembali</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-3"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
        <div><div class="text-muted small">Jumlah Karyawan</div><div class="fw-semibold"><?= (int) $period['item_count'] ?></div></div>
        <div><div class="text-muted small">Total Gaji Bersih</div><div class="fw-semibold"><?= e(rupiah($total_net)) ?></div></div>
        <div><div class="text-muted small">Dibuat</div><div><?= e((string) $period['created_date']) ?> oleh <?= e((string) $period['created_user']) ?></div></div>
        <?php if (!empty($period['modified_date'])): ?>
        <div><div class="text-muted small">Terakhir Diubah</div><div><?= e((string) $period['modified_date']) ?> oleh <?= e((string) $period['modified_user']) ?></div></div>
        <?php endif; ?>
        <?php if ($period['status'] === 'paid'): ?>
            <div class="ms-auto text-success small"><i class="bi bi-check-circle"></i> Periode terkunci — item tidak bisa diubah lagi.</div>
        <?php endif; ?>
    </div></div>

    <?php if ($can_manage && $period['status'] !== 'paid'): ?>
    <div class="card shadow-sm border-0 mb-3"><div class="card-body d-flex gap-2 align-items-center flex-wrap">
        <?php if ($period['status'] === 'draft'): ?>
            <form method="post" action="" onsubmit="return confirm('Finalkan periode ini? Pastikan angka sudah benar.')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="final">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-lock"></i> Finalkan</button>
            </form>
        <?php endif; ?>
        <?php if ($period['status'] === 'final'): ?>
            <form method="post" action="" onsubmit="return confirm('Tandai periode ini SUDAH DIBAYAR? Setelah ini data tidak bisa diubah lagi.')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="pay">
                <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-cash-coin"></i> Tandai Dibayar</button>
            </form>
        <?php endif; ?>
        <span class="text-muted small">Alur status: draft → final → dibayar (tidak bisa mundur).</span>
        <span class="text-muted small">Nilai per baris dihitung ulang saat disimpan: gaji pokok + (uang harian × hari hadir) − potongan. Hasil baru muncul setelah halaman dimuat ulang.</span>
    </div></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
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
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary" type="submit" form="<?= e($fid) ?>">Simpan</button>
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
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
