<?php
/**
 * Webhook KlikQRIS Multi-Tenant (`?company_id=<id>&token=<webhook_token>`).
 * Hanya memicu verifikasi server-to-server ke KlikQRIS sebelum memfinalisasi invoice.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';

function webhook_reply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhook_reply(405, ['ok' => false]);
}
$companyId = (int) ($_GET['company_id'] ?? 0);
$token     = (string) ($_GET['token'] ?? '');
$channel   = $companyId > 0 ? gateway_channel($companyId, 'klikqris') : null;

if (!$channel || empty($channel['webhook_token']) || !hash_equals((string) $channel['webhook_token'], $token)) {
    webhook_reply(403, ['ok' => false]);
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) {
    webhook_reply(200, ['ok' => false]);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$orderId = $payload['order_id'] ?? ($payload['data']['order_id'] ?? null);
if (!is_string($orderId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $orderId)) {
    webhook_reply(200, ['ok' => false]);
}
$order = gateway_find_order($orderId);
if (!$order || (int) $order['company_id'] !== $companyId) {
    webhook_reply(200, ['ok' => false]);
}

try {
    $status = gateway_status($channel, $orderId);
    $data   = $status['data'] ?? [];
    if (!empty($status['ok']) && gateway_is_paid($data)) {
        $reported = $data['total_amount'] ?? null;
        if (is_numeric($reported) && abs((float) $reported - (float) $order['total_amount']) <= 0.01) {
            gateway_finalize($orderId, (float) $reported);
        }
    } elseif (!empty($status['ok']) && strtoupper((string) ($data['status'] ?? '')) === 'EXPIRED') {
        gateway_mark_expired($orderId);
    }
} catch (Throwable $e) {
    error_log('KlikQRIS webhook error: ' . $e->getMessage());
}
webhook_reply(200, ['ok' => true]);

