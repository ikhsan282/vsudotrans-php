<?php
/**
 * Autentikasi, resolusi tenant (perusahaan), & resolusi identitas nomor WhatsApp
 * untuk semua endpoint `/api/bot/*` di VSudoTrans Multi-Tenant.
 *
 * Mendukung dua mode autentikasi inbound dari Hermes:
 *
 * 1) Mode Sederhana (direkomendasikan untuk tool `curl` Hermes):
 *    - Header `Authorization: Bearer <secret>` + `X-Bot-Phone: <nomor WA>` + `X-Bot-Company: <kode perusahaan, mis. MTM>`
 *    - atau query `?secret=<secret>&phone=<nomor WA>&company=<MTM>`
 *
 * 2) Mode HMAC-SHA256:
 *    - Header `X-Bot-Key`, `X-Bot-Timestamp`, `X-Bot-Phone`, `X-Bot-Signature`, opsional `X-Bot-Company`
 */

if (!defined('BOT_MAX_SKEW'))     define('BOT_MAX_SKEW',     300); // 5 menit
if (!defined('BOT_RATE_PER_MIN')) define('BOT_RATE_PER_MIN', 60);  // per nomor WA per menit

/**
 * Normalisasi nomor telepon Indonesia menjadi bentuk kanonis `628xxxxxxxxx` (9-15 digit).
 * Menerima input `0812...`, `+62 812-...`, `62812...`, atau JID WhatsApp `62812...@s.whatsapp.net`.
 */
function bot_normalize_phone(string $raw): ?string
{
    $d = normalize_phone_62($raw);
    if ($d === null || !preg_match('/^628\d{6,12}$/', $d)) {
        return null;
    }
    return $d;
}

/** Bentuk-bentuk penulisan nomor yang mungkin tersimpan di kolom `phone_number`. */
function bot_phone_variants(string $canonical): array
{
    $tail = substr($canonical, 2); // 812...
    return [$canonical, '0' . $tail, '+' . $canonical, $tail];
}

