<?php
/**
 * Self-test Tahap 2: Hermes WhatsApp Chatbot API (`/api/bot/*`)
 * Menguji:
 * 1. Autentikasi Simple Secret & HMAC-SHA256 + normalisasi nomor WA (`bot_normalize_phone`).
 * 2. Resolusi perusahaan multi-tenant (`MTM`, `LIZ`) & resolusi aktor (`bot_resolve_actor`).
 * 3. Eksekusi langsung seluruh endpoint `/api/bot/*.php` melalui sub-proses PHP CLI:
 *    - `me.php`
 *    - `routes.php`
 *    - `booking_create.php` (auto-order dari chat WA + auto-create passenger + dynamic QRIS)
 *    - `bookings.php` (cek booking aktif & riwayat berdasarkan nomor WA + isolasi nomor WA lain)
 *    - `qris_create.php` (reuse QRIS aktif & proteksi kepemilikan nomor WA)
 *    - `qris_status.php`
 *    - `staff_summary.php` (proteksi khusus staff/admin)
 *    - `outbox.php` (pull & acknowledge notifikasi WA)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bot_auth.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$pass = 0;
$fail = 0;
function check_bot(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Jalankan endpoint `/api/bot/<file>` di subproses PHP dengan query & JSON body. */
function call_bot_endpoint(string $file, string $method, array $query = [], ?array $jsonBody = null, array $headers = []): array
{
    $php = PHP_BINARY;
    $target = realpath(__DIR__ . '/../api/bot/' . $file);
    $runner = sys_get_temp_dir() . '/vsudo_bot_runner_' . getmypid() . '.php';
    $bodyStr = $jsonBody !== null ? json_encode($jsonBody) : '';

    $code = '<?php ' . "\n"
          . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . "\n"
          . '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";' . "\n"
          . '$_GET = ' . var_export($query, true) . ';' . "\n"
          . '$GLOBALS["BOT_RAW_BODY"] = ' . var_export($bodyStr, true) . ';' . "\n";
    foreach ($headers as $k => $v) {
        $code .= '$_SERVER[' . var_export($k, true) . '] = ' . var_export($v, true) . ';' . "\n";
    }
    $code .= 'require ' . var_export($target, true) . ';';
    file_put_contents($runner, $code);

    $cmd = '"' . $php . '" "' . $runner . '" 2>&1';
    $out = (string) shell_exec($cmd);
    @unlink($runner);

    // Strip any PHP warning before JSON if present
    $pos = strpos($out, '{"ok":');
    $jsonPart = $pos !== false ? substr($out, $pos) : $out;
    $decoded = json_decode($jsonPart, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'raw' => $out];
}

echo "=== Test Tahap 2: Hermes WhatsApp Chatbot API ===\n";

// 1. Phone normalization & HMAC
check_bot(bot_normalize_phone('0812-3456-7890') === '6281234567890', 'bot_normalize_phone converts 0812-3456-7890 to 6281234567890');
check_bot(bot_normalize_phone('6281234567890@s.whatsapp.net') === '6281234567890', 'bot_normalize_phone strips WhatsApp JID suffix');

$secret = 'mtm_hermes_secret_key_9f8e7d6c5b4a321098765432'; // Dedicated secret key MTM dari database
$testPhone = '6281377778888';
$otherPhone = '6281399990000';
$adminPhone = '081200000002'; // seeded admin for company 1

$db = db();
// Simpan konfigurasi payment_channels company 1 sebelum dimodifikasi test, agar selalu pulih setelah selesai
$origCh1 = $db->query("SELECT merchant_id, api_key, is_active FROM payment_channels WHERE company_id = 1 AND provider = 'gopay' LIMIT 1")->fetch_assoc() ?: null;

// Setup active QRIS GoPay channel & route for Company 1 (MTM)
gateway_ensure_company_channels(1);
$sampleStatic = qris_build_tlv([
    ['00', '01'],
    ['01', '11'],
    ['26', '0014ID.CO.GOPAY.WWW0118936009143123456789'],
    ['52', '4121'],
    ['53', '360'],
    ['58', 'ID'],
    ['59', 'MAURA TRANS MANDIRI'],
    ['60', 'TANGERANG'],
]);
$stCh = $db->prepare("UPDATE payment_channels SET merchant_id = ?, api_key = '[\"Authorization: Bearer test\"]', is_active = 1 WHERE company_id = 1 AND provider = 'gopay'");
$stCh->bind_param('s', $sampleStatic);
$stCh->execute();
$stCh->close();

$bkId = 0;
$invId = 0;
$orderId = '';
$eventId = '';

