<?php
/**
 * Self-test Mode Grup WhatsApp Admin Hermes:
 * 1. Parser form WA nyata (styled NOTE, emoji prefix, tanggal/jam Indonesia, 250rb, Gambir/Panyileukan geo).
 * 2. Parser form acak / tidak terstruktur (besok, jam 7 malam, 2 kursi, 500 ribu).
 * 3. Kontrol RBAC & isolasi perusahaan (non-staff -> 403, Staff read-only -> 200 rekap / 403 ingest, LIZ -> 404 pada tiket MTM).
 * 4. Siklus E2E: dry_run -> ingest 201 -> audit_trails -> duplikat 409 -> rekap full & driver -> edit + DP partial -> tolak over-reduce -> tolak delete berbayar -> cancel -> delete draft (wajib confirm).
 * 5. Pembersihan seluruh baris uji di blok finally.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bot_auth.php';
require_once __DIR__ . '/../includes/bot_staff_booking.php';

$pass = 0;
$fail = 0;
function check_grp(bool $cond, string $label, string $detail = ''): void
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

/** Eksekusi endpoint `/api/bot/<file>` di subproses PHP terisolasi dan kembalikan array `[status, json]`. */
function call_staff_bot(string $file, string $method, array $query = [], ?array $jsonBody = null, array $headers = []): array
{
    $php     = PHP_BINARY;
    $target  = realpath(__DIR__ . '/../api/bot/' . $file);
    $runner  = sys_get_temp_dir() . '/vsudo_staff_bot_' . getmypid() . '.php';
    $bodyStr = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE) : '';

    $code = '<?php' . "\n"
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';' . "\n"
        . '$_SERVER["HTTP_HOST"] = "localhost:8080";' . "\n"
        . '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";' . "\n"
        . '$_GET = ' . var_export($query, true) . ';' . "\n"
        . '$GLOBALS["BOT_RAW_BODY"] = ' . var_export($bodyStr, true) . ';' . "\n";
    foreach ($headers as $k => $v) {
        $code .= '$_SERVER[' . var_export($k, true) . '] = ' . var_export($v, true) . ';' . "\n";
    }
    $code .= 'register_shutdown_function(function(){ echo "\n__HTTP_CODE__:" . http_response_code(); });' . "\n";
    $code .= 'require ' . var_export($target, true) . ';';
    file_put_contents($runner, $code);

    $out = (string) shell_exec('"' . $php . '" "' . $runner . '" 2>&1');
    @unlink($runner);

    $httpCode = 0;
    if (preg_match('/__HTTP_CODE__:(\d+)\s*$/', $out, $cm)) {
        $httpCode = (int) $cm[1];
        $out      = substr($out, 0, -strlen($cm[0]));
    }
    $pos     = strpos($out, '{"ok":');
    $jsonStr = $pos !== false ? substr($out, $pos) : $out;
    $decoded = json_decode(trim($jsonStr), true);
    if (!is_array($decoded)) {
        $decoded = ['ok' => false, 'raw' => $out];
    }
    return ['status' => $httpCode, 'body' => $decoded, 'raw' => $out];
}

echo "=== Test Grup WhatsApp Admin Hermes ===\n";

$db        = db();
$secretMtm = (string) $db->query("SELECT bot_secret FROM companies WHERE code = 'MTM' LIMIT 1")->fetch_column();
$secretLiz = (string) $db->query("SELECT bot_secret FROM companies WHERE code = 'LIZ' LIMIT 1")->fetch_column();

$adminPhone = '6281200000002'; // Admin Perusahaan MTM
$staffPhone = '6281200000004'; // Staff MTM (hanya *.view)
$guestPhone = '6281399887766'; // Nomor non-staff

$createdBkIds = [];
$createdPaxId = 0;
$testPhones   = ['6285889699999', '6287712345678', $guestPhone, $adminPhone, $staffPhone];

