<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/dispatch_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/azure_blob.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b   = booking_find('travel', $id);
if (!$b) {
    flash('error', 'Booking tidak ditemukan.');
    redirect('pages/travel/bookings/index.php');
}
assert_company_access((int) $b['company_id']);
$booking_cid      = (int) $b['company_id'];
$b['company_id']  = $booking_cid;

$user      = $_SESSION['username'] ?? null;
$bid_type  = 'travel';
$canManage = can('travel.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengelola pemesanan travel.');
        redirect('pages/travel/bookings/detail.php?id=' . $id);
    }
    $act       = (string) ($_POST['act'] ?? '');
    $note      = trim((string) ($_POST['reason'] ?? ''));
    $targetTab = trim((string) ($_POST['active_tab'] ?? ''));
    try {
        if ($act === 'status') {
            $to = (string) ($_POST['to_status'] ?? '');
            booking_change_status('travel', $id, $booking_cid, $to, $note === '' ? 'Ubah status' : $note, $user);
            audit('booking.travel.status', "Booking #{$id} -> {$to}");
            flash('success', 'Status booking diperbarui.');
        } elseif ($act === 'cancel') {
            booking_change_status('travel', $id, $booking_cid, 'cancelled', $note === '' ? null : $note, $user);
            audit('booking.travel.cancel', "Booking #{$id}");
            flash('success', 'Booking dibatalkan.');
        } elseif ($act === 'reschedule') {
            $date = (string) ($_POST['date'] ?? '');
            $time = (string) ($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Tanggal/jam reschedule tidak valid.');
            }
            booking_reschedule('travel', $id, $booking_cid, $date, $time . ':00', $note === '' ? 'Reschedule' : $note, $user);
            audit('booking.travel.reschedule', "Booking #{$id} -> {$date} {$time}");
            flash('success', 'Booking dijadwalkan ulang.');
        } elseif ($act === 'driver') {
            $emp = (int) ($_POST['employee_id'] ?? 0);
            $veh = (int) ($_POST['vehicle_id'] ?? 0);
            booking_assign_driver('travel', $id, $booking_cid, $emp, $veh > 0 ? $veh : null, $user);
            audit('booking.travel.driver', "Booking #{$id} driver #{$emp}");
            flash('success', 'Driver ditugaskan.');
        } elseif ($act === 'pay_manual') {
            $curBkStatus = booking_current_status('travel', $id);
            if ($curBkStatus === 'cancelled') {
                throw new RuntimeException('Booking sudah dibatalkan — tidak bisa menerima pembayaran.');
            }
            $payAmt    = (float) parse_rupiah_input($_POST['amount'] ?? 0);
            $methodId  = (int) ($_POST['payment_method_id'] ?? 0);
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0);
            $payType   = trim((string) ($_POST['pay_type'] ?? 'Pelunasan'));
            $payNoteIn = trim((string) ($_POST['pay_note'] ?? ''));
            if (!in_array($payType, ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'], true)) {
                $payType = 'Pelunasan';
            }

            $stInvId = db()->prepare("SELECT id FROM invoices WHERE booking_type = 'travel' AND booking_id = ? AND company_id = ? LIMIT 1");
            $stInvId->bind_param('ii', $id, $booking_cid);
            $stInvId->execute();
            $invId = (int) ($stInvId->get_result()->fetch_column() ?: 0);
            $stInvId->close();

            if ($invId <= 0) {
                $invId = invoice_issue(
                    $booking_cid,
                    'travel',
                    $id,
                    !empty($b['branch_id']) ? (int) $b['branch_id'] : null,
                    (string) $b['document_number'],
                    (float) $b['total_price'],
                    $user
                );
            }

            $methodName = 'Transfer';
            if ($methodId > 0) {
                $stPm = db()->prepare('SELECT name FROM payment_methods WHERE id = ? AND is_active = 1 AND (company_id = ? OR company_id IS NULL) LIMIT 1');
                $stPm->bind_param('ii', $methodId, $booking_cid);
                $stPm->execute();
                $pmRow = $stPm->get_result()->fetch_column();
                $stPm->close();
                if ($pmRow) {
                    $methodName = (string) $pmRow;
                } else {
                    $methodId = 0;
                }
            }

            $proofPath = null;
            if (!empty($_FILES['proof_file']['tmp_name'])) {
                $proofPath = azure_blob_handle_upload(
                    'proof_file',
                    $booking_cid,
                    'finance/payment-proofs',
                    date('Y/m'),
                    'travel-' . $id,
                    true,
                    5
                );
            }

            $fullNote = $payType . ' | ' . $methodName . ($payNoteIn !== '' ? (' - ' . $payNoteIn) : '');
            $payRes   = invoice_add_payment(
                $invId,
                $booking_cid,
                $payAmt,
                $methodId > 0 ? $methodId : null,
                $fullNote,
                $user,
                false,
                $proofPath,
                $cashAccId > 0 ? $cashAccId : null
            );
            audit('booking.travel.pay_manual', "Booking #{$id} INV#{$invId} nominal {$payAmt} ({$payRes['status']})");
            flash('success', 'Pembayaran manual ' . rupiah($payAmt) . ' berhasil dicatat.');
        } elseif ($act === 'update_detail') {
            if (booking_current_status('travel', $id) === 'cancelled') {
                throw new RuntimeException('Booking yang sudah dibatalkan tidak dapat diubah detailnya.');
            }
            $branch_id    = (int) ($_POST['branch_id'] ?? $b['branch_id']);
            $date_in      = trim((string) ($_POST['date'] ?? $b['date']));
            $time_raw     = trim((string) ($_POST['time'] ?? substr((string) $b['time'], 0, 5)));
            $time_in      = substr($time_raw, 0, 5);
            $passenger_id = (int) ($_POST['passenger_id'] ?? $b['passenger_id']);
            $cat_id       = (int) ($_POST['category_vehicle_id'] ?? 0) ?: null;
            $veh_id       = (int) ($_POST['vehicle_id'] ?? 0) ?: null;
            $total_price  = parse_rupiah_input($_POST['total_price'] ?? $b['total_price']);
            $p_prov       = (int) ($_POST['pickup_point_province_id'] ?? 0) ?: null;
            $p_city       = (int) ($_POST['pickup_point_city_id'] ?? 0) ?: null;
            $p_dist       = resolve_district_id($_POST['pickup_point_district_id'] ?? $b['pickup_point_district_id']);
            $p_addr       = trim((string) ($_POST['pickup_address'] ?? ($b['pickup_address'] ?? '')));
            $d_prov       = (int) ($_POST['delivery_point_province_id'] ?? 0) ?: null;
            $d_city       = (int) ($_POST['delivery_point_city_id'] ?? 0) ?: null;
            $d_dist       = resolve_district_id($_POST['delivery_point_district_id'] ?? $b['delivery_point_district_id']);
            $d_addr       = trim((string) ($_POST['delivery_address'] ?? ($b['delivery_address'] ?? '')));
            $b_note       = trim((string) ($_POST['note'] ?? ($b['note'] ?? '')));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_in)) {
                throw new InvalidArgumentException('Tanggal keberangkatan tidak valid.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time_in)) {
                throw new InvalidArgumentException('Format jam keberangkatan tidak valid.');
            }
            if ($total_price < 0) {
                throw new InvalidArgumentException('Total harga tidak boleh negatif.');
            }
            if ($branch_id <= 0 || $passenger_id <= 0 || $p_dist <= 0 || $d_dist <= 0) {
                throw new InvalidArgumentException('Cabang, pelanggan utama, serta kecamatan penjemputan & pengantaran wajib dipilih.');
            }

            $db = db();
            $stBrChk = $db->prepare('SELECT 1 FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
            $stBrChk->bind_param('ii', $branch_id, $booking_cid);
            $stBrChk->execute();
            $okBr = (bool) $stBrChk->get_result()->fetch_row();
            $stBrChk->close();
            if (!$okBr) {
                throw new InvalidArgumentException('Cabang tidak valid untuk perusahaan booking ini.');
            }

            $stPaxChk = $db->prepare('SELECT 1 FROM passengers WHERE id = ? AND company_id = ? LIMIT 1');
            $stPaxChk->bind_param('ii', $passenger_id, $booking_cid);
            $stPaxChk->execute();
            $okPax = (bool) $stPaxChk->get_result()->fetch_row();
            $stPaxChk->close();
            if (!$okPax) {
                throw new InvalidArgumentException('Pelanggan utama tidak valid untuk perusahaan booking ini.');
            }

            $userCompanyIds = accessible_company_ids($booking_cid);
            if ($veh_id !== null && $veh_id > 0) {
                $compIn = implode(',', array_map('intval', $userCompanyIds));
                $stVehChk = $db->prepare("SELECT category_vehicle_id FROM vehicles WHERE id = ? AND company_id IN ({$compIn}) LIMIT 1");
                $stVehChk->bind_param('i', $veh_id);
                $stVehChk->execute();
                $vehRowChk = $stVehChk->get_result()->fetch_assoc();
                $stVehChk->close();
                if (!$vehRowChk) {
                    throw new InvalidArgumentException('Kendaraan tidak valid atau di luar akses perusahaan.');
                }
                if (($cat_id === null || $cat_id <= 0) && !empty($vehRowChk['category_vehicle_id'])) {
                    $cat_id = (int) $vehRowChk['category_vehicle_id'];
                }
            }

            $extra_pids_raw = array_values(array_unique(array_filter(
                array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
                static fn($v) => $v > 0 && $v !== $passenger_id
            )));
            $extra_pids = [];
            if ($extra_pids_raw) {
                $phExtra = implode(',', array_fill(0, count($extra_pids_raw), '?'));
                $stExChk = $db->prepare("SELECT id FROM passengers WHERE company_id = ? AND id IN ({$phExtra})");
                $stExChk->bind_param('i' . str_repeat('i', count($extra_pids_raw)), $booking_cid, ...$extra_pids_raw);
                $stExChk->execute();
                foreach ($stExChk->get_result()->fetch_all(MYSQLI_ASSOC) as $er) {
                    $extra_pids[] = (int) $er['id'];
                }
                $stExChk->close();
            }

            if ($p_info = resolve_district_info($p_dist)) {
                $p_city = $p_info['city_id'];
                $p_prov = $p_info['province_id'];
            }
            if ($d_info = resolve_district_info($d_dist)) {
                $d_city = $d_info['city_id'];
                $d_prov = $d_info['province_id'];
            }
            $time_sql = $time_in . ':00';

            $db->begin_transaction();
            try {
                $up = $db->prepare('UPDATE travel_ticket_bookings
                    SET branch_id = ?, date = ?, time = ?,
                        pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                        delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                        total_price = ?, category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                        modified_user = ?, modified_date = NOW()
                    WHERE id = ? AND company_id = ?');
                $up->bind_param(
                    'isssiiisiiidiiissii',
                    $branch_id, $date_in, $time_sql,
                    $p_addr, $p_prov, $p_city, $p_dist,
                    $d_addr, $d_prov, $d_city, $d_dist,
                    $total_price, $cat_id, $veh_id, $passenger_id, $b_note,
                    $user, $id, $booking_cid
                );
                $up->execute();
                $up->close();

                booking_passengers_set('travel', $id, $booking_cid, array_merge([$passenger_id], $extra_pids), $passenger_id, $user);

                if (abs($total_price - (float) $b['total_price']) > 0.01 || $branch_id !== (int) $b['branch_id']) {
                    $stInvUp = $db->prepare("SELECT id, status, tax_percent FROM invoices WHERE booking_type = 'travel' AND booking_id = ? AND company_id = ? LIMIT 1");
                    $stInvUp->bind_param('ii', $id, $booking_cid);
                    $stInvUp->execute();
                    $invUp = $stInvUp->get_result()->fetch_assoc();
                    $stInvUp->close();
                    if ($invUp && $invUp['status'] !== 'cancelled') {
                        $taxPct = (float) $invUp['tax_percent'];
                        $taxAmt = round($total_price * $taxPct / 100, 2);
                        $newTot = round($total_price + $taxAmt, 2);
                        $paid   = invoice_paid_total((int) $invUp['id']);
                        if ($newTot + 0.01 < $paid) {
                            throw new RuntimeException('Total harga tidak boleh kurang dari total yang sudah dibayar (' . rupiah($paid) . ').');
                        }
                        $newInvSt = (string) $invUp['status'];
                        if (in_array($newInvSt, ['partial', 'paid', 'overdue'], true)) {
                            $newInvSt = ($paid >= $newTot - 0.01 && $newTot > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'sent');
                        }
                        $upInv = $db->prepare('UPDATE invoices SET branch_id = ?, subtotal = ?, tax_amount = ?, total = ?, status = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                        $upInv->bind_param('idddssi', $branch_id, $total_price, $taxAmt, $newTot, $newInvSt, $user, $invUp['id']);
                        $upInv->execute();
                        $upInv->close();
                    }
                }

                $db->commit();
                audit('booking.travel.update_detail', "Booking #{$id} {$b['document_number']}");
                flash('success', 'Detail booking travel berhasil diperbarui.');
            } catch (Throwable $ex) {
                $db->rollback();
                throw $ex;
            }
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() === '' ? 'Aksi gagal dijalankan.' : $ex->getMessage());
    }
    $hash = in_array($targetTab, ['detail', 'ops', 'cost', 'note', 'audit'], true) ? ('#tab-' . $targetTab) : '';
    redirect('pages/travel/bookings/detail.php?id=' . $id . $hash);
}

$status = booking_current_status('travel', $id);
$next   = BOOKING_TRANSITIONS[$status] ?? [];
$canEditDetail = $canManage && $status !== 'cancelled';

$db = db();

$st = $db->prepare('SELECT h.from_status, h.to_status, h.reason, h.changed_by, h.changed_at
    FROM booking_status_history h WHERE h.booking_type = ? AND h.booking_id = ? ORDER BY h.id DESC LIMIT 20');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = $db->prepare("SELECT cost_type, description, amount, premi_type, premi_value, premi_amount, note
    FROM booking_cost_lines WHERE booking_type = ? AND booking_id = ? AND cost_type <> 'payment' ORDER BY id");
$st->bind_param('si', $bid_type, $id);
$st->execute();
$costs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = $db->prepare('SELECT t.employee_id, t.vehicle_id, t.started_at, e.name AS employee
    FROM trip_assignments t LEFT JOIN employees e ON e.id = t.employee_id
    WHERE t.booking_type = ? AND t.booking_id = ? AND t.ended_at IS NULL LIMIT 1');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$assignment = $st->get_result()->fetch_assoc();
$st->close();

// Daftar lengkap penumpang (primary + tambahan)
$paxList = booking_passengers_list('travel', $id, (int) $b['passenger_id']);
$primaryPax   = null;
$extraPaxList = [];
foreach ($paxList as $px) {
    if ($primaryPax === null && ((int) $px['is_primary'] === 1 || (int) $px['passenger_id'] === (int) $b['passenger_id'])) {
        $primaryPax = $px;
    } else {
        $extraPaxList[] = $px;
    }
}
if ($primaryPax === null) {
    $primaryPax = ['passenger_id' => (int) $b['passenger_id'], 'name' => '-', 'phone_number' => '', 'is_primary' => 1];
}

// Lookup metadata ringan O(1) via Primary Key
$stComp = $db->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $booking_cid);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $booking_cid, 'label' => active_company_name()];
$stComp->close();

// Lookup opsi terbatas + entitas terpilih (aman untuk jutaan baris data)
$displayVehId = (int) ($assignment['vehicle_id'] ?? $b['vehicle_id'] ?? 0);

$extra_passenger_ids = array_map(static fn($x) => (int) ($x['passenger_id'] ?? 0), $extraPaxList);
$selected_p_ids = array_values(array_unique(array_filter(array_merge([(int) ($b['passenger_id'] ?? 0)], $extra_passenger_ids))));

$branches   = opts_branches($booking_cid);
$passengers = opts_passengers($booking_cid, $selected_p_ids, 50);

$userCompanyIds = accessible_company_ids($booking_cid);
$cur_veh_id     = (int) ($b['vehicle_id'] ?? 0);
$cur_cat_id_raw = (int) ($b['category_vehicle_id'] ?? 0);

$cats           = opts_categories($booking_cid, $cur_cat_id_raw > 0 ? [$cur_cat_id_raw] : [], 200);
$detailVehicles = opts_vehicles_detailed(
    $booking_cid,
    $userCompanyIds,
    $cur_veh_id > 0 ? $cur_veh_id : null,
    $cur_cat_id_raw > 0 ? $cur_cat_id_raw : null,
    50
);
$cur_veh = null;
if ($cur_veh_id > 0) {
    foreach ($detailVehicles as $dv) {
        if ((int) $dv['id'] === $cur_veh_id) {
            $cur_veh = $dv;
            break;
        }
    }
}
$cur_category_id = (int) ($b['category_vehicle_id'] ?: ($cur_veh['category_vehicle_id'] ?? 0));

// Resolusi geografis authoritative untuk asal & tujuan
$p_dist_id = resolve_district_id($b['pickup_point_district_id'] ?? 0);
$d_dist_id = resolve_district_id($b['delivery_point_district_id'] ?? 0);
$p_city_id = (int) ($b['pickup_point_city_id'] ?? 0);
$d_city_id = (int) ($b['delivery_point_city_id'] ?? 0);
$p_prov_id = (int) ($b['pickup_point_province_id'] ?? 0);
$d_prov_id = (int) ($b['delivery_point_province_id'] ?? 0);

if ($p_info = resolve_district_info($p_dist_id)) {
    $p_dist_id = $p_info['id'];
    $p_city_id = $p_info['city_id'];
    $p_prov_id = $p_info['province_id'];
}
if ($d_info = resolve_district_info($d_dist_id)) {
    $d_dist_id = $d_info['id'];
    $d_city_id = $d_info['city_id'];
    $d_prov_id = $d_info['province_id'];
}
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

$invRow = null;
$stInv = $db->prepare("SELECT * FROM invoices WHERE booking_type = 'travel' AND booking_id = ? LIMIT 1");
$stInv->bind_param('i', $id);
$stInv->execute();
$invRow = $stInv->get_result()->fetch_assoc();
$stInv->close();
if ($invRow) {
    $invRow['paid_total'] = invoice_paid_total((int) $invRow['id']);
    $invRow['pay_status'] = invoice_pay_status($invRow);
}

$payment_rows   = $invRow ? invoice_payments((int) $invRow['id']) : [];
$qrisChannels   = gateway_active_channels($booking_cid);
$qrisInvTotal   = $invRow ? (float) ($invRow['total'] ?? 0) : (float) ($b['total_price'] ?? 0);
$qrisPaidTotal  = $invRow ? (float) ($invRow['paid_total'] ?? 0) : (float) ($b['total_payment'] ?? 0);
$qrisRemaining  = max(0.0, round($qrisInvTotal - $qrisPaidTotal, 2));
$hasPendingQris = false;
if ($invRow) {
    $stQChk = $db->prepare("SELECT 1 FROM payment_gateway_orders WHERE invoice_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    $invIdInt = (int) $invRow['id'];
    $stQChk->bind_param('i', $invIdInt);
    $stQChk->execute();
    $hasPendingQris = (bool) $stQChk->get_result()->fetch_row();
    $stQChk->close();
}

$paymentMethods = [];
$cashAccounts   = [];
if ($canManage) {
    $stPm = $db->prepare("SELECT id, name, account_name, account_number FROM payment_methods WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL) AND COALESCE(code, '') <> 'QRIS-AUTO' ORDER BY sort_order, name");
    $stPm->bind_param('i', $booking_cid);
    $stPm->execute();
    $paymentMethods = $stPm->get_result()->fetch_all(MYSQLI_ASSOC);
    $stPm->close();

    $stCa = $db->prepare('SELECT id, name, account_number FROM cash_accounts WHERE is_active = 1 AND company_id = ? ORDER BY name');
    $stCa->bind_param('i', $booking_cid);
    $stCa->execute();
    $cashAccounts = $stCa->get_result()->fetch_all(MYSQLI_ASSOC);
    $stCa->close();
}

$costSum  = 0.0;
$premiSum = 0.0;
foreach ($costs as $c) {
    $costSum  += (float) ($c['amount'] ?? 0);
    $premiSum += (float) ($c['premi_amount'] ?? 0);
}

// Riwayat penugasan driver (modul Penjadwalan) untuk booking ini.
$assignments = dispatch_by_booking('travel', $id);

$assignedEmpId = (int) ($assignment['employee_id'] ?? 0);
$employees     = $canManage ? opts_employees($booking_cid, $assignedEmpId > 0 ? [$assignedEmpId] : [], 50) : [];
$vehicles      = $canManage ? opts_vehicles($booking_cid, [$displayVehId]) : [];

$active = 'travel';
$title  = 'Detail Booking Travel - ' . $b['document_number'];
require __DIR__ . '/../../../includes/header.php';
?>

<main class="p-2 p-md-3 w-100 flex-grow-1">
    <!-- Top Action Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-ticket-perforated-fill me-1"></i>Booking Travel</span>
            <?= status_badge($status) ?>
            <span class="fw-bold font-monospace"><?= e($b['document_number']) ?></span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
            <?php if ($canEditDetail): ?>
                <button type="submit" form="travelDetailForm" class="btn btn-primary btn-sm px-3" id="btnSaveTravelDetailTop">
                    <i class="bi bi-check2-circle me-1"></i>Simpan Perubahan
                </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/form.php?id=<?= (int) $b['id'] ?>">
                    <i class="bi bi-pencil-square me-1"></i>Form Lengkap
                </a>
            <?php endif; ?>
            <?php if (can('dispatch.manage')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/dispatch/assign.php">
                    <i class="bi bi-person-badge me-1"></i>Tugaskan Driver
                </a>
            <?php endif; ?>
            <a class="btn btn-dark btn-sm" target="_blank" href="<?= e(APP_URL) ?>/tiket.php?no=<?= urlencode((string) $b['document_number']) ?>&print=1">
                <i class="bi bi-printer me-1"></i>Cetak E-Tiket
            </a>
        </div>
    </div>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="travelDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">
                    Detail
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-ops-btn" data-bs-toggle="tab" data-bs-target="#tab-ops" type="button" role="tab">
                    Driver &amp; Tindakan<?php if ($assignments): ?> <span class="badge bg-secondary-subtle text-dark border ms-1"><?= count($assignments) ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-cost-btn" data-bs-toggle="tab" data-bs-target="#tab-cost" type="button" role="tab">
                    Biaya &amp; Tagihan
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
            <?php if ($canEditDetail): ?>
            <form method="post" action="" id="travelDetailForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="update_detail">
                <input type="hidden" name="active_tab" value="detail">
            </form>
            <?php endif; ?>

            <div class="tab-content" id="travelDetailTabContent">
                <!-- ================= TAB 1: DETAIL ================= -->
                <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                    <?php if ($status === 'cancelled'): ?>
                        <div class="alert alert-secondary py-2 px-3 small mb-3">
                            <i class="bi bi-lock me-1"></i>Booking sudah dibatalkan — detail pemesanan bersifat baca-saja.
                        </div>
                    <?php endif; ?>

                    <!-- Informasi Umum & Harga -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Informasi Pemesanan</span>
                            <span class="badge bg-light text-dark border font-monospace"><?= e($b['document_number']) ?></span>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">No. Tiket / Resi</div>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light font-monospace" value="<?= e($b['document_number']) ?>" readonly>
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
                                    <select class="form-select form-select-sm" name="branch_id" id="branchSelect" form="travelDetailForm" <?= $canEditDetail ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Cabang --</option>
                                        <?= select_options($branches, $b['branch_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Tanggal Berangkat <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm <?= $canEditDetail ? 'bg-white flatpickr-date' : 'bg-light' ?>" name="date" id="travelDateInput" form="travelDetailForm" value="<?= e((string) $b['date']) ?>" placeholder="dd-MMM-yyyy" <?= $canEditDetail ? 'required' : 'disabled' ?> style="max-width: 260px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Jam <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="time" class="form-control form-control-sm" name="time" id="travelTimeInput" form="travelDetailForm" value="<?= e(substr((string) $b['time'], 0, 5)) ?>" <?= $canEditDetail ? 'required' : 'readonly' ?> style="max-width: 180px;">
                                        <span class="badge bg-light text-secondary border" id="travelTimePeriodBadge"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <div class="rnt-label pt-1">Total Harga <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <div class="input-group input-group-sm" style="max-width: 260px;">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" inputmode="numeric" class="form-control fw-semibold text-end" id="totalPriceDisplay" value="<?= e(format_nominal((float) $b['total_price'])) ?>" placeholder="0" autocomplete="off" <?= $canEditDetail ? 'required' : 'readonly' ?>>
                                        </div>
                                        <input type="hidden" name="total_price" id="totalPriceValue" form="travelDetailForm" value="<?= (int) round((float) $b['total_price']) ?>">
                                        <?php if ($canEditDetail): ?>
                                            <button type="button" id="btnApplyTravelCalc" class="btn btn-outline-primary btn-sm py-0 px-2" style="display:none;">
                                                <i class="bi bi-calculator me-1"></i>Terapkan Tarif Rute
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="travelPriceHint" class="form-text small mt-1" style="display:none;"></div>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Driver Aktif</div>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light fw-semibold" value="<?= e($assignment['employee'] ?? '-') ?>" readonly style="max-width: 260px;">
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
                                    <select class="form-select form-select-sm ts-select" name="category_vehicle_id" id="categoryVehicleSelect" form="travelDetailForm" data-ts-placeholder="-- Semua Kategori --" <?= $canEditDetail ? '' : 'disabled' ?>>
                                        <option value="">-- Semua Kategori --</option>
                                        <?= select_options($cats, $cur_category_id) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <div class="rnt-label">Kendaraan</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="vehicle_id" id="vehicleSelect" form="travelDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-parent="#categoryVehicleSelect"
                                        data-ts-param="category_id"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Kendaraan --"
                                        <?= $canEditDetail ? '' : 'disabled' ?>>
                                        <option value="">-- Pilih Kendaraan --</option>
                                        <?php foreach ($detailVehicles as $v): ?>
                                            <option value="<?= (int) $v['id'] ?>"
                                                    data-category="<?= (int) ($v['category_vehicle_id'] ?? 0) ?>"
                                                    data-plate="<?= e((string) ($v['vehicle_number'] ?? '')) ?>"
                                                    data-brand="<?= e((string) ($v['brand_name'] ?? '')) ?>"
                                                    data-model="<?= e((string) ($v['model_name'] ?? '')) ?>"
                                                    data-category-name="<?= e((string) ($v['category_name'] ?? '')) ?>"
                                                    data-color="<?= e((string) ($v['vehicle_color'] ?? '')) ?>"
                                                    data-seat="<?= (int) ($v['seat'] ?? 0) ?>"
                                                    <?= (int) $v['id'] === $cur_veh_id ? 'selected' : '' ?>>
                                                <?= e($v['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

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

                    <!-- Sub-panel Penumpang -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <div class="d-flex align-items-center gap-2">
                                <span>Daftar Penumpang</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($paxList) ?> Penumpang</span>
                            </div>
                            <?php if ($canEditDetail): ?>
                                <a href="#" class="text-primary text-decoration-none fw-normal small" data-bs-toggle="modal" data-bs-target="#quickPassengerModal">
                                    <i class="bi bi-person-plus me-1"></i>Tambah Pelanggan Baru
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Penumpang Utama <span class="text-danger">*</span></div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_id" id="primaryPassengerSelect" form="travelDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Penumpang Utama --"
                                        <?= $canEditDetail ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Penumpang Utama --</option>
                                        <?= select_options($passengers, $b['passenger_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <div class="rnt-label pt-1">Penumpang Tambahan</div>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_ids[]" id="extraPassengersSelect" form="travelDetailForm" multiple
                                        data-ts-url="<?= e(APP_URL) ?>/pages/travel/passengers/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-limit="30"
                                        placeholder="Pilih penumpang tambahan (opsional)..." <?= $canEditDetail ? '' : 'disabled' ?>>
                                        <?= select_options($passengers, $extra_passenger_ids) ?>
                                    </select>
                                    <div class="form-text small">Opsional — klik tombol <strong>&times;</strong> pada nama untuk menghapus penumpang tambahan.</div>
                                    <div id="seatCapacityWarning" class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" role="alert"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel Penjemputan & Pengantaran (2 Kolom) -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Penjemputan (Asal)</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_province_id" id="pickupProvince" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= $canEditDetail ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $p_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota / Kab <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_city_id" id="pickupCity" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#pickupProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= $canEditDetail ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($pickup_cities as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>" <?= (int) $c['id'] === $p_city_id ? 'selected' : '' ?>>
                                                        <?= e($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_district_id" id="pickupDistrict" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#pickupCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= $canEditDetail ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?= select_options($pickup_districts, $p_dist_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Alamat Jemput</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm<?= $canEditDetail ? '' : ' bg-light' ?>" name="pickup_address" form="travelDetailForm" rows="3" maxlength="2000" <?= $canEditDetail ? '' : 'readonly' ?>><?= e((string) ($b['pickup_address'] ?? '')) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Pengantaran (Tujuan)</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_province_id" id="deliveryProvince" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= $canEditDetail ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $d_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota / Kab <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_city_id" id="deliveryCity" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#deliveryProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= $canEditDetail ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($delivery_cities as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>" <?= (int) $c['id'] === $d_city_id ? 'selected' : '' ?>>
                                                        <?= e($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_district_id" id="deliveryDistrict" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#deliveryCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= $canEditDetail ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?= select_options($delivery_districts, $d_dist_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Alamat Antar</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm<?= $canEditDetail ? '' : ' bg-light' ?>" name="delivery_address" form="travelDetailForm" rows="3" maxlength="2000" <?= $canEditDetail ? '' : 'readonly' ?>><?= e((string) ($b['delivery_address'] ?? '')) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($canEditDetail): ?>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" form="travelDetailForm" class="btn btn-primary btn-sm px-3" id="btnSaveTravelDetailBottom">
                            <i class="bi bi-check2-circle me-1"></i>Simpan Detail Pemesanan
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ================= TAB 2: DRIVER & TINDAKAN ================= -->
                <div class="tab-pane fade" id="tab-ops" role="tabpanel">
                    <?php if ($canManage): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Ubah Status</span></div>
                                <div class="rnt-panel-body">
                                    <?php if ($next): ?>
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="status">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Status Tujuan</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" name="to_status">
                                                    <?php foreach ($next as $n): ?><option value="<?= e($n) ?>"><?= e($n) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Alasan</label>
                                            <div class="rnt-field">
                                                <input class="form-control form-control-sm" name="reason" placeholder="Catatan perubahan status..." maxlength="2000">
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Proses Status</button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                        <p class="text-muted mb-0 small">Status akhir — tidak ada transisi lanjutan.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Reschedule Jadwal</span></div>
                                <div class="rnt-panel-body">
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="reschedule">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tanggal Baru</label>
                                            <div class="rnt-field"><input type="date" class="form-control form-control-sm" name="date" required value="<?= e($b['date']) ?>"></div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Jam Baru</label>
                                            <div class="rnt-field"><input type="time" class="form-control form-control-sm" name="time" required value="<?= e(substr($b['time'], 0, 5)) ?>"></div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-calendar-check me-1"></i>Simpan Jadwal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Tugaskan Driver</span></div>
                                <div class="rnt-panel-body">
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="driver">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Driver</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" name="employee_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                                                    data-ts-company="<?= (int) $booking_cid ?>"
                                                    data-ts-limit="30">
                                                    <option value="">-- Karyawan --</option><?= select_options($employees, $assignment['employee_id'] ?? '') ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kendaraan</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" name="vehicle_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                                    data-ts-company="<?= (int) $booking_cid ?>"
                                                    data-ts-limit="30">
                                                    <option value="">-- Kendaraan --</option><?= select_options($vehicles, $assignment['vehicle_id'] ?? '') ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-person-check me-1"></i>Tugaskan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Batalkan Booking</span></div>
                                <div class="rnt-panel-body">
                                    <?php if ($status === 'cancelled'): ?>
                                        <p class="text-muted mb-0 small">Booking sudah dibatalkan.</p>
                                    <?php else: ?>
                                    <form method="post" action="" class="d-flex flex-column gap-2" onsubmit="return confirm('Batalkan booking ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="cancel">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Alasan Batal</label>
                                            <div class="rnt-field">
                                                <input class="form-control form-control-sm" name="reason" placeholder="Alasan pembatalan..." maxlength="2000">
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Batalkan Booking</button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Riwayat Penugasan Driver</span>
                            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= e(APP_URL) ?>/pages/dispatch/detail.php?type=travel&amp;id=<?= $id ?>">Kelola di Penjadwalan</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Driver</th><th>Kendaraan</th><th>Mulai</th><th>Selesai</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($assignments as $a): ?>
                                    <tr>
                                        <td><?= e((string) $a['employee_name']) ?></td>
                                        <td><?= e((string) ($a['vehicle_number'] ?? '-')) ?></td>
                                        <td><?= e((string) $a['started_at']) ?></td>
                                        <td><?= e($a['ended_at'] ? (string) $a['ended_at'] : '—') ?></td>
                                        <td><span class="badge bg-<?= empty($a['ended_at']) ? 'warning text-dark' : 'secondary' ?>"><?= empty($a['ended_at']) ? 'sedang jalan' : 'selesai' ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$assignments): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada penugasan driver.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 3: BIAYA & TAGIHAN ================= -->
                <div class="tab-pane fade" id="tab-cost" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Tagihan / Invoice &amp; Status Pembayaran</span>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span id="qrisLiveSyncBadge" class="badge bg-info-subtle text-info-emphasis border <?= $hasPendingQris ? '' : 'd-none' ?>">
                                    <i class="bi bi-arrow-repeat me-1"></i>Pantau QRIS Aktif
                                </span>
                                <?php if ($canManage): ?>
                                    <?php if (!empty($qrisChannels)): ?>
                                        <button type="button" class="btn btn-sm btn-success py-0 px-2" id="btnPayQrisAuto" <?= ($status === 'cancelled' || $qrisRemaining <= 0) ? 'disabled' : '' ?>>
                                            <i class="bi bi-qr-code-scan me-1"></i>Bayar via QRIS Otomatis
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Channel QRIS aktif belum dikonfigurasi untuk perusahaan ini">
                                            <i class="bi bi-qr-code me-1"></i>QRIS Belum Aktif
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($invRow && can('finance.view')): ?>
                                    <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $invRow['id'] ?>">Buka Invoice</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rnt-panel-body">
                            <?php if ($invRow):
                                $invDocNo = (string) (!empty($invRow['invoice_no']) ? $invRow['invoice_no'] : ($invRow['document_number'] ?? ('#' . (int) $invRow['id'])));
                                $payStatusNow = (string) ($invRow['pay_status'] ?? 'unpaid');
                            ?>
                            <div class="row g-3 small">
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">No. Invoice</div>
                                    <div class="fw-bold font-monospace"><?= e($invDocNo) ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">Status Pembayaran</div>
                                    <div id="travelPayStatusBadgeWrap"><?= invoice_pay_badge($payStatusNow) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Total Tagihan</div>
                                    <div class="fw-semibold"><?= e(rupiah($qrisInvTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Terbayar</div>
                                    <div class="fw-semibold text-success" id="travelPaidTotalDisplay"><?= e(rupiah($qrisPaidTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Sisa Tagihan</div>
                                    <div class="fw-bold <?= $qrisRemaining > 0 ? 'text-danger' : 'text-success' ?>" id="qrisRemainingDisplay">
                                        <?= $qrisRemaining > 0 ? e(rupiah($qrisRemaining)) : 'Lunas (Rp 0)' ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="row g-3 small align-items-center">
                                <div class="col-6 col-md-4">
                                    <div class="text-muted">Tagihan Travel</div>
                                    <div class="fw-bold"><?= e(rupiah($qrisInvTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="text-muted">Terbayar</div>
                                    <div class="fw-semibold text-success" id="travelPaidTotalDisplay"><?= e(rupiah($qrisPaidTotal)) ?></div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="text-muted">Sisa Tagihan</div>
                                    <div class="fw-bold <?= $qrisRemaining > 0 ? 'text-danger' : 'text-success' ?>" id="qrisRemainingDisplay">
                                        <?= $qrisRemaining > 0 ? e(rupiah($qrisRemaining)) : 'Lunas (Rp 0)' ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="qrisAutoNewRowBanner" class="alert alert-success py-2 px-3 small d-none align-items-center justify-content-between mb-3" role="alert">
                        <div>
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span id="qrisAutoNewRowMsg">Pembayaran QRIS baru telah masuk dan ditambahkan otomatis.</span>
                        </div>
                        <button type="button" class="btn-close btn-sm" aria-label="Tutup" onclick="document.getElementById('qrisAutoNewRowBanner').classList.add('d-none');document.getElementById('qrisAutoNewRowBanner').classList.remove('d-flex');"></button>
                    </div>

                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Riwayat Pembayaran</span>
                            <span class="badge bg-light text-secondary border" id="travelPayCountBadge"><?= count($payment_rows) ?> transaksi</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:120px;">Tanggal</th>
                                        <th>Tipe Pembayaran</th>
                                        <th>Metode</th>
                                        <th>Bukti</th>
                                        <th class="text-end" style="width:150px;">Nominal</th>
                                    </tr>
                                </thead>
                                <tbody id="travelPayTableBody">
                                    <?php foreach ($payment_rows as $pr):
                                        $isAutoQr = !empty($pr['is_auto_qris']);
                                        $pDt = !empty($pr['pay_date']) ? date('d-M-Y', strtotime((string) $pr['pay_date'])) : '-';
                                        $blob = (string) ($pr['proof_blob_path'] ?? '');
                                    ?>
                                    <tr class="travel-pay-row" data-payment-id="<?= (int) ($pr['id'] ?? 0) ?>">
                                        <td class="small text-nowrap"><?= e($pDt) ?></td>
                                        <td><?= e((string) ($pr['pay_type'] ?? 'Pelunasan')) ?></td>
                                        <td>
                                            <?= e((string) ($pr['pay_method'] ?? 'Transfer')) ?>
                                            <?php if ($isAutoQr): ?>
                                                <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-qr-code me-1"></i>QRIS Auto</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAutoQr): ?>
                                                <span class="text-success small">Terverifikasi Otomatis</span>
                                            <?php elseif ($blob !== ''): ?>
                                                <a href="<?= e(resolve_asset_url($blob)) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">Lihat Bukti</a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold text-nowrap"><?= e(rupiah((float) ($pr['amount'] ?? 0))) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$payment_rows): ?>
                                    <tr id="travelPayEmptyRow"><td colspan="5" class="text-center text-muted py-3">Belum ada pembayaran tercatat.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($canManage && $status !== 'cancelled' && $qrisRemaining > 0): ?>
                    <div class="rnt-panel" id="travelManualPayPanel">
                        <div class="rnt-panel-header">
                            <span>Catat Pembayaran Manual</span>
                            <span class="small text-muted">Maksimal sebesar sisa tagihan: <strong class="text-danger" id="travelManualMaxText"><?= e(rupiah($qrisRemaining)) ?></strong></span>
                        </div>
                        <div class="rnt-panel-body">
                            <div id="travelPayOverpayAlert" class="alert alert-danger py-2 px-3 small d-none mb-2" role="alert">
                                <i class="bi bi-exclamation-octagon me-1"></i><span id="travelPayOverpayMsg"></span>
                            </div>
                            <form method="post" action="" enctype="multipart/form-data" id="travelManualPayForm" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="act" value="pay_manual">
                                <input type="hidden" name="active_tab" value="cost">
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Tipe Pembayaran</label>
                                    <select name="pay_type" id="travelManualPayType" class="form-select form-select-sm">
                                        <option value="Down Payment (DP)">Down Payment (DP)</option>
                                        <option value="Pelunasan" <?= $qrisPaidTotal > 0 ? 'selected' : '' ?>>Pelunasan</option>
                                        <option value="Pembayaran Penuh (Full)" <?= $qrisPaidTotal <= 0 ? 'selected' : '' ?>>Pembayaran Penuh (Full)</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Metode Pembayaran</label>
                                    <select name="payment_method_id" class="form-select form-select-sm">
                                        <option value="">-- Transfer / Tunai Manual --</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?= (int) $pm['id'] ?>"><?= e($pm['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Akun Kas / Bank</label>
                                    <select name="cash_account_id" class="form-select form-select-sm">
                                        <option value="">-- Tidak Dicatat ke Kas --</option>
                                        <?php foreach ($cashAccounts as $ca): ?>
                                            <option value="<?= (int) $ca['id'] ?>"><?= e($ca['name']) ?><?= !empty($ca['account_number']) ? ' (' . e($ca['account_number']) . ')' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Nominal Bayar (Rp) <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rp</span>
                                        <input type="text" inputmode="numeric" class="form-control fw-bold text-end" id="travelPayAmountDisplay" value="<?= e(format_nominal($qrisRemaining)) ?>" autocomplete="off" required>
                                    </div>
                                    <input type="hidden" name="amount" id="travelPayAmountValue" value="<?= (int) round($qrisRemaining) ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Bukti Pembayaran (Opsional)</label>
                                    <input type="file" name="proof_file" class="form-control form-control-sm" accept="image/*,application/pdf">
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label small fw-semibold mb-1">Catatan</label>
                                    <input type="text" name="pay_note" class="form-control form-control-sm" placeholder="No. ref transfer / keterangan...">
                                </div>
                                <div class="col-12 col-md-3 d-flex justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary travel-pay-preset" data-preset="dp50">50%</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary travel-pay-preset" data-preset="full">Lunas</button>
                                    <button type="submit" class="btn btn-sm btn-primary px-3" id="btnSubmitTravelManualPay">
                                        <i class="bi bi-check2-circle me-1"></i>Simpan Bayar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Rincian Biaya &amp; Premi</span></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Jenis</th><th>Keterangan</th><th class="text-end">Jumlah</th><th>Premi</th><th class="text-end">Nilai Premi</th></tr></thead>
                                <tbody>
                                <?php foreach ($costs as $c): ?>
                                    <tr>
                                        <td><?= e((string) $c['cost_type']) ?></td>
                                        <td><?= e((string) $c['description']) ?></td>
                                        <td class="text-end"><?= e(rupiah((float) $c['amount'])) ?></td>
                                        <td><?= e((string) $c['premi_type'] . ' ' . rupiah((float) $c['premi_value'])) ?></td>
                                        <td class="text-end"><?= e(rupiah((float) $c['premi_amount'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$costs): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada rincian biaya.</td></tr>
                                <?php endif; ?>
                                </tbody>
                                <?php if ($costs): ?>
                                <tfoot class="table-light fw-semibold">
                                    <tr>
                                        <td colspan="2" class="text-end">Total</td>
                                        <td class="text-end"><?= e(rupiah($costSum)) ?></td>
                                        <td></td>
                                        <td class="text-end"><?= e(rupiah($premiSum)) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 4: KETERANGAN ================= -->
                <div class="tab-pane fade" id="tab-note" role="tabpanel">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Keterangan / Catatan Perjalanan</span></div>
                        <div class="rnt-panel-body">
                            <textarea class="form-control form-control-sm<?= $canEditDetail ? '' : ' bg-light' ?>" id="noteInput" name="note" form="travelDetailForm" rows="8" maxlength="2000" placeholder="Catatan tambahan perjalanan (jadwal jemput, patokan alamat, dll)..." <?= $canEditDetail ? '' : 'readonly' ?>><?= e((string) ($b['note'] ?? '')) ?></textarea>
                            <?php if ($canEditDetail): ?>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="submit" form="travelDetailForm" class="btn btn-primary btn-sm px-3">
                                    <i class="bi bi-check2-circle me-1"></i>Simpan Perubahan
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 5: AUDIT TRAIL ================= -->
                <div class="tab-pane fade" id="tab-audit" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Rekam Pembuatan &amp; Perubahan</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($b['created_user'] ?? '')) ?: '-') ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['created_date']) ? date('d-M-Y H:i', strtotime((string) $b['created_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($b['modified_user'] ?? '')) ?: '-') ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['modified_date']) ? date('d-M-Y H:i', strtotime((string) $b['modified_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Riwayat Status</span></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Waktu</th><th>Dari</th><th>Ke</th><th>Alasan</th><th>Oleh</th></tr></thead>
                                <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td><?= e($h['changed_at']) ?></td>
                                        <td><?= e((string) $h['from_status']) ?></td>
                                        <td><?= status_badge($h['to_status']) ?></td>
                                        <td><?= e((string) $h['reason']) ?></td>
                                        <td><?= e((string) $h['changed_by']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$history): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../../../includes/qris_modal.php'; ?>

<?php if ($canEditDetail): ?>
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
                <div class="mb-3">
                    <label class="form-label">No. HP / WhatsApp</label>
                    <input type="text" class="form-control" name="phone_number" id="qpPhone" maxlength="50" placeholder="0812xxxxxxxx">
                </div>
                <div class="mb-0">
                    <label class="form-label">Email (Opsional)</label>
                    <input type="email" class="form-control" name="email" id="qpEmail" maxlength="255" placeholder="pelanggan@email.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary" id="qpSubmitBtn"><i class="bi bi-check2 me-1"></i>Simpan &amp; Pilih</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const appUrl        = <?= json_encode(APP_URL) ?>;
    const csrfTok       = <?= json_encode(csrf_token()) ?>;
    const qrisBookingId = <?= (int) $id ?>;
    const qrisBookStat  = <?= json_encode($status) ?>;
    let qrisInvTotal    = <?= json_encode((float) $qrisInvTotal) ?>;
    let qrisServerPaid  = <?= json_encode((float) $qrisPaidTotal) ?>;
    let qrisHasPending  = <?= json_encode($hasPendingQris) ?>;

    function fmtRp(num) {
        return window.formatRupiah ? window.formatRupiah(num) : String(Math.round(num || 0));
    }
    function parseRp(v) {
        return window.parseRupiah ? window.parseRupiah(v) : (parseInt(String(v || '0').replace(/[^0-9]/g, ''), 10) || 0);
    }
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    var hash = window.location.hash;
    if (hash && /^#tab-(detail|ops|cost|note|audit)$/.test(hash)) {
        var btn = document.querySelector('#travelDetailTabs [data-bs-target="' + hash + '"]');
        if (btn && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(btn).show();
        }
    }
    document.querySelectorAll('#travelDetailTabs [data-bs-toggle="tab"]').forEach(function (tabEl) {
        tabEl.addEventListener('shown.bs.tab', function (ev) {
            var target = ev.target.getAttribute('data-bs-target');
            if (target && history.replaceState) {
                history.replaceState(null, '', target);
            }
            if (target === '#tab-cost') {
                pollTravelQris('');
            }
            refreshBgQrisPoller();
        });
    });

    // Validasi Pembayaran Manual (anti-overpay di sisi client sebelum submit)
    const manualForm    = document.getElementById('travelManualPayForm');
    const manualDisp    = document.getElementById('travelPayAmountDisplay');
    const manualHidden  = document.getElementById('travelPayAmountValue');
    const manualAlert   = document.getElementById('travelPayOverpayAlert');
    const manualAlertMs = document.getElementById('travelPayOverpayMsg');
    const manualTypeSel = document.getElementById('travelManualPayType');
    const manualBtnSub  = document.getElementById('btnSubmitTravelManualPay');

    function validateManualPayAmount() {
        if (!manualDisp || !manualHidden) return true;
        const amt = parseRp(manualHidden.value || manualDisp.value);
        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (amt > rem && rem > 0) {
            if (manualAlert && manualAlertMs) {
                manualAlertMs.textContent = 'Nominal pembayaran (Rp ' + fmtRp(amt) + ') melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').';
                manualAlert.classList.remove('d-none');
            }
            if (manualBtnSub) manualBtnSub.disabled = true;
            return false;
        }
        if (manualAlert) manualAlert.classList.add('d-none');
        if (manualBtnSub) manualBtnSub.disabled = false;
        return amt > 0;
    }

    if (manualDisp && manualHidden && window.attachRupiahInput) {
        window.attachRupiahInput(manualDisp, manualHidden, validateManualPayAmount);
    }
    document.querySelectorAll('.travel-pay-preset').forEach((btn) => {
        btn.addEventListener('click', () => {
            const preset = btn.getAttribute('data-preset');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            const val = preset === 'dp50' ? Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.5))) : rem;
            if (manualDisp) manualDisp.value = fmtRp(val);
            if (manualHidden) manualHidden.value = String(val);
            if (manualTypeSel) {
                manualTypeSel.value = preset === 'dp50'
                    ? 'Down Payment (DP)'
                    : (qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)');
            }
            validateManualPayAmount();
        });
    });
    if (manualForm) {
        manualForm.addEventListener('submit', (e) => {
            const amt = parseRp(manualHidden ? manualHidden.value : '0');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (amt <= 0 || amt > rem) {
                e.preventDefault();
                if (manualAlert && manualAlertMs) {
                    manualAlertMs.textContent = amt <= 0
                        ? 'Masukkan nominal pembayaran lebih dari Rp 0.'
                        : ('Nominal pembayaran (Rp ' + fmtRp(amt) + ') tidak boleh melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').');
                    manualAlert.classList.remove('d-none');
                }
                if (manualDisp) manualDisp.focus();
                return false;
            }
        });
    }

    // QRIS Dinamis Otomatis (berbagi endpoint pages/rental/bookings/qris_ajax.php dengan booking_type=travel)
    const btnPayQrisAuto  = document.getElementById('btnPayQrisAuto');
    const qrisModalEl     = document.getElementById('qrisAutoModal');
    const qrisStepForm    = document.getElementById('qrisStepForm');
    const qrisStepShow    = document.getElementById('qrisStepShow');
    const qrisCreateErr   = document.getElementById('qrisCreateErr');
    const qrisFormSisa    = document.getElementById('qrisFormSisaText');
    const qrisPayTypeSel  = document.getElementById('qrisPayType');
    const qrisProvSel     = document.getElementById('qrisChannelProvider');
    const qrisAmtDisp     = document.getElementById('qrisAmountDisplay');
    const qrisAmtVal      = document.getElementById('qrisAmountValue');
    const btnGenQris      = document.getElementById('btnGenerateQris');
    const qrisRemDisp     = document.getElementById('qrisRemainingDisplay');
    const travelPaidDisp  = document.getElementById('travelPaidTotalDisplay');
    const qrisLiveBadge   = document.getElementById('qrisLiveSyncBadge');
    const qrisNewBanner   = document.getElementById('qrisAutoNewRowBanner');
    const qrisNewMsg      = document.getElementById('qrisAutoNewRowMsg');
    const payTbody        = document.getElementById('travelPayTableBody');
    const payCountBadge   = document.getElementById('travelPayCountBadge');

    let qrisCurrentOrderId   = '';
    let qrisCurrentPayWebUrl = '';
    let qrisModalPollTimer   = null;
    let qrisBgPollTimer      = null;
    let qrisCountdownTimer   = null;
    let qrisPollInFlight     = false;

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

    function appendAutoQrisRow(p) {
        if (!payTbody || !p || !p.id) return false;
        if (payTbody.querySelector('tr.travel-pay-row[data-payment-id="' + String(p.id) + '"]')) return false;
        const emptyRow = document.getElementById('travelPayEmptyRow');
        if (emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.className = 'travel-pay-row table-success';
        tr.setAttribute('data-payment-id', String(p.id));
        tr.innerHTML = `
            <td class="small text-nowrap">${escHtml(p.pay_date_fmt || p.pay_date || '-')}</td>
            <td>${escHtml(p.pay_type || 'Pelunasan')}</td>
            <td>${escHtml(p.pay_method || 'QRIS')} <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-qr-code me-1"></i>QRIS Auto</span></td>
            <td><span class="text-success small">Terverifikasi Otomatis</span></td>
            <td class="text-end fw-semibold text-nowrap">Rp ${escHtml(p.amount_fmt || fmtRp(p.amount || 0))}</td>
        `;
        payTbody.appendChild(tr);
        setTimeout(() => tr.classList.remove('table-success'), 4000);
        if (payCountBadge) {
            const cnt = payTbody.querySelectorAll('tr.travel-pay-row').length;
            payCountBadge.textContent = cnt + ' transaksi';
        }
        return true;
    }

    function updateSummaryUi(data) {
        if (typeof data.invoice_total === 'number') qrisInvTotal = data.invoice_total;
        if (typeof data.paid_total === 'number') qrisServerPaid = data.paid_total;
        if (typeof data.has_pending === 'boolean') qrisHasPending = data.has_pending;

        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (travelPaidDisp) travelPaidDisp.textContent = 'Rp ' + fmtRp(qrisServerPaid);
        if (qrisRemDisp) {
            qrisRemDisp.textContent = rem > 0 ? ('Rp ' + fmtRp(rem)) : 'Lunas (Rp 0)';
            qrisRemDisp.className = 'fw-bold ' + (rem > 0 ? 'text-danger' : 'text-success');
        }
        if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + fmtRp(rem);
        const manualMax = document.getElementById('travelManualMaxText');
        if (manualMax) manualMax.textContent = 'Rp ' + fmtRp(rem);
        if (rem <= 0) {
            const manualPanel = document.getElementById('travelManualPayPanel');
            if (manualPanel) manualPanel.classList.add('d-none');
            const badgeWrap = document.getElementById('travelPayStatusBadgeWrap');
            if (badgeWrap) badgeWrap.innerHTML = '<span class="badge bg-success">Lunas</span>';
        }
        if (btnPayQrisAuto) {
            const curSt = data.booking_status || qrisBookStat;
            btnPayQrisAuto.disabled = (curSt === 'cancelled' || rem <= 0);
        }
    }

    async function pollTravelQris(targetOrderId = '') {
        if (qrisPollInFlight) return null;
        qrisPollInFlight = true;
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrfTok);
            fd.append('act', 'poll');
            fd.append('booking_type', 'travel');
            fd.append('booking_id', String(qrisBookingId));
            if (targetOrderId) fd.append('order_id', targetOrderId);

            const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) return data;

            let addedCount = 0;
            let addedSum = 0;
            if (Array.isArray(data.payments)) {
                data.payments.forEach((p) => {
                    if (p && Number(p.id) > 0 && appendAutoQrisRow(p)) {
                        addedCount++;
                        addedSum += Number(p.amount || 0);
                    }
                });
            }
            if (addedCount > 0 && qrisNewBanner && qrisNewMsg) {
                qrisNewMsg.textContent = `Pembayaran QRIS baru sebesar Rp ${fmtRp(addedSum)} telah masuk dan tercatat otomatis.`;
                qrisNewBanner.classList.remove('d-none');
                qrisNewBanner.classList.add('d-flex');
            }
            updateSummaryUi(data);

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
        const costPane  = document.getElementById('tab-cost');
        const tabActive = costPane && costPane.classList.contains('active');
        const shouldPoll = !document.hidden && (qrisHasPending || tabActive);

        if (shouldPoll && !qrisBgPollTimer) {
            qrisBgPollTimer = setInterval(() => {
                if (!document.hidden) pollTravelQris('');
            }, 6000);
        } else if (!shouldPoll && qrisBgPollTimer) {
            clearInterval(qrisBgPollTimer);
            qrisBgPollTimer = null;
        }
        if (qrisLiveBadge) {
            if (qrisHasPending || tabActive) qrisLiveBadge.classList.remove('d-none');
            else qrisLiveBadge.classList.add('d-none');
        }
    }
    document.addEventListener('visibilitychange', refreshBgQrisPoller);

    if (qrisAmtDisp && qrisAmtVal && window.attachRupiahInput) {
        window.attachRupiahInput(qrisAmtDisp, qrisAmtVal);
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
            if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(val);
            if (qrisAmtVal) qrisAmtVal.value = String(val);
        });
    });

    if (btnPayQrisAuto && qrisModalEl) {
        btnPayQrisAuto.addEventListener('click', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId   = '';
            qrisCurrentPayWebUrl = '';
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + fmtRp(rem);
            if (qrisPayTypeSel) qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
            if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(rem);
            if (qrisAmtVal) qrisAmtVal.value = String(rem);
            if (qrisStepForm) qrisStepForm.classList.remove('d-none');
            if (qrisStepShow) qrisStepShow.classList.add('d-none');
            if (typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(qrisModalEl).show();
        });
        qrisModalEl.addEventListener('hidden.bs.modal', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            refreshBgQrisPoller();
        });
    }

    if (btnGenQris) {
        btnGenQris.addEventListener('click', async () => {
            const amt  = parseRp(qrisAmtVal ? qrisAmtVal.value : (qrisAmtDisp ? qrisAmtDisp.value : '0'));
            const pt   = qrisPayTypeSel ? qrisPayTypeSel.value : 'Pelunasan';
            const prov = qrisProvSel ? qrisProvSel.value : '';
            const rem  = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
            if (rem <= 0 || amt <= 0 || amt > rem) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = rem <= 0
                        ? 'Tagihan invoice ini sudah lunas.'
                        : (amt <= 0 ? 'Masukkan nominal QRIS lebih dari Rp 0.' : 'Nominal QRIS (Rp ' + fmtRp(amt) + ') melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').');
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
                fd.append('booking_type', 'travel');
                fd.append('booking_id', String(qrisBookingId));
                fd.append('pay_type', pt);
                fd.append('amount', String(amt));
                if (prov) fd.append('provider', prov);

                const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, { method: 'POST', body: fd });
                const data = await res.json();
                if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || 'Gagal membuat transaksi QRIS.');

                qrisCurrentOrderId   = data.order_id || '';
                qrisCurrentPayWebUrl = data.pay_web_url || '';
                qrisHasPending       = true;

                const imgEl = document.getElementById('qrisShowImg');
                if (imgEl) {
                    const primarySrc  = data.qris_image_url || data.fallback_qr_url || '';
                    const fallbackSrc = data.fallback_qr_url || '';
                    imgEl.onerror = function () { if (fallbackSrc && this.src !== fallbackSrc) this.src = fallbackSrc; };
                    imgEl.src = primarySrc;
                }
                const setTxt = (id, t) => { const e = document.getElementById(id); if (e) e.textContent = t; };
                setTxt('qrisShowChannelBadge', data.channel_name || 'QRIS');
                setTxt('qrisShowOrderId', qrisCurrentOrderId);
                setTxt('qrisShowPayType', data.pay_type || pt);
                setTxt('qrisShowBill', 'Rp ' + fmtRp(Math.round(Number(data.bill_amount || amt))));

                const feeRow = document.getElementById('qrisShowFeeRow');
                const feeNum = Math.round(Number(data.fee || 0));
                if (feeRow) {
                    feeRow.classList.toggle('d-none', feeNum <= 0);
                    setTxt('qrisShowFee', 'Rp ' + fmtRp(feeNum));
                }
                const uniqRow = document.getElementById('qrisShowUniqueRow');
                const uniqNum = Math.round(Number(data.unique_code || 0));
                if (uniqRow) {
                    uniqRow.classList.toggle('d-none', uniqNum <= 0);
                    setTxt('qrisShowUnique', '+Rp ' + fmtRp(uniqNum));
                }
                setTxt('qrisShowTotal', 'Rp ' + fmtRp(Math.round(Number(data.total_amount || amt))));

                const stBadge = document.getElementById('qrisShowStatusBadge');
                if (stBadge) {
                    stBadge.className = 'badge bg-warning-subtle text-warning-emphasis border';
                    stBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;" role="status"></span>Menunggu Pembayaran';
                }
                if (qrisStepForm) qrisStepForm.classList.add('d-none');
                if (qrisStepShow) qrisStepShow.classList.remove('d-none');
                startQrisCountdown(data.expired_ts || null);
                qrisModalPollTimer = setInterval(() => { if (qrisCurrentOrderId) pollTravelQris(qrisCurrentOrderId); }, 4000);
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
            await pollTravelQris(qrisCurrentOrderId);
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
    if (qrisHasPending) refreshBgQrisPoller();

    // ================= INLINE DETAIL FORM (PARITAS RENTAL DETAIL) =================
    const detailForm     = document.getElementById('travelDetailForm');
    const travelDateEl   = document.getElementById('travelDateInput');
    const travelTimeEl   = document.getElementById('travelTimeInput');
    const travelTimeBdg  = document.getElementById('travelTimePeriodBadge');
    const priceDispEl    = document.getElementById('totalPriceDisplay');
    const priceValEl     = document.getElementById('totalPriceValue');
    const catSelect      = document.getElementById('categoryVehicleSelect');
    const vehSelect      = document.getElementById('vehicleSelect');
    const primPaxSel     = document.getElementById('primaryPassengerSelect');
    const extraPaxSel    = document.getElementById('extraPassengersSelect');
    const seatWarnEl     = document.getElementById('seatCapacityWarning');
    const vehPreview     = document.getElementById('vehicleDetailPreview');
    const vehPrevPlate   = document.getElementById('vehPreviewPlate')?.querySelector('span');
    const vehPrevModel   = document.getElementById('vehPreviewModel');
    const vehPrevCat     = document.getElementById('vehPreviewCategory');
    const vehPrevSpecs   = document.getElementById('vehPreviewSpecs');
    const bookingCid     = <?= (int) $booking_cid ?>;

    if (travelDateEl && !travelDateEl.disabled && !travelDateEl._flatpickr && typeof flatpickr !== 'undefined') {
        flatpickr(travelDateEl, {
            altInput: true,
            altFormat: 'd-M-Y',
            dateFormat: 'Y-m-d',
            altInputClass: 'form-control form-control-sm bg-white',
            allowInput: false
        });
    }

    function updateTravelTimeBadge() {
        if (!travelTimeEl || !travelTimeBdg) return;
        const val = String(travelTimeEl.value || '');
        if (!val) { travelTimeBdg.textContent = ''; return; }
        const h = parseInt(val.split(':')[0], 10);
        if (isNaN(h)) { travelTimeBdg.textContent = ''; return; }
        if (h < 5) travelTimeBdg.textContent = 'Dini Hari (AM)';
        else if (h < 11) travelTimeBdg.textContent = 'Pagi (AM)';
        else if (h < 15) travelTimeBdg.textContent = 'Siang (PM)';
        else if (h < 18) travelTimeBdg.textContent = 'Sore (PM)';
        else travelTimeBdg.textContent = 'Malam (PM)';
    }
    if (travelTimeEl) {
        travelTimeEl.addEventListener('input', updateTravelTimeBadge);
        travelTimeEl.addEventListener('change', updateTravelTimeBadge);
        updateTravelTimeBadge();
    }

    if (priceDispEl && priceValEl && !priceDispEl.readOnly && window.attachRupiahInput) {
        window.attachRupiahInput(priceDispEl, priceValEl);
    }
    if (detailForm && priceDispEl && priceValEl) {
        detailForm.addEventListener('submit', () => {
            if (!priceDispEl.readOnly) {
                priceValEl.value = String(parseRp(priceDispEl.value));
            }
        });
    }

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

    function updateTravelSeatWarn() {
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, primPaxSel, extraPaxSel, seatWarnEl);
        }
    }

    function refreshVehiclePreview() {
        if (!vehSelect || !vehPreview) return;
        const vId = vehSelect.tomselect ? String(vehSelect.tomselect.getValue() || '') : String(vehSelect.value || '');
        const vData = getVehicleMeta(vId);
        if (vData && vData.plate) {
            vehPreview.style.display = '';
            if (vehPrevPlate) vehPrevPlate.textContent = vData.plate;
            if (vehPrevModel) vehPrevModel.textContent = ((vData.brand || '') + ' ' + (vData.model || '')).trim();
            if (vehPrevCat) vehPrevCat.textContent = vData.category_name || 'Armada';
            const specs = [vData.color, vData.seat > 0 ? (vData.seat + ' Kursi') : ''].filter(Boolean).join(' • ');
            if (vehPrevSpecs) vehPrevSpecs.textContent = specs;
        } else {
            vehPreview.style.display = 'none';
        }
        updateTravelSeatWarn();
    }

    if (vehSelect && window.bindTsChange) {
        window.bindTsChange(vehSelect, (vId) => {
            refreshVehiclePreview();
            const vData = getVehicleMeta(String(vId || ''));
            const curCat = catSelect ? (catSelect.tomselect ? String(catSelect.tomselect.getValue() || '') : String(catSelect.value || '')) : '';
            if (vData && vData.category && catSelect && !curCat && window.setTsValueSilent) {
                window.setTsValueSilent(catSelect, String(vData.category));
            }
        });
        refreshVehiclePreview();
    }

    if (catSelect && vehSelect && window.bindTsChange && window.setTsOptions) {
        window.bindTsChange(catSelect, async (catId) => {
            const curVal = vehSelect.tomselect ? String(vehSelect.tomselect.getValue() || '') : String(vehSelect.value || '');
            const curOpt = getVehicleMeta(curVal);
            const keepVal = (curVal && curOpt && (!catId || String(curOpt.category || '') === catId)) ? curVal : '';
            try {
                const u = `${appUrl}/pages/vehicles/options.php?company_id=${bookingCid}&limit=50`
                        + (catId ? `&category_id=${encodeURIComponent(catId)}` : '');
                const res = await fetch(u);
                const data = await res.json();
                if (keepVal && curOpt && !data.some(x => String(x.id) === keepVal)) {
                    data.unshift(curOpt);
                }
                window.setTsOptions(vehSelect, data, keepVal, '-- Pilih Kendaraan --');
            } catch (e) {
                console.error('Gagal memuat kendaraan:', e);
            }
            refreshVehiclePreview();
            calculateTravelPrice(false);
        });
    }

    // Estimasi Tarif Rute via Lookup Rute Terindeks (tanpa menimpa harga otomatis kecuali klik Terapkan)
    const pCityEl    = document.getElementById('pickupCity');
    const dCityEl    = document.getElementById('deliveryCity');
    const priceHint  = document.getElementById('travelPriceHint');
    const btnApply   = document.getElementById('btnApplyTravelCalc');
    const routeCache = {};
    let routeReqSeq  = 0;

    function getExtraCountExcluding(el, excludeVal) {
        if (!el) return 0;
        const ex = excludeVal ? String(excludeVal) : '';
        if (el.tomselect) {
            const v = el.tomselect.getValue();
            const arr = Array.isArray(v) ? v : (v ? [v] : []);
            return arr.filter(x => x && String(x) !== ex).length;
        }
        let cnt = 0;
        if (el.selectedOptions) {
            for (let i = 0; i < el.selectedOptions.length; i++) {
                const val = el.selectedOptions[i].value;
                if (val && String(val) !== ex) cnt++;
            }
        }
        return cnt;
    }

    function renderRouteEstimate(matchedRoute, forceApply) {
        if (!priceHint) return;
        if (!matchedRoute) {
            priceHint.textContent = 'Tidak ditemukan tarif rute aktif untuk pasangan kota ini.';
            priceHint.className = 'form-text text-muted small mt-1';
            priceHint.style.display = 'block';
            if (btnApply) btnApply.style.display = 'none';
            return;
        }
        const primVal   = primPaxSel ? (primPaxSel.tomselect ? primPaxSel.tomselect.getValue() : primPaxSel.value) : '';
        const passCount = Math.max(1, (primVal ? 1 : 0) + getExtraCountExcluding(extraPaxSel, primVal));
        const unitPrice = parseFloat(matchedRoute.price) || 0;
        const estTotal  = Math.round(passCount * unitPrice);

        priceHint.className = 'form-text small mt-1';
        priceHint.innerHTML = `<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Estimasi (${escHtml(matchedRoute.name)}): ${passCount} penumpang &times; Rp ${escHtml(fmtRp(Math.round(unitPrice)))} = Rp ${escHtml(fmtRp(estTotal))}</span>`;
        priceHint.style.display = 'block';
        if (btnApply) btnApply.style.display = 'inline-block';

        if (forceApply && priceValEl && priceDispEl && !priceDispEl.readOnly) {
            priceValEl.value = String(estTotal);
            priceDispEl.value = fmtRp(estTotal);
        }
    }

    function calculateTravelPrice(forceApply) {
        if (!pCityEl || !dCityEl || !btnApply) return;
        const pcVal  = parseInt(pCityEl.tomselect ? pCityEl.tomselect.getValue() : pCityEl.value, 10) || 0;
        const dcVal  = parseInt(dCityEl.tomselect ? dCityEl.tomselect.getValue() : dCityEl.value, 10) || 0;
        const catVal = catSelect ? (parseInt(catSelect.tomselect ? catSelect.tomselect.getValue() : catSelect.value, 10) || 0) : 0;
        if (!pcVal || !dcVal) {
            if (priceHint) priceHint.style.display = 'none';
            if (btnApply) btnApply.style.display = 'none';
            return;
        }
        const cacheKey = `${pcVal}-${dcVal}-${catVal}`;
        if (Object.prototype.hasOwnProperty.call(routeCache, cacheKey)) {
            renderRouteEstimate(routeCache[cacheKey], forceApply);
            return;
        }
        const reqId = ++routeReqSeq;
        const u = `${appUrl}/pages/travel/rutes/lookup.php?company_id=${bookingCid}&pickup_point_city_id=${pcVal}&delivery_point_city_id=${dcVal}`
                + (catVal > 0 ? `&category_vehicle_id=${catVal}` : '');
        fetch(u, { headers: { Accept: 'application/json' } })
            .then(r => (r.ok ? r.json() : null))
            .then(res => {
                const matched = (res && res.ok) ? (res.route || null) : null;
                routeCache[cacheKey] = matched;
                if (reqId !== routeReqSeq) return;
                renderRouteEstimate(matched, forceApply);
            })
            .catch(() => {});
    }

    if (btnApply) {
        btnApply.addEventListener('click', (e) => {
            e.preventDefault();
            calculateTravelPrice(true);
        });
    }

    // Cascading Provinsi -> Kota/Kab -> Kecamatan
    async function reloadCityOptions(provVal, cityEl, distEl) {
        if (!cityEl || !window.setTsOptions) return;
        if (distEl) window.setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
        try {
            const qs = provVal ? `limit=100&province_id=${encodeURIComponent(provVal)}` : 'limit=50&all=1';
            const res = await fetch(`${appUrl}/pages/masters/cities/options.php?${qs}`);
            const data = await res.json();
            window.setTsOptions(cityEl, data, '', '-- Pilih Kota/Kab --');
        } catch (e) {
            console.error('Gagal memuat kota:', e);
        }
    }

    async function reloadDistrictOptions(cityVal, distEl) {
        if (!distEl || !window.setTsOptions) return;
        if (!cityVal) {
            window.setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
            return;
        }
        try {
            const res = await fetch(`${appUrl}/pages/masters/districts/options.php?limit=100&city_id=${encodeURIComponent(cityVal)}`);
            const data = await res.json();
            window.setTsOptions(distEl, data, '', '-- Pilih Kecamatan --');
        } catch (e) {
            console.error('Gagal memuat kecamatan:', e);
        }
    }

    function bindProvinceCityDistrict(provId, cityId, distId) {
        const pSel = document.getElementById(provId);
        const cSel = document.getElementById(cityId);
        const dSel = document.getElementById(distId);
        if (!pSel || !cSel || !dSel || !window.bindTsChange) return;
        window.bindTsChange(pSel, (val) => {
            reloadCityOptions(val, cSel, dSel).then(() => calculateTravelPrice(false));
        });
        window.bindTsChange(cSel, (val) => {
            const opt = cSel.tomselect ? cSel.tomselect.options[String(val)] : null;
            const curProv = pSel.tomselect ? String(pSel.tomselect.getValue() || '') : String(pSel.value || '');
            if (!curProv && opt && opt.province_id && window.setTsValueSilent) {
                window.setTsValueSilent(pSel, String(opt.province_id));
            }
            reloadDistrictOptions(val, dSel);
            calculateTravelPrice(false);
        });
    }
    bindProvinceCityDistrict('pickupProvince', 'pickupCity', 'pickupDistrict');
    bindProvinceCityDistrict('deliveryProvince', 'deliveryCity', 'deliveryDistrict');

    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(primPaxSel, extraPaxSel, () => {
            updateTravelSeatWarn();
            calculateTravelPrice(false);
        });
    }
    updateTravelSeatWarn();
    calculateTravelPrice(false);

    // Quick Add Pelanggan via Modal
    const qpForm  = document.getElementById('quickPassengerForm');
    const qpError = document.getElementById('qpError');
    const qpBtn   = document.getElementById('qpSubmitBtn');
    if (qpForm) {
        qpForm.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (qpError) qpError.classList.add('d-none');
            if (qpBtn) qpBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfTok);
                fd.append('name', (document.getElementById('qpName')?.value || '').trim());
                fd.append('phone_number', (document.getElementById('qpPhone')?.value || '').trim());
                fd.append('email', (document.getElementById('qpEmail')?.value || '').trim());

                const res = await fetch(`${appUrl}/pages/travel/passengers/quick_create.php`, { method: 'POST', body: fd });
                const json = await res.json();
                if (!res.ok || !json.ok) {
                    throw new Error((json.error && json.error.message) || json.error || 'Gagal menyimpan pelanggan.');
                }
                const p = json.data;
                const label = p.phone_number ? `${p.name} - ${p.phone_number}` : p.name;

                if (extraPaxSel) {
                    if (extraPaxSel.tomselect) {
                        extraPaxSel.tomselect.addOption({ value: String(p.id), text: label });
                        extraPaxSel.tomselect.removeItem(String(p.id), true);
                        if (window.syncTsLastVal) window.syncTsLastVal(extraPaxSel);
                    } else {
                        extraPaxSel.add(new Option(label, String(p.id), false, false));
                    }
                }
                if (primPaxSel) {
                    if (primPaxSel.tomselect) {
                        primPaxSel.tomselect.addOption({ value: String(p.id), text: label });
                        primPaxSel.tomselect.setValue(String(p.id));
                        if (window.syncTsLastVal) window.syncTsLastVal(primPaxSel);
                    } else {
                        primPaxSel.add(new Option(label, String(p.id), true, true));
                    }
                }
                updateTravelSeatWarn();
                calculateTravelPrice(false);

                qpForm.reset();
                const modalEl = document.getElementById('quickPassengerModal');
                const modal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
                if (modalEl && modal) {
                    if (modal._isTransitioning) {
                        modalEl.addEventListener('shown.bs.modal', () => modal.hide(), { once: true });
                    } else {
                        modal.hide();
                    }
                }
            } catch (err) {
                if (qpError) {
                    qpError.textContent = err.message;
                    qpError.classList.remove('d-none');
                }
            } finally {
                if (qpBtn) qpBtn.disabled = false;
            }
        });
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
