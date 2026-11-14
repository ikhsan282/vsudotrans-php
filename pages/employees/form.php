<?php
/** Form karyawan (create/edit). Edit wajib assert_company_access; company_id create dari active_company_id(). */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
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

$errors = [];
$input = $row ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mapped = employee_map_input($_POST);
    $mapped['id'] = $id;
    // Create: dari perusahaan aktif. Edit: perusahaan baris (sudah lolos assert_company_access).
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
// Cabang milik perusahaan baris (edit, termasuk Super Admin lintas perusahaan) atau perusahaan aktif (create).
$branches = employee_branch_options($row ? (int) $row['company_id'] : active_company_id());
$title = $id > 0 ? ('Ubah Karyawan - ' . $v('name')) : 'Tambah Karyawan';
$active = 'employees';
require __DIR__ . '/../../includes/header.php';

// Select dari master `job_grades` (gaji pokok + uang harian payroll).
$grade_opts = [];
try {
    $emp_cid = $row ? (int) $row['company_id'] : active_company_id();
    if ($emp_cid !== null) {
        $st_grade = db()->prepare('SELECT name FROM job_grades WHERE is_active = 1 AND company_id = ? ORDER BY name');
        $st_grade->bind_param('i', $emp_cid);
        $st_grade->execute();
        $grade_opts = $st_grade->get_result()->fetch_all(MYSQLI_ASSOC);
        $st_grade->close();
    } else {
        $grade_opts = db()->query('SELECT name FROM job_grades WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    }
} catch (mysqli_sql_exception $e) {
    $grade_opts = [];
}
$grade_val = $v('job_grade');
$grade_old = $grade_val !== '' && !in_array($grade_val, array_column($grade_opts, 'name'), true);

$bank_list = master_bank_options();
$cur_bank = (string) $v('bank_name');
$matched_bank = false;

$empActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => $id > 0 ? 'pages/employees/detail.php?id=' . $id : 'pages/employees/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($id > 0) {
    $empActions[] = ['label' => 'Lihat Detail', 'icon' => 'eye', 'href' => 'pages/employees/detail.php?id=' . $id, 'class' => 'btn btn-outline-secondary btn-sm'];
}
$empActions[] = ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formEmployee', 'class' => 'btn btn-primary btn-sm px-3'];
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

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="employeeFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-empf-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-empf-detail" type="button" role="tab">Detail</button>
            </li>
            <?php if ($id > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-empf-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-empf-audit" type="button" role="tab">Audit Trail</button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <form method="post" id="formEmployee" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="tab-content" id="employeeFormTabContent">
                    <div class="tab-pane fade show active" id="tab-empf-detail" role="tabpanel" aria-labelledby="tab-empf-detail-btn">
                        <div class="row g-3 mb-2">
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span>Data Pokok &amp; Kepegawaian</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kode (NIK) <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <input name="code" class="form-control form-control-sm font-monospace" maxlength="15" required value="<?= e($v('code')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Nama Lengkap <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <input name="name" class="form-control form-control-sm" maxlength="255" required value="<?= e($v('name')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Cabang</label>
                                            <div class="rnt-field">
                                                <select name="branch_id" class="form-select form-select-sm ts-select">
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
                                                <select name="status" class="form-select form-select-sm">
                                                    <option value="aktif" <?= $v('status') !== 'resign' ? 'selected' : '' ?>>Aktif</option>
                                                    <option value="resign" <?= $v('status') === 'resign' ? 'selected' : '' ?>>Resign</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tanggal Masuk <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <input type="date" name="join_date" class="form-control form-control-sm" required value="<?= e($v('join_date')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row mb-0">
                                            <label class="rnt-label">Tanggal Resign</label>
                                            <div class="rnt-field">
                                                <input type="date" name="resignation_date" class="form-control form-control-sm" value="<?= e($v('resignation_date')) ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span>Jabatan, Kontak &amp; Rekening Bank</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Jabatan (title)</label>
                                            <div class="rnt-field">
                                                <input name="job_title" class="form-control form-control-sm" maxlength="100" value="<?= e($v('job_title')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Posisi</label>
                                            <div class="rnt-field">
                                                <input name="job_position" class="form-control form-control-sm" maxlength="100" value="<?= e($v('job_position')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Grade</label>
                                            <div class="rnt-field">
                                                <select name="job_grade" class="form-select form-select-sm">
                                                    <option value="">— pilih —</option>
                                                    <?php foreach ($grade_opts as $g): ?>
                                                        <option value="<?= e($g['name']) ?>" <?= $g['name'] === $grade_val ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <?php if ($grade_old): ?>
                                                        <option value="<?= e($grade_val) ?>" selected><?= e($grade_val) ?> (tidak terdaftar)</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Email</label>
                                            <div class="rnt-field">
                                                <input type="email" name="email" class="form-control form-control-sm" maxlength="200" value="<?= e($v('email')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">No. Telepon</label>
                                            <div class="rnt-field">
                                                <input name="phone_number" class="form-control form-control-sm" maxlength="20" placeholder="62812xxxxxxxx" value="<?= e($v('phone_number')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Nama Bank</label>
                                            <div class="rnt-field">
                                                <select name="bank_name" class="form-select form-select-sm ts-select">
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
                                                <input name="account_number" class="form-control form-control-sm font-monospace" maxlength="50" value="<?= e($v('account_number')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row mb-0">
                                            <label class="rnt-label">Atas Nama</label>
                                            <div class="rnt-field">
                                                <input name="account_name" class="form-control form-control-sm" maxlength="100" value="<?= e($v('account_name')) ?>">
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
                                                <input name="id_number" class="form-control form-control-sm font-monospace" maxlength="30" value="<?= e($v('id_number')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">No. KK</label>
                                            <div class="rnt-field">
                                                <input name="family_number" class="form-control form-control-sm font-monospace" maxlength="30" value="<?= e($v('family_number')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">NPWP</label>
                                            <div class="rnt-field">
                                                <input name="tax_number" class="form-control form-control-sm font-monospace" maxlength="30" value="<?= e($v('tax_number')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tempat Lahir</label>
                                            <div class="rnt-field">
                                                <input name="birth_place" class="form-control form-control-sm" maxlength="100" value="<?= e($v('birth_place')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tanggal Lahir</label>
                                            <div class="rnt-field">
                                                <input type="date" name="birth_date" class="form-control form-control-sm" value="<?= e($v('birth_date')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Jenis Kelamin</label>
                                            <div class="rnt-field">
                                                <select name="gender" class="form-select form-select-sm">
                                                    <option value="">—</option>
                                                    <option value="L" <?= $v('gender') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                                    <option value="P" <?= $v('gender') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Agama</label>
                                            <div class="rnt-field">
                                                <input name="religion" class="form-control form-control-sm" maxlength="30" value="<?= e($v('religion')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Status Perkawinan</label>
                                            <div class="rnt-field">
                                                <input name="marital_status" class="form-control form-control-sm" maxlength="30" value="<?= e($v('marital_status')) ?>">
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start mb-0">
                                            <label class="rnt-label pt-1">Alamat</label>
                                            <div class="rnt-field">
                                                <textarea name="address" class="form-control form-control-sm rnt-ta-compact" rows="2" maxlength="500"><?= e($v('address')) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span>SIM Driver &amp; Berkas (Azure Blob Storage)</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row align-items-start">
                                            <label class="rnt-label pt-1" for="sim_due">Jatuh Tempo SIM</label>
                                            <div class="rnt-field">
                                                <input type="date" id="sim_due" name="sim_due" class="form-control form-control-sm" value="<?= e($v('sim_due')) ?>">
                                                <div class="form-text small">Wajib dipantau untuk pengemudi / driver.</div>
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start">
                                            <label class="rnt-label pt-1" for="photo_file">Pas Foto Karyawan</label>
                                            <div class="rnt-field">
                                                <input type="file" id="photo_file" name="photo_file" class="form-control form-control-sm mb-1" accept="image/*">
                                                <input type="hidden" name="photo" value="<?= e($v('photo')) ?>">
                                                <?php if ($v('photo') !== ''): ?>
                                                    <a href="<?= e(resolve_asset_url($v('photo'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-image"></i> Lihat Pas Foto</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start">
                                            <label class="rnt-label pt-1" for="ktp_file">Dokumen KTP</label>
                                            <div class="rnt-field">
                                                <input type="file" id="ktp_file" name="ktp_file" class="form-control form-control-sm mb-1" accept="image/*,application/pdf">
                                                <input type="hidden" name="ktp_doc_path" value="<?= e($v('ktp_doc_path')) ?>">
                                                <?php if ($v('ktp_doc_path') !== ''): ?>
                                                    <a href="<?= e(resolve_asset_url($v('ktp_doc_path'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-file-earmark-person"></i> Lihat Berkas KTP</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start mb-0">
                                            <label class="rnt-label pt-1" for="sim_file">Dokumen SIM Driver</label>
                                            <div class="rnt-field">
                                                <input type="file" id="sim_file" name="sim_file" class="form-control form-control-sm mb-1" accept="image/*,application/pdf">
                                                <input type="hidden" name="sim_doc_path" value="<?= e($v('sim_doc_path')) ?>">
                                                <?php if ($v('sim_doc_path') !== ''): ?>
                                                    <a href="<?= e(resolve_asset_url($v('sim_doc_path'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-card-heading"></i> Lihat Berkas SIM</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($id > 0): ?>
                        <div class="tab-pane fade" id="tab-empf-audit" role="tabpanel" aria-labelledby="tab-empf-audit-btn">
                            <?= ui_erp_audit_panel($row) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
