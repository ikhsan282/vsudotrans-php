<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$cid = require_active_company();
$in  = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'branch_id' => '', 'passenger_id' => '',
    'category_vehicle_id' => '', 'vehicle_id' => '', 'total_price' => '0',
    'pickup_address' => '', 'pickup_point_city_id' => '', 'pickup_point_district_id' => '',
    'delivery_address' => '', 'delivery_point_city_id' => '', 'delivery_point_district_id' => '', 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($in) as $k) {
        $in[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $err = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
        $err = 'Tanggal wajib diisi (YYYY-MM-DD).';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        $err = 'Jam wajib diisi (HH:MM).';
    } elseif ((int) $in['branch_id'] <= 0 || (int) $in['passenger_id'] <= 0) {
        $err = 'Cabang dan penumpang wajib dipilih.';
    } elseif ((int) $in['pickup_point_district_id'] <= 0 || (int) $in['delivery_point_district_id'] <= 0) {
        $err = 'Kecamatan asal dan tujuan wajib dipilih.';
    } elseif ((float) $in['total_price'] < 0) {
        $err = 'Total harga tidak boleh negatif.';
    }

    if ($err === '') {
        // Kota diturunkan dari kecamatan terpilih (konsisten asal & tujuan).
        $st = db()->prepare('SELECT city_id FROM districts WHERE id = ?');
        $st->bind_param('i', $in['pickup_point_district_id']);
        $st->execute();
        $row_city = $st->get_result()->fetch_assoc();
        $st->close();
        $city_id = $row_city ? (int) $row_city['city_id'] : null;
        $vehicle_id = (int) $in['vehicle_id'] ?: null;
        $data = [
            'company_id' => $cid,
            'branch_id' => (int) $in['branch_id'],
            'date' => $in['date'],
            'time' => $in['time'] . ':00',
            'pickup_address' => $in['pickup_address'],
            'pickup_point_city_id' => $city_id,
            'pickup_point_district_id' => (int) $in['pickup_point_district_id'],
            'delivery_address' => $in['delivery_address'],
            'delivery_point_city_id' => $city_id,
            'delivery_point_district_id' => (int) $in['delivery_point_district_id'],
            'total_price' => (float) $in['total_price'],
            'category_vehicle_id' => (int) $in['category_vehicle_id'] ?: null,
            'vehicle_id' => $vehicle_id,
            'passenger_id' => (int) $in['passenger_id'],
            'note' => $in['note'],
            'created_user' => $_SESSION['username'] ?? null,
        ];
        try {
            $res = booking_create('travel', $data);
            audit('booking.travel.create', "Booking #{$res['id']} {$res['document_number']}");
            flash('success', "Booking {$res['document_number']} berhasil dibuat.");
            redirect('pages/travel/bookings/detail.php?id=' . $res['id']);
        } catch (mysqli_sql_exception $ex) {
            throw $ex;
        }
    }
    flash('error', $err);
}

$branches   = opts_branches($cid);
$passengers = opts_passengers($cid);
$cats       = opts_categories($cid);
$vehicles   = opts_vehicles($cid);
$cities     = opts_cities();
$districts  = db()->query('SELECT id, name FROM districts ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$active = 'travel';
$title  = 'Booking Travel Baru';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php
    $ai_endpoint = APP_URL . '/pages/travel/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Tanggal</label>
                <input type="date" class="form-control" name="date" required value="<?= e($in['date']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Jam</label>
                <input type="time" class="form-control" name="time" required value="<?= e($in['time']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Cabang</label>
                <select class="form-select" name="branch_id" required><option value="">-- Pilih --</option><?= select_options($branches, $in['branch_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Penumpang</label>
                <select class="form-select" name="passenger_id" required><option value="">-- Pilih --</option><?= select_options($passengers, $in['passenger_id']) ?></select></div>
            <div class="col-md-3"><label class="form-label">Kategori Kendaraan</label>
                <select class="form-select" name="category_vehicle_id"><option value="">-- Pilih --</option><?= select_options($cats, $in['category_vehicle_id']) ?></select></div>
            <div class="col-md-3"><label class="form-label">Kendaraan</label>
                <select class="form-select" name="vehicle_id"><option value="">-- Pilih --</option><?= select_options($vehicles, $in['vehicle_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kota Asal</label>
                <select class="form-select" name="pickup_point_city_id"><option value="">-- Pilih --</option><?= select_options($cities, $in['pickup_point_city_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kecamatan Asal</label>
                <select class="form-select" name="pickup_point_district_id" required><option value="">-- Pilih --</option><?= select_options($districts, $in['pickup_point_district_id']) ?></select></div>
            <div class="col-md-8"><label class="form-label">Alamat Penjemputan</label>
                <input class="form-control" name="pickup_address" maxlength="2000" value="<?= e($in['pickup_address']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Kota Tujuan</label>
                <select class="form-select" name="delivery_point_city_id"><option value="">-- Pilih --</option><?= select_options($cities, $in['delivery_point_city_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kecamatan Tujuan</label>
                <select class="form-select" name="delivery_point_district_id" required><option value="">-- Pilih --</option><?= select_options($districts, $in['delivery_point_district_id']) ?></select></div>
            <div class="col-md-8"><label class="form-label">Alamat Tujuan</label>
                <input class="form-control" name="delivery_address" maxlength="2000" value="<?= e($in['delivery_address']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Total Harga</label>
                <input type="number" step="0.01" min="0" class="form-control" name="total_price" required value="<?= e($in['total_price']) ?>"></div>
            <div class="col-12"><label class="form-label">Catatan</label>
                <textarea class="form-control" name="note" rows="2" maxlength="2000"><?= e($in['note']) ?></textarea></div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
