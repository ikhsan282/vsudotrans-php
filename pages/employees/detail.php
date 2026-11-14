<?php
/** Detail karyawan. Baca satu baris wajib assert_company_access. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.view');

$id = (int) ($_GET['id'] ?? 0);
$row = $id > 0 ? employee_find($id) : null;
if (!$row) {
    http_response_code(404);
    exit('Karyawan tidak ditemukan.');
}
assert_company_access((int) $row['company_id']);

$branch_name = '';
if ($row['branch_id']) {
    $stmt = db()->prepare('SELECT name FROM branches WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $row['branch_id'], $row['company_id']);
    $stmt->execute();
    $branch_name = (string) $stmt->get_result()->fetch_column();
    $stmt->close();
}

$active = 'employees';
$title = 'Detail Karyawan - ' . $row['name'];
require __DIR__ . '/../../includes/header.php';

$genderLabel = $row['gender'] === 'L' ? 'Laki-laki' : ($row['gender'] === 'P' ? 'Perempuan' : '-');
$statusHtml = '<span class="badge bg-' . ($row['status'] === 'aktif' ? 'success' : 'secondary') . '">'
    . e(ucfirst((string) $row['status'])) . '</span>';
$actions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/employees/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if (can('employees.manage')) {
    $actions[] = ['label' => 'Ubah Karyawan', 'icon' => 'pencil-square', 'href' => 'pages/employees/form.php?id=' . (int) $row['id'], 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Karyawan',
        'person-badge',
        (string) $row['name'],
        [
            ['text' => (string) $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => 'ID #' . (int) $row['id'], 'class' => 'bg-light text-secondary border'],
            ['text' => ucfirst((string) $row['status']), 'class' => 'bg-' . ($row['status'] === 'aktif' ? 'success' : 'secondary') . '-subtle text-' . ($row['status'] === 'aktif' ? 'success' : 'secondary') . ' border'],
        ],
        $actions,
        !empty($row['job_title']) ? 'Jabatan: <strong>' . e($row['job_title']) . '</strong>' : ''
    ) ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="employeeDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-emp-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-emp-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-emp-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-emp-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="employeeDetailTabContent">
                <div class="tab-pane fade show active" id="tab-emp-detail" role="tabpanel" aria-labelledby="tab-emp-detail-btn">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Data Pokok &amp; Kepegawaian</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Kode (NIK)', $row['code'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Nama Lengkap', $row['name'] ?? '') ?>
                                    <?= ui_erp_ro_row('Cabang', $branch_name) ?>
                                    <?= ui_erp_ro_row('Jabatan', $row['job_title'] ?? '') ?>
                                    <?= ui_erp_ro_row('Posisi', $row['job_position'] ?? '') ?>
                                    <?= ui_erp_ro_row('Grade', $row['job_grade'] ?? '') ?>
                                    <?= ui_erp_ro_row('Tanggal Masuk', $row['join_date'] ?? '') ?>
                                    <?= ui_erp_ro_row('Tanggal Resign', $row['resignation_date'] ?? '') ?>
                                    <?= ui_erp_ro_html_row('Status', $statusHtml) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Kontak &amp; Rekening Bank</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Email', $row['email'] ?? '') ?>
                                    <?= ui_erp_ro_row('No. Telepon', $row['phone_number'] ?? '') ?>
                                    <?= ui_erp_ro_row('Nama Bank', $row['bank_name'] ?? '') ?>
                                    <?= ui_erp_ro_row('No. Rekening', $row['account_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Atas Nama', $row['account_name'] ?? '') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-0">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Identitas &amp; Data Pribadi</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('No. KTP', $row['id_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('No. KK', $row['family_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('NPWP', $row['tax_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Tempat Lahir', $row['birth_place'] ?? '') ?>
                                    <?= ui_erp_ro_row('Tanggal Lahir', $row['birth_date'] ?? '') ?>
                                    <?= ui_erp_ro_row('Jenis Kelamin', $genderLabel) ?>
                                    <?= ui_erp_ro_row('Agama', $row['religion'] ?? '') ?>
                                    <?= ui_erp_ro_row('Status Perkawinan', $row['marital_status'] ?? '') ?>
                                    <?= ui_erp_ro_row('Alamat', $row['address'] ?? '', true) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>SIM &amp; Berkas Dokumen (Azure Blob)</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Jatuh Tempo SIM', $row['sim_due'] ?? '') ?>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Pas Foto</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($row['photo'])): ?>
                                                <div class="d-flex align-items-center gap-2 p-2 border rounded-1 bg-light">
                                                    <a href="<?= e(resolve_asset_url($row['photo'])) ?>" target="_blank">
                                                        <img src="<?= e(resolve_asset_url($row['photo'])) ?>" alt="Pas Foto" style="max-height: 64px; max-width: 64px; object-fit: cover;" class="rounded border bg-white">
                                                    </a>
                                                    <a href="<?= e(resolve_asset_url($row['photo'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-image me-1"></i>Lihat Pas Foto
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Dokumen KTP</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($row['ktp_doc_path'])): ?>
                                                <a href="<?= e(resolve_asset_url($row['ktp_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-file-earmark-person me-1"></i>Lihat / Unduh KTP
                                                </a>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label">Dokumen SIM</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($row['sim_doc_path'])): ?>
                                                <a href="<?= e(resolve_asset_url($row['sim_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-card-heading me-1"></i>Lihat / Unduh SIM
                                                </a>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-emp-audit" role="tabpanel" aria-labelledby="tab-emp-audit-btn">
                    <?= ui_erp_audit_panel($row) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
