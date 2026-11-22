<?php
/**
 * POST/PATCH /api/bot/staff_booking_update.php
 * Edit pesanan existing dari Grup WA Admin berdasarkan `document_number`, `booking_id`, atau `invoice_no`.
 * Mendukung ubah jadwal (tanggal/jam), jumlah kursi, alamat jemput/antar + geo, tarif + sinkron invoice,
 * tambah/ubah catatan, penugasan armada/sopir, ubah status, dan pencatatan pembayaran.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/bot_staff_booking.php';

$ctx     = bot_boot(['POST', 'PATCH']);
$db      = $ctx['db'];
$company = $ctx['company'];
$body    = $ctx['body'];
$cid     = (int) $company['id'];

$ref      = trim((string) ($body['document_number'] ?? ($body['invoice_no'] ?? ($body['booking_id'] ?? ''))));
$hintType = !empty($body['booking_type']) ? strtolower(trim((string) $body['booking_type'])) : null;
if ($ref === '') {
    bot_error(400, 'MISSING_REFERENCE', 'Sertakan document_number, booking_id, atau invoice_no pesanan yang ingin diubah.');
}

$bk = bot_staff_find_booking($db, $cid, $ref, $hintType);
if (!$bk) {
    bot_error(404, 'BOOKING_NOT_FOUND', "Pesanan '{$ref}' tidak ditemukan di perusahaan {$company['code']}.");
}

$bType     = (string) $bk['booking_type'];
$bid       = (int) $bk['id'];
$docNo     = (string) $bk['document_number'];
$table     = booking_table($bType);
$staffCtx  = bot_staff_require($ctx, [$bType === 'rental' ? 'rental.manage' : 'travel.manage']);
$auditUser = $staffCtx['audit_user'];

$changes = [];

// 1. Update field utama tabel booking
$newDate     = (string) $bk['date'];
$newTime     = (string) $bk['time'];
$newStart    = (string) ($bk['start_date'] ?? $bk['date']);
$newEnd      = (string) ($bk['end_date'] ?? $bk['date']);
$newTrip     = (float) ($bk['trip'] ?? 1);
$newBbm      = (float) ($bk['bbm'] ?? 0);
$newPkAddr   = (string) ($bk['pickup_address'] ?? '');
$newPkProv   = !empty($bk['pickup_point_province_id']) ? (int) $bk['pickup_point_province_id'] : null;
$newPkCity   = !empty($bk['pickup_point_city_id']) ? (int) $bk['pickup_point_city_id'] : null;
$newPkDist   = (int) $bk['pickup_point_district_id'];
$newDlAddr   = (string) ($bk['delivery_address'] ?? '');
$newDlProv   = !empty($bk['delivery_point_province_id']) ? (int) $bk['delivery_point_province_id'] : null;
$newDlCity   = !empty($bk['delivery_point_city_id']) ? (int) $bk['delivery_point_city_id'] : null;
$newDlDist   = (int) $bk['delivery_point_district_id'];
$newPrice    = (float) $bk['total_price'];
$newCatId    = !empty($bk['category_vehicle_id']) ? (int) $bk['category_vehicle_id'] : null;
$newVehId    = !empty($bk['vehicle_id']) ? (int) $bk['vehicle_id'] : null;
$newPaxId    = (int) $bk['passenger_id'];
$newNote     = (string) ($bk['note'] ?? '');

// Tanggal & Jam
if (isset($body['date']) && trim((string) $body['date']) !== '') {
    $d = bot_staff_parse_indonesian_date((string) $body['date']);
    if (!$d) {
        bot_error(400, 'INVALID_DATE', 'Format tanggal baru tidak valid.');
    }
    if ($d !== $newDate) {
        $changes[] = "Tanggal: {$newDate} → {$d}";
        $newDate   = $d;
        if ($bType === 'rental' && !isset($body['start_date'])) {
            $newStart = $d;
        }
    }
}
if ($bType === 'rental') {
    if (isset($body['start_date']) && trim((string) $body['start_date']) !== '') {
        $sd = bot_staff_parse_indonesian_date((string) $body['start_date']);
        if (!$sd) {
            bot_error(400, 'INVALID_DATE', 'Format start_date tidak valid.');
        }
        if ($sd !== $newStart) {
            $changes[] = "Mulai Sewa: {$newStart} → {$sd}";
            $newStart  = $sd;
            $newDate   = $sd;
        }
    }
    if (isset($body['end_date']) && trim((string) $body['end_date']) !== '') {
        $ed = bot_staff_parse_indonesian_date((string) $body['end_date']);
        if (!$ed) {
            bot_error(400, 'INVALID_DATE', 'Format end_date tidak valid.');
        }
        if ($ed < $newStart) {
            bot_error(422, 'INVALID_DATE_RANGE', 'Tanggal selesai sewa tidak boleh lebih awal dari tanggal mulai.');
        }
        if ($ed !== $newEnd) {
            $changes[] = "Selesai Sewa: {$newEnd} → {$ed}";
            $newEnd    = $ed;
        }
    }
    if (isset($body['trip']) && is_numeric($body['trip'])) {
        $tr = max(0.0, (float) $body['trip']);
        if (abs($tr - $newTrip) > 0.001) {
            $changes[] = "Trip: {$tr}";
            $newTrip   = $tr;
        }
    }
    if (isset($body['bbm']) && $body['bbm'] !== '') {
        $bbm = bot_staff_parse_rupiah($body['bbm']);
        if (abs($bbm - $newBbm) > 0.001) {
            $changes[] = 'BBM: ' . rupiah($bbm);
            $newBbm    = $bbm;
        }
    }
}

if (isset($body['time']) && trim((string) $body['time']) !== '') {
    $t = bot_staff_parse_time((string) $body['time']);
    if (!$t) {
        bot_error(400, 'INVALID_TIME', 'Format jam keberangkatan tidak valid.');
    }
    $tFull = $t . ':00';
    if (substr($tFull, 0, 5) !== substr($newTime, 0, 5)) {
        $changes[] = 'Jam: ' . substr($newTime, 0, 5) . " → {$t}";
        $newTime   = $tFull;
    }
}

// Alamat & wilayah jemput
if (isset($body['pickup_address']) && trim((string) $body['pickup_address']) !== '') {
    $pa = mb_substr(trim((string) $body['pickup_address']), 0, 2000);
    if ($pa !== $newPkAddr) {
        $changes[] = 'Alamat Jemput';
        $newPkAddr = $pa;
    }
    if (empty($body['pickup_district_id']) && empty($body['pickup_point_district_id'])) {
        $g = bot_staff_resolve_address_geo($db, $pa, $cid);
        if ($g['district_id'] > 0) {
            $newPkDist = $g['district_id'];
            $newPkCity = $g['city_id'] ?: $newPkCity;
            $newPkProv = $g['province_id'] ?: $newPkProv;
        }
    }
}
if (!empty($body['pickup_district_id']) || !empty($body['pickup_point_district_id'])) {
    $did = (int) ($body['pickup_district_id'] ?? $body['pickup_point_district_id']);
    if ($info = resolve_district_info($did)) {
        if ($did !== $newPkDist) {
            $changes[] = 'Kecamatan Jemput: ' . $info['name'];
        }
        $newPkDist = $info['id'];
        $newPkCity = $info['city_id'];
        $newPkProv = $info['province_id'];
    }
}

// Alamat & wilayah antar
if (isset($body['delivery_address']) && trim((string) $body['delivery_address']) !== '') {
    $da = mb_substr(trim((string) $body['delivery_address']), 0, 2000);
    if ($da !== $newDlAddr) {
        $changes[] = 'Alamat Tujuan';
        $newDlAddr = $da;
    }
    if (empty($body['delivery_district_id']) && empty($body['delivery_point_district_id'])) {
        $g = bot_staff_resolve_address_geo($db, $da, $cid);
        if ($g['district_id'] > 0) {
            $newDlDist = $g['district_id'];
            $newDlCity = $g['city_id'] ?: $newDlCity;
            $newDlProv = $g['province_id'] ?: $newDlProv;
        }
    }
}
if (!empty($body['delivery_district_id']) || !empty($body['delivery_point_district_id'])) {
    $did = (int) ($body['delivery_district_id'] ?? $body['delivery_point_district_id']);
    if ($info = resolve_district_info($did)) {
        if ($did !== $newDlDist) {
            $changes[] = 'Kecamatan Tujuan: ' . $info['name'];
        }
        $newDlDist = $info['id'];
        $newDlCity = $info['city_id'];
        $newDlProv = $info['province_id'];
    }
}

// Penumpang utama (ubah nama/HP atau ganti ke penumpang lain)
if (isset($body['passenger_name']) || isset($body['passenger_phone'])) {
    $pName  = trim((string) ($body['passenger_name'] ?? ($bk['passengers'][0]['name'] ?? '')));
    $rawHp  = trim((string) ($body['passenger_phone'] ?? ''));
    $pPhone = $rawHp !== '' ? bot_normalize_phone($rawHp) : ($bk['passengers'][0]['phone_number'] ?? null);

    if ($pPhone !== null && $pPhone !== '' && $pPhone !== ($bk['passengers'][0]['phone_number'] ?? '')) {
        $newPax   = bot_staff_ensure_passenger_flexible($db, $cid, $pPhone, $pName, $auditUser);
        $newPaxId = (int) $newPax['id'];
        $changes[] = "Penumpang: {$newPax['name']}";
    } elseif ($pName !== '' && $pName !== ($bk['passengers'][0]['name'] ?? '')) {
        $stUpPax = $db->prepare('UPDATE passengers SET name = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
        $stUpPax->bind_param('ssii', $pName, $auditUser, $newPaxId, $cid);
        $stUpPax->execute();
        $stUpPax->close();
        $changes[] = "Nama Penumpang: {$pName}";
    }
}

// Kursi, Bagasi, Catatan
if (isset($body['note'])) {
    $noteIn = trim((string) $body['note']);
    if ($noteIn !== $newNote) {
        $newNote   = $noteIn;
        $changes[] = 'Catatan';
    }
}
if (isset($body['append_note']) && trim((string) $body['append_note']) !== '') {
    $app       = trim((string) $body['append_note']);
    $newNote   = $newNote !== '' ? ($newNote . ' | ' . $app) : $app;
    $changes[] = 'Catatan (+)';
}
if (isset($body['baggage']) && trim((string) $body['baggage']) !== '') {
    $bag = 'Barang bawaan: ' . trim((string) $body['baggage']);
    if (preg_match('/Barang bawaan\s*:[^|]*/i', $newNote)) {
        $newNote = (string) preg_replace('/Barang bawaan\s*:[^|]*/i', $bag, $newNote);
    } else {
        $newNote = $newNote !== '' ? ($newNote . ' | ' . $bag) : $bag;
    }
    $changes[] = 'Bagasi';
}
if (isset($body['seats']) && is_numeric($body['seats'])) {
    $sVal = max(1, min(20, (int) $body['seats']));
    if ($sVal !== (int) $bk['seats']) {
        $changes[] = "Kursi: {$bk['seats']} → {$sVal}";
    }
    $cleanNote = trim((string) preg_replace('/\|\s*$/', '', (string) preg_replace('/\[\d{1,2}\s*kursi\]\s*\|?\s*/i', '', $newNote)));
    $newNote   = $sVal > 1 ? ("[{$sVal} kursi]" . ($cleanNote !== '' ? ' | ' . $cleanNote : '')) : $cleanNote;
}
$newNote = mb_substr(trim($newNote), 0, 2000);

