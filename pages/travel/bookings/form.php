<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/azure_blob.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $bChk = booking_find('travel', $id);
    if (!$bChk) {
        flash('error', 'Pemesanan tiket perjalanan tidak ditemukan.');
        redirect('pages/travel/bookings/index.php');
    }
    assert_company_access((int) $bChk['company_id']);
    redirect('pages/travel/bookings/detail.php?id=' . $id);
}

$cid = require_active_company();
$canManage = can('travel.manage');

$in = [
    'branch_id'                  => '',
    'date'                       => date('Y-m-d'),
    'time'                       => date('H:i'),
    'passenger_id'               => '',
    'category_vehicle_id'        => '',
    'vehicle_id'                 => '',
    'total_price'                => '0',
    'bbm'                        => '0',
    'pickup_point_province_id'   => '',
    'pickup_point_city_id'       => '',
    'pickup_point_district_id'   => '',
    'pickup_address'             => '',
    'delivery_point_province_id' => '',
    'delivery_point_city_id'     => '',
    'delivery_point_district_id' => '',
    'delivery_address'           => '',
    'note'                       => '',
];
$in_passengers = [];
$in_crew = [
    ['type' => 'Sopir',  'employee_id' => 0, 'amount' => 0],
    ['type' => 'Kernet', 'employee_id' => 0, 'amount' => 0],
];
$in_payments = [
    ['id' => 0, 'pay_type' => 'Down Payment (DP)', 'pay_method' => 'Transfer', 'pay_date' => date('Y-m-d'), 'amount' => 0, 'proof_blob_path' => '', 'is_auto_qris' => false],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($in) as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = trim((string) $_POST[$k]);
        }
    }
    $in['total_price'] = (string) parse_rupiah_input($_POST['total_price'] ?? $in['total_price']);
    $in['bbm']         = '0';

    $parsedCrew = booking_parse_crew_post($_POST);
    if ($parsedCrew) {
        $in_crew = $parsedCrew;
    }
    $parsedPayments = booking_parse_payments_post($_POST);
    if ($parsedPayments) {
        $in_payments = $parsedPayments;
    }

    $in_passengers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    $in_passengers = array_values(array_diff($in_passengers, [(int) $in['passenger_id']]));

    $pk_dist_resolved = resolve_district_id($in['pickup_point_district_id']);
    $dl_dist_resolved = resolve_district_id($in['delivery_point_district_id']);

    $err = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date'])) {
        $err = 'Tanggal keberangkatan wajib diisi.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        $err = 'Jam keberangkatan wajib diisi.';
    } elseif ((int) $in['branch_id'] <= 0 || (int) $in['passenger_id'] <= 0) {
        $err = 'Cabang dan pelanggan utama wajib dipilih.';
    } elseif ($pk_dist_resolved <= 0 || $dl_dist_resolved <= 0) {
        $err = 'Kecamatan penjemputan dan pengantaran wajib dipilih.';
    } elseif ((float) $in['total_price'] < 0) {
        $err = 'Total harga tidak boleh negatif.';
    } else {
        $manualPaySum = array_sum(array_map(static fn($r) => (float) ($r['amount'] ?? 0), $parsedPayments));
        if ($manualPaySum > (float) $in['total_price'] + 0.001) {
            $err = 'Total pembayaran (' . rupiah($manualPaySum) . ') tidak boleh melebihi total harga pemesanan (' . rupiah((float) $in['total_price']) . ').';
        }
    }

    if ($err === '') {
        $cat_id_post = (int) $in['category_vehicle_id'] ?: null;
        $veh_id_post = (int) $in['vehicle_id'] ?: null;
        $crewEmpIds  = array_values(array_filter(array_map('intval', array_column($parsedCrew, 'employee_id'))));
        $p_loc       = resolve_district_info($pk_dist_resolved) ?: [];
        $d_loc       = resolve_district_info($dl_dist_resolved) ?: [];
        $pk_city_res = !empty($in['pickup_point_city_id']) ? (int) $in['pickup_point_city_id'] : (int) ($p_loc['city_id'] ?? 0);
        $dl_city_res = !empty($in['delivery_point_city_id']) ? (int) $in['delivery_point_city_id'] : (int) ($d_loc['city_id'] ?? 0);
        $allPaxIds   = array_values(array_unique(array_merge([(int) $in['passenger_id']], $in_passengers)));
        try {
            validate_booking_company_scope(
                $cid,
                (int) $in['branch_id'],
                (int) $in['passenger_id'],
                $in_passengers,
                $cat_id_post,
                $veh_id_post,
                $crewEmpIds
            );
            $in['category_vehicle_id'] = (string) ($cat_id_post ?? '');
            travel_validate_route_price_bounds(
                $cid,
                $pk_city_res,
                $dl_city_res,
                (int) ($cat_id_post ?? 0),
                count($allPaxIds),
                (float) $in['total_price'],
                $pk_dist_resolved,
                $dl_dist_resolved
            );
        } catch (InvalidArgumentException $ex) {
            $err = $ex->getMessage();
        }
    }

    if ($err === '') {
        $data = [
            'company_id'                 => $cid,
            'branch_id'                  => (int) $in['branch_id'],
            'date'                       => $in['date'],
            'time'                       => $in['time'] . ':00',
            'pickup_address'             => $in['pickup_address'],
            'pickup_point_province_id'   => !empty($in['pickup_point_province_id']) ? (int) $in['pickup_point_province_id'] : ($p_loc['province_id'] ?? null),
            'pickup_point_city_id'       => $pk_city_res ?: null,
            'pickup_point_district_id'   => $pk_dist_resolved,
            'delivery_address'           => $in['delivery_address'],
            'delivery_point_province_id' => !empty($in['delivery_point_province_id']) ? (int) $in['delivery_point_province_id'] : ($d_loc['province_id'] ?? null),
            'delivery_point_city_id'     => $dl_city_res ?: null,
            'delivery_point_district_id' => $dl_dist_resolved,
            'total_price'                => (float) $in['total_price'],
            'category_vehicle_id'        => (int) $in['category_vehicle_id'] ?: null,
            'vehicle_id'                 => (int) $in['vehicle_id'] ?: null,
            'passenger_id'               => (int) $in['passenger_id'],
            'passenger_ids'              => $allPaxIds,
            'note'                       => $in['note'],
            'created_user'               => $_SESSION['username'] ?? null,
        ];

        try {
            $res   = booking_create('travel', $data);
            $newId = (int) ($res['id'] ?? 0);
            $invId = (int) ($res['invoice_id'] ?? 0);

            if ($newId > 0 && !empty($parsedCrew)) {
                booking_crew_sync('travel', $newId, $cid, 0.0, $parsedCrew, (int) $in['vehicle_id'] ?: null, $_SESSION['username'] ?? null);
            }
            if ($newId > 0 && $invId > 0 && !empty($parsedPayments)) {
                booking_upload_payment_proofs($parsedPayments, $cid, $invId);
                booking_payments_sync('travel', $newId, $invId, $cid, $parsedPayments, $_SESSION['username'] ?? null);
            }

            audit('booking.travel.create', "Pemesanan #{$newId} {$res['document_number']}");
            require_once __DIR__ . '/../../../includes/wa.php';
            wa_notify_booking('travel', $newId);
            flash('success', "Pemesanan tiket perjalanan {$res['document_number']} berhasil dibuat.");

            $goQris = !empty($_POST['redirect_qris']);
            redirect('pages/travel/bookings/detail.php?id=' . $newId . ($goQris ? '&open_qris=1#tab-payment' : ''));
        } catch (Throwable $ex) {
            $err = $ex->getMessage();
        }
    }
    if ($err !== '') {
        flash('error', $err);
    }
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

