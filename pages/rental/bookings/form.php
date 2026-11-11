<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.manage');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';

$cid = require_active_company();
$in  = [
    'date'                       => date('Y-m-d'),
    'time'                       => '05:00',
    'start_date'                 => date('Y-m-d'),
    'end_date'                   => date('Y-m-d'),
    'duration_days'              => '1',
    'trip'                       => '0',
    'bbm'                        => '0',
    'branch_id'                  => '',
    'passenger_id'               => '',
    'category_vehicle_id'        => '',
    'vehicle_id'                 => '',
    'employee_id'                => '',
    'total_price'                => '0',
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
    ['type' => 'Sopir',  'employee_id' => '', 'amount' => '0'],
    ['type' => 'Kernet', 'employee_id' => '', 'amount' => '0'],
];
$in_payments = [
    ['pay_type' => 'Down Payment (DP)', 'pay_method' => 'Transfer', 'pay_date' => date('Y-m-d'), 'amount' => '0'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($in) as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = trim((string) $_POST[$k]);
        }
    }
    // Sinkronkan date = start_date bila date tidak dikirim terpisah
    if ($in['start_date'] !== '') {
        $in['date'] = $in['start_date'];
    }
    // Normalisasi total_price & bbm jika berformat ribuan (2.000.000 / 2,000,000 -> 2000000)
    $in['total_price'] = (string) parse_rupiah_input($in['total_price']);
    $in['bbm']         = (string) parse_rupiah_input($in['bbm']);

    // Ambil baris kru/premi dari Tab Sopir/Kernet/Montir
    $c_types = (array) ($_POST['crew_type'] ?? []);
    $c_emps  = (array) ($_POST['crew_employee_id'] ?? []);
    $c_amts  = (array) ($_POST['crew_amount'] ?? []);
    $in_crew = [];
    foreach ($c_types as $idx => $ct) {
        $empId = (int) ($c_emps[$idx] ?? 0);
        $amt   = parse_rupiah_input($c_amts[$idx] ?? 0);
        if ($empId > 0 || $amt > 0) {
            $in_crew[] = [
                'type'        => trim((string) $ct) ?: 'Sopir',
                'employee_id' => $empId,
                'amount'      => $amt,
            ];
        }
    }

    // Ambil baris pembayaran dari Tab Pembayaran Sewa
    $p_types   = (array) ($_POST['pay_type'] ?? []);
    $p_methods = (array) ($_POST['pay_method'] ?? []);
    $p_dates   = (array) ($_POST['pay_date'] ?? []);
    $p_amts    = (array) ($_POST['pay_amount'] ?? []);
    $in_payments = [];
    foreach ($p_types as $idx => $pt) {
        $pAmt = parse_rupiah_input($p_amts[$idx] ?? 0);
        if ($pAmt > 0) {
            $in_payments[] = [
                '_file_idx'  => (int) $idx,
                'pay_type'   => trim((string) $pt) ?: 'Down Payment (DP)',
                'pay_method' => trim((string) ($p_methods[$idx] ?? 'Transfer')) ?: 'Transfer',
                'pay_date'   => trim((string) ($p_dates[$idx] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'amount'     => $pAmt,
            ];
        }
    }

    $in_passengers = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    $in_passengers = array_values(array_diff($in_passengers, [(int) $in['passenger_id']]));

    $err = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['end_date'])) {
        $err = 'Tanggal keberangkatan (mulai & selesai sewa) wajib diisi.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        $err = 'Jam keberangkatan wajib diisi.';
    } elseif ($in['end_date'] < $in['start_date']) {
        $err = 'Tanggal selesai sewa tidak boleh sebelum tanggal mulai.';
    } elseif ((int) $in['branch_id'] <= 0 || (int) $in['passenger_id'] <= 0) {
        $err = 'Cabang dan pelanggan wajib dipilih.';
    } elseif ((int) $in['pickup_point_district_id'] <= 0 || (int) $in['delivery_point_district_id'] <= 0) {
        $err = 'Kecamatan penjemputan dan pengantaran wajib dipilih.';
    } elseif ((float) $in['trip'] < 0 || (float) $in['bbm'] < 0 || (float) $in['total_price'] < 0) {
        $err = 'Trip, BBM, dan harga sewa tidak boleh negatif.';
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
        // Resolusi city_id & province_id dari district penjemputan & pengantaran bila kosong
        $st = db()->prepare('SELECT city_id, province_id FROM districts WHERE id = ?');
        $p_dist_id = (int) $in['pickup_point_district_id'];
        $st->bind_param('i', $p_dist_id);
        $st->execute();
        $p_loc = $st->get_result()->fetch_assoc() ?: [];

        $d_dist_id = (int) $in['delivery_point_district_id'];
        $st->bind_param('i', $d_dist_id);
        $st->execute();
        $d_loc = $st->get_result()->fetch_assoc() ?: [];
        $st->close();

        $data = [
            'company_id'                 => $cid,
            'branch_id'                  => (int) $in['branch_id'],
            'date'                       => $in['date'],
            'time'                       => $in['time'] . ':00',
            'start_date'                 => $in['start_date'],
            'end_date'                   => $in['end_date'],
            'trip'                       => (float) $in['trip'],
            'bbm'                        => (float) $in['bbm'],
            'pickup_address'             => $in['pickup_address'],
            'pickup_point_province_id'   => !empty($in['pickup_point_province_id']) ? (int) $in['pickup_point_province_id'] : ($p_loc['province_id'] ?? null),
            'pickup_point_city_id'       => !empty($in['pickup_point_city_id']) ? (int) $in['pickup_point_city_id'] : ($p_loc['city_id'] ?? null),
            'pickup_point_district_id'   => $p_dist_id,
            'delivery_address'           => $in['delivery_address'],
            'delivery_point_province_id' => !empty($in['delivery_point_province_id']) ? (int) $in['delivery_point_province_id'] : ($d_loc['province_id'] ?? null),
            'delivery_point_city_id'     => !empty($in['delivery_point_city_id']) ? (int) $in['delivery_point_city_id'] : ($d_loc['city_id'] ?? null),
            'delivery_point_district_id' => $d_dist_id,
            'total_price'                => (float) $in['total_price'],
            'category_vehicle_id'        => (int) $in['category_vehicle_id'] ?: null,
            'vehicle_id'                 => (int) $in['vehicle_id'] ?: null,
            'passenger_id'               => (int) $in['passenger_id'],
            'passenger_ids'              => array_values(array_merge([(int) $in['passenger_id']], $in_passengers)),
            'note'                       => $in['note'],
            'created_user'               => $_SESSION['username'] ?? null,
        ];
        try {
            $res = booking_create('rental', $data);
            // Simpan rincian kru/premi (Sopir, Kernet, Montir, Agen) + BBM + Total Biaya Operasional
            if (!empty($in_crew) || (float) $in['bbm'] > 0) {
                booking_rental_crew_sync((int) $res['id'], $cid, (float) $in['bbm'], $in_crew, (int) $in['vehicle_id'] ?: null, $_SESSION['username'] ?? null);
            } elseif ((int) $in['employee_id'] > 0) {
                booking_assign_driver('rental', (int) $res['id'], $cid, (int) $in['employee_id'], (int) $in['vehicle_id'] ?: null, $_SESSION['username'] ?? null);
            }
            // Simpan rincian Pembayaran Sewa (DP / Pelunasan / Transfer / lampiran bukti)
            if (!empty($in_payments)) {
                foreach ($in_payments as &$pr) {
                    $fIdx = (int) ($pr['_file_idx'] ?? 0);
                    $pr['proof_blob_path'] = azure_blob_handle_upload_indexed(
                        'pay_proof',
                        $fIdx,
                        $cid,
                        'finance/payment-proofs',
                        date('Y/m'),
                        'inv-' . (int) $res['invoice_id']
                    );
                }
                unset($pr);
                booking_rental_payments_sync((int) $res['id'], (int) $res['invoice_id'], $cid, $in_payments, $_SESSION['username'] ?? null);
            }
            audit('booking.rental.create', "Booking #{$res['id']} {$res['document_number']}");
            require_once __DIR__ . '/../../../includes/wa.php';
            wa_notify_booking('rental', (int) $res['id']);
            flash('success', "Booking {$res['document_number']} berhasil dibuat.");
            if (!empty($_POST['redirect_qris']) && (int) $res['invoice_id'] > 0) {
                redirect('pages/finance/invoices/gateway_pay.php?invoice_id=' . (int) $res['invoice_id']);
            }
            redirect('pages/rental/bookings/detail.php?id=' . $res['id']);
        } catch (mysqli_sql_exception $ex) {
            throw $ex;
        }
    }
    flash('error', $err);
}