// Kendaraan / Kategori
if (array_key_exists('vehicle_id', $body) || array_key_exists('vehicle_plate', $body)) {
    $vQ = $body['vehicle_id'] ?? $body['vehicle_plate'];
    if ($vQ === null || $vQ === '' || $vQ === 0) {
        if ($newVehId !== null) {
            $newVehId  = null;
            $changes[] = 'Armada dilepas';
        }
    } else {
        $veh = bot_staff_resolve_vehicle($db, $cid, $vQ);
        if (!$veh) {
            bot_error(404, 'VEHICLE_NOT_FOUND', "Kendaraan '{$vQ}' tidak ditemukan/tidak aktif di perusahaan ini.");
        }
        $newVehId  = (int) $veh['id'];
        $changes[] = 'Armada: ' . ($veh['plate_number'] ?: $veh['code']);
    }
}
if (isset($body['category_vehicle_id']) && is_numeric($body['category_vehicle_id'])) {
    $cidVeh = (int) $body['category_vehicle_id'] > 0 ? (int) $body['category_vehicle_id'] : null;
    if ($cidVeh !== $newCatId) {
        $newCatId  = $cidVeh;
        $changes[] = 'Kategori Armada';
    }
}

// Tarif (total_price)
$priceChanged = false;
if (isset($body['total_price']) && $body['total_price'] !== '') {
    $tp = bot_staff_parse_rupiah($body['total_price']);
    if ($tp <= 0) {
        bot_error(422, 'INVALID_PRICE', 'Total tarif harus lebih dari Rp 0.');
    }
    if (abs($tp - $newPrice) >= 0.01) {
        $invPaid = (float) ($bk['invoice']['paid_total'] ?? 0);
        $taxPct  = (float) ($bk['invoice']['tax_percent'] ?? 0);
        $newInvTotalCheck = round($tp + round($tp * ($taxPct / 100), 2), 2);
        if ($newInvTotalCheck + 0.01 < $invPaid) {
            bot_error(
                422,
                'TOTAL_BELOW_PAID',
                'Tarif baru (' . rupiah($newInvTotalCheck) . ') tidak boleh lebih rendah dari pembayaran yang sudah tercatat (' . rupiah($invPaid) . ').'
            );
        }
        $changes[]    = 'Tarif: ' . rupiah($newPrice) . ' → ' . rupiah($tp);
        $newPrice     = $tp;
        $priceChanged = true;
    }
}

