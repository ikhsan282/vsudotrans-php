<?php
/**
 * VSudoTrans — Konfigurasi utama
 * Semua konstanta aplikasi ada di sini (satu sumber kebenaran).
 */

// --- Muat file .env lokal bila ada (di-ignore Git) -------------------
$__env_file = dirname(__DIR__) . '/.env';
if (is_file($__env_file) && is_readable($__env_file)) {
    foreach (file($__env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $__line) {
        $__line = trim($__line);
        if ($__line === '' || str_starts_with($__line, '#') || !str_contains($__line, '=')) {
            continue;
        }
        [$__k, $__v] = explode('=', $__line, 2);
        $__k = trim($__k);
        $__v = trim($__v);
        if ((str_starts_with($__v, '"') && str_ends_with($__v, '"')) || (str_starts_with($__v, "'") && str_ends_with($__v, "'"))) {
            $__v = substr($__v, 1, -1);
        }
        if ($__k !== '' && getenv($__k) === false) {
            putenv("{$__k}={$__v}");
            $_ENV[$__k] = $__v;
        }
    }
}

// --- Basis URL -------------------------------------------------------
// Otomatis mengikuti alamat yang dipakai browser (XAMPP, dev server, cPanel).
// Override bila perlu: set env VSUDO_APP_URL, atau isi langsung di sini.
if (!empty($_SERVER['HTTP_HOST'])) {
    $__root = str_replace('\\', '/', dirname(__DIR__));
    $__doc  = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $__base = ($__doc !== '' && str_starts_with($__root, $__doc)) ? trim(substr($__root, strlen($__doc)), '/') : '';
    $__auto = ((($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . ($__base !== '' ? '/' . $__base : '');
} else {
    $__auto = 'http://localhost:8080/vsudotrans'; // CLI (tes)
}
defined('APP_URL')   || define('APP_URL', getenv('VSUDO_APP_URL') ?: $__auto);
// Path aplikasi tanpa host, mis. '' atau '/vsudotrans' — untuk link internal & QR absolut.
defined('APP_URL_PATH') || define('APP_URL_PATH', (($p = parse_url(APP_URL, PHP_URL_PATH)) && $p !== '/') ? rtrim($p, '/') : '');
defined('APP_NAME')  || define('APP_NAME', 'VSudoTrans');
defined('APP_VERSION') || define('APP_VERSION', '0.1.0');

// --- Database --------------------------------------------------------
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'vsudotrans');

// --- Session & keamanan ---------------------------------------------
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 28800); // 8 jam
defined('PER_PAGE')         || define('PER_PAGE', 20);
defined('LOGIN_MAX_ATTEMPTS')    || define('LOGIN_MAX_ATTEMPTS', 5);
defined('LOGIN_MAX_IP_ATTEMPTS') || define('LOGIN_MAX_IP_ATTEMPTS', 20);
defined('LOGIN_LOCKOUT_TIME')    || define('LOGIN_LOCKOUT_TIME', 900); // 15 menit

// --- AI Isi Cepat (9router, OpenAI-compatible) -----------------------
// Kosongkan AI_API_KEY untuk mematikan fitur sepenuhnya (panel tidak muncul).
defined('AI_BASE_URL') || define('AI_BASE_URL', getenv('VSUDO_AI_BASE_URL') ?: 'http://43.173.27.40:20128/v1');
// Kunci TIDAK boleh di-hardcode di repo: isi lewat env VSUDO_AI_API_KEY (atau HERMES_CUSTOM_9ROUTER_API_KEY).
// Kosong = fitur "Isi Cepat" mati (panel tidak muncul), bukan error.
defined('AI_API_KEY')  || define('AI_API_KEY', getenv('VSUDO_AI_API_KEY') ?: (getenv('HERMES_CUSTOM_9ROUTER_API_KEY') ?: ''));
defined('AI_MODEL')    || define('AI_MODEL', getenv('VSUDO_AI_MODEL') ?: 'ikhsan-reason');

// --- Azure Blob Storage ----------------------------------------------
defined('BLOB_STORAGE_CONNECTION_STRING') || define('BLOB_STORAGE_CONNECTION_STRING', getenv('BLOB_STORAGE_CONNECTION_STRING') ?: '');
defined('BLOB_STORAGE_CONTAINER')         || define('BLOB_STORAGE_CONTAINER', getenv('BLOB_STORAGE_CONTAINER') ?: 'vsudotrans');

// --- Identitas perusahaan (untuk cetakan) ---------------------------
defined('COMPANY_NAME')    || define('COMPANY_NAME', 'VSudo Trans');
defined('COMPANY_ADDRESS') || define('COMPANY_ADDRESS', 'Jl. Contoh No. 1, Jakarta');
defined('COMPANY_PHONE')   || define('COMPANY_PHONE', '021-0000000');

// --- Zona waktu ------------------------------------------------------
date_default_timezone_set('Asia/Jakarta');

// --- Session hardening ----------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/azure_blob.php';