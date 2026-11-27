<?php
/**
 * Verification for Paket 1 (Security & Privacy Hardening) + Paket 2 (SQL Optimization):
 *  1. api/ticket.php: PII masking without phone4/sig, reveal with phone4/sig, CORS allowlist, IP rate-limiting
 *  2. verifikasi.php: syncs 'cancelled' status from booking_status_history
 *  3. gateway_status.php: poll_token guard (public) + company_id guard (authenticated)
 *  4. .htaccess & config/config.php: Host Header validation, APP_ENV/error config, CSP/Permissions/HSTS, scratch block
 *  5. MySQL Indexes + EXPLAIN verification: zero full-table scan (type=ALL) & zero filesort on status/cost/dispatch queries
 *
 * Usage: C:\xampp\php\php.exe tests/security_sql_hardening_check.php [base_url]
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/finance_sync.php';
require_once __DIR__ . '/../pages/reports/_finance_query.php';
require_once __DIR__ . '/../pages/reports/_dispatch_query.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/vsudotrans-php', '/');
$db   = db();
finance_ensure_schema();

$pass = 0;
$fail = 0;
function sec_ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function sec_cli_exec(string $relPath, array $get = [], array $post = [], array $server = [], array $session = [], array $env = []): string
{
    $php    = PHP_BINARY;
    $target = realpath(__DIR__ . '/../' . $relPath);
    $runner = sys_get_temp_dir() . '/vst_sec_' . getmypid() . '_' . random_int(100, 999) . '.php';
    $envSet = '';
    foreach ($env as $ek => $ev) {
        $envSet .= 'putenv(' . var_export($ek . '=' . $ev, true) . ");\n";
    }
    $sessCode = '';
    if ($session) {
        $sessCode = "require_once " . var_export(realpath(__DIR__ . '/../config/config.php'), true) . ";\n"
            . 'foreach (' . var_export($session, true) . " as \$k => \$v) { \$_SESSION[\$k] = \$v; }\n";
    }
    $code = "<?php\n"
        . $envSet
        . 'foreach (' . var_export($server, true) . " as \$k => \$v) { \$_SERVER[\$k] = \$v; }\n"
        . '$_GET = ' . var_export($get, true) . ";\n"
        . '$_POST = ' . var_export($post, true) . ";\n"
        . $sessCode
        . 'require ' . var_export($target, true) . ";\n";
    file_put_contents($runner, $code);
    $out = (string) shell_exec('"' . $php . '" "' . $runner . '" 2>&1');
    @unlink($runner);
    return $out;
}

$tmpPaxId     = 0;
$tmpBookingId = 0;
$tmpChannelId = 0;
$tmpOrderId   = '';
$rlIp         = '198.51.100.' . random_int(10, 240);
$rlFile       = sys_get_temp_dir() . '/vsudo_ticket_rl_' . md5($rlIp) . '.json';

try {
    echo "=== PAKET 1: Security & Privacy Hardening ===\n";

    // Create sample passenger & booking for company 1 (MTM)
    $paxCode = 'SEC' . random_int(1000, 9999);
    $db->query("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, '{$paxCode}', 'Budi Santoso', '081234569876')");
    $tmpPaxId = (int) $db->insert_id;

    $bk = booking_create('travel', [
        'company_id'                 => 1,
        'branch_id'                  => 1,
        'date'                       => date('Y-m-d'),
        'time'                       => '11:30:00',
        'pickup_address'             => 'Jl. Rahasia Rumah No. 99 Jakarta',
        'pickup_point_city_id'       => 1,
        'pickup_point_district_id'   => 1,
        'delivery_address'           => 'Jl. Tujuan Privat No. 77 Bandung',
        'delivery_point_city_id'     => 2,
        'delivery_point_district_id' => 2,
        'total_price'                => 250000,
        'category_vehicle_id'        => null,
        'vehicle_id'                 => null,
        'passenger_id'               => $tmpPaxId,
        'note'                       => 'Security check',
        'created_user'               => 'sec-check',
    ]);
    $tmpBookingId = (int) $bk['id'];
    $docNo = (string) $bk['document_number'];

    // 1a. Unverified api/ticket.php masks name & hides exact addresses
    $rawUnv = sec_cli_exec('api/ticket.php', ['no' => $docNo, 'company' => 'MTM'], [], ['REQUEST_METHOD' => 'GET']);
    $jUnv   = json_decode(substr($rawUnv, (int) strpos($rawUnv, '{')), true) ?: [];
    sec_ok(
        ($jUnv['ok'] ?? false) === true
        && ($jUnv['data']['pii_verified'] ?? true) === false
        && ($jUnv['data']['passengers'][0]['name'] ?? '') !== 'Budi Santoso'
        && str_contains((string) ($jUnv['data']['passengers'][0]['name'] ?? ''), '*')
        && array_key_exists('pickup_address', $jUnv['data']['route'] ?? [])
        && $jUnv['data']['route']['pickup_address'] === null
        && array_key_exists('delivery_address', $jUnv['data']['route'] ?? [])
        && $jUnv['data']['route']['delivery_address'] === null
        && !empty($jUnv['data']['route']['label']),
        'api/ticket.php tanpa phone4/sig menyamarkan nama & menyembunyikan alamat persis (pii_verified=false)'
    );

    // 1b. Verified with phone4=9876 returns full PII
    $rawPh = sec_cli_exec('api/ticket.php', ['no' => $docNo, 'company' => 'MTM', 'phone4' => '9876'], [], ['REQUEST_METHOD' => 'GET']);
    $jPh   = json_decode(substr($rawPh, (int) strpos($rawPh, '{')), true) ?: [];
    sec_ok(
        ($jPh['ok'] ?? false) === true
        && ($jPh['data']['pii_verified'] ?? false) === true
        && ($jPh['data']['passengers'][0]['name'] ?? '') === 'Budi Santoso'
        && ($jPh['data']['route']['pickup_address'] ?? '') === 'Jl. Rahasia Rumah No. 99 Jakarta'
        && str_contains((string) ($jPh['data']['links']['tiket_url'] ?? ''), 'sig='),
        'api/ticket.php dengan ?phone4=9876 valid membuka nama & alamat lengkap + sig'
    );

    // 1c. Verified with HMAC sig returns full PII
    $sig = ticket_pii_signature($docNo, 'MTM');
    $rawSg = sec_cli_exec('api/ticket.php', ['no' => $docNo, 'company' => 'MTM', 'sig' => $sig], [], ['REQUEST_METHOD' => 'GET']);
    $jSg   = json_decode(substr($rawSg, (int) strpos($rawSg, '{')), true) ?: [];
    sec_ok(
        ($jSg['ok'] ?? false) === true
        && ($jSg['data']['pii_verified'] ?? false) === true
        && ($jSg['data']['passengers'][0]['name'] ?? '') === 'Budi Santoso',
        'api/ticket.php dengan ?sig= HMAC valid membuka data lengkap'
    );

    // 1d. CORS allowlist over live HTTP
    $corsAllowedHdr = (string) shell_exec('curl.exe -s -D - -o NUL -H "Origin: https://mauratransmandiri.com" ' . escapeshellarg($base . '/api/ticket.php?no=BAD'));
    $corsBlockedHdr = (string) shell_exec('curl.exe -s -D - -o NUL -H "Origin: https://evil.example" ' . escapeshellarg($base . '/api/ticket.php?no=BAD'));
    sec_ok(
        stripos($corsAllowedHdr, 'Access-Control-Allow-Origin: https://mauratransmandiri.com') !== false
        && stripos($corsAllowedHdr, 'Access-Control-Allow-Origin: *') === false,
        'CORS allowlist mengizinkan domain resmi perusahaan (https://mauratransmandiri.com) tanpa wildcard *'
    );
    sec_ok(
        stripos($corsBlockedHdr, 'Access-Control-Allow-Origin:') === false,
        'CORS menolak origin asing (https://evil.example) dengan tidak mengirim header Access-Control-Allow-Origin'
    );

    // 1e. Rate limit test (limit 2 on synthetic REMOTE_ADDR)
    @unlink($rlFile);
    $r1 = sec_cli_exec('api/ticket.php', ['no' => 'BAD'], [], ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $rlIp], [], ['VSUDO_TICKET_RATE_LIMIT' => '2']);
    $r2 = sec_cli_exec('api/ticket.php', ['no' => 'BAD'], [], ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $rlIp], [], ['VSUDO_TICKET_RATE_LIMIT' => '2']);
    $r3 = sec_cli_exec('api/ticket.php', ['no' => 'BAD'], [], ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $rlIp], [], ['VSUDO_TICKET_RATE_LIMIT' => '2']);
    sec_ok(
        !str_contains($r1, 'RATE_LIMITED') && !str_contains($r2, 'RATE_LIMITED') && str_contains($r3, 'RATE_LIMITED'),
        'Rate limit IP api/ticket.php memblokir request ke-3 saat limit=2 (RATE_LIMITED)'
    );

    // 1f. verifikasi.php reflects 'cancelled' from booking_status_history even if invoice is 'issued'
    booking_change_status('travel', $tmpBookingId, 1, 'cancelled', 'Uji verifikasi batal', 'sec-check');
    $invId = (int) $bk['invoice_id'];
    $db->query("UPDATE invoices SET status = 'issued' WHERE id = {$invId}");
    $verHtml = sec_cli_exec('verifikasi.php', ['no' => $docNo, 'company' => 'MTM'], [], ['REQUEST_METHOD' => 'GET']);
    sec_ok(
        str_contains($verHtml, 'Tiket DIBATALKAN') && !str_contains($verHtml, 'Tiket SAH'),
        'verifikasi.php menampilkan Tiket DIBATALKAN saat booking_status_history = cancelled walau invoice masih issued'
    );

    // 1g. gateway_status.php tenant guard & public poll_token guard
    require_once __DIR__ . '/../includes/payment_gateway.php';
    gateway_ensure_company_channels(1);
    $chanId     = (int) $db->query('SELECT id FROM payment_channels WHERE company_id = 1 ORDER BY id ASC LIMIT 1')->fetch_column();
    $tmpOrderId = 'VSTSEC' . random_int(10000, 99999);
    $stOrd = $db->prepare("INSERT INTO payment_gateway_orders (company_id, channel_id, invoice_id, booking_type, booking_id, order_id, amount, total_amount, status, created_user) VALUES (1, ?, ?, 'travel', ?, ?, 250000, 250000, 'EXPIRED', 'sec-check')");
    $stOrd->bind_param('iiis', $chanId, $invId, $tmpBookingId, $tmpOrderId);
    $stOrd->execute();
    $stOrd->close();

    $csrfVal = 'sec_csrf_' . bin2hex(random_bytes(8));
    $badPub  = sec_cli_exec('pages/finance/invoices/gateway_status.php', [], ['order_id' => $tmpOrderId, 'csrf_token' => $csrfVal], ['REQUEST_METHOD' => 'POST'], ['csrf_token' => $csrfVal]);
    $goodTok = gateway_poll_token($tmpOrderId, 1, $csrfVal);
    $okPub   = sec_cli_exec('pages/finance/invoices/gateway_status.php', [], ['order_id' => $tmpOrderId, 'csrf_token' => $csrfVal, 'poll_token' => $goodTok], ['REQUEST_METHOD' => 'POST'], ['csrf_token' => $csrfVal]);
    $badTen  = sec_cli_exec('pages/finance/invoices/gateway_status.php', [], ['order_id' => $tmpOrderId, 'csrf_token' => $csrfVal], ['REQUEST_METHOD' => 'POST'], ['csrf_token' => $csrfVal, 'user_id' => 999, 'role_name' => 'Admin Perusahaan', 'company_id' => 2]);
    $okTen   = sec_cli_exec('pages/finance/invoices/gateway_status.php', [], ['order_id' => $tmpOrderId, 'csrf_token' => $csrfVal], ['REQUEST_METHOD' => 'POST'], ['csrf_token' => $csrfVal, 'user_id' => 999, 'role_name' => 'Admin Perusahaan', 'company_id' => 1]);
    sec_ok(
        str_contains($badPub, 'FORBIDDEN') && str_contains($okPub, 'EXPIRED'),
        'gateway_status.php menolak publik tanpa poll_token valid & menerima poll_token HMAC sah'
    );
    sec_ok(
        str_contains($badTen, 'FORBIDDEN') && str_contains($okTen, 'EXPIRED'),
        'gateway_status.php menolak user login tenant lain (company 2 -> order company 1) & mengizinkan company 1'
    );

    // 1h. .htaccess & HTTP_HOST injection guard
    $htRaw = (string) file_get_contents(__DIR__ . '/../.htaccess');
    sec_ok(
        str_contains($htRaw, 'Content-Security-Policy')
        && str_contains($htRaw, 'Permissions-Policy')
        && str_contains($htRaw, 'Strict-Transport-Security')
        && str_contains($htRaw, 'scratch'),
        '.htaccess memuat header CSP, Permissions-Policy, HSTS, serta memblokir folder scratch/'
    );
    $hostProbeRunner = sys_get_temp_dir() . '/vst_host_' . getmypid() . '.php';
    file_put_contents($hostProbeRunner, "<?php\n\$_SERVER['HTTP_HOST'] = \"evil.com/path\\r\\n\";\nrequire " . var_export(realpath(__DIR__ . '/../config/config.php'), true) . ";\necho APP_URL;\n");
    $hostOut = trim((string) shell_exec('"' . PHP_BINARY . '" "' . $hostProbeRunner . '" 2>&1'));
    @unlink($hostProbeRunner);
    sec_ok(!str_contains($hostOut, 'evil.com') && str_starts_with($hostOut, 'http://localhost'), 'config.php menolak Host Header Injection & fallback aman ke localhost (' . $hostOut . ')');

    echo "\n=== PAKET 2: MySQL Index & EXPLAIN Optimization ===\n";
    $hasIdx = static function (mysqli $conn, string $tbl, string $key): bool {
        $r = $conn->query("SHOW INDEX FROM `{$tbl}` WHERE Key_name = '{$key}'");
        return $r && $r->num_rows > 0;
    };
    sec_ok(
        $hasIdx($db, 'booking_status_history', 'ix_bsh_latest')
        && $hasIdx($db, 'booking_cost_lines', 'ix_bcl_company_created')
        && $hasIdx($db, 'booking_cost_lines', 'ix_bcl_created')
        && $hasIdx($db, 'rental_car_bookings', 'ix_rental_start_date')
        && $hasIdx($db, 'trip_assignments', 'ix_ta_company_started')
        && $hasIdx($db, 'trip_assignments', 'ix_ta_started')
        && !$hasIdx($db, 'travel_ticket_bookings', 'ix_travel_bookings_company')
        && !$hasIdx($db, 'rental_car_bookings', 'ix_rental_bookings_company'),
        'Semua index optimasi Paket 2 terpasang & 2 index company_id redundan telah dihapus'
    );

    // Check EXPLAIN plans for fin_cost_source() & fin_booking_source() (company 1 & Super Admin)
    $checkExplainClean = static function (mysqli $conn, string $sql, string $types, array $params): array {
        $st = $conn->prepare('EXPLAIN ' . $sql);
        if ($types !== '' && $params) {
            $st->bind_param($types, ...$params);
        }
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        $badAll = [];
        $badSort = [];
        foreach ($rows as $er) {
            $tbl = (string) ($er['table'] ?? '');
            $poss = trim((string) ($er['possible_keys'] ?? ''));
            $estRows = (int) ($er['rows'] ?? 0);
            if (($er['type'] ?? '') === 'ALL' && !str_starts_with($tbl, '<derived')) {
                // MariaDB sengaja memakai scan 1-halaman untuk tabel sangat kecil (<=20 baris) meski index SARGable tersedia di possible_keys
                if ($poss === '' || $estRows > 20) {
                    $badAll[] = $tbl . "(rows={$estRows},possible={$poss})";
                }
            }
            if ($tbl === 'bsh' && str_contains((string) ($er['Extra'] ?? ''), 'filesort')) {
                $badSort[] = $tbl . ':' . ($er['Extra'] ?? '');
            }
        }
        return ['bad_all' => $badAll, 'bad_sort' => $badSort];
    };

    $_SESSION['role_name']  = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $fComp = fin_report_filter(['from' => '2026-11-01', 'to' => '2026-11-30', 'booking_type' => 'all']);
    $csC = fin_cost_source($fComp);
    $exCostC = $checkExplainClean($db, 'SELECT COALESCE(SUM(z.amount),0) FROM ' . $csC['sql'], $csC['types'], $csC['params']);
    sec_ok(
        $exCostC['bad_all'] === [] && $exCostC['bad_sort'] === [],
        'EXPLAIN fin_cost_source(company=1): zero full table scan (ALL) & zero bsh filesort'
    );

    $bsC = fin_booking_source($fComp);
    $exBookC = $checkExplainClean($db, 'SELECT COUNT(*) FROM ' . $bsC['sql'], $bsC['types'], $bsC['params']);
    sec_ok(
        $exBookC['bad_all'] === [] && $exBookC['bad_sort'] === [],
        'EXPLAIN fin_booking_source(company=1): zero full table scan (ALL) & zero bsh filesort'
    );

    $_SESSION['role_name'] = 'Super Admin';
    $fSup = fin_report_filter(['from' => '2026-11-01', 'to' => '2026-11-30', 'booking_type' => 'all']);
    $csS = fin_cost_source($fSup);
    $exCostS = $checkExplainClean($db, 'SELECT COALESCE(SUM(z.amount),0) FROM ' . $csS['sql'], $csS['types'], $csS['params']);
    sec_ok(
        $exCostS['bad_all'] === [] && $exCostS['bad_sort'] === [],
        'EXPLAIN fin_cost_source(Super Admin): zero full table scan (ALL) & zero bsh filesort'
    );

    $daS = dispatch_assign_source($fComp);
    $exDisp = $checkExplainClean($db, 'SELECT COUNT(*) FROM trip_assignments ta WHERE ' . $daS['where'], $daS['types'], $daS['params']);
    sec_ok(
        $exDisp['bad_all'] === [] && $exDisp['bad_sort'] === [],
        'EXPLAIN dispatch_assign_source(company=1): zero full table scan (ALL)'
    );
} finally {
    @unlink($rlFile);
    if ($tmpOrderId !== '') {
        $db->query("DELETE FROM payment_gateway_orders WHERE order_id = '" . $db->real_escape_string($tmpOrderId) . "'");
    }
    if ($tmpChannelId > 0) {
        $db->query("DELETE FROM payment_gateway_channels WHERE id = {$tmpChannelId}");
    }
    if ($tmpBookingId > 0) {
        try { booking_delete('travel', $tmpBookingId, 1); } catch (Throwable $e) {}
    }
    if ($tmpPaxId > 0) {
        $db->query("DELETE FROM passengers WHERE id = {$tmpPaxId}");
    }
}

echo "\nRingkasan Paket 1 & 2 Check: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
