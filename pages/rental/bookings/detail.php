<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/dispatch_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b   = booking_find('rental', $id);
if (!$b) {
    flash('error', 'Booking tidak ditemukan.');
    redirect('pages/rental/bookings/index.php');
}
assert_company_access((int) $b['company_id']);
$booking_cid = (int) $b['company_id'];

$user      = $_SESSION['username'] ?? null;
$bid_type  = 'rental';
$canManage = can('rental.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengelola pemesanan sewa kendaraan.');
        redirect('pages/rental/bookings/detail.php?id=' . $id);
    }
    $act  = (string) ($_POST['act'] ?? '');
    $note = trim((string) ($_POST['reason'] ?? ''));
    try {
        if ($act === 'update_detail') {
            $start_date   = trim((string) ($_POST['start_date'] ?? $b['start_date']));
            $end_date     = trim((string) ($_POST['end_date'] ?? $b['end_date']));
            $time         = trim((string) ($_POST['time'] ?? substr((string) $b['time'], 0, 5)));
            $branch_id    = (int) ($_POST['branch_id'] ?? $b['branch_id']);
            $passenger_id = (int) ($_POST['passenger_id'] ?? $b['passenger_id']);
            $cat_id       = (int) ($_POST['category_vehicle_id'] ?? 0) ?: null;
            $veh_id       = (int) ($_POST['vehicle_id'] ?? 0) ?: null;
            if ($veh_id !== null && $veh_id > 0 && ($cat_id === null || $cat_id <= 0)) {
                $stVehCat = db()->prepare("SELECT category_vehicle_id FROM vehicles WHERE id = ?");
                $stVehCat->bind_param('i', $veh_id);
                $stVehCat->execute();
                $catRow = $stVehCat->get_result()->fetch_assoc();
                $stVehCat->close();
                if (!empty($catRow['category_vehicle_id'])) {
                    $cat_id = (int) $catRow['category_vehicle_id'];
                }
            }
            $total_price  = parse_rupiah_input($_POST['total_price'] ?? $b['total_price']);
            $trip         = (float) ($_POST['trip'] ?? $b['trip']);
            $bbm          = parse_rupiah_input($_POST['bbm'] ?? $b['bbm']);
            $c_types      = (array) ($_POST['crew_type'] ?? []);
            $c_emps       = (array) ($_POST['crew_employee_id'] ?? []);
            $c_amts       = (array) ($_POST['crew_amount'] ?? []);
            $in_crew      = [];
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
            $p_ids     = (array) ($_POST['pay_id'] ?? []);
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
                        'id'         => (int) ($p_ids[$idx] ?? 0),
                        'pay_type'   => trim((string) $pt) ?: 'Down Payment (DP)',
                        'pay_method' => trim((string) ($p_methods[$idx] ?? 'Transfer')) ?: 'Transfer',
                        'pay_date'   => trim((string) ($p_dates[$idx] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                        'amount'     => $pAmt,
                    ];
                }
            }
            $p_prov       = (int) ($_POST['pickup_point_province_id'] ?? 0) ?: null;
            $p_city       = (int) ($_POST['pickup_point_city_id'] ?? 0) ?: null;
            $p_dist       = resolve_district_id($_POST['pickup_point_district_id'] ?? $b['pickup_point_district_id']);
            $p_addr       = trim((string) ($_POST['pickup_address'] ?? $b['pickup_address']));
            $d_prov       = (int) ($_POST['delivery_point_province_id'] ?? 0) ?: null;
            $d_city       = (int) ($_POST['delivery_point_city_id'] ?? 0) ?: null;
            $d_dist       = resolve_district_id($_POST['delivery_point_district_id'] ?? $b['delivery_point_district_id']);
            $d_addr       = trim((string) ($_POST['delivery_address'] ?? $b['delivery_address']));
            $b_note       = trim((string) ($_POST['note'] ?? $b['note']));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                throw new InvalidArgumentException('Tanggal keberangkatan (mulai & selesai sewa) wajib diisi.');
            }
            if ($end_date < $start_date) {
                throw new InvalidArgumentException('Tanggal selesai sewa tidak boleh sebelum tanggal mulai.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Format jam keberangkatan tidak valid.');
            }
            if ($branch_id <= 0 || $passenger_id <= 0 || $p_dist <= 0 || $d_dist <= 0) {
                throw new InvalidArgumentException('Cabang, pelanggan, serta kecamatan penjemputan & pengantaran wajib dipilih.');
            }

            // Cek bentrok armada (kecualikan booking ini sendiri: b.id != $id)
            if ($veh_id !== null && $veh_id > 0) {
                $chk_st = db()->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date, p.name AS passenger_name, v.vehicle_number,
                                                (SELECT to_status FROM booking_status_history WHERE booking_type = 'rental' AND booking_id = b.id ORDER BY id DESC LIMIT 1) AS cur_status
                                         FROM rental_car_bookings b
                                         LEFT JOIN passengers p ON p.id = b.passenger_id
                                         LEFT JOIN vehicles v ON v.id = b.vehicle_id
                                         WHERE b.company_id = ?
                                           AND b.vehicle_id = ?
                                           AND b.id <> ?
                                           AND b.start_date <= ?
                                           AND b.end_date >= ?
                                         HAVING cur_status != 'cancelled' OR cur_status IS NULL
                                         LIMIT 1");
                $chk_st->bind_param('iiiss', $booking_cid, $veh_id, $id, $end_date, $start_date);
                $chk_st->execute();
                $conflict = $chk_st->get_result()->fetch_assoc();
                $chk_st->close();
                if ($conflict) {
                    throw new RuntimeException('Armada ' . ($conflict['vehicle_number'] ?: '') . ' sudah ter-booking pada tanggal tersebut (' . $conflict['document_number'] . ').');
                }
            }

            // Resolusi authoritative city/province dari district
            $p_info = resolve_district_info($p_dist);
            if ($p_info) {
                $p_city = $p_info['city_id'];
                $p_prov = $p_info['province_id'];
            }
            $d_info = resolve_district_info($d_dist);
            if ($d_info) {
                $d_city = $d_info['city_id'];
                $d_prov = $d_info['province_id'];
            }

            $time_sql = $time . ':00';

            $db = db();
            $db->begin_transaction();
            try {
                $up = $db->prepare('UPDATE rental_car_bookings
                    SET branch_id = ?, date = ?, time = ?, start_date = ?, end_date = ?, trip = ?, bbm = ?,
                        pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                        delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                        total_price = ?, category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                        modified_user = ?, modified_date = NOW()
                    WHERE id = ? AND company_id = ?');
                $up->bind_param(
                    'issssddsiiisiiidiiissii',
                    $branch_id, $start_date, $time_sql, $start_date, $end_date, $trip, $bbm,
                    $p_addr, $p_prov, $p_city, $p_dist,
                    $d_addr, $d_prov, $d_city, $d_dist,
                    $total_price, $cat_id, $veh_id, $passenger_id, $b_note,
                    $user, $id, $booking_cid
                );
                $up->execute();
                $up->close();

                // Sinkronkan invoice subtotal & total bila harga berubah
                if (abs($total_price - (float) $b['total_price']) > 0.01) {
                    $stInv = $db->prepare("SELECT id, tax_percent FROM invoices WHERE booking_type = 'rental' AND booking_id = ? AND company_id = ? LIMIT 1");
                    $stInv->bind_param('ii', $id, $booking_cid);
                    $stInv->execute();
                    $invRow = $stInv->get_result()->fetch_assoc();
                    $stInv->close();
                    if ($invRow) {
                        $taxPct = (float) $invRow['tax_percent'];
                        $taxAmt = round($total_price * $taxPct / 100, 2);
                        $newTot = $total_price + $taxAmt;
                        $upInv = $db->prepare('UPDATE invoices SET branch_id = ?, subtotal = ?, tax_amount = ?, total = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                        $upInv->bind_param('idddsi', $branch_id, $total_price, $taxAmt, $newTot, $user, $invRow['id']);
                        $upInv->execute();
                        $upInv->close();
                    }
                }

                // Sinkronkan penumpang utama
                $extra_pids = array_values(array_unique(array_filter(
                    array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
                    static fn($v) => $v > 0 && $v !== $passenger_id
                )));
                booking_passengers_set('rental', $id, $booking_cid, array_merge([$passenger_id], $extra_pids), $passenger_id, $user);

                // Sinkronkan rincian kru/premi (Sopir, Kernet, Montir, Agen) + BBM + Total Biaya Operasional
                booking_rental_crew_sync($id, $booking_cid, $bbm, $in_crew, $veh_id, $user);

                // Sinkronkan rincian Pembayaran Sewa (DP / Pelunasan / Transfer / lampiran bukti)
                $stInvP = $db->prepare("SELECT id FROM invoices WHERE booking_type = 'rental' AND booking_id = ? AND company_id = ? LIMIT 1");
                $stInvP->bind_param('ii', $id, $booking_cid);
                $stInvP->execute();
                $invPRow = $stInvP->get_result()->fetch_assoc();
                $stInvP->close();
                if ($invPRow && isset($_POST['pay_sync_present'])) {
                    $invPId = (int) $invPRow['id'];
                    foreach ($in_payments as &$pr) {
                        $fIdx = (int) ($pr['_file_idx'] ?? 0);
                        $uploaded = azure_blob_handle_upload_indexed(
                            'pay_proof',
                            $fIdx,
                            $booking_cid,
                            'finance/payment-proofs',
                            date('Y/m'),
                            'inv-' . $invPId
                        );
                        if ($uploaded !== null) {
                            $pr['proof_blob_path'] = $uploaded;
                        }
                    }
                    unset($pr);
                    booking_rental_payments_sync($id, $invPId, $booking_cid, $in_payments, $user);
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            audit('booking.rental.update', "Booking #{$id} {$b['document_number']} diperbarui");
            flash('success', 'Data pemesanan sewa kendaraan berhasil disimpan.');
        } elseif ($act === 'status') {
            $to = (string) ($_POST['to_status'] ?? '');
            booking_change_status('rental', $id, $cid, $to, $note === '' ? 'Ubah status' : $note, $user);
            audit('booking.rental.status', "Booking #{$id} -> {$to}");
            flash('success', 'Status pemesanan diperbarui.');
        } elseif ($act === 'cancel') {
            booking_change_status('rental', $id, $cid, 'cancelled', $note === '' ? null : $note, $user);
            audit('booking.rental.cancel', "Booking #{$id}");
            flash('success', 'Pemesanan sewa kendaraan dibatalkan.');
        } elseif ($act === 'reschedule') {
            $date = (string) ($_POST['date'] ?? '');
            $time = (string) ($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Tanggal/jam reschedule tidak valid.');
            }
            booking_reschedule('rental', $id, $cid, $date, $time . ':00', $note === '' ? 'Reschedule' : $note, $user);
            audit('booking.rental.reschedule', "Booking #{$id} -> {$date} {$time}");
            flash('success', 'Pemesanan sewa kendaraan dijadwalkan ulang.');
        } elseif ($act === 'driver') {
            $emp = (int) ($_POST['employee_id'] ?? 0);
            $veh = (int) ($_POST['vehicle_id'] ?? 0);
            booking_assign_driver('rental', $id, $cid, $emp, $veh > 0 ? $veh : null, $user);
            audit('booking.rental.driver', "Booking #{$id} driver #{$emp}");
            flash('success', 'Driver berhasil ditugaskan.');
        } elseif ($act === 'delete') {
            $doc_no = booking_delete('rental', $id, $cid);
            audit('booking.rental.delete', "Hapus booking {$doc_no} (#{$id})");
            flash('success', "Pemesanan sewa kendaraan {$doc_no} berhasil dihapus.");
            redirect('pages/rental/bookings/index.php');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() === '' ? 'Aksi gagal dijalankan.' : $ex->getMessage());
    }
    redirect('pages/rental/bookings/detail.php?id=' . $id);
}

$status = booking_current_status('rental', $id);
$next   = BOOKING_TRANSITIONS[$status] ?? [];

$stComp = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $b['company_id']);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $b['company_id'], 'label' => active_company_name()];
$stComp->close();

