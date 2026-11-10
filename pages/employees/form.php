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
$title = $id > 0 ? 'Ubah Karyawan' : 'Tambah Karyawan';
$active = 'employees';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
<?= ui_page_header(
    $title,
    ['Dashboard' => 'pages/dashboard/index.php', 'Karyawan' => 'pages/employees/index.php', ($id > 0 ? $v('name') : 'Tambah Baru') => null],
    $id > 0 ? array_values(array_filter([
        !empty($v('code')) ? ['text' => $v('code'), 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
        ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
        !empty($v('status')) ? ['text' => ucfirst($v('status')), 'class' => 'bg-' . ($v('status') === 'aktif' ? 'success' : 'secondary') . '-subtle text-' . ($v('status') === 'aktif' ? 'success' : 'secondary') . ' border'] : null,
    ])) : [],
    [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/employees/index.php']]
) ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm" enctype="multipart/form-data">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row g-3">
            <h6 class="fw-bold text-muted mt-2 mb-0">Data Pokok</h6>
            <div class="col-md-3">
                <label class="form-label">Kode (NIK) <span class="text-danger">*</span></label>
                <input name="code" class="form-control" maxlength="15" required value="<?= e($v('code')) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Nama <span class="text-danger">*</span></label>
                <input name="name" class="form-control" maxlength="255" required value="<?= e($v('name')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Cabang</label>
                <select name="branch_id" class="form-select ts-select">
                    <option value="">— Tanpa cabang —</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (string) $b['id'] === $v('branch_id') ? 'selected' : '' ?>>
                            <?= e($b['code'] . ' — ' . $b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" maxlength="200" value="<?= e($v('email')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">No. Telepon</label>
                <input name="phone_number" class="form-control" maxlength="20" value="<?= e($v('phone_number')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="aktif" <?= $v('status') !== 'resign' ? 'selected' : '' ?>>Aktif</option>
                    <option value="resign" <?= $v('status') === 'resign' ? 'selected' : '' ?>>Resign</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tanggal Masuk <span class="text-danger">*</span></label>
                <input type="date" name="join_date" class="form-control" required value="<?= e($v('join_date')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Tanggal Resign</label>
                <input type="date" name="resignation_date" class="form-control" value="<?= e($v('resignation_date')) ?>">
            </div>

            <h6 class="fw-bold text-muted mt-3 mb-0">Jabatan</h6>
            <div class="col-md-4">
                <label class="form-label">Jabatan (title)</label>
                <input name="job_title" class="form-control" maxlength="100" value="<?= e($v('job_title')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Posisi</label>
                <input name="job_position" class="form-control" maxlength="100" value="<?= e($v('job_position')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Grade</label>
                <?php
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
                ?>
                <select name="job_grade" class="form-select">
                    <option value="">— pilih —</option>
                    <?php foreach ($grade_opts as $g): ?>
                        <option value="<?= e($g['name']) ?>" <?= $g['name'] === $grade_val ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($grade_old): ?>
                        <option value="<?= e($grade_val) ?>" selected><?= e($grade_val) ?> (tidak terdaftar)</option>
                    <?php endif; ?>
                </select>
            </div>

            <h6 class="fw-bold text-muted mt-3 mb-0">Rekening Bank</h6>
            <div class="col-md-4">
                <label class="form-label">Nama Bank</label>
                <input name="bank_name" class="form-control" maxlength="100" value="<?= e($v('bank_name')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">No. Rekening</label>
                <input name="account_number" class="form-control" maxlength="50" value="<?= e($v('account_number')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Atas Nama</label>
                <input name="account_name" class="form-control" maxlength="100" value="<?= e($v('account_name')) ?>">
            </div>

            <h6 class="fw-bold text-muted mt-3 mb-0">Identitas & Pribadi</h6>
            <div class="col-md-3">
                <label class="form-label">No. KTP</label>
                <input name="id_number" class="form-control" maxlength="30" value="<?= e($v('id_number')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">No. KK</label>
                <input name="family_number" class="form-control" maxlength="30" value="<?= e($v('family_number')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">NPWP</label>
                <input name="tax_number" class="form-control" maxlength="30" value="<?= e($v('tax_number')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Jenis Kelamin</label>
                <select name="gender" class="form-select">
                    <option value="">—</option>
                    <option value="L" <?= $v('gender') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                    <option value="P" <?= $v('gender') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tempat Lahir</label>
                <input name="birth_place" class="form-control" maxlength="100" value="<?= e($v('birth_place')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tanggal Lahir</label>
                <input type="date" name="birth_date" class="form-control" value="<?= e($v('birth_date')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Agama</label>
                <input name="religion" class="form-control" maxlength="30" value="<?= e($v('religion')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status Perkawinan</label>
                <input name="marital_status" class="form-control" maxlength="30" value="<?= e($v('marital_status')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Alamat</label>
                <textarea name="address" class="form-control" rows="2" maxlength="500"><?= e($v('address')) ?></textarea>
            </div>

            <h6 class="fw-bold text-muted mt-3 mb-0">Berkas &amp; Foto (Azure Blob Storage)</h6>
            <div class="col-md-4">
                <label class="form-label" for="photo_file">Pas Foto Karyawan</label>
                <input type="file" id="photo_file" name="photo_file" class="form-control mb-1" accept="image/*">
                <input type="hidden" name="photo" value="<?= e($v('photo')) ?>">
                <?php if ($v('photo') !== ''): ?>
                    <a href="<?= e(resolve_asset_url($v('photo'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-image"></i> Lihat Pas Foto</a>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="ktp_file">Dokumen KTP (Gambar/PDF)</label>
                <input type="file" id="ktp_file" name="ktp_file" class="form-control mb-1" accept="image/*,application/pdf">
                <input type="hidden" name="ktp_doc_path" value="<?= e($v('ktp_doc_path')) ?>">
                <?php if ($v('ktp_doc_path') !== ''): ?>
                    <a href="<?= e(resolve_asset_url($v('ktp_doc_path'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-file-earmark-person"></i> Lihat Berkas KTP</a>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="sim_file">Dokumen SIM Driver (Gambar/PDF)</label>
                <input type="file" id="sim_file" name="sim_file" class="form-control mb-1" accept="image/*,application/pdf">
                <input type="hidden" name="sim_doc_path" value="<?= e($v('sim_doc_path')) ?>">
                <?php if ($v('sim_doc_path') !== ''): ?>
                    <a href="<?= e(resolve_asset_url($v('sim_doc_path'))) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-card-heading"></i> Lihat Berkas SIM</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
        <a href="<?= $id > 0 ? 'detail.php?id=' . $id : 'index.php' ?>" class="btn btn-outline-secondary">Batal</a>
        <button type="submit" class="btn btn-primary">Simpan</button>
    </div>
</form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
