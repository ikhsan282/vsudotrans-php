<?php
/**
 * Self-test Tab Pembayaran Sewa: QRIS Otomatis (Create + Live Poll + Auto-Row + Sync).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/invoice_service.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

$pass = 0;
$fail = 0;
function rq_check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}\n";
    }
}

function rq_http_post(string $base, string $jar, string $path, array $fields): array
{
    $bodyFile = tempnam(sys_get_temp_dir(), 'rq_post_');
    file_put_contents($bodyFile, http_build_query($fields));
    $hdrFile = tempnam(sys_get_temp_dir(), 'rq_hdr_');
    $cmd = 'curl.exe -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar)
         . ' -D ' . escapeshellarg($hdrFile)
         . ' -H "Content-Type: application/x-www-form-urlencoded"'
         . ' --data-binary @' . escapeshellarg($bodyFile) . ' ' . escapeshellarg($base . $path);
    $body = (string) shell_exec($cmd);
    $hdrs = (string) @file_get_contents($hdrFile);
    @unlink($bodyFile);
    @unlink($hdrFile);
    $code = 0;
    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $hdrs, $m)) {
        $code = (int) $m[1];
    }
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

echo "=== Test QRIS Otomatis Tab Pembayaran Sewa ===\n";

$db = db();
$branchId = (int) $db->query('SELECT id FROM branches WHERE company_id = 1 LIMIT 1')->fetch_column();
$cityId   = (int) $db->query('SELECT id FROM cities LIMIT 1')->fetch_column();
$distId   = (int) $db->query('SELECT id FROM districts LIMIT 1')->fetch_column();

$paxCode = 'PX-RQ' . random_int(1000, 9999);
$db->query("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, '{$paxCode}', 'Penumpang Test QRIS Rental', '081200001111')");
$paxId = (int) $db->insert_id;

$today = date('Y-m-d');
$bk = booking_create('rental', [
    'company_id'                 => 1,
    'branch_id'                  => $branchId,
    'date'                       => $today,
    'time'                       => '09:00:00',
    'start_date'                 => $today,
    'end_date'                   => date('Y-m-d', strtotime('+2 days')),
    'trip'                       => 3,
    'bbm'                        => 0,
    'pickup_address'             => 'Jl. Test Rental Jemput',
    'pickup_point_city_id'       => $cityId,
    'pickup_point_district_id'   => $distId,
    'delivery_address'           => 'Jl. Test Rental Antar',
    'delivery_point_city_id'     => $cityId,
    'delivery_point_district_id' => $distId,
    'total_price'                => 1000000,
    'category_vehicle_id'        => null,
    'vehicle_id'                 => null,
    'passenger_id'               => $paxId,
    'note'                       => 'Test Rental QRIS Auto',
    'created_user'               => 'test',
]);

$bid   = (int) $bk['id'];
$invId = (int) $bk['invoice_id'];
$inv   = invoice_find($invId);
$invTotalExpected = (float) ($inv['total'] ?? 1000000);
rq_check($bid > 0 && $invId > 0, "Temp rental booking #{$bid} + invoice #{$invId} created");

$base = 'http://127.0.0.1:8080/vsudotrans-php';
$jar  = tempnam(sys_get_temp_dir(), 'rq_jar_');
$createdOrders = [];

try {
    // 1. HTTP Login as admin
    $loginOut = (string) shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(__DIR__ . '/_wa_login.php') . ' ' . escapeshellarg($base) . ' ' . escapeshellarg($jar));
    rq_check(str_contains($loginOut, 'LOGIN_OK'), 'HTTP admin login succeeded');

    // 2. Load detail.php & extract CSRF
    $detailHtml = (string) shell_exec('curl.exe -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar) . ' ' . escapeshellarg($base . '/pages/rental/bookings/detail.php?id=' . $bid));
    rq_check(str_contains($detailHtml, 'id="btnPayQrisAuto"'), 'detail.php renders #btnPayQrisAuto button');
    rq_check(str_contains($detailHtml, 'id="qrisAutoModal"'), 'detail.php renders #qrisAutoModal');
    rq_check(str_contains($detailHtml, 'appendAutoQrisRow'), 'detail.php includes appendAutoQrisRow JS helper');

    $csrf = '';
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $detailHtml, $m)) {
        $csrf = $m[1];
    }
    rq_check($csrf !== '', 'CSRF token extracted from detail.php');

    // 3. Create DP QRIS via AJAX
    $resDp = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'create',
        'booking_id' => $bid,
        'pay_type'   => 'Down Payment (DP)',
        'amount'     => '400000',
    ]);
    $jDp = $resDp['json'] ?? [];
    rq_check($resDp['code'] === 200 && !empty($jDp['ok']), 'qris_ajax act=create succeeded (HTTP 200)');
    rq_check(($jDp['pay_type'] ?? '') === 'Down Payment (DP)', 'pay_type persisted as Down Payment (DP)');
    rq_check((int) round((float) ($jDp['bill_amount'] ?? 0)) === 400000, 'bill_amount matches 400000');
    rq_check(!empty($jDp['order_id']) && !empty($jDp['qris_image_url']), 'QRIS order_id & qris_image_url returned');
    $ordId1 = (string) ($jDp['order_id'] ?? '');
    if ($ordId1 !== '') $createdOrders[] = $ordId1;

    // 4. Duplicate request reuses same PENDING order
    $resDp2 = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'create',
        'booking_id' => $bid,
        'pay_type'   => 'Down Payment (DP)',
        'amount'     => '400000',
    ]);
    rq_check(($resDp2['json']['order_id'] ?? '') === $ordId1, 'Identical DP QRIS request reuses active PENDING order');

    // 5. Different amount creates distinct order
    $resPel = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'create',
        'booking_id' => $bid,
        'pay_type'   => 'Pelunasan',
        'amount'     => '300000',
    ]);
    $ordId2 = (string) ($resPel['json']['order_id'] ?? '');
    if ($ordId2 !== '') $createdOrders[] = $ordId2;
    rq_check($resPel['code'] === 200 && $ordId2 !== '' && $ordId2 !== $ordId1, 'Different amount/type creates separate QRIS order');

    // 6. Reject overpay
    $resOver = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'create',
        'booking_id' => $bid,
        'pay_type'   => 'Pelunasan',
        'amount'     => (string) ((int) round($invTotalExpected) + 50000),
    ]);
    rq_check($resOver['code'] === 422 && empty($resOver['json']['ok']), 'Overpay rejected with HTTP 422');

    // 7. Poll before payment
    $poll1 = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'poll',
        'booking_id' => $bid,
        'order_id'   => $ordId1,
    ]);
    rq_check($poll1['code'] === 200 && ($poll1['json']['order_status'] ?? '') === 'PENDING' && count($poll1['json']['payments'] ?? []) === 0, 'act=poll before settlement returns PENDING and 0 rows');

    // 8. Simulate QRIS settlement via gateway_finalize(), then poll over HTTP
    $fin = gateway_finalize($ordId1, (float) ($jDp['total_amount'] ?? 400100));
    rq_check(!empty($fin['ok']) && (int) ($fin['cost_line_id'] ?? 0) > 0, 'gateway_finalize succeeded for DP QRIS');

    $poll2 = rq_http_post($base, $jar, '/pages/rental/bookings/qris_ajax.php', [
        'csrf_token' => $csrf,
        'act'        => 'poll',
        'booking_id' => $bid,
        'order_id'   => $ordId1,
    ]);
    $pRows = $poll2['json']['payments'] ?? [];
    rq_check(($poll2['json']['order_status'] ?? '') === 'PAID', 'act=poll detects PAID order status');
    rq_check(count($pRows) === 1
        && !empty($pRows[0]['is_auto_qris'])
        && ($pRows[0]['pay_type'] ?? '') === 'Down Payment (DP)'
        && ($pRows[0]['pay_method'] ?? '') === 'QRIS'
        && (int) round((float) ($pRows[0]['amount'] ?? 0)) === 400000,
        'act=poll returns auto QRIS row with preserved DP pay_type, method=QRIS, and exact principal 400000'
    );

    // 9. Verify booking_rental_payments_sync keeps Verified QRIS row without duplicating + adds manual row
    $qrisRowId = (int) ($pRows[0]['id'] ?? 0);
    $totalAfterSync = booking_rental_payments_sync($bid, $invId, 1, [
        ['id' => $qrisRowId, 'pay_type' => 'Down Payment (DP)', 'pay_method' => 'QRIS', 'pay_date' => $today, 'amount' => 400000],
        ['id' => 0, 'pay_type' => 'Pelunasan', 'pay_method' => 'Transfer', 'pay_date' => $today, 'amount' => 600000],
    ], 'test');
    $afterSyncRows = invoice_payments($invId);
    rq_check(count($afterSyncRows) === 2 && abs($totalAfterSync - 1000000.0) < 0.01, 'booking_rental_payments_sync preserves QRIS row & appends manual row without duplication');
} finally {
    @unlink($jar);
    foreach ($createdOrders as $oid) {
        $esc = $db->real_escape_string($oid);
        $db->query("DELETE FROM wa_outbox WHERE event_id = 'pay-{$esc}'");
        $db->query("DELETE FROM payment_gateway_orders WHERE order_id = '{$esc}'");
    }
    $db->query("DELETE FROM payment_gateway_orders WHERE invoice_id = {$invId}");
    $db->query("DELETE FROM booking_cost_lines WHERE booking_type = 'rental' AND booking_id = {$bid}");
    $db->query("DELETE FROM booking_status_history WHERE booking_type = 'rental' AND booking_id = {$bid}");
    $db->query("DELETE FROM booking_passengers WHERE booking_type = 'rental' AND booking_id = {$bid}");
    $db->query("DELETE FROM invoices WHERE id = {$invId}");
    $db->query("DELETE FROM rental_car_bookings WHERE id = {$bid}");
    $db->query("DELETE FROM passengers WHERE id = {$paxId}");
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