try {
    // -----------------------------------------------------------------
    // 1. Parser Form Nyata Admin (dengan emoji, 250rb, styled 𝙽𝙾𝚃𝙴)
    // -----------------------------------------------------------------
    $sampleForm = <<<'TXT'
Form Pemesanan Tiket Travel Door to door
_______________
https://mauratransmandiri.com

👱♂️Nama : Bryan M. W
📱No Hp : 085889611974
🏠 Alamat Penjemputan : Jl. Batu Tulis IX no. 26A, Kel. Kebon Kelapa, Gambir, Jakarta Pusat
🚘 Alamat Tujuan : Panyileukan Regency Blok C no. 2, Cipadung Kidul, Panyileukan, Kota Bandung
👨👦👦 Jumlah Tiket/Kursi : 1
📆 Hari & Tanggal : Jum'at, 20 November 2026
⏰ Jam Berangkat : 19.00
🛍️ Barang bawaan : 
💰 Tarif : 250rb

𝙽𝙾𝚃𝙴 : 
1. Jika barang bawaan lebih dari satu harap info ke admin🙏🙏
2. Jika ada keperluan kejar waktu untuk acara atau kerjaan lain yang mendesak disarankan memilih travel carter agar tidak terlambat

Terimakasih
TXT;

    $p1 = bot_staff_parse_form_text($db, 1, $sampleForm, 'travel', false);
    check_grp($p1['passenger_name'] === 'Bryan M. W', 'Sample form extracts passenger_name = Bryan M. W');
    check_grp($p1['passenger_phone'] === '6285889611974', 'Sample form normalizes phone = 6285889611974');
    check_grp($p1['date'] === '2026-11-20', 'Sample form parses Indonesian date = 2026-11-20', json_encode($p1['date']));
    check_grp($p1['time'] === '19:00', 'Sample form parses time 19.00 = 19:00', json_encode($p1['time']));
    check_grp((int) $p1['seats'] === 1, 'Sample form parses seats = 1');
    check_grp(abs((float) $p1['total_price'] - 250000.0) < 0.01, 'Sample form parses 250rb = 250000');
    check_grp((int) $p1['pickup_geo']['city_id'] === 156 && (int) $p1['pickup_geo']['district_id'] === 1969, 'Sample form resolves Gambir, Jakarta Pusat cleanly', json_encode($p1['pickup_geo']));
    check_grp((int) $p1['delivery_geo']['city_id'] === 181 && (int) $p1['delivery_geo']['district_id'] === 2590, 'Sample form resolves Panyileukan, Kota Bandung cleanly', json_encode($p1['delivery_geo']));
    check_grp($p1['note'] === '', 'Sample form strips boilerplate 𝙽𝙾𝚃𝙴 cleanly without polluting note', json_encode($p1['note']));

    // -----------------------------------------------------------------
    // 2. Parser Form Acak / Bahasa Santai
    // -----------------------------------------------------------------
    $messyForm = "nama: Sari Dewi\nwa 087712345678\nbesok jam 7 malam 2 kursi\ndari Gambir, Jakarta Pusat\nke Panyileukan, Kota Bandung\ntarif 500 ribu\ncatatan: Bawa 2 koper besar";
    $p2        = bot_staff_parse_form_text($db, 1, $messyForm, 'travel', false);
    $tomorrow  = date('Y-m-d', strtotime('+1 day'));
    check_grp($p2['passenger_name'] === 'Sari Dewi' && $p2['passenger_phone'] === '6287712345678', 'Messy form extracts Sari Dewi & phone');
    check_grp($p2['date'] === $tomorrow && $p2['time'] === '19:00', "Messy form resolves besok ({$tomorrow}) & jam 7 malam (19:00)", json_encode([$p2['date'], $p2['time']]));
    check_grp((int) $p2['seats'] === 2 && abs((float) $p2['total_price'] - 500000.0) < 0.01, 'Messy form extracts 2 kursi & 500 ribu = 500000');
    check_grp($p2['pickup_address'] === 'Gambir, Jakarta Pusat' && $p2['delivery_address'] === 'Panyileukan, Kota Bandung', 'Messy form extracts pickup & delivery addresses without colons', json_encode([$p2['pickup_address'], $p2['delivery_address']]));

    // Salinan terisolasi untuk uji ingest DB live agar tidak bentrok dengan penumpang hasil seed Bryan M. W (6285889611974 / 2026-11-20)
    $ingestForm = str_replace(
        ['Bryan M. W', '085889611974', "Jum'at, 20 November 2026"],
        ['Tes Grup M. W', '085889699999', 'Senin, 28 Desember 2026'],
        $sampleForm
    );

    // -----------------------------------------------------------------
    // 3. RBAC & Hak Akses Grup Admin
    // -----------------------------------------------------------------
    $nonStaff = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => $ingestForm, 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $guestPhone,
    ]);
    check_grp($nonStaff['status'] === 403 && ($nonStaff['body']['error']['code'] ?? '') === 'STAFF_ONLY', 'Non-staff phone rejected with 403 STAFF_ONLY');

    $staffTryIngest = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => $ingestForm, 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $staffPhone,
    ]);
    check_grp($staffTryIngest['status'] === 403 && ($staffTryIngest['body']['error']['code'] ?? '') === 'FORBIDDEN_PERMISSION', 'Read-only Staff rejected from ingest with 403 FORBIDDEN_PERMISSION');

    $staffViewRekap = call_staff_bot('staff_rekap.php', 'GET', ['date' => '2026-12-28'], null, [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $staffPhone,
    ]);
    check_grp($staffViewRekap['status'] === 200 && !empty($staffViewRekap['body']['ok']), 'Read-only Staff allowed to view staff_rekap.php (200)');

    // -----------------------------------------------------------------
    // 4. Dry-Run Preview & Ingest Simpan Nyata + Anti-Duplikat + Audit Trail
    // -----------------------------------------------------------------
    $dryRes = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => $ingestForm, 'dry_run' => true, 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($dryRes['status'] === 200 && !empty($dryRes['body']['data']['ready']), 'dry_run=true returns ready=true without inserting row');

    $ingestRes = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => $ingestForm, 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $bkData = $ingestRes['body']['data'] ?? [];
    $bkId1  = (int) ($bkData['booking_id'] ?? 0);
    $doc1   = (string) ($bkData['document_number'] ?? '');
    if ($bkId1 > 0) {
        $createdBkIds[] = $bkId1;
        $createdPaxId   = (int) ($bkData['passenger']['id'] ?? ($bkData['booking']['passenger_id'] ?? 0));
    }
    check_grp($ingestRes['status'] === 201 && $bkId1 > 0 && $doc1 !== '', 'Admin ingest form creates booking (HTTP 201) + resi', $ingestRes['raw']);
    check_grp(($bkData['passenger']['phone_number'] ?? '') === '6285889699999', 'Passenger saved using form phone 6285889699999 (NOT admin WA)');
    check_grp(str_contains((string) ($bkData['wa_text'] ?? ''), $doc1) && str_contains((string) ($bkData['wa_text'] ?? ''), 'Tes Grup M. W'), 'wa_text confirmation summary includes resi & Tes Grup M. W');

    $audCnt = (int) $db->query("SELECT COUNT(*) FROM audit_trails WHERE company_id = 1 AND username = 'wa:admin' AND action = 'bot.staff_booking_create' AND description LIKE '%" . $db->real_escape_string($doc1) . "%'")->fetch_row()[0];
    check_grp($audCnt >= 1, 'audit_trails records wa:admin create event');

    // Uji Duplikat -> 409 DUPLICATE_BOOKING
    $dupRes = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => $ingestForm, 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp(
        $dupRes['status'] === 409
        && ($dupRes['body']['error']['code'] ?? '') === 'DUPLICATE_BOOKING'
        && ($dupRes['body']['error']['details']['existing_booking']['document_number'] ?? '') === $doc1,
        'Duplicate form on same passenger/date/time rejected with 409 DUPLICATE_BOOKING'
    );

    // Uji Data Kurang -> 422 INCOMPLETE_BOOKING_DATA
    $incRes = call_staff_bot('staff_booking_ingest.php', 'POST', [], ['raw_text' => 'Nama : Test Tanpa Rute', 'use_ai' => false], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($incRes['status'] === 422 && ($incRes['body']['error']['code'] ?? '') === 'INCOMPLETE_BOOKING_DATA', 'Incomplete form rejected with 422 INCOMPLETE_BOOKING_DATA');

    // -----------------------------------------------------------------
    // 5. Rekap Manifest Full vs Format Driver
    // -----------------------------------------------------------------
    $rekapFull = call_staff_bot('staff_rekap.php', 'GET', ['date' => '2026-12-28', 'q' => 'Tes Grup'], null, [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $rfData = $rekapFull['body']['data'] ?? [];
    check_grp(
        $rekapFull['status'] === 200
        && (int) ($rfData['summary']['total_bookings'] ?? 0) >= 1
        && str_contains((string) ($rfData['wa_text'] ?? ''), 'Tes Grup M. W')
        && str_contains((string) ($rfData['wa_text'] ?? ''), 'Rp 250.000'),
        'staff_rekap.php?format=full returns Tes Grup M. W manifest with Rp 250.000'
    );

    $rekapDriver = call_staff_bot('staff_rekap.php', 'GET', ['date' => '2026-12-28', 'q' => 'Tes Grup', 'format' => 'driver'], null, [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $rdData = $rekapDriver['body']['data'] ?? [];
    check_grp(
        $rekapDriver['status'] === 200
        && str_contains((string) ($rdData['wa_text'] ?? ''), 'MANIFEST KEBERANGKATAN')
        && str_contains((string) ($rdData['wa_text'] ?? ''), 'Tes Grup M. W')
        && !str_contains((string) ($rdData['wa_text'] ?? ''), 'Rp 250.000')
        && !isset($rdData['summary']['total_price']),
        'staff_rekap.php?format=driver hides financial figures'
    );

    // -----------------------------------------------------------------
    // 6. Edit Pesanan + Sinkron Invoice + Catat DP Transfer + Proteksi Cross-Tenant
    // -----------------------------------------------------------------
    $crossRes = call_staff_bot('staff_booking_update.php', 'POST', [], ['document_number' => $doc1, 'time' => '21:00'], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretLiz,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($crossRes['status'] === 404 && ($crossRes['body']['error']['code'] ?? '') === 'BOOKING_NOT_FOUND', 'LIZ bot cannot edit MTM booking (404 isolated)');

    $updRes = call_staff_bot('staff_booking_update.php', 'POST', [], [
        'document_number' => $doc1,
        'time'            => '20.00',
        'seats'           => 2,
        'total_price'     => '500rb',
        'append_note'     => 'Bawa 2 koper besar',
        'payment'         => ['amount' => 200000, 'method' => 'TRANSFER'],
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $ubData = $updRes['body']['data']['booking'] ?? [];
    check_grp(
        $updRes['status'] === 200
        && substr((string) ($ubData['time'] ?? ''), 0, 5) === '20:00'
        && (int) ($ubData['seats'] ?? 0) === 2
        && abs((float) ($ubData['total_price'] ?? 0) - 500000.0) < 0.01
        && ($ubData['invoice']['pay_status'] ?? '') === 'partial'
        && abs((float) ($ubData['invoice']['paid_total'] ?? 0) - 200000.0) < 0.01,
        'staff_booking_update.php updates time, seats, invoice total=500000 & records partial payment 200000',
        $updRes['raw']
    );

    $lowPrice = call_staff_bot('staff_booking_update.php', 'POST', [], [
        'document_number' => $doc1,
        'total_price'     => 100000,
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($lowPrice['status'] === 422 && ($lowPrice['body']['error']['code'] ?? '') === 'TOTAL_BELOW_PAID', 'Reducing total_price below paid amount rejected (422)');

    // -----------------------------------------------------------------
    // 7. Cancel & Safe Delete Guard
    // -----------------------------------------------------------------
    $delPaidTry = call_staff_bot('staff_booking_cancel.php', 'POST', [], [
        'document_number' => $doc1,
        'mode'            => 'delete',
        'confirm'         => true,
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($delPaidTry['status'] === 409 && ($delPaidTry['body']['error']['code'] ?? '') === 'CANNOT_DELETE_BOOKING', 'Permanent delete blocked on booking with recorded payment (409)');

    $cancelRes = call_staff_bot('staff_booking_cancel.php', 'POST', [], [
        'document_number' => $doc1,
        'mode'            => 'cancel',
        'reason'          => 'Tes cancel grup admin',
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $cbData = $cancelRes['body']['data']['booking'] ?? [];
    check_grp(
        $cancelRes['status'] === 200
        && ($cbData['booking_status'] ?? '') === 'cancelled'
        && ($cbData['invoice']['status'] ?? '') === 'cancelled',
        'staff_booking_cancel.php mode=cancel sets booking & invoice status to cancelled'
    );

    // Buat booking draft tanpa pembayaran untuk menguji confirm + delete permanen
    $ing2 = call_staff_bot('staff_booking_ingest.php', 'POST', [], [
        'raw_text'        => $messyForm,
        'allow_duplicate' => true,
        'use_ai'          => false,
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $bkId2 = (int) ($ing2['body']['data']['booking_id'] ?? 0);
    $doc2  = (string) ($ing2['body']['data']['document_number'] ?? '');
    if ($bkId2 > 0) {
        $createdBkIds[] = $bkId2;
    }
    check_grp($ing2['status'] === 201 && $doc2 !== '', 'Messy form ingests cleanly as draft booking (201)', $ing2['raw']);

    $delNoConfirm = call_staff_bot('staff_booking_cancel.php', 'POST', [], [
        'document_number' => $doc2,
        'mode'            => 'delete',
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    check_grp($delNoConfirm['status'] === 400 && ($delNoConfirm['body']['error']['code'] ?? '') === 'CONFIRMATION_REQUIRED', 'Permanent delete without confirm=true returns 400 CONFIRMATION_REQUIRED');

    $delConfirm = call_staff_bot('staff_booking_cancel.php', 'POST', [], [
        'document_number' => $doc2,
        'mode'            => 'delete',
        'confirm'         => true,
        'reason'          => 'Salah input tes',
    ], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $secretMtm,
        'HTTP_X_BOT_PHONE'   => $adminPhone,
    ]);
    $stillExists = (int) $db->query("SELECT COUNT(*) FROM travel_ticket_bookings WHERE id = {$bkId2}")->fetch_row()[0];
    check_grp($delConfirm['status'] === 200 && ($delConfirm['body']['data']['action_taken'] ?? '') === 'deleted' && $stillExists === 0, 'Permanent delete with confirm=true removes unpaid draft booking from DB');
} finally {
    foreach ($createdBkIds as $bidClean) {
        if ($bidClean <= 0) {
            continue;
        }
        $db->query("DELETE FROM cash_transactions WHERE company_id = 1 AND source_type = 'booking_payment' AND source_id IN (SELECT id FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = {$bidClean})");
        $db->query("DELETE FROM payment_gateway_orders WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM invoices WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM booking_status_history WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM trip_assignments WHERE booking_type = 'travel' AND booking_id = {$bidClean}");
        $db->query("DELETE FROM travel_ticket_bookings WHERE id = {$bidClean}");
    }
    $db->query("DELETE FROM passengers WHERE company_id = 1 AND phone_number IN ('6285889699999', '6287712345678')");
    $db->query("DELETE FROM audit_trails WHERE company_id = 1 AND username = 'wa:admin' AND action LIKE 'bot.staff_booking_%'");
    $inPh = "'" . implode("','", array_map([$db, 'real_escape_string'], $testPhones)) . "'";
    $db->query("DELETE FROM bot_request_logs WHERE phone IN ({$inPh})");
}

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
