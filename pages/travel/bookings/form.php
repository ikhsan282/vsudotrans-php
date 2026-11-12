<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$is_edit = $id > 0;
$b   = null;

if ($is_edit) {
    $b = booking_find('travel', $id);
    if (!$b) {
        flash('error', 'Booking travel tidak ditemukan.');
        redirect('pages/travel/bookings/index.php');
    }
    assert_company_access((int) $b['company_id']);
    $cid = (int) $b['company_id'];
}

$in = ['date' => date('Y-m-d'), 'time' => date('H:i'), 'branch_id' => '', 'passenger_id' => '',
    'category_vehicle_id' => '', 'vehicle_id' => '', 'total_price' => '0',
    'pickup_address' => '', 'pickup_point_city_id' => '', 'pickup_point_district_id' => '',
    'delivery_address' => '', 'delivery_point_city_id' => '', 'delivery_point_district_id' => '', 'note' => ''];

$in_passengers = []; // penumpang tambahan (selain primary)

if ($is_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $in = [
        'date'                       => (string) $b['date'],
        'time'                       => substr((string) $b['time'], 0, 5),
        'branch_id'                  => (string) $b['branch_id'],
        'passenger_id'               => (string) $b['passenger_id'],
        'category_vehicle_id'        => (string) ($b['category_vehicle_id'] ?? ''),
        'vehicle_id'                 => (string) ($b['vehicle_id'] ?? ''),
        'total_price'                => (string) (float) ($b['total_price'] ?? 0),
        'pickup_address'             => (string) ($b['pickup_address'] ?? ''),
        'pickup_point_city_id'       => (string) ($b['pickup_point_city_id'] ?? ''),
        'pickup_point_district_id'   => (string) ($b['pickup_point_district_id'] ?? ''),
        'delivery_address'           => (string) ($b['delivery_address'] ?? ''),
        'delivery_point_city_id'     => (string) ($b['delivery_point_city_id'] ?? ''),
        'delivery_point_district_id' => (string) ($b['delivery_point_district_id'] ?? ''),
        'note'                       => (string) ($b['note'] ?? ''),
    ];
    if (empty($in['pickup_point_city_id']) && !empty($in['pickup_point_district_id'])) {
        $stc = db()->query('SELECT city_id FROM districts WHERE id = ' . (int) $in['pickup_point_district_id'])->fetch_assoc();
        if ($stc) $in['pickup_point_city_id'] = (string) $stc['city_id'];
    }
    if (empty($in['delivery_point_city_id']) && !empty($in['delivery_point_district_id'])) {
        $stc = db()->query('SELECT city_id FROM districts WHERE id = ' . (int) $in['delivery_point_district_id'])->fetch_assoc();
        if ($stc) $in['delivery_point_city_id'] = (string) $stc['city_id'];
    }
    $bp_list = booking_passengers_list('travel', $id, (int) $b['passenger_id']);
    foreach ($bp_list as $bp) {
        if ((int) $bp['passenger_id'] !== (int) $b['passenger_id']) {
            $in_passengers[] = (int) $bp['passenger_id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($in) as $k) {
        $in[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $in_passengers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    // Primary tidak perlu dobel di daftar tambahan.
    $in_passengers = array_values(array_diff($in_passengers, [(int) $in['passenger_id']]));
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
        // Kota diturunkan dari kecamatan terpilih
        $st = db()->prepare('SELECT city_id FROM districts WHERE id = ?');
        $st->bind_param('i', $in['pickup_point_district_id']);
        $st->execute();
        $row_city = $st->get_result()->fetch_assoc();
        $st->close();
        $city_id = $row_city ? (int) $row_city['city_id'] : null;

        $st = db()->prepare('SELECT city_id FROM districts WHERE id = ?');
        $st->bind_param('i', $in['delivery_point_district_id']);
        $st->execute();
        $row_del_city = $st->get_result()->fetch_assoc();
        $st->close();
        $del_city_id = $row_del_city ? (int) $row_del_city['city_id'] : $city_id;

        $vehicle_id = (int) $in['vehicle_id'] ?: null;
        $category_vehicle_id = (int) $in['category_vehicle_id'] ?: null;
        $time_val = $in['time'] . ':00';
        $tot_price = (float) $in['total_price'];
        $user = $_SESSION['username'] ?? null;

        if ($is_edit) {
            $db = db();
            $db->begin_transaction();
            try {
                $pass_id  = (int) $in['passenger_id'];
                $br_id    = (int) $in['branch_id'];
                $pk_dist  = (int) $in['pickup_point_district_id'];
                $dl_dist  = (int) $in['delivery_point_district_id'];
                $note_val = $in['note'];

                $st = $db->prepare('UPDATE travel_ticket_bookings SET
                    branch_id = ?, date = ?, time = ?, pickup_address = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                    delivery_address = ?, delivery_point_city_id = ?, delivery_point_district_id = ?, total_price = ?,
                    category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                    modified_user = ?, modified_date = NOW()
                    WHERE id = ? AND company_id = ?');
                $st->bind_param('isssiisisiiisssii',
                    $br_id, $in['date'], $time_val, $in['pickup_address'], $city_id, $pk_dist,
                    $in['delivery_address'], $del_city_id, $dl_dist, $tot_price,
                    $category_vehicle_id, $vehicle_id, $pass_id, $note_val,
                    $user, $id, $b['company_id']
                );
                $st->execute();
                $st->close();

                // Sinkronkan daftar penumpang
                $all_pass = array_values(array_merge([$pass_id], $in_passengers));
                booking_passengers_set('travel', $id, (int) $b['company_id'], $all_pass, $pass_id, $user);

                // Sinkronkan total harga invoice jika masih berstatus draft/sent
                $stInv = $db->prepare("SELECT id, status, tax_percent FROM invoices WHERE booking_type = 'travel' AND booking_id = ? LIMIT 1");
                $stInv->bind_param('i', $id);
                $stInv->execute();
                $inv = $stInv->get_result()->fetch_assoc();
                $stInv->close();
                if ($inv && in_array($inv['status'], ['draft', 'sent'], true)) {
                    $tax_p = (float) $inv['tax_percent'];
                    $tax_amt = round($tot_price * ($tax_p / 100), 2);
                    $total_inv = round($tot_price + $tax_amt, 2);
                    $stUpInv = $db->prepare('UPDATE invoices SET branch_id = ?, subtotal = ?, tax_amount = ?, total = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                    $stUpInv->bind_param('idddsi', $br_id, $tot_price, $tax_amt, $total_inv, $user, $inv['id']);
                    $stUpInv->execute();
                    $stUpInv->close();
                }

                $db->commit();
                audit('booking.travel.update', "Booking #{$id} {$b['document_number']}");
                flash('success', "Booking travel {$b['document_number']} berhasil diperbarui.");
                redirect('pages/travel/bookings/detail.php?id=' . $id);
            } catch (Throwable $ex) {
                $db->rollback();
                throw $ex;
            }
        } else {
            $data = [
                'company_id' => $cid,
                'branch_id' => (int) $in['branch_id'],
                'date' => $in['date'],
                'time' => $time_val,
                'pickup_address' => $in['pickup_address'],
                'pickup_point_city_id' => $city_id,
                'pickup_point_district_id' => (int) $in['pickup_point_district_id'],
                'delivery_address' => $in['delivery_address'],
                'delivery_point_city_id' => $del_city_id,
                'delivery_point_district_id' => (int) $in['delivery_point_district_id'],
                'total_price' => $tot_price,
                'category_vehicle_id' => $category_vehicle_id,
                'vehicle_id' => $vehicle_id,
                'passenger_id' => (int) $in['passenger_id'],
                'passenger_ids' => array_values(array_merge([(int) $in['passenger_id']], $in_passengers)),
                'note' => $in['note'],
                'created_user' => $user,
            ];
            try {
                $res = booking_create('travel', $data);
                audit('booking.travel.create', "Booking #{$res['id']} {$res['document_number']}");
                require_once __DIR__ . '/../../../includes/wa.php';
                wa_notify_booking('travel', (int) $res['id']); // gagal WA tidak membatalkan booking
                flash('success', "Booking {$res['document_number']} berhasil dibuat.");
                redirect('pages/travel/bookings/detail.php?id=' . $res['id']);
            } catch (mysqli_sql_exception $ex) {
                throw $ex;
            }
        }
    }
    flash('error', $err);
}

$branches   = opts_branches($cid);
$passengers = opts_passengers($cid);
$cats       = opts_categories($cid);
$vehicles   = opts_vehicles($cid);
$cities     = opts_cities();
$rutes_data = db()->query("SELECT id, name, pickup_point_city_id, delivery_point_city_id, price FROM rutes WHERE company_id = {$cid} AND is_active = 1")->fetch_all(MYSQLI_ASSOC);

$pickup_districts = !empty($in['pickup_point_city_id'])
    ? db()->query('SELECT id, name FROM districts WHERE city_id = ' . (int) $in['pickup_point_city_id'] . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : (!empty($in['pickup_point_district_id']) ? db()->query('SELECT id, name FROM districts WHERE id = ' . (int) $in['pickup_point_district_id'])->fetch_all(MYSQLI_ASSOC) : []);

$delivery_districts = !empty($in['delivery_point_city_id'])
    ? db()->query('SELECT id, name FROM districts WHERE city_id = ' . (int) $in['delivery_point_city_id'] . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : (!empty($in['delivery_point_district_id']) ? db()->query('SELECT id, name FROM districts WHERE id = ' . (int) $in['delivery_point_district_id'])->fetch_all(MYSQLI_ASSOC) : []);

$active = 'travel';
$title  = $is_edit ? 'Ubah Booking Travel - ' . $b['document_number'] : 'Booking Travel Baru';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-0 text-break d-flex align-items-center gap-2">
                <span><i class="bi bi-ticket-perforated-fill text-primary me-2"></i><?= e($title) ?></span>
                <?php if ($is_edit): ?>
                    <?= status_badge(booking_current_status('travel', $id)) ?>
                <?php endif; ?>
            </h1>
            <div class="small text-muted">Perusahaan: <strong><?= e(active_company_name()) ?></strong></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/<?= $is_edit ? 'pages/travel/bookings/detail.php?id=' . $id : 'pages/travel/bookings/index.php' ?>">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
        </div>
    </div>
    <?php
    $ai_endpoint = APP_URL . '/pages/travel/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Tanggal</label>
                <input type="date" class="form-control" name="date" required value="<?= e($in['date']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Jam</label>
                <input type="time" class="form-control" name="time" required value="<?= e($in['time']) ?>"></div>
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
                <select class="form-select ts-select" name="vehicle_id"><option value="">-- Pilih --</option><?= select_options($vehicles, $in['vehicle_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kota Asal</label>
                <select class="form-select ts-select" id="pickup_city" name="pickup_point_city_id"><option value="">-- Pilih Kota --</option><?= select_options($cities, $in['pickup_point_city_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kecamatan Asal</label>
                <select class="form-select ts-select" id="pickup_district" name="pickup_point_district_id" required><option value="">-- Pilih Kecamatan --</option><?= select_options($pickup_districts, $in['pickup_point_district_id']) ?></select></div>
            <div class="col-md-8"><label class="form-label">Alamat Penjemputan</label>
                <input class="form-control" name="pickup_address" maxlength="2000" value="<?= e($in['pickup_address']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Kota Tujuan</label>
                <select class="form-select ts-select" id="delivery_city" name="delivery_point_city_id"><option value="">-- Pilih Kota --</option><?= select_options($cities, $in['delivery_point_city_id']) ?></select></div>
            <div class="col-md-4"><label class="form-label">Kecamatan Tujuan</label>
                <select class="form-select ts-select" id="delivery_district" name="delivery_point_district_id" required><option value="">-- Pilih Kecamatan --</option><?= select_options($delivery_districts, $in['delivery_point_district_id']) ?></select></div>
            <div class="col-md-8"><label class="form-label">Alamat Tujuan</label>
                <input class="form-control" name="delivery_address" maxlength="2000" value="<?= e($in['delivery_address']) ?>"></div>
            <div class="col-md-4">
                <label class="form-label d-flex justify-content-between align-items-center mb-1">
                    <span>Total Harga <span class="text-danger">*</span></span>
                    <button type="button" id="btn_apply_travel_calc" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 11px; display: none;">Hitung Ulang</button>
                </label>
                <input type="number" step="0.01" min="0" class="form-control" id="travel_total_price" name="total_price" required value="<?= e($in['total_price']) ?>">
                <div id="travel_price_hint" class="form-text small" style="display: none;"></div>
            </div>
            <div class="col-12"><label class="form-label">Catatan</label>
                <textarea class="form-control" name="note" rows="2" maxlength="2000"><?= e($in['note']) ?></textarea></div>
        </div>
        <div class="mt-4 d-flex flex-wrap gap-2">
            <button class="btn btn-primary btn-sm px-4 fw-semibold" type="submit">
                <i class="bi bi-check2-circle me-1"></i><?= $is_edit ? 'Simpan Perubahan' : 'Simpan Booking' ?>
            </button>
            <a class="btn btn-outline-secondary btn-sm px-3" href="<?= e(APP_URL) ?>/<?= $is_edit ? 'pages/travel/bookings/detail.php?id=' . $id : 'pages/travel/bookings/index.php' ?>">
                Batal
            </a>
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
    // Helper sinkronisasi opsi pada elemen TomSelect atau standard select
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

    // Cascading Kota -> Kecamatan Asal
    var pickupCity = document.getElementById('pickup_city');
    if (pickupCity) {
        pickupCity.addEventListener('change', function () {
            reloadDistrictOptions(this.value, 'pickup_district', '');
        });
    }

    // Cascading Kota -> Kecamatan Tujuan
    var deliveryCity = document.getElementById('delivery_city');
    if (deliveryCity) {
        deliveryCity.addEventListener('change', function () {
            reloadDistrictOptions(this.value, 'delivery_district', '');
        });
    }

    // Quick-Add Penumpang via AJAX Modal
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
                // Tambahkan opsi ke select Penumpang Utama
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

                // Tambahkan opsi ke select Penumpang Tambahan
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

    // Auto-Calculate Estimasi Total Harga Travel
    var activeRoutes = <?= json_encode($rutes_data, JSON_NUMERIC_CHECK) ?>;
    var priceInput = document.getElementById('travel_total_price');
    var priceHint = document.getElementById('travel_price_hint');
    var btnApply = document.getElementById('btn_apply_travel_calc');

    function calculateTravelPrice(forceApply) {
        var pCity = document.getElementById('pickup_city');
        var dCity = document.getElementById('delivery_city');
        var primPass = document.getElementById('select_passenger_id');
        var addPass = document.getElementById('select_passenger_ids');

        if (!pCity || !dCity || !priceInput) return;

        var pcVal = parseInt(pCity.value, 10);
        var dcVal = parseInt(dCity.value, 10);

        if (!pcVal || !dcVal) {
            if (priceHint) priceHint.style.display = 'none';
            if (btnApply) btnApply.style.display = 'none';
            return;
        }

        var matchedRoute = null;
        for (var i = 0; i < activeRoutes.length; i++) {
            if (activeRoutes[i].pickup_point_city_id === pcVal && activeRoutes[i].delivery_point_city_id === dcVal) {
                matchedRoute = activeRoutes[i];
                break;
            }
        }

        if (!matchedRoute) {
            if (priceHint) {
                priceHint.textContent = 'Tidak ditemukan rute aktif untuk asal-tujuan ini.';
                priceHint.className = 'form-text text-muted small';
                priceHint.style.display = 'block';
            }
            if (btnApply) btnApply.style.display = 'none';
            return;
        }

        var passCount = 0;
        if (primPass && primPass.value) passCount++;
        if (addPass && addPass.selectedOptions) {
            passCount += addPass.selectedOptions.length;
        }
        if (passCount === 0) passCount = 1;

        var unitPrice = parseFloat(matchedRoute.price) || 0;
        var estTotal = passCount * unitPrice;

        var formattedUnit = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(unitPrice);
        var formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(estTotal);

        if (priceHint) {
            priceHint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Estimasi: ' + passCount + ' tiket &times; ' + formattedUnit + ' = ' + formattedTotal + '</span>';
            priceHint.style.display = 'block';
        }
        if (btnApply) btnApply.style.display = 'inline';

        if (forceApply || !priceInput.value || parseFloat(priceInput.value) === 0) {
            priceInput.value = estTotal;
        }
    }

    var pickupCityEl = document.getElementById('pickup_city');
    var deliveryCityEl = document.getElementById('delivery_city');
    var selectPassEl = document.getElementById('select_passenger_id');
    var selectPassMultiEl = document.getElementById('select_passenger_ids');

    if (pickupCityEl) pickupCityEl.addEventListener('change', function() { calculateTravelPrice(false); });
    if (deliveryCityEl) deliveryCityEl.addEventListener('change', function() { calculateTravelPrice(false); });
    if (selectPassEl) {
        selectPassEl.addEventListener('change', function() { calculateTravelPrice(false); });
        if (selectPassEl.tomselect) {
            selectPassEl.tomselect.on('change', function() { calculateTravelPrice(false); });
        }
    }
    if (selectPassMultiEl) selectPassMultiEl.addEventListener('change', function() { calculateTravelPrice(false); });
    if (btnApply) btnApply.addEventListener('click', function(e) {
        e.preventDefault();
        calculateTravelPrice(true);
    });

    if (pickupCityEl && pickupCityEl.value && deliveryCityEl && deliveryCityEl.value) {
        calculateTravelPrice(false);
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
