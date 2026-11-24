<?php
/**
 * Master Komponen Gaji — Form Tambah / Ubah.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.manage');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

if (!is_super_admin() && active_company_id() === null) {
    require_active_company();
}
payroll_ensure_salary_schema();

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = null;
if ($id > 0) {
    $row = payroll_component_find($id);
    if (!$row) {
        flash('error', 'Komponen gaji tidak ditemukan.');
        redirect('pages/hr/salary_components/index.php');
    }
    if ($row['company_id'] !== null) {
        assert_company_access((int) $row['company_id']);
    } elseif (!is_super_admin()) {
        http_response_code(403);
        include __DIR__ . '/../../../includes/403.php';
        exit;
    }
}

$companies = accessible_companies();
$activeCid = active_company_id();
$errors    = [];
$input     = $row ?: [
    'company_id' => $activeCid,
    'code'       => '',
    'name'       => '',
    'category'   => 'earning',
    'calc_basis' => 'monthly',
    'note'       => '',
    'is_active'  => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $input = [
        'company_id' => is_super_admin()
            ? ((int) ($_POST['company_id'] ?? 0) > 0 ? (int) $_POST['company_id'] : null)
            : require_active_company(),
        'code'       => (string) ($_POST['code'] ?? ''),
        'name'       => (string) ($_POST['name'] ?? ''),
        'category'   => (string) ($_POST['category'] ?? 'earning'),
        'calc_basis' => (string) ($_POST['calc_basis'] ?? 'monthly'),
        'note'       => (string) ($_POST['note'] ?? ''),
        'is_active'  => isset($_POST['is_active']) ? 1 : 0,
    ];
    try {
        $targetCid = $row
            ? ($row['company_id'] !== null ? (int) $row['company_id'] : null)
            : $input['company_id'];
        $savedId = payroll_component_save($id, $input, $targetCid, $_SESSION['username'] ?? null);
        audit($id === 0 ? 'salary_component.create' : 'salary_component.update', "Komponen #{$savedId} {$input['code']} — {$input['name']}");
        flash('success', $id === 0 ? 'Komponen gaji berhasil ditambahkan.' : 'Komponen gaji berhasil diperbarui.');
        redirect('pages/hr/salary_components/index.php');
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$active = 'salary_components';
$title  = $id > 0 ? 'Ubah Komponen Gaji' : 'Tambah Komponen Gaji';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        $title,
        [
            'Dashboard' => 'pages/dashboard/index.php',
            'Master HR' => null,
            'Komponen Gaji' => 'pages/hr/salary_components/index.php',
            $id > 0 ? 'Ubah' : 'Tambah' => null,
        ],
        [],
        [
            ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/hr/salary_components/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
            ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formSalaryComponent', 'class' => 'btn btn-primary btn-sm px-3'],
        ]
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="formSalaryComponent" class="card shadow-sm border-0">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="card-body">
            <div class="row g-3">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Cakupan Perusahaan</label>
                    <?php if ($id > 0): ?>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e($row['company_id'] !== null ? ($row['company_code'] . ' — ' . $row['company_name']) : 'Semua Perusahaan (Global)') ?>" readonly>
                    <?php else: ?>
                        <select name="company_id" class="form-select form-select-sm">
                            <option value="0"<?= $input['company_id'] === null ? ' selected' : '' ?>>Semua Perusahaan (Global)</option>
                            <?php foreach ($companies as $co): ?>
                                <option value="<?= (int) $co['id'] ?>"<?= (int) ($input['company_id'] ?? 0) === (int) $co['id'] ? ' selected' : '' ?>>
                                    <?= e($co['code'] . ' — ' . $co['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">Kode Komponen <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control form-control-sm font-monospace text-uppercase" maxlength="20" required
                           placeholder="mis. TUNJ-KHS" value="<?= e((string) $input['code']) ?>">
                </div>

                <div class="col-12 col-md-5">
                    <label class="form-label small fw-semibold">Nama Komponen <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required
                           placeholder="mis. Tunjangan Khusus" value="<?= e((string) $input['name']) ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Jenis <span class="text-danger">*</span></label>
                    <select name="category" class="form-select form-select-sm" required>
                        <?php foreach (payroll_component_categories() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $input['category'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Basis Perhitungan <span class="text-danger">*</span></label>
                    <select name="calc_basis" class="form-select form-select-sm" required>
                        <?php foreach (payroll_component_bases() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $input['calc_basis'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3 d-flex align-items-end">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="is_active" id="scActive" value="1"<?= !empty($input['is_active']) ? ' checked' : '' ?>>
                        <label class="form-check-label small fw-medium" for="scActive">Komponen Aktif</label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold">Keterangan</label>
                    <textarea name="note" class="form-control form-control-sm" rows="2" maxlength="2000"><?= e((string) ($input['note'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent border-top py-2 px-3 d-flex justify-content-end gap-2">
            <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/index.php" class="btn btn-outline-secondary btn-sm">Batal</a>
            <button type="submit" class="btn btn-primary btn-sm px-3"><i class="bi bi-check2-circle me-1"></i>Simpan Komponen</button>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
