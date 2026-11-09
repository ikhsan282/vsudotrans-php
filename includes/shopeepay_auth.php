<?php
/**
 * Validasi & penyimpanan token ShopeePay Merchant (Partner Web) untuk channel QRIS langsung.
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
        return ['ok' => false, 'error' => 'Format token tidak dikenal. Token ShopeePay diawali huruf "B:" dari header Authorization aplikasi ShopeePay Merchant.'];
    }
    $body = json_encode([
        'begin_time'           => time() - 3600,
        'end_time'             => time() + 60,
        'page'                 => 1,
        'page_size'            => 1,
        'transaction_tab_type' => 102,
    ]);
    $headers = [
        'Host: shopeepay.shopee.co.id',
        'Authorization: ' . $token,
        'User-Agent: okhttp/3.12.4 app_type=1',
        'Content-Type: application/json',
    ];
    $res = gq_http_post(SHOPEEPAY_TX_LIST_URL, $body, $headers, 15);
    if ($res['raw'] === false) {
        return ['ok' => false, 'error' => 'Koneksi ke ShopeePay gagal: ' . $res['error']];
    }
    $json = json_decode((string) $res['raw'], true);
    $list = $json['data']['transaction_list'] ?? null;
    if ($res['code'] >= 400 || !is_array($list)) {
        $msg = (string) ($json['debug_msg'] ?? ($json['msg'] ?? 'Token ditolak atau sudah kedaluwarsa'));
        return ['ok' => false, 'error' => $msg . ' (HTTP ' . $res['code'] . ')'];
    }
    return ['ok' => true, 'token' => $token];
}

