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

$in = [
    'date'                       => date('Y-m-d'),
    'time'                       => date('H:i'),
    'branch_id'                  => '',
    'passenger_id'               => '',
    'category_vehicle_id'        => '',
    'vehicle_id'                 => '',
    'total_price'                => '0',
    'pickup_address'             => '',
    'pickup_point_province_id'   => '',
    'pickup_point_city_id'       => '',
    'pickup_point_district_id'   => '',
    'delivery_address'           => '',
    'delivery_point_province_id' => '',
    'delivery_point_city_id'     => '',
    'delivery_point_district_id' => '',
    'note'                       => '',
];

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
        'pickup_point_province_id'   => '',
        'pickup_point_city_id'       => (string) ($b['pickup_point_city_id'] ?? ''),
        'pickup_point_district_id'   => (string) resolve_district_id($b['pickup_point_district_id'] ?? ''),
        'delivery_address'           => (string) ($b['delivery_address'] ?? ''),
        'delivery_point_province_id' => '',
        'delivery_point_city_id'     => (string) ($b['delivery_point_city_id'] ?? ''),
        'delivery_point_district_id' => (string) resolve_district_id($b['delivery_point_district_id'] ?? ''),
        'note'                       => (string) ($b['note'] ?? ''),
    ];
    if (!empty($in['pickup_point_district_id'])) {
        $p_info = resolve_district_info($in['pickup_point_district_id']);
        if ($p_info) {
            $in['pickup_point_city_id']     = (string) $p_info['city_id'];
            $in['pickup_point_province_id'] = (string) $p_info['province_id'];
        }
    } elseif (!empty($in['pickup_point_city_id'])) {
        $stc = db()->query('SELECT province_id FROM cities WHERE id = ' . (int) $in['pickup_point_city_id'])->fetch_assoc();
        if ($stc) $in['pickup_point_province_id'] = (string) $stc['province_id'];
    }
    if (!empty($in['delivery_point_district_id'])) {
        $d_info = resolve_district_info($in['delivery_point_district_id']);
        if ($d_info) {
            $in['delivery_point_city_id']     = (string) $d_info['city_id'];
            $in['delivery_point_province_id'] = (string) $d_info['province_id'];
        }
    } elseif (!empty($in['delivery_point_city_id'])) {
        $stc = db()->query('SELECT province_id FROM cities WHERE id = ' . (int) $in['delivery_point_city_id'])->fetch_assoc();
        if ($stc) $in['delivery_point_province_id'] = (string) $stc['province_id'];
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
    $in['total_price'] = (string) parse_rupiah_input($_POST['total_price'] ?? $in['total_price']);
    $in_passengers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    // Primary tidak perlu dobel di daftar tambahan.
    $in_passengers = array_values(array_diff($in_passengers, [(int) $in['passenger_id']]));

    $pk_dist_resolved = resolve_district_id($in['pickup_point_district_id']);
    $dl_dist_resolved = resolve_district_id($in['delivery_point_district_id']);

    $err = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
        $err = 'Tanggal keberangkatan wajib diisi.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        $err = 'Jam keberangkatan wajib diisi.';
    } elseif ((int) $in['branch_id'] <= 0 || (int) $in['passenger_id'] <= 0) {
        $err = 'Cabang dan penumpang utama wajib dipilih.';
    } elseif ($pk_dist_resolved <= 0 || $dl_dist_resolved <= 0) {
        $err = 'Kecamatan penjemputan dan tujuan wajib dipilih.';
    } elseif ((float) $in['total_price'] < 0) {
        $err = 'Total harga tidak boleh negatif.';
    }

    if ($err === '') {
        $p_loc = resolve_district_info($pk_dist_resolved) ?: [];
        $d_loc = resolve_district_info($dl_dist_resolved) ?: [];
        $city_id     = !empty($p_loc['city_id']) ? (int) $p_loc['city_id'] : ((int) $in['pickup_point_city_id'] ?: null);
        $del_city_id = !empty($d_loc['city_id']) ? (int) $d_loc['city_id'] : ((int) $in['delivery_point_city_id'] ?: $city_id);

        $vehicle_id          = (int) $in['vehicle_id'] ?: null;
        $category_vehicle_id = (int) $in['category_vehicle_id'] ?: null;
        $time_val            = $in['time'] . ':00';
        $tot_price           = (float) $in['total_price'];
        $user                = $_SESSION['username'] ?? null;

        if ($is_edit) {
            $db = db();
            $db->begin_transaction();
            try {
                $pass_id  = (int) $in['passenger_id'];
                $br_id    = (int) $in['branch_id'];
                $pk_dist  = $pk_dist_resolved;
                $dl_dist  = $dl_dist_resolved;
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

                $all_pass = array_values(array_merge([$pass_id], $in_passengers));
                booking_passengers_set('travel', $id, (int) $b['company_id'], $all_pass, $pass_id, $user);

                $stInv = $db->prepare("SELECT id, status, tax_percent FROM invoices WHERE booking_type = 'travel' AND booking_id = ? LIMIT 1");
                $stInv->bind_param('i', $id);
                $stInv->execute();
                $inv = $stInv->get_result()->fetch_assoc();
                $stInv->close();
                if ($inv && in_array($inv['status'], ['draft', 'sent'], true)) {
                    $tax_p     = (float) $inv['tax_percent'];
                    $tax_amt   = round($tot_price * ($tax_p / 100), 2);
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
                'company_id'                 => $cid,
                'branch_id'                  => (int) $in['branch_id'],
                'date'                       => $in['date'],
                'time'                       => $time_val,
                'pickup_address'             => $in['pickup_address'],
                'pickup_point_city_id'       => $city_id,
                'pickup_point_district_id'   => $pk_dist_resolved,
                'delivery_address'           => $in['delivery_address'],
                'delivery_point_city_id'     => $del_city_id,
                'delivery_point_district_id' => $dl_dist_resolved,
                'total_price'                => $tot_price,
                'category_vehicle_id'        => $category_vehicle_id,
                'vehicle_id'                 => $vehicle_id,
                'passenger_id'               => (int) $in['passenger_id'],
                'passenger_ids'              => array_values(array_merge([(int) $in['passenger_id']], $in_passengers)),
                'note'                       => $in['note'],
                'created_user'               => $user,
            ];
            try {
                $res = booking_create('travel', $data);
                audit('booking.travel.create', "Booking #{$res['id']} {$res['document_number']}");
                require_once __DIR__ . '/../../../includes/wa.php';
                wa_notify_booking('travel', (int) $res['id']);
                flash('success', "Booking {$res['document_number']} berhasil dibuat.");
                redirect('pages/travel/bookings/detail.php?id=' . $res['id']);
            } catch (mysqli_sql_exception $ex) {
                throw $ex;
            }
        }
    }
    flash('error', $err);
}

