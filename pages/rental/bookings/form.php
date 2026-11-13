<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.manage');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$is_edit = $id > 0;

if ($is_edit) {
    redirect('pages/rental/bookings/detail.php?id=' . $id);
}

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

if ($is_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $d1 = new DateTime((string) $b['start_date']);
    $d2 = new DateTime((string) $b['end_date']);
    $duration_days = max(1, (int) $d1->diff($d2)->days + 1);

    $in = [
        'date'                       => (string) ($b['date'] ?: $b['start_date']),
        'time'                       => substr((string) $b['time'], 0, 5),
        'start_date'                 => (string) $b['start_date'],
        'end_date'                   => (string) $b['end_date'],
        'duration_days'              => (string) $duration_days,
        'trip'                       => (string) (float) ($b['trip'] ?? 1),
        'bbm'                        => (string) (float) ($b['bbm'] ?? 0),
        'branch_id'                  => (string) $b['branch_id'],
        'passenger_id'               => (string) $b['passenger_id'],
        'category_vehicle_id'        => (string) ($b['category_vehicle_id'] ?? ''),
        'vehicle_id'                 => (string) ($b['vehicle_id'] ?? ''),
        'employee_id'                => '',
        'total_price'                => (string) (float) ($b['total_price'] ?? 0),
        'pickup_point_province_id'   => (string) ($b['pickup_point_province_id'] ?? ''),
        'pickup_point_city_id'       => (string) ($b['pickup_point_city_id'] ?? ''),
        'pickup_point_district_id'   => (string) ($b['pickup_point_district_id'] ?? ''),
        'pickup_address'             => (string) ($b['pickup_address'] ?? ''),
        'delivery_point_province_id' => (string) ($b['delivery_point_province_id'] ?? ''),
        'delivery_point_city_id'     => (string) ($b['delivery_point_city_id'] ?? ''),
        'delivery_point_district_id' => (string) ($b['delivery_point_district_id'] ?? ''),
        'delivery_address'           => (string) ($b['delivery_address'] ?? ''),
        'note'                       => (string) ($b['note'] ?? ''),
    ];

    $in['pickup_point_district_id']   = (string) resolve_district_id($in['pickup_point_district_id']);
    $in['delivery_point_district_id'] = (string) resolve_district_id($in['delivery_point_district_id']);

    $p_info = resolve_district_info($in['pickup_point_district_id']);
    if ($p_info) {
        $in['pickup_point_city_id'] = (string) $p_info['city_id'];
        $in['pickup_point_province_id'] = (string) $p_info['province_id'];
    }
    $d_info = resolve_district_info($in['delivery_point_district_id']);
    if ($d_info) {
        $in['delivery_point_city_id'] = (string) $d_info['city_id'];
        $in['delivery_point_province_id'] = (string) $d_info['province_id'];
    }

    $bp_list = booking_passengers_list('rental', $id, (int) $b['passenger_id']);
    foreach ($bp_list as $bp) {
        if ((int) $bp['passenger_id'] !== (int) $b['passenger_id']) {
            $in_passengers[] = (int) $bp['passenger_id'];
        }
    }

    $c_list = booking_rental_crew_list($id);
    if ($c_list) {
        $in_crew = [];
        foreach ($c_list as $cl) {
            $in_crew[] = [
                'type'        => $cl['cost_type'],
                'employee_id' => (string) ($cl['employee_id'] ?? ''),
                'amount'      => (string) (float) ($cl['amount'] ?? 0),
            ];
        }
    }

    $stInvId = db()->prepare("SELECT id FROM invoices WHERE booking_type = 'rental' AND booking_id = ? LIMIT 1");
    $stInvId->bind_param('i', $id);
    $stInvId->execute();
    $invRowId = $stInvId->get_result()->fetch_assoc();
    $stInvId->close();
    $invId = $invRowId ? (int) $invRowId['id'] : 0;
    if ($invId > 0) {
        $p_list = invoice_payments($invId);
        if ($p_list) {
            $in_payments = [];
            foreach ($p_list as $pl) {
                $in_payments[] = [
                    'id'              => (int) ($pl['id'] ?? 0),
                    'pay_type'        => (string) ($pl['pay_type'] ?? 'Down Payment (DP)'),
                    'pay_method'      => (string) ($pl['pay_method'] ?? 'Transfer'),
                    'pay_date'        => (string) (substr($pl['created_date'] ?? date('Y-m-d'), 0, 10)),
                    'amount'          => (string) (float) ($pl['amount'] ?? 0),
                    'proof_blob_path' => (string) ($pl['proof_blob_path'] ?? ''),
                ];
            }
        }
    }
}

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
    } else {
        $manualPaySum = array_sum(array_map(static fn($r) => (float) ($r['amount'] ?? 0), $in_payments));
        if ($manualPaySum > (float) $in['total_price'] + 0.001) {
            $err = 'Total pembayaran (' . rupiah($manualPaySum) . ') tidak boleh melebihi total harga sewa (' . rupiah((float) $in['total_price']) . ').';
        }
    }

    if ($err === '') {
        $cat_id_post = (int) $in['category_vehicle_id'] ?: null;
        $veh_id_post = (int) $in['vehicle_id'] ?: null;
        $crew_emp_ids = array_values(array_filter(array_merge(
            [(int) ($in['employee_id'] ?? 0)],
            array_column($in_crew, 'employee_id')
        )));
        try {
            validate_booking_company_scope(
                $cid,
                (int) $in['branch_id'],
                (int) $in['passenger_id'],
                $in_passengers,
                $cat_id_post,
                $veh_id_post,
                $crew_emp_ids
            );
            $in['category_vehicle_id'] = (string) ($cat_id_post ?? '');
        } catch (InvalidArgumentException $ex) {
            $err = $ex->getMessage();
        }
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
        // Resolusi city_id & province_id dari district penjemputan & pengantaran
        $p_dist_id = resolve_district_id($in['pickup_point_district_id']);
        $d_dist_id = resolve_district_id($in['delivery_point_district_id']);

        $p_loc = resolve_district_info($p_dist_id) ?: [];
        $d_loc = resolve_district_info($d_dist_id) ?: [];

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
        } catch (Throwable $ex) {
            $err = $ex->getMessage() === '' ? 'Gagal menyimpan pemesanan sewa.' : $ex->getMessage();
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
$selected_p_ids = array_filter(array_merge([(int) ($in['passenger_id'] ?? 0)], $in_passengers));
$passengers = opts_passengers($cid, $selected_p_ids, 50);
$cats = opts_categories($cid, [(int) ($in['category_vehicle_id'] ?? 0)], 200);
$vehicles = opts_vehicles_detailed(
    $cid,
    [$cid],
    (int) ($in['vehicle_id'] ?? 0) ?: null,
    !empty($in['category_vehicle_id']) ? (int) $in['category_vehicle_id'] : null,
    50
);
$crewEmpIds = array_values(array_unique(array_filter(array_map(static fn($cr) => (int) ($cr['employee_id'] ?? 0), $in_crew))));
$employees  = opts_employees($cid, $crewEmpIds, 50);

$p_form_dist = resolve_district_id($in['pickup_point_district_id'] ?? 0);
$d_form_dist = resolve_district_id($in['delivery_point_district_id'] ?? 0);

$provinces = opts_provinces([(int) ($in['pickup_point_province_id'] ?? 0), (int) ($in['delivery_point_province_id'] ?? 0)], 100);
$pickup_cities = opts_cities(
    !empty($in['pickup_point_province_id']) ? (int) $in['pickup_point_province_id'] : null,
    [(int) ($in['pickup_point_city_id'] ?? 0)],
    !empty($in['pickup_point_province_id']) ? 100 : 50
);
$delivery_cities = opts_cities(
    !empty($in['delivery_point_province_id']) ? (int) $in['delivery_point_province_id'] : null,
    [(int) ($in['delivery_point_city_id'] ?? 0)],
    !empty($in['delivery_point_province_id']) ? 100 : 50
);
$company_regulations = regulation_list_by_company($cid);

$pickup_districts   = opts_districts_by_city((int) ($in['pickup_point_city_id'] ?? 0), [$p_form_dist], 100);
$delivery_districts = opts_districts_by_city((int) ($in['delivery_point_city_id'] ?? 0), [$d_form_dist], 100);

$active = 'rental';
$title  = $is_edit ? 'Ubah Pemesanan Sewa Kendaraan - ' . $b['document_number'] : 'Pemesanan Sewa Kendaraan Baru';
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-2 p-md-3">
    <!-- Header Toolbar Halaman -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-car-front-fill me-1"></i>Pemesanan Sewa Kendaraan</span>
            <span class="badge bg-secondary-subtle text-secondary border"><?= $is_edit ? 'Ubah Pemesanan' : 'Draft Baru' ?></span>
            <span id="rental_duration_badge" class="badge bg-primary-subtle text-primary border">1 Hari Sewa</span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Batal
            </a>
            <button type="submit" form="formRentalBooking" class="btn btn-primary btn-sm px-3">
                <i class="bi bi-check2-circle me-1"></i>Simpan Pemesanan
            </button>
        </div>
    </div>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- 5 SUB-TABS -->
        <ul class="nav nav-tabs erp-sub-tabs" id="rentalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">
                    Detail
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-crew-btn" data-bs-toggle="tab" data-bs-target="#tab-crew" type="button" role="tab">
                    Sopir/Kernet/Montir
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">
                    Pembayaran Sewa
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
            <form method="post" action="" id="formRentalBooking" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="date" id="rental_booking_date" value="<?= e($in['date']) ?>">
                <input type="hidden" name="trip" id="rental_trip" value="<?= e($in['trip'] !== '' && (float)$in['trip'] > 0 ? $in['trip'] : '1') ?>">
                <input type="hidden" name="redirect_qris" id="redirect_qris_flag" value="">

                <div class="tab-content" id="rentalTabContent">
                    <!-- ========================================================== -->
                    <!-- TAB 1: DETAIL                                              -->
                    <!-- ========================================================== -->
                    <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                        <div class="rnt-panel">
                            <div class="rnt-panel-body">
                                <!-- Resi -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Resi</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm fw-bold bg-light font-monospace" readonly value="OTOMATIS (100009<?= date('Ymd') ?>)">
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

                                <!-- Tanggal Keberangkatan (Mulai | Selesai | Durasi Hari) -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Tanggal Keberangkatan <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <div class="row g-2 align-items-center">
                                            <div class="col-12 col-sm-5">
                                                <input type="text" class="form-control form-control-sm bg-white flatpickr-date" id="rental_start_date" name="start_date" required value="<?= e($in['start_date']) ?>" placeholder="dd-MMM-yyyy">
                                            </div>
                                            <div class="col-12 col-sm-5">
                                                <input type="text" class="form-control form-control-sm bg-white flatpickr-date" id="rental_end_date" name="end_date" required value="<?= e($in['end_date']) ?>" placeholder="dd-MMM-yyyy">
                                            </div>
                                            <div class="col-12 col-sm-2">
                                                <input type="number" min="1" max="365" class="form-control form-control-sm text-end fw-semibold" id="rental_duration_days" name="duration_days" value="<?= e($in['duration_days']) ?>" title="Durasi Sewa (Hari)">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Jam -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Jam <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input type="time" class="form-control form-control-sm" name="time" id="rental_time_input" required value="<?= e($in['time']) ?>" style="max-width: 260px;">
                                    </div>
                                </div>

                                <!-- Harga -->
                                <div class="rnt-row">
                                    <label class="rnt-label">Harga <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="rental_price_display" placeholder="0" value="<?= format_nominal((float) $in['total_price']) ?>">
                                        <input type="hidden" id="rental_total_price" name="total_price" value="<?= e($in['total_price']) ?>">
                                        <div id="rental_calc_hint" class="form-text small" style="display: none;"></div>
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
                                        <select class="form-select form-select-sm ts-select" id="rental_category_select" name="category_vehicle_id" data-ts-placeholder="-- Semua Kategori Kendaraan --">
                                            <option value="">-- Semua Kategori Kendaraan --</option>
                                            <?= select_options($cats, $in['category_vehicle_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Kendaraan</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="rental_vehicle_select" name="vehicle_id"
                                            data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-parent="#rental_category_select"
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

                        <!-- PANEL PELANGGAN -->
                        <div class="rnt-panel">
                            <div class="rnt-panel-header">
                                <span>Pelanggan</span>
                                <a href="#" data-bs-toggle="modal" data-bs-target="#modalQuickPassenger" class="text-primary text-decoration-none small fw-semibold">
                                    Tambah Pelanggan Baru
                                </a>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Pelanggan Utama <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="select_passenger_id" name="passenger_id" required
                                            data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-limit="30"
                                            data-ts-placeholder="-- Pilih Pelanggan Utama --">
                                            <option value="">-- Pilih Pelanggan Utama --</option>
                                            <?= select_options($passengers, $in['passenger_id']) ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1">Penumpang Tambahan</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm ts-select" id="extraPassengersSelect" name="passenger_ids[]" multiple
                                            data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-limit="30"
                                            placeholder="Pilih penumpang tambahan (opsional)...">
                                            <?= select_options($passengers, $in_passengers) ?>
                                        </select>
                                        <div class="form-text small">Opsional — pilih penumpang lain yang ikut serta (ditampilkan pada E-Tiket).</div>
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
                                        <span>Penjemputan</span>
                                    </div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rental_pickup_province" name="pickup_point_province_id"
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
                                                <select class="form-select form-select-sm ts-select" id="rental_pickup_city" name="pickup_point_city_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-parent="#rental_pickup_province"
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
                                                <select class="form-select form-select-sm ts-select" id="rental_pickup_district" name="pickup_point_district_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                    data-ts-parent="#rental_pickup_city"
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
                                                <textarea class="form-control form-control-sm" name="pickup_address" rows="5" maxlength="2000" placeholder="Titik penjemputan lengkap..."><?= e($in['pickup_address']) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Kolom Kanan: Pengantaran -->
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header">
                                        <span>Pengantaran</span>
                                    </div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rental_delivery_province" name="delivery_point_province_id"
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
                                                <select class="form-select form-select-sm ts-select" id="rental_delivery_city" name="delivery_point_city_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-parent="#rental_delivery_province"
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
                                                <select class="form-select form-select-sm ts-select" id="rental_delivery_district" name="delivery_point_district_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                    data-ts-parent="#rental_delivery_city"
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
                    <div class="rnt-panel-body">
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
                        <div class="d-flex align-items-center gap-2">
                            <span>Karyawan</span>
                            <span id="crewRegStatusMsg" class="small text-muted fw-normal"></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" id="btnApplyRegulation" title="Hitung otomatis premi dan BBM sesuai peraturan komisi kategori armada">
                                <i class="bi bi-calculator me-1"></i>Auto Hitung Komisi
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddCrewRow">
                                <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                            </button>
                        </div>
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
                                        <select class="form-select form-select-sm ts-select crew-emp-select" name="crew_employee_id[]"
                                            data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                                            data-ts-company="<?= (int) $cid ?>"
                                            data-ts-limit="30"
                                            data-ts-dropdown-parent="body">
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
                <!-- Field Atas: Total Pembayaran -->
                <div class="rnt-panel">
                    <div class="rnt-panel-body">
                        <div class="rnt-row">
                            <label class="rnt-label">Total Pembayaran</label>
                            <div class="rnt-field">
                                <input type="text" class="form-control form-control-sm fw-bold bg-light" id="pay_total_top_display" value="0" readonly style="max-width:320px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grid Pembayaran Sewa (Lampiran | Tipe Pembayaran | Metode Pembayaran | Tanggal | Nominal) -->
                <div class="rnt-panel mb-0">
                    <div class="rnt-panel-header">
                        <span>Pembayaran Sewa</span>
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddPayRow">
                            <i class="bi bi-plus-lg me-1"></i>Tambah Pembayaran Manual
                        </button>
                    </div>
                    <div id="payOverpayAlert" class="alert alert-danger py-2 px-3 small d-none m-2 mb-0" role="alert">
                        <i class="bi bi-exclamation-octagon me-1"></i><span id="payOverpayMsg"></span>
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
                                            <?php foreach (['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'] as $pt): ?>
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
                        <span>Keterangan</span>
                    </div>
                    <div class="rnt-panel-body">
                        <textarea class="form-control form-control-sm" name="note" rows="8" maxlength="2000" placeholder="Tuliskan keterangan sewa, fasilitas tambahan, atau instruksi khusus..."><?= e($in['note']) ?></textarea>
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
                                <input type="text" class="form-control form-control-sm bg-light" value="<?= e($is_edit ? ($b['created_user'] ?? '-') : ($_SESSION['username'] ?? '-')) ?>" readonly>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Dibuat Tanggal</label>
                            <div class="rnt-field">
                                <input type="text" class="form-control form-control-sm bg-light" value="<?= $is_edit ? (!empty($b['created_date']) ? date('d-M-Y H:i', strtotime($b['created_date'])) : '-') : date('d-M-Y H:i') ?>" readonly>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Diubah Oleh</label>
                            <div class="rnt-field">
                                <input type="text" class="form-control form-control-sm bg-light" value="<?= e($is_edit ? ($b['modified_user'] ?? '-') : '-') ?>" readonly>
                            </div>
                        </div>
                        <div class="rnt-row">
                            <label class="rnt-label">Diubah Tanggal</label>
                            <div class="rnt-field">
                                <input type="text" class="form-control form-control-sm bg-light" value="<?= $is_edit ? (!empty($b['modified_date']) ? date('d-M-Y H:i', strtotime($b['modified_date'])) : '-') : '-' ?>" readonly>
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
        if (typeof recalcPayTotals === 'function') recalcPayTotals();
    }
    if (priceDisplay && priceHidden) {
        attachRupiahMask(priceDisplay, priceHidden, function () {
            if (typeof recalcPayTotals === 'function') recalcPayTotals();
        });
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

    // 3. Filter Kendaraan Berdasarkan Kategori (Tom Select + Server Remote Search)
    var catSelect = document.getElementById('rental_category_select');
    var vehSelect = document.getElementById('rental_vehicle_select');
    var onSelectChange = window.bindTsChange;
    var updateSelectOptions = window.setTsOptions;

    var primPaxSel  = document.getElementById('select_passenger_id');
    var extraPaxSel = document.getElementById('extraPassengersSelect');
    var seatWarnEl  = document.getElementById('seatCapacityWarning');
    function updateRentalSeatWarn() {
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, primPaxSel, extraPaxSel, seatWarnEl);
        }
    }

    if (catSelect && vehSelect) {
        bindTsChange(catSelect, function (cidVal) {
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
                    setTsOptions(vehSelect, data, keepVal, '-- Pilih Unit Kendaraan --');
                    if (keepVal !== curVal) checkVehicleAvailability();
                    updateRentalSeatWarn();
                });
        });

        bindTsChange(vehSelect, function (vId) {
            var vOpt = vehSelect.tomselect ? vehSelect.tomselect.options[String(vId)] : null;
            var curCat = catSelect.tomselect ? String(catSelect.tomselect.getValue() || '') : String(catSelect.value || '');
            if (vOpt && vOpt.category && !curCat) {
                setTsValueSilent(catSelect, String(vOpt.category));
            }
            checkVehicleAvailability();
            updateRentalSeatWarn();
        });
    }

    // 4. Dropdown Bertingkat: Provinsi -> Kota/Kab -> Kecamatan (Tom Select + AJAX)
    function reloadCityOptions(provVal, targetCityId, targetDistId) {
        var cityEl = document.getElementById(targetCityId);
        var distEl = document.getElementById(targetDistId);
        if (!cityEl) return;
        if (distEl) setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
        var u = '<?= e(APP_URL) ?>/pages/masters/cities/options.php?limit=' + (provVal ? '100&province_id=' + encodeURIComponent(provVal) : '50&all=1');
        fetch(u)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setTsOptions(cityEl, data, '', '-- Pilih Kota/Kab --');
            });
    }

    function reloadDistrictOptions(cityVal, targetSelectId, currentVal) {
        var el = document.getElementById(targetSelectId);
        if (!el) return;
        if (!cityVal) {
            setTsOptions(el, [], '', '-- Pilih Kecamatan --');
            return;
        }
        fetch('<?= e(APP_URL) ?>/pages/masters/districts/options.php?limit=100&city_id=' + encodeURIComponent(cityVal))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setTsOptions(el, data, currentVal, '-- Pilih Kecamatan --');
            });
    }

    var pProv = document.getElementById('rental_pickup_province');
    var pCity = document.getElementById('rental_pickup_city');
    if (pProv) bindTsChange(pProv, function (val) { reloadCityOptions(val, 'rental_pickup_city', 'rental_pickup_district'); });
    if (pCity) bindTsChange(pCity, function (val) {
        var opt = pCity.tomselect ? pCity.tomselect.options[String(val)] : null;
        var curP = pProv ? (pProv.tomselect ? String(pProv.tomselect.getValue() || '') : String(pProv.value || '')) : '';
        if (pProv && !curP && opt && opt.province_id) {
            setTsValueSilent(pProv, String(opt.province_id));
        }
        reloadDistrictOptions(val, 'rental_pickup_district', '');
    });

    var dProv = document.getElementById('rental_delivery_province');
    var dCity = document.getElementById('rental_delivery_city');
    if (dProv) bindTsChange(dProv, function (val) { reloadCityOptions(val, 'rental_delivery_city', 'rental_delivery_district'); });
    if (dCity) bindTsChange(dCity, function (val) {
        var opt = dCity.tomselect ? dCity.tomselect.options[String(val)] : null;
        var curP = dProv ? (dProv.tomselect ? String(dProv.tomselect.getValue() || '') : String(dProv.value || '')) : '';
        if (dProv && !curP && opt && opt.province_id) {
            setTsValueSilent(dProv, String(opt.province_id));
        }
        reloadDistrictOptions(val, 'rental_delivery_district', '');
    });

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
            payload.append('company_id', '<?= (int) $cid ?>');
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
                var pidStr = String(p.id);
                var pSelect = document.getElementById('select_passenger_id');
                if (pSelect) {
                    if (pSelect.tomselect) {
                        pSelect.tomselect.addOption({ value: pidStr, text: labelText });
                        pSelect.tomselect.setValue(pidStr);
                    } else {
                        var opt = document.createElement('option');
                        opt.value = pidStr;
                        opt.textContent = labelText;
                        opt.selected = true;
                        pSelect.appendChild(opt);
                    }
                }

                var extraSel = document.getElementById('extraPassengersSelect');
                if (extraSel) {
                    if (extraSel.tomselect) {
                        extraSel.tomselect.addOption({ value: pidStr, text: labelText });
                        extraSel.tomselect.removeItem(pidStr, true);
                        if (window.syncTsLastVal) window.syncTsLastVal(extraSel);
                    } else {
                        var opt2 = document.createElement('option');
                        opt2.value = pidStr;
                        opt2.textContent = labelText;
                        extraSel.appendChild(opt2);
                    }
                }
                if (pSelect && window.syncTsLastVal) window.syncTsLastVal(pSelect);
                updateRentalSeatWarn();

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

    // Hindari pelanggan utama ikut terpilih ganda di daftar penumpang tambahan + peringatan kapasitas kursi
    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(primPaxSel, extraPaxSel, updateRentalSeatWarn);
    }
    updateRentalSeatWarn();

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

        var url = '<?= e(APP_URL) ?>/pages/rental/bookings/check_availability.php?company_id=' + encodeURIComponent('<?= (int) $cid ?>')
                + '&vehicle_id=' + encodeURIComponent(vId)
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

    if (vehSelect && !vehSelect.dataset.availBound) {
        vehSelect.dataset.availBound = '1';
        bindTsChange(vehSelect, checkVehicleAvailability);
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
            var curVal = empSel.tomselect ? String(empSel.tomselect.getValue() || '') : String(empSel.value || '');
            var orgVal = '';
            var posVal = '';
            if (curVal && empSel.tomselect && empSel.tomselect.options[curVal]) {
                orgVal = empSel.tomselect.options[curVal].job_title || '';
                posVal = empSel.tomselect.options[curVal].job_position || '';
            } else if (empSel.selectedIndex >= 0 && empSel.options[empSel.selectedIndex]) {
                var opt = empSel.options[empSel.selectedIndex];
                orgVal = opt.getAttribute('data-org') || '';
                posVal = opt.getAttribute('data-pos') || '';
            }
            if (orgInp) orgInp.value = orgVal;
            if (posInp) posInp.value = posVal;
        }
        if (empSel) {
            if (window.initTsSelect && !empSel.tomselect) {
                window.initTsSelect(empSel);
            }
            if (window.bindTsChange) {
                window.bindTsChange(empSel, syncEmpMeta);
            } else {
                empSel.addEventListener('change', syncEmpMeta);
            }
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
                <select class="form-select form-select-sm ts-select crew-emp-select" name="crew_employee_id[]"
                    data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                    data-ts-company="<?= (int) $cid ?>"
                    data-ts-limit="30"
                    data-ts-dropdown-parent="body">${empOptionsHtml}</select>
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
        var prEl  = document.getElementById('rental_total_price');

        var regId = typeof manualRegId !== 'undefined' ? parseInt(manualRegId || '0', 10) : (regSelect ? parseInt(regSelect.value || '0', 10) : 0);
        var catId = catEl ? parseInt(catEl.value || '0', 10) : 0;
        var trip  = trpEl ? parseFloat(trpEl.value || '1') : 1;
        var sDate = stEl ? stEl.value : '';
        var price = prEl ? parseRupiah(prEl.value) : 0;

        if (!catId) {
            if (regMsgEl) {
                regMsgEl.className = 'small text-warning fw-semibold';
                regMsgEl.textContent = 'Pilih Kategori Kendaraan terlebih dahulu pada tab Detail!';
            }
            return;
        }

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

    if (bbmDisp && bbmVal) {
        attachRupiahMask(bbmDisp, bbmVal, function () {
            recalcCrewTotals();
        });
    }

    if (crewTbody) {
        crewTbody.querySelectorAll('tr.crew-row').forEach(bindCrewRow);
    }
    ['btnAddCrewRow', 'btnFooterAddCrew'].forEach(function (id) {
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
    var paySubDisp      = document.getElementById('pay_subtotal_display');
    var payTbody        = document.getElementById('payTableBody');
    var payCountEl      = document.getElementById('payRecordCount');
    var payOverAlert    = document.getElementById('payOverpayAlert');
    var payOverMsg      = document.getElementById('payOverpayMsg');
    var todayIso        = <?= json_encode(date('Y-m-d')) ?>;

    function getPayValidationState(paySum) {
        var current = paySum !== undefined ? paySum : (function () {
            var s = 0;
            var rs = payTbody ? payTbody.querySelectorAll('tr.pay-row') : [];
            rs.forEach(function (tr) {
                var hid = tr.querySelector('.pay-amt-value');
                s += parseRupiah(hid ? hid.value : '0');
            });
            return s;
        })();
        var targetTotal = parseRupiah(priceHidden ? priceHidden.value : '0');
        var exceeded = current > targetTotal + 0.001;
        return { paySum: current, targetTotal: targetTotal, exceeded: exceeded };
    }

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

        var st = getPayValidationState(paySum);
        if (payOverAlert && payOverMsg) {
            if (st.exceeded) {
                payOverMsg.textContent = 'Total pembayaran (Rp ' + formatRupiah(st.paySum) + ') tidak boleh melebihi total harga sewa (Rp ' + formatRupiah(st.targetTotal) + ').';
                payOverAlert.classList.remove('d-none');
            } else {
                payOverAlert.classList.add('d-none');
                payOverMsg.textContent = '';
            }
        }
        [payTopDisp, paySubDisp].forEach(function (el) {
            if (!el) return;
            el.classList.toggle('is-invalid', st.exceeded);
            el.classList.toggle('text-danger', st.exceeded);
        });
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
    ['btnAddPayRow', 'btnFooterAddPay'].forEach(function (id) {
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
    // Handler auto-switch tab bila ada field required yang belum diisi di tab lain
    var rentalFormEl = document.getElementById('formRentalBooking');
    if (rentalFormEl) {
        recalcPayTotals();
        rentalFormEl.addEventListener('submit', function (e) {
            var paySt = getPayValidationState();
            if (paySt.exceeded) {
                e.preventDefault();
                recalcPayTotals();
                var payTabBtn = document.querySelector('[data-bs-target="#tab-payment"]');
                if (payTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(payTabBtn).show();
                }
                setTimeout(function () {
                    var targetInp = null;
                    if (payTbody) {
                        payTbody.querySelectorAll('tr.pay-row').forEach(function (tr) {
                            var v = parseRupiah((tr.querySelector('.pay-amt-value') || {}).value || '0');
                            if (!targetInp && v > 0) targetInp = tr.querySelector('.pay-amt-display');
                        });
                    }
                    if (targetInp) targetInp.focus();
                    if (payOverAlert) payOverAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 150);
                return false;
            }
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

    document.querySelectorAll('#formRentalBooking [required]').forEach(function (el) {
        el.addEventListener('invalid', function () {
            var pane = el.closest('.tab-pane');
            if (pane) {
                var tabBtn = document.querySelector('[data-bs-target="#' + pane.id + '"]');
                if (tabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(tabBtn).show();
                }
            }
        });
    });

    recalcPayTotals();
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
