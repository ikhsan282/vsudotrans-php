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

$fields = [
    'Kode (NIK)' => $row['code'], 'Nama' => $row['name'], 'Cabang' => $branch_name,
    'Email' => $row['email'], 'No. Telepon' => $row['phone_number'],
    'Tanggal Masuk' => $row['join_date'], 'Tanggal Resign' => $row['resignation_date'],
    'Jabatan' => $row['job_title'], 'Posisi' => $row['job_position'], 'Grade' => $row['job_grade'],
    'Nama Bank' => $row['bank_name'], 'No. Rekening' => $row['account_number'], 'Atas Nama' => $row['account_name'],
    'No. KTP' => $row['id_number'], 'No. KK' => $row['family_number'], 'NPWP' => $row['tax_number'],
    'Tanggal Lahir' => $row['birth_date'], 'Tempat Lahir' => $row['birth_place'],
    'Jenis Kelamin' => $row['gender'] === 'L' ? 'Laki-laki' : ($row['gender'] === 'P' ? 'Perempuan' : null),
    'Agama' => $row['religion'], 'Status Perkawinan' => $row['marital_status'],
    'Alamat' => $row['address'], 'Status' => $row['status'],
];

$active = 'employees';
$title = 'Detail Karyawan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
<?= ui_page_header(
    $row['name'],
    ['Dashboard' => 'pages/dashboard/index.php', 'Karyawan' => 'pages/employees/index.php', 'Detail ' . $row['name'] => null],
    [
        ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
        ['text' => 'ID #' . (int) $row['id'], 'class' => 'bg-light text-secondary border'],
        ['text' => ucfirst((string) $row['status']), 'class' => 'bg-' . ($row['status'] === 'aktif' ? 'success' : 'secondary') . '-subtle text-' . ($row['status'] === 'aktif' ? 'success' : 'secondary') . ' border'],
    ],
    array_merge(
        can('employees.manage') ? [['label' => 'Ubah Karyawan', 'icon' => 'pencil-square', 'href' => 'pages/employees/form.php?id=' . (int) $row['id'], 'class' => 'btn btn-sm btn-primary']] : [],
        [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/employees/index.php']]
    ),
    !empty($row['job_title']) ? 'Jabatan: ' . e($row['job_title']) : ''
) ?>
<div class="card shadow-sm">
    <div class="card-body">
        <dl class="row mb-0">
            <?php foreach ($fields as $label => $value): ?>
                <dt class="col-sm-3 text-muted fw-normal"><?= e($label) ?></dt>
                <dd class="col-sm-9"><?= $value !== null && $value !== '' ? e((string) $value) : '<span class="text-muted">—</span>' ?></dd>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">Kembali</a>
        <?php if (can('employees.manage')): ?>
            <a href="form.php?id=<?= $id ?>" class="btn btn-primary">Ubah</a>
        <?php endif; ?>
    </div>
</div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
