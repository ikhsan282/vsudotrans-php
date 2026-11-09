<?php
/**
 * Salin file ini menjadi config/bot_secrets.php lalu isi secret asli.
 * Buat secret acak dengan:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */
if (!defined('BOT_API_KEYS')) {
    define('BOT_API_KEYS', [
        'hermes-prod' => 'GANTI_DENGAN_SECRET_ACAK_MINIMAL_32_KARAKTER',
    ]);
}
if (!defined('BOT_DEFAULT_COMPANY')) define('BOT_DEFAULT_COMPANY', 'MTM');
if (!defined('BOT_WEBHOOK_URL'))     define('BOT_WEBHOOK_URL',     '');
if (!defined('BOT_WEBHOOK_SECRET'))  define('BOT_WEBHOOK_SECRET',  '');

