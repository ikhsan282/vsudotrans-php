<?php
/**
 * Payroll — daftar periode + generate periode baru.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.view');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$cid = require_active_company();
$user = $_SESSION['username'] ?? null;
$year = (int) ($_GET['year'] ?? date('Y'));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('payroll.manage');
    verify_csrf();
    try {
        $gen_year  = (int) ($_POST['period_year'] ?? 0);
        $gen_month = (int) ($_POST['period_month'] ?? 0);
        $res = payroll_generate($cid, $gen_year, $gen_month, $user);
        audit('payroll.generate', 'Periode ' . payroll_month_name($gen_month) . " $gen_year (#{$res['period_id']}), {$res['items']} karyawan");
        $msg = 'Periode ' . payroll_month_name($gen_month) . " $gen_year dibuat dengan {$res['items']} item.";
        if ($res['grade_missing']) {
            $msg .= ' Tanpa grade (gaji 0): ' . implode(', ', array_slice($res['grade_missing'], 0, 5))
                . (count($res['grade_missing']) > 5 ? ' (+' . (count($res['grade_missing']) - 5) . ' lagi)' : '') . '.';
        }
        flash('success', $msg);
        redirect('pages/payroll/periods/detail.php?id=' . $res['period_id']);
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$st = db()->prepare('SELECT p.*,
        (SELECT COUNT(*) FROM payroll_items i WHERE i.payroll_period_id = p.id) AS item_count,
        (SELECT COALESCE(SUM(i.net_salary),0) FROM payroll_items i WHERE i.payroll_period_id = p.id) AS total_net
    FROM payroll_periods p
    WHERE p.company_id = ? AND (? = 0 OR p.period_year = ?)
    ORDER BY p.period_year DESC, p.period_month DESC');
$st->bind_param('iii', $cid, $year, $year);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$years = [];
for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--) {
    $years[] = $y;
}

$active = 'payroll';
$title  = 'Payroll';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Payroll</h4>
        <form method="get" action="" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0" <?= $year === 0 ? 'selected' : '' ?>>Semua tahun</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (can('payroll.manage')): ?>
    <div class="card shadow-sm border-0 mb-3"><div class="card-body">
        <h6 class="fw-bold">Generate Periode</h6>
        <form method="post" action="" class="row g-2 align-items-end" onsubmit="return confirm('Buat periode payroll ini?')">
            <?= csrf_field() ?>
            <div class="col-md-3">
                <label class="form-label small mb-1">Bulan</label>
                <select name="period_month" class="form-select form-select-sm" required>
                    <?php foreach (range(1, 12) as $m): ?>
                        <option value="<?= $m ?>" <?= $m === (int) date('n') ? 'selected' : '' ?>><?= e(payroll_month_name($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Tahun</label>
                <input type="number" name="period_year" class="form-control form-control-sm" min="2000" max="2100"
                       value="<?= (int) date('Y') ?>" required>
            </div>
            <div class="col-md-4">
                <div class="form-text mt-0">Satu item per karyawan aktif, gaji dari grade jabatan.</div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100" type="submit">Generate</button>
            </div>
        </form>
    </div></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Periode</th><th>Status</th><th class="text-end">Karyawan</th>
                    <th class="text-end">Total Gaji Bersih</th><th>Dibuat</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e(payroll_period_label($r)) ?></td>
                        <td><?= payroll_status_badge($r['status']) ?></td>
                        <td class="text-end"><?= (int) $r['item_count'] ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_net'])) ?></td>
                        <td class="small text-muted"><?= e((string) $r['created_date']) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/payroll/periods/detail.php?id=<?= (int) $r['id'] ?>" title="Detail" aria-label="Detail"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada periode payroll<?= $year ? " untuk tahun $year" : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