$db->begin_transaction();
try {
    if ($bType === 'rental') {
        $st = $db->prepare(
            "UPDATE rental_car_bookings
                SET `date` = ?, `time` = ?, start_date = ?, end_date = ?, trip = ?, bbm = ?,
                    pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                    delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                    total_price = ?, category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                    modified_user = ?, modified_date = NOW()
              WHERE id = ? AND company_id = ?"
        );
        $st->bind_param(
            'ssssddsiiisiiidiiissii',
            $newDate, $newTime, $newStart, $newEnd, $newTrip, $newBbm,
            $newPkAddr, $newPkProv, $newPkCity, $newPkDist,
            $newDlAddr, $newDlProv, $newDlCity, $newDlDist,
            $newPrice, $newCatId, $newVehId, $newPaxId, $newNote,
            $auditUser, $bid, $cid
        );
    } else {
        $st = $db->prepare(
            "UPDATE travel_ticket_bookings
                SET `date` = ?, `time` = ?,
                    pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                    delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                    total_price = ?, category_vehicle_id = ?, vehicle_id = ?, passenger_id = ?, note = ?,
                    modified_user = ?, modified_date = NOW()
              WHERE id = ? AND company_id = ?"
        );
        $st->bind_param(
            'sssiiisiiidiiissii',
            $newDate, $newTime,
            $newPkAddr, $newPkProv, $newPkCity, $newPkDist,
            $newDlAddr, $newDlProv, $newDlCity, $newDlDist,
            $newPrice, $newCatId, $newVehId, $newPaxId, $newNote,
            $auditUser, $bid, $cid
        );
    }
    $st->execute();
    $st->close();

    // Pastikan primary passenger sinkron di booking_passengers jika passenger_id berubah
    if ($newPaxId !== (int) $bk['passenger_id']) {
        $curIds = array_map(static fn($p): int => (int) $p['passenger_id'], $bk['passengers']);
        $curIds = array_values(array_unique(array_merge([$newPaxId], array_filter($curIds, static fn($x): bool => $x !== (int) $bk['passenger_id']))));
        booking_passengers_set($bType, $bid, $cid, $curIds, $newPaxId, $auditUser);
    }

    // Sinkronkan invoice jika tarif berubah
    if ($priceChanged && !empty($bk['invoice'])) {
        $invRow = $bk['invoice'];
        $invId  = (int) $invRow['id'];
        if (($invRow['status'] ?? '') !== 'cancelled') {
            $taxPct = (float) ($invRow['tax_percent'] ?? 0);
            $taxAmt = round($newPrice * ($taxPct / 100), 2);
            $newTot = round($newPrice + $taxAmt, 2);
            $paid   = invoice_paid_total($invId);
            $newSt  = ($paid > 0.001 && $paid + 0.001 >= $newTot)
                ? 'paid'
                : ($paid > 0.001 ? 'partial' : 'issued');

            invoice_snapshot($invId, 'Edit via Grup WA Admin (' . $auditUser . ')', $auditUser);
            $uInv = $db->prepare('UPDATE invoices SET subtotal = ?, tax_amount = ?, total = ?, status = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
            $uInv->bind_param('dddssii', $newPrice, $taxAmt, $newTot, $newSt, $auditUser, $invId, $cid);
            $uInv->execute();
            $uInv->close();
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    bot_error(500, 'BOOKING_UPDATE_FAILED', 'Gagal memperbarui pesanan: ' . $e->getMessage());
}

// 2. Penugasan Sopir opsional
if (isset($body['driver_employee_id']) || isset($body['driver_name'])) {
    $dQ  = $body['driver_employee_id'] ?? $body['driver_name'];
    $drv = bot_staff_resolve_driver($db, $cid, $dQ);
    if (!$drv) {
        bot_error(404, 'DRIVER_NOT_FOUND', "Sopir/karyawan '{$dQ}' tidak ditemukan/tidak aktif di perusahaan ini.");
    }
    try {
        booking_assign_driver($bType, $bid, $cid, (int) $drv['id'], $newVehId, $auditUser);
        booking_auto_advance_status($bType, $bid, $cid, $auditUser);
        $changes[] = 'Sopir: ' . $drv['name'];
    } catch (Throwable $e) {
        bot_error(422, 'DRIVER_ASSIGN_FAILED', $e->getMessage());
    }
} elseif ($newVehId !== null && !empty($bk['driver']['id']) && (string) $bk['booking_status'] === 'draft') {
    booking_auto_advance_status($bType, $bid, $cid, $auditUser);
}

// 3. Ubah status operasional opsional
if (!empty($body['status'])) {
    $toSt = strtolower(trim((string) $body['status']));
    if (!booking_status_valid($toSt)) {
        bot_error(400, 'INVALID_STATUS', "Status '{$toSt}' tidak valid (gunakan: draft, scheduled, on_trip, done, cancelled).");
    }
    if ($toSt !== $bk['booking_status']) {
        $reason = trim((string) ($body['status_reason'] ?? 'Diubah via Grup WA Admin'));
        booking_change_status($bType, $bid, $cid, $toSt, $reason, $auditUser);
        $changes[] = 'Status: ' . bot_staff_status_label((string) $bk['booking_status']) . ' → ' . bot_staff_status_label($toSt);
    }
}

// 4. Catat pembayaran tambahan opsional
if (!empty($body['payment']) && is_array($body['payment'])) {
    if (empty($bk['invoice'])) {
        bot_error(422, 'NO_INVOICE', 'Pesanan ini belum memiliki invoice aktif.');
    }
    $invId     = (int) $bk['invoice']['id'];
    $pObj      = $body['payment'];
    $payAmt    = isset($pObj['amount']) ? bot_staff_parse_rupiah($pObj['amount']) : 0.0;
    $payMethod = trim((string) ($pObj['method'] ?? 'TRANSFER')) ?: 'TRANSFER';
    if ($payAmt <= 0 && strtolower((string) ($pObj['status'] ?? '')) === 'paid') {
        $curInv = invoice_find($invId);
        $payAmt = $curInv ? max(0.0, round((float) $curInv['total'] - (float) ($curInv['paid_total'] ?? 0), 2)) : 0.0;
    }
    if ($payAmt <= 0) {
        bot_error(422, 'INVALID_PAYMENT_AMOUNT', 'Nominal pembayaran harus lebih dari Rp 0.');
    }
    try {
        $pmId    = finance_resolve_payment_method_id($cid, $payMethod);
        $payType = trim((string) ($pObj['pay_type'] ?? ($payAmt + 0.01 >= $newPrice ? 'Pelunasan' : 'Down Payment (DP)')));
        $payNote = trim((string) ($pObj['note'] ?? ($payType . ' | ' . $payMethod)));
        invoice_add_payment($invId, $cid, $payAmt, $pmId, $payNote, $auditUser);
        $changes[] = 'Bayar ' . rupiah($payAmt) . " ({$payMethod})";
    } catch (Throwable $e) {
        bot_error(422, 'PAYMENT_FAILED', $e->getMessage());
    }
}

bot_staff_audit(
    $db,
    $cid,
    $staffCtx['staff'],
    'bot.staff_booking_update',
    "Edit {$bType} #{$docNo}" . ($changes ? ' [' . implode('; ', $changes) . ']' : '') . ' via Grup WA Admin'
);

$updated = bot_staff_find_booking($db, $cid, $docNo, $bType);
bot_reply(200, [
    'company' => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'changes' => $changes,
    'booking' => $updated,
    'wa_text' => bot_staff_format_booking_wa('Pesanan Diperbarui', $updated, $company, $changes),
]);
