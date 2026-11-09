<?php
/** Master kendaraan (merek, kategori, tipe mesin, model): CRUD sederhana per perusahaan. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission($_SERVER['REQUEST_METHOD'] === 'POST' ? 'vehicles.manage' : 'vehicles.view');

// Whitelist: nama tabel tidak pernah berasal dari input langsung.
$MASTERS = [
    'brand'    => ['table' => 'brand_vehicles',    'label' => 'Merek',
                   'refs' => [['vehicles', 'brand_id'], ['model_units', 'brand_id']]],
    'category' => ['table' => 'category_vehicles', 'label' => 'Kategori',
                   'refs' => [['vehicles', 'category_vehicle_id'], ['rutes', 'category_vehicle_id'],
                              ['rental_car_regulation_employees', 'category_vehicle_id'], ['premi_configs', 'category_vehicle_id']]],
    'engine'   => ['table' => 'type_engines',      'label' => 'Tipe Mesin',
                   'refs' => [['vehicles', 'type_engine_id']]],
    'model'    => ['table' => 'model_units',       'label' => 'Model Unit',
                   'refs' => [['vehicles', 'model_unit_id']]],
];

$key = (string) ($_REQUEST['t'] ?? 'brand');
if (!isset($MASTERS[$key])) {
    redirect('pages/vehicles/index.php');
}
$m = $MASTERS[$key];
$t = $m['table'];
$db = db();
$cid = active_company_id();
if ($cid === null) {
    flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
    redirect('pages/vehicles/index.php');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $found = null;
    if ($id > 0) {
        $st = $db->prepare("SELECT * FROM {$t} WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $found = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$found) {
            flash('error', "{$m['label']} tidak ditemukan.");
            redirect("pages/vehicles/master.php?t={$key}");
        }
        assert_company_access((int) $found['company_id']);
    }

    if (($_POST['action'] ?? '') === 'delete') {
        if (!$found) {
            flash('error', "{$m['label']} tidak ditemukan.");
            redirect("pages/vehicles/master.php?t={$key}");
        }
        // Cek relasi dulu (FK RESTRICT) agar tidak muncul error SQL mentah.
        $used = 0;
        $usedIn = [];
        foreach ($m['refs'] as [$rt, $rc]) {
            $st = $db->prepare("SELECT COUNT(*) FROM {$rt} WHERE {$rc} = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $n = (int) $st->get_result()->fetch_row()[0];
            $st->close();
            if ($n > 0) {
                $used += $n;
                $usedIn[] = $rt;
            }
        }
        if ($used > 0) {
            flash('error', "{$m['label']} tidak dapat dihapus: masih dipakai " . implode(', ', $usedIn) . '.');
        } else {
            $st = $db->prepare("DELETE FROM {$t} WHERE id = ? AND company_id = ?");
            $st->bind_param('ii', $id, $cid);
            $st->execute();
            $affected = $st->affected_rows;
            $st->close();
            if ($affected > 0) {
                audit("vehicle_master.{$key}.delete", "{$m['label']} #{$id} {$found['code']}");
                flash('success', "{$m['label']} berhasil dihapus.");
            } else {
                flash('error', "{$m['label']} tidak dapat dihapus.");
            }
        }
        redirect("pages/vehicles/master.php?t={$key}");
    }

    // Simpan (tambah / ubah)
    $raw_code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $raw_name = trim((string) ($_POST['name'] ?? ''));
    $raw_note = trim((string) ($_POST['note'] ?? ''));
    $row = [
        'code'      => $raw_code,
        'name'      => $raw_name,
        'note'      => $raw_note,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'brand_id'  => (int) ($_POST['brand_id'] ?? 0),
    ];
    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($row['name'] === '') {
        $errors[] = 'Nama wajib diisi.';
    } elseif (mb_strlen($row['name']) > 255) {
        $errors[] = 'Nama maksimal 255 karakter.';
    }
    if (mb_strlen($row['note']) > 2000) {
        $errors[] = 'Catatan maksimal 2000 karakter.';
    }
    if ($key === 'model' && !vehicle_owned($db, 'brand_vehicles', $row['brand_id'], $cid)) {
        $errors[] = 'Merek wajib dipilih dari perusahaan ini.';
    }

    if (!$errors) {
        $nul = fn (string $v): ?string => $v === '' ? null : $v;
        $user = $_SESSION['username'] ?? null;
        try {
            if ($found) {
                if ($key === 'model') {
                    $st = $db->prepare("UPDATE {$t} SET code = ?, name = ?, note = ?, is_active = ?, brand_id = ?,
                        modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
                    $st->bind_param('sssiisii', $row['code'], $row['name'], $nul($row['note']), $row['is_active'],
                        $row['brand_id'], $user, $id, $cid);
                } else {
                    $st = $db->prepare("UPDATE {$t} SET code = ?, name = ?, note = ?, is_active = ?,
                        modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
                    $st->bind_param('sssisii', $row['code'], $row['name'], $nul($row['note']), $row['is_active'],
                        $user, $id, $cid);
                }
                $st->execute();
                $st->close();
                audit("vehicle_master.{$key}.update", "{$m['label']} #{$id} {$row['code']}");
                flash('success', "{$m['label']} berhasil diperbarui.");
            } else {
                if ($key === 'model') {
                    $st = $db->prepare("INSERT INTO {$t} (company_id, code, name, note, is_active, brand_id, created_user)
                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $st->bind_param('isssiis', $cid, $row['code'], $row['name'], $nul($row['note']), $row['is_active'],
                        $row['brand_id'], $user);
                } else {
                    $st = $db->prepare("INSERT INTO {$t} (company_id, code, name, note, is_active, created_user)
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $st->bind_param('isssis', $cid, $row['code'], $row['name'], $nul($row['note']), $row['is_active'], $user);
                }
                $st->execute();
                $new = $db->insert_id;
                $st->close();
                audit("vehicle_master.{$key}.create", "{$m['label']} #{$new} {$row['code']}");
                flash('success', "{$m['label']} berhasil ditambahkan.");
            }
            redirect("pages/vehicles/master.php?t={$key}");
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
            $errors[] = vehicle_dup_message($ex->getMessage());
        }
    }
    $editing = ['id' => $id] + $row; // pertahankan input saat validasi gagal
}

// ---- GET: daftar + form (tambah / ubah) ----
$editId = (int) ($_GET['edit'] ?? 0);
$adding = isset($_GET['new']);
$form = ['id' => 0, 'code' => '', 'name' => '', 'note' => '', 'is_active' => 1, 'brand_id' => 0];
if (isset($editing)) {
    $form = $editing + $form;
} elseif ($editId > 0) {
    $st = $db->prepare("SELECT * FROM {$t} WHERE id = ?");
    $st->bind_param('i', $editId);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if ($found) {
        assert_company_access((int) $found['company_id']);
        $form = $found + $form;
    }
}
$showForm = $adding || $editId > 0 || $errors;
$isModel = $key === 'model';
$brands = $isModel ? vehicle_options($db, 'brand_vehicles', $cid, (int) $form['brand_id']) : [];

$where = company_scope('x.company_id');
$st = $db->prepare("SELECT COUNT(*) FROM {$t} x WHERE {$where[0]}");
if ($where[1] !== '') {
    $st->bind_param($where[1], ...$where[2]);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$sel = $isModel
    ? "SELECT x.*, b.name AS brand_name FROM {$t} x JOIN brand_vehicles b ON b.id = x.brand_id"
    : "SELECT x.* FROM {$t} x";
$st = $db->prepare("{$sel} WHERE {$where[0]} ORDER BY x.name LIMIT ? OFFSET ?");
$st->bind_param($where[1] . 'ii', ...[...$where[2], $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'vehicles';
$title  = 'Master ' . $m['label'];
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Master Kendaraan</h4>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/vehicles/index.php">Kembali ke Kendaraan</a>
    </div>
    <ul class="nav nav-pills mb-3">
        <?php foreach ($MASTERS as $k => $def): ?>
            <li class="nav-item"><a class="nav-link<?= $k === $key ? ' active' : '' ?>" href="?t=<?= e($k) ?>"><?= e($def['label']) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($showForm && can('vehicles.manage')): ?>
        <div class="card shadow-sm border-0 mb-3" style="max-width: 640px;">
            <div class="card-body">
                <h6 class="fw-bold"><?= $form['id'] ? 'Ubah' : 'Tambah' ?> <?= e($m['label']) ?></h6>
                <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/master.php?t=<?= e($key) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                            <input class="form-control text-uppercase" id="code" name="code" maxlength="15" required value="<?= e($form['code']) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="name">Nama <span class="text-danger">*</span></label>
                            <input class="form-control" id="name" name="name" maxlength="255" required value="<?= e($form['name']) ?>">
                        </div>
                        <?php if ($isModel): ?>
                            <div class="col-12">
                                <label class="form-label" for="brand_id">Merek <span class="text-danger">*</span></label>
                                <select class="form-select" id="brand_id" name="brand_id" required>
                                    <option value="">— Pilih merek —</option>
                                    <?php foreach ($brands as $b): ?>
                                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $form['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label" for="note">Catatan</label>
                            <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e($form['note']) ?></textarea>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Aktif</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Simpan</button>
                    <a class="btn btn-outline-secondary mt-3" href="?t=<?= e($key) ?>">Batal</a>
                </form>
            </div>
        </div>
    <?php elseif (can('vehicles.manage')): ?>
        <a class="btn btn-primary btn-sm mb-3" href="?t=<?= e($key) ?>&new=1"><i class="bi bi-plus-lg"></i> Tambah</a>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Kode</th><th>Nama</th><?= $isModel ? '<th>Merek</th>' : '' ?><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <?php if ($isModel): ?><td><?= e($r['brand_name']) ?></td><?php endif; ?>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end">
                            <?php if (can('vehicles.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="?t=<?= e($key) ?>&edit=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/vehicles/master.php?t=<?= e($key) ?>" class="d-inline" onsubmit="return confirm('Hapus data ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $isModel ? 5 : 4 ?>" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, 't=' . $key) ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
