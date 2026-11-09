<?php
/**
 * GET /api/bot/qris_status.php?order_id=<ORDER_ID>  (atau ?document_number=TRV-XXXXXXXX)
 *
 * Memeriksa status pembayaran QRIS ke penyedia mutasi (GoPay Merchant / ShopeePay Partner / KlikQRIS),
 * memfinalisasi otomatis bila sudah dibayar (`gateway_finalize`), dan mengembalikan status terbaru
 * beserta tautan E-Tiket resmi (`tiket_url`).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$cid     = (int) $company['id'];

$orderId = trim((string) ($_GET['order_id'] ?? ''));
$docNo   = strtoupper(trim((string) ($_GET['document_number'] ?? ($_GET['no'] ?? ''))));

$order = null;
if ($orderId !== '') {
    $order = gateway_find_order($orderId);
} elseif ($docNo !== '') {
    $st = $db->prepare(
        "SELECT o.order_id FROM payment_gateway_orders o
           JOIN invoices i ON i.id = o.invoice_id
          WHERE o.company_id = ? AND i.document_number = ?
          ORDER BY o.id DESC LIMIT 1"
    );
    $st->bind_param('is', $cid, $docNo);
    $st->execute();
    $foundOrd = (string) $st->get_result()->fetch_column();
    $st->close();
    if ($foundOrd !== '') {
        $order = gateway_find_order($foundOrd);
    }
}

if (!$order || (int) $order['company_id'] !== $cid) {
    bot_error(404, 'ORDER_NOT_FOUND', 'Order pembayaran QRIS tidak ditemukan.');
}

// Verifikasi kepemilikan nomor WA (kecuali staff/admin)
if (!$actor['is_staff']) {
    $paxIds = array_map('intval', array_column($actor['passengers'], 'id'));
    if (!$paxIds) {
        bot_error(403, 'FORBIDDEN_ORDER', 'Order ini bukan milik nomor WhatsApp Anda.');
    }
    $tbl   = booking_table((string) $order['booking_type']);
    $bId   = (int) $order['booking_id'];
    $bType = (string) $order['booking_type'];
    $in    = implode(',', array_fill(0, count($paxIds), '?'));
    $types = 'i' . str_repeat('i', count($paxIds)) . str_repeat('i', count($paxIds));
    $params = array_merge([$bId], $paxIds, $paxIds);
    $st = $db->prepare(
        "SELECT 1 FROM {$tbl} b
          WHERE b.id = ?
            AND (b.passenger_id IN ({$in})
                 OR EXISTS (SELECT 1 FROM booking_passengers bp
                             WHERE bp.booking_type = '{$bType}' AND bp.booking_id = b.id AND bp.passenger_id IN ({$in})))
          LIMIT 1"
    );
    $st->bind_param($types, ...$params);
    $st->execute();
    $owned = (bool) $st->get_result()->fetch_row();
    $st->close();
    if (!$owned) {
        bot_error(403, 'FORBIDDEN_ORDER', 'Order ini bukan milik nomor WhatsApp Anda.');
    }
}

$orderId = (string) $order['order_id'];
if ($order['status'] === 'PENDING') {
    if (!empty($order['expired_at']) && strtotime((string) $order['expired_at']) <= time()) {
        gateway_mark_expired($orderId);
        $order['status'] = 'EXPIRED';
    } else {
        $channel = [
            'id'          => (int) $order['channel_id'],
            'company_id'  => (int) $order['company_id'],
            'api_key'     => $order['api_key'],
            'merchant_id' => $order['merchant_id'],
            'mode'        => $order['mode'],
            'provider'    => $order['provider'] ?? '',
            'config'      => $order['config'] ?? '',
        ];
        $remote = gateway_status($channel, $orderId);
        if ($remote['ok']) {
            $data  = is_array($remote['data'] ?? null) ? $remote['data'] : [];
            $state = strtoupper((string) ($data['status'] ?? ''));
            if (in_array($state, ['SUCCESS', 'PAID', 'SETTLED'], true)
                && isset($data['total_amount'])
                && abs((float) $data['total_amount'] - (float) $order['total_amount']) <= 0.01) {
                $done = gateway_finalize($orderId, (float) $data['total_amount']);
                if ($done['ok']) {
                    $order = gateway_find_order($orderId) ?: $order;
                }
            } elseif ($state === 'EXPIRED') {
                gateway_mark_expired($orderId);
                $order['status'] = 'EXPIRED';
            }
        }
    }
}

$inv = invoice_find((int) $order['invoice_id']);
$docNumber = (string) ($inv['document_number'] ?? '');
$bStatus   = booking_current_status((string) $order['booking_type'], (int) $order['booking_id']);
$baseUrl   = bot_base_url();

bot_reply(200, [
    'order_id'        => $orderId,
    'status'          => $order['status'],
    'invoice_id'      => (int) $order['invoice_id'],
    'invoice_no'      => $inv['invoice_no'] ?? null,
    'document_number' => $docNumber,
    'booking_status'  => $bStatus,
    'payment_status'  => $inv['pay_status'] ?? 'unpaid',
    'total'           => bot_money((float) $order['total_amount']),
    'paid_at'         => $order['paid_at'],
    'expires_at'      => $order['expired_at'],
    'tiket_url'       => $docNumber !== '' ? ($baseUrl . '/tiket.php?no=' . rawurlencode($docNumber) . '&company=' . rawurlencode($company['code'])) : null,
    'verifikasi_url'  => $docNumber !== '' ? ($baseUrl . '/verifikasi.php?no=' . rawurlencode($docNumber) . '&company=' . rawurlencode($company['code'])) : null,
]);

