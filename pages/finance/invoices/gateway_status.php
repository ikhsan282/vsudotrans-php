<?php
/**
 * Endpoint AJAX Polling Status QRIS Dinamis (POST + CSRF).
 * Memeriksa mutasi GoPay Merchant / ShopeePay Partner / KlikQRIS dan memfinalisasi invoice.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';

header('Content-Type: application/json; charset=utf-8');

function gw_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gw_reply(405, ['status' => 'METHOD_NOT_ALLOWED']);
}
if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    gw_reply(403, ['status' => 'FORBIDDEN']);
}

$orderId = trim((string) ($_POST['order_id'] ?? ''));
$order = $orderId !== '' ? gateway_find_order($orderId) : null;
if (!$order) {
    gw_reply(404, ['status' => 'NOT_FOUND']);
}

if ($order['status'] === 'PENDING') {
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
    if (!$remote['ok']) {
        gw_reply(200, ['status' => 'PENDING', 'note' => 'Menunggu pengecekan berkala']);
    }
    $data  = is_array($remote['data'] ?? null) ? $remote['data'] : [];
    $state = strtoupper((string) ($data['status'] ?? ''));

    if (in_array($state, ['SUCCESS', 'PAID', 'SETTLED'], true)
        && isset($data['total_amount'])
        && abs((float) $data['total_amount'] - (float) $order['total_amount']) <= 0.01) {
        $done = gateway_finalize($orderId, (float) $data['total_amount']);
        if ($done['ok']) {
            $order['status'] = 'PAID';
            $order['cost_line_id'] = $done['cost_line_id'];
        }
    } elseif ($state === 'EXPIRED') {
        gateway_mark_expired($orderId);
        $order['status'] = 'EXPIRED';
    }
}

gw_reply(200, [
    'status'       => $order['status'],
    'invoice_id'   => (int) $order['invoice_id'],
    'cost_line_id' => isset($order['cost_line_id']) ? (int) $order['cost_line_id'] : null,
]);