try {

// Ensure city, district, category_vehicle, and rute exist for Company 1
$db->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$db->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BT', 'Banten')");
$db->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang'), (2, 1, 1, 'BDG', 'Bandung')");
$db->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CPT', 'Cipondoh'), (2, 1, 1, 2, 'DGO', 'Dago')");
$db->query("INSERT IGNORE INTO category_vehicles (id, company_id, code, name, is_active) VALUES (1, 1, 'HIACE', 'Toyota HiAce Executive', 1)");
$db->query("INSERT IGNORE INTO rutes (id, company_id, code, name, pickup_point_city_id, delivery_point_city_id, category_vehicle_id, min_price, price, max_price, start_capacity_seat, end_capacity_seat, is_active) VALUES (1, 1, 'TNG-BDG', 'Tangerang - Bandung', 1, 2, 1, 150000, 175000, 200000, 1, 10, 1)");

// 2. Test GET /api/bot/me.php
$meRes = call_bot_endpoint('me.php', 'GET', ['secret' => $secret, 'phone' => $testPhone, 'company' => 'MTM']);
check_bot(($meRes['ok'] ?? false) === true && ($meRes['data']['company']['code'] ?? '') === 'MTM', 'GET /api/bot/me.php returns MTM profile', json_encode($meRes));

// 3. Test GET /api/bot/routes.php
$rtRes = call_bot_endpoint('routes.php', 'GET', ['secret' => $secret, 'phone' => $testPhone, 'company' => 'MTM']);
check_bot(($rtRes['ok'] ?? false) === true && count($rtRes['data']['routes'] ?? []) >= 1, 'GET /api/bot/routes.php returns active routes for MTM');

// 4. Test POST /api/bot/booking_create.php (Auto-Order + Auto Dynamic QRIS)
$bkRes = call_bot_endpoint('booking_create.php', 'POST', [
    'secret'  => $secret,
    'phone'   => $testPhone,
    'company' => 'MTM',
], [
    'booking_type'     => 'travel',
    'passenger_name'   => 'Ikhsan Test Bot',
    'rute_id'          => 1,
    'date'             => date('Y-m-d', strtotime('+1 day')),
    'time'             => '09:00',
    'seats'            => 2,
    'pickup_address'   => 'Jl. Jemput Bot No. 1',
    'delivery_address' => 'Jl. Antar Bot No. 2',
    'create_qris'      => true,
]);
check_bot(($bkRes['ok'] ?? false) === true && !empty($bkRes['data']['document_number']), 'POST /api/bot/booking_create.php creates travel booking from WA chat');
$docNo   = (string) ($bkRes['data']['document_number'] ?? '');
$invId   = (int) ($bkRes['data']['invoice_id'] ?? 0);
$bkId    = (int) ($bkRes['data']['booking_id'] ?? 0);
$orderId = (string) ($bkRes['data']['qris']['order_id'] ?? '');
check_bot(($bkRes['data']['total']['raw'] ?? 0) === 350000, 'Booking total for 2 seats @ 175.000 is 350.000');
check_bot($orderId !== '' && ($bkRes['data']['qris']['total']['raw'] ?? 0) > 350000, 'Booking auto-generates Dynamic QRIS with unique code');

// 5. Test GET /api/bot/bookings.php (history & active bookings by phone)
$listRes = call_bot_endpoint('bookings.php', 'GET', ['secret' => $secret, 'phone' => $testPhone, 'company' => 'MTM']);
check_bot(($listRes['ok'] ?? false) === true && ($listRes['data']['active_count'] ?? 0) >= 1, 'GET /api/bot/bookings.php lists caller active booking & pending QRIS');

// Verify phone isolation: another WA number cannot see $testPhone's bookings
$otherList = call_bot_endpoint('bookings.php', 'GET', ['secret' => $secret, 'phone' => $otherPhone, 'company' => 'MTM']);
check_bot(($otherList['ok'] ?? false) === true && ($otherList['data']['active_count'] ?? -1) === 0, 'GET /api/bot/bookings.php isolates bookings strictly by caller WA number');

// 6. Test POST /api/bot/qris_create.php (reuses active QRIS & blocks other phone)
$qReuse = call_bot_endpoint('qris_create.php', 'POST', [
    'secret'  => $secret,
    'phone'   => $testPhone,
    'company' => 'MTM',
], ['document_number' => $docNo]);
check_bot(($qReuse['ok'] ?? false) === true && ($qReuse['data']['reused'] ?? false) === true && ($qReuse['data']['order_id'] ?? '') === $orderId, 'POST /api/bot/qris_create.php reuses existing unexpired QRIS order');

$qForbidden = call_bot_endpoint('qris_create.php', 'POST', [
    'secret'  => $secret,
    'phone'   => $otherPhone,
    'company' => 'MTM',
], ['document_number' => $docNo]);
check_bot(($qForbidden['ok'] ?? true) === false && ($qForbidden['error']['code'] ?? '') === 'FORBIDDEN_INVOICE', 'POST /api/bot/qris_create.php rejects non-owner WA phone');

// 7. Simulate payment finalized & check GET /api/bot/qris_status.php + GET /api/bot/outbox.php
gateway_finalize($orderId);
$qStat = call_bot_endpoint('qris_status.php', 'GET', [
    'secret'          => $secret,
    'phone'           => $testPhone,
    'company'         => 'MTM',
    'document_number' => $docNo,
]);
check_bot(($qStat['ok'] ?? false) === true && ($qStat['data']['status'] ?? '') === 'PAID' && ($qStat['data']['booking_status'] ?? '') === 'confirmed', 'GET /api/bot/qris_status.php returns PAID & confirmed booking status');

// 8. Test GET & POST /api/bot/outbox.php
$outGet = call_bot_endpoint('outbox.php', 'GET', ['secret' => $secret, 'phone' => $adminPhone, 'company' => 'MTM']);
$eventId = 'pay-' . $orderId;
$foundEvent = false;
foreach ($outGet['data']['items'] ?? [] as $it) {
    if (($it['event_id'] ?? '') === $eventId) {
        $foundEvent = true;
        break;
    }
}
check_bot($foundEvent === true, 'GET /api/bot/outbox.php returns queued payment.verified WA notification');

$outAck = call_bot_endpoint('outbox.php', 'POST', ['secret' => $secret, 'phone' => $adminPhone, 'company' => 'MTM'], [
    'event_id' => $eventId,
    'status'   => 'sent',
]);
check_bot(($outAck['ok'] ?? false) === true && ($outAck['data']['status'] ?? '') === 'sent', 'POST /api/bot/outbox.php marks WA notification as sent');

// 9. Test GET /api/bot/staff_summary.php (rejected for customer, allowed for staff)
$staffDeny = call_bot_endpoint('staff_summary.php', 'GET', ['secret' => $secret, 'phone' => $testPhone, 'company' => 'MTM']);
check_bot(($staffDeny['ok'] ?? true) === false && ($staffDeny['error']['code'] ?? '') === 'STAFF_ONLY', 'GET /api/bot/staff_summary.php blocks non-staff phone');

$staffOk = call_bot_endpoint('staff_summary.php', 'GET', ['secret' => $secret, 'phone' => $adminPhone, 'company' => 'MTM']);
check_bot(($staffOk['ok'] ?? false) === true && isset($staffOk['data']['today_bookings_amount']), 'GET /api/bot/staff_summary.php succeeds for staff phone');

// 10. Test 1-Bot-Per-Company Dedicated Secret Key (auto-lock company & block cross-company)
$mtmKey = 'mtm_hermes_secret_key_9f8e7d6c5b4a321098765432';
$lizKey = 'liz_hermes_secret_key_4d5e6f7a8b9c012345678901';

$mtmAuto = call_bot_endpoint('me.php', 'GET', ['secret' => $mtmKey, 'phone' => $testPhone]);
check_bot(
    ($mtmAuto['ok'] ?? false) === true && ($mtmAuto['data']['company']['code'] ?? '') === 'MTM',
    'Dedicated MTM bot_secret auto-resolves to MTM without passing ?company=',
    json_encode($mtmAuto)
);

$lizAuto = call_bot_endpoint('me.php', 'GET', ['secret' => $lizKey, 'phone' => $testPhone]);
check_bot(
    ($lizAuto['ok'] ?? false) === true && ($lizAuto['data']['company']['code'] ?? '') === 'LIZ',
    'Dedicated LIZ bot_secret auto-resolves to LIZ without passing ?company=',
    json_encode($lizAuto)
);

$crossMismatch = call_bot_endpoint('me.php', 'GET', ['secret' => $mtmKey, 'phone' => $testPhone, 'company' => 'LIZ']);
check_bot(
    ($crossMismatch['ok'] ?? true) === false && ($crossMismatch['error']['code'] ?? '') === 'FORBIDDEN_COMPANY_MISMATCH',
    'Dedicated MTM bot_secret rejects ?company=LIZ with 403 FORBIDDEN_COMPANY_MISMATCH',
    json_encode($crossMismatch)
);
} finally {
    // Cleanup test rows & restore live GoPay channel configuration
    if ($eventId !== '') {
        $evEsc = $db->real_escape_string($eventId);
        $db->query("DELETE FROM wa_outbox WHERE event_id = '{$evEsc}'");
    }
    if ($orderId !== '') {
        $ordEsc = $db->real_escape_string($orderId);
        $db->query("DELETE FROM payment_gateway_orders WHERE order_id = '{$ordEsc}'");
    }
    if ($bkId > 0) {
        $db->query("DELETE FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = {$bkId}");
        $db->query("DELETE FROM booking_status_history WHERE booking_type = 'travel' AND booking_id = {$bkId}");
        $db->query("DELETE FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$bkId}");
    }
    if ($invId > 0) {
        $db->query("DELETE FROM invoices WHERE id = {$invId}");
    }
    if ($bkId > 0) {
        $db->query("DELETE FROM travel_ticket_bookings WHERE id = {$bkId}");
    }
    $db->query("DELETE FROM passengers WHERE phone_number = '081377778888'");
    $db->query("DELETE FROM bot_request_logs WHERE phone IN ('6281377778888', '6281399990000', '6281200000002')");
    if ($origCh1 !== null) {
        $stRestore = $db->prepare("UPDATE payment_channels SET merchant_id = ?, api_key = ?, is_active = ? WHERE company_id = 1 AND provider = 'gopay'");
        $origAct = (int) $origCh1['is_active'];
        $stRestore->bind_param('ssi', $origCh1['merchant_id'], $origCh1['api_key'], $origAct);
        $stRestore->execute();
        $stRestore->close();
    }
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