$stComp = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $cid);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $cid, 'label' => active_company_name()];
$stComp->close();

$branches = opts_branches($cid);
if ($in['branch_id'] === '' && !empty($branches[0]['id'])) {
    $in['branch_id'] = (string) $branches[0]['id'];
}
$selected_p_ids = array_filter(array_merge([(int) ($in['passenger_id'] ?? 0)], $in_passengers));
$passengers = opts_passengers($cid, $selected_p_ids, 50);
$userCompanyIds = accessible_company_ids($cid);

$cats = opts_categories($cid, [(int) ($in['category_vehicle_id'] ?? 0)], 200);
$vehicles = opts_vehicles_detailed(
    $cid,
    $userCompanyIds,
    (int) ($in['vehicle_id'] ?? 0) ?: null,
    !empty($in['category_vehicle_id']) ? (int) $in['category_vehicle_id'] : null,
    50
);

$p_form_dist = resolve_district_id($in['pickup_point_district_id'] ?? 0);
$d_form_dist = resolve_district_id($in['delivery_point_district_id'] ?? 0);

$provinces = opts_provinces([(int) ($in['pickup_point_province_id'] ?? 0), (int) ($in['delivery_point_province_id'] ?? 0)], 100);

$pickup_districts   = opts_districts_by_city((int) ($in['pickup_point_city_id'] ?? 0), [$p_form_dist], 100);
$delivery_districts = opts_districts_by_city((int) ($in['delivery_point_city_id'] ?? 0), [$d_form_dist], 100);

$pickup_cities_view = opts_cities(
    !empty($in['pickup_point_province_id']) ? (int) $in['pickup_point_province_id'] : null,
    [(int) ($in['pickup_point_city_id'] ?? 0)],
    !empty($in['pickup_point_province_id']) ? 100 : 50
);
$delivery_cities_view = opts_cities(
    !empty($in['delivery_point_province_id']) ? (int) $in['delivery_point_province_id'] : null,
    [(int) ($in['delivery_point_city_id'] ?? 0)],
    !empty($in['delivery_point_province_id']) ? 100 : 50
);

