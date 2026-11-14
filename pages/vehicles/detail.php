<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('vehicles.view');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT v.*, b.name AS brand_name, m.name AS model_name, c.name AS category_name, comp.name AS company_name
                     FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     JOIN category_vehicles c ON c.id = v.category_vehicle_id
                     JOIN companies comp ON comp.id = v.company_id
                     WHERE v.id = ?');
$st->bind_param('i', $id);
$st->execute();
$vehicle = $st->get_result()->fetch_assoc();
$st->close();

if (!$vehicle) {
    flash('error', 'Kendaraan tidak ditemukan.');
    redirect('pages/vehicles/index.php');
}
assert_company_access((int) $vehicle['company_id']);

$active = 'vehicles';
$title  = 'Detail Kendaraan - ' . $vehicle['vehicle_number'];
require __DIR__ . '/../../includes/header.php';

$statusBadgeHtml = '<span class="badge bg-' . (!empty($vehicle['is_active']) ? 'success' : 'secondary') . '">'
    . (!empty($vehicle['is_active']) ? 'Aktif' : 'Nonaktif') . '</span>';
$actions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/vehicles/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if (can('vehicles.manage')) {
    $actions[] = ['label' => 'Ubah Unit', 'icon' => 'pencil-square', 'href' => 'pages/vehicles/form.php?id=' . (int) $vehicle['id'], 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Kendaraan',
        'truck',
        (string) $vehicle['vehicle_number'],
        [
            ['text' => $vehicle['brand_name'] . ' ' . $vehicle['model_name'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle'],
            ['text' => 'ID #' . (int) $vehicle['id'], 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($vehicle['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($vehicle['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($vehicle['is_active']) ? 'success' : 'secondary') . ' border'],
        ],
        $actions,
        'Perusahaan: <strong>' . e($vehicle['company_name']) . '</strong>'
    ) ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="vehicleDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-veh-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-veh-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-veh-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-veh-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="vehicleDetailTabContent">
                <div class="tab-pane fade show active" id="tab-veh-detail" role="tabpanel" aria-labelledby="tab-veh-detail-btn">
                    <div class="row g-3 mb-0">
                        <div class="col-12 col-lg-7">
                            <div class="rnt-panel">
                                <div class="rnt-panel-header"><span>Spesifikasi &amp; Identitas Kendaraan</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('No. Polisi', $vehicle['vehicle_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Merek & Model', ($vehicle['brand_name'] ?? '') . ' — ' . ($vehicle['model_name'] ?? '')) ?>
                                    <?= ui_erp_ro_row('Kategori', $vehicle['category_name'] ?? '') ?>
                                    <?= ui_erp_ro_row('Tahun & Warna', ((int) $vehicle['production_year']) . ' · ' . ($vehicle['vehicle_color'] ?? '')) ?>
                                    <?= ui_erp_ro_row('Kapasitas Kursi', ((int) $vehicle['seat']) . ' Kursi') ?>
                                    <?= ui_erp_ro_row('Kepemilikan', $vehicle['ownership'] ?? '') ?>
                                    <?= ui_erp_ro_html_row('Status', $statusBadgeHtml) ?>
                                </div>
                            </div>

                            <div class="rnt-panel mb-0">
                                <div class="rnt-panel-header"><span>Nomor Rangka, Legalitas &amp; Catatan</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('No. Rangka', $vehicle['frame_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('No. Mesin', $vehicle['machine_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('No. BPKB', $vehicle['bpkb_number'] ?? '', false, true) ?>
                                    <?= ui_erp_ro_row('Jatuh Tempo Pajak', $vehicle['tax_due'] ?? '') ?>
                                    <?= ui_erp_ro_row('Jatuh Tempo STNK', $vehicle['stnk_due'] ?? '') ?>
                                    <?= ui_erp_ro_row('Jatuh Tempo KIR', $vehicle['kir_due'] ?? '') ?>
                                    <?= ui_erp_ro_row('Catatan', $vehicle['note'] ?? '', true) ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-5">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Foto &amp; Dokumen Kendaraan (Azure Blob)</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Foto Unit</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($vehicle['photo_path'])): ?>
                                                <div class="p-2 border rounded-1 bg-light text-center">
                                                    <a href="<?= e(resolve_asset_url($vehicle['photo_path'])) ?>" target="_blank">
                                                        <img src="<?= e(resolve_asset_url($vehicle['photo_path'])) ?>" alt="Foto Unit" class="img-fluid rounded border bg-white" style="max-height: 190px;">
                                                    </a>
                                                    <div class="small text-muted mt-1 text-break"><code><?= e($vehicle['photo_path']) ?></code></div>
                                                </div>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum ada foto unit kendaraan" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Dokumen STNK</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($vehicle['stnk_doc_path'])): ?>
                                                <a href="<?= e(resolve_asset_url($vehicle['stnk_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-file-earmark-text me-1"></i>Lihat / Unduh Dokumen STNK
                                                </a>
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm bg-light text-muted" value="Belum diunggah" readonly>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label">Dokumen KIR</label>
                                        <div class="rnt-field">
                                            <?php if (!empty($vehicle['kir_doc_path'])): ?>
                                                <a href="<?= e(resolve_asset_url($vehicle['kir_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-file-earmark-text me-1"></i>Lihat / Unduh Dokumen KIR
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

                <div class="tab-pane fade" id="tab-veh-audit" role="tabpanel" aria-labelledby="tab-veh-audit-btn">
                    <?= ui_erp_audit_panel($vehicle) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
