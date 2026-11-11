<?php
/**
 * Validasi & penyimpanan token ShopeePay Merchant (Partner Web) untuk channel QRIS langsung.
 * Mengikuti protokol resmi Portal Partner ShopeePay (sama dengan arya-green-ipl).
 */

if (!defined('SHOPEEPAY_TX_LIST_URL')) {
    define('SHOPEEPAY_TX_LIST_URL', 'https://shopeepay.shopee.co.id/merchant/v1/partner-web/get-transaction-list');
}

function shopeepay_clean_token(string $raw): string
{
    $t = trim($raw);
    if (stripos($t, 'authorization:') === 0) {
        $t = trim(substr($t, strlen('authorization:')));
    }
    return $t;
}

function shopeepay_verify_token(string $rawToken): array
{
    require_once __DIR__ . '/payment_gateway_direct.php';
    $token = shopeepay_clean_token($rawToken);
    if ($token === '' || stripos($token, 'B:') !== 0) {
        return ['ok' => false, 'error' => 'Format token tidak dikenal. Token ShopeePay harus diawali "B:" (salin dari data.metadata.token di Portal Partner).'];
    }
    $r = gq_check_shopeepay(
        ['api_key' => $token, 'id' => 0],
        ['total_amount' => -1, 'created_at' => date('Y-m-d H:i:s', time() - 3600), 'order_id' => 'PING']
    );
    if ($r['ok']) {
        return ['ok' => true, 'token' => $token];
    }
    return ['ok' => false, 'error' => (string) ($r['error'] ?? 'Token ditolak atau sudah kedaluwarsa')];
}
