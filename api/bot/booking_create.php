<?php
/**
 * POST /api/bot/booking_create.php
 * Auto-Order Booking Travel atau Rental langsung dari Chatbot WhatsApp Hermes.
 *
 * Fitur:
 * 1. Otomatis mendaftarkan/mengambil profil `passengers` berdasarkan nomor WA pengirim (`X-Bot-Phone`).
 * 2. Mendukung pemesanan `travel` (berdasarkan `rute_id` / `rute_code` atau kota/kecamatan) dan `rental`.
 * 3. Membuat booking + status `draft` + `invoices` otomatis dalam satu transaksi.
 * 4. Jika `create_qris = true` (default) dan perusahaan memiliki channel QRIS aktif, langsung
 *    membuatkan QRIS Dinamis sehingga Hermes bisa mengirimkan detail pesanan sekaligus gambar QRIS!
 */
require __DIR__ . '/_bootstrap.php';
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

$paxName = trim((string) ($body['passenger_name'] ?? ($body['name'] ?? '')));
$primaryPax = bot_ensure_passenger($db, $cid, $ctx['phone'], $paxName);
$primaryPaxId = (int) $primaryPax['id'];

// Daftar penumpang tambahan bila seats > 1 atau dikirim array `passengers`
$seats = max(1, min(20, (int) ($body['seats'] ?? ($body['passengers_count'] ?? 1))));
$paxIds = [$primaryPaxId];
if (!empty($body['passengers']) && is_array($body['passengers'])) {
    foreach (array_slice($body['passengers'], 0, 20) as $idx => $pItem) {
        if (!is_array($pItem)) continue;
        $pPhone = !empty($pItem['phone']) ? bot_normalize_phone((string) $pItem['phone']) : null;
        $pName  = trim((string) ($pItem['name'] ?? ''));
        if ($pPhone && $pPhone !== $ctx['phone']) {
            $subPax = bot_ensure_passenger($db, $cid, $pPhone, $pName);
            $paxIds[] = (int) $subPax['id'];
        }
    }
    $paxIds = array_values(array_unique($paxIds));
    $seats  = max($seats, count($paxIds));
}

