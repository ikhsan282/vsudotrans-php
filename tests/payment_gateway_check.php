<?php
/**
 * Self-test Tahap 1: Payment Gateway QRIS Dinamis Multi-Tenant
 * Menguji:
 * 1. EMVCo TLV parser, builder, CRC16-CCITT, dan konversi Static-to-Dynamic QRIS (Tag 01='12', Tag 54=nominal, Tag 63=CRC).
 * 2. Isolasi channel per tenant (company_id) & pencegahan tabrakan kode unik 3 digit (`gq_unique_touch`).
 * 3. Klaim signature mutasi (`gq_claim`) agar 1 mutasi merchant tidak melunasi 2 order berbeda.
 * 4. Finalisasi atomik (`gateway_finalize`): mencatat `booking_cost_lines`, menyinkronkan `total_payment`,
 *    mengubah status booking dari `draft` ke `confirmed` saat lunas, dan mengantrekan event `wa_outbox`.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payment_gateway.php';
require_once __DIR__ . '/../includes/qr_svg.php';

$pass = 0;
$fail = 0;
function check_pg(bool $cond, string $label): void
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

echo "=== Test Tahap 1: Payment Gateway QRIS Dinamis Multi-Tenant ===\n";

// 1. EMVCo Static-to-Dynamic QRIS
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
check_pg(substr($sampleStatic, 0, 12) === '000201010211', 'Static QRIS has Tag 00=01 and Tag 01=11');

$dynamic = qris_make_dynamic($sampleStatic, 150234);
check_pg($dynamic !== null, 'qris_make_dynamic produces valid dynamic QRIS string');
$parsed = qris_parse_tlv((string) $dynamic);
$tagMap = [];
foreach ($parsed ?: [] as [$t, $v]) {
    $tagMap[$t] = $v;
}
check_pg(($tagMap['01'] ?? '') === '12', 'Dynamic QRIS updates Tag 01 to 12');
check_pg(($tagMap['54'] ?? '') === '150234', 'Dynamic QRIS injects Tag 54 with exact integer amount 150234');
$expectedCrc = qris_crc16(substr((string) $dynamic, 0, -4));
check_pg(($tagMap['63'] ?? '') === $expectedCrc, 'Dynamic QRIS Tag 63 matches recalculated CRC16-CCITT (' . $expectedCrc . ')');
check_pg(qris_validate_static($sampleStatic) !== null, 'qris_validate_static rejects dummy sample MPAN 936009143123456789');

$realStaticSample = '00020101021126610014COM.GO-JEK.WWW01189360091439066772990210G9066772990303UMI51440014ID.CO.QRIS.WWW0215ID10265478591690303UMI5204478953033605802ID5919Maura Trans Mandiri6009TANGERANG61051513362140703A011103621630406CD';
check_pg(qris_validate_static($realStaticSample) === null, 'qris_validate_static accepts valid real GoPay QRIS with NMID & CRC');
$realDynamic = qris_make_dynamic($realStaticSample, 500369);
check_pg($realDynamic !== null && qris_merchant_fingerprint($realDynamic) === qris_merchant_fingerprint($realStaticSample), 'Dynamic QRIS preserves exact Tag 26–51 merchant fingerprint');
check_pg(str_contains(gq_qr_url((string) $realDynamic), '&qzone=4&data='), 'gq_qr_url includes qzone=4 quiet zone');
$qrT0 = hrtime(true);
$localSvgUri = qr_svg_data_uri((string) $realDynamic, 4, 230);
$qrElapsedMs = (hrtime(true) - $qrT0) / 1e6;
$localSvgDecoded = str_starts_with($localSvgUri, 'data:image/svg+xml;base64,')
    ? (string) base64_decode(substr($localSvgUri, strlen('data:image/svg+xml;base64,')), true)
    : '';
check_pg(
    str_starts_with($localSvgUri, 'data:image/svg+xml;base64,')
        && str_contains($localSvgDecoded, '<svg ')
        && str_contains($localSvgDecoded, '<path d="M')
        && $qrElapsedMs < 250.0,
    'qr_svg_data_uri generates local crisp SVG Data URI cleanly (' . round($qrElapsedMs, 1) . ' ms)'
);

// 2. Multi-tenant channel & unique code collision avoidance + atomic finalize
$db = db();
gateway_ensure_company_channels(1);
$stCh = $db->prepare("SELECT * FROM payment_channels WHERE company_id = 1 AND provider = 'gopay' LIMIT 1");
$stCh->execute();
$ch = $stCh->get_result()->fetch_assoc();
$stCh->close();
check_pg(!empty($ch['id']), 'Default GoPay channel created for company #1 (MTM)');

$ch['merchant_id'] = $sampleStatic;
$ch['api_key']     = '["Authorization: Bearer test-token"]';

// Create temporary test booking & invoice for company #1
$branchId = (int) $db->query('SELECT id FROM branches WHERE company_id = 1 LIMIT 1')->fetch_column();
$cityId   = (int) $db->query('SELECT id FROM cities LIMIT 1')->fetch_column();
$distId   = (int) $db->query('SELECT id FROM districts LIMIT 1')->fetch_column();
if ($cityId === 0 || $distId === 0) {
    $db->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
    $db->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BT', 'Banten')");
    $db->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
    $db->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CPT', 'Cipondoh')");
    $cityId = 1;
    $distId = 1;
}

$paxCode = 'PX-T' . random_int(1000, 9999);
$db->query("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, '{$paxCode}', 'Penumpang Test QRIS', '081299998888')");
$paxId = (int) $db->insert_id;

$bk = booking_create('travel', [
    'company_id'              => 1,
    'branch_id'               => $branchId,
    'date'                    => date('Y-m-d'),
    'time'                    => '08:00:00',
    'pickup_address'          => 'Jl. Test Jemput',
    'pickup_point_city_id'    => $cityId,
    'pickup_point_district_id'=> $distId,
    'delivery_address'        => 'Jl. Test Antar',
    'delivery_point_city_id'  => $cityId,
    'delivery_point_district_id' => $distId,
    'total_price'             => 150000,
    'category_vehicle_id'     => null,
    'vehicle_id'              => null,
    'passenger_id'            => $paxId,
    'note'                    => 'Test QRIS',
    'created_user'            => 'test',
]);

$inv = invoice_find((int) $bk['invoice_id']);
check_pg($inv !== null && $inv['pay_status'] === 'unpaid', 'New booking starts with unpaid invoice');
check_pg(booking_current_status('travel', (int) $bk['id']) === 'draft', 'New booking starts in draft status');

$ordId1 = gateway_make_order_id((int) $inv['id']);
$res1 = gq_create($ch, $ordId1, 150000);
check_pg($res1['ok'] === true, 'gq_create succeeds for order 1');
gateway_store_order(1, (int) $ch['id'], $ordId1, $inv, 150000, $res1['data'], 'whatsapp_bot', 'hermes');

// Test unique code collision when same base amount is requested on same channel
$ordId2 = $ordId1; // same seed to force collision check
$code2 = gq_unique_touch(150000, $ordId2, (int) $ch['id']);
$total1 = (int) $res1['data']['total_amount'];
check_pg((150000 + $code2) !== $total1, 'gq_unique_touch avoids active PENDING total_amount collision');

// Test gq_claim idempotency & anti-double-claim
$claimed1 = gq_claim((int) $ch['id'], $ordId1, 'GOPAY-TX-999111');
$claimedOther = gq_claim((int) $ch['id'], 'OTHER-ORDER-ID', 'GOPAY-TX-999111');
check_pg($claimed1 === true && $claimedOther === false, 'gq_claim prevents another order from claiming the same merchant transaction_id');

// Test atomic gateway_finalize
$fin = gateway_finalize($ordId1, (float) $total1);
check_pg($fin['ok'] === true && $fin['cost_line_id'] > 0, 'gateway_finalize succeeds and creates booking_cost_lines payment row');

$invAfter = invoice_find((int) $inv['id']);
check_pg($invAfter['pay_status'] === 'paid' && (float) $invAfter['paid_total'] === 150000.0, 'Invoice is now marked paid with principal amount 150000');
check_pg(booking_current_status('travel', (int) $bk['id']) === 'confirmed', 'Booking status automatically transitioned from draft to confirmed upon full QRIS payment');

// Cleanup test rows
$db->query("DELETE FROM wa_outbox WHERE event_id = 'pay-{$ordId1}'");
$db->query("DELETE FROM payment_gateway_orders WHERE order_id = '{$ordId1}'");
$db->query("DELETE FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = " . (int) $bk['id']);
$db->query("DELETE FROM booking_status_history WHERE booking_type = 'travel' AND booking_id = " . (int) $bk['id']);
$db->query("DELETE FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = " . (int) $bk['id']);
$db->query("DELETE FROM invoices WHERE id = " . (int) $inv['id']);
$db->query("DELETE FROM travel_ticket_bookings WHERE id = " . (int) $bk['id']);
$db->query("DELETE FROM passengers WHERE id = {$paxId}");

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

