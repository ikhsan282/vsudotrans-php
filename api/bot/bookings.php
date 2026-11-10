<?php
/**
 * GET /api/bot/bookings.php?history=<1-20, default 10>
 * Menampilkan daftar booking aktif/belum lunas (`active`) serta riwayat booking/pembayaran (`history`)
 * khusus untuk nomor WhatsApp pengirim (`X-Bot-Phone` / `?phone=`) pada perusahaan ini.
 *
 * Menyertakan detail invoice, status pembayaran, order QRIS dinamis yang masih aktif (`pending_qris`),
 * dan tautan e-tiket (`tiket_url`).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$cid     = (int) $company['id'];
$historyLimit = max(1, min(20, (int) ($_GET['history'] ?? 10)));

$paxIds = array_map('intval', array_column($actor['passengers'], 'id'));
if (!$paxIds) {
    bot_reply(200, [
        'active'       => [],
        'active_count' => 0,
        'unpaid_total' => bot_money(0),
        'history'      => [],
    ]);
}

$in    = implode(',', array_fill(0, count($paxIds), '?'));
$types = 'i' . str_repeat('i', count($paxIds)) . str_repeat('i', count($paxIds));
$params = array_merge([$cid], $paxIds, $paxIds);

$allRows = [];
foreach (['travel' => 'travel_ticket_bookings', 'rental' => 'rental_car_bookings'] as $bType => $tbl) {
    $extraCols = $bType === 'rental' ? 'b.start_date, b.end_date, b.trip,' : 'NULL AS start_date, NULL AS end_date, 0 AS trip,';
    $sql = "SELECT '{$bType}' AS booking_type, b.id, b.document_number, b.date, b.time, {$extraCols}
                   b.pickup_address, b.delivery_address, b.total_price, b.total_payment, b.created_date,
                   c1.name AS origin_city, c2.name AS destination_city,
                   d1.name AS origin_district, d2.name AS destination_district,
                   p.name AS passenger_name, p.phone_number AS passenger_phone,
                   i.id AS invoice_id, i.invoice_no, i.total AS invoice_total, i.status AS invoice_status,
                   COALESCE((SELECT h.to_status FROM booking_status_history h
                              WHERE h.booking_type = '{$bType}' AND h.booking_id = b.id
                              ORDER BY h.id DESC LIMIT 1), 'draft') AS booking_status,
                   COALESCE((SELECT SUM(bcl.amount) FROM booking_cost_lines bcl
                              WHERE bcl.cost_type = 'payment' AND bcl.description = CONCAT('INV#', i.id)), 0) AS invoice_paid
              FROM {$tbl} b
              LEFT JOIN cities c1 ON c1.id = b.pickup_point_city_id
              LEFT JOIN cities c2 ON c2.id = b.delivery_point_city_id
              LEFT JOIN districts d1 ON d1.id = b.pickup_point_district_id
              LEFT JOIN districts d2 ON d2.id = b.delivery_point_district_id
              LEFT JOIN passengers p ON p.id = b.passenger_id
              LEFT JOIN invoices i ON i.document_number = b.document_number
             WHERE b.company_id = ?
               AND (b.passenger_id IN ({$in})
                    OR EXISTS (SELECT 1 FROM booking_passengers bp
                                WHERE bp.booking_type = '{$bType}' AND bp.booking_id = b.id AND bp.passenger_id IN ({$in})))
             ORDER BY b.date DESC, b.id DESC
             LIMIT 50";
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $allRows[] = $r;
    }
    $st->close();
}

usort($allRows, static fn(array $a, array $b): int => strcmp((string) $b['date'] . $b['time'], (string) $a['date'] . $a['time']));

$baseUrl = bot_base_url();
$today   = date('Y-m-d');
$active  = [];
$history = [];
$unpaidSum = 0.0;

foreach ($allRows as $r) {
    $bType = (string) $r['booking_type'];
    $bId   = (int) $r['id'];
    $invId = (int) ($r['invoice_id'] ?? 0);
    $docNo = (string) $r['document_number'];

    $bStatus  = (string) ($r['booking_status'] ?: 'draft');
    $invTotal = (float) ($r['invoice_total'] ?? $r['total_price']);
    $paid     = $invId > 0 ? (float) ($r['invoice_paid'] ?? 0) : (float) $r['total_payment'];
    $rem      = max(0.0, round($invTotal - $paid, 2));
    $payStatus = ($r['invoice_status'] ?? 'issued') === 'cancelled' || $bStatus === 'cancelled'
        ? 'cancelled'
        : ($paid <= 0 ? 'unpaid' : ($rem <= 0.01 ? 'paid' : 'partial'));

    $pendingQris = null;
    if ($invId > 0 && $rem > 0 && $payStatus !== 'cancelled') {
        $ord = gateway_pending_for_invoice($invId);
        if ($ord && (empty($ord['expired_at']) || strtotime((string) $ord['expired_at']) > time())) {
            $pendingQris = [
                'order_id'       => $ord['order_id'],
                'provider'       => $ord['provider'],
                'channel_name'   => $ord['channel_name'],
                'total'          => bot_money((float) $ord['total_amount']),
                'qris_image_url' => $ord['qris_url'],
                'expires_at'     => $ord['expired_at'],
            ];
        }
    }

    $item = [
        'booking_id'      => $bId,
        'booking_type'    => $bType,
        'document_number' => $docNo,
        'invoice_id'      => $invId > 0 ? $invId : null,
        'invoice_no'      => $r['invoice_no'],
        'date'            => $r['date'],
        'time'            => substr((string) $r['time'], 0, 5),
        'start_date'      => $r['start_date'],
        'end_date'        => $r['end_date'],
        'origin'          => trim(($r['origin_city'] ?? '') . ($r['origin_district'] ? ' (' . $r['origin_district'] . ')' : '')),
        'destination'     => trim(($r['destination_city'] ?? '') . ($r['destination_district'] ? ' (' . $r['destination_district'] . ')' : '')),
        'pickup_address'  => $r['pickup_address'],
        'delivery_address'=> $r['delivery_address'],
        'passenger_name'  => $r['passenger_name'],
        'booking_status'  => $bStatus,
        'payment_status'  => $payStatus,
        'total'           => bot_money($invTotal),
        'paid'            => bot_money($paid),
        'remaining'       => bot_money($rem),
        'pending_qris'    => $pendingQris,
        'tiket_url'       => $baseUrl . '/tiket.php?no=' . rawurlencode($docNo) . '&company=' . rawurlencode($company['code']),
        'pay_web_url'     => $rem > 0 ? ($baseUrl . '/pages/finance/invoices/gateway_pay.php?no=' . rawurlencode($docNo)) : null,
    ];

    $isActive = $payStatus !== 'cancelled' && ($rem > 0.01 || ($r['date'] >= $today && in_array($bStatus, ['draft', 'confirmed', 'rescheduled'], true)));
    if ($isActive) {
        $active[] = $item;
        $unpaidSum += $rem;
    } elseif (count($history) < $historyLimit) {
        $history[] = $item;
    }
}

bot_reply(200, [
    'company'      => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'active'       => $active,
    'active_count' => count($active),
    'unpaid_total' => bot_money($unpaidSum),
    'history'      => $history,
]);