$active = 'travel';
$title  = $is_edit ? 'Ubah Pemesanan Travel - ' . $b['document_number'] : 'Pemesanan Travel Baru';
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-2 p-md-3 w-100 flex-grow-1">
    <!-- Header Toolbar Halaman -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-ticket-perforated-fill me-1"></i>Booking Travel</span>
            <?php if ($is_edit): ?>
                <?= status_badge(booking_current_status('travel', $id)) ?>
            <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border">Draft Baru</span>
            <?php endif; ?>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/<?= $is_edit ? 'pages/travel/bookings/detail.php?id=' . $id : 'pages/travel/bookings/index.php' ?>">
                <i class="bi bi-arrow-left me-1"></i><?= $is_edit ? 'Kembali' : 'Batal' ?>
            </a>
            <button type="submit" form="formTravelBooking" class="btn btn-primary btn-sm px-3">
                <i class="bi bi-check2-circle me-1"></i><?= $is_edit ? 'Simpan Perubahan' : 'Simpan Pemesanan' ?>
            </button>
        </div>
    </div>

    <?php
    $ai_endpoint = APP_URL . '/pages/travel/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- SUB-TABS -->
        <ul class="nav nav-tabs erp-sub-tabs" id="travelTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">
                    Detail
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-note-btn" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">
                    Keterangan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-audit" type="button" role="tab">
                    Audit Trail
                </button>
            </li>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="" id="formTravelBooking">
                <?= csrf_field() ?>
                <?php if ($is_edit): ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                <?php endif; ?>

                <div class="tab-content" id="travelTabContent">
                    <!-- ========================================================== -->
                    <!-- TAB 1: DETAIL                                              -->
                    <!-- ========================================================== -->
                    <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                        <div class="rnt-panel">
                            <div class="rnt-panel-body">
                                <!-- Resi / No Booking -->
                                <div class="rnt-row">
                                    <label class="rnt-label">No. Tiket / Resi</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm fw-bold bg-light font-monospace" readonly value="<?= e($is_edit ? (string) $b['document_number'] : 'OTOMATIS (Dibuat saat disimpan)') ?>">
                                    </div>
                                </div>

                                <!-- Perusahaan -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Perusahaan</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm bg-light" disabled>
                                            <option value="<?= (int) $curCompany['id'] ?>" selected><?= e($curCompany['label']) ?></option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Cabang -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Cabang <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm" name="branch_id" required>
                                            <option value="">-- Pilih Cabang --</option>
                                            <?= select_options($branches, $in['branch_id']) ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Tanggal Keberangkatan -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Tanggal Berangkat <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-white flatpickr-date" id="travel_date_input" name="date" required value="<?= e($in['date']) ?>" placeholder="dd-MMM-yyyy" style="max-width: 260px;">
                                    </div>
                                </div>

                                <!-- Jam -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Jam Berangkat <span class="text-danger">*</span></label>
                                    <div class="rnt-field d-flex align-items-center gap-2">
                                        <input type="time" class="form-control form-control-sm" name="time" id="travel_time_input" required value="<?= e($in['time']) ?>" style="max-width: 180px;">
                                        <span id="travel_time_period_badge" class="badge bg-light text-secondary border small"></span>
                                    </div>
                                </div>

                                <!-- Harga -->
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1">Total Harga <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="totalPriceDisplay" placeholder="0" value="<?= format_nominal((float) $in['total_price']) ?>" style="max-width: 260px;" required>
                                            <input type="hidden" id="travel_total_price" name="total_price" value="<?= e($in['total_price']) ?>">
                                            <button type="button" id="btn_apply_travel_calc" class="btn btn-outline-primary btn-sm py-0 px-2" style="display: none;">
                                                <i class="bi bi-calculator me-1"></i>Terapkan Tarif Rute
                                            </button>
                                        </div>
                                        <div id="travel_price_hint" class="form-text small mt-1" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL KENDARAAN -->
                        <div class="rnt-panel">
                            <div class="rnt-panel-header">
                                <span>Kendaraan</span>
                                <a href="<?= e(APP_URL) ?>/pages/vehicles/form.php" target="_blank" class="text-primary text-decoration-none small fw-semibold">
                                    Tambah Kendaraan Baru
                                </a>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Kategori</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="travel_category_select" name="category_vehicle_id" data-ts-placeholder="-- Semua Kategori Kendaraan --">
                                            <option value="">-- Semua Kategori Kendaraan --</option>
                                            <?= select_options($cats, $in['category_vehicle_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Kendaraan</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="travel_vehicle_select" name="vehicle_id"
                                            data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-parent="#travel_category_select"
                                            data-ts-param="category_id"
                                            data-ts-limit="30"
                                            data-ts-placeholder="-- Pilih Unit Kendaraan --">
                                            <option value="">-- Pilih Unit Kendaraan --</option>
                                            <?php foreach ($vehicles as $v):
                                                $sel = ((string) $v['id'] === (string) $in['vehicle_id']) ? ' selected' : '';
                                            ?>
                                                <option value="<?= (int) $v['id'] ?>"
                                                    data-category="<?= (int) ($v['category_vehicle_id'] ?? 0) ?>"
                                                    data-plate="<?= e((string) ($v['vehicle_number'] ?? '')) ?>"
                                                    data-seat="<?= (int) ($v['seat'] ?? 0) ?>"<?= $sel ?>><?= e($v['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL PENUMPANG -->
                        <div class="rnt-panel">
                            <div class="rnt-panel-header">
                                <span>Penumpang</span>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#modalQuickPassenger" class="text-primary text-decoration-none small fw-semibold">
                                    Tambah Penumpang Baru
                                </a>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Penumpang Utama <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="select_passenger_id" name="passenger_id" required
                                            data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-limit="30"
                                            data-ts-placeholder="-- Pilih Penumpang Utama --">
                                            <option value="">-- Pilih Penumpang Utama --</option>
                                            <?= select_options($passengers, $in['passenger_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1">Penumpang Tambahan</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="select_passenger_ids" name="passenger_ids[]" multiple
                                            data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-limit="30"
                                            placeholder="Pilih penumpang tambahan (opsional)...">
                                            <?= select_options($passengers, $in_passengers) ?>
                                        </select>
                                        <div id="seatCapacityWarning" class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" role="alert"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL PENJEMPUTAN & PENGANTARAN (2 KOLOM BERDAMPINGAN) -->
                        <div class="row g-3">
                            <!-- Kolom Kiri: Penjemputan -->
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header">
                                        <span>Penjemputan (Asal)</span>
                                    </div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="travel_pickup_province" name="pickup_point_province_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                    data-ts-with-code="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Semua Provinsi --">
                                                    <option value="">-- Semua Provinsi --</option>
                                                    <?= select_options($provinces, $in['pickup_point_province_id']) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kota/Kab <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="pickup_city" name="pickup_point_city_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-parent="#travel_pickup_province"
                                                    data-ts-param="province_id"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kota/Kab --">
                                                    <option value="">-- Pilih Kota/Kab --</option>
                                                    <?php foreach ($pickup_cities_view as $c):
                                                        $sel = ((string) $c['id'] === (string) $in['pickup_point_city_id']) ? ' selected' : '';
                                                    ?>
                                                        <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) ($c['province_id'] ?? 0) ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="pickup_district" name="pickup_point_district_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                    data-ts-parent="#pickup_city"
                                                    data-ts-param="city_id"
                                                    data-ts-require-parent="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kecamatan --">
                                                    <option value="">-- Pilih Kecamatan --</option>
                                                    <?= select_options($pickup_districts, $in['pickup_point_district_id']) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start">
                                            <label class="rnt-label pt-1">Detail Alamat</label>
                                            <div class="rnt-field">
                                                <textarea class="form-control form-control-sm" name="pickup_address" rows="4" maxlength="2000" placeholder="Alamat lengkap / titik jemput..."><?= e($in['pickup_address']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Kolom Kanan: Pengantaran -->
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header">
                                        <span>Pengantaran (Tujuan)</span>
                                    </div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="travel_delivery_province" name="delivery_point_province_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                    data-ts-with-code="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Semua Provinsi --">
                                                    <option value="">-- Semua Provinsi --</option>
                                                    <?= select_options($provinces, $in['delivery_point_province_id']) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kota/Kab <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="delivery_city" name="delivery_point_city_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-parent="#travel_delivery_province"
                                                    data-ts-param="province_id"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kota/Kab --">
                                                    <option value="">-- Pilih Kota/Kab --</option>
                                                    <?php foreach ($delivery_cities_view as $c):
                                                        $sel = ((string) $c['id'] === (string) $in['delivery_point_city_id']) ? ' selected' : '';
                                                    ?>
                                                        <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) ($c['province_id'] ?? 0) ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="delivery_district" name="delivery_point_district_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                    data-ts-parent="#delivery_city"
                                                    data-ts-param="city_id"
                                                    data-ts-require-parent="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kecamatan --">
                                                    <option value="">-- Pilih Kecamatan --</option>
                                                    <?= select_options($delivery_districts, $in['delivery_point_district_id']) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row align-items-start">
                                            <label class="rnt-label pt-1">Detail Alamat</label>
                                            <div class="rnt-field">
                                                <textarea class="form-control form-control-sm" name="delivery_address" rows="4" maxlength="2000" placeholder="Alamat lengkap / tujuan pengantaran..."><?= e($in['delivery_address']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================== -->
                    <!-- TAB 2: KETERANGAN                                          -->
                    <!-- ========================================================== -->
                    <div class="tab-pane fade" id="tab-note" role="tabpanel">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header">
                                <span>Keterangan</span>
                            </div>
                            <div class="rnt-panel-body">
                                <textarea class="form-control form-control-sm" name="note" rows="8" maxlength="2000" placeholder="Tuliskan catatan perjalanan, instruksi penjemputan, atau permintaan khusus..."><?= e($in['note']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================== -->
                    <!-- TAB 3: AUDIT TRAIL                                         -->
                    <!-- ========================================================== -->
                    <div class="tab-pane fade" id="tab-audit" role="tabpanel">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e($is_edit ? (trim((string) ($b['created_user'] ?? '')) ?: '-') : ($_SESSION['username'] ?? '-')) ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= $is_edit ? (!empty($b['created_date']) ? date('d-M-Y H:i', strtotime((string) $b['created_date'])) : '-') : date('d-M-Y H:i') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e($is_edit ? (trim((string) ($b['modified_user'] ?? '')) ?: '-') : '-') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= $is_edit ? (!empty($b['modified_date']) ? date('d-M-Y H:i', strtotime((string) $b['modified_date'])) : '-') : '-' ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /tab-content -->
            </form>
        </div><!-- /.erp-content-body -->
    </div><!-- /.erp-window-card -->
</main>

<!-- Modal Quick-Add Penumpang Baru -->
<div class="modal fade" id="modalQuickPassenger" tabindex="-1" aria-labelledby="modalQuickPassengerLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6 fw-semibold" id="modalQuickPassengerLabel"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Tambah Penumpang Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formQuickPassenger">
                <div class="modal-body">
                    <div id="quickPassAlert" class="alert alert-danger py-2 d-none small"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="qp_name" required placeholder="mis. Budi Santoso">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">No. Telepon / WhatsApp</label>
                        <input type="text" class="form-control form-control-sm" id="qp_phone" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold mb-1">Email (Opsional)</label>
                        <input type="email" class="form-control form-control-sm" id="qp_email" placeholder="budi@example.com">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSaveQuickPassenger"><i class="bi bi-check2 me-1"></i>Simpan &amp; Pilih</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Format Rupiah Input (totalPriceDisplay <-> travel_total_price)
    var priceDisplay = document.getElementById('totalPriceDisplay');
    var priceHidden  = document.getElementById('travel_total_price');
    function syncPriceDisplayFromHidden() {
        var raw = window.parseRupiah ? window.parseRupiah(priceHidden ? priceHidden.value : '0') : 0;
        if (priceDisplay) priceDisplay.value = window.formatRupiah ? window.formatRupiah(raw) : String(raw);
    }
    if (priceDisplay && priceHidden && window.attachRupiahInput) {
        window.attachRupiahInput(priceDisplay, priceHidden);
    }

    // 2. Flatpickr Tanggal Berangkat (dd-MMM-yyyy)
    var dateInp = document.getElementById('travel_date_input');
    if (dateInp && typeof flatpickr !== 'undefined' && !dateInp._flatpickr) {
        flatpickr(dateInp, {
            altInput: true,
            altFormat: "d-M-Y",
            dateFormat: "Y-m-d",
            altInputClass: "form-control form-control-sm bg-white",
            allowInput: false
        });
    }

    // 2b. Label AM/PM Bahasa Indonesia untuk Jam
    function getTimePeriodLabel(timeVal) {
        if (!timeVal) return '';
        var h = parseInt(String(timeVal).split(':')[0], 10);
        if (isNaN(h)) return '';
        if (h >= 0 && h < 5) return 'Dini Hari (AM)';
        if (h >= 5 && h < 11) return 'Pagi (AM)';
        if (h >= 11 && h < 15) return 'Siang (PM)';
        if (h >= 15 && h < 18) return 'Sore (PM)';
        return 'Malam (PM)';
    }
    var timeInp   = document.getElementById('travel_time_input');
    var timeBadge = document.getElementById('travel_time_period_badge');
    if (timeInp && timeBadge) {
        function updateTimeBadge() { timeBadge.textContent = getTimePeriodLabel(timeInp.value); }
        timeInp.addEventListener('input', updateTimeBadge);
        timeInp.addEventListener('change', updateTimeBadge);
        updateTimeBadge();
    }

    // 3. Helper sinkronisasi opsi TomSelect (pakai helper terpusat app.js)
    var updateSelectOptions = window.setTsOptions;
    var bindSelectChange = window.bindTsChange;

    // 4. Filter Kendaraan Berdasarkan Kategori (Tom Select + Server Remote Search) + Peringatan Kapasitas Kursi
    var catSelect   = document.getElementById('travel_category_select');
    var vehSelect   = document.getElementById('travel_vehicle_select');
    var seatWarnEl  = document.getElementById('seatCapacityWarning');
    var selectPassEl      = document.getElementById('select_passenger_id');
    var selectPassMultiEl = document.getElementById('select_passenger_ids');

    function updateTravelSeatWarn() {
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, selectPassEl, selectPassMultiEl, seatWarnEl);
        }
    }

    if (catSelect && vehSelect) {
        bindSelectChange(catSelect, function (cidVal) {
            var curVal = vehSelect.tomselect ? String(vehSelect.tomselect.getValue() || '') : String(vehSelect.value || '');
            var curOpt = vehSelect.tomselect ? vehSelect.tomselect.options[curVal] : null;
            var keepVal = (curVal && curOpt && (!cidVal || String(curOpt.category || '') === cidVal)) ? curVal : '';
            var u = '<?= e(APP_URL) ?>/pages/vehicles/options.php?company_id=<?= (int) $cid ?>&limit=50'
                  + (cidVal ? '&category_id=' + encodeURIComponent(cidVal) : '');
            fetch(u)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (keepVal && curOpt && !data.some(function (x) { return String(x.id) === keepVal; })) {
                        data.unshift(curOpt);
                    }
                    updateSelectOptions(vehSelect, data, keepVal, '-- Pilih Unit Kendaraan --');
                    updateTravelSeatWarn();
                });
            calculateTravelPrice(false);
        });
        bindSelectChange(vehSelect, function (vId) {
            var vOpt = vehSelect.tomselect ? vehSelect.tomselect.options[String(vId)] : null;
            var curCat = catSelect.tomselect ? String(catSelect.tomselect.getValue() || '') : String(catSelect.value || '');
            if (vOpt && vOpt.category && !curCat) {
                setTsValueSilent(catSelect, String(vOpt.category));
            }
            updateTravelSeatWarn();
        });
    }

    // 5. Dropdown Bertingkat Provinsi -> Kota/Kab -> Kecamatan (Tom Select + AJAX)
    function reloadCityOptions(provVal, targetCityId, targetDistId) {
        var cityEl = document.getElementById(targetCityId);
        var distEl = document.getElementById(targetDistId);
        if (!cityEl) return;
        if (distEl) updateSelectOptions(distEl, [], '', '-- Pilih Kecamatan --');
        var u = '<?= e(APP_URL) ?>/pages/masters/cities/options.php?limit=' + (provVal ? '100&province_id=' + encodeURIComponent(provVal) : '50&all=1');
        fetch(u)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                updateSelectOptions(cityEl, data, '', '-- Pilih Kota/Kab --');
            });
    }

    function reloadDistrictOptions(cityVal, targetSelectId, currentVal) {
        var el = document.getElementById(targetSelectId);
        if (!el) return;
        if (!cityVal) {
            updateSelectOptions(el, [], '', '-- Pilih Kecamatan --');
            return;
        }
        fetch('<?= e(APP_URL) ?>/pages/masters/districts/options.php?limit=100&city_id=' + encodeURIComponent(cityVal))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                updateSelectOptions(el, data, currentVal, '-- Pilih Kecamatan --');
            });
    }

    var pProv = document.getElementById('travel_pickup_province');
    var pCity = document.getElementById('pickup_city');
    if (pProv) bindSelectChange(pProv, function (val) { reloadCityOptions(val, 'pickup_city', 'pickup_district'); calculateTravelPrice(false); });
    if (pCity) bindSelectChange(pCity, function (val) {
        var opt = pCity.tomselect ? pCity.tomselect.options[String(val)] : null;
        var curP = pProv ? (pProv.tomselect ? String(pProv.tomselect.getValue() || '') : String(pProv.value || '')) : '';
        if (pProv && !curP && opt && opt.province_id) {
            setTsValueSilent(pProv, String(opt.province_id));
        }
        reloadDistrictOptions(val, 'pickup_district', '');
        calculateTravelPrice(false);
    });

    var dProv = document.getElementById('travel_delivery_province');
    var dCity = document.getElementById('delivery_city');
    if (dProv) bindSelectChange(dProv, function (val) { reloadCityOptions(val, 'delivery_city', 'delivery_district'); calculateTravelPrice(false); });
    if (dCity) bindSelectChange(dCity, function (val) {
        var opt = dCity.tomselect ? dCity.tomselect.options[String(val)] : null;
        var curP = dProv ? (dProv.tomselect ? String(dProv.tomselect.getValue() || '') : String(dProv.value || '')) : '';
        if (dProv && !curP && opt && opt.province_id) {
            setTsValueSilent(dProv, String(opt.province_id));
        }
        reloadDistrictOptions(val, 'delivery_district', '');
        calculateTravelPrice(false);
    });

    // 6. Quick-Add Penumpang Baru via AJAX Modal
    var qpForm  = document.getElementById('formQuickPassenger');
    var qpAlert = document.getElementById('quickPassAlert');
    var qpBtn   = document.getElementById('btnSaveQuickPassenger');
    var modalEl = document.getElementById('modalQuickPassenger');

    if (qpForm) {
        qpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            qpAlert.classList.add('d-none');
            qpBtn.disabled = true;
            qpBtn.textContent = 'Menyimpan...';

            var phoneVal = document.getElementById('qp_phone').value.trim();
            var payload = new FormData();
            payload.append('csrf_token', '<?= csrf_token() ?>');
            payload.append('name', document.getElementById('qp_name').value);
            payload.append('phone_number', phoneVal);
            payload.append('email', document.getElementById('qp_email').value);

            fetch('<?= e(APP_URL) ?>/pages/travel/passengers/quick_create.php', {
                method: 'POST',
                body: payload
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                qpBtn.disabled = false;
                qpBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Simpan &amp; Pilih';
                if (!res.ok) {
                    qpAlert.textContent = res.error || 'Gagal menyimpan penumpang.';
                    qpAlert.classList.remove('d-none');
                    return;
                }

                var p = res.data;
                var labelText = p.name + (phoneVal ? ' - ' + phoneVal : '');
                var pSelect = document.getElementById('select_passenger_id');
                var pMulti = document.getElementById('select_passenger_ids');
                if (pMulti) {
                    if (pMulti.tomselect) {
                        pMulti.tomselect.addOption({ value: String(p.id), text: labelText });
                        pMulti.tomselect.removeItem(String(p.id), true);
                        if (window.syncTsLastVal) window.syncTsLastVal(pMulti);
                    } else {
                        pMulti.add(new Option(labelText, String(p.id)));
                    }
                }
                if (pSelect) {
                    if (pSelect.tomselect) {
                        pSelect.tomselect.addOption({ value: String(p.id), text: labelText });
                        pSelect.tomselect.setValue(String(p.id));
                        if (window.syncTsLastVal) window.syncTsLastVal(pSelect);
                    } else {
                        var opt = new Option(labelText, String(p.id), true, true);
                        pSelect.add(opt);
                    }
                }
                updateTravelSeatWarn();

                qpForm.reset();
                var bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            })
            .catch(function () {
                qpBtn.disabled = false;
                qpBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Simpan &amp; Pilih';
                qpAlert.textContent = 'Terjadi kesalahan jaringan.';
                qpAlert.classList.remove('d-none');
            });
        });
    }

    // 7. Auto-Calculate Estimasi Total Harga Travel via Lookup Rute Terindeks (aman hingga jutaan rute)
    var routeCache   = {};
    var routeReqSeq  = 0;
    var priceHint    = document.getElementById('travel_price_hint');
    var btnApply     = document.getElementById('btn_apply_travel_calc');

    function getMultiSelectCount(el, excludeVal) {
        if (!el) return 0;
        var ex = excludeVal ? String(excludeVal) : '';
        if (el.tomselect) {
            var v = el.tomselect.getValue();
            var arr = Array.isArray(v) ? v : (v ? [v] : []);
            return arr.filter(function (x) { return x && String(x) !== ex; }).length;
        }
        var cnt = 0;
        if (el.selectedOptions) {
            for (var i = 0; i < el.selectedOptions.length; i++) {
                var val = el.selectedOptions[i].value;
                if (val && String(val) !== ex) cnt++;
            }
        }
        return cnt;
    }

    function renderMatchedRouteEstimate(matchedRoute, forceApply) {
        if (!matchedRoute) {
            if (priceHint) {
                priceHint.textContent = 'Tidak ditemukan tarif rute aktif untuk pasangan kota ini.';
                priceHint.className = 'form-text text-muted small mt-1';
                priceHint.style.display = 'block';
            }
            if (btnApply) btnApply.style.display = 'none';
            return;
        }

        var primVal = selectPassEl ? (selectPassEl.tomselect ? selectPassEl.tomselect.getValue() : selectPassEl.value) : '';
        var passCount = (primVal ? 1 : 0) + getMultiSelectCount(selectPassMultiEl, primVal);
        if (passCount === 0) passCount = 1;

        var unitPrice = parseFloat(matchedRoute.price) || 0;
        var estTotal  = Math.round(passCount * unitPrice);
        var fmtUnit   = 'Rp ' + (window.formatRupiah ? window.formatRupiah(Math.round(unitPrice)) : unitPrice);
        var fmtTotal  = 'Rp ' + (window.formatRupiah ? window.formatRupiah(estTotal) : estTotal);

        if (priceHint) {
            priceHint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Estimasi (' + matchedRoute.name + '): ' + passCount + ' penumpang &times; ' + fmtUnit + ' = ' + fmtTotal + '</span>';
            priceHint.style.display = 'block';
        }
        if (btnApply) btnApply.style.display = 'inline-block';

        var curPrice = window.parseRupiah ? window.parseRupiah(priceHidden.value) : 0;
        if (forceApply || curPrice <= 0) {
            priceHidden.value = String(estTotal);
            syncPriceDisplayFromHidden();
        }
    }

    function calculateTravelPrice(forceApply) {
        if (!pCity || !dCity || !priceHidden) return;

        var pcVal  = parseInt(pCity.tomselect ? pCity.tomselect.getValue() : pCity.value, 10) || 0;
        var dcVal  = parseInt(dCity.tomselect ? dCity.tomselect.getValue() : dCity.value, 10) || 0;
        var catVal = catSelect ? (parseInt(catSelect.tomselect ? catSelect.tomselect.getValue() : catSelect.value, 10) || 0) : 0;

        if (!pcVal || !dcVal) {
            if (priceHint) priceHint.style.display = 'none';
            if (btnApply) btnApply.style.display = 'none';
            return;
        }

        var cacheKey = pcVal + '-' + dcVal + '-' + catVal;
        if (Object.prototype.hasOwnProperty.call(routeCache, cacheKey)) {
            renderMatchedRouteEstimate(routeCache[cacheKey], forceApply);
            return;
        }

        var reqId = ++routeReqSeq;
        var u = '<?= e(APP_URL) ?>/pages/travel/rutes/lookup.php?company_id=<?= (int) $cid ?>'
              + '&pickup_point_city_id=' + pcVal
              + '&delivery_point_city_id=' + dcVal
              + (catVal > 0 ? '&category_vehicle_id=' + catVal : '');
        fetch(u, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) {
                var matched = (res && res.ok) ? (res.route || null) : null;
                routeCache[cacheKey] = matched;
                if (reqId !== routeReqSeq) return;
                renderMatchedRouteEstimate(matched, forceApply);
            })
            .catch(function () {});
    }

    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(selectPassEl, selectPassMultiEl, function () {
            updateTravelSeatWarn();
            calculateTravelPrice(false);
        });
    }
    updateTravelSeatWarn();
    if (btnApply) {
        btnApply.addEventListener('click', function (e) {
            e.preventDefault();
            calculateTravelPrice(true);
        });
    }
    if (pCity && pCity.value && dCity && dCity.value) {
        calculateTravelPrice(false);
    }

    // 8. Auto-switch ke tab field required jika validasi HTML5 gagal
    var travelFormEl = document.getElementById('formTravelBooking');
    if (travelFormEl) {
        travelFormEl.addEventListener('submit', function (e) {
            if (!this.checkValidity()) {
                e.preventDefault();
                var firstInvalid = this.querySelector(':invalid');
                if (firstInvalid) {
                    var pane = firstInvalid.closest('.tab-pane');
                    if (pane) {
                        var tabBtn = document.querySelector('[data-bs-target="#' + pane.id + '"]');
                        if (tabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                            bootstrap.Tab.getOrCreateInstance(tabBtn).show();
                        }
                    }
                    setTimeout(function () {
                        if (firstInvalid.tomselect) {
                            firstInvalid.tomselect.focus();
                        } else {
                            if (typeof firstInvalid.reportValidity === 'function') {
                                firstInvalid.reportValidity();
                            }
                            firstInvalid.focus();
                        }
                    }, 150);
                }
                return false;
            }
        });
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
