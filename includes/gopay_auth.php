<?php
/**
 * Login & perpanjangan token GoPay Merchant (GoBiz) untuk channel QRIS langsung.
 * Mengikuti protokol resmi portal GoBiz (sama dengan arya-green-ipl):
 *   1. POST /goid/login/request  (client_id = go-biz-web-new)
 *   2. POST /goid/token          (grant_type = otp / refresh_token)
 *   3. GET  /goresto/v5/public/users/config
 */

if (!defined('GOPAY_GOBIZ_CLIENT_ID')) define('GOPAY_GOBIZ_CLIENT_ID', 'go-biz-web-new');
if (!defined('GOPAY_GOBIZ_BASE')) define('GOPAY_GOBIZ_BASE', 'https://api.gobiz.co.id');

function gopay_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Normalisasi 08xx / +628xx / 8xx -> ['country_code' => '62', 'phone' => '8xxxxxxxxx'] */
function gopay_normalize_phone(string $raw): ?array
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '62')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '0'))  $digits = substr($digits, 1);
    if (strlen($digits) < 8 || strlen($digits) > 15 || !str_starts_with($digits, '8')) {
        return null;
    }
    return ['country_code' => '62', 'phone' => $digits];
}

/** Map header standar portal GoBiz (Chrome 150 / platform-v3.111.0-1708bc9a). */
function gopay_headers_map(?string $uniqueId = null): array
{
    $uid = ($uniqueId !== null && $uniqueId !== '') ? $uniqueId : 'b5f98d3a-c9de-49ca-8067-dba94002b23c';
    return [
        'accept'              => 'application/json, text/plain, */*',
        'accept-language'     => 'id',
        'authentication-type' => 'go-id',
        'content-type'        => 'application/json',
        'gojek-country-code'  => 'ID',
        'gojek-timezone'      => 'Asia/Jakarta',
        'origin'              => 'https://portal.gofoodmerchant.co.id',
        'referer'             => 'https://portal.gofoodmerchant.co.id/',
        'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        'x-appid'             => 'go-biz-web-dashboard',
        'x-appversion'        => 'platform-v3.111.0-1708bc9a',
        'x-deviceos'          => 'Web',
        'x-phonemake'         => 'Windows 10 64-bit',
        'x-phonemodel'        => 'Chrome 150.0.0.0 on Windows 10 64-bit',
        'x-platform'          => 'Web',
        'x-uniqueid'          => $uid,
        'x-user-locale'       => 'en-GB',
        'x-user-type'         => 'merchant',
    ];
}

function gopay_gobiz_headers(?string $token = null, ?string $uniqueId = null): array
{
    $map = gopay_headers_map($uniqueId);
    if ($token !== null && $token !== '') {
        $map['authorization'] = 'Bearer ' . $token;
    }
    $out = [];
    foreach ($map as $k => $v) {
        $out[] = "$k: $v";
    }
    return $out;
}

function gopay_http(string $method, string $url, array|object|null $body, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
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
    $uid = 'b5f98d3a-c9de-49ca-8067-dba94002b23c';
    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/login/request', [
        'client_id'    => GOPAY_GOBIZ_CLIENT_ID,
        'phone_number' => $p['phone'],
        'country_code' => $p['country_code'],
    ], gopay_gobiz_headers(null, $uid));

    $otpToken = (string) ($res['data']['data']['otp_token'] ?? ($res['data']['otp_token'] ?? ''));
    if (!$res['ok'] || $otpToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Gagal meminta OTP GoPay Merchant')];
    }
    $_SESSION['gopay_otp'] = [
        'otp_token'  => $otpToken,
        'unique_id'  => $uid,
        'phone'      => $p['phone'],
        'expires_at' => time() + (int) ($res['data']['data']['otp_expires_in'] ?? 720),
    ];
    return ['ok' => true, 'phone' => '0' . $p['phone']];
}

