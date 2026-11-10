<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('vehicles.view');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT v.*, b.name AS brand_name, m.name AS model_name, e.name AS engine_name, c.name AS category_name, comp.name AS company_name
                     FROM vehicles v
                     JOIN brand_vehicles b ON b.id = v.brand_id
                     JOIN model_units m ON m.id = v.model_unit_id
                     JOIN type_engines e ON e.id = v.type_engine_id
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
$title  = 'Detail ' . $vehicle['vehicle_number'];
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        $vehicle['vehicle_number'],
        ['Dashboard' => 'pages/dashboard/index.php', 'Kendaraan' => 'pages/vehicles/index.php', 'Detail ' . $vehicle['vehicle_number'] => null],
        [
            ['text' => $vehicle['brand_name'] . ' ' . $vehicle['model_name'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle'],
            ['text' => 'ID #' . (int) $vehicle['id'], 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($vehicle['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($vehicle['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($vehicle['is_active']) ? 'success' : 'secondary') . ' border'],
        ],
        array_merge(
            can('vehicles.manage') ? [['label' => 'Ubah Unit', 'icon' => 'pencil-square', 'href' => 'pages/vehicles/form.php?id=' . (int) $vehicle['id'], 'class' => 'btn btn-sm btn-primary']] : [],
            [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/vehicles/index.php']]
        ),
        'Perusahaan: ' . e($vehicle['company_name'])
    ) ?>
    <div class="row g-3" style="max-width: 960px;">
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Spesifikasi &amp; Identitas Kendaraan</div>
                <dl class="row mb-0 card-body">
                    <dt class="col-sm-4">No. Polisi</dt><dd class="col-sm-8 fw-bold"><?= e($vehicle['vehicle_number']) ?></dd>
                    <dt class="col-sm-4">Merek &amp; Model</dt><dd class="col-sm-8"><?= e($vehicle['brand_name'] . ' — ' . $vehicle['model_name']) ?></dd>
                    <dt class="col-sm-4">Tipe Mesin</dt><dd class="col-sm-8"><?= e($vehicle['engine_name']) ?></dd>
                    <dt class="col-sm-4">Kategori</dt><dd class="col-sm-8"><?= e($vehicle['category_name']) ?></dd>
                    <dt class="col-sm-4">Tahun &amp; Warna</dt><dd class="col-sm-8"><?= (int) $vehicle['production_year'] ?> &middot; <?= e($vehicle['vehicle_color']) ?></dd>
                    <dt class="col-sm-4">Kapasitas Kursi</dt><dd class="col-sm-8"><?= (int) $vehicle['seat'] ?> Kursi</dd>
                    <dt class="col-sm-4">Kepemilikan</dt><dd class="col-sm-8"><?= e($vehicle['ownership']) ?></dd>
                    <dt class="col-sm-4">No. Rangka</dt><dd class="col-sm-8 font-monospace"><?= e($vehicle['frame_number']) ?></dd>
                    <dt class="col-sm-4">No. Mesin</dt><dd class="col-sm-8 font-monospace"><?= e($vehicle['machine_number']) ?></dd>
                    <dt class="col-sm-4">No. BPKB</dt><dd class="col-sm-8"><?= e($vehicle['bpkb_number'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Jatuh Tempo Pajak</dt><dd class="col-sm-8"><?= e($vehicle['tax_due'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Jatuh Tempo STNK</dt><dd class="col-sm-8"><?= e($vehicle['stnk_due'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Jatuh Tempo KIR</dt><dd class="col-sm-8"><?= e($vehicle['kir_due'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Catatan</dt><dd class="col-sm-8"><?= nl2br(e($vehicle['note'] ?: '—')) ?></dd>
                </dl>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Foto &amp; Dokumen Kendaraan (Azure Blob)</div>
                <div class="card-body">
                    <?php if (!empty($vehicle['photo_path'])): ?>
                        <div class="mb-3 text-center">
                            <a href="<?= e(resolve_asset_url($vehicle['photo_path'])) ?>" target="_blank">
                                <img src="<?= e(resolve_asset_url($vehicle['photo_path'])) ?>" alt="Foto Unit" class="img-fluid rounded border" style="max-height: 200px;">
                            </a>
                            <div class="small text-muted mt-1"><code><?= e($vehicle['photo_path']) ?></code></div>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small mb-3">Belum ada foto unit kendaraan.</div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <?php if (!empty($vehicle['stnk_doc_path'])): ?>
                            <a href="<?= e(resolve_asset_url($vehicle['stnk_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary text-start">
                                <i class="bi bi-file-earmark-text me-1"></i> Lihat / Unduh Dokumen STNK
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($vehicle['kir_doc_path'])): ?>
                            <a href="<?= e(resolve_asset_url($vehicle['kir_doc_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary text-start">
                                <i class="bi bi-file-earmark-text me-1"></i> Lihat / Unduh Dokumen KIR
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
