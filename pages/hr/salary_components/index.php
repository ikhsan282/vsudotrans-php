<?php
/**
 * Master Komponen Gaji (`salary_components`) — list & filter.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.view');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

if (!is_super_admin() && active_company_id() === null) {
    require_active_company();
}
payroll_ensure_salary_schema();

$compScope  = company_filter_scope('company_id');
$filter_cid = (int) $compScope['filter_company_id'];
$q          = trim((string) ($_GET['q'] ?? ''));
$category   = trim((string) ($_GET['category'] ?? ''));
$basis      = trim((string) ($_GET['calc_basis'] ?? ''));
$statusRaw  = trim((string) ($_GET['is_active'] ?? ''));
$isActive   = $statusRaw === '1' ? 1 : ($statusRaw === '0' ? 0 : null);

$scopedCid  = is_super_admin() ? ($filter_cid > 0 ? $filter_cid : null) : require_active_company();
$rows       = payroll_component_list($scopedCid, $q, $category, $basis, $isActive);
$canManage  = can('payroll.manage');

$headerActions = [
    ['label' => 'Penugasan Komponen', 'icon' => 'person-check', 'href' => 'pages/hr/salary_components/assignments.php', 'class' => 'btn btn-outline-primary btn-sm'],
];
if ($canManage) {
    $headerActions[] = ['label' => 'Tambah Komponen', 'icon' => 'plus-lg', 'href' => 'pages/hr/salary_components/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'salary_components';
$title  = 'Komponen Gaji';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        'Komponen Gaji',
        ['Dashboard' => 'pages/dashboard/index.php', 'Master HR' => null, 'Komponen Gaji' => null],
        [['text' => count($rows) . ' Komponen', 'class' => 'bg-light text-secondary border']],
        $headerActions,
        'Master komponen pendapatan dan potongan yang dapat di-assign ke karyawan beserta masa berlakunya'
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-end erp-filter-bar erp-filter-bar-wide">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan (+ Global)</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-3 erp-filter-q">
                    <label class="form-label small mb-1">Pencarian</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Kode atau nama komponen..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Jenis</label>
                    <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Jenis</option>
                        <?php foreach (payroll_component_categories() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $category === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Basis</label>
                    <select name="calc_basis" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Basis</option>
                        <?php foreach (payroll_component_bases() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $basis === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="is_active" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <option value="1"<?= $statusRaw === '1' ? ' selected' : '' ?>>Aktif</option>
                        <option value="0"<?= $statusRaw === '0' ? ' selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/hr/salary_components/index.php', ($q !== '' || $category !== '' || $basis !== '' || $statusRaw !== '' || (is_super_admin() && $filter_cid > 0))) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if (is_super_admin() && $filter_cid === 0): ?><th>Cakupan</th><?php endif; ?>
                        <th>Kode</th>
                        <th>Nama Komponen</th>
                        <th>Jenis</th>
                        <th>Basis Hitung</th>
                        <th class="text-end">Karyawan Aktif</th>
                        <th>Status</th>
                        <?php if ($canManage): ?><th class="text-end">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $isGlobalRow = $r['company_id'] === null;
                    $canRowEdit  = $canManage && (!$isGlobalRow || is_super_admin());
                    ?>
                    <tr>
                        <?php if (is_super_admin() && $filter_cid === 0): ?>
                            <td>
                                <?php if ($isGlobalRow): ?>
                                    <span class="badge bg-dark-subtle text-dark-emphasis border">Global</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="font-monospace fw-semibold"><?= e((string) $r['code']) ?></td>
                        <td>
                            <div class="fw-medium"><?= e((string) $r['name']) ?></div>
                            <?php if (!empty($r['note'])): ?>
                                <div class="small text-muted"><?= e((string) $r['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= payroll_component_category_badge((string) $r['category']) ?></td>
                        <td><?= payroll_component_basis_badge((string) $r['calc_basis']) ?></td>
                        <td class="text-end">
                            <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/assignments.php?component_id=<?= (int) $r['id'] ?>" class="text-decoration-none fw-semibold">
                                <?= (int) ($r['active_assignments'] ?? 0) ?>
                            </a>
                            <span class="text-muted small">/ <?= (int) ($r['total_assignments'] ?? 0) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= !empty($r['is_active']) ? 'success' : 'secondary' ?>">
                                <?= !empty($r['is_active']) ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <?php if ($canManage): ?>
                        <td class="text-end text-nowrap">
                            <?php if ($canRowEdit): ?>
                                <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/form.php?id=<?= (int) $r['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/hr/salary_components/delete.php" class="d-inline"
                                      onsubmit="return confirm('Hapus komponen gaji <?= e($r['name']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"<?= ((int) ($r['total_assignments'] ?? 0)) > 0 ? ' disabled' : '' ?>>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small">Global</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= (is_super_admin() && $filter_cid === 0 ? 8 : 7) ?>" class="text-center text-muted py-4">Belum ada komponen gaji yang sesuai filter.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