$stComp = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $cid);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $cid, 'label' => active_company_name()];
$stComp->close();

$branches   = opts_branches($cid);
if ($in['branch_id'] === '' && !empty($branches[0]['id'])) {
    $in['branch_id'] = (string) $branches[0]['id'];
}
$passengers = opts_passengers($cid);
$cats       = opts_categories($cid);
$vehicles   = opts_vehicles($cid);
$employees  = opts_employees($cid);
$provinces  = opts_provinces();
$cities     = opts_cities();
$company_regulations = regulation_list_by_company($cid);

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
<style>
    .rnt-tabs .nav-link {
        color: #495057;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: none;
        margin-right: 4px;
        font-size: .875rem;
        font-weight: 500;
        padding: .5rem 1rem;
        border-top-left-radius: .375rem;
        border-top-right-radius: .375rem;
    }
    .rnt-tabs .nav-link.active {
        color: #0d6efd;
        background: #fff;
        font-weight: 600;
        border-bottom: 2px solid #fff;
        margin-bottom: -1px;
    }
    .rnt-panel {
        border: 1px solid #dee2e6;
        border-radius: .375rem;
        background: #fff;
        margin-bottom: 1rem;
    }
    .rnt-panel-header {
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        padding: .5rem .875rem;
        font-size: .85rem;
        font-weight: 600;
        color: #343a40;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top-left-radius: .375rem;
        border-top-right-radius: .375rem;
    }
    .rnt-panel-body {
        padding: .875rem;
    }
    .rnt-row {
        display: flex;
        align-items: center;
        margin-bottom: .5rem;
    }
    .rnt-row:last-child {
        margin-bottom: 0;
    }
    .rnt-label {
        width: 165px;
        flex-shrink: 0;
        font-size: .84rem;
        color: #343a40;
        font-weight: 500;
        padding-right: .5rem;
    }
    .rnt-field {
        flex: 1 1 auto;
        min-width: 0;
    }
    .rnt-field .ts-control {
        min-height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        border-radius: 0.25rem;
    }
    @media (max-width: 767.98px) {
        .rnt-row { flex-direction: column; align-items: stretch; }
        .rnt-label { width: 100%; margin-bottom: .25rem; }
    }
</style>