/** Ambil profil merchant dari /goresto/v5/public/users/config */
function gopay_fetch_merchant_profile(string $accessToken, ?string $uniqueId = null): array
{
    $prof = gopay_http('GET', GOPAY_GOBIZ_BASE . '/goresto/v5/public/users/config', null, gopay_gobiz_headers($accessToken, $uniqueId));
    if ($prof['ok'] && is_array($prof['data'] ?? null)) {
        $p = $prof['data']['merchant'] ?? ($prof['data']['data'] ?? []);
        $outlet = trim((string) ($p['outlet_name'] ?? ($p['name'] ?? ($p['merchant_name'] ?? ''))));
        $mid    = trim((string) ($p['merchant_id'] ?? ($p['id'] ?? '')));
        if ($outlet !== '' || $mid !== '') {
            return ['outlet' => $outlet, 'merchant_id' => $mid];
        }
    }
    return ['outlet' => '', 'merchant_id' => ''];
}

function gopay_verify_otp(string $otp, int $companyId): array
{
    $otp = preg_replace('/\D+/', '', $otp) ?? '';
    $st  = $_SESSION['gopay_otp'] ?? null;
    if (!$st || empty($st['otp_token'])) {
        return ['ok' => false, 'error' => 'Sesi OTP tidak ditemukan. Silakan kirim ulang OTP.'];
    }
    if (strlen($otp) < 4 || strlen($otp) > 8) {
        return ['ok' => false, 'error' => 'Kode OTP harus berupa angka 4–8 digit.'];
    }
    $uid = (string) ($st['unique_id'] ?? 'b5f98d3a-c9de-49ca-8067-dba94002b23c');
    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/token', [
        'client_id'  => GOPAY_GOBIZ_CLIENT_ID,
        'grant_type' => 'otp',
        'data'       => [
            'otp'       => $otp,
            'otp_token' => $st['otp_token'],
        ],
    ], gopay_gobiz_headers(null, $uid));

    $data         = $res['data']['data'] ?? ($res['data'] ?? []);
    $accessToken  = (string) ($data['access_token']  ?? '');
    $refreshToken = (string) ($data['refresh_token'] ?? '');
    $expiresIn    = (int) ($data['expires_in'] ?? 86400);
    if (!$res['ok'] || $accessToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Kode OTP salah atau sudah kedaluwarsa')];
    }

    $prof = gopay_fetch_merchant_profile($accessToken, $uid);
    $outlet = $prof['outlet'];
    $mid    = $prof['merchant_id'];
    $merchantName = $outlet !== '' ? $outlet : ($mid !== '' ? ('GoPay Merchant #' . $mid) : 'GoPay Merchant');

    $pNorm = gopay_normalize_phone((string) ($st['phone'] ?? ''));
    $phoneLocal = $pNorm ? $pNorm['phone'] : ltrim((string) ($st['phone'] ?? ''), '0');

    gopay_save_tokens($companyId, $accessToken, $refreshToken, $uid, $phoneLocal, $merchantName, $outlet, $mid, $expiresIn);
    unset($_SESSION['gopay_otp']);
    if (function_exists('audit')) {
        audit('payment_channel.gopay_login', 'Menghubungkan akun GoPay Merchant (' . $merchantName . ')');
    }
    return ['ok' => true, 'merchant' => $merchantName, 'outlet' => $outlet, 'merchant_id' => $mid];
}

function gopay_build_headers(string $accessToken, string $uniqueId): array
{
    $headers = gopay_headers_map($uniqueId);
    $headers['authorization'] = 'Bearer ' . $accessToken;
    unset($headers['content-type'], $headers['authentication-type']);
    return $headers;
}

