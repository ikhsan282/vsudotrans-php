<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/dispatch_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/azure_blob.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b  = booking_find('travel', $id);
if (!$b) {
    flash('error', 'Pemesanan tiket perjalanan tidak ditemukan.');
    redirect('pages/travel/bookings/index.php');
}
assert_company_access((int) $b['company_id']);
$booking_cid     = (int) $b['company_id'];
$b['company_id'] = $booking_cid;
$canManage       = can('travel.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengubah pemesanan tiket perjalanan.');
        redirect('pages/travel/bookings/detail.php?id=' . $id);
    }
    verify_csrf();
    $act       = (string) ($_POST['act'] ?? '');
    $user      = $_SESSION['username'] ?? null;
    $targetTab = trim((string) ($_POST['active_tab'] ?? 'detail'));
    $allowedTabs = ['detail', 'crew', 'payment', 'note', 'audit'];
    $hash      = in_array($targetTab, $allowedTabs, true) ? ('#tab-' . $targetTab) : '#tab-detail';

    try {
        if ($act === 'delete') {
            $docNum = booking_delete('travel', $id, $booking_cid);
            audit('booking.travel.delete', "Pemesanan #{$id} ({$docNum})");
            flash('success', "Pemesanan tiket perjalanan {$docNum} berhasil dihapus.");
            redirect('pages/travel/bookings/index.php');
        } elseif ($act === 'status') {
            $to   = (string) ($_POST['to_status'] ?? '');
            $note = trim((string) ($_POST['reason'] ?? ''));
            booking_change_status('travel', $id, $booking_cid, $to, $note === '' ? 'Ubah status' : $note, $user);
            audit('booking.travel.status', "Pemesanan #{$id} -> {$to}");
            flash('success', 'Status pemesanan tiket perjalanan diperbarui.');
        } elseif ($act === 'cancel') {
            $note = trim((string) ($_POST['reason'] ?? ''));
            booking_change_status('travel', $id, $booking_cid, 'cancelled', $note === '' ? null : $note, $user);
            audit('booking.travel.cancel', "Pemesanan #{$id}");
            flash('success', 'Pemesanan tiket perjalanan dibatalkan.');
            $hash = '#tab-audit';
        } elseif ($act === 'reschedule') {
            $date = trim((string) ($_POST['date'] ?? ''));
            $time = trim((string) ($_POST['time'] ?? ''));
            $note = trim((string) ($_POST['reason'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Tanggal & jam reschedule tidak valid.');
            }
            booking_reschedule('travel', $id, $booking_cid, $date, $time . ':00', $note === '' ? 'Reschedule' : $note, $user);
            audit('booking.travel.reschedule', "Pemesanan #{$id} -> {$date} {$time}");
            flash('success', 'Pemesanan dijadwalkan ulang.');
        } elseif ($act === 'driver') {
            $emp = (int) ($_POST['employee_id'] ?? 0);
            $veh = (int) ($_POST['vehicle_id'] ?? 0);
            $catCheck = null;
            $vehPtr   = $veh > 0 ? $veh : null;
            validate_booking_company_scope($booking_cid, (int) $b['branch_id'], (int) $b['passenger_id'], [], $catCheck, $vehPtr, $emp > 0 ? [$emp] : []);
            booking_assign_driver('travel', $id, $booking_cid, $emp, $veh > 0 ? $veh : null, $user);
            audit('booking.travel.driver', "Pemesanan #{$id} driver #{$emp}");
            flash('success', 'Driver berhasil ditugaskan.');
            $hash = '#tab-crew';
        } elseif ($act === 'pay_manual') {
            $curBkStatus = booking_current_status('travel', $id);
            if ($curBkStatus === 'cancelled') {
                throw new RuntimeException('Pemesanan sudah dibatalkan — tidak bisa menerima pembayaran.');
            }
            $payAmt     = parse_rupiah_input($_POST['pay_amount'] ?? ($_POST['amount'] ?? 0));
            $payType    = trim((string) ($_POST['pay_type'] ?? 'Pelunasan'));
            $payMethod  = trim((string) ($_POST['pay_method'] ?? 'Transfer'));
            $methodId   = (int) ($_POST['payment_method_id'] ?? 0);
            $cashAccId  = (int) ($_POST['cash_account_id'] ?? 0);
            $payNote    = trim((string) ($_POST['pay_note'] ?? ''));
            if ($payAmt <= 0) {
                throw new InvalidArgumentException('Nominal pembayaran harus lebih dari Rp 0.');
            }

            $stInvId = db()->prepare("SELECT id FROM invoices WHERE booking_type = 'travel' AND booking_id = ? AND company_id = ? LIMIT 1");
            $stInvId->bind_param('ii', $id, $booking_cid);
            $stInvId->execute();
            $invIdRow = $stInvId->get_result()->fetch_assoc();
            $stInvId->close();
            $invId = $invIdRow ? (int) $invIdRow['id'] : 0;

            if ($invId <= 0) {
                $invId = invoice_issue(
                    $booking_cid,
                    'travel',
                    $id,
                    !empty($b['branch_id']) ? (int) $b['branch_id'] : null,
                    (string) ($b['document_number'] ?? ('TRV-' . $id)),
                    (float) $b['total_price'],
                    $user
                );
            }

            $methodName = $payMethod !== '' ? $payMethod : 'Transfer';
            if ($methodId > 0) {
                $stPm = db()->prepare('SELECT name FROM payment_methods WHERE id = ? AND is_active = 1 AND (company_id = ? OR company_id IS NULL) LIMIT 1');
                $stPm->bind_param('ii', $methodId, $booking_cid);
                $stPm->execute();
                $pmRow = $stPm->get_result()->fetch_assoc();
                $stPm->close();
                if ($pmRow) {
                    $methodName = (string) $pmRow['name'];
                }
            }

            $proofPath = null;
            if (!empty($_FILES['pay_proof']['name'])) {
                $proofPath = azure_blob_handle_upload(
                    'pay_proof',
                    $booking_cid,
                    'finance/payment-proofs',
                    date('Y/m'),
                    'inv-' . $invId
                );
            }

            $fullNote = trim($payType . ' | ' . $methodName . ($payNote !== '' ? ' - ' . $payNote : ''));
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
            audit('booking.travel.pay_manual', "Pemesanan #{$id} INV#{$invId} nominal {$payAmt} ({$payRes['status']})");
            flash('success', 'Pembayaran manual sebesar ' . rupiah($payAmt) . ' berhasil dicatat.');
            $hash = '#tab-payment';
        } elseif ($act === 'update_detail') {
            if (booking_current_status('travel', $id) === 'cancelled') {
                throw new RuntimeException('Pemesanan yang sudah dibatalkan tidak dapat diubah detailnya.');
            }
            $branch_id           = (int) ($_POST['branch_id'] ?? 0);
            $date                = trim((string) ($_POST['date'] ?? ''));
            $time                = substr(trim((string) ($_POST['time'] ?? '')), 0, 5);
            $total_price         = parse_rupiah_input($_POST['total_price'] ?? 0);
            $category_vehicle_id = (int) ($_POST['category_vehicle_id'] ?? 0);
            $vehicle_id          = (int) ($_POST['vehicle_id'] ?? 0);
            $passenger_id        = (int) ($_POST['passenger_id'] ?? 0);
            $extra_pids          = array_values(array_unique(array_filter(
                array_map('intval', (array) ($_POST['passenger_ids'] ?? [])),
                static fn($v) => $v > 0 && $v !== $passenger_id
            )));

            $pk_prov_in = (int) ($_POST['pickup_point_province_id'] ?? 0);
            $pk_city_in = (int) ($_POST['pickup_point_city_id'] ?? 0);
            $pk_dist_in = resolve_district_id($_POST['pickup_point_district_id'] ?? 0);
            $pk_addr    = trim((string) ($_POST['pickup_address'] ?? ''));

            $dl_prov_in = (int) ($_POST['delivery_point_province_id'] ?? 0);
            $dl_city_in = (int) ($_POST['delivery_point_city_id'] ?? 0);
            $dl_dist_in = resolve_district_id($_POST['delivery_point_district_id'] ?? 0);
            $dl_addr    = trim((string) ($_POST['delivery_address'] ?? ''));

            $note = trim((string) ($_POST['note'] ?? ''));

            $crew_sync_present = !empty($_POST['crew_sync_present']);
            $pay_sync_present  = !empty($_POST['pay_sync_present']);
            $bbm               = 0.0;
            $crew_rows         = $crew_sync_present ? booking_parse_crew_post($_POST) : [];
            $pay_rows          = $pay_sync_present  ? booking_parse_payments_post($_POST) : [];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException('Tanggal keberangkatan tidak valid.');
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Jam keberangkatan tidak valid.');
            }
            if ($branch_id <= 0 || $passenger_id <= 0) {
                throw new InvalidArgumentException('Cabang dan pelanggan utama wajib dipilih.');
            }
            if ($pk_dist_in <= 0 || $dl_dist_in <= 0) {
                throw new InvalidArgumentException('Kecamatan penjemputan dan pengantaran wajib dipilih.');
            }
            if ($total_price < 0) {
                throw new InvalidArgumentException('Harga pemesanan tidak boleh negatif.');
            }

            $cat_id_post = $category_vehicle_id > 0 ? $category_vehicle_id : null;
            $veh_id_post = $vehicle_id > 0 ? $vehicle_id : null;
            $crewEmpIds  = $crew_sync_present
                ? array_values(array_filter(array_map('intval', array_column($crew_rows, 'employee_id'))))
                : [];
            validate_booking_company_scope(
                $booking_cid,
                $branch_id,
                $passenger_id,
                $extra_pids,
                $cat_id_post,
                $veh_id_post,
                $crewEmpIds
            );
            $category_vehicle_id = $cat_id_post ?? 0;

            $pk_info = resolve_district_info($pk_dist_in) ?: [];
            $dl_info = resolve_district_info($dl_dist_in) ?: [];
            $pk_city = $pk_city_in > 0 ? $pk_city_in : (int) ($pk_info['city_id'] ?? 0);
            $pk_prov = $pk_prov_in > 0 ? $pk_prov_in : (int) ($pk_info['province_id'] ?? 0);
            $dl_city = $dl_city_in > 0 ? $dl_city_in : (int) ($dl_info['city_id'] ?? 0);
            $dl_prov = $dl_prov_in > 0 ? $dl_prov_in : (int) ($dl_info['province_id'] ?? 0);

            $db = db();
            $db->begin_transaction();
            try {
                $timeFull    = $time . ':00';
                $catIdOrNull = $category_vehicle_id > 0 ? $category_vehicle_id : null;
                $vehIdOrNull = $vehicle_id > 0 ? $vehicle_id : null;
                $pkProvNull  = $pk_prov > 0 ? $pk_prov : null;
                $pkCityNull  = $pk_city > 0 ? $pk_city : null;
                $dlProvNull  = $dl_prov > 0 ? $dl_prov : null;
                $dlCityNull  = $dl_city > 0 ? $dl_city : null;

                $up = $db->prepare('UPDATE travel_ticket_bookings
                    SET branch_id = ?, `date` = ?, `time` = ?,
                        pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                        delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                        total_price = ?, category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                        modified_user = ?, modified_date = NOW()
                    WHERE id = ? AND company_id = ?');
                $up->bind_param(
                    'isssiiisiiidiiissii',
                    $branch_id, $date, $timeFull,
                    $pk_addr, $pkProvNull, $pkCityNull, $pk_dist_in,
                    $dl_addr, $dlProvNull, $dlCityNull, $dl_dist_in,
                    $total_price, $catIdOrNull, $vehIdOrNull, $passenger_id, $note,
                    $user, $id, $booking_cid
                );
                $up->execute();
                $up->close();

                booking_passengers_set('travel', $id, $booking_cid, array_merge([$passenger_id], $extra_pids), $passenger_id, $user);

                $stInvUp = $db->prepare("SELECT id, status, tax_percent FROM invoices WHERE booking_type = 'travel' AND booking_id = ? AND company_id = ? LIMIT 1");
                $stInvUp->bind_param('ii', $id, $booking_cid);
                $stInvUp->execute();
                $invUp = $stInvUp->get_result()->fetch_assoc();
                $stInvUp->close();

                if ($invUp && ($invUp['status'] ?? '') !== 'cancelled') {
                    $invIdUp = (int) $invUp['id'];
                    $taxPct  = (float) ($invUp['tax_percent'] ?? 0);
                    $taxAmt  = round($total_price * ($taxPct / 100), 2);
                    $newTot  = round($total_price + $taxAmt, 2);

                    if ($pay_sync_present) {
                        $qrisLocked = 0.0;
                        foreach (invoice_payments($invIdUp) as $exP) {
                            if (!empty($exP['is_auto_qris'])) {
                                $qrisLocked += max(0.0, (float) ($exP['amount'] ?? 0));
                            }
                        }
                        if ($newTot + 0.01 < $qrisLocked) {
                            throw new InvalidArgumentException('Total harga tidak dapat diturunkan di bawah pembayaran QRIS otomatis yang sudah tercatat (' . rupiah($qrisLocked) . ').');
                        }
                        $upInvPre = $db->prepare('UPDATE invoices SET branch_id = ?, subtotal = ?, tax_amount = ?, total = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                        $upInvPre->bind_param('idddsi', $branch_id, $total_price, $taxAmt, $newTot, $user, $invIdUp);
                        $upInvPre->execute();
                        $upInvPre->close();

                        booking_upload_payment_proofs($pay_rows, $booking_cid, $invIdUp);
                        $finalPaid = booking_payments_sync('travel', $id, $invIdUp, $booking_cid, $pay_rows, $user);
                        $newInvSt  = ($finalPaid > 0.001 && $finalPaid + 0.001 >= $newTot)
                            ? 'paid'
                            : ($finalPaid > 0.001 ? 'partial' : 'issued');
                        $upInvSt = $db->prepare('UPDATE invoices SET status = ? WHERE id = ?');
                        $upInvSt->bind_param('si', $newInvSt, $invIdUp);
                        $upInvSt->execute();
                        $upInvSt->close();
                    } else {
                        $paid = invoice_paid_total($invIdUp);
                        if ($newTot + 0.01 < $paid) {
                            throw new InvalidArgumentException('Total harga tidak dapat lebih rendah dari total pembayaran yang sudah dibayar (' . rupiah($paid) . ').');
                        }
                        $newInvSt = ($paid + 0.01 >= $newTot && $paid > 0) ? 'paid' : ($paid > 0.01 ? 'partial' : 'issued');
                        $upInv = $db->prepare('UPDATE invoices SET branch_id = ?, subtotal = ?, tax_amount = ?, total = ?, status = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                        $upInv->bind_param('idddssi', $branch_id, $total_price, $taxAmt, $newTot, $newInvSt, $user, $invIdUp);
                        $upInv->execute();
                        $upInv->close();
                    }
                } elseif ($pay_sync_present && $pay_rows) {
                    $newInvId = invoice_issue(
                        $booking_cid,
                        'travel',
                        $id,
                        $branch_id > 0 ? $branch_id : null,
                        (string) ($b['document_number'] ?? ('TRV-' . $id)),
                        $total_price,
                        $user
                    );
                    booking_upload_payment_proofs($pay_rows, $booking_cid, $newInvId);
                    $finalPaid = booking_payments_sync('travel', $id, $newInvId, $booking_cid, $pay_rows, $user);
                    $newInvSt  = ($finalPaid > 0.001 && $finalPaid + 0.001 >= $total_price)
                        ? 'paid'
                        : ($finalPaid > 0.001 ? 'partial' : 'issued');
                    $upInvSt = $db->prepare('UPDATE invoices SET status = ? WHERE id = ?');
                    $upInvSt->bind_param('si', $newInvSt, $newInvId);
                    $upInvSt->execute();
                    $upInvSt->close();
                }

                if ($crew_sync_present) {
                    booking_crew_sync('travel', $id, $booking_cid, $bbm, $crew_rows, $vehIdOrNull, $user);
                }

                $db->commit();
                audit('booking.travel.update_detail', "Pemesanan #{$id} {$b['document_number']}");
                flash('success', 'Perubahan pemesanan tiket perjalanan berhasil disimpan.');
            } catch (Throwable $txEx) {
                $db->rollback();
                throw $txEx;
            }
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('pages/travel/bookings/detail.php?id=' . $id . $hash);
}

$status = booking_current_status('travel', $id);
$next   = BOOKING_TRANSITIONS[$status] ?? [];

$db  = db();
$bt  = 'travel';
$stH = $db->prepare('SELECT h.from_status, h.to_status, h.reason, h.changed_by, h.changed_at
    FROM booking_status_history h WHERE h.booking_type = ? AND h.booking_id = ? ORDER BY h.id DESC LIMIT 30');
$stH->bind_param('si', $bt, $id);
$stH->execute();
$hist = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
$stH->close();

$stA = $db->prepare('SELECT t.id, t.employee_id, t.vehicle_id, e.name AS driver_name, e.phone_number AS driver_phone
    FROM trip_assignments t LEFT JOIN employees e ON e.id = t.employee_id
    WHERE t.booking_type = ? AND t.booking_id = ? AND t.ended_at IS NULL LIMIT 1');
$stA->bind_param('si', $bt, $id);
$stA->execute();
$activeAssign = $stA->get_result()->fetch_assoc();
$stA->close();

$paxList          = booking_passengers_list('travel', $id, (int) $b['passenger_id']);
$extra_passengers = [];
$extra_p_ids      = [];
foreach ($paxList as $px) {
    $pidRow = (int) ($px['passenger_id'] ?? $px['id'] ?? 0);
    if ($pidRow > 0 && $pidRow !== (int) $b['passenger_id']) {
        $extra_passengers[] = $px;
        $extra_p_ids[]      = $pidRow;
    }
}
$selected_p_ids = array_values(array_unique(array_merge([(int) $b['passenger_id']], $extra_p_ids)));

$stComp = $db->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $booking_cid);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $booking_cid, 'label' => active_company_name()];
$stComp->close();

$assignedEmpId = (int) ($activeAssign['employee_id'] ?? 0);
$displayVehId  = (int) ($b['vehicle_id'] ?? ($activeAssign['vehicle_id'] ?? 0));

$cur_veh = null;
if ($displayVehId > 0) {
    $stVeh = $db->prepare("SELECT v.*, bv.name AS brand_name, mu.name AS model_name, cv.name AS category_name
        FROM vehicles v
        LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
        LEFT JOIN model_units mu ON mu.id = v.model_unit_id
        LEFT JOIN category_vehicles cv ON cv.id = v.category_vehicle_id
        WHERE v.id = ? LIMIT 1");
    $stVeh->bind_param('i', $displayVehId);
    $stVeh->execute();
    $cur_veh = $stVeh->get_result()->fetch_assoc();
    $stVeh->close();
}

$cur_category_id = (int) ($b['category_vehicle_id'] ?? ($cur_veh['category_vehicle_id'] ?? 0));

$branches   = opts_branches($booking_cid);
$passengers = opts_passengers($booking_cid, $selected_p_ids, 50);
$cats       = opts_categories($booking_cid, $cur_category_id > 0 ? [$cur_category_id] : [], 200);
$vehicles   = opts_vehicles_detailed(
    $booking_cid,
    [$booking_cid],
    $displayVehId > 0 ? $displayVehId : null,
    $cur_category_id > 0 ? $cur_category_id : null,
    50
);

$crewExt   = booking_crew_extract('travel', $id, $b);
$bbm       = 0.0;
$crew_rows = $crewExt['crew_rows'];
if (!$crew_rows) {
    $crew_rows = [
        ['type' => 'Sopir',  'employee_id' => $assignedEmpId, 'amount' => 0],
        ['type' => 'Kernet', 'employee_id' => 0, 'amount' => 0],
    ];
}
$crewEmpIds          = array_values(array_unique(array_filter(array_merge([$assignedEmpId], array_map('intval', array_column($crew_rows, 'employee_id'))))));
$employees           = opts_employees($booking_cid, $crewEmpIds, 50);
$company_regulations = regulation_list_by_company($booking_cid);

$p_dist_id = resolve_district_id($b['pickup_point_district_id'] ?? 0);
$d_dist_id = resolve_district_id($b['delivery_point_district_id'] ?? 0);
$p_info    = resolve_district_info($p_dist_id);
$d_info    = resolve_district_info($d_dist_id);
$p_prov_id = (int) ($b['pickup_point_province_id'] ?? ($p_info['province_id'] ?? 0));
$p_city_id = (int) ($b['pickup_point_city_id'] ?? ($p_info['city_id'] ?? 0));
$d_prov_id = (int) ($b['delivery_point_province_id'] ?? ($d_info['province_id'] ?? 0));
$d_city_id = (int) ($b['delivery_point_city_id'] ?? ($d_info['city_id'] ?? 0));

$provinces          = opts_provinces([$p_prov_id, $d_prov_id], 100);
$pickup_cities      = opts_cities($p_prov_id > 0 ? $p_prov_id : null, $p_city_id > 0 ? [$p_city_id] : [], $p_prov_id > 0 ? 100 : 50);
$delivery_cities    = opts_cities($d_prov_id > 0 ? $d_prov_id : null, $d_city_id > 0 ? [$d_city_id] : [], $d_prov_id > 0 ? 100 : 50);
$pickup_districts   = opts_districts_by_city($p_city_id, $p_dist_id > 0 ? [$p_dist_id] : [], 100);
$delivery_districts = opts_districts_by_city($d_city_id, $d_dist_id > 0 ? [$d_dist_id] : [], 100);

$stInv = $db->prepare("SELECT * FROM invoices WHERE booking_type = 'travel' AND booking_id = ? LIMIT 1");
$stInv->bind_param('i', $id);
$stInv->execute();
$invRow = $stInv->get_result()->fetch_assoc();
$stInv->close();
if ($invRow) {
    $invRow['paid_total'] = invoice_paid_total((int) $invRow['id']);
    $invRow['pay_status'] = invoice_pay_status($invRow);
}

$invId        = $invRow ? (int) $invRow['id'] : 0;
$invDocNo     = $invRow ? (string) (!empty($invRow['invoice_no']) ? $invRow['invoice_no'] : ($invRow['document_number'] ?? ('#' . $invId))) : '';
$invTotal     = $invRow ? max(0.0, (float) $invRow['total']) : max(0.0, (float) $b['total_price']);
$paidTotal    = $invRow ? max(0.0, (float) ($invRow['paid_total'] ?? 0)) : max(0.0, (float) ($b['total_payment'] ?? 0));
$qrisRemaining = max(0.0, $invTotal - $paidTotal);
$payStatusNow  = $invRow ? (string) ($invRow['pay_status'] ?? 'unpaid') : 'unpaid';
$payBadgeHtml  = invoice_pay_badge($payStatusNow);

$payment_rows = $invId > 0 ? invoice_payments($invId) : [];
$qrisChannels = gateway_active_channels($booking_cid);
$hasPendingQris = false;
if ($invId > 0) {
    $stQChk = $db->prepare("SELECT 1 FROM payment_gateway_orders WHERE invoice_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    $stQChk->bind_param('i', $invId);
    $stQChk->execute();
    $hasPendingQris = (bool) $stQChk->get_result()->fetch_row();
    $stQChk->close();
}

$assignments = dispatch_by_booking('travel', $id);

$activeTab   = 'detail';
$active      = 'travel';
$title       = 'Pemesanan Tiket Perjalanan - ' . $b['document_number'];
$openQrisNow = !empty($_GET['open_qris']) && $canManage && $status !== 'cancelled' && $qrisRemaining > 0 && !empty($qrisChannels);
if ($openQrisNow) {
    $activeTab = 'payment';
}
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <!-- Top Action Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-ticket-perforated-fill me-1"></i>Pemesanan Tiket Perjalanan</span>
            <strong class="fs-6"><?= e($b['document_number']) ?></strong>
            <span id="bookingStatusBadgeWrap"><?= status_badge($status) ?></span>
            <span class="badge bg-info-subtle text-info-emphasis border" id="passengerCountHeaderBadge"><?= 1 + count($extra_passengers) ?> Penumpang</span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
            <?php if ($canManage && $status !== 'cancelled'): ?>
                <button type="submit" form="travelDetailForm" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-check2-circle me-1"></i>Simpan Perubahan
                </button>
            <?php endif; ?>
            <a class="btn btn-dark btn-sm" target="_blank" href="<?= e(APP_URL) ?>/pages/travel/bookings/tiket.php?no=<?= urlencode($b['document_number']) ?>&print=1">
                <i class="bi bi-printer me-1"></i>Cetak E-Tiket
            </a>
            <?php if ($canManage): ?>
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-1"></i>Tindakan
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($next): ?>
                            <li>
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#travelStatusModal">
                                    <i class="bi bi-arrow-repeat me-2 text-primary"></i>Ubah Status
                                </button>
                            </li>
                        <?php endif; ?>
                        <?php if ($status !== 'cancelled'): ?>
                            <li>
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#travelRescheduleModal">
                                    <i class="bi bi-calendar-event me-2 text-info"></i>Reschedule Jadwal
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#travelDriverModal">
                                    <i class="bi bi-person-badge me-2 text-success"></i>Tugaskan Driver Cepat
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item text-warning-emphasis" data-bs-toggle="modal" data-bs-target="#travelCancelModal">
                                    <i class="bi bi-slash-circle me-2 text-warning"></i>Batalkan Pemesanan
                                </button>
                            </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button type="button" class="dropdown-item text-danger" onclick="if (confirm('Hapus permanen pemesanan <?= e($b['document_number']) ?> beserta tagihan terkait?')) document.getElementById('travelDeleteForm').submit();">
                                <i class="bi bi-trash me-2"></i>Hapus Pemesanan
                            </button>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($status === 'cancelled'): ?>
        <div class="alert alert-secondary py-2 px-3 small mb-2">
            <i class="bi bi-info-circle me-1"></i>Pemesanan ini berstatus <strong>Dibatalkan</strong> (mode baca-saja).
        </div>
    <?php endif; ?>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- Sub-Tab Navigation (5 Tab ERP Seragam Rental) -->
        <ul class="nav nav-tabs erp-sub-tabs" id="travelDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'detail' ? ' active' : '' ?>" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'crew' ? ' active' : '' ?>" id="tab-crew-btn" data-bs-toggle="tab" data-bs-target="#tab-crew" type="button" role="tab">
                    Sopir/Kernet/Montir<?php if ($assignments): ?> <span class="badge bg-secondary-subtle text-dark border ms-1"><?= count($assignments) ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'payment' ? ' active' : '' ?>" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">Pembayaran Travel</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'note' ? ' active' : '' ?>" id="tab-note-btn" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">Keterangan</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'audit' ? ' active' : '' ?>" id="tab-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <form method="post" id="travelDetailForm" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="update_detail">
            <input type="hidden" name="active_tab" id="activeTabInput" value="<?= e($activeTab) ?>">

            <div class="tab-content" id="travelDetailTabContent">
                <!-- ================= TAB 1: DETAIL ================= -->
                <div class="tab-pane fade<?= $activeTab === 'detail' ? ' show active' : '' ?>" id="tab-detail" role="tabpanel" aria-labelledby="tab-detail-btn">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Informasi Pemesanan</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Resi</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm fw-bold bg-light" value="<?= e($b['document_number']) ?>" readonly style="max-width:300px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Perusahaan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm bg-light" disabled style="max-width:380px;">
                                        <option value="<?= (int) $curCompany['id'] ?>" selected><?= e($curCompany['label']) ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Cabang <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm" name="branch_id" form="travelDetailForm" style="max-width:380px;" <?= ($canManage && $status !== 'cancelled') ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Cabang --</option>
                                        <?= select_options($branches, $b['branch_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Tanggal Keberangkatan <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="date" id="travelDateInput" form="travelDetailForm" required value="<?= e($b['date']) ?>" placeholder="dd-MMM-yyyy" style="max-width:260px;" <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Jam <span class="text-danger">*</span></label>
                                <div class="rnt-field d-flex align-items-center gap-2">
                                    <input type="time" class="form-control form-control-sm" name="time" id="travelTimeInput" form="travelDetailForm" required value="<?= e(substr((string) $b['time'], 0, 5)) ?>" style="max-width:180px;" <?= ($canManage && $status !== 'cancelled') ? '' : 'readonly' ?>>
                                    <span id="travelTimePeriodBadge" class="badge bg-light text-dark border">--</span>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Harga <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <div class="input-group input-group-sm" style="max-width:260px;">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold text-end" id="totalPriceDisplay" value="<?= e(format_nominal((float) $b['total_price'])) ?>" placeholder="0" <?= ($canManage && $status !== 'cancelled') ? 'required' : 'readonly' ?>>
                                        </div>
                                        <input type="hidden" name="total_price" id="totalPriceValue" form="travelDetailForm" value="<?= (int) round((float) $b['total_price']) ?>">
                                        <?php if ($canManage && $status !== 'cancelled'): ?>
                                            <button type="button" id="btn_apply_route_price" class="btn btn-sm btn-outline-primary" style="display:none;">
                                                <i class="bi bi-magic me-1"></i>Terapkan Tarif Rute
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="travel_route_price_hint" class="form-text small mt-1" style="display:none;"></div>
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
                                <label class="rnt-label">Kategori</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="category_vehicle_id" id="categoryVehicleSelect" form="travelDetailForm" data-ts-placeholder="-- Semua Kategori --" <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                        <option value="">-- Semua Kategori --</option>
                                        <?= select_options($cats, $cur_category_id) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Kendaraan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="vehicle_id" id="vehicleSelect" form="travelDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-parent="#categoryVehicleSelect"
                                        data-ts-param="category_id"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Kendaraan --"
                                        <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                        <option value="">-- Pilih Kendaraan --</option>
                                        <?php foreach ($vehicles as $v):
                                            $sel = ((int) $v['id'] === $displayVehId) ? ' selected' : '';
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
                        </div>
                    </div>

                    <!-- Sub-panel Pelanggan & Penumpang -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <div class="d-flex align-items-center gap-2">
                                <span>Pelanggan &amp; Penumpang</span>
                                <span class="badge bg-primary-subtle text-primary border" id="passengerCountPanelBadge"><?= 1 + count($extra_passengers) ?> Penumpang</span>
                            </div>
                            <?php if ($canManage && $status !== 'cancelled'): ?>
                                <a href="#" class="text-primary text-decoration-none fw-normal small" data-bs-toggle="modal" data-bs-target="#quickPassengerModal">
                                    Tambah Pelanggan Baru
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Pelanggan Utama <span class="text-danger">*</span></label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_id" id="primaryPassengerSelect" form="travelDetailForm"
                                        data-ts-url="<?= e(APP_URL) ?>/pages/transport/passengers/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-limit="30"
                                        data-ts-placeholder="-- Pilih Pelanggan Utama --"
                                        <?= ($canManage && $status !== 'cancelled') ? 'required' : 'disabled' ?>>
                                        <option value="">-- Pilih Pelanggan Utama --</option>
                                        <?= select_options($passengers, $b['passenger_id']) ?>
                                    </select>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Penumpang Tambahan</label>
                                <div class="rnt-field">
                                    <select class="form-select form-select-sm ts-select" name="passenger_ids[]" id="extraPassengersSelect" form="travelDetailForm" multiple
                                        data-ts-url="<?= e(APP_URL) ?>/pages/transport/passengers/options.php"
                                        data-ts-company="<?= (int) $booking_cid ?>"
                                        data-ts-limit="30"
                                        placeholder="Pilih penumpang tambahan (opsional)..." <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                        <?= select_options($passengers, $extra_p_ids) ?>
                                    </select>
                                    <div class="form-text small">Penumpang tambahan ditampilkan pada E-Tiket &amp; diperhitungkan ke kapasitas kursi.</div>
                                    <div id="seatCapacityWarning" class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" role="alert"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-panel Penjemputan & Pengantaran (2 Kolom) -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Penjemputan</span></div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <label class="rnt-label">Provinsi</label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_province_id" id="pickupProvince" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $p_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kota/Kab <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_city_id" id="pickupCity" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#pickupProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($pickup_cities as $c):
                                                    $sel = ((int) $c['id'] === $p_city_id) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="pickup_point_district_id" id="pickupDistrict" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#pickupCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="100"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= ($canManage && $status !== 'cancelled') ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?php foreach ($pickup_districts as $d):
                                                    $sel = ((int) $d['id'] === $p_dist_id) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $d['id'] ?>" data-city="<?= (int) $d['city_id'] ?>"<?= $sel ?>><?= e($d['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Detail Alamat</label>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="pickup_address" form="travelDetailForm" rows="4" maxlength="2000" <?= ($canManage && $status !== 'cancelled') ? '' : 'readonly' ?>><?= e($b['pickup_address'] ?? '') ?></textarea>
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
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_province_id" id="deliveryProvince" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                data-ts-with-code="1"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Provinsi --"
                                                <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Provinsi --</option>
                                                <?= select_options($provinces, $d_prov_id) ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kota/Kab <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_city_id" id="deliveryCity" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                data-ts-parent="#deliveryProvince"
                                                data-ts-param="province_id"
                                                data-ts-limit="50"
                                                data-ts-placeholder="-- Pilih Kota/Kab --"
                                                <?= ($canManage && $status !== 'cancelled') ? '' : 'disabled' ?>>
                                                <option value="">-- Pilih Kota/Kab --</option>
                                                <?php foreach ($delivery_cities as $c):
                                                    $sel = ((int) $c['id'] === $d_city_id) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) $c['province_id'] ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <label class="rnt-label">Kecamatan <span class="text-danger">*</span></label>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="delivery_point_district_id" id="deliveryDistrict" form="travelDetailForm"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/masters/districts/options.php"
                                                data-ts-parent="#deliveryCity"
                                                data-ts-param="city_id"
                                                data-ts-require-parent="1"
                                                data-ts-limit="100"
                                                data-ts-placeholder="-- Pilih Kecamatan --"
                                                <?= ($canManage && $status !== 'cancelled') ? 'required' : 'disabled' ?>>
                                                <option value="">-- Pilih Kecamatan --</option>
                                                <?php foreach ($delivery_districts as $d):
                                                    $sel = ((int) $d['id'] === $d_dist_id) ? ' selected' : '';
                                                ?>
                                                    <option value="<?= (int) $d['id'] ?>" data-city="<?= (int) $d['city_id'] ?>"<?= $sel ?>><?= e($d['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Detail Alamat</label>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm" name="delivery_address" form="travelDetailForm" rows="4" maxlength="2000" <?= ($canManage && $status !== 'cancelled') ? '' : 'readonly' ?>><?= e($b['delivery_address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 2: SOPIR/KERNET/MONTIR ================= -->
                <?php
                $formId      = 'travelDetailForm';
                $companyId   = $booking_cid;
                $canManageTab = $canManage && $status !== 'cancelled';
                $origCanManage = $canManage;
                $canManage   = $canManageTab;
                $showBbm     = false;
                $bbm         = 0.0;
                $isDetailMode = true;
                $regulations = $company_regulations;
                $dispatchUrl = APP_URL . '/pages/dispatch/index.php?booking_type=travel&booking_id=' . $id;
                require __DIR__ . '/../../../includes/booking_crew_tab.php';
                $canManage   = $origCanManage;
                ?>

                <!-- ================= TAB 3: PEMBAYARAN TRAVEL ================= -->
                <?php
                $formId        = 'travelDetailForm';
                $canManage     = $canManage && $status !== 'cancelled';
                $payments      = $payment_rows;
                $totalPrice    = (float) $b['total_price'];
                $isDetailMode  = true;
                $invoiceId     = $invId;
                $invoiceNumber = $invDocNo;
                $invoiceTotal  = $invTotal;
                $bookingStatus = $status;
                $tabTitle      = 'Pembayaran Travel';
                require __DIR__ . '/../../../includes/booking_payment_tab.php';
                $canManage     = $origCanManage;
                ?>

                <!-- ================= TAB 4: KETERANGAN ================= -->
                <div class="tab-pane fade<?= $activeTab === 'note' ? ' show active' : '' ?>" id="tab-note" role="tabpanel" aria-labelledby="tab-note-btn">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Keterangan / Catatan Tambahan</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row align-items-start">
                                <label class="rnt-label pt-1">Catatan</label>
                                <div class="rnt-field">
                                    <textarea class="form-control form-control-sm" name="note" id="noteInput" form="travelDetailForm" rows="6" maxlength="2000" <?= ($canManage && $status !== 'cancelled') ? '' : 'readonly' ?>><?= e($b['note'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 5: AUDIT TRAIL ================= -->
                <div class="tab-pane fade<?= $activeTab === 'audit' ? ' show active' : '' ?>" id="tab-audit" role="tabpanel" aria-labelledby="tab-audit-btn">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Audit Trail Dokumen</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e($b['created_user'] ?? '-') ?>" readonly style="max-width:300px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['created_date']) ? e(date('d-M-Y H:i', strtotime((string) $b['created_date']))) : '-' ?>" readonly style="max-width:300px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e($b['modified_user'] ?? '-') ?>" readonly style="max-width:300px;">
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['modified_date']) ? e(date('d-M-Y H:i', strtotime((string) $b['modified_date']))) : '-' ?>" readonly style="max-width:300px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Riwayat Perubahan Status</span></div>
                        <div class="table-responsive">
                            <table class="erp-grid-table">
                                <thead>
                                    <tr>
                                        <th style="width:160px;">Waktu</th>
                                        <th style="width:130px;">Dari</th>
                                        <th style="width:130px;">Menjadi</th>
                                        <th>Alasan / Catatan</th>
                                        <th style="width:140px;">Oleh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($hist): ?>
                                        <?php foreach ($hist as $h): ?>
                                            <tr>
                                                <td><?= !empty($h['changed_at']) ? e(date('d-M-Y H:i', strtotime((string) $h['changed_at']))) : '-' ?></td>
                                                <td><?= !empty($h['from_status']) ? status_badge((string) $h['from_status']) : '<span class="text-muted">-</span>' ?></td>
                                                <td><?= status_badge((string) ($h['to_status'] ?? 'draft')) ?></td>
                                                <td><?= e($h['reason'] ?? '-') ?></td>
                                                <td><?= e($h['changed_by'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat status.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        </div><!-- /.erp-content-body -->
    </div><!-- /.erp-window-card -->
</main>

<!-- Modal Quick-Add Pelanggan Baru -->
<?php if ($canManage): ?>
<div class="modal fade" id="quickPassengerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold"><i class="bi bi-person-plus-fill me-1 text-primary"></i>Tambah Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formQuickPassenger">
                <div class="modal-body">
                    <div id="quickPassAlert" class="alert alert-danger py-2 d-none"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="qp_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">No. Telepon / WhatsApp</label>
                        <input type="text" class="form-control" id="qp_phone" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email (Opsional)</label>
                        <input type="email" class="form-control" id="qp_email">
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

<!-- Form Hapus Tersembunyi -->
<form method="post" id="travelDeleteForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="act" value="delete">
</form>

<!-- Modal Ubah Status -->
<?php if ($next): ?>
<div class="modal fade" id="travelStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="status">
            <input type="hidden" name="active_tab" value="audit">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold">Ubah Status Pemesanan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Status Baru</label>
                    <select class="form-select" name="to_status" required>
                        <?php foreach ($next as $stOpt): ?>
                            <option value="<?= e($stOpt) ?>"><?= e(ucfirst($stOpt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold">Catatan / Alasan</label>
                    <input type="text" class="form-control" name="reason" placeholder="Opsional...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Simpan Status</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Reschedule -->
<div class="modal fade" id="travelRescheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="reschedule">
            <input type="hidden" name="active_tab" value="detail">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold">Reschedule Jadwal Keberangkatan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Tanggal Baru <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="date" required value="<?= e($b['date']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Jam Baru <span class="text-danger">*</span></label>
                    <input type="time" class="form-control" name="time" required value="<?= e(substr((string) $b['time'], 0, 5)) ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold">Alasan Reschedule</label>
                    <input type="text" class="form-control" name="reason" placeholder="Permintaan penumpang / penyesuaian armada...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm btn-info text-white">Simpan Jadwal Baru</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tugaskan Driver Cepat -->
<div class="modal fade" id="travelDriverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="driver">
            <input type="hidden" name="active_tab" value="crew">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold">Tugaskan Driver &amp; Armada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Driver / Karyawan <span class="text-danger">*</span></label>
                    <select class="form-select ts-select" name="employee_id"
                        data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                        data-ts-company="<?= (int) $booking_cid ?>"
                        data-ts-limit="30"
                        required>
                        <option value="">-- Pilih Driver --</option>
                        <?php foreach ($employees as $em): ?>
                            <option value="<?= (int) $em['id'] ?>"<?= ((int) $em['id'] === $assignedEmpId) ? ' selected' : '' ?>><?= e($em['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold">Armada</label>
                    <select class="form-select ts-select" name="vehicle_id"
                        data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                        data-ts-company="<?= (int) $booking_cid ?>"
                        data-ts-limit="30">
                        <option value="">-- Pertahankan / Pilih Armada --</option>
                        <?php foreach ($vehicles as $v): ?>
                            <option value="<?= (int) $v['id'] ?>"<?= ((int) $v['id'] === $displayVehId) ? ' selected' : '' ?>><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-sm btn-success">Simpan Penugasan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Batalkan Pemesanan -->
<div class="modal fade" id="travelCancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="act" value="cancel">
            <input type="hidden" name="active_tab" value="audit">
            <div class="modal-header">
                <h5 class="modal-title fs-6 fw-semibold text-danger">Batalkan Pemesanan Tiket Perjalanan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Pembatalan akan mengubah status menjadi <strong>cancelled</strong> dan membatalkan invoice terkait.</p>
                <label class="form-label small fw-semibold">Alasan Pembatalan</label>
                <input type="text" class="form-control" name="reason" placeholder="Contoh: Penumpang membatalkan perjalanan...">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-sm btn-danger">Ya, Batalkan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/qris_modal.php'; ?>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
<script src="<?= e(APP_URL) ?>/assets/js/booking_erp.js?v=<?= @filemtime(__DIR__ . '/../../../assets/js/booking_erp.js') ?: time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const appUrl     = <?= json_encode(APP_URL) ?>;
    const bookingCid = <?= (int) $booking_cid ?>;
    const csrfToken  = <?= json_encode(csrf_token()) ?>;
    const canEdit    = <?= json_encode($canManage && $status !== 'cancelled') ?>;

    const travelDateEl  = document.getElementById('travelDateInput');
    const travelTimeEl  = document.getElementById('travelTimeInput');
    const travelTimeBdg = document.getElementById('travelTimePeriodBadge');
    const priceDispEl   = document.getElementById('totalPriceDisplay');
    const priceValEl    = document.getElementById('totalPriceValue');
    const catSelect     = document.getElementById('categoryVehicleSelect');
    const vehSelect     = document.getElementById('vehicleSelect');
    const primPaxSel    = document.getElementById('primaryPassengerSelect');
    const extraPaxSel   = document.getElementById('extraPassengersSelect');
    const seatWarnEl    = document.getElementById('seatCapacityWarning');

    const pProvEl = document.getElementById('pickupProvince');
    const pCityEl = document.getElementById('pickupCity');
    const pDistEl = document.getElementById('pickupDistrict');
    const dProvEl = document.getElementById('deliveryProvince');
    const dCityEl = document.getElementById('deliveryCity');
    const dDistEl = document.getElementById('deliveryDistrict');

    const priceHint = document.getElementById('travel_route_price_hint');
    const btnApply  = document.getElementById('btn_apply_route_price');

    if (travelDateEl && !travelDateEl.disabled && !travelDateEl._flatpickr && typeof flatpickr !== 'undefined') {
        flatpickr(travelDateEl, {
            altInput: true,
            altFormat: 'd-M-Y',
            dateFormat: 'Y-m-d',
            allowInput: false
        });
    }

    let qrisCtrl = null;
    BookingErp.initTabs({
        tabNavId: 'travelDetailTabs',
        activeTabInputId: 'activeTabInput',
        defaultTab: <?= json_encode($activeTab) ?>,
        onTabShown: function () {
            if (qrisCtrl) qrisCtrl.refreshBgPoller();
        }
    });

    BookingErp.bindTimeBadge(travelTimeEl, travelTimeBdg);

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
        formId: 'travelDetailForm',
        canManage: canEdit,
        isDetailMode: true,
        invoiceTotal: <?= (float) $invTotal ?>,
        origBookingPrice: <?= (float) $b['total_price'] ?>,
        taxPercent: <?= (float) ($invRow['tax_percent'] ?? 0) ?>,
        remainingDisplayEl: document.getElementById('qrisRemainingDisplay'),
        todayIso: <?= json_encode(date('Y-m-d')) ?>,
        getBillTotal: function () {
            return priceValEl ? BookingErp.parseRp(priceValEl.value) : <?= (int) round($invTotal) ?>;
        }
    });

    BookingErp.initCrewTab({
        formId: 'travelDetailForm',
        appUrl: appUrl,
        companyId: bookingCid,
        canManage: canEdit,
        showBbm: false,
        getCategoryId: function () {
            return catSelect ? (catSelect.tomselect ? catSelect.tomselect.getValue() : catSelect.value) : 0;
        },
        getTotalPrice: function () {
            return priceValEl ? BookingErp.parseRp(priceValEl.value) : 0;
        },
        getStartDate: function () {
            return travelDateEl ? travelDateEl.value : <?= json_encode($b['date']) ?>;
        },
        getTrip: function () { return 1; }
    });

    qrisCtrl = BookingErp.initQrisController({
        bookingId: <?= (int) $id ?>,
        bookingType: 'travel',
        appUrl: appUrl,
        csrfToken: csrfToken,
        canManage: <?= json_encode($canManage) ?>,
        invoiceTotal: <?= (float) $invTotal ?>,
        paidTotal: <?= (float) $paidTotal ?>,
        bookingStatus: <?= json_encode($status) ?>,
        hasPending: <?= json_encode($hasPendingQris) ?>,
        todayIso: <?= json_encode(date('Y-m-d')) ?>,
        paymentCtrl: payCtrl,
        getLivePrice: function () {
            return priceValEl ? BookingErp.parseRp(priceValEl.value) : <?= (float) $invTotal ?>;
        },
        onStatusBadge: function (badgeHtml) {
            const wrap = document.getElementById('bookingStatusBadgeWrap');
            if (wrap) wrap.innerHTML = badgeHtml;
        }
    });

    if (priceDispEl && priceValEl && !priceDispEl.readOnly && window.attachRupiahInput) {
        window.attachRupiahInput(priceDispEl, priceValEl, function () {
            payCtrl.recalcPayTotals();
        });
    }

    BookingErp.initFormTabValidation({
        formId: 'travelDetailForm',
        paymentCtrl: payCtrl,
        getBillTotal: function () {
            return priceValEl ? BookingErp.parseRp(priceValEl.value) : <?= (int) round($invTotal) ?>;
        }
    });

    const routeCtrl = BookingErp.initTravelRouteLookup({
        appUrl: appUrl,
        lookupUrl: appUrl + '/pages/travel/rutes/lookup.php',
        companyId: bookingCid,
        pickupCityEl: pCityEl,
        deliveryCityEl: dCityEl,
        categoryEl: catSelect,
        primaryPassengerEl: primPaxSel,
        extraPassengersEl: extraPaxSel,
        hintEl: priceHint,
        applyBtnEl: btnApply,
        priceDisplayEl: priceDispEl,
        priceValueEl: priceValEl,
        autoFillZero: false,
        notFoundText: 'Tidak ditemukan tarif rute aktif untuk pasangan kota ini.',
        onPriceApplied: function () { payCtrl.recalcPayTotals(); }
    });

    function syncPassengerHeaderCount() {
        const primVal = primPaxSel ? String(primPaxSel.tomselect ? primPaxSel.tomselect.getValue() : primPaxSel.value) : '';
        let extCnt = 0;
        if (extraPaxSel) {
            if (extraPaxSel.tomselect) {
                const v = extraPaxSel.tomselect.getValue();
                extCnt = (Array.isArray(v) ? v : (v ? [v] : [])).filter(x => x && String(x) !== primVal).length;
            } else {
                extCnt = Array.from(extraPaxSel.selectedOptions || []).filter(o => o.value && o.value !== primVal).length;
            }
        }
        const total = (primVal ? 1 : 0) + extCnt;
        ['passengerCountHeaderBadge', 'passengerCountPanelBadge'].forEach(function (bid) {
            const bEl = document.getElementById(bid);
            if (bEl) bEl.textContent = total + ' Penumpang';
        });
    }

    function syncCapacity() {
        syncPassengerHeaderCount();
        if (window.updateSeatCapacityWarning) {
            window.updateSeatCapacityWarning(vehSelect, primPaxSel, extraPaxSel, seatWarnEl);
        }
    }

    if (window.bindExclusivePrimaryExtra) {
        window.bindExclusivePrimaryExtra(primPaxSel, extraPaxSel, function () {
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
        companyId: bookingCid,
        categorySelectEl: catSelect,
        vehicleSelectEl: vehSelect,
        placeholder: '-- Pilih Kendaraan --',
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

    BookingErp.initQuickPassengerModal({
        formId: 'formQuickPassenger',
        modalId: 'quickPassengerModal',
        alertId: 'quickPassAlert',
        saveBtnId: 'btnSaveQuickPassenger',
        nameInputId: 'qp_name',
        phoneInputId: 'qp_phone',
        emailInputId: 'qp_email',
        primarySelectEl: primPaxSel,
        extraSelectEl: extraPaxSel,
        appUrl: appUrl,
        companyId: bookingCid,
        csrfToken: csrfToken
    });

    <?php if ($openQrisNow): ?>
    const btnAutoQrisOpen = document.getElementById('btnPayQrisAuto');
    if (btnAutoQrisOpen) {
        setTimeout(function () { btnAutoQrisOpen.click(); }, 180);
    }
    <?php endif; ?>
});
</script>