$passIncludeIds = array_values(array_filter(array_merge([(int) $in['passenger_id']], $in_passengers)));
$passengers     = opts_passengers($cid, $passIncludeIds, 50);
$cats           = opts_categories($cid, [(int) $in['category_vehicle_id']], 200);
$vehicles       = opts_vehicles_detailed(
    $cid,
    [$cid],
    (int) ($in['vehicle_id'] ?? 0) ?: null,
    !empty($in['category_vehicle_id']) ? (int) $in['category_vehicle_id'] : null,
    50
);

$crewEmpIds          = array_values(array_filter(array_map('intval', array_column($in_crew, 'employee_id'))));
$employees           = opts_employees($cid, $crewEmpIds, 50);
$company_regulations = regulation_list_by_company($cid);
$qrisChannels        = gateway_active_channels($cid);

$p_form_dist = resolve_district_id($in['pickup_point_district_id'] ?? 0);
$d_form_dist = resolve_district_id($in['delivery_point_district_id'] ?? 0);
if ($p_form_dist > 0) {
    $in['pickup_point_district_id'] = (string) $p_form_dist;
    if ($p_inf = resolve_district_info($p_form_dist)) {
        if ($in['pickup_point_city_id'] === '') $in['pickup_point_city_id'] = (string) $p_inf['city_id'];
        if ($in['pickup_point_province_id'] === '') $in['pickup_point_province_id'] = (string) $p_inf['province_id'];
    }
}
if ($d_form_dist > 0) {
    $in['delivery_point_district_id'] = (string) $d_form_dist;
    if ($d_inf = resolve_district_info($d_form_dist)) {
        if ($in['delivery_point_city_id'] === '') $in['delivery_point_city_id'] = (string) $d_inf['city_id'];
        if ($in['delivery_point_province_id'] === '') $in['delivery_point_province_id'] = (string) $d_inf['province_id'];
    }
}

