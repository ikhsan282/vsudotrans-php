<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.manage');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$cid = require_active_company();
$in  = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d'), 'trip' => '0', 'bbm' => '0', 'branch_id' => '', 'passenger_id' => '',
    'category_vehicle_id' => '', 'vehicle_id' => '', 'total_price' => '0',
    'pickup_address' => '', 'pickup_point_city_id' => '', 'pickup_point_district_id' => '',
    'delivery_address' => '', 'delivery_point_city_id' => '', 'delivery_point_district_id' => '', 'note' => ''];

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

    // Validasi Anti Double-Booking Armada Rental
    if ($err === '' && (int) $in['vehicle_id'] > 0) {
        $v_id = (int) $in['vehicle_id'];
        $chk_st = db()->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date, p.name AS passenger_name,
                                        v.vehicle_number,
                                        (SELECT to_status FROM booking_status_history WHERE booking_type = 'rental' AND booking_id = b.id ORDER BY id DESC LIMIT 1) AS cur_status
                                 FROM rental_car_bookings b
                                 LEFT JOIN passengers p ON p.id = b.passenger_id
                                 LEFT JOIN vehicles v ON v.id = b.vehicle_id
                                 WHERE b.company_id = ?
                                   AND b.vehicle_id = ?
                                   AND b.start_date <= ?
                                   AND b.end_date >= ?
                                 HAVING cur_status != 'cancelled' OR cur_status IS NULL
                                 LIMIT 1");
        $chk_st->bind_param('iiss', $cid, $v_id, $in['end_date'], $in['start_date']);
        $chk_st->execute();
        $conflict = $chk_st->get_result()->fetch_assoc();
        $chk_st->close();
        if ($conflict) {
            $err = 'Armada ' . ($conflict['vehicle_number'] ?: '') . ' sudah ter-booking pada tanggal tersebut ('
                 . $conflict['document_number'] . ' periode ' . date('d/m/Y', strtotime($conflict['start_date'])) . ' s.d ' . date('d/m/Y', strtotime($conflict['end_date']))
                 . (!empty($conflict['passenger_name']) ? ' a.n ' . $conflict['passenger_name'] : '') . '). Silakan pilih armada lain atau sesuaikan tanggal sewa.';
        }
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
            'pickup_point_city_id' => !empty($in['pickup_point_city_id']) ? (int) $in['pickup_point_city_id'] : $city_id,
            'pickup_point_district_id' => (int) $in['pickup_point_district_id'],
            'delivery_address' => $in['delivery_address'],
            'delivery_point_city_id' => !empty($in['delivery_point_city_id']) ? (int) $in['delivery_point_city_id'] : $city_id,
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
$cities     = opts_cities();

$pickup_districts = !empty($in['pickup_point_city_id'])
    ? db()->query('SELECT id, name FROM districts WHERE city_id = ' . (int) $in['pickup_point_city_id'] . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : (!empty($in['pickup_point_district_id']) ? db()->query('SELECT id, name FROM districts WHERE id = ' . (int) $in['pickup_point_district_id'])->fetch_all(MYSQLI_ASSOC) : []);

$delivery_districts = !empty($in['delivery_point_city_id'])
    ? db()->query('SELECT id, name FROM districts WHERE city_id = ' . (int) $in['delivery_point_city_id'] . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : (!empty($in['delivery_point_district_id']) ? db()->query('SELECT id, name FROM districts WHERE id = ' . (int) $in['delivery_point_district_id'])->fetch_all(MYSQLI_ASSOC) : []);

$active = 'rental';
$title  = 'Booking Rental Baru';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-md-4">
    <h1 class="h4 fw-bold mb-3"><?= e($title) ?></h1>
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
                <input type="date" class="form-control" id="rental_start_date" name="start_date" required value="<?= e($in['start_date']) ?>"></div>
            <div class="col-md-3">
                <label class="form-label d-flex justify-content-between align-items-center mb-1">
                    <span>Selesai Sewa</span>
                    <span id="rental_duration_badge" class="badge bg-primary-subtle text-primary border" style="display:none; font-size: 11px;"></span>
                </label>
                <input type="date" class="form-control" id="rental_end_date" name="end_date" required value="<?= e($in['end_date']) ?>">
            </div>
            <div class="col-md-6"><label class="form-label">Cabang</label>
                <select class="form-select ts-select" name="branch_id" required><option value="">-- Pilih --</option><?= select_options($branches, $in['branch_id']) ?></select></div>
            <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Penumpang Utama <span class="text-danger">*</span></label>
                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalQuickPassenger">
                        <i class="bi bi-person-plus-fill me-1"></i>+ Penumpang Baru
                    </button>
                </div>
                <select class="form-select ts-select" id="select_passenger_id" name="passenger_id" required><option value="">-- Pilih --</option><?= select_options($passengers, $in['passenger_id']) ?></select>
            </div>
            <div class="col-md-6"><label class="form-label">Penumpang Tambahan <span class="text-muted small">(Ctrl/Cmd klik untuk banyak)</span></label>
                <select class="form-select" id="select_passenger_ids" name="passenger_ids[]" multiple size="4"><?= select_options($passengers, $in_passengers, true) ?></select></div>
            <div class="col-md-3"><label class="form-label">Kategori Kendaraan</label>
                <select class="form-select ts-select" name="category_vehicle_id"><option value="">-- Pilih --</option><?= select_options($cats, $in['category_vehicle_id']) ?></select></div>
            <div class="col-md-3"><label class="form-label">Kendaraan</label>
                <select class="form-select ts-select" id="rental_vehicle_select" name="vehicle_id"><option value="">-- Pilih --</option><?= select_options($vehicles, $in['vehicle_id']) ?></select>
                <div id="rental_vehicle_alert" style="display:none;"></div>
            </div>
            <div class="col-md-6"><label class="form-label">Kota Asal</label>
                <select class="form-select ts-select" id="rental_pickup_city" name="pickup_point_city_id"><option value="">-- Pilih Kota --</option><?= select_options($cities, $in['pickup_point_city_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kecamatan Asal</label>
                <select class="form-select ts-select" id="rental_pickup_district" name="pickup_point_district_id" required><option value="">-- Pilih Kecamatan --</option><?= select_options($pickup_districts, $in['pickup_point_district_id']) ?></select></div>
            <div class="col-md-12"><label class="form-label">Alamat Penjemputan</label>
                <input class="form-control" name="pickup_address" maxlength="2000" value="<?= e($in['pickup_address']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Kota Tujuan</label>
                <select class="form-select ts-select" id="rental_delivery_city" name="delivery_point_city_id"><option value="">-- Pilih Kota --</option><?= select_options($cities, $in['delivery_point_city_id']) ?></select></div>
            <div class="col-md-6"><label class="form-label">Kecamatan Tujuan</label>
                <select class="form-select ts-select" id="rental_delivery_district" name="delivery_point_district_id" required><option value="">-- Pilih Kecamatan --</option><?= select_options($delivery_districts, $in['delivery_point_district_id']) ?></select></div>
            <div class="col-md-12"><label class="form-label">Alamat Tujuan</label>
                <input class="form-control" name="delivery_address" maxlength="2000" value="<?= e($in['delivery_address']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Trip (km)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="trip" required value="<?= e($in['trip']) ?>"></div>
            <div class="col-md-3"><label class="form-label">BBM</label>
                <input type="number" step="0.01" min="0" class="form-control" name="bbm" required value="<?= e($in['bbm']) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Tarif / Hari <span class="text-muted small">(Kalkulator)</span></label>
                <input type="number" step="1000" min="0" class="form-control" id="rental_daily_rate" placeholder="mis. 500.000">
            </div>
            <div class="col-md-3">
                <label class="form-label d-flex justify-content-between align-items-center mb-1">
                    <span>Total Harga <span class="text-danger">*</span></span>
                    <button type="button" id="btn_apply_rental_calc" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 11px; display: none;">Hitung Ulang</button>
                </label>
                <input type="number" step="0.01" min="0" class="form-control" id="rental_total_price" name="total_price" required value="<?= e($in['total_price']) ?>">
                <div id="rental_calc_hint" class="form-text small" style="display: none;"></div>
            </div>
            <div class="col-12"><label class="form-label">Catatan</label>
                <input class="form-control" name="note" maxlength="2000" value="<?= e($in['note']) ?>"></div>
        </div>
        <div class="mt-4 d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>

<!-- Modal Quick-Add Penumpang Baru -->
<div class="modal fade" id="modalQuickPassenger" tabindex="-1" aria-labelledby="modalQuickPassengerLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold" id="modalQuickPassengerLabel"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Tambah Penumpang Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formQuickPassenger">
                <div class="modal-body">
                    <div id="quickPassAlert" class="alert alert-danger py-2 d-none"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="qp_name" required placeholder="mis. Budi Santoso">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">No. Telepon / WhatsApp</label>
                        <input type="text" class="form-control" id="qp_phone" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email (Opsional)</label>
                        <input type="email" class="form-control" id="qp_email" placeholder="budi@example.com">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSaveQuickPassenger">Simpan &amp; Pilih</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function reloadDistrictOptions(cityVal, targetSelectId, currentVal) {
        var el = document.getElementById(targetSelectId);
        if (!el) return;
        if (!cityVal) {
            updateSelectOptions(el, [], '');
            return;
        }
        fetch('<?= e(APP_URL) ?>/pages/masters/districts/options.php?city_id=' + encodeURIComponent(cityVal))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                updateSelectOptions(el, data, currentVal);
            });
    }

    function updateSelectOptions(el, items, selectedVal) {
        if (el.tomselect) {
            el.tomselect.clear();
            el.tomselect.clearOptions();
            el.tomselect.addOption({ value: '', text: '-- Pilih Kecamatan --' });
            items.forEach(function (it) {
                el.tomselect.addOption({ value: it.id, text: it.text });
            });
            if (selectedVal) {
                el.tomselect.setValue(selectedVal);
            }
        } else {
            el.innerHTML = '<option value="">-- Pilih Kecamatan --</option>';
            items.forEach(function (it) {
                var opt = document.createElement('option');
                opt.value = it.id;
                opt.textContent = it.text;
                if (String(it.id) === String(selectedVal)) opt.selected = true;
                el.appendChild(opt);
            });
        }
    }

    var pickupCity = document.getElementById('rental_pickup_city');
    if (pickupCity) {
        pickupCity.addEventListener('change', function () {
            reloadDistrictOptions(this.value, 'rental_pickup_district', '');
        });
    }

    var deliveryCity = document.getElementById('rental_delivery_city');
    if (deliveryCity) {
        deliveryCity.addEventListener('change', function () {
            reloadDistrictOptions(this.value, 'rental_delivery_district', '');
        });
    }

    var qpForm = document.getElementById('formQuickPassenger');
    var qpAlert = document.getElementById('quickPassAlert');
    var qpBtn = document.getElementById('btnSaveQuickPassenger');
    var modalEl = document.getElementById('modalQuickPassenger');

    if (qpForm) {
        qpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            qpAlert.classList.add('d-none');
            qpBtn.disabled = true;
            qpBtn.textContent = 'Menyimpan...';

            var payload = new FormData();
            payload.append('csrf_token', '<?= csrf_token() ?>');
            payload.append('name', document.getElementById('qp_name').value);
            payload.append('phone_number', document.getElementById('qp_phone').value);
            payload.append('email', document.getElementById('qp_email').value);

            fetch('<?= e(APP_URL) ?>/pages/travel/passengers/quick_create.php', {
                method: 'POST',
                body: payload
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                qpBtn.disabled = false;
                qpBtn.textContent = 'Simpan & Pilih';
                if (!res.ok) {
                    qpAlert.textContent = res.error || 'Gagal menyimpan penumpang.';
                    qpAlert.classList.remove('d-none');
                    return;
                }

                var p = res.data;
                var pSelect = document.getElementById('select_passenger_id');
                if (pSelect) {
                    if (pSelect.tomselect) {
                        pSelect.tomselect.addOption({ value: p.id, text: p.name });
                        pSelect.tomselect.setValue(p.id);
                    } else {
                        var opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.name;
                        opt.selected = true;
                        pSelect.appendChild(opt);
                    }
                }

                var pMulti = document.getElementById('select_passenger_ids');
                if (pMulti) {
                    var mOpt = document.createElement('option');
                    mOpt.value = p.id;
                    mOpt.textContent = p.name;
                    pMulti.appendChild(mOpt);
                }

                qpForm.reset();
                var bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            })
            .catch(function () {
                qpBtn.disabled = false;
                qpBtn.textContent = 'Simpan & Pilih';
                qpAlert.textContent = 'Terjadi kesalahan jaringan.';
                qpAlert.classList.remove('d-none');
            });
        });
    }

    // Pengecekan Ketersediaan Armada Real-Time (Anti Double-Booking)
    function checkVehicleAvailability() {
        var vEl = document.getElementById('rental_vehicle_select');
        var sEl = document.getElementById('rental_start_date');
        var eEl = document.getElementById('rental_end_date');
        var alertEl = document.getElementById('rental_vehicle_alert');
        if (!vEl || !sEl || !eEl || !alertEl) return;

        var vId = vEl.value;
        var sDate = sEl.value;
        var eDate = eEl.value;

        if (!vId || !sDate || !eDate) {
            alertEl.style.display = 'none';
            alertEl.innerHTML = '';
            return;
        }

        var url = '<?= e(APP_URL) ?>/pages/rental/bookings/check_availability.php?vehicle_id=' + encodeURIComponent(vId)
                + '&start_date=' + encodeURIComponent(sDate)
                + '&end_date=' + encodeURIComponent(eDate);

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.available) {
                    alertEl.className = 'alert alert-danger py-1 px-2 small mb-0 mt-1';
                    alertEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + (res.message || 'Armada bentrok pada tanggal ini!');
                    alertEl.style.display = 'block';
                } else if (res.checked) {
                    alertEl.className = 'text-success small mt-1';
                    alertEl.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Armada tersedia pada tanggal ini.';
                    alertEl.style.display = 'block';
                } else {
                    alertEl.style.display = 'none';
                }
            })
            .catch(function () {
                alertEl.style.display = 'none';
            });
    }

    var sInput = document.getElementById('rental_start_date');
    var eInput = document.getElementById('rental_end_date');
    var vInput = document.getElementById('rental_vehicle_select');

    if (sInput) sInput.addEventListener('change', checkVehicleAvailability);
    if (eInput) eInput.addEventListener('change', checkVehicleAvailability);
    if (vInput) {
        vInput.addEventListener('change', checkVehicleAvailability);
        if (vInput.tomselect) {
            vInput.tomselect.on('change', checkVehicleAvailability);
        }
    }
    // Jalankan pengecekan saat pertama kali halaman dimuat jika sudah ada nilai
    if (vInput && vInput.value) {
        checkVehicleAvailability();
    }

    // Auto-Calculate Durasi & Total Harga Rental
    var durBadge = document.getElementById('rental_duration_badge');
    var dailyRateInput = document.getElementById('rental_daily_rate');
    var rentalPriceInput = document.getElementById('rental_total_price');
    var rentalCalcHint = document.getElementById('rental_calc_hint');
    var btnApplyRental = document.getElementById('btn_apply_rental_calc');

    function calculateRentalDurationAndPrice(forceApply) {
        var sVal = sInput ? sInput.value : '';
        var eVal = eInput ? eInput.value : '';

        if (!sVal || !eVal) {
            if (durBadge) durBadge.style.display = 'none';
            if (rentalCalcHint) rentalCalcHint.style.display = 'none';
            if (btnApplyRental) btnApplyRental.style.display = 'none';
            return;
        }

        var sDate = new Date(sVal + 'T00:00:00');
        var eDate = new Date(eVal + 'T00:00:00');
        var diffTime = eDate - sDate;
        var diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;

        if (diffDays < 1) {
            if (durBadge) {
                durBadge.textContent = 'Tanggal tidak valid';
                durBadge.className = 'badge bg-danger-subtle text-danger border';
                durBadge.style.display = 'inline-block';
            }
            if (rentalCalcHint) rentalCalcHint.style.display = 'none';
            if (btnApplyRental) btnApplyRental.style.display = 'none';
            return;
        }

        if (durBadge) {
            durBadge.textContent = diffDays + ' Hari Sewa';
            durBadge.className = 'badge bg-primary-subtle text-primary border';
            durBadge.style.display = 'inline-block';
        }

        var dailyRate = dailyRateInput ? parseFloat(dailyRateInput.value) || 0 : 0;
        if (dailyRate > 0) {
            var estTotal = diffDays * dailyRate;
            var formattedRate = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(dailyRate);
            var formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(estTotal);

            if (rentalCalcHint) {
                rentalCalcHint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Est: ' + diffDays + ' hari &times; ' + formattedRate + ' = ' + formattedTotal + '</span>';
                rentalCalcHint.style.display = 'block';
            }
            if (btnApplyRental) btnApplyRental.style.display = 'inline';

            if (forceApply || !rentalPriceInput.value || parseFloat(rentalPriceInput.value) === 0) {
                rentalPriceInput.value = estTotal;
            }
        } else {
            if (rentalCalcHint) {
                rentalCalcHint.innerHTML = '<span class="text-muted">Durasi sewa: <strong>' + diffDays + ' hari</strong>.</span>';
                rentalCalcHint.style.display = 'block';
            }
            if (btnApplyRental) btnApplyRental.style.display = 'none';
        }
    }

    if (sInput) sInput.addEventListener('change', function() { calculateRentalDurationAndPrice(false); });
    if (eInput) eInput.addEventListener('change', function() { calculateRentalDurationAndPrice(false); });
    if (dailyRateInput) dailyRateInput.addEventListener('input', function() { calculateRentalDurationAndPrice(true); });
    if (btnApplyRental) btnApplyRental.addEventListener('click', function(e) {
        e.preventDefault();
        calculateRentalDurationAndPrice(true);
    });

    if (sInput && sInput.value && eInput && eInput.value) {
        calculateRentalDurationAndPrice(false);
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