/** Ambil daftar key `[key_id => ['secret' => ..., 'company' => ...]]` murni dari tabel `companies.bot_secret`. */
function bot_api_keys_normalized(): array
{
    if (isset($GLOBALS['BOT_KEYS_CACHE']) && is_array($GLOBALS['BOT_KEYS_CACHE'])) {
        return $GLOBALS['BOT_KEYS_CACHE'];
    }
    $out = [];

    // Muat secret key bot milik masing-masing perusahaan langsung dari tabel `companies`
    if (function_exists('db')) {
        try {
            $res = db()->query("SELECT code, bot_secret FROM companies WHERE is_active = 1 AND bot_secret IS NOT NULL AND bot_secret <> ''");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $code = strtoupper(trim((string) $row['code']));
                    $sec  = trim((string) $row['bot_secret']);
                    if ($code !== '' && strlen($sec) >= 24) {
                        $out['company-' . strtolower($code)] = [
                            'secret'  => $sec,
                            'company' => $code,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Abaikan bila database belum siap
        }
    }

    $GLOBALS['BOT_KEYS_CACHE'] = $out;
    return $out;
}

/** Verifikasi mode sederhana (Bearer header atau query `?secret=`). */
function bot_verify_simple(array $server, array $keys): ?array
{
    $token = '';
    $auth  = trim((string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    } elseif (isset($_GET['secret']) && is_string($_GET['secret'])) {
        $token = trim($_GET['secret']);
    }
    if ($token === '') return null;

    $matchedKey = null;
    $matchedMeta = null;
    // Prioritaskan key yang terikat langsung ke perusahaan (1 Bot Hermes = 1 Perusahaan)
    foreach ($keys as $kid => $meta) {
        if ($meta['company'] !== null && strlen($meta['secret']) >= 24 && hash_equals($meta['secret'], $token)) {
            $matchedKey = (string) $kid;
            $matchedMeta = $meta;
            break;
        }
    }
    if ($matchedKey === null) {
        foreach ($keys as $kid => $meta) {
            if (strlen($meta['secret']) >= 24 && hash_equals($meta['secret'], $token)) {
                $matchedKey = (string) $kid;
                $matchedMeta = $meta;
                break;
            }
        }
    }
    if ($matchedKey === null) {
        return ['ok' => false, 'status' => 401, 'code' => 'BAD_SECRET', 'reason' => 'secret key did not match'];
    }

    $rawPhone = trim((string) ($server['HTTP_X_BOT_PHONE'] ?? ($_GET['phone'] ?? '')));
    $phone = bot_normalize_phone($rawPhone);
    if ($phone === null) {
        return ['ok' => false, 'status' => 400, 'code' => 'INVALID_PHONE', 'reason' => 'missing or invalid phone parameter/header'];
    }

    $boundCompany = $matchedMeta['company'] ?? null;
    $explicitComp = trim((string) ($server['HTTP_X_BOT_COMPANY'] ?? ($_GET['company'] ?? '')));
    if ($boundCompany !== null) {
        if ($explicitComp !== '' && strtoupper($explicitComp) !== $boundCompany) {
            return [
                'ok'     => false,
                'status' => 403,
                'code'   => 'FORBIDDEN_COMPANY_MISMATCH',
                'reason' => "Secret key bot ini khusus untuk perusahaan {$boundCompany} dan tidak dapat mengakses {$explicitComp}",
            ];
        }
        $compCode = $boundCompany;
        $locked   = true;
    } else {
        $compCode = strtoupper($explicitComp !== '' ? $explicitComp : (defined('BOT_DEFAULT_COMPANY') ? BOT_DEFAULT_COMPANY : 'MTM'));
        $locked   = false;
    }

    return [
        'ok'             => true,
        'key_id'         => $matchedKey,
        'phone'          => $phone,
        'company_code'   => $compCode,
        'company_locked' => $locked,
    ];
}

/** Verifikasi tanda tangan request dari Hermes (Mode Sederhana atau HMAC-SHA256). */
function bot_verify_request(array $server, string $rawBody): array
{
    $keys = bot_api_keys_normalized();
    if (!$keys) {
        return ['ok' => false, 'status' => 503, 'code' => 'BOT_NOT_CONFIGURED', 'reason' => 'Tidak ada bot_secret perusahaan yang aktif di database'];
    }

    $simple = bot_verify_simple($server, $keys);
    if ($simple !== null) {
        return $simple;
    }

    $keyId = trim((string) ($server['HTTP_X_BOT_KEY'] ?? ''));
    $tsRaw = trim((string) ($server['HTTP_X_BOT_TIMESTAMP'] ?? ''));
    $phone = trim((string) ($server['HTTP_X_BOT_PHONE'] ?? ''));
    $sig   = strtolower(trim((string) ($server['HTTP_X_BOT_SIGNATURE'] ?? '')));

    if ($keyId === '' || $tsRaw === '' || $phone === '' || $sig === '') {
        return ['ok' => false, 'status' => 401, 'code' => 'MISSING_AUTH_HEADERS', 'reason' => 'missing auth headers or Bearer token'];
    }
    if (!isset($keys[$keyId]) || strlen($keys[$keyId]['secret']) < 24) {
        return ['ok' => false, 'status' => 401, 'code' => 'UNKNOWN_KEY', 'reason' => 'unknown X-Bot-Key'];
    }
    if (!ctype_digit($tsRaw)) {
        return ['ok' => false, 'status' => 401, 'code' => 'BAD_TIMESTAMP', 'reason' => 'non-digit timestamp'];
    }
    $ts = (int) $tsRaw;
    if (abs(time() - $ts) > BOT_MAX_SKEW) {
        return ['ok' => false, 'status' => 401, 'code' => 'TIMESTAMP_SKEW', 'reason' => 'clock skew exceeded'];
    }
    $normPhone = bot_normalize_phone($phone);
    if ($normPhone === null) {
        return ['ok' => false, 'status' => 400, 'code' => 'INVALID_PHONE', 'reason' => 'invalid phone format'];
    }

    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    $uri    = (string) ($server['REQUEST_URI'] ?? '');
    $base   = implode("\n", [$tsRaw, $normPhone, $method, $uri, hash('sha256', $rawBody)]);
    $expect = hash_hmac('sha256', $base, $keys[$keyId]['secret']);

    if (!hash_equals($expect, $sig)) {
        return ['ok' => false, 'status' => 401, 'code' => 'BAD_SIGNATURE', 'reason' => 'HMAC signature mismatch'];
    }

    $boundCompany = $keys[$keyId]['company'] ?? null;
    $explicitComp = trim((string) ($server['HTTP_X_BOT_COMPANY'] ?? ($_GET['company'] ?? '')));
    if ($boundCompany !== null) {
        if ($explicitComp !== '' && strtoupper($explicitComp) !== $boundCompany) {
            return [
                'ok'     => false,
                'status' => 403,
                'code'   => 'FORBIDDEN_COMPANY_MISMATCH',
                'reason' => "Key '{$keyId}' khusus untuk perusahaan {$boundCompany} dan tidak dapat mengakses {$explicitComp}",
            ];
        }
        $compCode = $boundCompany;
        $locked   = true;
    } else {
        $compCode = strtoupper($explicitComp !== '' ? $explicitComp : (defined('BOT_DEFAULT_COMPANY') ? BOT_DEFAULT_COMPANY : 'MTM'));
        $locked   = false;
    }

    return [
        'ok'             => true,
        'key_id'         => $keyId,
        'phone'          => $normPhone,
        'company_code'   => $compCode,
        'company_locked' => $locked,
    ];
}

/** Resolusi tenant perusahaan berdasarkan kode (`MTM`, `GTM`, `NTM`, `TTM`, `LIZ`). */
function bot_resolve_company(mysqli $db, string $companyCode): ?array
{
    $code = strtoupper(trim($companyCode));
    $st = $db->prepare('SELECT id, group_id, code, name, phone_number, website, email, address, city, logo, stamp, bot_phone FROM companies WHERE code = ? AND is_active = 1 LIMIT 1');
    $st->bind_param('s', $code);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/**
 * Cari identitas pengirim di database berdasarkan nomor telepon kanonis & perusahaan aktif:
 * - `passengers`: daftar profil penumpang milik nomor tersebut di perusahaan ini
 * - `staff`     : akun `users` (Admin Perusahaan / Super Admin / Staff) bila nomor cocok
 * - `driver`    : data `employees` bila nomor cocok sebagai karyawan/driver perusahaan ini
 */
function bot_resolve_actor(mysqli $db, int $companyId, string $canonicalPhone, bool $forceRefresh = false): array
{
    $cacheKey = $companyId . ':' . $canonicalPhone;
    if (!$forceRefresh && isset($GLOBALS['BOT_ACTOR_CACHE'][$cacheKey])) {
        return $GLOBALS['BOT_ACTOR_CACHE'][$cacheKey];
    }

    $variants = bot_phone_variants($canonicalPhone);
    $tail9    = substr($canonicalPhone, -9); // fallback pencocokan akhiran nomor
    $likeTail = '%' . $tail9;

    // 1. Cari di tabel passengers milik company_id ini (utamakan Index Lookup ix_passengers_company_phone)
    $st = $db->prepare(
        "SELECT id, code, name, phone_number, email
           FROM passengers
          WHERE company_id = ? AND phone_number IN (?,?,?,?)
          ORDER BY id DESC"
    );
    $st->bind_param('issss', $companyId, $variants[0], $variants[1], $variants[2], $variants[3]);
    $st->execute();
    $passengers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (!$passengers) {
        $st = $db->prepare(
            "SELECT id, code, name, phone_number, email
               FROM passengers
              WHERE company_id = ?
                AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE ?
              ORDER BY id DESC"
        );
        $st->bind_param('is', $companyId, $likeTail);
        $st->execute();
        $passengers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // 2. Cari di tabel users (utamakan Index Lookup ix_users_phone)
    $st = $db->prepare(
        "SELECT u.id, u.username, u.full_name, u.role_id, r.name AS role_name
           FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE u.is_active = 1
            AND u.phone_number IN (?,?,?,?)
            AND (u.role_id = 1 OR u.company_id = ? OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = ?))
          ORDER BY u.role_id ASC LIMIT 1"
    );
    $st->bind_param('ssssii', $variants[0], $variants[1], $variants[2], $variants[3], $companyId, $companyId);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$u) {
        $st = $db->prepare(
            "SELECT u.id, u.username, u.full_name, u.role_id, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND REPLACE(REPLACE(REPLACE(u.phone_number, '-', ''), ' ', ''), '+', '') LIKE ?
                AND (u.role_id = 1 OR u.company_id = ? OR EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id AND uc.company_id = ?))
              ORDER BY u.role_id ASC LIMIT 1"
        );
        $st->bind_param('sii', $likeTail, $companyId, $companyId);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();
    }

    $staff = null;
    if ($u) {
        $staff = [
            'user_id'    => (int) $u['id'],
            'username'   => $u['username'],
            'name'       => $u['full_name'],
            'role_id'    => (int) $u['role_id'],
            'role_label' => $u['role_name'],
        ];
    }

    // 3. Cari di tabel employees (utamakan Index Lookup ix_employees_company_phone)
    $st = $db->prepare(
        "SELECT id, code, name, job_title, job_position
           FROM employees
          WHERE company_id = ? AND status = 'aktif'
            AND phone_number IN (?,?,?,?)
          LIMIT 1"
    );
    $st->bind_param('issss', $companyId, $variants[0], $variants[1], $variants[2], $variants[3]);
    $st->execute();
    $emp = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    if (!$emp) {
        $st = $db->prepare(
            "SELECT id, code, name, job_title, job_position
               FROM employees
              WHERE company_id = ? AND status = 'aktif'
                AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE ?
              LIMIT 1"
        );
        $st->bind_param('is', $companyId, $likeTail);
        $st->execute();
        $emp = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }

    $actor = [
        'phone'      => $canonicalPhone,
        'registered' => !empty($passengers) || $staff !== null || $emp !== null,
        'passengers' => $passengers,
        'is_staff'   => $staff !== null,
        'staff'      => $staff,
        'is_driver'  => $emp !== null,
        'employee'   => $emp,
    ];
    $GLOBALS['BOT_ACTOR_CACHE'][$cacheKey] = $actor;
    return $actor;
}

/**
 * Pastikan pengirim memiliki profil di tabel `passengers` untuk perusahaan ini.
 * Bila belum ada, otomatis dibuatkan agar pelanggan baru bisa langsung auto-order dari WA!
 */
function bot_ensure_passenger(mysqli $db, int $companyId, string $canonicalPhone, string $name = ''): array
{
    $actor = bot_resolve_actor($db, $companyId, $canonicalPhone);
    if (!empty($actor['passengers'])) {
        $primary = $actor['passengers'][0];
        // Bila pelanggan mengirim nama baru yang lebih lengkap dari placeholder, perbarui namanya
        $cleanName = trim($name);
        if ($cleanName !== '' && strcasecmp($cleanName, (string) $primary['name']) !== 0 && str_starts_with((string) $primary['name'], 'Pelanggan WA')) {
            $u = $db->prepare('UPDATE passengers SET name = ?, modified_user = "hermes-bot", modified_date = NOW() WHERE id = ?');
            $pid = (int) $primary['id'];
            $u->bind_param('si', $cleanName, $pid);
            $u->execute();
            $u->close();
            $primary['name'] = $cleanName;
            unset($GLOBALS['BOT_ACTOR_CACHE'][$companyId . ':' . $canonicalPhone]);
        }
        return $primary;
    }

    $cleanName = trim($name) !== '' ? mb_substr(trim($name), 0, 255) : ('Pelanggan WA ' . substr($canonicalPhone, -4));

    for ($i = 0; $i < 10; $i++) {
        $code = 'WA-' . strtoupper( substr(bin2hex(random_bytes(4)), 0, 7) );
        $st = $db->prepare('SELECT 1 FROM passengers WHERE company_id = ? AND code = ? LIMIT 1');
        $st->bind_param('is', $companyId, $code);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;
        $st->close();
        if (!$exists) {
            $ins = $db->prepare(
                "INSERT INTO passengers (company_id, code, name, phone_number, note, created_user)
                 VALUES (?, ?, ?, ?, 'Auto-registered via WhatsApp Bot Hermes', 'hermes-bot')"
            );
            $ins->bind_param('isss', $companyId, $code, $cleanName, $canonicalPhone);
            $ins->execute();
            $id = (int) $ins->insert_id;
            $ins->close();
            unset($GLOBALS['BOT_ACTOR_CACHE'][$companyId . ':' . $canonicalPhone]);
            return [
                'id'           => $id,
                'code'         => $code,
                'name'         => $cleanName,
                'phone_number' => $canonicalPhone,
                'email'        => null,
            ];
        }
    }
    throw new RuntimeException('Gagal membuat kode penumpang baru.');
}

/** Format nominal uang ganda (raw integer + string terformat Rupiah) untuk respons JSON bot. */
function bot_money(float $n): array
{
    $raw = (int) round($n);
    return ['raw' => $raw, 'formatted' => rupiah($n)];
}

/** Bangun base URL publik aplikasi. */
function bot_base_url(): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . APP_URL_PATH;
}