$provinces          = opts_provinces([(int) ($in['pickup_point_province_id'] ?? 0), (int) ($in['delivery_point_province_id'] ?? 0)], 100);
$pickup_cities      = opts_cities(
    !empty($in['pickup_point_province_id']) ? (int) $in['pickup_point_province_id'] : null,
    [(int) ($in['pickup_point_city_id'] ?? 0)],
    !empty($in['pickup_point_province_id']) ? 100 : 50
);
$delivery_cities    = opts_cities(
    !empty($in['delivery_point_province_id']) ? (int) $in['delivery_point_province_id'] : null,
    [(int) ($in['delivery_point_city_id'] ?? 0)],
    !empty($in['delivery_point_province_id']) ? 100 : 50
);
$pickup_districts   = opts_districts_by_city((int) $in['pickup_point_city_id'], [(int) $in['pickup_point_district_id']], 100);
$delivery_districts = opts_districts_by_city((int) $in['delivery_point_city_id'], [(int) $in['delivery_point_district_id']], 100);

$curVehPreview = null;
if ((int) $in['vehicle_id'] > 0) {
    foreach ($vehicles as $vRow) {
        if ((int) $vRow['id'] === (int) $in['vehicle_id']) {
            $curVehPreview = $vRow;
            break;
        }
    }
}

