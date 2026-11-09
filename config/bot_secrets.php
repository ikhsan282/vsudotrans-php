<?php
/**
 * Konfigurasi rahasia untuk integrasi Chatbot WhatsApp Hermes -> VSudoTrans Multi-Tenant.
 *
 * BOT_API_KEYS       : daftar key_id => secret (atau array ['secret'=>..., 'company'=>'MTM']).
 *                      Setiap key minimal 24 karakter.
 * BOT_DEFAULT_COMPANY: kode perusahaan default bila Hermes tidak mengirim header X-Bot-Company / ?company=.
 * BOT_WEBHOOK_URL    : URL HTTPS endpoint Hermes penerima event outbound (kosongkan bila memakai mode pull).
 * BOT_WEBHOOK_SECRET : secret HMAC-SHA256 untuk menandatangani webhook outbound VSudoTrans -> Hermes.
 */
if (!defined('BOT_API_KEYS')) {
    define('BOT_API_KEYS', [
        'hermes-prod' => 'mj52qt4B68sJzw8mw8NLM6GG5H6CfFnJJrOoX3-e72w',
        'hermes-mtm'  => ['secret' => 'mtm_hermes_secret_key_9f8e7d6c5b4a321098765432', 'company' => 'MTM'],
        'hermes-gtm'  => ['secret' => 'gtm_hermes_secret_key_1a2b3c4d5e6f789012345678', 'company' => 'GTM'],
        'hermes-ntm'  => ['secret' => 'ntm_hermes_secret_key_2b3c4d5e6f7a890123456789', 'company' => 'NTM'],
        'hermes-ttm'  => ['secret' => 'ttm_hermes_secret_key_3c4d5e6f7a8b901234567890', 'company' => 'TTM'],
        'hermes-liz'  => ['secret' => 'liz_hermes_secret_key_4d5e6f7a8b9c012345678901', 'company' => 'LIZ'],
    ]);
}
if (!defined('BOT_DEFAULT_COMPANY')) define('BOT_DEFAULT_COMPANY', 'MTM');
if (!defined('BOT_WEBHOOK_URL'))     define('BOT_WEBHOOK_URL',     '');
if (!defined('BOT_WEBHOOK_SECRET'))  define('BOT_WEBHOOK_SECRET',  'mj52qt4B68sJzw8mw8NLM6GG5H6CfFnJJrOoX3-e72w');

