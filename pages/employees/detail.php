<?php
/**
 * Detail & Form karyawan (create/edit/view-only) + Tab Komponen Gaji bermasa berlaku.
 * Edit/Create data pokok wajib employees.manage; view wajib employees.view;
 * tab Komponen Gaji wajib payroll.view (kelola: payroll.manage).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.view');

$canManage        = can('employees.manage');
$canSeeSalary     = can('payroll.view');
$canManagePayroll = can('payroll.manage');
if ($canSeeSalary) {
    require_once __DIR__ . '/../../includes/payroll_service.php';
    payroll_ensure_salary_schema();
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id === 0 && !$canManage) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$row = null;
if ($id > 0) {
    $row = employee_find($id);
    if (!$row) {
        http_response_code(404);
        exit('Karyawan tidak ditemukan.');
    }
    assert_company_access((int) $row['company_id']);
} else {
    employee_scope_cid();
}

$errors    = [];
$sc_errors = [];
$input     = $row ?? [];
$sc_input  = [
    'assign_id'           => 0,
    'salary_component_id' => 0,
    'amount'              => 0.0,
    'effective_from'      => date('Y-m-d'),
    'effective_to'        => '',
    'note'                => '',
];

// Handler POST khusus tab Komponen Gaji (save / delete assignment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sc_action'])) {
    verify_csrf();
    if (!$canManagePayroll || $id <= 0 || !$row) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
    $emp_cid_sc = (int) $row['company_id'];
    assert_company_access($emp_cid_sc);
    $scAct = (string) $_POST['sc_action'];

    try {
        if ($scAct === 'delete') {
            $aid = (int) ($_POST['assign_id'] ?? 0);
            payroll_assignment_delete($aid, $emp_cid_sc);
            audit('employee_salary_component.delete', "Hapus komponen gaji #{$aid} karyawan #{$id}");
            flash('success', 'Komponen gaji karyawan berhasil dihapus.');
        } else {
            $aid     = (int) ($_POST['assign_id'] ?? 0);
            $scId    = (int) ($_POST['salary_component_id'] ?? 0);
            $amt     = payroll_parse_money($_POST['amount'] ?? '0');
            $from    = (string) ($_POST['effective_from'] ?? '');
            $to      = trim((string) ($_POST['effective_to'] ?? ''));
            $noteSc  = trim((string) ($_POST['note'] ?? ''));
            $autoCls = !empty($_POST['auto_close_prev']);

            $savedAid = payroll_assignment_save(
                $aid,
                $emp_cid_sc,
                $id,
                $scId,
                $amt,
                $from,
                $to !== '' ? $to : null,
                $noteSc !== '' ? $noteSc : null,
                $_SESSION['username'] ?? null,
                $autoCls
            );
            audit($aid === 0 ? 'employee_salary_component.create' : 'employee_salary_component.update', "Assign komponen #{$scId} (assign #{$savedAid}) karyawan #{$id} (" . rupiah($amt) . ')');
            flash('success', $aid === 0 ? 'Komponen gaji berhasil di-assign ke karyawan.' : 'Komponen gaji berhasil diperbarui.');
        }
        redirect('pages/employees/detail.php?id=' . $id . '&tab=salary');
    } catch (Throwable $ex) {
        $sc_errors[] = $ex->getMessage();
        $sc_input = [
            'assign_id'           => (int) ($_POST['assign_id'] ?? 0),
            'salary_component_id' => (int) ($_POST['salary_component_id'] ?? 0),
            'amount'              => payroll_parse_money($_POST['amount'] ?? '0'),
            'effective_from'      => (string) ($_POST['effective_from'] ?? ''),
            'effective_to'        => (string) ($_POST['effective_to'] ?? ''),
            'note'                => (string) ($_POST['note'] ?? ''),
        ];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }

    $mapped = employee_map_input($_POST);
    $mapped['id'] = $id;
    $mapped['company_id'] = $row ? (int) $row['company_id'] : active_company_id();
    $errors = employee_validate($mapped);
    $input = $_POST;

    if (!$errors) {
        try {
            $emp_cid = (int) $mapped['company_id'];
            $code_slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $mapped['data']['code'])) ?: 'emp';
            if (!empty($_FILES['photo_file']['tmp_name'])) {
                $up_photo = azure_blob_handle_upload('photo_file', $emp_cid, 'employees', $code_slug, 'photo', false, 5);
                if ($up_photo !== null) {
                    $mapped['data']['photo'] = $up_photo;
                }
            }
            if (!empty($_FILES['ktp_file']['tmp_name'])) {
                $up_ktp = azure_blob_handle_upload('ktp_file', $emp_cid, 'employees', $code_slug, 'ktp', true, 5);
                if ($up_ktp !== null) {
                    $mapped['data']['ktp_doc_path'] = $up_ktp;
                }
            }
            if (!empty($_FILES['sim_file']['tmp_name'])) {
                $up_sim = azure_blob_handle_upload('sim_file', $emp_cid, 'employees', $code_slug, 'sim', true, 5);
                if ($up_sim !== null) {
                    $mapped['data']['sim_doc_path'] = $up_sim;
                }
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $saved_id = employee_save($id, $mapped);
        if ($id === 0) {
            audit('employee.create', "Tambah karyawan {$mapped['data']['code']} — {$mapped['data']['name']} (id {$saved_id})");
            flash('success', 'Karyawan berhasil ditambahkan.');
        } else {
            audit('employee.update', "Ubah karyawan {$mapped['data']['code']} — {$mapped['data']['name']} (id {$saved_id})");
            flash('success', 'Data karyawan berhasil diperbarui.');
        }
        redirect('pages/employees/detail.php?id=' . $saved_id);
    }
}

$v = fn(string $k): string => (string) ($input[$k] ?? '');
$roAttr = $canManage ? '' : ' readonly';
$roCls  = $canManage ? '' : ' bg-light';
$disAttr = $canManage ? '' : ' disabled';

// Cabang & Master HR milik perusahaan baris (edit/detail) atau perusahaan aktif (create).
$emp_cid  = $row ? (int) $row['company_id'] : active_company_id();
$branches = employee_branch_options($emp_cid);
$hr_opts  = employee_hr_options($emp_cid);
$title_opts = $hr_opts['job_titles'];
$pos_opts   = $hr_opts['job_positions'];
$org_opts   = $hr_opts['organization_structures'];

$cur_title = $v('job_title');
$cur_pos   = $v('job_position');
$cur_org   = $v('organization_structure');
$title_unlisted = $cur_title !== '' && !in_array($cur_title, array_column($title_opts, 'name'), true);
$pos_unlisted   = $cur_pos !== '' && !in_array($cur_pos, array_column($pos_opts, 'name'), true);
$org_unlisted   = $cur_org !== '' && !in_array($cur_org, array_column($org_opts, 'name'), true);

// Data Tab Komponen Gaji (khusus existing employee & payroll.view)
$scStatusFilter = '';
$scRows         = [];
$scCompOpts     = [];
$scTodayEff     = ['components' => [], 'monthly_earning' => 0.0, 'daily_earning' => 0.0, 'monthly_deduction' => 0.0, 'daily_deduction' => 0.0];
if ($id > 0 && $row && $canSeeSalary) {
    $scStatusFilter = trim((string) ($_GET['sc_status'] ?? ''));
    if (!in_array($scStatusFilter, ['active', 'upcoming', 'expired'], true)) {
        $scStatusFilter = '';
    }
    $scRows     = payroll_employee_assignments($id, (int) $row['company_id'], $scStatusFilter);
    $scCompOpts = payroll_component_options((int) $row['company_id'], true);
    $scTodayEff = payroll_employee_effective_components($id, (int) $row['company_id'], date('Y-m-d'), date('Y-m-d'));

    $editScId = (int) ($_GET['edit_sc'] ?? 0);
    if ($editScId > 0 && $canManagePayroll && !$sc_errors) {
        $editScRow = payroll_assignment_find($editScId);
        if ($editScRow && (int) $editScRow['employee_id'] === $id && (int) $editScRow['company_id'] === (int) $row['company_id']) {
            $sc_input = [
                'assign_id'           => (int) $editScRow['id'],
                'salary_component_id' => (int) $editScRow['salary_component_id'],
                'amount'              => (float) $editScRow['amount'],
                'effective_from'      => (string) $editScRow['effective_from'],
                'effective_to'        => (string) ($editScRow['effective_to'] ?? ''),
                'note'                => (string) ($editScRow['note'] ?? ''),
            ];
        }
    }
}

$activeTab = ($id > 0 && $canSeeSalary && (($_GET['tab'] ?? '') === 'salary' || $sc_errors !== [])) ? 'salary' : 'detail';

$title = $id > 0 ? ('Karyawan - ' . $v('name')) : 'Tambah Karyawan';
$active = 'employees';
require __DIR__ . '/../../includes/header.php';

$bank_list = master_bank_options();
$cur_bank = (string) $v('bank_name');
$matched_bank = false;

$empActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/employees/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($canManage) {
    $empActions[] = ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formEmployee', 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Karyawan',
        'person-badge',
        $id > 0 ? (string) $v('name') : 'Tambah Karyawan Baru',
        $id > 0 ? array_values(array_filter([
            !empty($v('code')) ? ['text' => $v('code'), 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            !empty($v('status')) ? ['text' => ucfirst($v('status')), 'class' => 'bg-' . ($v('status') === 'aktif' ? 'success' : 'secondary') . '-subtle text-' . ($v('status') === 'aktif' ? 'success' : 'secondary') . ' border'] : null,
        ])) : [['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border']],
        $empActions
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($sc_errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2">
            <ul class="mb-0 ps-3">
                <?php foreach ($sc_errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="employeeFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'detail' ? ' active' : '' ?>" id="tab-empf-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-empf-detail" type="button" role="tab">Detail</button>
            </li>
            <?php if ($id > 0 && $canSeeSalary): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?= $activeTab === 'salary' ? ' active' : '' ?>" id="tab-empf-salary-btn" data-bs-toggle="tab" data-bs-target="#tab-empf-salary" type="button" role="tab">
                        Komponen Gaji
                        <?php if ($scTodayEff['components']): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"><?= count($scTodayEff['components']) ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            <?php endif; ?>
            <?php if ($id > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-empf-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-empf-audit" type="button" role="tab">Audit Trail</button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="employeeFormTabContent">
                <div class="tab-pane fade<?= $activeTab === 'detail' ? ' show active' : '' ?>" id="tab-empf-detail" role="tabpanel" aria-labelledby="tab-empf-detail-btn">
                    <?php if ($canManage): ?>
                    <form method="post" action="<?= e(APP_URL) ?>/pages/employees/detail.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formEmployee" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                    <?php endif; ?>

                    <div class="row g-3 mb-2">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Data Pokok &amp; Kepegawaian</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kode (NIK) <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <input name="code" class="form-control form-control-sm font-monospace<?= $roCls ?>" maxlength="15" required value="<?= e($v('code')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Nama Lengkap <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <input name="name" class="form-control form-control-sm<?= $roCls ?>" maxlength="255" required value="<?= e($v('name')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Cabang</label>
                                        <div class="rnt-field">
                                            <select name="branch_id" class="form-select form-select-sm ts-select<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">— Tanpa cabang —</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?= (int) $b['id'] ?>" <?= (string) $b['id'] === $v('branch_id') ? 'selected' : '' ?>>
                                                        <?= e($b['code'] . ' — ' . $b['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Status</label>
                                        <div class="rnt-field">
                                            <select name="status" class="form-select form-select-sm<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="aktif" <?= $v('status') !== 'resign' ? 'selected' : '' ?>>Aktif</option>
                                                <option value="resign" <?= $v('status') === 'resign' ? 'selected' : '' ?>>Resign</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Tanggal Masuk <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="text" name="join_date" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" required value="<?= e($v('join_date')) ?>">
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light" value="<?= $v('join_date') !== '' ? e(date('d-M-Y', strtotime($v('join_date')))) : '—' ?>" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label">Tanggal Resign</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="text" name="resignation_date" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" value="<?= e($v('resignation_date')) ?>">
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light" value="<?= $v('resignation_date') !== '' ? e(date('d-M-Y', strtotime($v('resignation_date')))) : '—' ?>" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Jabatan, Struktur, Kontak &amp; Rekening Bank</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">Struktur Organisasi</label>
                                        <div class="rnt-field">
                                            <select name="organization_structure" id="empOrgStructure" class="form-select form-select-sm<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">— Pilih Struktur Organisasi —</option>
                                                <?php foreach ($org_opts as $org): ?>
                                                    <option value="<?= e($org['name']) ?>" <?= $org['name'] === $cur_org ? 'selected' : '' ?>>
                                                        <?= e($org['code'] . ' — ' . $org['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php if ($org_unlisted): ?>
                                                    <option value="<?= e($cur_org) ?>" selected><?= e($cur_org) ?> (belum terdaftar di Master HR)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Posisi</label>
                                        <div class="rnt-field">
                                            <select name="job_position" id="empJobPosition" class="form-select form-select-sm<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">— Pilih Posisi —</option>
                                                <?php foreach ($pos_opts as $jp): ?>
                                                    <option value="<?= e($jp['name']) ?>"
                                                            data-job-title="<?= e((string) ($jp['job_title_name'] ?? '')) ?>"
                                                            <?= $jp['name'] === $cur_pos ? 'selected' : '' ?>>
                                                        <?= e($jp['code'] . ' — ' . $jp['name'] . (!empty($jp['job_title_name']) ? ' (' . $jp['job_title_name'] . ')' : '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php if ($pos_unlisted): ?>
                                                    <option value="<?= e($cur_pos) ?>" selected><?= e($cur_pos) ?> (belum terdaftar di Master HR)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Jabatan</label>
                                        <div class="rnt-field">
                                            <select name="job_title" id="empJobTitle" class="form-select form-select-sm<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">— Pilih Jabatan —</option>
                                                <?php foreach ($title_opts as $jt): ?>
                                                    <option value="<?= e($jt['name']) ?>"
                                                            <?= $jt['name'] === $cur_title ? 'selected' : '' ?>>
                                                        <?= e($jt['code'] . ' — ' . $jt['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php if ($title_unlisted): ?>
                                                    <option value="<?= e($cur_title) ?>" selected><?= e($cur_title) ?> (belum terdaftar di Master HR)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Email</label>
                                        <div class="rnt-field">
                                            <input type="email" name="email" class="form-control form-control-sm<?= $roCls ?>" maxlength="200" value="<?= e($v('email')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">No. Telepon</label>
                                        <div class="rnt-field">
                                            <input name="phone_number" class="form-control form-control-sm<?= $roCls ?>" maxlength="20" placeholder="62812xxxxxxxx" value="<?= e($v('phone_number')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Nama Bank</label>
                                        <div class="rnt-field">
                                            <select name="bank_name" class="form-select form-select-sm ts-select<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">— Pilih Bank —</option>
                                                <?php foreach ($bank_list as $bk):
                                                    $is_sel = ($cur_bank === $bk['name'] || $cur_bank === $bk['code'] || $cur_bank === ($bk['code'] . ' — ' . $bk['name']));
                                                    if ($is_sel) $matched_bank = true;
                                                ?>
                                                    <option value="<?= e($bk['name']) ?>" <?= $is_sel ? 'selected' : '' ?>><?= e($bk['code'] . ' — ' . $bk['name']) ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($cur_bank !== '' && !$matched_bank): ?>
                                                    <option value="<?= e($cur_bank) ?>" selected><?= e($cur_bank) ?> (Bank Lain)</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">No. Rekening</label>
                                        <div class="rnt-field">
                                            <input name="account_number" class="form-control form-control-sm font-monospace<?= $roCls ?>" maxlength="50" value="<?= e($v('account_number')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label">Atas Nama</label>
                                        <div class="rnt-field">
                                            <input name="account_name" class="form-control form-control-sm<?= $roCls ?>" maxlength="100" value="<?= e($v('account_name')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-0">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Identitas &amp; Data Pribadi</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">No. KTP</label>
                                        <div class="rnt-field">
                                            <input name="id_number" class="form-control form-control-sm font-monospace<?= $roCls ?>" maxlength="30" value="<?= e($v('id_number')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">No. KK</label>
                                        <div class="rnt-field">
                                            <input name="family_number" class="form-control form-control-sm font-monospace<?= $roCls ?>" maxlength="30" value="<?= e($v('family_number')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">NPWP</label>
                                        <div class="rnt-field">
                                            <input name="tax_number" class="form-control form-control-sm font-monospace<?= $roCls ?>" maxlength="30" value="<?= e($v('tax_number')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Tempat Lahir</label>
                                        <div class="rnt-field">
                                            <input name="birth_place" class="form-control form-control-sm<?= $roCls ?>" maxlength="100" value="<?= e($v('birth_place')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Tanggal Lahir</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="text" name="birth_date" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" value="<?= e($v('birth_date')) ?>">
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light" value="<?= $v('birth_date') !== '' ? e(date('d-M-Y', strtotime($v('birth_date')))) : '—' ?>" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Jenis Kelamin</label>
                                        <div class="rnt-field">
                                            <select name="gender" class="form-select form-select-sm<?= $roCls ?>"<?= $disAttr ?>>
                                                <option value="">—</option>
                                                <option value="L" <?= $v('gender') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                                <option value="P" <?= $v('gender') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Agama</label>
                                        <div class="rnt-field">
                                            <input name="religion" class="form-control form-control-sm<?= $roCls ?>" maxlength="30" value="<?= e($v('religion')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Status Perkawinan</label>
                                        <div class="rnt-field">
                                            <input name="marital_status" class="form-control form-control-sm<?= $roCls ?>" maxlength="30" value="<?= e($v('marital_status')) ?>"<?= $roAttr ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start mb-0">
                                        <label class="rnt-label pt-1">Alamat</label>
                                        <div class="rnt-field">
                                            <textarea name="address" class="form-control form-control-sm rnt-ta-compact<?= $roCls ?>" rows="2" maxlength="500"<?= $roAttr ?>><?= e($v('address')) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>SIM Driver &amp; Berkas Karyawan</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1" for="sim_due">Jatuh Tempo SIM</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="text" id="sim_due" name="sim_due" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" value="<?= e($v('sim_due')) ?>">
                                            <?php else: ?>
                                                <input type="text" id="sim_due" class="form-control form-control-sm bg-light" value="<?= $v('sim_due') !== '' ? e(date('d-M-Y', strtotime($v('sim_due')))) : '—' ?>" readonly>
                                            <?php endif; ?>
                                            <div class="form-text small">Wajib dipantau untuk pengemudi / driver.</div>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1" for="photo_file">Pas Foto Karyawan</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="file" id="photo_file" name="photo_file" class="form-control form-control-sm mb-2" accept="image/*">
                                                <input type="hidden" name="photo" value="<?= e($v('photo')) ?>">
                                            <?php endif; ?>
                                            <?php if ($v('photo') !== ''): ?>
                                                <div class="p-2 border rounded-1 bg-light text-center">
                                                    <a href="<?= e(resolve_asset_url($v('photo'))) ?>" target="_blank">
                                                        <img src="<?= e(resolve_asset_url($v('photo'))) ?>" alt="Pas Foto" class="img-fluid rounded border bg-white" style="max-height: 130px;">
                                                    </a>
                                                    <div class="mt-1">
                                                        <a href="<?= e(resolve_asset_url($v('photo'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-image me-1"></i>Lihat Pas Foto</a>
                                                    </div>
                                                </div>
                                            <?php elseif (!$canManage): ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1" for="ktp_file">Dokumen KTP</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="file" id="ktp_file" name="ktp_file" class="form-control form-control-sm mb-2" accept="image/*,application/pdf">
                                                <input type="hidden" name="ktp_doc_path" value="<?= e($v('ktp_doc_path')) ?>">
                                            <?php endif; ?>
                                            <?php if ($v('ktp_doc_path') !== ''): ?>
                                                <div>
                                                    <a href="<?= e(resolve_asset_url($v('ktp_doc_path'))) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-file-earmark-person me-1"></i>Lihat / Unduh Berkas KTP
                                                    </a>
                                                </div>
                                            <?php elseif (!$canManage): ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start mb-0">
                                        <label class="rnt-label pt-1" for="sim_file">Dokumen SIM Driver</label>
                                        <div class="rnt-field">
                                            <?php if ($canManage): ?>
                                                <input type="file" id="sim_file" name="sim_file" class="form-control form-control-sm mb-2" accept="image/*,application/pdf">
                                                <input type="hidden" name="sim_doc_path" value="<?= e($v('sim_doc_path')) ?>">
                                            <?php endif; ?>
                                            <?php if ($v('sim_doc_path') !== ''): ?>
                                                <div>
                                                    <a href="<?= e(resolve_asset_url($v('sim_doc_path'))) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-card-heading me-1"></i>Lihat / Unduh Berkas SIM
                                                    </a>
                                                </div>
                                            <?php elseif (!$canManage): ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($canManage): ?>
                    </form>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var posSel = document.getElementById('empJobPosition');
                        var titleSel = document.getElementById('empJobTitle');
                        if (posSel && titleSel) {
                            posSel.addEventListener('change', function () {
                                var opt = posSel.options[posSel.selectedIndex];
                                var linkedTitle = opt ? (opt.getAttribute('data-job-title') || '') : '';
                                if (linkedTitle !== '') {
                                    for (var i = 0; i < titleSel.options.length; i++) {
                                        if (titleSel.options[i].value === linkedTitle) {
                                            titleSel.value = linkedTitle;
                                            break;
                                        }
                                    }
                                }
                            });
                        }
                    });
                    </script>
                    <?php endif; ?>
                </div>

                <?php if ($id > 0 && $canSeeSalary): ?>
                <div class="tab-pane fade<?= $activeTab === 'salary' ? ' show active' : '' ?>" id="tab-empf-salary" role="tabpanel" aria-labelledby="tab-empf-salary-btn">
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <div class="border rounded-2 p-2 px-3 bg-body-tertiary h-100">
                                <div class="small text-muted">Pendapatan Tetap / Bulan (Aktif)</div>
                                <div class="fs-5 fw-bold text-primary font-monospace"><?= e(rupiah($scTodayEff['monthly_earning'])) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded-2 p-2 px-3 bg-body-tertiary h-100">
                                <div class="small text-muted">Tarif Harian / Hari Hadir (Aktif)</div>
                                <div class="fs-5 fw-bold text-info font-monospace"><?= e(rupiah($scTodayEff['daily_earning'])) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded-2 p-2 px-3 bg-body-tertiary h-100 d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="small text-muted">Potongan Tetap / Bulan (Aktif)</div>
                                    <div class="fs-5 fw-bold text-danger font-monospace"><?= e(rupiah($scTodayEff['monthly_deduction'])) ?></div>
                                </div>
                                <a href="<?= e(APP_URL) ?>/pages/hr/salary_components/index.php" class="btn btn-sm btn-outline-secondary text-nowrap">
                                    <i class="bi bi-sliders me-1"></i>Master
                                </a>
                            </div>
                        </div>
                    </div>

                    <?php if ($canManagePayroll): ?>
                    <div class="rnt-panel mb-3">
                        <div class="rnt-panel-header">
                            <span><i class="bi bi-plus-circle me-1 text-primary"></i><?= $sc_input['assign_id'] > 0 ? 'Ubah Penugasan Komponen #' . $sc_input['assign_id'] : 'Assign Komponen Gaji' ?></span>
                            <?php if ($sc_input['assign_id'] > 0): ?>
                                <a href="<?= e(APP_URL) ?>/pages/employees/detail.php?id=<?= $id ?>&amp;tab=salary" class="btn btn-sm btn-outline-secondary py-0 px-2">Batal Ubah</a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <form method="post" action="<?= e(APP_URL) ?>/pages/employees/detail.php?id=<?= $id ?>&amp;tab=salary" id="formEmpSalaryComp" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="sc_action" value="save">
                                <input type="hidden" name="assign_id" value="<?= (int) $sc_input['assign_id'] ?>">

                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Komponen Gaji <span class="text-danger">*</span></label>
                                    <select name="salary_component_id" class="form-select form-select-sm" required>
                                        <option value="">— Pilih Komponen —</option>
                                        <?php foreach ($scCompOpts as $co): ?>
                                            <option value="<?= (int) $co['id'] ?>"<?= (int) $sc_input['salary_component_id'] === (int) $co['id'] ? ' selected' : '' ?>>
                                                <?= e($co['code'] . ' — ' . $co['name'] . ' (' . ($co['category'] === 'deduction' ? 'Potongan' : 'Pendapatan') . ' · ' . ($co['calc_basis'] === 'daily' ? 'Harian' : 'Bulanan') . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold mb-1">Nominal (Rp) <span class="text-danger">*</span></label>
                                    <input type="text" inputmode="numeric" name="amount" class="form-control form-control-sm font-monospace" required
                                           value="<?= e(number_format((float) $sc_input['amount'], 0, ',', '.')) ?>">
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Berlaku Mulai <span class="text-danger">*</span></label>
                                    <input type="text" name="effective_from" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto required
                                           placeholder="dd-MMM-yyyy" value="<?= e($sc_input['effective_from']) ?>">
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Berlaku Sampai</label>
                                    <input type="text" name="effective_to" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto
                                           placeholder="Tanpa batas" value="<?= e($sc_input['effective_to']) ?>">
                                </div>

                                <div class="col-12 col-md-5">
                                    <label class="form-label small fw-semibold mb-1">Catatan</label>
                                    <input type="text" name="note" class="form-control form-control-sm" maxlength="500" placeholder="Keterangan SK / penyesuaian (opsional)"
                                           value="<?= e($sc_input['note']) ?>">
                                </div>

                                <div class="col-12 col-md-5">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" name="auto_close_prev" id="empScAutoClose" value="1"<?= $sc_input['assign_id'] === 0 ? ' checked' : '' ?>>
                                        <label class="form-check-label small" for="empScAutoClose">Tutup otomatis masa berlaku sebelumnya pada komponen sama (H-1)</label>
                                    </div>
                                </div>

                                <div class="col-12 col-md-2 text-md-end">
                                    <button type="submit" class="btn btn-sm btn-primary w-100">
                                        <i class="bi bi-check2-circle me-1"></i><?= $sc_input['assign_id'] > 0 ? 'Perbarui' : 'Assign' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Riwayat &amp; Daftar Komponen Gaji</span>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach (['' => 'Semua', 'active' => 'Aktif', 'upcoming' => 'Akan Datang', 'expired' => 'Berakhir'] as $stKey => $stLbl):
                                    $isCur = $scStatusFilter === $stKey;
                                    $stHref = APP_URL . '/pages/employees/detail.php?id=' . $id . '&tab=salary' . ($stKey !== '' ? '&sc_status=' . $stKey : '');
                                ?>
                                    <a href="<?= e($stHref) ?>" class="btn btn-xs <?= $isCur ? 'btn-primary' : 'btn-outline-secondary' ?>" style="font-size:.75rem;padding:.15rem .5rem;"><?= e($stLbl) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="rnt-panel-body p-0">
                            <div class="table-responsive">
                                <table class="erp-grid-table">
                                    <thead>
                                        <tr>
                                            <th>Komponen</th>
                                            <th>Jenis</th>
                                            <th>Basis</th>
                                            <th class="text-end">Nominal</th>
                                            <th>Masa Berlaku</th>
                                            <th>Status</th>
                                            <th>Catatan</th>
                                            <?php if ($canManagePayroll): ?><th class="text-end">Aksi</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($scRows as $sr):
                                        $fromFmt = date('d-M-Y', strtotime((string) $sr['effective_from']));
                                        $toFmt   = !empty($sr['effective_to']) ? date('d-M-Y', strtotime((string) $sr['effective_to'])) : 'Selamanya';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= e((string) $sr['component_name']) ?></div>
                                                <div class="small text-muted font-monospace"><?= e((string) $sr['component_code']) ?></div>
                                            </td>
                                            <td><?= payroll_component_category_badge((string) $sr['category']) ?></td>
                                            <td><?= payroll_component_basis_badge((string) $sr['calc_basis']) ?></td>
                                            <td class="text-end fw-semibold font-monospace"><?= e(rupiah((float) $sr['amount'])) ?></td>
                                            <td class="small text-nowrap"><?= e($fromFmt) ?> <span class="text-muted">s/d</span> <?= e($toFmt) ?></td>
                                            <td><?= payroll_assignment_status_badge((string) $sr['eff_status']) ?></td>
                                            <td class="small text-muted"><?= e((string) ($sr['note'] ?? '—')) ?></td>
                                            <?php if ($canManagePayroll): ?>
                                            <td class="text-end text-nowrap">
                                                <a href="<?= e(APP_URL) ?>/pages/employees/detail.php?id=<?= $id ?>&amp;tab=salary&amp;edit_sc=<?= (int) $sr['id'] ?>"
                                                   class="btn btn-sm btn-outline-primary" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                                <form method="post" action="<?= e(APP_URL) ?>/pages/employees/detail.php?id=<?= $id ?>&amp;tab=salary" class="d-inline"
                                                      onsubmit="return confirm('Hapus penugasan komponen gaji <?= e($sr['component_name']) ?>?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="hidden" name="sc_action" value="delete">
                                                    <input type="hidden" name="assign_id" value="<?= (int) $sr['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$scRows): ?>
                                        <tr><td colspan="<?= $canManagePayroll ? 8 : 7 ?>" class="text-center text-muted py-4">Belum ada komponen gaji yang di-assign untuk karyawan ini.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="tab-empf-audit" role="tabpanel" aria-labelledby="tab-empf-audit-btn">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