function gopay_save_tokens(
    int $companyId,
    string $accessToken,
    string $refreshToken,
    string $uniqueId,
    string $phone,
    string $merchantName,
    string $outlet = '',
    string $merchantId = '',
    int $expiresIn = 86400
): void {
    $db = db();
    $apiKey = json_encode(gopay_build_headers($accessToken, $uniqueId), JSON_UNESCAPED_SLASHES);

    $st = $db->prepare("SELECT id, config FROM payment_channels WHERE company_id = ? AND provider = 'gopay' LIMIT 1");
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $existingCfg = ($row && !empty($row['config'])) ? (json_decode((string) $row['config'], true) ?: []) : [];
    $pNorm = gopay_normalize_phone($phone);
    $phoneLocal = $pNorm ? $pNorm['phone'] : $phone;

    $newCfg = array_merge($existingCfg, [
        'refresh_token' => $refreshToken,
        'unique_id'     => $uniqueId,
        'phone'         => $phoneLocal,
        'merchant_name' => $merchantName,
        'expires_at'    => time() + ($expiresIn > 0 ? $expiresIn : 86400),
        'login_at'      => $existingCfg['login_at'] ?? time(),
        'updated_at'    => date('Y-m-d H:i:s'),
    ]);
    if ($outlet !== '') {
        $newCfg['outlet'] = $outlet;
    }
    if ($merchantId !== '') {
        $newCfg['merchant_id'] = $merchantId;
        if (empty($newCfg['merchant_ids'])) {
            $newCfg['merchant_ids'] = $merchantId;
        }
    }
    $cfg = json_encode($newCfg, JSON_UNESCAPED_SLASHES);

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

/**
 * Cek sesi masih hidup (sama dengan arya-green-ipl):
 * 1. Panggil GET /goresto/v5/public/users/config -> 401/403 berarti expired; ambil outlet & merchant_id.
 * 2. Panggil GET /merchant-analytics/v2/merchants/transactions -> pastikan token juga diterima API transaksi.
 */
function gopay_session_check(array $channel): array
{
    $auth = trim((string) ($channel['api_key'] ?? ''));
    $hdr  = json_decode($auth, true);
    $h    = [];
    $hasBearer = false;

    if (is_array($hdr)) {
        foreach ($hdr as $k => $v) {
            if (is_int($k)) {
                $line = trim((string) $v);
                if ($line !== '') {
                    $h[] = $line;
                    if (stripos($line, 'authorization: bearer ') === 0) $hasBearer = true;
                }
            } else {
                $h[] = "$k: $v";
                if (strtolower((string) $k) === 'authorization' && str_starts_with((string) $v, 'Bearer ')) {
                    $hasBearer = true;
                }
            }
        }
    }
    if (!$hasBearer) {
        return ['ok' => true, 'state' => 'empty', 'error' => null];
    }

    $cfg = json_decode((string) ($channel['config'] ?? ''), true);
    $cfg = is_array($cfg) ? $cfg : [];
    $merchantId = trim((string) ($cfg['merchant_ids'] ?? ($cfg['merchant_id'] ?? '')));

    $prof = gopay_http('GET', GOPAY_GOBIZ_BASE . '/goresto/v5/public/users/config', null, $h);
    if (in_array((int) $prof['code'], [401, 403], true)) {
        return ['ok' => true, 'state' => 'expired', 'error' => null];
    }
    if (!$prof['ok']) {
        return ['ok' => false, 'state' => 'unknown', 'error' => gopay_extract_error($prof, 'Gagal membaca profil merchant')];
    }

    $p = (array) ($prof['data']['merchant'] ?? ($prof['data']['data'] ?? []));
    $outlet = trim((string) ($p['outlet_name'] ?? ($p['name'] ?? ($p['merchant_name'] ?? ''))));
    $mid    = trim((string) ($p['id'] ?? ($p['merchant_id'] ?? '')));
    if ($outlet === '' && $mid === '') {
        return ['ok' => false, 'state' => 'unknown', 'error' => 'Profil merchant tidak terbaca dari sesi ini.'];
    }

    $q = [
        'from'       => 0,
        'size'       => 1,
        'statuses'   => 'SETTLEMENT,CAPTURE',
        'start_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
        'end_time'   => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
    ];
    if ($merchantId !== '') {
        $q['merchant_ids'] = $merchantId;
    } elseif ($mid !== '') {
        $q['merchant_ids'] = $mid;
    }

    $base = getenv('GOPAY_API_URL') ?: 'https://api.gojekapi.com';
    $res  = gopay_http('GET', $base . '/merchant-analytics/v2/merchants/transactions?' . http_build_query($q), null, $h);
    if (in_array((int) $res['code'], [401, 403], true)) {
        return ['ok' => true, 'state' => 'expired', 'error' => null];
    }
    if ($res['ok']) {
        // Sinkronkan outlet & merchant_name ke config bila baru terbaca
        if (!empty($channel['id'])) {
            if ($outlet !== '') {
                $cfg['outlet'] = $outlet;
                $cfg['merchant_name'] = $outlet;
            }
            if ($mid !== '') {
                $cfg['merchant_id'] = $mid;
                if (empty($cfg['merchant_ids'])) {
                    $cfg['merchant_ids'] = $mid;
                }
            }
            $cfg['updated_at'] = date('Y-m-d H:i:s');
            $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES);
            $chId = (int) $channel['id'];
            $stUp = db()->prepare('UPDATE payment_channels SET config = ?, modified_date = NOW() WHERE id = ?');
            $stUp->bind_param('si', $cfgJson, $chId);
            $stUp->execute();
            $stUp->close();
        }
        return ['ok' => true, 'state' => 'valid', 'error' => null, 'outlet' => $outlet, 'merchant_id' => $mid];
    }
    return ['ok' => false, 'state' => 'unknown', 'error' => gopay_extract_error($res, 'Gagal memverifikasi API transaksi GoPay')];
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
        return ['ok' => false, 'error' => 'Refresh token GoPay tidak tersedia. Silakan login OTP ulang.'];
    }
    $uid = (string) ($cfg['unique_id'] ?? 'b5f98d3a-c9de-49ca-8067-dba94002b23c');
    $pNorm = gopay_normalize_phone((string) ($cfg['phone'] ?? ''));
    $phoneLocal = $pNorm ? $pNorm['phone'] : ltrim((string) ($cfg['phone'] ?? ''), '0');

    $res = gopay_http('POST', GOPAY_GOBIZ_BASE . '/goid/token', [
        'client_id'  => GOPAY_GOBIZ_CLIENT_ID,
        'grant_type' => 'refresh_token',
        'data'       => [
            'refresh_token' => (string) $cfg['refresh_token'],
            'phone_number'  => $phoneLocal,
            'country_code'  => '62',
        ],
    ], gopay_gobiz_headers(null, $uid));

    $data         = $res['data']['data'] ?? ($res['data'] ?? []);
    $accessToken  = (string) ($data['access_token']  ?? '');
    $refreshToken = (string) ($data['refresh_token'] ?? $cfg['refresh_token']);
    $expiresIn    = (int) ($data['expires_in'] ?? 86400);
    if (!$res['ok'] || $accessToken === '') {
        return ['ok' => false, 'error' => gopay_extract_error($res, 'Gagal memperbarui token GoPay')];
    }

    $prof   = gopay_fetch_merchant_profile($accessToken, $uid);
    $outlet = $prof['outlet'] !== '' ? $prof['outlet'] : trim((string) ($cfg['outlet'] ?? ''));
    $mid    = $prof['merchant_id'] !== '' ? $prof['merchant_id'] : trim((string) ($cfg['merchant_id'] ?? ($cfg['merchant_ids'] ?? '')));
    $merchantName = $outlet !== ''
        ? $outlet
        : (trim((string) ($cfg['merchant_name'] ?? '')) ?: ($mid !== '' ? ('GoPay Merchant #' . $mid) : 'GoPay Merchant'));

    gopay_save_tokens(
        (int) $row['company_id'],
        $accessToken,
        $refreshToken,
        $uid,
        $phoneLocal,
        $merchantName,
        $outlet,
        $mid,
        $expiresIn
    );
    return [
        'ok'         => true,
        'api_key'    => json_encode(gopay_build_headers($accessToken, $uid), JSON_UNESCAPED_SLASHES),
        'outlet'     => $outlet,
        'merchant'   => $merchantName,
        'expires_at' => time() + ($expiresIn > 0 ? $expiresIn : 86400),
    ];
}
