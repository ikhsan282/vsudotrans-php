<?php
/**
 * Login & perpanjangan token GoPay Merchant (GoBiz) untuk channel QRIS langsung.
 * Mengambil token otomatis lewat OTP SMS GoBiz tanpa perlu menyalin header secara manual.
 */

if (!defined('GOPAY_GOBIZ_CLIENT_ID')) define('GOPAY_GOBIZ_CLIENT_ID', 'go-biz-web-dashboard');
if (!defined('GOPAY_GOBIZ_BASE')) define('GOPAY_GOBIZ_BASE', 'https://api.gobiz.co.id');

function gopay_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function gopay_normalize_phone(string $raw): ?array
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '' || $digits === null) return null;
    if (str_starts_with($digits, '62')) $local = substr($digits, 2);
    elseif (str_starts_with($digits, '0')) $local = substr($digits, 1);
    else $local = $digits;
    if (!preg_match('/^8[0-9]{7,12}$/', $local)) return null;
    return ['country_code' => '62', 'phone' => $local];
}

function gopay_gobiz_headers(?string $token = null, ?string $uniqueId = null): array
{
    $uid = $uniqueId ?: gopay_uuid();
    $h = [
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: id',
        'Origin: https://portal.gofoodmerchant.co.id',
        'Referer: https://portal.gofoodmerchant.co.id/',
        'Authentication-Type: go-id',
        'Gojek-Country-Code: ID',
        'Gojek-Timezone: Asia/Jakarta',
        'X-Appid: go-biz-web-dashboard',
        'X-Appversion: platform-v3.97.0-b986b897',
        'X-Deviceos: Web',
        'X-Phonemake: Windows 10 64-bit',
        'X-Phonemodel: Chrome 131.0.0.0 on Windows 10 64-bit',
        'X-Platform: Web',
        'X-Uniqueid: ' . $uid,
        'X-User-Type: merchant',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
    if ($token !== null && $token !== '') {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    return $h;
}

function gopay_http(string $method, string $url, ?array $body, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMRequest  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'code' => 0, 'error' => 'Koneksi gagal: ' . $err];
        $json = json_decode((string) $raw, true);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $json, 'raw' => $raw];
    }

    $ctxOpts = [
        'method'        => $method,
        'header'        => implode("\r\n", $headers) . "\r\n",
        'timeout'       => 20,
        'ignore_errors' => true,
    ];
    if ($body !== null) {
        $ctxOpts['content'] = json_encode($body);
    }
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $ctxOpts]));
    $code = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
    if ($raw === false) return ['ok' => false, 'code' => 0, 'error' => 'Koneksi gagal'];
    $json = json_decode((string) $raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $json, 'raw' => $raw];
}

function gopay_extract_error(array $res, string $fallback): string
{
    $d = $res['data'] ?? null;
    if (!empty($res['error'])) return (string) $res['error'];
    if (is_array($d)) {
        if (!empty($d['errors'][0]['message'])) return (string) $d['errors'][0]['message'];
        if (!empty($d['error_description'])) return (string) $d['error_description'];
        if (!empty($d['message'])) return (string) $d['message'];
    }
    return $fallback . ' (HTTP ' . ($res['code'] ?? 0) . ')';
}

function gopay_request_otp(string $rawPhone): array
{
    $p = gopay_normalize_phone($rawPhone);
    if (!$p) {
        return ['ok' => false, 'error' => 'Nomor HP tidak valid. Gunakan format 08xxxxxxxxxx.'];
    }
    $uid = gopay_uuid();
    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/login/request', [
        'client_id'    => GOPAY_GOBIZ_CLIENT_ID,
        'login_type'   => 'phone',
        ' claims'      => new stdClass(),
        'country_code' => $p['country_code'],
        'phone_number' => $p['phone'],
    ], gopay_gobiz_headers(null, $uid));

    $otpToken = $res['data']['data']['otp_token'] ?? '';
    if (!$res['ok'] || $otpToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Gagal meminta OTP GoPay Merchant')];
    }
    $_SESSION['gopay_otp'] = [
        'otp_token'  => $otpToken,
        'unique_id'  => $uid,
        'phone'      => '0' . $p['phone'],
        'expires_at' => time() + (int) ($res['data']['data']['otp_expires_in'] ?? 180),
    ];
    return ['ok' => true, 'phone' => '0' . $p['phone']];
}