<main class="p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-0"><i class="bi bi-car-front-fill text-primary me-2"></i><?= e($title) ?></h1>
            <div class="text-muted small">Formulir pemesanan sewa kendaraan / bus pariwisata</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Kembali ke Daftar
            </a>
        </div>
    </div>

    <?php
    $ai_endpoint = APP_URL . '/pages/rental/bookings/ai_parse.php';
    require __DIR__ . '/../../../includes/ai_panel.php';
    ?>

    <form method="post" action="" id="formRentalBooking" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="date" id="rental_booking_date" value="<?= e($in['date']) ?>">
        <input type="hidden" name="redirect_qris" id="redirect_qris_flag" value="">

        <!-- Navigasi 5 Tab (Detail | Sopir/Kernet/Montir | Pembayaran Sewa | Keterangan | Audit Trail) -->
        <ul class="nav nav-tabs rnt-tabs border-bottom-0" id="rentalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">
                    <i class="bi bi-card-list me-1"></i>Detail
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-crew-btn" data-bs-toggle="tab" data-bs-target="#tab-crew" type="button" role="tab">
                    <i class="bi bi-person-badge me-1"></i>Sopir/Kernet/Montir
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">
                    <i class="bi bi-cash-coin me-1"></i>Pembayaran Sewa
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-note-btn" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">
                    <i class="bi bi-journal-text me-1"></i>Keterangan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-audit" type="button" role="tab">
                    <i class="bi bi-clock-history me-1"></i>Audit Trail
                </button>
            </li>
        </ul>

        <div class="tab-content border rounded-bottom rounded-end bg-white p-3 shadow-sm">
            <!-- ========================================================== -->
            <!-- TAB 1: DETAIL                                              -->
            <!-- ========================================================== -->
            <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                <div class="mb-3">
                    <!-- Resi -->
                    <div class="rnt-row">
                        <label class="rnt-label">Resi</label>
                        <div class="rnt-field">
                            <input type="text" class="form-control form-control-sm fw-bold bg-light font-monospace" readonly value="OTOMATIS (RNT-XXXXXXXX)">
                        </div>
                    </div>

                    <!-- Perusahaan -->
                    <div class="rnt-row">
                        <label class="rnt-label">Perusahaan</label>
                        <div class="rnt-field">
                            <input type="text" class="form-control form-control-sm bg-light fw-semibold" readonly value="<?= e($curCompany['label']) ?>">
                        </div>
                    </div>

                    <!-- Cabang -->
                    <div class="rnt-row">
                        <label class="rnt-label">Cabang <span class="text-danger">*</span></label>
                        <div class="rnt-field">
                            <select class="form-select form-select-sm ts-select" name="branch_id" required>
                                <option value="">-- Pilih Cabang --</option>
                                <?= select_options($branches, $in['branch_id']) ?>
                            </select>
                        </div>
                    </div>

                    <!-- Tanggal Keberangkatan (Mulai | Selesai | Durasi Hari) -->
                    <div class="rnt-row">
                        <label class="rnt-label">Tanggal Keberangkatan <span class="text-danger">*</span></label>
                        <div class="rnt-field">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-5">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" id="rental_start_date" name="start_date" required value="<?= e($in['start_date']) ?>" placeholder="dd-MMM-yyyy">
                                </div>
                                <div class="col-sm-5">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" id="rental_end_date" name="end_date" required value="<?= e($in['end_date']) ?>" placeholder="dd-MMM-yyyy">
                                </div>
                                <div class="col-sm-2">
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="1" max="365" class="form-control form-control-sm text-center fw-semibold" id="rental_duration_days" name="duration_days" value="<?= e($in['duration_days']) ?>" title="Durasi Sewa (Hari)">
                                        <span class="input-group-text">hr</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Jam -->
                    <div class="rnt-row">
                        <label class="rnt-label">Jam <span class="text-danger">*</span></label>
                        <div class="rnt-field">
                            <div class="input-group input-group-sm" style="max-width: 260px;">
                                <input type="time" class="form-control form-control-sm" name="time" id="rental_time_input" required value="<?= e($in['time']) ?>">
                                <span class="input-group-text bg-light text-muted fw-semibold" id="rental_time_period_badge"><?= time_period_label($in['time']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Harga -->
                    <div class="rnt-row">
                        <label class="rnt-label">Harga <span class="text-danger">*</span></label>
                        <div class="rnt-field">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light fw-semibold">Rp</span>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm fw-bold" id="rental_price_display" placeholder="0" value="<?= format_nominal((float) $in['total_price']) ?>">
                                <input type="hidden" id="rental_total_price" name="total_price" value="<?= e($in['total_price']) ?>">
                            </div>
                            <div id="rental_calc_hint" class="form-text small" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- PANEL KENDARAAN -->
                <div class="rnt-panel">
                    <div class="rnt-panel-header">
                        <span><i class="bi bi-bus-front me-1 text-primary"></i>Kendaraan</span>
                        <a href="<?= e(APP_URL) ?>/pages/vehicles/form.php" target="_blank" class="text-primary text-decoration-none small fw-semibold">
                            <i class="bi bi-plus-circle me-1"></i>Tambah Kendaraan Baru
                        </a>
                    </div>
                    <div class="rnt-panel-body">
                        <div class="rnt-row">
                            <label class="rnt-label">Kategori</label>
                            <div class="rnt-field">
                                <select class="form-select form-select-sm ts-select" id="rental_category_select" name="category_vehicle_id">
                                    <option value="">-- Semua Kategori Kendaraan --</option>
                                    <?= select_options($cats, $in['category_vehicle_id']) ?>
                                </select>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Kendaraan</label>
                            <div class="rnt-field">
                                <select class="form-select form-select-sm ts-select" id="rental_vehicle_select" name="vehicle_id">
                                    <option value="">-- Pilih Unit Kendaraan --</option>
                                    <?php foreach ($vehicles as $v):
                                        $sel = ((string) $v['id'] === (string) $in['vehicle_id']) ? ' selected' : '';
                                    ?>
                                        <option value="<?= (int) $v['id'] ?>" data-category="<?= (int) ($v['category_vehicle_id'] ?? 0) ?>"<?= $sel ?>><?= e($v['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="rental_vehicle_alert" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PANEL PELANGGAN -->
                <div class="rnt-panel">
                    <div class="rnt-panel-header">
                        <span><i class="bi bi-person-vcard me-1 text-primary"></i>Pelanggan</span>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#modalQuickPassenger" class="text-primary text-decoration-none small fw-semibold">
                            <i class="bi bi-person-plus-fill me-1"></i>Tambah Pelanggan Baru
                        </a>
                    </div>
                    <div class="rnt-panel-body">
                        <div class="rnt-row">
                            <label class="rnt-label">Pelanggan <span class="text-danger">*</span></label>
                            <div class="rnt-field">
                                <select class="form-select form-select-sm ts-select" id="select_passenger_id" name="passenger_id" required>
                                    <option value="">-- Pilih Pelanggan --</option>
                                    <?= select_options($passengers, $in['passenger_id']) ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PANEL PENJEMPUTAN & PENGANTARAN (2 KOLOM BERDAMPINGAN) -->
                <div class="row g-3">
                    <!-- Kolom Kiri: Penjemputan -->
                    <div class="col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header">
                                <span><i class="bi bi-geo-alt-fill text-success me-1"></i>Penjemputan</span>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Provinsi</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_pickup_province" name="pickup_point_province_id">
                                            <option value="">-- Pilih Provinsi --</option>
                                            <?= select_options($provinces, $in['pickup_point_province_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Kota/Kab <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_pickup_city" name="pickup_point_city_id">
                                            <option value="">-- Pilih Kota/Kab --</option>
                                            <?php foreach ($cities as $c):
                                                $sel = ((string) $c['id'] === (string) $in['pickup_point_city_id']) ? ' selected' : '';
                                            ?>
                                                <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Kecamatan <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_pickup_district" name="pickup_point_district_id" required>
                                            <option value="">-- Pilih Kecamatan --</option>
                                            <?= select_options($pickup_districts, $in['pickup_point_district_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start mt-2">
                                    <label class="rnt-label pt-1" style="width:120px;">Detail Alamat</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm" name="pickup_address" rows="5" maxlength="2000" placeholder="Titik penjemputan lengkap..."><?= e($in['pickup_address']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Kolom Kanan: Pengantaran -->
                    <div class="col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header">
                                <span><i class="bi bi-pin-map-fill text-danger me-1"></i>Pengantaran</span>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Provinsi</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_delivery_province" name="delivery_point_province_id">
                                            <option value="">-- Pilih Provinsi --</option>
                                            <?= select_options($provinces, $in['delivery_point_province_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Kota/Kab <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_delivery_city" name="delivery_point_city_id">
                                            <option value="">-- Pilih Kota/Kab --</option>
                                            <?php foreach ($cities as $c):
                                                $sel = ((string) $c['id'] === (string) $in['delivery_point_city_id']) ? ' selected' : '';
                                            ?>
                                                <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" style="width:120px;">Kecamatan <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_delivery_district" name="delivery_point_district_id" required>
                                            <option value="">-- Pilih Kecamatan --</option>
                                            <?= select_options($delivery_districts, $in['delivery_point_district_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start mt-2">
                                    <label class="rnt-label pt-1" style="width:120px;">Detail Alamat</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm" name="delivery_address" rows="5" maxlength="2000" placeholder="Tujuan pengantaran lengkap..."><?= e($in['delivery_address']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- TAB 2: SOPIR / KERNET / MONTIR                             -->
            <!-- ========================================================== -->
            <div class="tab-pane fade" id="tab-crew" role="tabpanel">
                <!-- Field Atas: Total Biaya Operasional & Bahan Bakar Minyak (BBM) -->
                <div class="rnt-panel">
                    <div class="rnt-panel-header">
                        <span>Perhitungan Operasional &amp; Peraturan Komisi Karyawan</span>
                        <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank">
                            <i class="bi bi-sliders me-1"></i>Kelola Peraturan Komisi
                        </a>
                    </div>
                    <div class="rnt-panel-body">
                        <div class="rnt-row">
                            <label class="rnt-label">Peraturan Komisi</label>
                            <div class="rnt-field">
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <select class="form-select form-select-sm" id="crewRegulationSelect" style="max-width:480px;">
                                        <option value="0">-- Otomatis Sesuai Kategori Kendaraan &amp; Trip --</option>
                                        <?php foreach ($company_regulations as $crg):
                                            $crgSummary = trim((string) ($crg['note'] ?? '')) ?: regulation_build_summary($crg['details'] ?? []);
                                        ?>
                                            <option value="<?= (int) $crg['id'] ?>">
                                                <?= e('[' . $crg['category_code'] . ' · Trip ' . (int) $crg['trip'] . '] ' . $crgSummary) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-primary" id="btnApplyRegulation">
                                        <i class="bi bi-lightning-charge-fill me-1"></i>Hitung &amp; Isi Otomatis Premi
                                    </button>
                                    <span class="small text-muted" id="crewRegStatusMsg"></span>
                                </div>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Total Biaya Operasional</label>
                            <div class="rnt-field">
                                <input type="text" class="form-control form-control-sm fw-bold bg-light" id="crew_total_op_display" value="0" readonly>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Bahan Bakar Minyak (BBM)</label>
                            <div class="rnt-field">
                                <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="crew_bbm_display" value="<?= format_nominal((float) $in['bbm']) ?>" placeholder="0">
                                <input type="hidden" name="bbm" id="crew_bbm_value" value="<?= e($in['bbm']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sub-panel Karyawan (Grid Premi / Biaya Kru) -->
                <div class="rnt-panel mb-0">
                    <div class="rnt-panel-header">
                        <span>Karyawan</span>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddCrewRow">
                            <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 align-middle" id="crewTable" style="font-size:.84rem;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px;" class="text-center">#</th>
                                    <th style="width:160px;">Tipe</th>
                                    <th style="min-width:240px;">Karyawan</th>
                                    <th style="width:190px;">Organisasi</th>
                                    <th style="width:190px;">Posisi</th>
                                    <th style="width:170px;" class="text-end">Nominal</th>
                                    <th style="width:42px;" class="text-center">Aksi</th>
                                </tr>
                                <tr class="bg-light text-muted small" style="cursor:pointer;" id="crewQuickAddBanner" title="Klik untuk menambah baris karyawan baru">
                                    <td class="text-center text-warning"><i class="bi bi-sun-fill"></i></td>
                                    <td colspan="6" class="text-center fst-italic py-1">Click here to add a new row</td>
                                </tr>
                            </thead>
                            <tbody id="crewTableBody">
                                <?php foreach ($in_crew as $idx => $cr): ?>
                                <tr class="crew-row">
                                    <td class="text-center text-muted crew-row-num"><?= $idx + 1 ?></td>
                                    <td>
                                        <select class="form-select form-select-sm crew-type-select" name="crew_type[]">
                                            <?php foreach (['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'] as $tp): ?>
                                                <option value="<?= $tp ?>" <?= ($cr['type'] ?? '') === $tp ? 'selected' : '' ?>><?= $tp ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm crew-emp-select" name="crew_employee_id[]">
                                            <option value="">-- Pilih Karyawan --</option>
                                            <?php foreach ($employees as $emp): ?>
                                                <option value="<?= (int) $emp['id'] ?>"
                                                        data-org="<?= e($emp['job_title']) ?>"
                                                        data-pos="<?= e($emp['job_position']) ?>"
                                                        <?= (string) ($cr['employee_id'] ?? '') === (string) $emp['id'] ? 'selected' : '' ?>>
                                                    <?= e($emp['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" class="form-control form-control-sm bg-light crew-org-input" value="" readonly placeholder="-"></td>
                                    <td><input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="" readonly placeholder="-"></td>
                                    <td>
                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="<?= format_nominal((float) ($cr['amount'] ?? 0)) ?>">
                                        <input type="hidden" name="crew_amount[]" class="crew-amt-value" value="<?= (float) ($cr['amount'] ?? 0) ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="5" class="py-2">
                                        <div class="d-flex align-items-center gap-2 small text-muted">
                                            <span id="crewRecordCount">Record 1 of <?= count($in_crew) ?></span>
                                            <div class="btn-group btn-group-sm ms-2">
                                                <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterAddCrew" title="Tambah Baris">+</button>
                                                <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterDelCrew" title="Hapus Baris Terakhir">&minus;</button>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2">
                                        <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="crew_subtotal_display" value="0" readonly>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- TAB 3: PEMBAYARAN SEWA                                     -->
            <!-- ========================================================== -->
            <div class="tab-pane fade" id="tab-payment" role="tabpanel">
                <!-- Field Atas: Total Pembayaran + Tombol QRIS Otomatis -->
                <div class="rnt-panel">
                    <div class="rnt-panel-body">
                        <div class="rnt-row">
                            <label class="rnt-label">Total Pembayaran</label>
                            <div class="rnt-field">
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" id="pay_total_top_display" value="0" readonly style="max-width:320px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSaveAndPayQris" title="Simpan booking lalu buka halaman QRIS Dinamis Otomatis">
                                        <i class="bi bi-qr-code-scan me-1"></i>Simpan &amp; Bayar Otomatis via QRIS
                                    </button>
                                    <span class="small text-muted">Input manual di tabel bawah (DP/Transfer/Tunai) atau klik tombol QRIS untuk pembayaran otomatis.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grid Pembayaran Sewa (Lampiran | Tipe Pembayaran | Metode Pembayaran | Tanggal | Nominal) -->
                <div class="rnt-panel mb-0">
                    <div class="rnt-panel-header">
                        <span>Rincian Pembayaran Sewa</span>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddPayRow">
                            <i class="bi bi-plus-lg me-1"></i>Tambah Pembayaran Manual
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 align-middle" id="payTable" style="font-size:.84rem;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px;" class="text-center">#</th>
                                    <th style="width:210px;">Lampiran</th>
                                    <th style="width:220px;">Tipe Pembayaran</th>
                                    <th style="width:200px;">Metode Pembayaran</th>
                                    <th style="width:170px;">Tanggal</th>
                                    <th style="width:180px;" class="text-end">Nominal</th>
                                    <th style="width:42px;" class="text-center">Aksi</th>
                                </tr>
                                <tr class="bg-light text-muted small" style="cursor:pointer;" id="payQuickAddBanner" title="Klik untuk menambah baris pembayaran">
                                    <td class="text-center text-warning"><i class="bi bi-sun-fill"></i></td>
                                    <td colspan="6" class="text-center fst-italic py-1">Click here to add a new row</td>
                                </tr>
                            </thead>
                            <tbody id="payTableBody">
                                <?php foreach ($in_payments as $idx => $pr): ?>
                                <tr class="pay-row">
                                    <td class="text-center text-muted pay-row-num"><?= $idx + 1 ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="text-muted small pay-file-label">No image data</span>
                                            <label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Pilih bukti pembayaran">
                                                <i class="bi bi-paperclip"></i>
                                                <input type="file" name="pay_proof[]" class="d-none pay-file-input" accept="image/*,application/pdf">
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm pay-type-select" name="pay_type[]">
                                            <?php foreach (['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)', 'Cicilan / Termin'] as $pt): ?>
                                                <option value="<?= e($pt) ?>" <?= ($pr['pay_type'] ?? '') === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm pay-method-select" name="pay_method[]">
                                            <?php foreach (['Transfer', 'Tunai', 'QRIS', 'EDC / Kartu'] as $pm): ?>
                                                <option value="<?= e($pm) ?>" <?= ($pr['pay_method'] ?? '') === $pm ? 'selected' : '' ?>><?= e($pm) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="pay_date[]" value="<?= e($pr['pay_date'] ?? date('Y-m-d')) ?>" placeholder="dd-MMM-yyyy">
                                    </td>
                                    <td>
                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="<?= format_nominal((float) ($pr['amount'] ?? 0)) ?>">
                                        <input type="hidden" name="pay_amount[]" class="pay-amt-value" value="<?= (float) ($pr['amount'] ?? 0) ?>">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="5" class="py-2">
                                        <div class="d-flex align-items-center gap-2 small text-muted">
                                            <span id="payRecordCount">Record 1 of <?= count($in_payments) ?></span>
                                            <div class="btn-group btn-group-sm ms-2">
                                                <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterAddPay" title="Tambah Baris">+</button>
                                                <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterDelPay" title="Hapus Baris Terakhir">&minus;</button>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2">
                                        <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="pay_subtotal_display" value="0" readonly>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- TAB 4: KETERANGAN                                          -->
            <!-- ========================================================== -->
            <div class="tab-pane fade" id="tab-note" role="tabpanel">
                <div class="rnt-panel mb-0">
                    <div class="rnt-panel-header">
                        <span><i class="bi bi-journal-text me-1 text-primary"></i>Keterangan &amp; Catatan Tambahan</span>
                    </div>
                    <div class="rnt-panel-body">
                        <textarea class="form-control form-control-sm" name="note" rows="6" maxlength="2000" placeholder="Tuliskan keterangan sewa, fasilitas tambahan, atau instruksi khusus..."><?= e($in['note']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- TAB 5: AUDIT TRAIL                                         -->
            <!-- ========================================================== -->
            <div class="tab-pane fade" id="tab-audit" role="tabpanel">
                <div class="rnt-panel mb-0">
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
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= date('d-M-Y H:i') ?>" readonly>
                                    <span class="input-group-text bg-white"><i class="bi bi-chevron-down text-muted"></i></span>
                                </div>
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
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm bg-light" value="-" readonly>
                                    <span class="input-group-text bg-white"><i class="bi bi-chevron-down text-muted"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tombol Simpan Bawah -->
            <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">
                    <span id="rental_duration_badge" class="badge bg-primary-subtle text-primary border me-1">1 Hari Sewa</span>
                    Pastikan data penjemputan, pengantaran, dan harga sewa sudah sesuai.
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary btn-sm px-3" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">Batal</a>
                    <button class="btn btn-primary btn-sm px-4 fw-semibold" type="submit">
                        <i class="bi bi-check2-circle me-1"></i>Simpan Booking Rental
                    </button>
                </div>
            </div>
        </div>
    </form>
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
                        <input type="text" class="form-control" id="qp_phone" placeholder="08xxxxxxxxxx">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Helper format & parse rupiah standar Indonesia
    function parseRupiah(val) {
        if (val === null || val === undefined || val === '') return 0;
        if (typeof val === 'number') return Math.round(val);
        var str = String(val).trim();
        if (/^\d+\.(0|00)$/.test(str)) {
            str = str.replace(/\.(0|00)$/, '');
        }
        var digits = str.replace(/[^0-9]/g, '');
        return digits ? parseInt(digits, 10) : 0;
    }

    function formatRupiah(val) {
        if (val === null || val === undefined || val === '') return '';
        var num = parseRupiah(val);
        if (num === 0) {
            return String(val).replace(/[^0-9]/g, '') === '' ? '' : '0';
        }
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function attachRupiahMask(inputEl, hiddenEl, onChangeCallback) {
        if (!inputEl) return;
        inputEl.addEventListener('input', function () {
            var oldVal = this.value;
            var caretPos = this.selectionStart || 0;
            var digitsBeforeCaret = oldVal.slice(0, caretPos).replace(/[^0-9]/g, '').length;

            var clean = oldVal.replace(/[^0-9]/g, '');
            var num = parseInt(clean || '0', 10);
            if (hiddenEl) hiddenEl.value = String(num);

            var formatted = clean === '' ? '' : formatRupiah(num);
            this.value = formatted;

            if (this.setSelectionRange && formatted) {
                var newCaret = 0;
                var digitCount = 0;
                for (var i = 0; i < formatted.length; i++) {
                    if (/[0-9]/.test(formatted[i])) {
                        digitCount++;
                    }
                    if (digitCount === digitsBeforeCaret) {
                        newCaret = i + 1;
                        break;
                    }
                }
                if (digitCount < digitsBeforeCaret) newCaret = formatted.length;
                try { this.setSelectionRange(newCaret, newCaret); } catch (e) {}
            }

            if (typeof onChangeCallback === 'function') onChangeCallback(num);
        });

        inputEl.addEventListener('blur', function () {
            if (this.value.trim() === '') {
                this.value = '0';
                if (hiddenEl) hiddenEl.value = '0';
                if (typeof onChangeCallback === 'function') onChangeCallback(0);
            }
        });
    }

    // 1. Format input Harga (2.000.000 <-> 2000000)
    var priceDisplay = document.getElementById('rental_price_display');
    var priceHidden  = document.getElementById('rental_total_price');
    function syncPriceDisplayFromHidden() {
        var raw = parseRupiah(priceHidden ? priceHidden.value : '0');
        if (priceDisplay) priceDisplay.value = formatRupiah(raw);
    }
    if (priceDisplay && priceHidden) {
        attachRupiahMask(priceDisplay, priceHidden);
    }

    // 2. Sinkronisasi 2 Arah: Tanggal Mulai + Selesai <-> Durasi Hari (Spinner)
    var sInput   = document.getElementById('rental_start_date');
    var eInput   = document.getElementById('rental_end_date');
    var bDate    = document.getElementById('rental_booking_date');
    var durInput = document.getElementById('rental_duration_days');
    var durBadge = document.getElementById('rental_duration_badge');
    var dailyRateInput = document.getElementById('rental_daily_rate');
    var rentalCalcHint = document.getElementById('rental_calc_hint');
    var btnApplyRental = document.getElementById('btn_apply_rental_calc');

    function formatDateIso(d) {
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd;
    }

    function parseDateObj(el) {
        if (!el) return null;
        if (el._flatpickr && el._flatpickr.selectedDates && el._flatpickr.selectedDates.length > 0) {
            var d = el._flatpickr.selectedDates[0];
            return new Date(d.getFullYear(), d.getMonth(), d.getDate());
        }
        if (el.value) {
            var m = String(el.value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (m) {
                return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
            }
        }
        return null;
    }

    function updateDaysFromDates() {
        if (!sInput || !eInput || !durInput) return;
        var s = parseDateObj(sInput);
        var e = parseDateObj(eInput);
        if (!s || !e) return;

        if (bDate) bDate.value = formatDateIso(s);

        var diffDays = Math.round((e - s) / 86400000) + 1;
        if (diffDays < 1) {
            diffDays = 1;
            if (eInput._flatpickr) {
                eInput._flatpickr.setDate(s, false);
            } else {
                eInput.value = formatDateIso(s);
            }
        }
        durInput.value = String(diffDays);
        if (durBadge) durBadge.textContent = diffDays + ' Hari Sewa';
        updateRateHint(diffDays, false);
        checkVehicleAvailability();
    }

    function updateEndDateFromDays() {
        if (!sInput || !eInput || !durInput) return;
        var s = parseDateObj(sInput);
        if (!s) return;

        var days = parseInt(durInput.value || '1', 10);
        if (isNaN(days) || days < 1) days = 1;
        durInput.value = String(days);

        var target = new Date(s.getFullYear(), s.getMonth(), s.getDate() + (days - 1));
        if (eInput._flatpickr) {
            eInput._flatpickr.setDate(target, false);
        } else {
            eInput.value = formatDateIso(target);
        }
        if (durBadge) durBadge.textContent = days + ' Hari Sewa';
        updateRateHint(days, false);
        checkVehicleAvailability();
    }

    function initFlatpickr(el, onDateChange) {
        if (!el || el._flatpickr || typeof flatpickr === 'undefined') return;
        return flatpickr(el, {
            altInput: true,
            altFormat: "d-M-Y",
            dateFormat: "Y-m-d",
            altInputClass: "form-control form-control-sm bg-white",
            allowInput: false,
            onChange: function(selectedDates, dateStr) {
                if (typeof onDateChange === 'function') {
                    onDateChange(selectedDates, dateStr);
                }
            }
        });
    }

    if (sInput) {
        initFlatpickr(sInput, function() {
            updateDaysFromDates();
        });
    }
    if (eInput) {
        initFlatpickr(eInput, function() {
            updateDaysFromDates();
        });
    }

    document.querySelectorAll('.flatpickr-date').forEach(function(el) {
        if (el !== sInput && el !== eInput) {
            initFlatpickr(el);
        }
    });

    if (durInput) {
        durInput.addEventListener('input', updateEndDateFromDays);
        durInput.addEventListener('change', updateEndDateFromDays);
    }
    updateDaysFromDates();

    function updateRateHint(days, forceApply) {
        var dailyRate = dailyRateInput ? parseFloat(dailyRateInput.value) || 0 : 0;
        if (dailyRate > 0) {
            var estTotal = days * dailyRate;
            if (rentalCalcHint) {
                rentalCalcHint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Estimasi: ' + days + ' hari &times; Rp ' + dailyRate.toLocaleString('id-ID') + ' = Rp ' + estTotal.toLocaleString('id-ID') + '</span>';
                rentalCalcHint.style.display = 'block';
            }
            if (forceApply) {
                priceHidden.value = String(estTotal);
                syncPriceDisplayFromHidden();
            }
        } else if (rentalCalcHint) {
            rentalCalcHint.style.display = 'none';
        }
    }

    if (sInput) sInput.addEventListener('change', function () { updateDaysFromDates(); checkVehicleAvailability(); });
    if (eInput) eInput.addEventListener('change', function () { updateDaysFromDates(); checkVehicleAvailability(); });
    if (durInput) durInput.addEventListener('input', updateEndDateFromDays);
    if (dailyRateInput) dailyRateInput.addEventListener('input', function () {
        var d = Math.max(1, parseInt(durInput ? durInput.value : '1', 10));
        updateRateHint(d, true);
    });
    if (btnApplyRental) btnApplyRental.addEventListener('click', function () {
        var d = Math.max(1, parseInt(durInput ? durInput.value : '1', 10));
        updateRateHint(d, true);
    });
    updateDaysFromDates();

    // 2b. Label AM/PM Bahasa Indonesia Dinamis untuk Jam
    function getTimePeriodLabel(timeVal) {
        if (!timeVal) return '';
        var parts = String(timeVal).split(':');
        var h = parseInt(parts[0], 10);
        if (isNaN(h)) return '';
        if (h >= 0 && h < 5) return 'Dini Hari (AM)';
        if (h >= 5 && h < 11) return 'Pagi (AM)';
        if (h >= 11 && h < 15) return 'Siang (PM)';
        if (h >= 15 && h < 18) return 'Sore (PM)';
        return 'Malam (PM)';
    }

    var timeInp = document.getElementById('rental_time_input');
    var timeBadge = document.getElementById('rental_time_period_badge');
    if (timeInp && timeBadge) {
        function updateTimeBadge() {
            timeBadge.textContent = getTimePeriodLabel(timeInp.value);
        }
        timeInp.addEventListener('input', updateTimeBadge);
        timeInp.addEventListener('change', updateTimeBadge);
        updateTimeBadge();
    }

    // 3. Filter Kendaraan Berdasarkan Kategori
    var catSelect = document.getElementById('rental_category_select');
    var vehSelect = document.getElementById('rental_vehicle_select');
    var allVehicles = <?= json_encode(array_map(static fn($v) => [
        'id'       => (string) $v['id'],
        'text'     => $v['name'],
        'category' => (string) ($v['category_vehicle_id'] ?? ''),
    ], $vehicles), JSON_UNESCAPED_UNICODE) ?>;

    if (catSelect && vehSelect) {
        catSelect.addEventListener('change', function () {
            var cidVal = String(this.value || '');
            var filtered = allVehicles.filter(function (v) {
                return !cidVal || v.category === cidVal;
            });
            updateSelectOptions(vehSelect, filtered, '', '-- Pilih Unit Kendaraan --');
        });
    }

    // 4. Dropdown Bertingkat: Provinsi -> Kota/Kab -> Kecamatan
    function updateSelectOptions(el, items, selectedVal, placeholder) {
        if (!el) return;
        var ph = placeholder || '-- Pilih --';
        var html = '<option value="">' + ph + '</option>';
        (items || []).forEach(function(it) {
            var id = String(it.id != null ? it.id : (it.value != null ? it.value : ''));
            var txt = String(it.name != null ? it.name : (it.text != null ? it.text : ''));
            var isSel = (id && String(selectedVal || '') === id);
            html += '<option value="' + id + '"' + (isSel ? ' selected' : '') + '>' + txt + '</option>';
        });
        el.innerHTML = html;

        if (el.tomselect) {
            var ts = el.tomselect;
            ts.clear(true);
            ts.clearOptions();
            ts.addOption({ value: '', text: ph });
            (items || []).forEach(function (it) {
                var id = String(it.id != null ? it.id : (it.value != null ? it.value : ''));
                var txt = String(it.name != null ? it.name : (it.text != null ? it.text : ''));
                ts.addOption({ value: id, text: txt });
            });
            ts.refreshOptions(false);
            if (selectedVal) {
                ts.setValue(String(selectedVal), true);
            }
        }
    }

    function onSelectChange(el, callback) {
        if (!el) return;
        el.addEventListener('change', function () {
            callback(this.value);
        });
        if (el.tomselect) {
            el.tomselect.on('change', function (val) {
                callback(val);
            });
        } else {
            var timer = setInterval(function () {
                if (el.tomselect) {
                    clearInterval(timer);
                    el.tomselect.on('change', function (val) {
                        callback(val);
                    });
                }
            }, 100);
            setTimeout(function () { clearInterval(timer); }, 3000);
        }
    }

    function reloadCityOptions(provVal, targetCityId, targetDistId) {
        var cityEl = document.getElementById(targetCityId);
        var distEl = document.getElementById(targetDistId);
        if (!cityEl) return;
        if (distEl) updateSelectOptions(distEl, [], '', '-- Pilih Kecamatan --');
        if (!provVal) {
            updateSelectOptions(cityEl, [], '', '-- Pilih Kota/Kab --');
            return;
        }
        fetch('<?= e(APP_URL) ?>/pages/masters/cities/options.php?province_id=' + encodeURIComponent(provVal))
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
        fetch('<?= e(APP_URL) ?>/pages/masters/districts/options.php?city_id=' + encodeURIComponent(cityVal))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                updateSelectOptions(el, data, currentVal, '-- Pilih Kecamatan --');
            });
    }

    var pProv = document.getElementById('rental_pickup_province');
    var pCity = document.getElementById('rental_pickup_city');
    if (pProv) onSelectChange(pProv, function (val) { reloadCityOptions(val, 'rental_pickup_city', 'rental_pickup_district'); });
    if (pCity) onSelectChange(pCity, function (val) { reloadDistrictOptions(val, 'rental_pickup_district', ''); });

    var dProv = document.getElementById('rental_delivery_province');
    var dCity = document.getElementById('rental_delivery_city');
    if (dProv) onSelectChange(dProv, function (val) { reloadCityOptions(val, 'rental_delivery_city', 'rental_delivery_district'); });
    if (dCity) onSelectChange(dCity, function (val) { reloadDistrictOptions(val, 'rental_delivery_district', ''); });

    // 5. Quick-Add Pelanggan Baru
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
                qpBtn.textContent = 'Simpan & Pilih';
                if (!res.ok) {
                    qpAlert.textContent = res.error || 'Gagal menyimpan pelanggan.';
                    qpAlert.classList.remove('d-none');
                    return;
                }

                var p = res.data;
                var labelText = p.name + (phoneVal ? ' - ' + phoneVal : '');
                var pSelect = document.getElementById('select_passenger_id');
                if (pSelect) {
                    if (pSelect.tomselect) {
                        pSelect.tomselect.addOption({ value: p.id, text: labelText });
                        pSelect.tomselect.setValue(p.id);
                    } else {
                        var opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = labelText;
                        opt.selected = true;
                        pSelect.appendChild(opt);
                    }
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

    // 6. Pengecekan Ketersediaan Armada Real-Time (Anti Double-Booking)
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

    if (vehSelect) {
        vehSelect.addEventListener('change', checkVehicleAvailability);
        if (vehSelect.tomselect) {
            vehSelect.tomselect.on('change', checkVehicleAvailability);
        }
    }

    // 7. Grid Sopir / Kernet / Montir / Agen & Kalkulasi Otomatis Total Biaya Operasional
    var bbmDisp     = document.getElementById('crew_bbm_display');
    var bbmVal      = document.getElementById('crew_bbm_value');
    var totalOpDisp = document.getElementById('crew_total_op_display');
    var subCrewDisp = document.getElementById('crew_subtotal_display');
    var crewTbody   = document.getElementById('crewTableBody');
    var recCountEl  = document.getElementById('crewRecordCount');
    var empOptionsHtml = `<option value="">-- Pilih Karyawan --</option>` + <?= json_encode(implode('', array_map(static fn($emp) =>
        '<option value="' . (int) $emp['id'] . '" data-org="' . e($emp['job_title']) . '" data-pos="' . e($emp['job_position']) . '">' . e($emp['name']) . '</option>',
        $employees
    ))) ?>;

    function recalcCrewTotals() {
        var bbmNum = parseRupiah(bbmVal ? bbmVal.value : '0');
        var crewSum = 0;
        var rows = crewTbody ? crewTbody.querySelectorAll('tr.crew-row') : [];
        rows.forEach(function (tr, idx) {
            var numCell = tr.querySelector('.crew-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            var hid = tr.querySelector('.crew-amt-value');
            crewSum += parseRupiah(hid ? hid.value : '0');
        });
        if (subCrewDisp) subCrewDisp.value = formatRupiah(crewSum);
        if (totalOpDisp) totalOpDisp.value = formatRupiah(bbmNum + crewSum);
        if (recCountEl)  recCountEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
    }

    function bindCrewRow(tr) {
        var empSel  = tr.querySelector('.crew-emp-select');
        var orgInp  = tr.querySelector('.crew-org-input');
        var posInp  = tr.querySelector('.crew-pos-input');
        var amtDisp = tr.querySelector('.crew-amt-display');
        var amtVal  = tr.querySelector('.crew-amt-value');
        var delBtn  = tr.querySelector('.btn-remove-crew');

        function syncEmpMeta() {
            if (!empSel) return;
            var opt = empSel.options[empSel.selectedIndex];
            if (orgInp) orgInp.value = opt ? (opt.getAttribute('data-org') || '') : '';
            if (posInp) posInp.value = opt ? (opt.getAttribute('data-pos') || '') : '';
        }
        if (empSel) {
            empSel.addEventListener('change', syncEmpMeta);
            syncEmpMeta();
        }
        if (amtDisp && amtVal) {
            attachRupiahMask(amtDisp, amtVal, function () {
                recalcCrewTotals();
            });
        }
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                tr.remove();
                recalcCrewTotals();
            });
        }
    }

    function addCrewRow(defaultType, defaultAmount, defaultEmpId) {
        if (!crewTbody) return;
        var tr = document.createElement('tr');
        tr.className = 'crew-row';
        var tp = defaultType || 'Sopir';
        var amtNum = parseRupiah(defaultAmount || 0);
        var typeOptions = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'];
        if (!typeOptions.includes(tp)) typeOptions.unshift(tp);
        var typeOptsHtml = typeOptions.map(function(t) {
            return '<option value="' + t + '"' + (tp === t ? ' selected' : '') + '>' + t + '</option>';
        }).join('');

        tr.innerHTML = `
            <td class="text-center text-muted crew-row-num">1</td>
            <td>
                <select class="form-select form-select-sm crew-type-select" name="crew_type[]">
                    ${typeOptsHtml}
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm crew-emp-select" name="crew_employee_id[]">${empOptionsHtml}</select>
            </td>
            <td><input type="text" class="form-control form-control-sm bg-light crew-org-input" value="" readonly placeholder="-"></td>
            <td><input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="" readonly placeholder="-"></td>
            <td>
                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="${formatRupiah(amtNum) || '0'}">
                <input type="hidden" name="crew_amount[]" class="crew-amt-value" value="${amtNum}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        crewTbody.appendChild(tr);
        if (defaultEmpId) {
            var empSel = tr.querySelector('.crew-emp-select');
            if (empSel) empSel.value = String(defaultEmpId);
        }
        bindCrewRow(tr);
        recalcCrewTotals();
    }

    // Tombol & Auto-Match Peraturan Komisi Karyawan
    var btnApplyReg = document.getElementById('btnApplyRegulation');
    var regSelect   = document.getElementById('crewRegulationSelect');
    var regMsgEl    = document.getElementById('crewRegStatusMsg');

    async function applyRegulationAuto(manualRegId) {
        var catEl = document.getElementById('rental_category_select');
        var trpEl = document.getElementById('rental_trip');
        var stEl  = document.getElementById('rental_start_date');
        var prEl  = document.getElementById('total_price_hidden');

        var regId = typeof manualRegId !== 'undefined' ? parseInt(manualRegId || '0', 10) : (regSelect ? parseInt(regSelect.value || '0', 10) : 0);
        var catId = catEl ? parseInt(catEl.value || '0', 10) : 0;
        var trip  = trpEl ? parseFloat(trpEl.value || '1') : 1;
        var sDate = stEl ? stEl.value : '';
        var price = prEl ? parseRupiah(prEl.value) : 0;

        if (btnApplyReg) btnApplyReg.disabled = true;
        if (regMsgEl) {
            regMsgEl.className = 'small text-muted';
            regMsgEl.textContent = 'Menghitung komisi...';
        }

        try {
            var qs = new URLSearchParams({
                company_id: '<?= (int) $cid ?>',
                regulation_id: String(regId),
                category_vehicle_id: String(catId),
                trip: String(trip),
                start_date: sDate,
                total_price: String(price)
            });
            var res = await fetch('<?= e(APP_URL) ?>/pages/rental/regulations/match_api.php?' + qs.toString(), {
                headers: { 'Accept': 'application/json' }
            });
            var data = await res.json();
            if (!data.ok) {
                if (regMsgEl) {
                    regMsgEl.className = 'small text-danger fw-semibold';
                    regMsgEl.textContent = data.error || 'Gagal memuat peraturan komisi.';
                }
                return;
            }

            // Simpan karyawan yang sudah dipilih per tipe agar tidak ter-reset
            var existingEmpsByType = {};
            crewTbody.querySelectorAll('tr.crew-row').forEach(function (r) {
                var t = r.querySelector('.crew-type-select')?.value || 'Sopir';
                var e = r.querySelector('.crew-emp-select')?.value || '';
                if (e) {
                    if (!existingEmpsByType[t]) existingEmpsByType[t] = [];
                    existingEmpsByType[t].push(e);
                }
            });

            var calc = data.calculated || {};
            var bbmNum = parseRupiah(calc.bbm || 0);
            if (bbmVal) bbmVal.value = String(bbmNum);
            if (bbmDisp) bbmDisp.value = formatRupiah(bbmNum) || '0';

            crewTbody.innerHTML = '';
            var crewRows = Array.isArray(calc.crew_rows) ? calc.crew_rows : [];
            crewRows.forEach(function (cr) {
                var tp = cr.type || 'Sopir';
                var empQueue = existingEmpsByType[tp] || [];
                var empId = empQueue.length > 0 ? empQueue.shift() : '';
                addCrewRow(tp, cr.amount || 0, empId);
            });
            recalcCrewTotals();

            if (regSelect && data.regulation && data.regulation.id) {
                regSelect.value = String(data.regulation.id);
            }
            if (regMsgEl) {
                regMsgEl.className = 'small text-success fw-semibold';
                regMsgEl.textContent = '✓ Diterapkan: ' + (data.regulation?.note || '');
            }
        } catch (err) {
            if (regMsgEl) {
                regMsgEl.className = 'small text-danger';
                regMsgEl.textContent = 'Gagal menghubungi server.';
            }
        } finally {
            if (btnApplyReg) btnApplyReg.disabled = false;
        }
    }

    if (btnApplyReg) {
        btnApplyReg.addEventListener('click', function () {
            applyRegulationAuto();
        });
    }

    // Auto-Match Komisi saat Kategori Kendaraan atau Harga Sewa selesai diisi di Tab 1
    var catSelectEl = document.getElementById('rental_category_select');
    if (catSelectEl) {
        catSelectEl.addEventListener('change', function () {
            if (catSelectEl.value && parseRupiah(priceHidden ? priceHidden.value : 0) > 0) {
                applyRegulationAuto(0);
            }
        });
    }
        crewTbody.appendChild(tr);
        bindCrewRow(tr);
        recalcCrewTotals();
    }

    if (bbmDisp && bbmVal) {
        attachRupiahMask(bbmDisp, bbmVal, function () {
            recalcCrewTotals();
        });
    }

    if (crewTbody) {
        crewTbody.querySelectorAll('tr.crew-row').forEach(bindCrewRow);
    }
    ['btnAddCrewRow', 'crewQuickAddBanner', 'btnFooterAddCrew'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function () { addCrewRow('Sopir'); });
    });
    var btnFooterDel = document.getElementById('btnFooterDelCrew');
    if (btnFooterDel && crewTbody) {
        btnFooterDel.addEventListener('click', function () {
            var rows = crewTbody.querySelectorAll('tr.crew-row');
            if (rows.length > 0) {
                rows[rows.length - 1].remove();
                recalcCrewTotals();
            }
        });
    }
    recalcCrewTotals();

    // 8. Grid Pembayaran Sewa (Lampiran | Tipe Pembayaran | Metode Pembayaran | Tanggal | Nominal)
    var payTopDisp = document.getElementById('pay_total_top_display');
    var paySubDisp = document.getElementById('pay_subtotal_display');
    var payTbody   = document.getElementById('payTableBody');
    var payCountEl = document.getElementById('payRecordCount');
    var todayIso   = <?= json_encode(date('Y-m-d')) ?>;

    function recalcPayTotals() {
        var paySum = 0;
        var rows = payTbody ? payTbody.querySelectorAll('tr.pay-row') : [];
        rows.forEach(function (tr, idx) {
            var numCell = tr.querySelector('.pay-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            var hid = tr.querySelector('.pay-amt-value');
            paySum += parseRupiah(hid ? hid.value : '0');
        });
        if (payTopDisp) payTopDisp.value = formatRupiah(paySum);
        if (paySubDisp) paySubDisp.value = formatRupiah(paySum);
        if (payCountEl) payCountEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
    }

    function bindPayRow(tr) {
        var fileInp  = tr.querySelector('.pay-file-input');
        var fileLbl  = tr.querySelector('.pay-file-label');
        var amtDisp  = tr.querySelector('.pay-amt-display');
        var amtVal   = tr.querySelector('.pay-amt-value');
        var delBtn   = tr.querySelector('.btn-remove-pay');

        if (fileInp && fileLbl) {
            fileInp.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    fileLbl.textContent = this.files[0].name;
                    fileLbl.classList.remove('text-muted');
                    fileLbl.classList.add('text-primary', 'fw-semibold');
                } else {
                    fileLbl.textContent = 'No image data';
                    fileLbl.classList.add('text-muted');
                    fileLbl.classList.remove('text-primary', 'fw-semibold');
                }
            });
        }
        if (amtDisp && amtVal) {
            attachRupiahMask(amtDisp, amtVal, function () {
                recalcPayTotals();
            });
        }
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                tr.remove();
                recalcPayTotals();
            });
        }
    }

    function addPayRow() {
        if (!payTbody) return;
        var tr = document.createElement('tr');
        tr.className = 'pay-row';
        tr.innerHTML = `
            <td class="text-center text-muted pay-row-num">1</td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small pay-file-label">No image data</span>
                    <label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Pilih bukti pembayaran">
                        <i class="bi bi-paperclip"></i>
                        <input type="file" name="pay_proof[]" class="d-none pay-file-input" accept="image/*,application/pdf">
                    </label>
                </div>
            </td>
            <td>
                <select class="form-select form-select-sm pay-type-select" name="pay_type[]">
                    <option value="Down Payment (DP)">Down Payment (DP)</option>
                    <option value="Pelunasan">Pelunasan</option>
                    <option value="Pembayaran Penuh (Full)">Pembayaran Penuh (Full)</option>
                    <option value="Cicilan / Termin">Cicilan / Termin</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm pay-method-select" name="pay_method[]">
                    <option value="Transfer">Transfer</option>
                    <option value="Tunai">Tunai</option>
                    <option value="QRIS">QRIS</option>
                    <option value="EDC / Kartu">EDC / Kartu</option>
                </select>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bg-white flatpickr-dynamic" name="pay_date[]" value="${todayIso}" placeholder="dd-MMM-yyyy">
            </td>
            <td>
                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="0">
                <input type="hidden" name="pay_amount[]" class="pay-amt-value" value="0">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        payTbody.appendChild(tr);
        var newDateInp = tr.querySelector('.flatpickr-dynamic');
        if (newDateInp && typeof initFlatpickr === 'function') initFlatpickr(newDateInp);
        bindPayRow(tr);
        recalcPayTotals();
    }

    if (payTbody) {
        payTbody.querySelectorAll('tr.pay-row').forEach(bindPayRow);
    }
    ['btnAddPayRow', 'payQuickAddBanner', 'btnFooterAddPay'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', addPayRow);
    });
    var btnFooterDelPay = document.getElementById('btnFooterDelPay');
    if (btnFooterDelPay && payTbody) {
        btnFooterDelPay.addEventListener('click', function () {
            var rows = payTbody.querySelectorAll('tr.pay-row');
            if (rows.length > 0) {
                rows[rows.length - 1].remove();
                recalcPayTotals();
            }
        });
    }
    var btnSaveQris = document.getElementById('btnSaveAndPayQris');
    if (btnSaveQris) {
        btnSaveQris.addEventListener('click', function () {
            var flag = document.getElementById('redirect_qris_flag');
            var form = document.getElementById('formRentalBooking');
            if (flag) flag.value = '1';
            if (form) form.requestSubmit();
        });
    }
    recalcPayTotals();
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
