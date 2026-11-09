<?php
/**
 * Bootstrap bersama untuk semua endpoint `/api/bot/*` di VSudoTrans Multi-Tenant.
 * Tanpa sesi login web: identitas murni dari nomor WhatsApp (`X-Bot-Phone` / `?phone=`)
 * dan kode perusahaan (`X-Bot-Company` / `?company=`).
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
if (!defined('BOT_API_KEYS') && is_file(__DIR__ . '/../../config/bot_secrets.php')) {
    require_once __DIR__ . '/../../config/bot_secrets.php';
}
require_once __DIR__ . '/../../includes/bot_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$GLOBALS['BOT_CTX'] = [
    'company_id' => null,
    'key_id'     => null,
    'phone'      => null,
    'endpoint'   => basename($_SERVER['SCRIPT_NAME'] ?? '', '.php'),
];

/** Kirim respons sukses dan akhiri request. */
function bot_reply(int $status, array $data): never
{
    bot_log_request($status);
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Kirim respons error dan akhiri request. */
function bot_error(int $status, string $code, string $message): never
{
    bot_log_request($status);
    http_response_code($status);
    echo json_encode(
        ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Catat request bot yang lolos autentikasi ke `bot_request_logs`. */
function bot_log_request(int $status): void
{
    $phone = $GLOBALS['BOT_CTX']['phone'] ?? null;
    if ($phone === null) return;
    try {
        $st = db()->prepare(
            'INSERT INTO bot_request_logs (company_id, key_id, phone, endpoint, http_status, ip_address)
             VALUES (?,?,?,?,?,?)'
        );
        $cid = $GLOBALS['BOT_CTX']['company_id'];
        $kid = $GLOBALS['BOT_CTX']['key_id'];
        $ep  = $GLOBALS['BOT_CTX']['endpoint'];
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $st->bind_param('isssis', $cid, $kid, $phone, $ep, $status, $ip);
        $st->execute();
        $st->close();
        if (random_int(1, 200) === 1) {
            db()->query('DELETE FROM bot_request_logs WHERE created_at < NOW() - INTERVAL 30 DAY');
        }
    } catch (Throwable $e) {
        error_log('bot_log_request: ' . $e->getMessage());
    }
}

/**
 * Validasi method, autentikasi secret/HMAC, rate-limit, resolusi perusahaan, dan resolusi aktor WA.
 * @param string[] $methods
 */
function bot_boot(array $methods): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        bot_error(405, 'METHOD_NOT_ALLOWED', 'Metode HTTP tidak diizinkan.');
    }
    $raw = isset($GLOBALS['BOT_RAW_BODY'])
        ? (string) $GLOBALS['BOT_RAW_BODY']
        : (string) file_get_contents('php://input');
    if (strlen($raw) > 32768) {
        bot_error(413, 'PAYLOAD_TOO_LARGE', 'Permintaan terlalu besar.');
    }

    $auth = bot_verify_request($_SERVER, $raw);
    if (!$auth['ok']) {
        $msg = 'Permintaan tidak dapat diautentikasi.' . (!empty($auth['reason']) ? ' (' . $auth['reason'] . ')' : '');
        bot_error($auth['status'], $auth['code'], $msg);
    }

    $body = [];
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            bot_error(400, 'INVALID_JSON', 'Format body JSON tidak valid.');
        }
    }

    // Izinkan override kode perusahaan dari body JSON bila dikirim
    $companyCode = !empty($body['company']) && is_string($body['company'])
        ? strtoupper(trim($body['company']))
        : $auth['company_code'];

    $db = db();
    $company = bot_resolve_company($db, $companyCode);
    if (!$company) {
        bot_error(404, 'COMPANY_NOT_FOUND', "Kode perusahaan '{$companyCode}' tidak ditemukan atau tidak aktif.");
    }

    $companyId = (int) $company['id'];
    $GLOBALS['BOT_CTX']['company_id'] = $companyId;
    $GLOBALS['BOT_CTX']['key_id']     = $auth['key_id'];
    $GLOBALS['BOT_CTX']['phone']      = $auth['phone'];

    // Rate-limit per nomor WA
    $st = $db->prepare('SELECT COUNT(*) FROM bot_request_logs WHERE phone = ? AND created_at > NOW() - INTERVAL 60 SECOND');
    $st->bind_param('s', $auth['phone']);
    $st->execute();
    $cnt = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    if ($cnt >= BOT_RATE_PER_MIN) {
        bot_error(429, 'RATE_LIMITED', 'Terlalu banyak permintaan. Silakan coba lagi dalam 1 menit.');
    }

    $actor = bot_resolve_actor($db, $companyId, $auth['phone']);

    return [
        'db'      => $db,
        'company' => $company,
        'phone'   => $auth['phone'],
        'key_id'  => $auth['key_id'],
        'actor'   => $actor,
        'body'    => $body,
    ];
}
