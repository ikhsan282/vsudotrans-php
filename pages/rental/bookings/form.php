<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.manage');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$cid = require_active_company();
$in  = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d'), 'trip' => '0', 'bbm' => '0', 'branch_id' => '', 'passenger_id' => '',
    'category_vehicle_id' => '', 'vehicle_id' => '', 'total_price' => '0',
    'pickup_address' => '', 'pickup_point_district_id' => '',
    'delivery_address' => '', 'delivery_point_district_id' => '', 'note' => ''];

$in_passengers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($in) as $k) {
        $in[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $in_passengers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    $in_passengers = array_values(array_diff($in_passengers, [(int) $in['passenger_id']]));
    $err = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        $err = 'Tanggal dan jam booking wajib diisi.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['end_date'])) {
        $err = 'Tanggal mulai dan selesai sewa wajib diisi.';
    } elseif ($in['end_date'] < $in['start_date']) {
        $err = 'Tanggal selesai sewa tidak boleh sebelum tanggal mulai.';
    } elseif ((int) $in['branch_id'] <= 0 || (int) $in['passenger_id'] <= 0) {
        $err = 'Cabang dan penumpang wajib dipilih.';
    } elseif ((int) $in['pickup_point_district_id'] <= 0 || (int) $in['delivery_point_district_id'] <= 0) {
        $err = 'Kecamatan asal dan tujuan wajib dipilih.';
    } elseif ((float) $in['trip'] < 0 || (float) $in['bbm'] < 0 || (float) $in['total_price'] < 0) {
        $err = 'Trip, BBM, dan total harga tidak boleh negatif.';
    }

    if ($err === '') {
        $st = db()->prepare('SELECT city_id FROM districts WHERE id = ?');
        $st->bind_param('i', $in['pickup_point_district_id']);
        $st->execute();
        $row_city = $st->get_result()->fetch_assoc();
        $st->close();
        $city_id = $row_city ? (int) $row_city['city_id'] : null;

        $data = [
            'company_id' => $cid,
            'branch_id' => (int) $in['branch_id'],
            'date' => $in['date'],
            'time' => $in['time'] . ':00',
            'start_date' => $in['start_date'],
            'end_date' => $in['end_date'],
            'trip' => (float) $in['trip'],
            'bbm' => (float) $in['bbm'],
            'pickup_address' => $in['pickup_address'],
            'pickup_point_city_id' => $city_id,
            'pickup_point_district_id' => (int) $in['pickup_point_district_id'],
            'delivery_address' => $in['delivery_address'],
            'delivery_point_city_id' => $city_id,
            'delivery_point_district_id' => (int) $in['delivery_point_district_id'],
            'total_price' => (float) $in['total_price'],
            'category_vehicle_id' => (int) $in['category_vehicle_id'] ?: null,
            'vehicle_id' => (int) $in['vehicle_id'] ?: null,
            'passenger_id' => (int) $in['passenger_id'],
            'passenger_ids' => array_values(array_merge([(int) $in['passenger_id']], $in_passengers)),
            'note' => $in['note'],
            'created_user' => $_SESSION['username'] ?? null,
        ];
        try {
            $res = booking_create('rental', $data);
            audit('booking.rental.create', "Booking #{$res['id']} {$res['document_number']}");
            require_once __DIR__ . '/../../../includes/wa.php';
            wa_notify_booking('rental', (int) $res['id']); // gagal WA tidak membatalkan booking
            flash('success', "Booking {$res['document_number']} berhasil dibuat.");
            redirect('pages/rental/bookings/detail.php?id=' . $res['id']);
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
$districts  = db()->query('SELECT id, name FROM districts ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$active = 'rental';
$title  = 'Booking Rental Baru';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php
    $ai_endpoint = APP_URL . '/pages/rental/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Tanggal Booking</label>
                <input type="date" class="form-control" name="date" required value="<?= e($in['date']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Jam Booking</label>
                <input type="time" class="form-control" name="time" required value="<?= e($in['time']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Mulai Sewa</label>
                <input type="date" class="form-control" name="start_date" required value="<?= e($in['start_date']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Selesai Sewa</label>
                <input type="date" class="form-control" name="end_date" required value="<?= e($in['end_date']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Cabang</label>
                <select class="form-select" name="branch_id" required><option value="">-- Pilih --</option><?= select_options($branches, $in['branch_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Penumpang Utama</label>
                <select class="form-select" name="passenger_id" required><option value="">-- Pilih --</option><?= select_options($passengers, $in['passenger_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Penumpang Tambahan <span class="text-muted small">(Ctrl/Cmd klik untuk banyak)</span></label>
                <select class="form-select" name="passenger_ids[]" multiple size="4"><?= select_options($passengers, $in_passengers, true) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kategori Kendaraan</label>
                <select class="form-select" name="category_vehicle_id"><option value="">-- Pilih --</option><?= select_options($cats, $in['category_vehicle_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kendaraan</label>
                <select class="form-select" name="vehicle_id"><option value="">-- Pilih --</option><?= select_options($vehicles, $in['vehicle_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kecamatan Asal</label>
                <select class="form-select" name="pickup_point_district_id" required><option value="">-- Pilih --</option><?= select_options($districts, $in['pickup_point_district_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kecamatan Tujuan</label>
                <select class="form-select" name="delivery_point_district_id" required><option value="">-- Pilih --</option><?= select_options($districts, $in['delivery_point_district_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Alamat Penjemputan</label>
                <input class="form-control" name="pickup_address" maxlength="2000" value="<?= e($in['pickup_address']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Alamat Tujuan</label>
                <input class="form-control" name="delivery_address" maxlength="2000" value="<?= e($in['delivery_address']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Trip (km)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="trip" required value="<?= e($in['trip']) ?>"></div>
            <div class="col-md-3"><label class="form-label">BBM</label>
                <input type="number" step="0.01" min="0" class="form-control" name="bbm" required value="<?= e($in['bbm']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Total Harga</label>
                <input type="number" step="0.01" min="0" class="form-control" name="total_price" required value="<?= e($in['total_price']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Catatan</label>
                <input class="form-control" name="note" maxlength="2000" value="<?= e($in['note']) ?>"></div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
