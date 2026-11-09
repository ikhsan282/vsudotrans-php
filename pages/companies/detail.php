<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.view');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT c.*, g.name AS group_name FROM companies c JOIN groups g ON g.id = c.group_id WHERE c.id = ?');
$st->bind_param('i', $id);
$st->execute();
$company = $st->get_result()->fetch_assoc();
$st->close();

if (!$company) {
    flash('error', 'Perusahaan tidak ditemukan.');
    redirect('pages/companies/index.php');
}
assert_company_access((int) $company['id']);

$active = 'companies';
$title  = 'Detail ' . $company['name'];
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <!-- Breadcrumb Navigasi -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0" style="font-size: 0.8rem;">
            <li class="breadcrumb-item"><a href="<?= e(APP_URL) ?>/pages/dashboard/index.php" class="text-decoration-none text-muted">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= e(APP_URL) ?>/pages/companies/index.php" class="text-decoration-none text-muted">Perusahaan</a></li>
            <li class="breadcrumb-item active text-dark" aria-current="page">Detail <?= e($company['name']) ?></li>
        </ol>
    </nav>

    <!-- Header Ringkas (Title Bar & Aksi) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h4 class="fw-bold mb-0 text-dark"><?= e($company['name']) ?></h4>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1">
                <?= e($company['code']) ?>
            </span>
            <span class="badge bg-light text-secondary border px-2 py-1">ID #<?= (int) $company['id'] ?></span>
            <span class="badge bg-<?= !empty($company['is_active']) ? 'success' : 'secondary' ?>-subtle text-<?= !empty($company['is_active']) ? 'success' : 'secondary' ?> border border-<?= !empty($company['is_active']) ? 'success' : 'secondary' ?>-subtle px-2 py-1">
                <?= !empty($company['is_active']) ? 'Aktif' : 'Nonaktif' ?>
            </span>
            <?php if (!empty($company['group_name'])): ?>
                <span class="text-muted small ms-1">&bull; Grup: <?= e($company['group_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (can('companies.manage')): ?>
                <a class="btn btn-sm btn-primary" href="<?= e(APP_URL) ?>/pages/companies/form.php?id=<?= (int) $company['id'] ?>">
                    <i class="bi bi-pencil-square me-1"></i> Ubah Pengaturan
                </a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/companies/index.php">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
            </a>
        </div>
    </div>
    <div class="card shadow-sm border-0" style="max-width: 760px;">
        <dl class="row mb-0 card-body">
            <dt class="col-sm-3">Grup</dt><dd class="col-sm-9"><?= e($company['group_name']) ?></dd>
            <dt class="col-sm-3">Telepon</dt><dd class="col-sm-9"><?= e(trim($company['country_code'] . ' ' . $company['phone_number'])) ?></dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= e($company['email']) ?></dd>
            <dt class="col-sm-3">Website</dt><dd class="col-sm-9"><?= e($company['website']) ?></dd>
            <dt class="col-sm-3">Kota</dt><dd class="col-sm-9"><?= e($company['city']) ?></dd>
            <dt class="col-sm-3">Alamat</dt><dd class="col-sm-9"><?= nl2br(e($company['address'])) ?></dd>
            <dt class="col-sm-3">Catatan</dt><dd class="col-sm-9"><?= nl2br(e($company['note'])) ?></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= $company['is_active'] ? 'Aktif' : 'Nonaktif' ?></dd>
        </dl>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