$st = db()->prepare('SELECT h.from_status, h.to_status, h.reason, h.changed_by, h.changed_at
    FROM booking_status_history h WHERE h.booking_type = ? AND h.booking_id = ? ORDER BY h.id DESC LIMIT 30');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = db()->prepare('SELECT cost_type, description, amount, premi_type, premi_value, premi_amount
    FROM booking_cost_lines WHERE booking_type = ? AND booking_id = ? ORDER BY id');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$costs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = db()->prepare('SELECT t.employee_id, t.vehicle_id, e.name AS employee
    FROM trip_assignments t LEFT JOIN employees e ON e.id = t.employee_id
    WHERE t.booking_type = ? AND t.booking_id = ? AND t.ended_at IS NULL LIMIT 1');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$assignment = $st->get_result()->fetch_assoc();
$st->close();

// Cari invoice terkait booking ini
$stInvId = db()->prepare("SELECT id FROM invoices WHERE booking_type = 'rental' AND booking_id = ? LIMIT 1");
$stInvId->bind_param('i', $id);
$stInvId->execute();
$invRowId = $stInvId->get_result()->fetch_assoc();
$stInvId->close();
$invoice = $invRowId ? invoice_find((int) $invRowId['id']) : null;
$payment_rows = $invoice ? invoice_payments((int) $invoice['id']) : [];

$qrisChannels   = gateway_active_channels($booking_cid);
$qrisInvTotal   = $invoice ? (float) $invoice['total'] : (float) $b['total_price'];
$qrisPaidTotal  = $invoice ? (float) ($invoice['paid_total'] ?? 0) : (float) ($b['total_payment'] ?? 0);
$qrisRemaining  = max(0.0, round($qrisInvTotal - $qrisPaidTotal, 2));
$hasPendingQris = false;
if ($invoice) {
    $stQChk = db()->prepare("SELECT 1 FROM payment_gateway_orders WHERE invoice_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    $invIdInt = (int) $invoice['id'];
    $stQChk->bind_param('i', $invIdInt);
    $stQChk->execute();
    $hasPendingQris = (bool) $stQChk->get_result()->fetch_row();
    $stQChk->close();
}

$assignments = dispatch_by_booking('rental', $id);
$crew_rows   = booking_rental_crew_list($id);
if (!$crew_rows) {
    $crew_rows = [
        [
            'cost_type'   => 'Sopir',
            'employee_id' => $assignment['employee_id'] ?? '',
            'amount'      => 0,
        ],
        [
            'cost_type'   => 'Kernet',
            'employee_id' => '',
            'amount'      => 0,
        ],
    ];
}
$bp_list     = booking_passengers_list('rental', $id, (int) $b['passenger_id']);
$extra_passenger_ids = [];
foreach ($bp_list as $bp) {
    if ((int) $bp['passenger_id'] !== (int) $b['passenger_id']) {
        $extra_passenger_ids[] = (int) $bp['passenger_id'];
    }
}

$branches   = opts_branches((int) $b['company_id']);
$selected_p_ids = array_filter(array_merge([(int) ($b['passenger_id'] ?? 0)], $extra_passenger_ids));
$passengers = opts_passengers((int) $b['company_id'], $selected_p_ids, 50);

$userCompanyIds = accessible_company_ids((int) $b['company_id']);
$cur_veh_id = (int) ($b['vehicle_id'] ?? 0);
$cur_cat_id_raw = (int) ($b['category_vehicle_id'] ?? 0);

// Kategori & kendaraan perusahaan terkait (dibatasi LIMIT agar aman jutaan data + wajib sertakan nilai terpilih)
$cats = opts_categories((int) $b['company_id'], $cur_cat_id_raw > 0 ? [$cur_cat_id_raw] : [], 200);
$vehicles = opts_vehicles_detailed(
    (int) $b['company_id'],
    $userCompanyIds,
    $cur_veh_id > 0 ? $cur_veh_id : null,
    $cur_cat_id_raw > 0 ? $cur_cat_id_raw : null,
    50
);

// Ambil data kendaraan yang sedang terhubung untuk preview detail
$cur_veh = null;
if ($cur_veh_id > 0) {
    foreach ($vehicles as $v) {
        if ((int) $v['id'] === $cur_veh_id) {
            $cur_veh = $v;
            break;
        }
    }
}
$cur_category_id = (int) ($b['category_vehicle_id'] ?: ($cur_veh['category_vehicle_id'] ?? 0));

$employees  = opts_employees((int) $b['company_id']);
$company_regulations = regulation_list_by_company((int) $b['company_id']);
$matched_regulation  = regulation_match(
    (int) $b['company_id'],
    (int) ($cur_category_id ?: ($b['category_vehicle_id'] ?? 0)),
    (float) ($b['trip'] ?? 1),
    (string) ($b['start_date'] ?? '')
);

// Resolusi geographic: district_id, city_id, province_id secara authoritative
$p_dist_id = resolve_district_id($b['pickup_point_district_id'] ?? 0);
$d_dist_id = resolve_district_id($b['delivery_point_district_id'] ?? 0);

$p_city_id = (int) ($b['pickup_point_city_id'] ?? 0);
$d_city_id = (int) ($b['delivery_point_city_id'] ?? 0);
$p_prov_id = (int) ($b['pickup_point_province_id'] ?? 0);
$d_prov_id = (int) ($b['delivery_point_province_id'] ?? 0);

$p_info = resolve_district_info($p_dist_id);
if ($p_info) {
    $p_dist_id = $p_info['id'];
    $p_city_id = $p_info['city_id'];
    $p_prov_id = $p_info['province_id'];
}
$d_info = resolve_district_info($d_dist_id);
if ($d_info) {
    $d_dist_id = $d_info['id'];
    $d_city_id = $d_info['city_id'];
    $d_prov_id = $d_info['province_id'];
}

// Fallback O(1) via Primary Key jika district belum ada namun city_id sudah tersimpan
if ($p_prov_id <= 0 && $p_city_id > 0) {
    $p_prov_id = resolve_city_province_id($p_city_id);
}
if ($d_prov_id <= 0 && $d_city_id > 0) {
    $d_prov_id = resolve_city_province_id($d_city_id);
}

$provinces          = opts_provinces([$p_prov_id, $d_prov_id], 100);
$pickup_cities      = opts_cities($p_prov_id > 0 ? $p_prov_id : null, [$p_city_id], $p_prov_id > 0 ? 100 : 50);
$delivery_cities    = opts_cities($d_prov_id > 0 ? $d_prov_id : null, [$d_city_id], $d_prov_id > 0 ? 100 : 50);
$pickup_districts   = opts_districts_by_city($p_city_id, [$p_dist_id], 100);
$delivery_districts = opts_districts_by_city($d_city_id, [$d_dist_id], 100);

$d1 = new DateTime((string) $b['start_date']);
$d2 = new DateTime((string) $b['end_date']);
$duration_days = max(1, (int) $d1->diff($d2)->days + 1);

$active = 'rental';
$title  = 'Detail Pemesanan Sewa Kendaraan - ' . $b['document_number'];
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-2 p-md-3">
    <!-- Top Action Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-car-front me-1"></i>Pemesanan Sewa Kendaraan</span>
            <span id="bookingStatusBadgeWrap"><?= status_badge($status) ?></span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
            <?php if ($canManage): ?>
                <button type="submit" form="rentalDetailForm" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-check2-circle me-1"></i>Simpan Perubahan
                </button>
            <?php endif; ?>
            <a class="btn btn-dark btn-sm" target="_blank" href="<?= e(APP_URL) ?>/tiket.php?no=<?= urlencode((string) $b['document_number']) ?>&print=1">
                <i class="bi bi-printer me-1"></i>Cetak E-Tiket
            </a>
        </div>
    </div>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- 5 SUB-TABS -->
        <ul class="nav nav-tabs erp-sub-tabs" id="rentalDetailTabs" role="tablist">
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
            <!-- Form Utama untuk Update Detail + Kru + Pembayaran + Keterangan -->
            <form method="post" action="" id="rentalDetailForm" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="update_detail">
                <input type="hidden" name="pay_sync_present" value="1">
                <input type="hidden" name="trip" id="hiddenTrip" value="<?= e((string) $b['trip']) ?>">
                <input type="hidden" name="bbm" id="hiddenBbm" value="<?= (int) round((float) $b['bbm']) ?>">
                <input type="hidden" name="note" id="hiddenNote" value="<?= e((string) $b['note']) ?>">

            <div class="tab-content" id="rentalDetailTabContent">
                <!-- ================= TAB 1: DETAIL ================= -->
                <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Resi</div>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" value="<?= e($b['document_number']) ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Perusahaan</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm bg-light" disabled>
                                        <option value="<?= (int) $curCompany['id'] ?>" selected><?= e($curCompany['label']) ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Cabang <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm" name="branch_id" form="rentalDetailForm" <?= $canManage ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Cabang --</option>
                                        <?= select_options($branches, $b['branch_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Tanggal Keberangkatan <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-12 col-sm-5">
                                            <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="start_date" id="startDateInput" form="rentalDetailForm" required value="<?= e($b['start_date']) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="col-12 col-sm-5">
                                            <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="end_date" id="endDateInput" form="rentalDetailForm" required value="<?= e($b['end_date']) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? '' : 'disabled' ?>>
                                        </div>
                                        <div class="col-12 col-sm-2">
                                            <input type="number" min="1" max="365" class="form-control form-control-sm text-end" id="durationDaysInput" value="<?= $duration_days ?>" title="Durasi Sewa (Hari)" <?= $canManage ? '' : 'readonly' ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Jam <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <input type="time" class="form-control form-control-sm" name="time" id="rentalTimeInput" form="rentalDetailForm" required value="<?= e(substr((string) $b['time'], 0, 5)) ?>" <?= $canManage ? '' : 'readonly' ?> style="max-width: 260px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Harga <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="totalPriceDisplay" value="<?= e(format_nominal((float) $b['total_price'])) ?>" placeholder="0" <?= $canManage ? 'required' : 'readonly' ?>>
                                    <input type="hidden" name="total_price" id="totalPriceValue" form="rentalDetailForm" value="<?= (int) round((float) $b['total_price']) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel Kendaraan -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Kendaraan</span>
                            <?php if (can('masters.manage')): ?>
                                <a href="<?= e(APP_URL) ?>/pages/masters/vehicles.php" target="_blank" class="text-primary text-decoration-none fw-normal small">
                                    Tambah Kendaraan Baru
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Kategori</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="category_vehicle_id" id="categoryVehicleSelect" form="rentalDetailForm" data-ts-placeholder="-- Semua Kategori --" <?= $canManage ? '' : 'disabled' ?>>
                                        <option value="">-- Semua Kategori --</option>
                                        <?= select_options($cats, $cur_category_id) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Kendaraan</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="vehicle_id" id="vehicleSelect" form="rentalDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                        data-ts-company="<?= (int) $b['company_id'] ?>"
                                        data-ts-parent="#categoryVehicleSelect"
                                        data-ts-param="category_id"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Kendaraan --"
                                        <?= $canManage ? '' : 'disabled' ?>>
                                        <option value="">-- Pilih Kendaraan --</option>
                                        <?php foreach ($vehicles as $v): ?>
                                            <option value="<?= (int) $v['id'] ?>"
                                                    data-category="<?= (int) ($v['category_vehicle_id'] ?? 0) ?>"
                                                    data-plate="<?= e((string) ($v['vehicle_number'] ?? '')) ?>"
                                                    data-brand="<?= e((string) ($v['brand_name'] ?? '')) ?>"
                                                    data-model="<?= e((string) ($v['model_name'] ?? '')) ?>"
                                                    data-category-name="<?= e((string) ($v['category_name'] ?? '')) ?>"
                                                    data-color="<?= e((string) ($v['vehicle_color'] ?? '')) ?>"
                                                    data-seat="<?= (int) ($v['seat'] ?? 0) ?>"
                                                    <?= (string) $v['id'] === (string) $b['vehicle_id'] ? 'selected' : '' ?>>
                                                <?= e($v['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Preview Ringkasan Armada Terpilih -->
                            <div id="vehicleDetailPreview" class="mt-2 p-2 rounded bg-light border" style="<?= empty($cur_veh) ? 'display:none;' : '' ?>">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-bold text-dark" id="vehPreviewPlate" style="font-size:0.95rem;">
                                            <i class="bi bi-car-front-fill text-primary me-1"></i>
                                            <span><?= e($cur_veh['vehicle_number'] ?? '') ?></span>
                                        </div>
                                        <div class="small text-muted" id="vehPreviewModel" style="font-size:0.8rem;">
                                            <?= e(trim(($cur_veh['brand_name'] ?? '') . ' ' . ($cur_veh['model_name'] ?? ''))) ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-primary-subtle text-primary border" id="vehPreviewCategory">
                                            <?= e($cur_veh['category_name'] ?? 'Armada') ?>
                                        </span>
                                        <div class="small text-muted mt-1" id="vehPreviewSpecs" style="font-size:0.75rem;">
                                            <?= e(trim(($cur_veh['vehicle_color'] ?? '') . (!empty($cur_veh['seat']) ? ' • ' . $cur_veh['seat'] . ' Kursi' : ''))) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel Pelanggan -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Pelanggan</span>
                            <?php if ($canManage): ?>
                                <a href="#" class="text-primary text-decoration-none fw-normal small" data-bs-toggle="modal" data-bs-target="#quickPassengerModal">
                                    Tambah Pelanggan Baru
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Pelanggan Utama <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_id" id="primaryPassengerSelect" form="rentalDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                        data-ts-company="<?= (int) $b['company_id'] ?>"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Pelanggan Utama --"
                                        <?= $canManage ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Pelanggan Utama --</option>
                                        <?= select_options($passengers, $b['passenger_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <div class="rnt-label pt-1">Penumpang Tambahan</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_ids[]" id="extraPassengersSelect" form="rentalDetailForm" multiple
                                        data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                        data-ts-company="<?= (int) $b['company_id'] ?>"
                                        data-ts-limit="30"
                                        placeholder="Pilih penumpang tambahan (opsional)..." <?= $canManage ? '' : 'disabled' ?>>
                                        <?= select_options($passengers, $extra_passenger_ids) ?>
                                    </select>
                                    <div class="form-text small">Opsional — pilih penumpang lain yang ikut dalam penyewaan ini (ditampilkan pada E-Tiket).</div>
                                    <div id="seatCapacityWarning" class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" role="alert"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel Penjemputan & Pengantaran (Side-by-Side) -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header">
                                    <span>Penjemputan</span>
                                </div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_province_id" id="pickupProvince" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $p_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota/Kab <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_city_id" id="pickupCity" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#pickupProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($pickup_cities as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>"
                                                            data-province="<?= (int) $c['province_id'] ?>"
                                                            <?= (int) $c['id'] === $p_city_id ? 'selected' : '' ?>>
                                                        <?= e($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_district_id" id="pickupDistrict" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#pickupCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= $canManage ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?= select_options($pickup_districts, $p_dist_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Detail Alamat</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="pickup_address" form="rentalDetailForm" rows="5" maxlength="2000" <?= $canManage ? '' : 'readonly' ?>><?= e((string) $b['pickup_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header">
                                    <span>Pengantaran</span>
                                </div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_province_id" id="deliveryProvince" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $d_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota/Kab <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_city_id" id="deliveryCity" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#deliveryProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($delivery_cities as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>"
                                                            data-province="<?= (int) $c['province_id'] ?>"
                                                            <?= (int) $c['id'] === $d_city_id ? 'selected' : '' ?>>
                                                        <?= e($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_district_id" id="deliveryDistrict" form="rentalDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#deliveryCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= $canManage ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?= select_options($delivery_districts, $d_dist_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Detail Alamat</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="delivery_address" form="rentalDetailForm" rows="5" maxlength="2000" <?= $canManage ? '' : 'readonly' ?>><?= e((string) $b['delivery_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 2: SOPIR/KERNET/MONTIR ================= -->
                <div class="tab-pane fade" id="tab-crew" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Total Biaya Operasional</div>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" id="crew_total_op_display" value="<?= format_nominal((float) ($b['total_operational_cost'] ?? 0)) ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Bahan Bakar Minyak (BBM)</div>
                                <div class="rnt-field">
                                    <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="crew_bbm_display" value="<?= format_nominal((float) $b['bbm']) ?>" placeholder="0" <?= $canManage ? '' : 'readonly' ?>>
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
                            <?php if ($canManage): ?>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" id="btnApplyRegulation" title="Hitung otomatis premi dan BBM sesuai peraturan komisi kategori armada">
                                    <i class="bi bi-calculator me-1"></i>Auto Hitung Komisi
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddCrewRow">
                                    <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                                </button>
                            </div>
                            <?php endif; ?>
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
                                        <?php if ($canManage): ?><th style="width:42px;" class="text-center">Aksi</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="crewTableBody">
                                    <?php foreach ($crew_rows as $idx => $cr): ?>
                                    <tr class="crew-row">
                                        <td class="text-center text-muted crew-row-num"><?= $idx + 1 ?></td>
                                        <td>
                                            <select class="form-select form-select-sm crew-type-select" name="crew_type[]" form="rentalDetailForm" <?= $canManage ? '' : 'disabled' ?>>
                                                <?php
                                                $curType = (string) ($cr['cost_type'] ?? 'Sopir');
                                                $typeOpts = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'];
                                                if (!in_array($curType, $typeOpts, true) && $curType !== '') {
                                                    $typeOpts[] = $curType;
                                                }
                                                foreach ($typeOpts as $tp): ?>
                                                    <option value="<?= e($tp) ?>" <?= $curType === $tp ? 'selected' : '' ?>><?= e($tp) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm crew-emp-select" name="crew_employee_id[]" form="rentalDetailForm" <?= $canManage ? '' : 'disabled' ?>>
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
                                        <td><input type="text" class="form-control form-control-sm bg-light crew-org-input" value="<?= e((string) ($cr['job_title'] ?? '')) ?>" readonly placeholder="-"></td>
                                        <td><input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="<?= e((string) ($cr['job_position'] ?? '')) ?>" readonly placeholder="-"></td>
                                        <td>
                                            <?php $rowAmt = (float) ($cr['amount'] ?? $cr['premi_amount'] ?? 0); ?>
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="<?= format_nominal($rowAmt) ?>" <?= $canManage ? '' : 'readonly' ?>>
                                            <input type="hidden" name="crew_amount[]" class="crew-amt-value" form="rentalDetailForm" value="<?= $rowAmt ?>">
                                        </td>
                                        <?php if ($canManage): ?>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="5" class="py-2">
                                            <div class="d-flex align-items-center gap-2 small text-muted">
                                                <span id="crewRecordCount">Record 1 of <?= count($crew_rows) ?></span>
                                                <?php if ($canManage): ?>
                                                <div class="btn-group btn-group-sm ms-2">
                                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterAddCrew" title="Tambah Baris">+</button>
                                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterDelCrew" title="Hapus Baris Terakhir">&minus;</button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-2">
                                            <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="crew_subtotal_display" value="0" readonly>
                                        </td>
                                        <?php if ($canManage): ?><td></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 3: PEMBAYARAN SEWA ================= -->
                <div class="tab-pane fade" id="tab-payment" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Total Pembayaran</div>
                                <div class="rnt-field d-flex flex-wrap align-items-center gap-3">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" id="pay_total_top_display" value="<?= format_nominal((float) ($invoice['paid_total'] ?? $b['total_payment'] ?? 0)) ?>" readonly style="max-width:240px;">
                                    <span class="small text-muted">
                                        Sisa Tagihan:
                                        <strong id="qrisRemainingDisplay" class="<?= $qrisRemaining > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= $qrisRemaining > 0 ? ('Rp ' . format_nominal($qrisRemaining)) : 'Lunas (Rp 0)' ?>
                                        </strong>
                                    </span>
                                    <span id="qrisLiveSyncBadge" class="badge bg-info-subtle text-info-emphasis border <?= $hasPendingQris ? '' : 'd-none' ?>">
                                        <i class="bi bi-arrow-repeat me-1"></i>Pantau QRIS Otomatis Aktif
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="qrisAutoNewRowBanner" class="alert alert-success py-2 px-3 small d-none align-items-center justify-content-between mb-3" role="alert">
                        <div>
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span id="qrisAutoNewRowMsg">Pembayaran QRIS baru telah masuk dan ditambahkan otomatis ke tabel.</span>
                        </div>
                        <button type="button" class="btn-close btn-sm" aria-label="Tutup" onclick="document.getElementById('qrisAutoNewRowBanner').classList.add('d-none');document.getElementById('qrisAutoNewRowBanner').classList.remove('d-flex');"></button>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Pembayaran Sewa</span>
                            <?php if ($canManage): ?>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if (!empty($qrisChannels)): ?>
                                <button type="button" class="btn btn-sm btn-success py-0 px-2" id="btnPayQrisAuto" <?= ($status === 'cancelled' || $qrisRemaining <= 0) ? 'disabled' : '' ?>>
                                    <i class="bi bi-qr-code-scan me-1"></i>Bayar via QRIS Otomatis
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Konfigurasikan channel QRIS aktif di menu Payment Channel terlebih dahulu">
                                    <i class="bi bi-qr-code me-1"></i>QRIS Belum Aktif
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddPayRow">
                                    <i class="bi bi-plus-lg me-1"></i>Tambah Pembayaran Manual
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div id="payOverpayAlert" class="alert alert-danger py-2 px-3 small d-none m-2 mb-0" role="alert">
                            <i class="bi bi-exclamation-octagon me-1"></i><span id="payOverpayMsg"></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 align-middle" id="payTable" style="font-size:.84rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:36px;" class="text-center">#</th>
                                        <th style="width:220px;">Lampiran</th>
                                        <th style="width:220px;">Tipe Pembayaran</th>
                                        <th style="width:200px;">Metode Pembayaran</th>
                                        <th style="width:170px;">Tanggal</th>
                                        <th style="width:180px;" class="text-end">Nominal</th>
                                        <?php if ($canManage): ?><th style="width:42px;" class="text-center">Aksi</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="payTableBody">
                                    <?php foreach ($payment_rows as $idx => $pr): ?>
                                    <?php $isAutoQris = !empty($pr['is_auto_qris']); ?>
                                    <tr class="pay-row" data-payment-id="<?= (int) ($pr['id'] ?? 0) ?>">
                                        <td class="text-center text-muted pay-row-num"><?= $idx + 1 ?></td>
                                        <td>
                                            <input type="hidden" name="pay_id[]" form="rentalDetailForm" value="<?= (int) ($pr['id'] ?? 0) ?>">
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($isAutoQris): ?>
                                                    <span class="badge bg-success-subtle text-success border"><i class="bi bi-qr-code me-1"></i>Verified QRIS</span>
                                                <?php elseif (!empty($pr['proof_blob_path'])): ?>
                                                    <a href="<?= e(resolve_asset_url($pr['proof_blob_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 pay-file-label">
                                                        <i class="bi bi-image me-1"></i>Lihat Bukti
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted small pay-file-label">No image data</span>
                                                <?php endif; ?>
                                                <?php if ($canManage && !$isAutoQris): ?>
                                                    <label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Unggah/ganti bukti pembayaran">
                                                        <i class="bi bi-paperclip"></i>
                                                        <input type="file" name="pay_proof[]" form="rentalDetailForm" class="d-none pay-file-input" accept="image/*,application/pdf">
                                                    </label>
                                                <?php elseif ($canManage): ?>
                                                    <input type="file" name="pay_proof[]" form="rentalDetailForm" class="d-none" tabindex="-1">
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm pay-type-select" name="pay_type[]" form="rentalDetailForm" <?= ($canManage && !$isAutoQris) ? '' : 'disabled' ?>>
                                                <?php
                                                $curPt = (string) ($pr['pay_type'] ?? 'Down Payment (DP)');
                                                $ptOpts = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'];
                                                if (!in_array($curPt, $ptOpts, true) && $curPt !== '') $ptOpts[] = $curPt;
                                                foreach ($ptOpts as $pt): ?>
                                                    <option value="<?= e($pt) ?>" <?= $curPt === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($isAutoQris): ?>
                                                <input type="hidden" name="pay_type[]" form="rentalDetailForm" value="<?= e($curPt) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm pay-method-select" name="pay_method[]" form="rentalDetailForm" <?= ($canManage && !$isAutoQris) ? '' : 'disabled' ?>>
                                                <?php
                                                $curPm = (string) ($pr['pay_method'] ?? 'Transfer');
                                                $pmOpts = ['Transfer', 'Tunai', 'QRIS', 'EDC / Kartu'];
                                                if (!in_array($curPm, $pmOpts, true) && $curPm !== '') $pmOpts[] = $curPm;
                                                foreach ($pmOpts as $pm): ?>
                                                    <option value="<?= e($pm) ?>" <?= $curPm === $pm ? 'selected' : '' ?>><?= e($pm) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($isAutoQris): ?>
                                                <input type="hidden" name="pay_method[]" form="rentalDetailForm" value="<?= e($curPm) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="pay_date[]" form="rentalDetailForm" value="<?= e($pr['pay_date'] ?? date('Y-m-d')) ?>" placeholder="dd-MMM-yyyy" <?= ($canManage && !$isAutoQris) ? '' : 'disabled' ?>>
                                            <?php if ($isAutoQris): ?>
                                                <input type="hidden" name="pay_date[]" form="rentalDetailForm" value="<?= e($pr['pay_date'] ?? date('Y-m-d')) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $pRowAmt = (float) ($pr['amount'] ?? 0); ?>
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="<?= format_nominal($pRowAmt) ?>" <?= ($canManage && !$isAutoQris) ? '' : 'readonly' ?>>
                                            <input type="hidden" name="pay_amount[]" class="pay-amt-value" form="rentalDetailForm" value="<?= $pRowAmt ?>">
                                        </td>
                                        <?php if ($canManage): ?>
                                        <td class="text-center">
                                            <?php if (!$isAutoQris): ?>
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                            <?php else: ?>
                                                <i class="bi bi-lock-fill text-muted small" title="Pembayaran terverifikasi otomatis oleh QRIS"></i>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="5" class="py-2">
                                            <div class="d-flex align-items-center gap-2 small text-muted">
                                                <span id="payRecordCount">Record <?= count($payment_rows) ? 1 : 0 ?> of <?= count($payment_rows) ?></span>
                                                <?php if ($canManage): ?>
                                                <div class="btn-group btn-group-sm ms-2">
                                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterAddPay" title="Tambah Baris">+</button>
                                                    <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterDelPay" title="Hapus Baris Terakhir">&minus;</button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-2">
                                            <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="pay_subtotal_display" value="0" readonly>
                                        </td>
                                        <?php if ($canManage): ?><td></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 4: KETERANGAN ================= -->
                <div class="tab-pane fade" id="tab-note" role="tabpanel">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Keterangan</span>
                        </div>
                        <div class="rnt-panel-body">
                            <textarea class="form-control form-control-sm" name="note" id="visibleNote" rows="8" maxlength="2000" placeholder="Tuliskan keterangan sewa, fasilitas tambahan, atau instruksi khusus..." <?= $canManage ? '' : 'readonly' ?>><?= e((string) $b['note']) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 5: AUDIT TRAIL ================= -->
                <div class="tab-pane fade" id="tab-audit" role="tabpanel">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e((string) ($b['created_user'] ?? '-')) ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['created_date']) ? date('d-M-Y H:i', strtotime($b['created_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e((string) ($b['modified_user'] ?? '-')) ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['modified_date']) ? date('d-M-Y H:i', strtotime($b['modified_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </form>
        </div>
    </div>
</main>

<?php if ($canManage): ?>
<!-- Modal Tambah Pelanggan Cepat (AJAX) -->
<div class="modal fade" id="quickPassengerModal" tabindex="-1" aria-labelledby="quickPassengerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="quickPassengerForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="quickPassengerModalLabel"><i class="bi bi-person-plus text-primary me-1"></i>Tambah Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none py-2 small" id="qpError"></div>
                <div class="mb-3">
                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" id="qpName" required maxlength="255" placeholder="Contoh: Budi Santoso">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label">No. HP / WhatsApp</label>
                        <input type="text" class="form-control" name="phone_number" id="qpPhone" maxlength="50" placeholder="0812xxxxxxxx">
                    </div>
                    <div class="col-5">
                        <label class="form-label">Gender</label>
                        <select class="form-select" name="gender" id="qpGender">
                            <option value="">-- Pilih --</option>
                            <option value="L">Laki-laki (L)</option>
                            <option value="P">Perempuan (P)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label">Alamat Singkat (Opsional)</label>
                    <input type="text" class="form-control" name="address" id="qpAddress" maxlength="255" placeholder="Domisili / alamat pelanggan">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary" id="qpSubmitBtn"><i class="bi bi-check2 me-1"></i>Simpan & Pilih</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/qris_modal.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const appUrl   = <?= json_encode(APP_URL) ?>;
    const csrfTok  = <?= json_encode(csrf_token()) ?>;

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

    // Sinkronisasi input Note, BBM, dan Grid Kru (Sopir/Kernet/Montir/Agen)
    const hidBbm        = document.getElementById('hiddenBbm');
    const visNote       = document.getElementById('visibleNote');
    const hidNote       = document.getElementById('hiddenNote');
    const bbmDisp       = document.getElementById('crew_bbm_display');
    const totalOpDisp   = document.getElementById('crew_total_op_display');
    const subCrewDisp   = document.getElementById('crew_subtotal_display');
    const crewTbody     = document.getElementById('crewTableBody');
    const recCountEl    = document.getElementById('crewRecordCount');
    const empOptionsHtml = `<option value="">-- Pilih Karyawan --</option>` + <?= json_encode(implode('', array_map(static fn($emp) =>
        '<option value="' . (int) $emp['id'] . '" data-org="' . e($emp['job_title']) . '" data-pos="' . e($emp['job_position']) . '">' . e($emp['name']) . '</option>',
        $employees
    ))) ?>;

    if (visNote && hidNote) visNote.addEventListener('input', () => { hidNote.value = visNote.value; });

    function recalcCrewTotals() {
        const bbmNum = parseRupiah(hidBbm ? hidBbm.value : '0');
        let crewSum = 0;
        const rows = crewTbody ? crewTbody.querySelectorAll('tr.crew-row') : [];
        rows.forEach((tr, idx) => {
            const numCell = tr.querySelector('.crew-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            const hid = tr.querySelector('.crew-amt-value');
            crewSum += parseRupiah(hid ? hid.value : '0');
        });
        if (subCrewDisp) subCrewDisp.value = formatRupiah(crewSum);
        if (totalOpDisp) totalOpDisp.value = formatRupiah(bbmNum + crewSum);
        if (recCountEl)  recCountEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
    }

    function bindCrewRow(tr) {
        const empSel  = tr.querySelector('.crew-emp-select');
        const orgInp  = tr.querySelector('.crew-org-input');
        const posInp  = tr.querySelector('.crew-pos-input');
        const amtDisp = tr.querySelector('.crew-amt-display');
        const amtVal  = tr.querySelector('.crew-amt-value');
        const delBtn  = tr.querySelector('.btn-remove-crew');

        function syncEmpMeta() {
            if (!empSel) return;
            const opt = empSel.options[empSel.selectedIndex];
            if (orgInp) orgInp.value = opt ? (opt.getAttribute('data-org') || '') : '';
            if (posInp) posInp.value = opt ? (opt.getAttribute('data-pos') || '') : '';
        }
        if (empSel) {
            empSel.addEventListener('change', syncEmpMeta);
            syncEmpMeta();
        }
        if (amtDisp && amtVal) {
            attachRupiahMask(amtDisp, amtVal, () => {
                recalcCrewTotals();
            });
        }
        if (delBtn) {
            delBtn.addEventListener('click', () => {
                tr.remove();
                recalcCrewTotals();
            });
        }
    }

    function addCrewRow(defaultType, defaultAmount = 0, defaultEmpId = '') {
        if (!crewTbody) return;
        const tr = document.createElement('tr');
        tr.className = 'crew-row';
        const tp = defaultType || 'Sopir';
        const amtNum = parseRupiah(defaultAmount);
        const typeOptions = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'];
        if (!typeOptions.includes(tp)) typeOptions.unshift(tp);
        const typeOptsHtml = typeOptions.map(t => `<option value="${t}" ${tp === t ? 'selected' : ''}>${t}</option>`).join('');

        tr.innerHTML = `
            <td class="text-center text-muted crew-row-num">1</td>
            <td>
                <select class="form-select form-select-sm crew-type-select" name="crew_type[]" form="rentalDetailForm">
                    ${typeOptsHtml}
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm crew-emp-select" name="crew_employee_id[]" form="rentalDetailForm">${empOptionsHtml}</select>
            </td>
            <td><input type="text" class="form-control form-control-sm bg-light crew-org-input" value="" readonly placeholder="-"></td>
            <td><input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="" readonly placeholder="-"></td>
            <td>
                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="${formatRupiah(amtNum) || '0'}">
                <input type="hidden" name="crew_amount[]" class="crew-amt-value" form="rentalDetailForm" value="${amtNum}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        crewTbody.appendChild(tr);
        if (defaultEmpId) {
            const empSel = tr.querySelector('.crew-emp-select');
            if (empSel) empSel.value = String(defaultEmpId);
        }
        bindCrewRow(tr);
        recalcCrewTotals();
    }

    // Tombol Hitung & Isi Otomatis Premi dari Peraturan Komisi Karyawan
    const btnApplyReg = document.getElementById('btnApplyRegulation');
    const regSelect   = document.getElementById('crewRegulationSelect');
    const regMsgEl    = document.getElementById('crewRegStatusMsg');
    if (btnApplyReg && crewTbody) {
        btnApplyReg.addEventListener('click', async () => {
            const regId = regSelect ? parseInt(regSelect.value || '0', 10) : 0;
            const catEl = document.getElementById('categoryVehicleSelect');
            const trpEl = document.querySelector('input[name="trip"]');
            const stEl  = document.getElementById('startDateInput');
            const prEl  = document.getElementById('totalPriceValue');

            const catId = catEl ? parseInt(catEl.value || '0', 10) : 0;
            const trip  = trpEl ? parseFloat(trpEl.value || '1') : 1;
            const sDate = stEl ? stEl.value : '';
            const price = prEl ? parseRupiah(prEl.value) : 0;

            btnApplyReg.disabled = true;
            if (regMsgEl) {
                regMsgEl.className = 'small text-muted';
                regMsgEl.textContent = 'Menghitung komisi...';
            }

            try {
                const qs = new URLSearchParams({
                    company_id: String(<?= (int) $b['company_id'] ?>),
                    regulation_id: String(regId),
                    category_vehicle_id: String(catId),
                    trip: String(trip),
                    start_date: sDate,
                    total_price: String(price)
                });
                const res = await fetch(`${appUrl}/pages/rental/regulations/match_api.php?` + qs.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (!data.ok) {
                    if (regMsgEl) {
                        regMsgEl.className = 'small text-danger fw-semibold';
                        regMsgEl.textContent = data.error || 'Gagal memuat peraturan komisi.';
                    }
                    return;
                }

                // Simpan pilihan karyawan yang sudah ada per tipe agar tidak hilang saat dihitung ulang
                const existingEmpsByType = {};
                crewTbody.querySelectorAll('tr.crew-row').forEach((r) => {
                    const t = r.querySelector('.crew-type-select')?.value || 'Sopir';
                    const e = r.querySelector('.crew-emp-select')?.value || '';
                    if (e) {
                        if (!existingEmpsByType[t]) existingEmpsByType[t] = [];
                        existingEmpsByType[t].push(e);
                    }
                });

                const calc = data.calculated || {};
                const bbmVal = parseRupiah(calc.bbm || 0);
                if (hidBbm) hidBbm.value = String(bbmVal);
                if (bbmDisp) bbmDisp.value = formatRupiah(bbmVal) || '0';

                crewTbody.innerHTML = '';
                const crewRows = Array.isArray(calc.crew_rows) ? calc.crew_rows : [];
                crewRows.forEach((cr) => {
                    const tp = cr.type || 'Sopir';
                    const empQueue = existingEmpsByType[tp] || [];
                    const empId = empQueue.length > 0 ? empQueue.shift() : '';
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
                btnApplyReg.disabled = false;
            }
        });
    }

    if (bbmDisp && hidBbm) {
        attachRupiahMask(bbmDisp, hidBbm, () => {
            recalcCrewTotals();
        });
    }
    if (crewTbody) {
        crewTbody.querySelectorAll('tr.crew-row').forEach(bindCrewRow);
    }
    ['btnAddCrewRow', 'btnFooterAddCrew'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', () => addCrewRow('Sopir'));
    });
    const btnFooterDel = document.getElementById('btnFooterDelCrew');
    if (btnFooterDel && crewTbody) {
        btnFooterDel.addEventListener('click', () => {
            const rows = crewTbody.querySelectorAll('tr.crew-row');
            if (rows.length > 0) {
                rows[rows.length - 1].remove();
                recalcCrewTotals();
            }
        });
    }
    recalcCrewTotals();

    // Format ribuan untuk Harga
    const priceDisp = document.getElementById('totalPriceDisplay');
    const priceVal  = document.getElementById('totalPriceValue');
    if (priceDisp && priceVal) {
        attachRupiahMask(priceDisp, priceVal, () => {
            if (typeof recalcPayTotals === 'function') recalcPayTotals();
        });
    }

    // Inisialisasi Flatpickr dengan format tampilan 'd-M-Y' (misal: 11-Nov-2026) dan value server 'Y-m-d'
    const startEl = document.getElementById('startDateInput');
    const endEl   = document.getElementById('endDateInput');
    const durEl   = document.getElementById('durationDaysInput');

    function formatDateIso(d) {
        const yyyy = d.getFullYear();
        const mm   = String(d.getMonth() + 1).padStart(2, '0');
        const dd   = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    function parseDateObj(el) {
        if (!el) return null;
        if (el._flatpickr && el._flatpickr.selectedDates && el._flatpickr.selectedDates.length > 0) {
            const d = el._flatpickr.selectedDates[0];
            return new Date(d.getFullYear(), d.getMonth(), d.getDate());
        }
        if (el.value) {
            const m = String(el.value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (m) {
                return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
            }
        }
        return null;
    }

    function calcDaysFromDates() {
        if (!startEl || !endEl || !durEl) return;
        const s = parseDateObj(startEl);
        const e = parseDateObj(endEl);
        if (!s || !e) return;

        let diff = Math.round((e - s) / 86400000) + 1;
        if (diff < 1) {
            diff = 1;
            if (endEl._flatpickr) {
                endEl._flatpickr.setDate(s, false);
            } else {
                endEl.value = formatDateIso(s);
            }
        }
        durEl.value = diff;
    }

    function calcEndDateFromDays() {
        if (!startEl || !endEl || !durEl) return;
        const s = parseDateObj(startEl);
        if (!s) return;

        let days = parseInt(durEl.value || '1', 10);
        if (isNaN(days) || days < 1) days = 1;
        durEl.value = days;

        const target = new Date(s.getFullYear(), s.getMonth(), s.getDate() + (days - 1));
        if (endEl._flatpickr) {
            endEl._flatpickr.setDate(target, false);
        } else {
            endEl.value = formatDateIso(target);
        }
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

    if (startEl) {
        initFlatpickr(startEl, function() {
            calcDaysFromDates();
        });
    }
    if (endEl) {
        initFlatpickr(endEl, function() {
            calcDaysFromDates();
        });
    }
    document.querySelectorAll('.flatpickr-date').forEach(el => {
        if (el !== startEl && el !== endEl) {
            initFlatpickr(el);
        }
    });

    if (durEl) {
        durEl.addEventListener('input', calcEndDateFromDays);
        durEl.addEventListener('change', calcEndDateFromDays);
    }
    calcDaysFromDates();

    // Label AM/PM Bahasa Indonesia Dinamis untuk Jam
    function getTimePeriodLabel(timeVal) {
        if (!timeVal) return '';
        const parts = String(timeVal).split(':');
        const h = parseInt(parts[0], 10);
        if (isNaN(h)) return '';
        if (h >= 0 && h < 5) return 'Dini Hari (AM)';
        if (h >= 5 && h < 11) return 'Pagi (AM)';
        if (h >= 11 && h < 15) return 'Siang (PM)';
        if (h >= 15 && h < 18) return 'Sore (PM)';
        return 'Malam (PM)';
    }

    function bindTimeBadge(inpId, badgeId) {
        const inp = document.getElementById(inpId);
        const badge = document.getElementById(badgeId);
        if (!inp || !badge) return;
        const update = () => { badge.textContent = getTimePeriodLabel(inp.value); };
        inp.addEventListener('input', update);
        inp.addEventListener('change', update);
        update();
    }
    bindTimeBadge('rentalTimeInput', 'rentalTimePeriodBadge');
    bindTimeBadge('rescheduleTimeInput', 'rescheduleTimePeriodBadge');

    // Filter Kendaraan berdasarkan Kategori Kendaraan & Preview Card
    const catSelect = document.getElementById('categoryVehicleSelect');
    const vehSelect = document.getElementById('vehicleSelect');
    const vehPreview = document.getElementById('vehicleDetailPreview');
    const vehPreviewPlate = document.getElementById('vehPreviewPlate')?.querySelector('span');
    const vehPreviewModel = document.getElementById('vehPreviewModel');
    const vehPreviewCategory = document.getElementById('vehPreviewCategory');
    const vehPreviewSpecs = document.getElementById('vehPreviewSpecs');

    const getVehicleMeta = (vId) => {
        if (!vId || !vehSelect) return null;
        if (vehSelect.tomselect && vehSelect.tomselect.options[vId]) {
            return vehSelect.tomselect.options[vId];
        }
        const opt = vehSelect.querySelector('option[value="' + String(vId).replace(/"/g, '\\"') + '"]');
        if (!opt) return null;
        const d = opt.dataset || {};
        return {
            id: vId,
            plate: d.plate || '',
            brand: d.brand || '',
            model: d.model || '',
            category_name: d.categoryName || '',
            color: d.color || '',
            seat: d.seat ? parseInt(d.seat, 10) || 0 : 0,
            category: d.category || ''
        };
    };

    const primPaxDetailSel  = document.getElementById('primaryPassengerSelect');
    const extraPaxDetailSel = document.getElementById('extraPassengersSelect');
    const seatWarnEl        = document.getElementById('seatCapacityWarning');
    function updateRentalDetailSeatWarn() {
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, primPaxDetailSel, extraPaxDetailSel, seatWarnEl);
        }
    }

    function refreshVehiclePreview() {
        if (!vehSelect || !vehPreview) return;
        const vId = vehSelect.tomselect ? String(vehSelect.tomselect.getValue() || '') : String(vehSelect.value || '');
        const vData = getVehicleMeta(vId);
        if (vData && vData.plate) {
            vehPreview.style.display = '';
            if (vehPreviewPlate) vehPreviewPlate.textContent = vData.plate;
            if (vehPreviewModel) vehPreviewModel.textContent = ((vData.brand || '') + ' ' + (vData.model || '')).trim();
            if (vehPreviewCategory) vehPreviewCategory.textContent = vData.category_name || 'Armada';
            const specs = [vData.color, vData.seat > 0 ? (vData.seat + ' Kursi') : ''].filter(Boolean).join(' • ');
            if (vehPreviewSpecs) vehPreviewSpecs.textContent = specs;
        } else {
            vehPreview.style.display = 'none';
        }
        updateRentalDetailSeatWarn();
    }

    if (vehSelect) {
        bindTsChange(vehSelect, (vId) => {
            refreshVehiclePreview();
            const vData = getVehicleMeta(String(vId || ''));
            const curCat = catSelect ? (catSelect.tomselect ? String(catSelect.tomselect.getValue() || '') : String(catSelect.value || '')) : '';
            if (vData && vData.category && catSelect && !curCat) {
                setTsValueSilent(catSelect, String(vData.category));
            }
        });
        refreshVehiclePreview();
    }

    if (catSelect && vehSelect) {
        bindTsChange(catSelect, async (catId) => {
            const curVal = vehSelect.tomselect ? String(vehSelect.tomselect.getValue() || '') : String(vehSelect.value || '');
            const curOpt = getVehicleMeta(curVal);
            const keepVal = (curVal && curOpt && (!catId || String(curOpt.category || '') === catId)) ? curVal : '';
            try {
                const u = `${appUrl}/pages/vehicles/options.php?company_id=<?= (int) $b['company_id'] ?>&limit=50`
                        + (catId ? `&category_id=${encodeURIComponent(catId)}` : '');
                const res = await fetch(u);
                const data = await res.json();
                if (keepVal && curOpt && !data.some(x => String(x.id) === keepVal)) {
                    data.unshift(curOpt);
                }
                setTsOptions(vehSelect, data, keepVal, '-- Pilih Kendaraan --');
            } catch (e) {
                console.error('Gagal memuat kendaraan:', e);
            }
            refreshVehiclePreview();
        });
    }

    // Cascading Provinsi -> Kota/Kab -> Kecamatan (Tom Select + Remote Search)
    const updateSelectOptions = window.setTsOptions;
    const onSelectChange = window.bindTsChange;

    async function reloadCityOptions(provVal, cityEl, distEl, currentCityVal) {
        if (!cityEl) return;
        if (distEl) setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
        try {
            const qs = provVal ? `limit=100&province_id=${encodeURIComponent(provVal)}` : 'limit=50&all=1';
            const res = await fetch(`${appUrl}/pages/masters/cities/options.php?${qs}`);
            const data = await res.json();
            setTsOptions(cityEl, data, currentCityVal || '', '-- Pilih Kota/Kab --');
        } catch (e) {
            console.error('Gagal memuat kota:', e);
        }
    }

    async function reloadDistrictOptions(cityVal, distEl, currentDistVal) {
        if (!distEl) return;
        if (!cityVal) {
            setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
            return;
        }
        try {
            const res = await fetch(`${appUrl}/pages/masters/districts/options.php?limit=100&city_id=${encodeURIComponent(cityVal)}`);
            const data = await res.json();
            setTsOptions(distEl, data, currentDistVal || '', '-- Pilih Kecamatan --');
        } catch (e) {
            console.error('Gagal memuat kecamatan:', e);
        }
    }

    function bindProvinceCityDistrict(provId, cityId, distId) {
        const pSel = document.getElementById(provId);
        const cSel = document.getElementById(cityId);
        const dSel = document.getElementById(distId);
        if (!pSel || !cSel || !dSel) return;

        bindTsChange(pSel, function (val) {
            reloadCityOptions(val, cSel, dSel, '');
        });

        bindTsChange(cSel, function (val) {
            const opt = cSel.tomselect ? cSel.tomselect.options[String(val)] : null;
            const curProv = pSel.tomselect ? String(pSel.tomselect.getValue() || '') : String(pSel.value || '');
            if (!curProv && opt && opt.province_id) {
                setTsValueSilent(pSel, String(opt.province_id));
            }
            reloadDistrictOptions(val, dSel, '');
        });
    }

    bindProvinceCityDistrict('pickupProvince', 'pickupCity', 'pickupDistrict');
    bindProvinceCityDistrict('deliveryProvince', 'deliveryCity', 'deliveryDistrict');

    // Hindari pelanggan utama terpilih ganda pada penumpang tambahan (2 arah) + peringatan kapasitas kursi
    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(primPaxDetailSel, extraPaxDetailSel, updateRentalDetailSeatWarn);
    }
    updateRentalDetailSeatWarn();

    // Quick Add Pelanggan via Modal
    const qpForm  = document.getElementById('quickPassengerForm');
    const qpError = document.getElementById('qpError');
    const qpBtn   = document.getElementById('qpSubmitBtn');

    if (qpForm) {
        qpForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            qpError.classList.add('d-none');
            qpBtn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfTok);
                fd.append('name', document.getElementById('qpName').value.trim());
                fd.append('phone_number', document.getElementById('qpPhone').value.trim());

                const res = await fetch(`${appUrl}/pages/travel/passengers/quick_create.php`, {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (!res.ok || !json.ok) {
                    throw new Error((json.error && json.error.message) || json.error || 'Gagal menyimpan pelanggan.');
                }

                const p = json.data;
                const label = p.phone_number ? `${p.name} - ${p.phone_number}` : p.name;
                const primarySel = document.getElementById('primaryPassengerSelect');
                const extraSel   = document.getElementById('extraPassengersSelect');

                if (extraSel) {
                    if (extraSel.tomselect) {
                        extraSel.tomselect.addOption({ value: String(p.id), text: label });
                        extraSel.tomselect.removeItem(String(p.id), true);
                        if (window.syncTsLastVal) window.syncTsLastVal(extraSel);
                    } else {
                        extraSel.add(new Option(label, String(p.id), false, false));
                    }
                }
                if (primarySel) {
                    if (primarySel.tomselect) {
                        primarySel.tomselect.addOption({ value: String(p.id), text: label });
                        primarySel.tomselect.setValue(String(p.id));
                        if (window.syncTsLastVal) window.syncTsLastVal(primarySel);
                    } else {
                        primarySel.add(new Option(label, String(p.id), true, true));
                    }
                }
                updateRentalDetailSeatWarn();

                qpForm.reset();
                const modalEl = document.getElementById('quickPassengerModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            } catch (err) {
                qpError.textContent = err.message;
                qpError.classList.remove('d-none');
            } finally {
                qpBtn.disabled = false;
            }
        });
    }

    // Grid Pembayaran Sewa (Lampiran | Tipe Pembayaran | Metode Pembayaran | Tanggal | Nominal)
    const payTopDisp = document.getElementById('pay_total_top_display');
    const paySubDisp = document.getElementById('pay_subtotal_display');
    const payTbody   = document.getElementById('payTableBody');
    const payCountEl = document.getElementById('payRecordCount');
    const payOverAlert = document.getElementById('payOverpayAlert');
    const payOverMsg   = document.getElementById('payOverpayMsg');
    const qrisRemainingDisp  = document.getElementById('qrisRemainingDisplay');
    const todayIso     = <?= json_encode(date('Y-m-d')) ?>;
    const qrisTaxPct   = <?= json_encode((float) ($invoice['tax_percent'] ?? 0)) ?>;
    const qrisOrigBookingPrice = <?= json_encode((float) $b['total_price']) ?>;
    let qrisInvTotal   = <?= json_encode((float) $qrisInvTotal) ?>;
    let qrisServerPaid = <?= json_encode((float) $qrisPaidTotal) ?>;

    function getExpectedInvoiceTotal() {
        if (priceVal) {
            const uiPrice = parseRupiah(priceVal.value || '0');
            if (Math.abs(uiPrice - Math.round(qrisOrigBookingPrice)) > 1) {
                return uiPrice + Math.round(uiPrice * qrisTaxPct / 100);
            }
        }
        return Math.round(qrisInvTotal);
    }

    function getCurrentPaySum() {
        let s = 0;
        const rows = payTbody ? payTbody.querySelectorAll('tr.pay-row') : [];
        rows.forEach((tr) => {
            const hid = tr.querySelector('.pay-amt-value');
            s += parseRupiah(hid ? hid.value : '0');
        });
        return s;
    }

    function getPayValidationState(currentPaySum) {
        const paySum = currentPaySum !== undefined ? currentPaySum : getCurrentPaySum();
        const targetTotal = getExpectedInvoiceTotal();
        const exceeded = paySum > targetTotal + 0.001;
        return { paySum, targetTotal, exceeded };
    }

    function updateDynamicInvoiceSisa(currentPaySum) {
        const st = getPayValidationState(currentPaySum);
        const sisa = Math.round(st.targetTotal - st.paySum);
        if (qrisRemainingDisp) {
            if (st.exceeded) {
                qrisRemainingDisp.textContent = 'Melebihi Rp ' + formatRupiah(Math.abs(sisa));
                qrisRemainingDisp.className = 'text-danger fw-bold';
            } else {
                qrisRemainingDisp.textContent = sisa > 0 ? ('Rp ' + formatRupiah(sisa)) : 'Lunas (Rp 0)';
                qrisRemainingDisp.className = sisa > 0 ? 'text-danger' : 'text-success';
            }
        }
        if (payOverAlert && payOverMsg) {
            if (st.exceeded) {
                payOverMsg.textContent = 'Total pembayaran (Rp ' + formatRupiah(st.paySum) + ') tidak boleh melebihi total tagihan (Rp ' + formatRupiah(st.targetTotal) + ').';
                payOverAlert.classList.remove('d-none');
            } else {
                payOverAlert.classList.add('d-none');
                payOverMsg.textContent = '';
            }
        }
        [payTopDisp, paySubDisp].forEach((el) => {
            if (!el) return;
            el.classList.toggle('is-invalid', st.exceeded);
            el.classList.toggle('text-danger', st.exceeded);
        });
    }

    function recalcPayTotals() {
        let paySum = 0;
        const rows = payTbody ? payTbody.querySelectorAll('tr.pay-row') : [];
        rows.forEach((tr, idx) => {
            const numCell = tr.querySelector('.pay-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            const hid = tr.querySelector('.pay-amt-value');
            paySum += parseRupiah(hid ? hid.value : '0');
        });
        if (payTopDisp) payTopDisp.value = formatRupiah(paySum);
        if (paySubDisp) paySubDisp.value = formatRupiah(paySum);
        if (payCountEl) payCountEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
        updateDynamicInvoiceSisa(paySum);
    }

    function bindPayRow(tr) {
        const fileInp = tr.querySelector('.pay-file-input');
        const fileLbl = tr.querySelector('.pay-file-label');
        const amtDisp = tr.querySelector('.pay-amt-display');
        const amtVal  = tr.querySelector('.pay-amt-value');
        const delBtn  = tr.querySelector('.btn-remove-pay');

        if (fileInp && fileLbl) {
            fileInp.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    fileLbl.textContent = this.files[0].name;
                    fileLbl.classList.remove('text-muted');
                    fileLbl.classList.add('text-primary', 'fw-semibold');
                }
            });
        }
        if (amtDisp && amtVal) {
            attachRupiahMask(amtDisp, amtVal, () => {
                recalcPayTotals();
            });
        }
        if (delBtn) {
            delBtn.addEventListener('click', () => {
                tr.remove();
                recalcPayTotals();
            });
        }
    }

    function addPayRow() {
        if (!payTbody) return;
        const tr = document.createElement('tr');
        tr.className = 'pay-row';
        tr.innerHTML = `
            <td class="text-center text-muted pay-row-num">1</td>
            <td>
                <input type="hidden" name="pay_id[]" form="rentalDetailForm" value="0">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small pay-file-label">No image data</span>
                    <label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Pilih bukti pembayaran">
                        <i class="bi bi-paperclip"></i>
                        <input type="file" name="pay_proof[]" form="rentalDetailForm" class="d-none pay-file-input" accept="image/*,application/pdf">
                    </label>
                </div>
            </td>
            <td>
                <select class="form-select form-select-sm pay-type-select" name="pay_type[]" form="rentalDetailForm">
                    <option value="Down Payment (DP)">Down Payment (DP)</option>
                    <option value="Pelunasan">Pelunasan</option>
                    <option value="Pembayaran Penuh (Full)">Pembayaran Penuh (Full)</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm pay-method-select" name="pay_method[]" form="rentalDetailForm">
                    <option value="Transfer">Transfer</option>
                    <option value="Tunai">Tunai</option>
                    <option value="QRIS">QRIS</option>
                    <option value="EDC / Kartu">EDC / Kartu</option>
                </select>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bg-white flatpickr-dynamic" name="pay_date[]" form="rentalDetailForm" value="${todayIso}" placeholder="dd-MMM-yyyy">
            </td>
            <td>
                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="0">
                <input type="hidden" name="pay_amount[]" class="pay-amt-value" form="rentalDetailForm" value="0">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        payTbody.appendChild(tr);
        const newDateInp = tr.querySelector('.flatpickr-dynamic');
        if (newDateInp) initFlatpickr(newDateInp);
        bindPayRow(tr);
        recalcPayTotals();
    }

    if (payTbody) {
        payTbody.querySelectorAll('tr.pay-row').forEach(bindPayRow);
    }
    ['btnAddPayRow', 'btnFooterAddPay'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', addPayRow);
    });
    const btnFooterDelPay = document.getElementById('btnFooterDelPay');
    if (btnFooterDelPay && payTbody) {
        btnFooterDelPay.addEventListener('click', () => {
            const rows = payTbody.querySelectorAll('tr.pay-row');
            if (rows.length > 0) {
                const last = rows[rows.length - 1];
                if (!last.querySelector('.bi-lock-fill')) {
                    last.remove();
                    recalcPayTotals();
                }
            }
        });
    }
    recalcPayTotals();

    // ==================== QRIS OTOMATIS & AUTO-APPEND ROW ====================
    const qrisBookingId   = <?= (int) $id ?>;
    const qrisCanManage   = <?= $canManage ? 'true' : 'false' ?>;
    const qrisBookStat    = <?= json_encode((string) $status) ?>;
    let qrisHasPending    = <?= $hasPendingQris ? 'true' : 'false' ?>;
    let qrisCurrentOrderId   = '';
    let qrisCurrentPayWebUrl = '';
    let qrisModalPollTimer   = null;
    let qrisBgPollTimer      = null;
    let qrisCountdownTimer   = null;
    let qrisPollInFlight     = false;

    const btnPayQrisAuto     = document.getElementById('btnPayQrisAuto');
    const qrisModalEl        = document.getElementById('qrisAutoModal');
    const qrisStepForm       = document.getElementById('qrisStepForm');
    const qrisStepShow       = document.getElementById('qrisStepShow');
    const qrisCreateErr      = document.getElementById('qrisCreateErr');
    const qrisUnsavedWarn    = document.getElementById('qrisUnsavedPriceWarn');
    const qrisFormSisaText   = document.getElementById('qrisFormSisaText');
    const qrisPayTypeSel     = document.getElementById('qrisPayType');
    const qrisProvSel        = document.getElementById('qrisChannelProvider');
    const qrisAmtDisp        = document.getElementById('qrisAmountDisplay');
    const qrisAmtVal         = document.getElementById('qrisAmountValue');
    const btnGenQris         = document.getElementById('btnGenerateQris');
    const qrisLiveSyncBadge  = document.getElementById('qrisLiveSyncBadge');
    const qrisNewRowBanner   = document.getElementById('qrisAutoNewRowBanner');
    const qrisNewRowMsg      = document.getElementById('qrisAutoNewRowMsg');

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c] || c));
    }

    function appendAutoQrisRow(p) {
        if (!payTbody || !p || !p.id) return false;
        if (payTbody.querySelector(`tr.pay-row[data-payment-id="${Number(p.id)}"]`)) {
            return false;
        }
        const ptOpts = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'];
        if (p.pay_type && !ptOpts.includes(p.pay_type)) ptOpts.push(p.pay_type);
        const pmOpts = ['Transfer', 'Tunai', 'QRIS', 'EDC / Kartu'];
        if (p.pay_method && !pmOpts.includes(p.pay_method)) pmOpts.push(p.pay_method);

        const ptOptionsHtml = ptOpts.map((opt) =>
            `<option value="${escHtml(opt)}" ${opt === p.pay_type ? 'selected' : ''}>${escHtml(opt)}</option>`
        ).join('');
        const pmOptionsHtml = pmOpts.map((opt) =>
            `<option value="${escHtml(opt)}" ${opt === (p.pay_method || 'QRIS') ? 'selected' : ''}>${escHtml(opt)}</option>`
        ).join('');

        const tr = document.createElement('tr');
        tr.className = 'pay-row table-success';
        tr.setAttribute('data-payment-id', String(Number(p.id)));
        tr.innerHTML = `
            <td class="text-center text-muted pay-row-num">1</td>
            <td>
                <input type="hidden" name="pay_id[]" form="rentalDetailForm" value="${Number(p.id)}">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success-subtle text-success border"><i class="bi bi-qr-code me-1"></i>Verified QRIS</span>
                    ${qrisCanManage ? '<input type="file" name="pay_proof[]" form="rentalDetailForm" class="d-none" tabindex="-1">' : ''}
                </div>
            </td>
            <td>
                <select class="form-select form-select-sm pay-type-select" name="pay_type[]" form="rentalDetailForm" disabled>${ptOptionsHtml}</select>
                <input type="hidden" name="pay_type[]" form="rentalDetailForm" value="${escHtml(p.pay_type || 'Pelunasan')}">
            </td>
            <td>
                <select class="form-select form-select-sm pay-method-select" name="pay_method[]" form="rentalDetailForm" disabled>${pmOptionsHtml}</select>
                <input type="hidden" name="pay_method[]" form="rentalDetailForm" value="${escHtml(p.pay_method || 'QRIS')}">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm bg-light" value="${escHtml(p.pay_date_fmt || p.pay_date || todayIso)}" disabled>
                <input type="hidden" name="pay_date[]" form="rentalDetailForm" value="${escHtml(p.pay_date || todayIso)}">
            </td>
            <td>
                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="${escHtml(p.amount_fmt || formatRupiah(p.amount || 0))}" readonly>
                <input type="hidden" name="pay_amount[]" class="pay-amt-value" form="rentalDetailForm" value="${Number(p.amount || 0)}">
            </td>
            ${qrisCanManage ? '<td class="text-center"><i class="bi bi-lock-fill text-muted small" title="Pembayaran terverifikasi otomatis oleh QRIS"></i></td>' : ''}
        `;
        payTbody.appendChild(tr);
        recalcPayTotals();
        setTimeout(() => tr.classList.remove('table-success'), 4000);
        return true;
    }

    function updateQrisSummaryUi(data) {
        if (typeof data.invoice_total === 'number') qrisInvTotal = data.invoice_total;
        if (typeof data.paid_total === 'number') qrisServerPaid = data.paid_total;
        if (typeof data.has_pending === 'boolean') qrisHasPending = data.has_pending;

        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (qrisRemainingDisp) {
            qrisRemainingDisp.textContent = rem > 0 ? ('Rp ' + formatRupiah(rem)) : 'Lunas (Rp 0)';
            qrisRemainingDisp.className = rem > 0 ? 'text-danger' : 'text-success';
        }
        if (qrisFormSisaText) {
            qrisFormSisaText.textContent = 'Rp ' + formatRupiah(rem);
        }
        if (btnPayQrisAuto) {
            const curSt = data.booking_status || qrisBookStat;
            btnPayQrisAuto.disabled = (curSt === 'cancelled' || rem <= 0);
        }
        if (data.booking_status_badge) {
            const wrap = document.getElementById('bookingStatusBadgeWrap');
            if (wrap) wrap.innerHTML = data.booking_status_badge;
        }
        if (qrisLiveSyncBadge) {
            const tabPayPane = document.getElementById('tab-payment');
            const tabActive = tabPayPane && tabPayPane.classList.contains('active');
            if (qrisHasPending || tabActive) {
                qrisLiveSyncBadge.classList.remove('d-none');
            } else {
                qrisLiveSyncBadge.classList.add('d-none');
            }
        }
    }

    function stopQrisModalTimers() {
        if (qrisModalPollTimer) { clearInterval(qrisModalPollTimer); qrisModalPollTimer = null; }
        if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
    }

    function startQrisCountdown(expiredTs) {
        if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
        const cdEl = document.getElementById('qrisShowCountdown');
        if (!cdEl) return;
        if (!expiredTs) { cdEl.textContent = '±15 menit'; return; }
        const tick = () => {
            const left = Math.max(0, expiredTs - Math.floor(Date.now() / 1000));
            if (left <= 0) {
                cdEl.textContent = 'Kedaluwarsa';
                clearInterval(qrisCountdownTimer);
                qrisCountdownTimer = null;
                return;
            }
            const m = Math.floor(left / 60);
            const s = left % 60;
            cdEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        };
        tick();
        qrisCountdownTimer = setInterval(tick, 1000);
    }

    async function pollQrisPayments(targetOrderId = '') {
        if (qrisPollInFlight) return null;
        qrisPollInFlight = true;
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrfTok);
            fd.append('act', 'poll');
            fd.append('booking_id', String(qrisBookingId));
            if (targetOrderId) fd.append('order_id', targetOrderId);

            const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) return data;

            let addedCount = 0;
            let addedSum = 0;
            if (Array.isArray(data.payments)) {
                data.payments.forEach((p) => {
                    if (p && p.is_auto_qris && Number(p.id) > 0) {
                        if (appendAutoQrisRow(p)) {
                            addedCount++;
                            addedSum += Number(p.amount || 0);
                        }
                    }
                });
            }

            if (addedCount > 0 && qrisNewRowBanner && qrisNewRowMsg) {
                qrisNewRowMsg.textContent = `Pembayaran QRIS baru sebesar Rp ${formatRupiah(addedSum)} telah masuk dan ditambahkan otomatis ke tabel Pembayaran Sewa.`;
                qrisNewRowBanner.classList.remove('d-none');
                qrisNewRowBanner.classList.add('d-flex');
            }

            updateQrisSummaryUi(data);

            // Update modal view bila sedang memantau order yang sama
            if (targetOrderId && qrisCurrentOrderId === targetOrderId) {
                const stBadge = document.getElementById('qrisShowStatusBadge');
                if (data.order_status === 'PAID') {
                    stopQrisModalTimers();
                    if (stBadge) {
                        stBadge.className = 'badge bg-success text-white';
                        stBadge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Lunas / Terverifikasi';
                    }
                    setTimeout(() => {
                        if (qrisModalEl && typeof bootstrap !== 'undefined') {
                            const inst = bootstrap.Modal.getInstance(qrisModalEl);
                            if (inst) inst.hide();
                        }
                    }, 1200);
                } else if (data.order_status === 'EXPIRED') {
                    stopQrisModalTimers();
                    if (stBadge) {
                        stBadge.className = 'badge bg-danger text-white';
                        stBadge.innerHTML = '<i class="bi bi-x-circle me-1"></i>Kedaluwarsa';
                    }
                }
            }
            return data;
        } catch (err) {
            return null;
        } finally {
            qrisPollInFlight = false;
        }
    }

    function refreshBgQrisPoller() {
        const tabPayPane = document.getElementById('tab-payment');
        const tabActive = tabPayPane && tabPayPane.classList.contains('active');
        const shouldPoll = !document.hidden && (qrisHasPending || tabActive);

        if (shouldPoll && !qrisBgPollTimer) {
            qrisBgPollTimer = setInterval(() => {
                if (document.hidden) return;
                pollQrisPayments('');
            }, 6000);
        } else if (!shouldPoll && qrisBgPollTimer) {
            clearInterval(qrisBgPollTimer);
            qrisBgPollTimer = null;
        }
        if (qrisLiveSyncBadge) {
            if (qrisHasPending || tabActive) qrisLiveSyncBadge.classList.remove('d-none');
            else qrisLiveSyncBadge.classList.add('d-none');
        }
    }

    const tabPaymentBtn = document.getElementById('tab-payment-btn');
    if (tabPaymentBtn) {
        tabPaymentBtn.addEventListener('shown.bs.tab', () => {
            pollQrisPayments('');
            refreshBgQrisPoller();
        });
    }
    document.querySelectorAll('#rentalDetailTabs [data-bs-toggle="tab"]').forEach((btn) => {
        if (btn.id !== 'tab-payment-btn') {
            btn.addEventListener('shown.bs.tab', refreshBgQrisPoller);
        }
    });
    document.addEventListener('visibilitychange', refreshBgQrisPoller);

    if (qrisAmtDisp && qrisAmtVal) {
        attachRupiahMask(qrisAmtDisp, qrisAmtVal);
    }

    document.querySelectorAll('.qris-preset-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const preset = btn.getAttribute('data-qris-preset');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            let val = rem;
            if (preset === 'dp50') {
                val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.5)));
                if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
            } else if (preset === 'dp30') {
                val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.3)));
                if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
            } else if (qrisPayTypeSel) {
                qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
            }
            if (qrisAmtDisp) qrisAmtDisp.value = formatRupiah(val);
            if (qrisAmtVal) qrisAmtVal.value = String(val);
        });
    });

    if (btnPayQrisAuto && qrisModalEl) {
        btnPayQrisAuto.addEventListener('click', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            qrisCurrentPayWebUrl = '';
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }

            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisFormSisaText) qrisFormSisaText.textContent = 'Rp ' + formatRupiah(rem);
            if (qrisPayTypeSel) {
                qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
            }
            if (qrisAmtDisp) qrisAmtDisp.value = formatRupiah(rem);
            if (qrisAmtVal) qrisAmtVal.value = String(rem);

            if (qrisUnsavedWarn) {
                if (Math.abs(getExpectedInvoiceTotal() - Math.round(qrisInvTotal)) > 1) {
                    qrisUnsavedWarn.classList.remove('d-none');
                } else {
                    qrisUnsavedWarn.classList.add('d-none');
                }
            }

            if (qrisStepForm) qrisStepForm.classList.remove('d-none');
            if (qrisStepShow) qrisStepShow.classList.add('d-none');
            if (typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(qrisModalEl).show();
            }
        });

        qrisModalEl.addEventListener('hidden.bs.modal', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            refreshBgQrisPoller();
        });
    }

    if (btnGenQris) {
        btnGenQris.addEventListener('click', async () => {
            const amt = parseRupiah(qrisAmtVal ? qrisAmtVal.value : (qrisAmtDisp ? qrisAmtDisp.value : '0'));
            const pt  = qrisPayTypeSel ? qrisPayTypeSel.value : 'Pelunasan';
            const prov = qrisProvSel ? qrisProvSel.value : '';

            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
            if (rem <= 0) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = 'Tagihan invoice ini sudah lunas (sisa Rp 0).';
                    qrisCreateErr.classList.remove('d-none');
                }
                return;
            }
            if (amt <= 0) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = 'Masukkan nominal tagihan QRIS lebih dari Rp 0.';
                    qrisCreateErr.classList.remove('d-none');
                }
                return;
            }
            if (amt > rem) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = 'Nominal QRIS (Rp ' + formatRupiah(amt) + ') tidak boleh melebihi sisa tagihan (Rp ' + formatRupiah(rem) + ').';
                    qrisCreateErr.classList.remove('d-none');
                }
                return;
            }

            btnGenQris.disabled = true;
            const origHtml = btnGenQris.innerHTML;
            btnGenQris.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membuat QRIS...';
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfTok);
                fd.append('act', 'create');
                fd.append('booking_id', String(qrisBookingId));
                fd.append('pay_type', pt);
                fd.append('amount', String(amt));
                if (prov) fd.append('provider', prov);

                const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!res.ok || !data || !data.ok) {
                    throw new Error((data && data.error) || 'Gagal membuat transaksi QRIS.');
                }

                qrisCurrentOrderId   = data.order_id || '';
                qrisCurrentPayWebUrl = data.pay_web_url || '';
                qrisHasPending       = true;

                const imgEl = document.getElementById('qrisShowImg');
                if (imgEl) {
                    const primarySrc  = data.qris_image_url || data.fallback_qr_url || '';
                    const fallbackSrc = data.fallback_qr_url || '';
                    imgEl.onerror = function () {
                        if (fallbackSrc && this.src !== fallbackSrc) this.src = fallbackSrc;
                    };
                    imgEl.src = primarySrc;
                }
                const chEl = document.getElementById('qrisShowChannelBadge');
                if (chEl) chEl.textContent = data.channel_name || 'QRIS';
                const oidEl = document.getElementById('qrisShowOrderId');
                if (oidEl) oidEl.textContent = qrisCurrentOrderId;
                const ptEl = document.getElementById('qrisShowPayType');
                if (ptEl) ptEl.textContent = data.pay_type || pt;
                const billEl = document.getElementById('qrisShowBill');
                if (billEl) billEl.textContent = 'Rp ' + formatRupiah(Math.round(Number(data.bill_amount || amt)));

                const feeRow = document.getElementById('qrisShowFeeRow');
                const feeEl  = document.getElementById('qrisShowFee');
                const feeNum = Math.round(Number(data.fee || 0));
                if (feeRow && feeEl) {
                    if (feeNum > 0) {
                        feeEl.textContent = 'Rp ' + formatRupiah(feeNum);
                        feeRow.classList.remove('d-none');
                    } else {
                        feeRow.classList.add('d-none');
                    }
                }
                const uniqRow = document.getElementById('qrisShowUniqueRow');
                const uniqEl  = document.getElementById('qrisShowUnique');
                const uniqNum = Math.round(Number(data.unique_code || 0));
                if (uniqRow && uniqEl) {
                    if (uniqNum > 0) {
                        uniqEl.textContent = '+Rp ' + formatRupiah(uniqNum);
                        uniqRow.classList.remove('d-none');
                    } else {
                        uniqRow.classList.add('d-none');
                    }
                }
                const totEl = document.getElementById('qrisShowTotal');
                if (totEl) totEl.textContent = 'Rp ' + formatRupiah(Math.round(Number(data.total_amount || amt)));

                const stBadge = document.getElementById('qrisShowStatusBadge');
                if (stBadge) {
                    stBadge.className = 'badge bg-warning-subtle text-warning-emphasis border';
                    stBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;" role="status"></span>Menunggu Pembayaran';
                }

                if (qrisStepForm) qrisStepForm.classList.add('d-none');
                if (qrisStepShow) qrisStepShow.classList.remove('d-none');

                startQrisCountdown(data.expired_ts || null);
                qrisModalPollTimer = setInterval(() => {
                    if (qrisCurrentOrderId) pollQrisPayments(qrisCurrentOrderId);
                }, 4000);
                refreshBgQrisPoller();
            } catch (err) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = err.message || 'Terjadi kesalahan saat membuat QRIS.';
                    qrisCreateErr.classList.remove('d-none');
                }
            } finally {
                btnGenQris.disabled = false;
                btnGenQris.innerHTML = origHtml;
            }
        });
    }

    const btnQrisBack = document.getElementById('btnQrisBackToForm');
    if (btnQrisBack) {
        btnQrisBack.addEventListener('click', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            if (qrisStepShow) qrisStepShow.classList.add('d-none');
            if (qrisStepForm) qrisStepForm.classList.remove('d-none');
        });
    }

    const btnCheckQrisNow = document.getElementById('btnCheckQrisNow');
    if (btnCheckQrisNow) {
        btnCheckQrisNow.addEventListener('click', async () => {
            if (!qrisCurrentOrderId) return;
            btnCheckQrisNow.disabled = true;
            await pollQrisPayments(qrisCurrentOrderId);
            btnCheckQrisNow.disabled = false;
        });
    }

    const btnCopyQrisLink = document.getElementById('btnCopyQrisLink');
    if (btnCopyQrisLink) {
        btnCopyQrisLink.addEventListener('click', async () => {
            if (!qrisCurrentPayWebUrl) return;
            const orig = btnCopyQrisLink.innerHTML;
            try {
                await navigator.clipboard.writeText(qrisCurrentPayWebUrl);
                btnCopyQrisLink.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin';
                setTimeout(() => { btnCopyQrisLink.innerHTML = orig; }, 1800);
            } catch (e) {
                window.prompt('Salin tautan pembayaran QRIS berikut:', qrisCurrentPayWebUrl);
            }
        });
    }

    if (qrisHasPending) {
        refreshBgQrisPoller();
    }

    // Intersep submit form rentalDetailForm untuk validasi pembayaran & HTML5 otomatis lintas tab
    const detailForm = document.getElementById('rentalDetailForm');
    if (detailForm) {
        recalcPayTotals();
        detailForm.addEventListener('submit', function (e) {
            if (visNote && hidNote) {
                hidNote.value = visNote.value;
            }

            const paySt = getPayValidationState();
            if (paySt.exceeded) {
                e.preventDefault();
                recalcPayTotals();
                const payTabBtn = document.querySelector('[data-bs-target="#tab-payment"]');
                if (payTabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                    bootstrap.Tab.getOrCreateInstance(payTabBtn).show();
                }
                setTimeout(() => {
                    let targetInp = null;
                    if (payTbody) {
                        payTbody.querySelectorAll('tr.pay-row').forEach((tr) => {
                            if (targetInp) return;
                            const disp = tr.querySelector('.pay-amt-display');
                            const val  = parseRupiah((tr.querySelector('.pay-amt-value') || {}).value || '0');
                            if (disp && !disp.readOnly && val > 0) targetInp = disp;
                        });
                    }
                    if (targetInp) targetInp.focus();
                    if (payOverAlert) payOverAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 150);
                return false;
            }

            if (!this.checkValidity()) {
                e.preventDefault();
                const firstInvalid = this.querySelector(':invalid');
                if (firstInvalid) {
                    const tabPane = firstInvalid.closest('.tab-pane');
                    if (tabPane) {
                        const tabId = tabPane.getAttribute('id');
                        const tabBtn = document.querySelector(`[data-bs-target="#${tabId}"]`);
                        if (tabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                            const tab = bootstrap.Tab.getOrCreateInstance(tabBtn);
                            tab.show();
                        }
                    }
                    setTimeout(() => {
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
