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
    <?= ui_page_header(
        $company['name'],
        ['Dashboard' => 'pages/dashboard/index.php', 'Perusahaan' => 'pages/companies/index.php', 'Detail ' . $company['name'] => null],
        [
            ['text' => $company['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => 'ID #' . (int) $company['id'], 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($company['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($company['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($company['is_active']) ? 'success' : 'secondary') . ' border'],
        ],
        array_merge(
            can('companies.manage') ? [['label' => 'Ubah Pengaturan', 'icon' => 'pencil-square', 'href' => 'pages/companies/form.php?id=' . (int) $company['id'], 'class' => 'btn btn-sm btn-primary']] : [],
            [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/companies/index.php']]
        ),
        !empty($company['group_name']) ? 'Grup: ' . e($company['group_name']) : ''
    ) ?>
    <div class="card shadow-sm border-0" style="max-width: 760px;">
        <dl class="row mb-0 card-body">
            <dt class="col-sm-3">Grup</dt><dd class="col-sm-9"><?= e($company['group_name']) ?></dd>
            <dt class="col-sm-3">Telepon</dt><dd class="col-sm-9"><?= e(trim($company['country_code'] . ' ' . $company['phone_number'])) ?></dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= e($company['email']) ?></dd>
            <dt class="col-sm-3">Website</dt><dd class="col-sm-9"><?= e($company['website']) ?></dd>
            <dt class="col-sm-3">Kota</dt><dd class="col-sm-9"><?= e($company['city']) ?></dd>
            <dt class="col-sm-3">Alamat</dt><dd class="col-sm-9"><?= nl2br(e($company['address'])) ?></dd>
            <dt class="col-sm-3">Catatan</dt><dd class="col-sm-9"><?= nl2br(e($company['note'])) ?></dd>
            <dt class="col-sm-3">Logo &amp; Stempel</dt>
            <dd class="col-sm-9">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <?php if (!empty($company['logo'])): ?>
                        <div>
                            <div class="small text-muted mb-1">Logo:</div>
                            <a href="<?= e(resolve_asset_url($company['logo'])) ?>" target="_blank">
                                <img src="<?= e(resolve_asset_url($company['logo'])) ?>" alt="Logo" style="max-height: 48px;" class="border rounded p-1 bg-light">
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($company['stamp'])): ?>
                        <div>
                            <div class="small text-muted mb-1">Stempel:</div>
                            <a href="<?= e(resolve_asset_url($company['stamp'])) ?>" target="_blank">
                                <img src="<?= e(resolve_asset_url($company['stamp'])) ?>" alt="Stempel" style="max-height: 48px;" class="border rounded p-1 bg-light">
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($company['logo']) && empty($company['stamp'])): ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= $company['is_active'] ? 'Aktif' : 'Nonaktif' ?></dd>
        </dl>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
