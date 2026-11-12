<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('vehicles.manage');

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cid = active_company_id();

$row = ['brand_id' => 0, 'model_unit_id' => 0, 'type_engine_id' => 0, 'category_vehicle_id' => 0,
        'frame_number' => '', 'machine_number' => '', 'seat' => 0, 'production_year' => (int) date('Y'),
        'vehicle_color' => '', 'vehicle_number' => '', 'bpkb_number' => '', 'tax_due' => null,
        'stnk_due' => null, 'kir_due' => null, 'ownership' => 'Internal', 'note' => '',
        'photo_path' => '', 'stnk_doc_path' => '', 'kir_doc_path' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM vehicles WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Kendaraan tidak ditemukan.');
        redirect('pages/vehicles/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $cid = (int) $found['company_id']; // edit: scope ke perusahaan milik kendaraan
    $row = $found;
} elseif ($cid === null) {
    flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
    redirect('pages/vehicles/index.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $row = [
        'brand_id'           => (int) ($_POST['brand_id'] ?? 0),
        'model_unit_id'      => (int) ($_POST['model_unit_id'] ?? 0),
        'type_engine_id'     => (int) ($_POST['type_engine_id'] ?? 0),
        'category_vehicle_id'=> (int) ($_POST['category_vehicle_id'] ?? 0),
        'frame_number'       => strtoupper($in('frame_number', 50)),
        'machine_number'     => strtoupper($in('machine_number', 50)),
        'vehicle_number'     => strtoupper(preg_replace('/\s+/', ' ', $in('vehicle_number', 15))),
        'production_year'    => (int) ($_POST['production_year'] ?? 0),
        'vehicle_color'      => $in('vehicle_color', 50),
        'seat'               => (int) ($_POST['seat'] ?? 0),
        'bpkb_number'        => $in('bpkb_number', 50),
        'tax_due'            => vehicle_date($_POST, 'tax_due'),
        'stnk_due'           => vehicle_date($_POST, 'stnk_due'),
        'kir_due'            => vehicle_date($_POST, 'kir_due'),
        'ownership'          => $in('ownership', 30),
        'note'               => $in('note', 2000),
        'photo_path'         => $in('photo_path', 255),
        'stnk_doc_path'      => $in('stnk_doc_path', 255),
        'kir_doc_path'       => $in('kir_doc_path', 255),
        'is_active'          => isset($_POST['is_active']) ? 1 : 0,
    ];
    $errors = vehicle_validate($row);
    if (!$errors) {
        $errors = vehicle_check_refs($db, $cid, $row);
    }

    if (!$errors) {
        try {
            $nopol_slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $row['vehicle_number'])) ?: 'unit';
            if (!empty($_FILES['photo_file']['tmp_name'])) {
                $up_photo = azure_blob_handle_upload('photo_file', $cid, 'vehicles', $nopol_slug, 'photo', false, 5);
                if ($up_photo !== null) {
                    $row['photo_path'] = $up_photo;
                }
            }
            if (!empty($_FILES['stnk_file']['tmp_name'])) {
                $up_stnk = azure_blob_handle_upload('stnk_file', $cid, 'vehicles', $nopol_slug, 'stnk', true, 5);
                if ($up_stnk !== null) {
                    $row['stnk_doc_path'] = $up_stnk;
                }
            }
            if (!empty($_FILES['kir_file']['tmp_name'])) {
                $up_kir = azure_blob_handle_upload('kir_file', $cid, 'vehicles', $nopol_slug, 'kir', true, 5);
                if ($up_kir !== null) {
                    $row['kir_doc_path'] = $up_kir;
                }
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $nul = fn (?string $v): ?string => ($v === null || $v === '') ? null : $v;
        $user = $_SESSION['username'] ?? null;
        try {
            if ($id > 0) {
                $st = $db->prepare('UPDATE vehicles SET brand_id = ?, model_unit_id = ?, type_engine_id = ?, category_vehicle_id = ?,
                    frame_number = ?, machine_number = ?, seat = ?, production_year = ?, vehicle_color = ?, vehicle_number = ?,
                    bpkb_number = ?, tax_due = ?, stnk_due = ?, kir_due = ?, ownership = ?, note = ?,
                    photo_path = ?, stnk_doc_path = ?, kir_doc_path = ?, is_active = ?,
                    modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
                $st->bind_param('iiii' . 'ss' . 'ii' . 'sss' . 'sss' . 'ss' . 'sss' . 'i' . 's' . 'ii',
                    $row['brand_id'], $row['model_unit_id'], $row['type_engine_id'], $row['category_vehicle_id'],
                    $row['frame_number'], $row['machine_number'], $row['seat'], $row['production_year'],
                    $row['vehicle_color'], $row['vehicle_number'], $nul($row['bpkb_number']),
                    $row['tax_due'], $row['stnk_due'], $row['kir_due'], $row['ownership'], $nul($row['note']),
                    $nul($row['photo_path']), $nul($row['stnk_doc_path']), $nul($row['kir_doc_path']),
                    $row['is_active'], $user, $id, $cid);
                $st->execute();
                $st->close();
                audit('vehicle.update', "Kendaraan #{$id} {$row['vehicle_number']}");
                flash('success', 'Kendaraan berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO vehicles (company_id, brand_id, model_unit_id, type_engine_id, category_vehicle_id,
                    frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, bpkb_number,
                    tax_due, stnk_due, kir_due, ownership, note, photo_path, stnk_doc_path, kir_doc_path, is_active, created_user)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('iiiii' . 'ss' . 'ii' . 'ss' . 'ssssss' . 'sss' . 'i' . 's',
                    $cid, $row['brand_id'], $row['model_unit_id'], $row['type_engine_id'], $row['category_vehicle_id'],
                    $row['frame_number'], $row['machine_number'], $row['seat'], $row['production_year'],
                    $row['vehicle_color'], $row['vehicle_number'], $nul($row['bpkb_number']),
                    $row['tax_due'], $row['stnk_due'], $row['kir_due'], $row['ownership'], $nul($row['note']),
                    $nul($row['photo_path']), $nul($row['stnk_doc_path']), $nul($row['kir_doc_path']),
                    $row['is_active'], $user);
                $st->execute();
                $new = $db->insert_id;
                $st->close();
                audit('vehicle.create', "Kendaraan #{$new} {$row['vehicle_number']}");
                flash('success', 'Kendaraan berhasil ditambahkan.');
            }
            redirect('pages/vehicles/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
            $errors[] = vehicle_dup_message($ex->getMessage());
        }
    }
}

$brands = vehicle_options($db, 'brand_vehicles', $cid, 0);
$engines = vehicle_options($db, 'type_engines', $cid, (int) $row['type_engine_id']);
$categories = vehicle_options($db, 'category_vehicles', $cid, (int) $row['category_vehicle_id']);
$st = $db->prepare('SELECT m.id, m.name, b.name AS brand_name FROM model_units m JOIN brand_vehicles b ON b.id = m.brand_id
                    WHERE m.company_id = ? AND (m.is_active = 1 OR m.id = ?) ORDER BY b.name, m.name');
$cur = (int) $row['model_unit_id'];
$st->bind_param('ii', $cid, $cur);
$st->execute();
$models = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'vehicles';
$title  = $id > 0 ? 'Ubah Kendaraan' : 'Tambah Kendaraan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        $title,
        ['Dashboard' => 'pages/dashboard/index.php', 'Kendaraan' => 'pages/vehicles/index.php', ($id > 0 ? $row['vehicle_number'] : 'Tambah Baru') => null],
        $id > 0 ? array_values(array_filter([
            !empty($row['vehicle_number']) ? ['text' => $row['vehicle_number'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            isset($row['is_active']) ? ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'] : null,
        ])) : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => $id > 0 ? 'pages/vehicles/detail.php?id=' . $id : 'pages/vehicles/index.php', 'class' => 'btn btn-sm btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formVehicle', 'class' => 'btn btn-sm btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 860px;">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formVehicle" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="brand_id">Merek <span class="text-danger">*</span></label>
                        <select class="form-select" id="brand_id" name="brand_id" required>
                            <option value="">— Pilih merek —</option>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $row['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="model_unit_id">Model <span class="text-danger">*</span></label>
                        <select class="form-select" id="model_unit_id" name="model_unit_id" required>
                            <option value="">— Pilih model —</option>
                            <?php foreach ($models as $m): ?>
                                <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $cur ? 'selected' : '' ?>><?= e($m['brand_name'] . ' — ' . $m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="type_engine_id">Tipe Mesin <span class="text-danger">*</span></label>
                        <select class="form-select" id="type_engine_id" name="type_engine_id" required>
                            <option value="">— Pilih tipe mesin —</option>
                            <?php foreach ($engines as $t): ?>
                                <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) $row['type_engine_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="category_vehicle_id">Kategori <span class="text-danger">*</span></label>
                        <select class="form-select" id="category_vehicle_id" name="category_vehicle_id" required>
                            <option value="">— Pilih kategori —</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $row['category_vehicle_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="vehicle_number">No. Polisi <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="vehicle_number" name="vehicle_number" maxlength="15" required value="<?= e($row['vehicle_number']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="frame_number">No. Rangka <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="frame_number" name="frame_number" maxlength="50" required value="<?= e($row['frame_number']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="machine_number">No. Mesin <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="machine_number" name="machine_number" maxlength="50" required value="<?= e($row['machine_number']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="production_year">Tahun Produksi <span class="text-danger">*</span></label>
                        <input class="form-control" type="number" id="production_year" name="production_year" min="1950" max="<?= (int) date('Y') + 1 ?>" required value="<?= (int) $row['production_year'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="vehicle_color">Warna <span class="text-danger">*</span></label>
                        <input class="form-control" id="vehicle_color" name="vehicle_color" maxlength="50" required value="<?= e($row['vehicle_color']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="seat">Kursi <span class="text-danger">*</span></label>
                        <input class="form-control" type="number" id="seat" name="seat" min="1" max="99" required value="<?= (int) $row['seat'] ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ownership">Kepemilikan <span class="text-danger">*</span></label>
                        <select class="form-select" id="ownership" name="ownership" required>
                            <?php foreach (VEHICLE_OWNERSHIP as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $row['ownership'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="bpkb_number">No. BPKB</label>
                        <input class="form-control" id="bpkb_number" name="bpkb_number" maxlength="50" value="<?= e((string) $row['bpkb_number']) ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Aktif</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="tax_due">Jatuh Tempo Pajak</label>
                        <input class="form-control" type="date" id="tax_due" name="tax_due" value="<?= e((string) $row['tax_due']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="stnk_due">Jatuh Tempo STNK</label>
                        <input class="form-control" type="date" id="stnk_due" name="stnk_due" value="<?= e((string) $row['stnk_due']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="kir_due">Jatuh Tempo KIR</label>
                        <input class="form-control" type="date" id="kir_due" name="kir_due" value="<?= e((string) $row['kir_due']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="photo_file">Foto Unit Kendaraan (Azure Blob)</label>
                        <input class="form-control mb-1" type="file" id="photo_file" name="photo_file" accept="image/*">
                        <input type="hidden" name="photo_path" value="<?= e((string) ($row['photo_path'] ?? '')) ?>">
                        <?php if (!empty($row['photo_path'])): ?>
                            <a href="<?= e(resolve_asset_url($row['photo_path'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-image"></i> Lihat Foto Unit</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="stnk_file">Dokumen STNK (Gambar/PDF)</label>
                        <input class="form-control mb-1" type="file" id="stnk_file" name="stnk_file" accept="image/*,application/pdf">
                        <input type="hidden" name="stnk_doc_path" value="<?= e((string) ($row['stnk_doc_path'] ?? '')) ?>">
                        <?php if (!empty($row['stnk_doc_path'])): ?>
                            <a href="<?= e(resolve_asset_url($row['stnk_doc_path'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-file-earmark-check"></i> Lihat Berkas STNK</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="kir_file">Dokumen KIR (Gambar/PDF)</label>
                        <input class="form-control mb-1" type="file" id="kir_file" name="kir_file" accept="image/*,application/pdf">
                        <input type="hidden" name="kir_doc_path" value="<?= e((string) ($row['kir_doc_path'] ?? '')) ?>">
                        <?php if (!empty($row['kir_doc_path'])): ?>
                            <a href="<?= e(resolve_asset_url($row['kir_doc_path'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-file-earmark-check"></i> Lihat Berkas KIR</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">Catatan</label>
                        <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e((string) $row['note']) ?></textarea>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