// Cabang (default cabang pertama perusahaan)
$branchId = (int) ($body['branch_id'] ?? 0);
if ($branchId <= 0) {
    $stBr = $db->prepare('SELECT id FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
    $stBr->bind_param('i', $cid);
    $stBr->execute();
    $branchId = (int) $stBr->get_result()->fetch_column();
    $stBr->close();
}
if ($branchId <= 0) {
    bot_error(422, 'NO_BRANCH', 'Perusahaan ini belum memiliki cabang aktif.');
}

// Tanggal & Jam Keberangkatan
$date = trim((string) ($body['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    bot_error(400, 'INVALID_DATE', 'Format tanggal harus YYYY-MM-DD.');
}
$time = trim((string) ($body['time'] ?? '08:00'));
if (!empty($body['schedule_id'])) {
    $schId = (int) $body['schedule_id'];
    $stSch = $db->prepare('SELECT start FROM schedules WHERE id = ? AND company_id = ? LIMIT 1');
    $stSch->bind_param('ii', $schId, $cid);
    $stSch->execute();
    $schStart = $stSch->get_result()->fetch_column();
    $stSch->close();
    if ($schStart) {
        $time = substr((string) $schStart, 0, 5);
    }
}
if (preg_match('/^\d{2}:\d{2}$/', $time)) {
    $time .= ':00';
}

// Resolusi Rute / Kota / Kecamatan & Harga
$rute = null;
if (!empty($body['rute_id'])) {
    $rid = (int) $body['rute_id'];
    $stR = $db->prepare('SELECT * FROM rutes WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
    $stR->bind_param('ii', $rid, $cid);
    $stR->execute();
    $rute = $stR->get_result()->fetch_assoc() ?: null;
    $stR->close();
} elseif (!empty($body['rute_code'])) {
    $rc = strtoupper(trim((string) $body['rute_code']));
    $stR = $db->prepare('SELECT * FROM rutes WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1');
    $stR->bind_param('is', $cid, $rc);
    $stR->execute();
    $rute = $stR->get_result()->fetch_assoc() ?: null;
    $stR->close();
} elseif ($bType === 'travel') {
    // Fallback ke rute aktif pertama milik perusahaan bila hanya ada 1 rute atau tidak dikirim spesifik
    $stR = $db->prepare('SELECT * FROM rutes WHERE company_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
    $stR->bind_param('i', $cid);
    $stR->execute();
    $rute = $stR->get_result()->fetch_assoc() ?: null;
    $stR->close();
}

$pickupCityId   = (int) ($body['pickup_city_id'] ?? ($rute['pickup_point_city_id'] ?? 0));
$deliveryCityId = (int) ($body['delivery_city_id'] ?? ($rute['delivery_point_city_id'] ?? 0));
$catVehicleId   = !empty($body['category_vehicle_id']) ? (int) $body['category_vehicle_id'] : (!empty($rute['category_vehicle_id']) ? (int) $rute['category_vehicle_id'] : null);

// Cari district_id dari city_id bila tidak dikirim eksplisit
$resolveDistrict = static function (mysqli $db, int $cityId, int $explicitDistId): int {
    if ($explicitDistId > 0) return $explicitDistId;
    if ($cityId > 0) {
        $st = $db->prepare('SELECT id FROM districts WHERE city_id = ? ORDER BY id ASC LIMIT 1');
        $st->bind_param('i', $cityId);
        $st->execute();
        $did = (int) $st->get_result()->fetch_column();
        $st->close();
        if ($did > 0) return $did;
    }
    return (int) $db->query('SELECT id FROM districts ORDER BY id ASC LIMIT 1')->fetch_column();
};

$pickupDistId   = $resolveDistrict($db, $pickupCityId, (int) ($body['pickup_district_id'] ?? 0));
$deliveryDistId = $resolveDistrict($db, $deliveryCityId, (int) ($body['delivery_district_id'] ?? 0));
if ($pickupCityId <= 0 && $pickupDistId > 0) {
    $pickupCityId = (int) $db->query("SELECT city_id FROM districts WHERE id = {$pickupDistId} LIMIT 1")->fetch_column();
}
if ($deliveryCityId <= 0 && $deliveryDistId > 0) {
    $deliveryCityId = (int) $db->query("SELECT city_id FROM districts WHERE id = {$deliveryDistId} LIMIT 1")->fetch_column();
}
if ($pickupDistId <= 0 || $deliveryDistId <= 0) {
    bot_error(422, 'MISSING_LOCATION', 'Data kota/kecamatan jemput & tujuan belum tersedia.');
}

// Hitung harga total
$unitPrice = isset($body['price']) && is_numeric($body['price'])
    ? (float) $body['price']
    : (float) ($rute['price'] ?? 0);
$totalPrice = isset($body['total_price']) && is_numeric($body['total_price'])
    ? (float) $body['total_price']
    : ($unitPrice * $seats);

if ($totalPrice <= 0) {
    bot_error(400, 'INVALID_PRICE', 'Harga rute/booking harus lebih dari Rp 0. Pilih rute yang valid.');
}

$pickupAddr   = mb_substr(trim((string) ($body['pickup_address'] ?? 'Titik jemput sesuai kesepakatan WA')), 0, 2000);
$deliveryAddr = mb_substr(trim((string) ($body['delivery_address'] ?? 'Titik antar sesuai kesepakatan WA')), 0, 2000);
$note         = mb_substr(trim((string) ($body['note'] ?? ("Auto-order via WhatsApp ({$seats} kursi)"))), 0, 2000);

try {
    $createData = [
        'company_id'                 => $cid,
        'branch_id'                  => $branchId,
        'date'                       => $date,
        'time'                       => $time,
        'start_date'                 => trim((string) ($body['start_date'] ?? $date)),
        'end_date'                   => trim((string) ($body['end_date'] ?? $date)),
        'trip'                       => (float) ($body['trip'] ?? 1),
        'bbm'                        => 0.0,
        'pickup_address'             => $pickupAddr,
        'pickup_point_city_id'       => $pickupCityId ?: null,
        'pickup_point_district_id'   => $pickupDistId,
        'delivery_address'           => $deliveryAddr,
        'delivery_point_city_id'     => $deliveryCityId ?: null,
        'delivery_point_district_id' => $deliveryDistId,
        'total_price'                => $totalPrice,
        'category_vehicle_id'        => $catVehicleId,
        'vehicle_id'                 => null,
        'passenger_id'               => $primaryPaxId,
        'passenger_ids'              => $paxIds,
        'note'                       => $note,
        'created_user'               => 'hermes-bot',
    ];
    $created = booking_create($bType, $createData);
} catch (Throwable $e) {
    bot_error(500, 'BOOKING_CREATE_FAILED', 'Gagal membuat pesanan: ' . $e->getMessage());
}

$inv = invoice_find((int) $created['invoice_id']);
$docNo = (string) $created['document_number'];
$baseUrl = bot_base_url();

// Opsional: langsung buat order QRIS Dinamis bila perusahaan memiliki channel QRIS aktif
$wantQris = !isset($body['create_qris']) || (bool) $body['create_qris'];
$qrisInfo = null;
if ($wantQris && $inv) {
    $channel = gateway_active_channel($cid, isset($body['provider']) ? (string) $body['provider'] : null);
    if ($channel) {
        $fee    = gateway_fee($channel, (float) $inv['total']);
        $charge = gateway_charge_total($channel, (float) $inv['total']);
        $ordId  = gateway_make_order_id((int) $inv['id']);
        $qRes   = gateway_create($channel, $ordId, $charge, 'Tiket ' . $docNo);
        if ($qRes['ok']) {
            gateway_store_order($cid, (int) $channel['id'], $ordId, $inv, $charge, $qRes['data'], 'whatsapp_bot', 'hermes-bot');
            $qTotal = (float) $qRes['data']['total_amount'];
            $qUnique = max(0.0, round($qTotal - $charge, 2));
            $qrisInfo = [
                'order_id'       => $ordId,
                'provider'       => $channel['provider'],
                'channel_name'   => $channel['name'],
                'qris_image_url' => $qRes['data']['qris_url'],
                'qris_string'    => $qRes['data']['qris_string'] ?? null,
                'breakdown'      => [
                    'bill'        => bot_money((float) $inv['total']),
                    'fee'         => bot_money($fee),
                    'unique_code' => (int) $qUnique,
                ],
                'total'          => bot_money($qTotal),
                'expires_at'     => $qRes['data']['expired_at'] ?? null,
            ];
        }
    }
}

bot_reply(201, [
    'company'         => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'booking_id'      => (int) $created['id'],
    'booking_type'    => $bType,
    'document_number' => $docNo,
    'invoice_id'      => (int) $created['invoice_id'],
    'invoice_no'      => $inv['invoice_no'] ?? null,
    'passenger_name'  => $primaryPax['name'],
    'date'            => $date,
    'time'            => substr($time, 0, 5),
    'seats'           => $seats,
    'total'           => bot_money((float) ($inv['total'] ?? $totalPrice)),
    'booking_status'  => 'draft',
    'payment_status'  => 'unpaid',
    'tiket_url'       => $baseUrl . '/tiket.php?no=' . rawurlencode($docNo) . '&company=' . rawurlencode($company['code']),
    'verifikasi_url'  => $baseUrl . '/verifikasi.php?no=' . rawurlencode($docNo) . '&company=' . rawurlencode($company['code']),
    'qris'            => $qrisInfo,
]);

