<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = [
    'code' => '', 'name' => '', 'pickup_point_city_id' => '', 'delivery_point_city_id' => '',
    'category_vehicle_id' => '', 'min_price' => 0, 'price' => 0, 'max_price' => 0,
    'start_capacity_seat' => 0, 'end_capacity_seat' => 0, 'note' => '', 'is_active' => 1,
];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM rutes WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Rute tidak ditemukan.');
        redirect('pages/travel/rutes/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $row = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = [
        'code'     => strtoupper(trim((string) ($_POST['code'] ?? ''))),
        'name'     => trim((string) ($_POST['name'] ?? '')),
        'pickup'   => (int) ($_POST['pickup_point_city_id'] ?? 0),
        'delivery' => (int) ($_POST['delivery_point_city_id'] ?? 0),
        'category' => (int) ($_POST['category_vehicle_id'] ?? 0),
        'min'      => (float) ($_POST['min_price'] ?? 0),
        'price'    => (float) ($_POST['price'] ?? 0),
        'max'      => (float) ($_POST['max_price'] ?? 0),
        'seat_s'   => (int) ($_POST['start_capacity_seat'] ?? 0),
        'seat_e'   => (int) ($_POST['end_capacity_seat'] ?? 0),
        'note'     => trim((string) ($_POST['note'] ?? '')),
        'active'   => isset($_POST['is_active']) ? 1 : 0,
    ];
    $err = '';
    if ($in['code'] === '' || strlen($in['code']) > 15) {
        $err = 'Kode wajib diisi (maks 15 karakter).';
    } elseif ($in['name'] === '' || strlen($in['name']) > 255) {
        $err = 'Nama wajib diisi (maks 255 karakter).';
    } elseif ($in['pickup'] <= 0 || $in['delivery'] <= 0 || $in['category'] <= 0) {
        $err = 'Kota asal, tujuan, dan kategori kendaraan wajib dipilih.';
    } elseif ($in['price'] < 0 || $in['min'] < 0 || $in['max'] < 0 || $in['seat_s'] < 0 || $in['seat_e'] < 0) {
        $err = 'Harga dan kapasitas tidak boleh negatif.';
    }

    if ($err === '') {
        try {
            if ($id > 0) {
                $st = db()->prepare('UPDATE rutes SET code=?, name=?, pickup_point_city_id=?, delivery_point_city_id=?,
                    category_vehicle_id=?, min_price=?, price=?, max_price=?, start_capacity_seat=?, end_capacity_seat=?,
                    note=?, is_active=?, modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?');
                $user = $_SESSION['username'] ?? null;
                $st->bind_param('ssiiidddiisisii', $in['code'], $in['name'], $in['pickup'], $in['delivery'],
                    $in['category'], $in['min'], $in['price'], $in['max'], $in['seat_s'], $in['seat_e'],
                    $in['note'], $in['active'], $user, $id, $cid);
                $st->execute();
                $st->close();
                audit('rute.update', "Rute #{$id} {$in['code']}");
            } else {
                $st = db()->prepare('INSERT INTO rutes (company_id, code, name, pickup_point_city_id, delivery_point_city_id,
                    category_vehicle_id, min_price, price, max_price, start_capacity_seat, end_capacity_seat,
                    note, is_active, created_user) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $user = $_SESSION['username'] ?? null;
                $st->bind_param('issiiidddiisis', $cid, $in['code'], $in['name'], $in['pickup'], $in['delivery'],
                    $in['category'], $in['min'], $in['price'], $in['max'], $in['seat_s'], $in['seat_e'],
                    $in['note'], $in['active'], $user);
                $st->execute();
                $id = (int) db()->insert_id;
                $st->close();
                audit('rute.create', "Rute #{$id} {$in['code']}");
            }
            flash('success', 'Rute berhasil disimpan.');
            redirect('pages/travel/rutes/index.php');
        } catch (mysqli_sql_exception $ex) {
            $err = (int) $ex->getCode() === 1062 ? 'Kode rute sudah dipakai.' : 'Gagal menyimpan rute.';
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
        }
    }
    flash('error', $err);
    $row = array_merge($row, [
        'code' => $in['code'], 'name' => $in['name'], 'pickup_point_city_id' => $in['pickup'],
        'delivery_point_city_id' => $in['delivery'], 'category_vehicle_id' => $in['category'],
        'min_price' => $in['min'], 'price' => $in['price'], 'max_price' => $in['max'],
        'start_capacity_seat' => $in['seat_s'], 'end_capacity_seat' => $in['seat_e'],
        'note' => $in['note'], 'is_active' => $in['active'],
    ]);
}

$cities = opts_cities();
$cats   = opts_categories($cid);
$active = 'travel';
$title  = $id > 0 ? 'Ubah Rute' : 'Tambah Rute';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Kode</label>
                <input class="form-control" name="code" maxlength="15" required value="<?= e($row['code']) ?>"></div>
            <div class="col-md-8"><label class="form-label">Nama</label>
                <input class="form-control" name="name" maxlength="255" required value="<?= e($row['name']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Kota Asal</label>
                <select class="form-select ts-select" name="pickup_point_city_id" required><option value="">-- Pilih --</option><?= select_options($cities, $row['pickup_point_city_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kota Tujuan</label>
                <select class="form-select ts-select" name="delivery_point_city_id" required><option value="">-- Pilih --</option><?= select_options($cities, $row['delivery_point_city_id']) ?></select></div>
            <div class="col-md-12"><label class="form-label">Kategori Kendaraan</label>
                <select class="form-select ts-select" name="category_vehicle_id" required><option value="">-- Pilih --</option><?= select_options($cats, $row['category_vehicle_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Harga Minimum</label>
                <input type="number" step="0.01" min="0" class="form-control" name="min_price" value="<?= e((string) $row['min_price']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Harga</label>
                <input type="number" step="0.01" min="0" class="form-control" name="price" required value="<?= e((string) $row['price']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Harga Maksimum</label>
                <input type="number" step="0.01" min="0" class="form-control" name="max_price" value="<?= e((string) $row['max_price']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Kapasitas Kursi Awal</label>
                <input type="number" min="0" class="form-control" name="start_capacity_seat" value="<?= (int) $row['start_capacity_seat'] ?>"></div>
            <div class="col-md-6"><label class="form-label">Kapasitas Kursi Akhir</label>
                <input type="number" min="0" class="form-control" name="end_capacity_seat" value="<?= (int) $row['end_capacity_seat'] ?>"></div>
            <div class="col-12"><label class="form-label">Catatan</label>
                <textarea class="form-control" name="note" rows="2" maxlength="2000"><?= e($row['note']) ?></textarea></div>
            <div class="col-12"><div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Aktif</label></div></div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/rutes/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