function gopay_verify_otp(string $otp, int $companyId): array
{
    $otp = preg_replace('/\D+/', '', $otp);
    $st  = $_SESSION['gopay_otp'] ?? null;
    if (!$st || empty($st['otp_token'])) {
        return ['ok' => false, 'error' => 'Sesi OTP tidak ditemukan. Silakan kirim ulang OTP.'];
    }
    if (strlen($otp) < 4) {
        return ['ok' => false, 'error' => 'Kode OTP harus berupa angka 4–6 digit.'];
    }
    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/token', [
        'client_id'  => GOPAY_GOBIZ_CLIENT_ID,
        'grant_type' => 'otp',
        'data'       => [
            'otp'       => $otp,
            'otp_token' => $st['otp_token'],
        ],
    ], gopay_gobiz_headers(null, $st['unique_id']));

    $accessToken  = $res['data']['access_token']  ?? '';
    $refreshToken = $res['data']['refresh_token'] ?? '';
    if (!$res['ok'] || $accessToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Kode OTP salah atau sudah kedaluwarsa')];
    }

    $me = gopay_http('POST', GOPAY_GOBIZ_BASE . '/v1/users/me', (object) [], gopay_gobiz_headers($accessToken, $st['unique_id']));
    $u  = $me['data']['user'] ?? [];
    $merchantName = (string) ($u['merchant_name'] ?? ($u['name'] ?? ($u['phone'] ?? $st['phone'])));

    gopay_save_tokens($companyId, $accessToken, $refreshToken, $st['unique_id'], $st['phone'], $merchantName);
    unset($_SESSION['gopay_otp']);
    if (function_exists('audit')) {
        audit('payment_channel.gopay_login', 'Menghubungkan akun GoPay Merchant (' . $merchantName . ')');
    }
    return ['ok' => true, 'merchant' => $merchantName];
}

function gopay_build_headers(string $accessToken, string $uniqueId): array
{
    return [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: id',
        'Origin: https://portal.gofoodmerchant.co.id',
        'Referer: https://portal.gofoodmerchant.co.id/',
        'Authentication-Type: go-id',
        'Gojek-Country-Code: ID',
        'Gojek-Timezone: Asia/Jakarta',
        'X-Appid: go-biz-web-dashboard',
        'X-Appversion: platform-v3.97.0-b986b897',
        'X-Deviceos: Web',
        'X-Phonemake: Windows 10 64-bit',
        'X-Phonemodel: Chrome 131.0.0.0 on Windows 10 64-bit',
        'X-Platform: Web',
        'X-Uniqueid: ' . $uniqueId,
        'X-User-Type: merchant',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
}

function gopay_save_tokens(int $companyId, string $accessToken, string $refreshToken, string $uniqueId, string $phone, string $merchantName): void
{
    $db = db();
    $apiKey = json_encode(gopay_build_headers($accessToken, $uniqueId), JSON_UNESCAPED_SLASHES);
    $cfg = json_encode([
        'refresh_token' => $refreshToken,
        'unique_id'     => $uniqueId,
        'phone'         => $phone,
        'merchant_name' => $merchantName,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_SLASHES);

    $st = $db->prepare("SELECT id FROM payment_channels WHERE company_id = ? AND provider = 'gopay' LIMIT 1");
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        $s = $db->prepare('UPDATE payment_channels SET api_key = ?, config = ?, modified_date = NOW() WHERE id = ?');
        $id = (int) $row['id'];
        $s->bind_param('ssi', $apiKey, $cfg, $id);
        $s->execute();
        $s->close();
    } else {
        $s = $db->prepare(
            "INSERT INTO payment_channels (company_id, code, name, provider, api_key, config, mode, is_active, sort_order)
             VALUES (?, 'QRIS-GOPAY', 'QRIS GoPay Merchant', 'gopay', ?, ?, 'production', 0, 2)"
        );
        $s->bind_param('iss', $companyId, $apiKey, $cfg);
        $s->execute();
        $s->close();
    }
}

function gopay_refresh_channel(int $channelId): array
{
    $db = db();
    $st = $db->prepare("SELECT id, company_id, config FROM payment_channels WHERE id = ? AND provider = 'gopay' LIMIT 1");
    $st->bind_param('i', $channelId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $cfg = $row ? json_decode((string) $row['config'], true) : null;
    if (!is_array($cfg) || empty($cfg['refresh_token'])) {
        return ['ok' => false, 'error' => 'Refresh token GoPay tidak tersedia.'];
    }
    $uid = (string) ($cfg['unique_id'] ?? gopay_uuid());
    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/token', [
        'client_id'  => GOPAY_GOBIZ_CLIENT_ID,
        'grant_type' => 'refresh_token',
        'data'       => [
            'refresh_token' => $cfg['refresh_token'],
            'user_type'     => 'merchant',
        ],
    ], gopay_gobiz_headers(null, $uid));

    $accessToken  = $res['data']['access_token']  ?? '';
    $refreshToken = $res['data']['refresh_token'] ?? $cfg['refresh_token'];
    if (!$res['ok'] || $accessToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Gagal memperbarui token GoPay')];
    }
    gopay_save_tokens(
        (int) $row['company_id'],
        $accessToken,
        (string) $refreshToken,
        $uid,
        (string) ($cfg['phone'] ?? ''),
        (string) ($cfg['merchant_name'] ?? '')
    );
    return [
        'ok'      => true,
        'api_key' => json_encode(gopay_build_headers($accessToken, $uid), JSON_UNESCAPED_SLASHES),
    ];
}

