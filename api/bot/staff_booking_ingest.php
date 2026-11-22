<?php
/**
 * POST /api/bot/staff_booking_ingest.php
 * Khusus Admin/Staff Grup WhatsApp Hermes: menerima teks form pesanan bebas/belum rapi
 * atau field JSON terstruktur, parsing + resolusi geo/rute + cek duplikat + simpan booking & invoice.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/bot_staff_booking.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';

$ctx     = bot_boot(['POST']);
$db      = $ctx['db'];
$company = $ctx['company'];
$body    = $ctx['body'];
$cid     = (int) $company['id'];

$bType = strtolower(trim((string) ($body['booking_type'] ?? 'travel')));
if (!in_array($bType, ['travel', 'rental'], true)) {
    bot_error(400, 'INVALID_BOOKING_TYPE', "booking_type harus 'travel' atau 'rental'.");
}

$staffCtx  = bot_staff_require($ctx, [$bType === 'rental' ? 'rental.manage' : 'travel.manage']);
$auditUser = $staffCtx['audit_user'];

$rawText = trim((string) ($body['raw_text'] ?? ''));
if (mb_strlen($rawText, 'UTF-8') > 4000) {
    bot_error(422, 'RAW_TEXT_TOO_LONG', 'Panjang raw_text maksimal 4000 karakter.');
}

$useAi  = !array_key_exists('use_ai', $body) || (bool) $body['use_ai'];
$dryRun = !empty($body['dry_run']);

$parsed = [
    'passenger_name'    => '',
    'passenger_phone'   => '',
    'pickup_address'    => '',
    'delivery_address'  => '',
    'seats'             => 1,
    'date'              => '',
    'time'              => '',
    'baggage'           => '',
    'total_price'       => 0.0,
    'price_is_per_seat' => false,
    'note'              => '',
    'vehicle_query'     => '',
    'driver_query'      => '',
    'payment_hint'      => '',
    'pickup_geo'        => ['province_id' => 0, 'city_id' => 0, 'district_id' => 0, 'city_name' => '', 'district_name' => ''],
    'delivery_geo'      => ['province_id' => 0, 'city_id' => 0, 'district_id' => 0, 'city_name' => '', 'district_name' => ''],
    'rute'              => null,
];
if ($rawText !== '') {
    $parsed = bot_staff_parse_form_text($db, $cid, $rawText, $bType, $useAi);
}

// Field eksplisit JSON menimpa hasil parsing teks jika dikirim
$paxName  = trim((string) ($body['passenger_name'] ?? ($body['name'] ?? $parsed['passenger_name'])));
$rawHp    = trim((string) ($body['passenger_phone'] ?? ($body['phone'] ?? $parsed['passenger_phone'])));
$paxPhone = $rawHp !== '' ? (bot_normalize_phone($rawHp) ?? '') : '';

$seats = isset($body['seats']) && is_numeric($body['seats'])
    ? max(1, min(20, (int) $body['seats']))
    : max(1, min(20, (int) $parsed['seats']));

$rawDate = trim((string) ($body['date'] ?? $parsed['date']));
$date    = $rawDate !== '' ? (bot_staff_parse_indonesian_date($rawDate) ?? '') : '';
$rawTime = trim((string) ($body['time'] ?? $parsed['time']));
$time    = $rawTime !== '' ? (bot_staff_parse_time($rawTime) ?? '08:00') : '08:00';
$timeSql = $time . ':00';

$startDate = trim((string) ($body['start_date'] ?? $date));
$endDate   = trim((string) ($body['end_date'] ?? $startDate));
if ($bType === 'rental') {
    $startDate = $startDate !== '' ? (bot_staff_parse_indonesian_date($startDate) ?? '') : '';
    $endDate   = $endDate !== '' ? (bot_staff_parse_indonesian_date($endDate) ?? $startDate) : $startDate;
    if ($date === '' && $startDate !== '') {
        $date = $startDate;
    }
}

$pickupAddr   = trim((string) ($body['pickup_address'] ?? $parsed['pickup_address']));
$deliveryAddr = trim((string) ($body['delivery_address'] ?? $parsed['delivery_address']));

// Resolusi wilayah jemput & antar
$pkGeo = $parsed['pickup_geo'];
if ($pickupAddr !== '' && isset($body['pickup_address']) && empty($body['pickup_district_id']) && empty($body['pickup_city_id'])) {
    $pkGeo = bot_staff_resolve_address_geo($db, $pickupAddr, $cid);
}
$dlGeo = $parsed['delivery_geo'];
if ($deliveryAddr !== '' && isset($body['delivery_address']) && empty($body['delivery_district_id']) && empty($body['delivery_city_id'])) {
    $dlGeo = bot_staff_resolve_address_geo($db, $deliveryAddr, $cid);
}

$pkDistId = (int) ($body['pickup_district_id'] ?? $body['pickup_point_district_id'] ?? $pkGeo['district_id']);
$pkCityId = (int) ($body['pickup_city_id'] ?? $body['pickup_point_city_id'] ?? $pkGeo['city_id']);
$pkProvId = (int) ($pkGeo['province_id'] ?? 0);
if ($pkDistId > 0 && ($dInfo = resolve_district_info($pkDistId))) {
    $pkCityId = $pkCityId ?: $dInfo['city_id'];
    $pkProvId = $pkProvId ?: $dInfo['province_id'];
} elseif ($pkCityId > 0 && $pkDistId <= 0) {
    $stFirst = $db->prepare('SELECT id, province_id FROM districts WHERE city_id = ? ORDER BY id ASC LIMIT 1');
    $stFirst->bind_param('i', $pkCityId);
    $stFirst->execute();
    if ($fr = $stFirst->get_result()->fetch_assoc()) {
        $pkDistId = (int) $fr['id'];
        $pkProvId = $pkProvId ?: (int) $fr['province_id'];
    }
    $stFirst->close();
}

$dlDistId = (int) ($body['delivery_district_id'] ?? $body['delivery_point_district_id'] ?? $dlGeo['district_id']);
$dlCityId = (int) ($body['delivery_city_id'] ?? $body['delivery_point_city_id'] ?? $dlGeo['city_id']);
$dlProvId = (int) ($dlGeo['province_id'] ?? 0);
if ($dlDistId > 0 && ($dInfo = resolve_district_info($dlDistId))) {
    $dlCityId = $dlCityId ?: $dInfo['city_id'];
    $dlProvId = $dlProvId ?: $dInfo['province_id'];
} elseif ($dlCityId > 0 && $dlDistId <= 0) {
    $stFirst = $db->prepare('SELECT id, province_id FROM districts WHERE city_id = ? ORDER BY id ASC LIMIT 1');
    $stFirst->bind_param('i', $dlCityId);
    $stFirst->execute();
    if ($fr = $stFirst->get_result()->fetch_assoc()) {
        $dlDistId = (int) $fr['id'];
        $dlProvId = $dlProvId ?: (int) $fr['province_id'];
    }
    $stFirst->close();
}

$rute = bot_staff_match_route(
    $db,
    $cid,
    $pkCityId,
    $dlCityId,
    !empty($body['rute_id']) ? (int) $body['rute_id'] : null,
    !empty($body['rute_code']) ? (string) $body['rute_code'] : null
);

$catVehicleId = !empty($body['category_vehicle_id']) ? (int) $body['category_vehicle_id'] : null;
if ($catVehicleId === null && !empty($rute['category_vehicle_id'])) {
    $candCat = (int) $rute['category_vehicle_id'];
    if (category_service_type_for_id($cid, $candCat) === $bType) {
        $catVehicleId = $candCat;
    }
}

$rentalDays = $bType === 'rental'
    ? rental_duration_days($startDate !== '' ? $startDate : $date, $endDate !== '' ? $endDate : ($startDate !== '' ? $startDate : $date))
    : 0;
$qtyFactor = $bType === 'rental' ? $rentalDays : $seats;

$totalPrice = 0.0;
if (isset($body['total_price']) && $body['total_price'] !== '') {
    $totalPrice = bot_staff_parse_rupiah($body['total_price']);
} elseif (isset($body['price']) && $body['price'] !== '') {
    $billQty    = category_pricing_unit_multiplier(category_pricing_unit_for_id($cid, $catVehicleId), $qtyFactor);
    $totalPrice = round(bot_staff_parse_rupiah($body['price']) * $billQty, 2);
} elseif ((float) $parsed['total_price'] > 0) {
    $totalPrice = (float) $parsed['total_price'];
} elseif ($rute && in_array($bType, ['travel', 'rental'], true) && $pkDistId > 0 && $dlDistId > 0) {
    if ($bType === 'travel') {
        $qRes       = rute_calculate_travel_quote($cid, $rute, $catVehicleId, $seats, $pkDistId, $dlDistId, 0);
        $totalPrice = (float) ($qRes['total'] ?? 0);
    } else {
        if (!empty($body['rute_id']) || !empty($body['rute_code'])) {
            $qRes       = rute_calculate_travel_quote($cid, $rute, $catVehicleId, $seats, $pkDistId, $dlDistId, $rentalDays);
            $totalPrice = (float) ($qRes['total'] ?? 0);
        } else {
            $ruteLookup = travel_lookup_route_tariff($cid, $pkCityId, $dlCityId, $catVehicleId ?: 0, $pkDistId, $dlDistId, 'rental');
            if ($ruteLookup !== null && isset($ruteLookup['route']['id'])) {
                $rute       = $ruteLookup['route'];
                $qRes       = rute_calculate_travel_quote($cid, $rute, $catVehicleId, $seats, $pkDistId, $dlDistId, $rentalDays);
                $totalPrice = (float) ($qRes['total'] ?? 0);
            }
        }
    }
}

// Susun catatan operasional bersih (tanpa template NOTE umum)
$baggage   = trim((string) ($body['baggage'] ?? $parsed['baggage']));
$extraNote = trim((string) ($body['note'] ?? $parsed['note']));
$noteParts = [];
if ($seats > 1) {
    $noteParts[] = "[{$seats} kursi]";
}
if ($baggage !== '') {
    $noteParts[] = 'Barang bawaan: ' . $baggage;
}
if ($extraNote !== '') {
    $noteParts[] = $extraNote;
}
$finalNote = mb_substr(implode(' | ', $noteParts), 0, 2000);

// Resolusi cabang, kendaraan & sopir opsional
$branchId = (int) ($body['branch_id'] ?? 0);
if ($branchId <= 0) {
    $stBr = $db->prepare('SELECT id FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
    $stBr->bind_param('i', $cid);
    $stBr->execute();
    $branchId = (int) ($stBr->get_result()->fetch_column() ?: 0);
    $stBr->close();
}

$vehInput = $body['vehicle_id'] ?? ($body['vehicle_plate'] ?? ($parsed['vehicle_query'] ?: null));
$drvInput = $body['driver_employee_id'] ?? ($body['driver_name'] ?? ($parsed['driver_query'] ?: null));
$vehicle  = bot_staff_resolve_vehicle($db, $cid, $vehInput);
$driver   = bot_staff_resolve_driver($db, $cid, $drvInput);

// Cek kelengkapan field penting
$missing = [];
if ($paxName === '' && $paxPhone === '') {
    $missing[] = 'passenger_name';
}
if ($date === '') {
    $missing[] = 'date';
}
if ($pkDistId <= 0) {
    $missing[] = 'pickup_location';
}
if ($dlDistId <= 0) {
    $missing[] = 'delivery_location';
}
if ($totalPrice <= 0) {
    $missing[] = 'total_price';
}
if ($branchId <= 0) {
    $missing[] = 'branch_id';
}

// Cari kemungkinan penumpang existing & duplikat tanpa mutasi DB terlebih dulu
$existingPax = null;
if ($paxPhone !== '') {
    $variants = bot_phone_variants($paxPhone);
    $stP      = $db->prepare('SELECT id, code, name, phone_number FROM passengers WHERE company_id = ? AND phone_number IN (?,?,?,?) ORDER BY id DESC LIMIT 1');
    $stP->bind_param('issss', $cid, $variants[0], $variants[1], $variants[2], $variants[3]);
    $stP->execute();
    $existingPax = $stP->get_result()->fetch_assoc() ?: null;
    $stP->close();
} elseif ($paxName !== '') {
    $stP = $db->prepare('SELECT id, code, name, phone_number FROM passengers WHERE company_id = ? AND LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 1');
    $stP->bind_param('is', $cid, $paxName);
    $stP->execute();
    $existingPax = $stP->get_result()->fetch_assoc() ?: null;
    $stP->close();
}

$duplicate = ($existingPax && $date !== '')
    ? bot_staff_check_duplicate($db, $cid, $bType, (int) $existingPax['id'], $date, $time)
    : null;

$previewSummary = [
    'booking_type'         => $bType,
    'passenger_name'       => $paxName ?: ($existingPax['name'] ?? ''),
    'passenger_phone'      => $paxPhone ?: ($existingPax['phone_number'] ?? ''),
    'date'                 => $date,
    'time'                 => $time,
    'seats'                => $seats,
    'pickup_address'       => $pickupAddr,
    'pickup_city_id'       => $pkCityId ?: null,
    'pickup_district_id'   => $pkDistId ?: null,
    'delivery_address'     => $deliveryAddr,
    'delivery_city_id'     => $dlCityId ?: null,
    'delivery_district_id' => $dlDistId ?: null,
    'rute_id'              => $rute ? (int) $rute['id'] : null,
    'total_price'          => bot_money($totalPrice),
    'baggage'              => $baggage,
    'note'                 => $finalNote,
    'vehicle_id'           => $vehicle ? (int) $vehicle['id'] : null,
    'driver_employee_id'   => $driver ? (int) $driver['id'] : null,
];

if ($dryRun) {
    $waPreview = "*PREVIEW PARSING FORM — {$company['name']}*\n"
        . 'Nama: ' . ($previewSummary['passenger_name'] ?: '-') . ($previewSummary['passenger_phone'] ? ' (' . $previewSummary['passenger_phone'] . ')' : '') . "\n"
        . 'Jadwal: ' . ($date ?: '-') . ' ' . $time . " | {$seats} kursi\n"
        . 'Jemput: ' . ($pickupAddr ?: '-') . "\n"
        . 'Tujuan: ' . ($deliveryAddr ?: '-') . "\n"
        . 'Tarif: ' . rupiah($totalPrice)
        . ($missing ? "\nKurang: " . implode(', ', $missing) : '');
    bot_reply(200, [
        'dry_run'        => true,
        'ready'          => empty($missing),
        'missing_fields' => $missing,
        'parsed'         => $previewSummary,
        'duplicate'      => $duplicate,
        'wa_text'        => $waPreview,
    ]);
}

if ($missing) {
    bot_error(
        422,
        'INCOMPLETE_BOOKING_DATA',
        'Data pesanan belum lengkap: ' . implode(', ', $missing) . '.',
        ['missing_fields' => $missing, 'parsed' => $previewSummary]
    );
}

$primaryPax   = bot_staff_ensure_passenger_flexible($db, $cid, $paxPhone, $paxName ?: ($existingPax['name'] ?? ''), $auditUser);
$primaryPaxId = (int) $primaryPax['id'];
$duplicate    = bot_staff_check_duplicate($db, $cid, $bType, $primaryPaxId, $date, $time);

if ($duplicate && empty($body['allow_duplicate'])) {
    if (!empty($body['update_if_duplicate'])) {
        $dupBid = (int) $duplicate['id'];
        $tbl    = booking_table($bType);
        $up     = $db->prepare(
            "UPDATE {$tbl}
                SET pickup_address = ?, pickup_point_province_id = ?, pickup_point_city_id = ?, pickup_point_district_id = ?,
                    delivery_address = ?, delivery_point_province_id = ?, delivery_point_city_id = ?, delivery_point_district_id = ?,
                    total_price = ?, note = ?, modified_user = ?, modified_date = NOW()
              WHERE id = ? AND company_id = ?"
        );
        $pkProvNull = $pkProvId > 0 ? $pkProvId : null;
        $pkCityNull = $pkCityId > 0 ? $pkCityId : null;
        $dlProvNull = $dlProvId > 0 ? $dlProvId : null;
        $dlCityNull = $dlCityId > 0 ? $dlCityId : null;
        $up->bind_param(
            'siiisiiidssii',
            $pickupAddr, $pkProvNull, $pkCityNull, $pkDistId,
            $deliveryAddr, $dlProvNull, $dlCityNull, $dlDistId,
            $totalPrice, $finalNote, $auditUser, $dupBid, $cid
        );
        $up->execute();
        $up->close();

        // Sinkron invoice subtotal/total
        $stInv = $db->prepare('SELECT id, tax_percent, status FROM invoices WHERE booking_type = ? AND booking_id = ? AND company_id = ? LIMIT 1');
        $stInv->bind_param('sii', $bType, $dupBid, $cid);
        $stInv->execute();
        $invRow = $stInv->get_result()->fetch_assoc();
        $stInv->close();
        if ($invRow && ($invRow['status'] ?? '') !== 'cancelled') {
            $invId = (int) $invRow['id'];
            $paid  = invoice_paid_total($invId);
            $tax   = round($totalPrice * ((float) ($invRow['tax_percent'] ?? 0) / 100), 2);
            $newT  = round($totalPrice + $tax, 2);
            if ($newT + 0.01 >= $paid) {
                invoice_snapshot($invId, 'Perbarui via Grup WA Admin (update_if_duplicate)', $auditUser);
                $newSt = ($paid > 0.001 && $paid + 0.001 >= $newT) ? 'paid' : ($paid > 0.001 ? 'partial' : 'issued');
                $uI    = $db->prepare('UPDATE invoices SET subtotal = ?, tax_amount = ?, total = ?, status = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                $uI->bind_param('dddssi', $totalPrice, $tax, $newT, $newSt, $auditUser, $invId);
                $uI->execute();
                $uI->close();
            }
        }
        bot_staff_audit($db, $cid, $staffCtx['staff'], 'bot.staff_booking_update', "Update duplikat #{$duplicate['document_number']} a.n. {$primaryPax['name']} via Grup WA");
        $updatedBk = bot_staff_find_booking($db, $cid, (string) $duplicate['document_number'], $bType);
        bot_reply(200, [
            'updated_duplicate' => true,
            'booking'           => $updatedBk,
            'wa_text'           => bot_staff_format_booking_wa('Pesanan Diperbarui (Data Sebelumnya)', $updatedBk, $company),
        ]);
    }

    bot_error(
        409,
        'DUPLICATE_BOOKING',
        "Pesanan aktif a.n. {$primaryPax['name']} pada {$date} pukul {$time} sudah ada (#{$duplicate['document_number']}).",
        ['existing_booking' => $duplicate, 'parsed' => $previewSummary]
    );
}

// Daftar penumpang tambahan bila dikirim array `passengers`
$paxIds = [$primaryPaxId];
if (!empty($body['passengers']) && is_array($body['passengers'])) {
    foreach (array_slice($body['passengers'], 0, 20) as $pItem) {
        if (!is_array($pItem)) {
            continue;
        }
        $spPhone = !empty($pItem['phone']) ? bot_normalize_phone((string) $pItem['phone']) : null;
        $spName  = trim((string) ($pItem['name'] ?? ''));
        if ($spPhone || $spName !== '') {
            $subPax   = bot_staff_ensure_passenger_flexible($db, $cid, $spPhone, $spName ?: 'Penumpang', $auditUser);
            $paxIds[] = (int) $subPax['id'];
        }
    }
    $paxIds = array_values(array_unique($paxIds));
    $seats  = max($seats, count($paxIds));
}

try {
    $createData = [
        'company_id'                 => $cid,
        'branch_id'                  => $branchId,
        'date'                       => $date,
        'time'                       => $timeSql,
        'start_date'                 => $startDate ?: $date,
        'end_date'                   => $endDate ?: ($startDate ?: $date),
        'trip'                       => (float) ($body['trip'] ?? 1),
        'bbm'                        => (float) ($body['bbm'] ?? 0),
        'pickup_address'             => mb_substr($pickupAddr !== '' ? $pickupAddr : 'Titik jemput sesuai form WA Admin', 0, 2000),
        'pickup_point_province_id'   => $pkProvId ?: null,
        'pickup_point_city_id'       => $pkCityId ?: null,
        'pickup_point_district_id'   => $pkDistId,
        'delivery_address'           => mb_substr($deliveryAddr !== '' ? $deliveryAddr : 'Titik antar sesuai form WA Admin', 0, 2000),
        'delivery_point_province_id' => $dlProvId ?: null,
        'delivery_point_city_id'     => $dlCityId ?: null,
        'delivery_point_district_id' => $dlDistId,
        'total_price'                => $totalPrice,
        'category_vehicle_id'        => $catVehicleId,
        'vehicle_id'                 => $vehicle ? (int) $vehicle['id'] : null,
        'passenger_id'               => $primaryPaxId,
        'passenger_ids'              => $paxIds,
        'note'                       => $finalNote !== '' ? $finalNote : null,
        'created_user'               => $auditUser,
    ];
    $created = booking_create($bType, $createData);
} catch (Throwable $e) {
    bot_error(500, 'BOOKING_CREATE_FAILED', 'Gagal menyimpan pesanan: ' . $e->getMessage());
}

$bookingId = (int) $created['id'];
$docNo     = (string) $created['document_number'];
$invId     = (int) $created['invoice_id'];

// booking_create('travel') tidak menyertakan kolom province_id; lengkapi jika terdeteksi
if ($bType === 'travel' && ($pkProvId > 0 || $dlProvId > 0)) {
    $pkP = $pkProvId > 0 ? $pkProvId : null;
    $dlP = $dlProvId > 0 ? $dlProvId : null;
    $upP = $db->prepare('UPDATE travel_ticket_bookings SET pickup_point_province_id = ?, delivery_point_province_id = ? WHERE id = ? AND company_id = ?');
    $upP->bind_param('iiii', $pkP, $dlP, $bookingId, $cid);
    $upP->execute();
    $upP->close();
}

// Penugasan sopir opsional -> langsung auto-advance ke scheduled jika kendaraan & sopir lengkap
if ($driver) {
    try {
        booking_assign_driver($bType, $bookingId, $cid, (int) $driver['id'], $vehicle ? (int) $vehicle['id'] : null, $auditUser);
        booking_auto_advance_status($bType, $bookingId, $cid, $auditUser);
    } catch (Throwable $e) {
        error_log('staff_booking_ingest driver assign: ' . $e->getMessage());
    }
}

// Pencatatan pembayaran awal opsional (`payment: {amount, method, status}`)
$paymentWarning = null;
if (!empty($body['payment']) && is_array($body['payment'])) {
    $pObj      = $body['payment'];
    $payAmt    = isset($pObj['amount']) ? bot_staff_parse_rupiah($pObj['amount']) : 0.0;
    $payMethod = trim((string) ($pObj['method'] ?? 'TRANSFER')) ?: 'TRANSFER';
    $payStatus = strtolower(trim((string) ($pObj['status'] ?? '')));
    if ($payAmt <= 0 && $payStatus === 'paid') {
        $payAmt = $totalPrice;
    }
    if ($payAmt > 0 && $invId > 0) {
        try {
            $pmId = finance_resolve_payment_method_id($cid, $payMethod);
            $note = trim((string) ($pObj['note'] ?? (($payAmt + 0.01 >= $totalPrice ? 'Pelunasan' : 'Down Payment (DP)') . ' | ' . $payMethod)));
            invoice_add_payment($invId, $cid, $payAmt, $pmId, $note, $auditUser);
        } catch (Throwable $e) {
            $paymentWarning = $e->getMessage();
        }
    }
}

// Opsional QRIS dinamis hanya bila `create_qris: true` diminta eksplisit
$qrisInfo = null;
if (!empty($body['create_qris']) && $invId > 0) {
    $inv     = invoice_find($invId);
    $channel = $inv ? gateway_active_channel($cid, isset($body['provider']) ? (string) $body['provider'] : null) : null;
    if ($channel && $inv) {
        $rem = max(0.0, (float) $inv['total'] - (float) ($inv['paid_total'] ?? 0));
        if ($rem > 0) {
            $fee    = gateway_fee($channel, $rem);
            $charge = gateway_charge_total($channel, $rem);
            $ordId  = gateway_make_order_id($invId);
            $qRes   = gateway_create($channel, $ordId, $charge, 'Tiket ' . $docNo);
            if ($qRes['ok']) {
                gateway_store_order($cid, (int) $channel['id'], $ordId, $inv, $charge, $qRes['data'], 'whatsapp_bot', $auditUser);
                $qTotal   = (float) $qRes['data']['total_amount'];
                $qrisInfo = [
                    'order_id'       => $ordId,
                    'provider'       => $channel['provider'],
                    'qris_image_url' => $qRes['data']['qris_url'] ?? null,
                    'total'          => bot_money($qTotal),
                ];
            }
        }
    }
}

bot_staff_audit(
    $db,
    $cid,
    $staffCtx['staff'],
    'bot.staff_booking_create',
    "Tambah {$bType} #{$docNo} a.n. {$primaryPax['name']} (" . rupiah($totalPrice) . ") via Grup WA Admin"
);

$bk      = bot_staff_find_booking($db, $cid, $docNo, $bType);
$baseUrl = bot_base_url();
$webPath = $bType === 'rental' ? "/pages/rental/bookings/detail.php?id={$bookingId}" : "/pages/travel/bookings/detail.php?id={$bookingId}";

bot_reply(201, [
    'company'         => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'booking_id'      => $bookingId,
    'booking_type'    => $bType,
    'document_number' => $docNo,
    'invoice_id'      => $invId,
    'invoice_no'      => $bk['invoice']['invoice_no'] ?? null,
    'passenger'       => $bk['passengers'][0] ?? $primaryPax,
    'booking'         => $bk,
    'parsed'          => $previewSummary,
    'qris'            => $qrisInfo,
    'payment_warning' => $paymentWarning,
    'erp_detail_url'  => $baseUrl . $webPath,
    'tiket_url'       => $baseUrl . '/tiket.php?no=' . rawurlencode($docNo) . '&company=' . rawurlencode((string) $company['code']),
    'wa_text'         => bot_staff_format_booking_wa('Pesanan Tercatat', $bk, $company),
]);
