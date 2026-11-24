<?php
/**
 * Penugasan Komponen Gaji Karyawan Terpusat (`employee_salary_components`).
 * Mendukung filter lintas karyawan, masa berlaku (effective_from - effective_to),
 * penutupan otomatis penugasan sebelumnya, serta CRUD lengkap.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.view');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

if (!is_super_admin() && active_company_id() === null) {
    require_active_company();
}
payroll_ensure_salary_schema();

$canManage    = can('payroll.manage');
$compScope    = company_filter_scope('company_id');
$filter_cid   = (int) $compScope['filter_company_id'];
$emp_filter   = (int) ($_GET['employee_id'] ?? 0);
$comp_filter  = (int) ($_GET['component_id'] ?? 0);
$status_filt  = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status_filt, ['active', 'upcoming', 'expired'], true)) {
    $status_filt = '';
}
$editId       = (int) ($_GET['edit_id'] ?? 0);
$errors       = [];

$activeCid    = active_company_id();
$formCid      = $filter_cid > 0 ? $filter_cid : ($activeCid ?? 0);
if (!is_super_admin()) {
    $formCid = require_active_company();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        include __DIR__ . '/../../../includes/403.php';
        exit;
    }
    verify_csrf();
    $act = (string) ($_POST['act'] ?? 'save');

    try {
        if ($act === 'delete') {
            $delId  = (int) ($_POST['assign_id'] ?? 0);
            $delRow = payroll_assignment_find($delId);
            if (!$delRow) {
                throw new RuntimeException('Penugasan komponen gaji tidak ditemukan.');
            }
            $delCid = (int) $delRow['company_id'];
            assert_company_access($delCid);
            payroll_assignment_delete($delId, $delCid);
            audit('employee_salary_component.delete', "Hapus penugasan #{$delId} ({$delRow['component_name']}) milik {$delRow['employee_name']}");
            flash('success', 'Penugasan komponen gaji berhasil dihapus.');
        } else {
            $aid     = (int) ($_POST['assign_id'] ?? 0);
            $empId   = (int) ($_POST['employee_id'] ?? 0);
            $scId    = (int) ($_POST['salary_component_id'] ?? 0);
            $amount  = payroll_parse_money($_POST['amount'] ?? '0');
            $from    = (string) ($_POST['effective_from'] ?? '');
            $to      = trim((string) ($_POST['effective_to'] ?? ''));
            $note    = trim((string) ($_POST['note'] ?? ''));
            $autoCls = !empty($_POST['auto_close_prev']);

            $stE = db()->prepare('SELECT company_id, name FROM employees WHERE id = ? LIMIT 1');
            $stE->bind_param('i', $empId);
            $stE->execute();
            $empInfo = $stE->get_result()->fetch_assoc();
            $stE->close();
            if (!$empInfo) {
                throw new RuntimeException('Pilih karyawan terlebih dahulu.');
            }
            $targetCid = (int) $empInfo['company_id'];
            assert_company_access($targetCid);

            $savedId = payroll_assignment_save(
                $aid,
                $targetCid,
                $empId,
                $scId,
                $amount,
                $from,
                $to !== '' ? $to : null,
                $note !== '' ? $note : null,
                $_SESSION['username'] ?? null,
                $autoCls
            );
            audit($aid === 0 ? 'employee_salary_component.create' : 'employee_salary_component.update', "Assign #{$savedId} komponen #{$scId} untuk {$empInfo['name']} (" . rupiah($amount) . ')');
            flash('success', $aid === 0 ? 'Komponen gaji berhasil di-assign ke karyawan.' : 'Penugasan komponen gaji berhasil diperbarui.');
        }
        $qs = array_filter([
            'company_id'   => is_super_admin() && $filter_cid > 0 ? $filter_cid : null,
            'employee_id'  => $emp_filter > 0 ? $emp_filter : null,
            'component_id' => $comp_filter > 0 ? $comp_filter : null,
            'status'       => $status_filt !== '' ? $status_filt : null,
        ], static fn($v): bool => $v !== null);
        redirect('pages/hr/salary_components/assignments.php' . ($qs ? '?' . http_build_query($qs) : ''));
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$scopedCid   = is_super_admin() ? ($filter_cid > 0 ? $filter_cid : null) : require_active_company();
$rows        = payroll_company_assignments($scopedCid, $emp_filter, $comp_filter, $status_filt);
$compOptions = payroll_component_options($scopedCid, true);

// Daftar karyawan untuk dropdown filter & assign
$empWhere  = ($scopedCid !== null && $scopedCid > 0) ? 'WHERE e.company_id = ? AND e.status = \'aktif\'' : 'WHERE e.status = \'aktif\'';
$stEmpOpt  = db()->prepare("SELECT e.id, e.code, e.name, e.company_id, co.code AS company_code FROM employees e LEFT JOIN companies co ON co.id = e.company_id {$empWhere} ORDER BY e.name ASC");
if ($scopedCid !== null && $scopedCid > 0) {
    $stEmpOpt->bind_param('i', $scopedCid);
}
$stEmpOpt->execute();
$empOptions = $stEmpOpt->get_result()->fetch_all(MYSQLI_ASSOC);
$stEmpOpt->close();

$editRow = null;
if ($editId > 0) {
    $editRow = payroll_assignment_find($editId);
    if ($editRow) {
        assert_company_access((int) $editRow['company_id']);
    }
}

$formVal = [
    'assign_id'           => $editRow ? (int) $editRow['id'] : 0,
    'employee_id'         => $editRow ? (int) $editRow['employee_id'] : ($emp_filter > 0 ? $emp_filter : 0),
    'salary_component_id' => $editRow ? (int) $editRow['salary_component_id'] : ($comp_filter > 0 ? $comp_filter : 0),
    'amount'              => $editRow ? (float) $editRow['amount'] : 0.0,
    'effective_from'      => $editRow ? (string) $editRow['effective_from'] : date('Y-m-d'),
    'effective_to'        => $editRow ? (string) ($editRow['effective_to'] ?? '') : '',
    'note'                => $editRow ? (string) ($editRow['note'] ?? '') : '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? 'save') === 'save') {
    $formVal = [
        'assign_id'           => (int) ($_POST['assign_id'] ?? 0),
        'employee_id'         => (int) ($_POST['employee_id'] ?? 0),
        'salary_component_id' => (int) ($_POST['salary_component_id'] ?? 0),
        'amount'              => payroll_parse_money($_POST['amount'] ?? '0'),
        'effective_from'      => (string) ($_POST['effective_from'] ?? ''),
        'effective_to'        => (string) ($_POST['effective_to'] ?? ''),
        'note'                => (string) ($_POST['note'] ?? ''),
    ];
}

$active = 'salary_assignments';
$title  = 'Penugasan Komponen Gaji';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        'Penugasan Komponen Gaji',
        ['Dashboard' => 'pages/dashboard/index.php', 'Master HR' => null, 'Penugasan Komponen' => null],
        [['text' => count($rows) . ' Penugasan', 'class' => 'bg-light text-secondary border']],
        [
            ['label' => 'Master Komponen', 'icon' => 'sliders', 'href' => 'pages/hr/salary_components/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
        ],
        'Atur komponen gaji, masa berlaku, dan nominal untuk setiap karyawan'
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-plus-circle me-1 text-primary"></i><?= $formVal['assign_id'] > 0 ? 'Ubah Penugasan Komponen #' . $formVal['assign_id'] : 'Assign Komponen Gaji ke Karyawan' ?></span>
            <?php if ($formVal['assign_id'] > 0): ?>
                <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/assignments.php" class="btn btn-sm btn-outline-secondary">Batal Ubah</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="save">
                <input type="hidden" name="assign_id" value="<?= (int) $formVal['assign_id'] ?>">

                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold mb-1">Karyawan <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select form-select-sm ts-select" required>
                        <option value="">— Pilih Karyawan —</option>
                        <?php foreach ($empOptions as $eo): ?>
                            <option value="<?= (int) $eo['id'] ?>"<?= (int) $eo['id'] === $formVal['employee_id'] ? ' selected' : '' ?>>
                                <?= e($eo['code'] . ' — ' . $eo['name'] . (is_super_admin() && $filter_cid === 0 ? ' [' . ($eo['company_code'] ?? '') . ']' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold mb-1">Komponen Gaji <span class="text-danger">*</span></label>
                    <select name="salary_component_id" class="form-select form-select-sm ts-select" required>
                        <option value="">— Pilih Komponen —</option>
                        <?php foreach ($compOptions as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= (int) $co['id'] === $formVal['salary_component_id'] ? ' selected' : '' ?>>
                                <?= e($co['code'] . ' — ' . $co['name'] . ' (' . ($co['category'] === 'deduction' ? 'Potongan' : 'Pendapatan') . ' · ' . ($co['calc_basis'] === 'daily' ? 'Harian' : 'Bulanan') . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small fw-semibold mb-1">Nominal (Rp) <span class="text-danger">*</span></label>
                    <input type="text" inputmode="numeric" name="amount" class="form-control form-control-sm font-monospace" required
                           value="<?= e(number_format((float) $formVal['amount'], 0, ',', '.')) ?>">
                </div>

                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold mb-1">Berlaku Mulai <span class="text-danger">*</span></label>
                    <input type="text" name="effective_from" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto required
                           placeholder="dd-MMM-yyyy" value="<?= e($formVal['effective_from']) ?>">
                </div>

                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold mb-1">Berlaku Sampai</label>
                    <input type="text" name="effective_to" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto
                           placeholder="Tanpa batas" value="<?= e($formVal['effective_to']) ?>">
                </div>

                <div class="col-12 col-md-6 col-lg-5">
                    <label class="form-label small fw-semibold mb-1">Catatan</label>
                    <input type="text" name="note" class="form-control form-control-sm" maxlength="500" placeholder="Keterangan SK / penyesuaian gaji (opsional)"
                           value="<?= e($formVal['note']) ?>">
                </div>

                <div class="col-12 col-md-6 col-lg-5">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="auto_close_prev" id="asgAutoClose" value="1"<?= $formVal['assign_id'] === 0 ? ' checked' : '' ?>>
                        <label class="form-check-label small" for="asgAutoClose">Tutup otomatis penugasan terbuka sebelumnya pada komponen yang sama (H-1)</label>
                    </div>
                </div>

                <div class="col-12 col-lg-2 text-lg-end">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-check2-circle me-1"></i><?= $formVal['assign_id'] > 0 ? 'Perbarui' : 'Assign' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-end erp-filter-bar erp-filter-bar-wide">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Karyawan</label>
                    <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Semua Karyawan</option>
                        <?php foreach ($empOptions as $eo): ?>
                            <option value="<?= (int) $eo['id'] ?>"<?= $emp_filter === (int) $eo['id'] ? ' selected' : '' ?>>
                                <?= e($eo['code'] . ' — ' . $eo['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Komponen</label>
                    <select name="component_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Semua Komponen</option>
                        <?php foreach ($compOptions as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $comp_filter === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e($co['code'] . ' — ' . $co['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Masa Berlaku</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <option value="active"<?= $status_filt === 'active' ? ' selected' : '' ?>>Aktif</option>
                        <option value="upcoming"<?= $status_filt === 'upcoming' ? ' selected' : '' ?>>Akan Datang</option>
                        <option value="expired"<?= $status_filt === 'expired' ? ' selected' : '' ?>>Berakhir</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/hr/salary_components/assignments.php', ($emp_filter > 0 || $comp_filter > 0 || $status_filt !== '' || (is_super_admin() && $filter_cid > 0))) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if (is_super_admin() && $filter_cid === 0): ?><th>PT</th><?php endif; ?>
                        <th>Karyawan</th>
                        <th>Komponen</th>
                        <th>Jenis &amp; Basis</th>
                        <th class="text-end">Nominal</th>
                        <th>Masa Berlaku</th>
                        <th>Status</th>
                        <?php if ($canManage): ?><th class="text-end">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $fromLbl = date('d-M-Y', strtotime((string) $r['effective_from']));
                    $toLbl   = !empty($r['effective_to']) ? date('d-M-Y', strtotime((string) $r['effective_to'])) : 'Selamanya';
                    ?>
                    <tr>
                        <?php if (is_super_admin() && $filter_cid === 0): ?>
                            <td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td>
                        <?php endif; ?>
                        <td>
                            <a href="<?= e(APP_URL) ?>/pages/employees/detail.php?id=<?= (int) $r['employee_id'] ?>&amp;tab=salary" class="fw-semibold text-decoration-none">
                                <?= e((string) $r['employee_name']) ?>
                            </a>
                            <div class="small text-muted font-monospace"><?= e((string) $r['employee_code']) ?></div>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e((string) $r['component_name']) ?></div>
                            <div class="small text-muted font-monospace"><?= e((string) $r['component_code']) ?></div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <?= payroll_component_category_badge((string) $r['category']) ?>
                                <?= payroll_component_basis_badge((string) $r['calc_basis']) ?>
                            </div>
                        </td>
                        <td class="text-end fw-semibold font-monospace"><?= e(rupiah((float) $r['amount'])) ?></td>
                        <td class="small text-nowrap">
                            <?= e($fromLbl) ?> <span class="text-muted">s/d</span> <?= e($toLbl) ?>
                            <?php if (!empty($r['note'])): ?>
                                <div class="text-muted text-wrap" style="max-width: 240px;"><?= e((string) $r['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= payroll_assignment_status_badge((string) $r['eff_status']) ?></td>
                        <?php if ($canManage): ?>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/assignments.php?edit_id=<?= (int) $r['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus penugasan komponen gaji ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="delete">
                                <input type="hidden" name="assign_id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= (is_super_admin() && $filter_cid === 0 ? 8 : 7) ?>" class="text-center text-muted py-4">Belum ada penugasan komponen gaji.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
