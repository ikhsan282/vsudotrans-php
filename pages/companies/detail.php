<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.view');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT c.*, g.name AS group_name FROM companies c JOIN groups g ON g.id = c.group_id WHERE c.id = ?');
$st->bind_param('i', $id);
$st->execute();
$c = $st->get_result()->fetch_assoc();
$st->close();

if (!$c) {
    flash('error', 'Perusahaan tidak ditemukan.');
    redirect('pages/companies/index.php');
}
assert_company_access((int) $c['id']);

$active = 'companies';
$title  = $c['name'];
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><?= e($c['name']) ?> <span class="text-muted small">(<?= e($c['code']) ?>)</span></h4>
        <div>
            <?php if (can('companies.manage')): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/companies/form.php?id=<?= (int) $c['id'] ?>">Ubah</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/companies/index.php">Kembali</a>
        </div>
    </div>
    <div class="card shadow-sm border-0" style="max-width: 760px;">
        <dl class="row mb-0 card-body">
            <dt class="col-sm-3">Grup</dt><dd class="col-sm-9"><?= e($c['group_name']) ?></dd>
            <dt class="col-sm-3">Telepon</dt><dd class="col-sm-9"><?= e(trim($c['country_code'] . ' ' . $c['phone_number'])) ?></dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= e($c['email']) ?></dd>
            <dt class="col-sm-3">Website</dt><dd class="col-sm-9"><?= e($c['website']) ?></dd>
            <dt class="col-sm-3">Kota</dt><dd class="col-sm-9"><?= e($c['city']) ?></dd>
            <dt class="col-sm-3">Alamat</dt><dd class="col-sm-9"><?= nl2br(e($c['address'])) ?></dd>
            <dt class="col-sm-3">Catatan</dt><dd class="col-sm-9"><?= nl2br(e($c['note'])) ?></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?></dd>
        </dl>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
