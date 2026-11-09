<?php
/**
 * POST /api/bot/qris_create.php
 * Body: { "document_number": "TRV-XXXXXXXX", "invoice_id": 123, "provider": "gopay"|"shopeepay"|"klikqris" }
 *
 * Membuat (atau memakai kembali bila masih aktif) order QRIS Dinamis untuk satu invoice/tiket.
 * Memastikan tiket/invoice tersebut adalah milik pengirim WhatsApp (atau pengirim adalah pengurus/staff).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/payment_gateway.php';

$ctx     = bot_boot(['POST']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$body    = $ctx['body'];
$cid     = (int) $company['id'];

$docNo     = strtoupper(trim((string) ($body['document_number'] ?? ($body['no'] ?? ''))));
$invoiceId = (int) ($body['invoice_id'] ?? 0);
$provider  = !empty($body['provider']) ? strtolower(trim((string) $body['provider'])) : null;

$inv = null;
if ($invoiceId > 0) {
    $inv = invoice_find($invoiceId);
} elseif ($docNo !== '') {
    $st = $db->prepare('SELECT id FROM invoices WHERE company_id = ? AND document_number = ? LIMIT 1');
    $st->bind_param('is', $cid, $docNo);
    $st->execute();
    $foundId = (int) $st->get_result()->fetch_column();
    $st->close();
    if ($foundId > 0) {
        $inv = invoice_find($foundId);
    }
}

if (!$inv || (int) $inv['company_id'] !== $cid) {
    bot_error(404, 'INVOICE_NOT_FOUND', 'Tagihan atau Nomor Tiket tidak ditemukan pada perusahaan ini.');
}

// Verifikasi kepemilikan nomor WA (kecuali pengirim adalah staff/admin perusahaan ini)
if (!$actor['is_staff']) {
    $paxIds = array_map('intval', array_column($actor['passengers'], 'id'));
    if (!$paxIds) {
        bot_error(403, 'FORBIDDEN_INVOICE', 'Nomor WhatsApp Anda tidak terdaftar sebagai pemesan tiket ini.');
    }
    $tbl   = booking_table((string) $inv['booking_type']);
    $bId   = (int) $inv['booking_id'];
    $bType = (string) $inv['booking_type'];
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
        bot_error(403, 'FORBIDDEN_INVOICE', 'Tiket tersebut bukan milik nomor WhatsApp Anda.');
    }
}

if ($inv['status'] === 'cancelled') {
    bot_error(409, 'INVOICE_CANCELLED', 'Tiket/Invoice ini sudah dibatalkan.');
}

$remaining = max(0.0, round((float) $inv['total'] - (float) $inv['paid_total'], 2));
if ($remaining <= 0.01) {
    bot_error(409, 'ALREADY_PAID', 'Tiket/Invoice ini sudah lunas.');
}

$channel = gateway_active_channel($cid, $provider);
if (!$channel) {
    bot_error(503, 'GATEWAY_UNAVAILABLE', 'Pembayaran QRIS otomatis sedang belum diaktifkan untuk ' . $company['name'] . '.');
}

$activeProvider = (string) $channel['provider'];
$order = gateway_pending_for_invoice((int) $inv['id'], $activeProvider);
if ($order && !empty($order['expired_at']) && strtotime((string) $order['expired_at']) <= time()) {
    gateway_mark_expired((string) $order['order_id']);
    $order = null;
}

$fee    = gateway_fee($channel, $remaining);
$charge = gateway_charge_total($channel, $remaining);
$reused = true;

if (!$order) {
    $reused  = false;
    $orderId = gateway_make_order_id((int) $inv['id']);
    $res     = gateway_create($channel, $orderId, $charge, 'Tiket ' . $inv['document_number']);
    if (!$res['ok']) {
        bot_error(502, 'GATEWAY_ERROR', 'Gagal membuat QRIS Dinamis: ' . ($res['error'] ?? 'Unknown error'));
    }
    $data = $res['data'];
    gateway_store_order($cid, (int) $channel['id'], $orderId, $inv, $charge, $data, 'whatsapp_bot', 'hermes-bot');
    $order = gateway_find_order($orderId);
}

$total      = (float) $order['total_amount'];
$uniqueCode = max(0.0, round($total - $charge, 2));
$payload    = !empty($order['payload']) ? json_decode((string) $order['payload'], true) : [];

bot_reply(200, [
    'order_id'        => $order['order_id'],
    'invoice_id'      => (int) $inv['id'],
    'invoice_no'      => $inv['invoice_no'],
    'document_number' => $inv['document_number'],
    'provider'        => $activeProvider,
    'channel_name'    => $channel['name'],
    'reused'          => $reused,
    'qris_image_url'  => $order['qris_url'],
    'qris_string'     => $payload['qris_string'] ?? null,
    'pay_web_url'     => bot_base_url() . '/pages/finance/invoices/gateway_pay.php?no=' . rawurlencode((string) $inv['document_number']),
    'breakdown'       => [
        'bill'        => bot_money($remaining),
        'fee'         => bot_money($fee),
        'unique_code' => bot_money($uniqueCode),
    ],
    'total'           => bot_money($total),
    'expires_at'      => $order['expired_at'],
    'instructions'    => 'Scan QRIS dan bayar PERSIS sebesar ' . rupiah($total)
                       . ' (sudah termasuk kode unik ' . rupiah($uniqueCode)
                       . ') agar pembayaran terverifikasi otomatis oleh sistem.',
]);