$active = 'travel';
$title  = 'Buat Pemesanan Tiket Perjalanan';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <!-- Header Toolbar Halaman -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-ticket-perforated-fill me-1"></i>Pemesanan Tiket Perjalanan</span>
            <span class="badge bg-secondary-subtle text-secondary border">Draft Baru</span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Batal
            </a>
            <button type="submit" form="formTravelBooking" class="btn btn-primary btn-sm px-3">
                <i class="bi bi-check2-circle me-1"></i>Simpan Pemesanan
            </button>
        </div>
    </div>

    <?php
    $ai_endpoint = APP_URL . '/pages/travel/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- Sub-Tab Navigation (5 Tab ERP Seragam Rental) -->
        <ul class="nav nav-tabs erp-sub-tabs" id="travelFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-crew-btn" data-bs-toggle="tab" data-bs-target="#tab-crew" type="button" role="tab">Sopir/Kernet/Montir</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">Pembayaran Travel</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-note-btn" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">Keterangan</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <form method="post" id="formTravelBooking" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="tab-content" id="travelFormTabContent">
                <!-- ================= TAB 1: DETAIL ================= -->
                <div class="tab-pane fade show active" id="tab-detail" role="tabpanel" aria-labelledby="tab-detail-btn">
                    <!-- Panel Data Utama -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Informasi Pemesanan</span>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Resi</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" value="(Dibuat otomatis saat disimpan)" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Perusahaan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm bg-light" disabled>
                                        <option value="<?= (int) $curCompany['id'] ?>" selected><?= e($curCompany['label']) ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Cabang <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm" name="branch_id" required>
                                        <option value="">-- Pilih Cabang --</option>
                                        <?= select_options($branches, $in['branch_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Tanggal Keberangkatan <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="date" id="travel_date" required value="<?= e($in['date']) ?>" placeholder="dd-MMM-yyyy">
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Jam <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="time" class="form-control form-control-sm" name="time" id="travel_time" required value="<?= e(substr((string) $in['time'], 0, 5)) ?>" style="max-width:180px;">
                                        <span id="travelTimePeriodBadge" class="badge bg-light text-dark border">--</span>
                                    </div>
                                    <div id="travelRouteSchedulesBar" class="d-none flex-wrap align-items-center gap-1 mt-1"></div>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Harga <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <div class="d-flex flex-wrap flex-sm-nowrap align-items-center gap-2">
                                        <div class="input-group input-group-sm flex-grow-1" style="min-width:0;">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="totalPriceDisplay" placeholder="0" value="<?= e(format_nominal((float) $in['total_price'])) ?>" required>
                                        </div>
                                        <input type="hidden" id="travel_total_price" name="total_price" value="<?= e($in['total_price']) ?>">
                                        <button type="button" id="btn_apply_route_price" class="btn btn-sm btn-outline-primary flex-shrink-0 text-nowrap" style="display:none;">
                                            <i class="bi bi-magic me-1"></i>Terapkan Tarif Rute
                                        </button>
                                    </div>
                                    <div id="travel_route_price_hint" class="form-text small mt-1" style="display:none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Kendaraan -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Kendaraan</span>
                            <?php if (can('vehicles.manage')): ?>
                                <a href="<?= e(APP_URL) ?>/pages/vehicles/form.php" target="_blank" class="rnt-header-action">
                                    <i class="bi bi-plus-lg me-1"></i>Tambah Kendaraan Baru
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Kategori</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="category_vehicle_id" id="travel_category_select" data-ts-placeholder="-- Semua Kategori --">
                                        <option value="">-- Semua Kategori --</option>
                                        <?= select_options($cats, $in['category_vehicle_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Kendaraan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="vehicle_id" id="travel_vehicle_select"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                        data-ts-company="<?= (int) $cid ?>"
                                        data-ts-parent="#travel_category_select"
                                        data-ts-param="category_id"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Kendaraan (opsional) --">
                                        <option value="">-- Pilih Kendaraan (opsional) --</option>
                                        <?php foreach ($vehicles as $v):
                                            $sel = ((string) $v['id'] === (string) $in['vehicle_id']) ? ' selected' : '';
                                        ?>
                                            <option value="<?= (int) $v['id'] ?>"
                                                data-category="<?= (int) ($v['category_vehicle_id'] ?? 0) ?>"
                                                data-plate="<?= e($v['vehicle_number'] ?? '') ?>"
                                                data-brand="<?= e($v['brand_name'] ?? '') ?>"
                                                data-model="<?= e($v['model_name'] ?? '') ?>"
                                                data-color="<?= e($v['vehicle_color'] ?? '') ?>"
                                                data-seat="<?= (int) ($v['seat'] ?? 0) ?>"
                                                data-category-name="<?= e($v['category_name'] ?? '') ?>"<?= $sel ?>><?= e($v['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                    <div id="vehicleDetailPreview" class="mt-2 p-2 rounded bg-light border" style="<?= empty($curVehPreview) ? 'display:none;' : '' ?>">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <div class="fw-bold text-dark" id="vehPreviewPlate" style="font-size:0.95rem;">
                                                    <i class="bi bi-car-front-fill text-primary me-1"></i>
                                                    <span><?= e($curVehPreview['vehicle_number'] ?? '') ?></span>
                                                </div>
                                                <div class="small text-muted" id="vehPreviewModel" style="font-size:0.8rem;">
                                                    <?= e(trim(($curVehPreview['brand_name'] ?? '') . ' ' . ($curVehPreview['model_name'] ?? ''))) ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-primary-subtle text-primary border" id="vehPreviewCategory">
                                                    <?= e($curVehPreview['category_name'] ?? 'Armada') ?>
                                                </span>
                                                <div class="small text-muted mt-1" id="vehPreviewSpecs" style="font-size:0.75rem;">
                                                    <?= e(trim(($curVehPreview['vehicle_color'] ?? '') . (!empty($curVehPreview['seat']) ? ' • ' . $curVehPreview['seat'] . ' Kursi' : ''))) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Pelanggan / Penumpang -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Pelanggan &amp; Penumpang</span>
                            <a href="#" class="rnt-header-action" data-bs-toggle="modal" data-bs-target="#modalQuickPassenger">
                                <i class="bi bi-person-plus me-1"></i>Tambah Pelanggan Baru
                            </a>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Pelanggan Utama <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_id" id="select_passenger_id"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/transport/passengers/options.php"
                                        data-ts-company="<?= (int) $cid ?>"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Pelanggan Utama --"
                                        required>
                                        <option value="">-- Pilih Pelanggan Utama --</option>
                                        <?= select_options($passengers, $in['passenger_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Penumpang Tambahan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_ids[]" id="select_passenger_ids" multiple
                                        data-ts-url="<?= e(APP_URL) ?>/pages/transport/passengers/options.php"
                                        data-ts-company="<?= (int) $cid ?>"
                                        data-ts-limit="30"
                                        placeholder="Pilih penumpang tambahan (opsional)...">
                                        <?= select_options($passengers, $in_passengers) ?>
                                    </select>
                                    <div class="form-text small">Opsional — penumpang tambahan dihitung ke total tarif rute &amp; tampil di E-Tiket.</div>
                                    <div id="seatCapacityWarning" class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" role="alert"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Penjemputan & Pengantaran 2 Kolom -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Penjemputan</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">Provinsi</label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" id="travel_pickup_province" name="pickup_point_province_id"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --">
                                                <option value="">-- Pilih Provinsi --</option>
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
                                                <?php foreach ($pickup_cities as $c):
                                                    $sel = ((string) $c['id'] === (string) $in['pickup_point_city_id']) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" id="pickup_district" name="pickup_point_district_id"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#pickup_city"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="100"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                required>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?php foreach ($pickup_districts as $d):
                                                    $sel = ((string) $d['id'] === (string) $in['pickup_point_district_id']) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $d['id'] ?>" data-city="<?= (int) $d['city_id'] ?>"<?= $sel ?>><?= e($d['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Detail Alamat</label>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="pickup_address" rows="4" maxlength="2000" placeholder="Alamat lengkap penjemputan / patokan..."><?= e($in['pickup_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Pengantaran</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">Provinsi</label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" id="travel_delivery_province" name="delivery_point_province_id"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --">
                                                <option value="">-- Pilih Provinsi --</option>
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
                                                <?php foreach ($delivery_cities as $c):
                                                    $sel = ((string) $c['id'] === (string) $in['delivery_point_city_id']) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" id="delivery_district" name="delivery_point_district_id"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#delivery_city"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="100"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                required>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?php foreach ($delivery_districts as $d):
                                                    $sel = ((string) $d['id'] === (string) $in['delivery_point_district_id']) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $d['id'] ?>" data-city="<?= (int) $d['city_id'] ?>"<?= $sel ?>><?= e($d['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Detail Alamat</label>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="delivery_address" rows="4" maxlength="2000" placeholder="Alamat lengkap tujuan pengantaran..."><?= e($in['delivery_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 2: SOPIR/KERNET/MONTIR ================= -->
                <?php
                $formId      = 'formTravelBooking';
                $companyId   = $cid;
                $showBbm     = false;
                $bbm         = 0.0;
                $isDetailMode = false;
                $crew_rows   = $in_crew;
                $regulations = $company_regulations;
                $activeTab   = 'detail';
                $assignments = [];
                $dispatchUrl = '';
                require __DIR__ . '/../../../includes/booking_crew_tab.php';
                ?>

                <!-- ================= TAB 3: PEMBAYARAN TRAVEL ================= -->
                <?php
                $formId       = 'formTravelBooking';
                $payments     = $in_payments;
                $totalPrice   = (float) $in['total_price'];
                $isDetailMode = false;
                $tabTitle     = 'Pembayaran Travel';
                require __DIR__ . '/../../../includes/booking_payment_tab.php';
                ?>

                <!-- ================= TAB 4: KETERANGAN ================= -->
                <div class="tab-pane fade" id="tab-note" role="tabpanel" aria-labelledby="tab-note-btn">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Keterangan / Catatan Tambahan</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Catatan</label>
                                <div class="rnt-field">
                                    <textarea class="form-control form-control-sm" name="note" rows="5" maxlength="2000" placeholder="Tulis catatan operasional, permintaan kursi/bagasi, atau instruksi khusus..."><?= e($in['note']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 5: AUDIT TRAIL ================= -->
                <div class="tab-pane fade" id="tab-audit" role="tabpanel" aria-labelledby="tab-audit-btn">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Audit Trail Dokumen</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e($_SESSION['username'] ?? '-') ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e(date('d-M-Y H:i')) ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="-" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="-" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        </div><!-- /.erp-content-body -->
    </div><!-- /.erp-window-card -->
</main>

<!-- Modal Quick-Add Pelanggan Baru -->
<div class="modal fade" id="modalQuickPassenger" tabindex="-1" aria-labelledby="modalQuickPassengerLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold" id="modalQuickPassengerLabel"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Tambah Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formQuickPassenger">
                <div class="modal-body">
                    <div id="quickPassAlert" class="alert alert-danger py-2 d-none"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="qp_name" required placeholder="mis. Ajun / Budi Santoso">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">No. Telepon / WhatsApp</label>
                        <input type="text" class="form-control" id="qp_phone" placeholder="62812xxxxxxxx">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email (Opsional)</label>
                        <input type="email" class="form-control" id="qp_email" placeholder="nama@example.com">
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

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
<script src="<?= e(APP_URL) ?>/assets/js/booking_erp.js?v=<?= @filemtime(__DIR__ . '/../../../assets/js/booking_erp.js') ?: time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const appUrl    = <?= json_encode(APP_URL) ?>;
    const companyId = <?= (int) $cid ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    const dateEl      = document.getElementById('travel_date');
    const timeEl      = document.getElementById('travel_time');
    const timeBadgeEl = document.getElementById('travelTimePeriodBadge');
    const priceDisp   = document.getElementById('totalPriceDisplay');
    const priceHidden = document.getElementById('travel_total_price');
    const catSelect   = document.getElementById('travel_category_select');
    const vehSelect   = document.getElementById('travel_vehicle_select');
    const passEl      = document.getElementById('select_passenger_id');
    const extraPassEl = document.getElementById('select_passenger_ids');
    const seatWarnEl  = document.getElementById('seatCapacityWarning');

    const pProvEl = document.getElementById('travel_pickup_province');
    const pCityEl = document.getElementById('pickup_city');
    const pDistEl = document.getElementById('pickup_district');
    const dProvEl = document.getElementById('travel_delivery_province');
    const dCityEl = document.getElementById('delivery_city');
    const dDistEl = document.getElementById('delivery_district');

    const priceHint   = document.getElementById('travel_route_price_hint');
    const btnApplyRt  = document.getElementById('btn_apply_route_price');

    if (dateEl && !dateEl._flatpickr && typeof flatpickr !== 'undefined') {
        flatpickr(dateEl, {
            altInput: true,
            altFormat: 'd-M-Y',
            dateFormat: 'Y-m-d',
            allowInput: false
        });
    }

    BookingErp.initTabs({ tabNavId: 'travelFormTabs', defaultTab: 'detail' });
    BookingErp.bindTimeBadge(timeEl, timeBadgeEl);

    const vehPreviewCtrl = BookingErp.initVehiclePreview({
        vehicleSelectEl: vehSelect,
        categorySelectEl: catSelect,
        previewEl: document.getElementById('vehicleDetailPreview'),
        plateEl: document.getElementById('vehPreviewPlate') ? document.getElementById('vehPreviewPlate').querySelector('span') : null,
        modelEl: document.getElementById('vehPreviewModel'),
        catEl: document.getElementById('vehPreviewCategory'),
        specsEl: document.getElementById('vehPreviewSpecs')
    });

    const payCtrl = BookingErp.initPaymentTab({
        formId: 'formTravelBooking',
        canManage: true,
        isDetailMode: false,
        todayIso: <?= json_encode(date('Y-m-d')) ?>,
        getBillTotal: function () {
            return priceHidden ? BookingErp.parseRp(priceHidden.value) : 0;
        }
    });

    BookingErp.initCrewTab({
        formId: 'formTravelBooking',
        appUrl: appUrl,
        companyId: companyId,
        canManage: true,
        showBbm: false,
        getCategoryId: function () {
            return catSelect ? (catSelect.tomselect ? catSelect.tomselect.getValue() : catSelect.value) : 0;
        },
        getTotalPrice: function () {
            return priceHidden ? BookingErp.parseRp(priceHidden.value) : 0;
        },
        getStartDate: function () {
            return dateEl ? dateEl.value : <?= json_encode(date('Y-m-d')) ?>;
        },
        getTrip: function () { return 1; }
    });

    if (priceDisp && priceHidden && window.attachRupiahInput) {
        window.attachRupiahInput(priceDisp, priceHidden, function () {
            payCtrl.recalcPayTotals();
        });
    }
    if (priceHidden) {
        priceHidden.addEventListener('change', function () {
            if (priceDisp) priceDisp.value = BookingErp.fmtRp(this.value) || '0';
            payCtrl.recalcPayTotals();
        });
    }

    let routeCtrl = null;
    BookingErp.initFormTabValidation({
        formId: 'formTravelBooking',
        paymentCtrl: payCtrl,
        getBillTotal: function () {
            return priceHidden ? BookingErp.parseRp(priceHidden.value) : 0;
        },
        beforeSubmit: function () {
            return routeCtrl ? routeCtrl.validateBounds() : { ok: true };
        }
    });

    routeCtrl = BookingErp.initTravelRouteLookup({
        appUrl: appUrl,
        lookupUrl: appUrl + '/pages/travel/rutes/lookup.php',
        companyId: companyId,
        pickupCityEl: pCityEl,
        deliveryCityEl: dCityEl,
        pickupDistrictEl: pDistEl,
        deliveryDistrictEl: dDistEl,
        categoryEl: catSelect,
        primaryPassengerEl: passEl,
        extraPassengersEl: extraPassEl,
        hintEl: priceHint,
        applyBtnEl: btnApplyRt,
        priceDisplayEl: priceDisp,
        priceValueEl: priceHidden,
        scheduleContainerEl: document.getElementById('travelRouteSchedulesBar'),
        timeInputEl: timeEl,
        autoFillZero: true,
        notFoundText: 'Belum ada tarif rute aktif untuk pasangan kota & kategori ini.',
        onPriceApplied: function () { payCtrl.recalcPayTotals(); }
    });

    function syncCapacity() {
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, passEl, extraPassEl, seatWarnEl);
        }
    }
    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(passEl, extraPassEl, function () {
            syncCapacity();
            routeCtrl.lookup(false);
        });
    }
    if (vehSelect) {
        vehSelect.addEventListener('change', syncCapacity);
    }
    syncCapacity();

    BookingErp.initCategoryVehicleFilter({
        appUrl: appUrl,
        companyId: companyId,
        categorySelectEl: catSelect,
        vehicleSelectEl: vehSelect,
        placeholder: '-- Pilih Kendaraan (opsional) --',
        onChanged: function () {
            if (vehPreviewCtrl) vehPreviewCtrl.refresh();
            syncCapacity();
            routeCtrl.lookup(false);
        }
    });

    BookingErp.initGeoCascade({
        appUrl: appUrl,
        provinceEl: pProvEl,
        cityEl: pCityEl,
        districtEl: pDistEl,
        onCityChange: function () { routeCtrl.lookup(false); }
    });
    BookingErp.initGeoCascade({
        appUrl: appUrl,
        provinceEl: dProvEl,
        cityEl: dCityEl,
        districtEl: dDistEl,
        onCityChange: function () { routeCtrl.lookup(false); }
    });

    routeCtrl.lookup(false);

    // Quick-Add Pelanggan Baru
    BookingErp.initQuickPassengerModal({
        formId: 'formQuickPassenger',
        modalId: 'modalQuickPassenger',
        alertId: 'quickPassAlert',
        saveBtnId: 'btnSaveQuickPassenger',
        nameInputId: 'qp_name',
        phoneInputId: 'qp_phone',
        emailInputId: 'qp_email',
        primarySelectEl: passEl,
        extraSelectEl: extraPassEl,
        appUrl: appUrl,
        companyId: companyId,
        csrfToken: csrfToken
    });
});
</script>
