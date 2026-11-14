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

$canManage = can('companies.manage');
$activeTab = 'detail';
$active = 'companies';
$title  = 'Detail Perusahaan - ' . $company['name'];
require __DIR__ . '/../../includes/header.php';

$phoneDisp = trim((string) ($company['phone_number'] ?? ''));
$statusBadge = '<span class="badge bg-' . (!empty($company['is_active']) ? 'success' : 'secondary') . '">'
    . (!empty($company['is_active']) ? 'Aktif' : 'Nonaktif') . '</span>';
$websiteHtml = !empty($company['website'])
    ? '<a href="' . e($company['website']) . '" target="_blank" rel="noopener" class="text-decoration-none">'
        . '<i class="bi bi-box-arrow-up-right me-1"></i>' . e($company['website']) . '</a>'
    : '<span class="text-muted">-</span>';

$actions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/companies/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($canManage) {
    $actions[] = ['label' => 'Channel QRIS', 'icon' => 'qr-code-scan', 'href' => 'pages/companies/form.php?id=' . (int) $company['id'] . '&tab=payment', 'class' => 'btn btn-outline-secondary btn-sm'];
    $actions[] = ['label' => 'Bot WA', 'icon' => 'robot', 'href' => 'pages/companies/form.php?id=' . (int) $company['id'] . '&tab=hermes', 'class' => 'btn btn-outline-secondary btn-sm'];
    $actions[] = ['label' => 'Ubah Pengaturan', 'icon' => 'pencil-square', 'href' => 'pages/companies/form.php?id=' . (int) $company['id'], 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Perusahaan',
        'building',
        (string) $company['name'],
        [
            ['text' => (string) $company['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => 'ID #' . (int) $company['id'], 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($company['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($company['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($company['is_active']) ? 'success' : 'secondary') . ' border'],
        ],
        $actions,
        !empty($company['group_name']) ? 'Grup: <strong>' . e($company['group_name']) . '</strong>' : ''
    ) ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="companyDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-company-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-company-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-company-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-company-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="companyDetailTabContent">
                <div class="tab-pane fade show active" id="tab-company-detail" role="tabpanel" aria-labelledby="tab-company-detail-btn">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Informasi Perusahaan</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Kode Perusahaan', $company['code'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Nama Perusahaan', $company['name'] ?? '') ?>
                                    <?= ui_erp_ro_row('Grup Holding', $company['group_name'] ?? '') ?>
                                    <?= ui_erp_ro_row('Kota Operasional', $company['city'] ?? '') ?>
                                    <?= ui_erp_ro_row('Alamat Lengkap', $company['address'] ?? '', true) ?>
                                    <?= ui_erp_ro_html_row('Status', $statusBadge) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Kontak, Digital &amp; Catatan</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Telepon Kantor', $phoneDisp) ?>
                                    <?= ui_erp_ro_row('Email', $company['email'] ?? '') ?>
                                    <?= ui_erp_ro_html_row('Website', $websiteHtml) ?>
                                    <?= ui_erp_ro_row('Nomor Bot WA', $company['bot_phone'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Catatan Internal', $company['note'] ?? '', true) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Logo &amp; Stempel Perusahaan (Azure Blob)</span></div>
                        <div class="rnt-panel-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="rnt-row align-items-start mb-0">
                                        <label class="rnt-label pt-1">Logo Perusahaan</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($company['logo'])): ?>
                                                <div class="d-flex align-items-center gap-2 p-2 border rounded-1 bg-light">
                                                    <img src="<?= e(resolve_asset_url($company['logo'])) ?>" alt="Logo" style="max-height: 48px; max-width: 140px;" class="border rounded bg-white p-1">
                                                    <div class="small text-truncate">
                                                        <div><code class="small"><?= e($company['logo']) ?></code></div>
                                                        <a href="<?= e(resolve_asset_url($company['logo'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 mt-1">
                                                            <i class="bi bi-box-arrow-up-right me-1"></i>Lihat Logo
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="rnt-row align-items-start mb-0">
                                        <label class="rnt-label pt-1">Stempel / Cap</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($company['stamp'])): ?>
                                                <div class="d-flex align-items-center gap-2 p-2 border rounded-1 bg-light">
                                                    <img src="<?= e(resolve_asset_url($company['stamp'])) ?>" alt="Stempel" style="max-height: 48px; max-width: 140px;" class="border rounded bg-white p-1">
                                                    <div class="small text-truncate">
                                                        <div><code class="small"><?= e($company['stamp']) ?></code></div>
                                                        <a href="<?= e(resolve_asset_url($company['stamp'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 mt-1">
                                                            <i class="bi bi-box-arrow-up-right me-1"></i>Lihat Stempel
                                                        </a>
                                                    </div>
                                                </div>
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

                <div class="tab-pane fade" id="tab-company-audit" role="tabpanel" aria-labelledby="tab-company-audit-btn">
                    <?= ui_erp_audit_panel($company) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
