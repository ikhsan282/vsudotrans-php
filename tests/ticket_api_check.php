<?php
/**
 * Self-test Tahap 3: Public WordPress Ticket Check API (`/api/ticket.php`) & Multi-Brand `tiket.php`
 * Menguji:
 * 1. Validasi format nomor tiket (`INVALID_TICKET_FORMAT`).
 * 2. Respons JSON lengkap `/api/ticket.php?no=...&company=MTM` (rute, jadwal, harga, masking nomor telepon penumpang, tautan cetak & QRIS).
 * 3. Isolasi Multi-Tenant: tiket milik `MTM` ditolak (`404 TICKET_NOT_FOUND`) bila dicek dengan `?company=LIZ`.
 * 4. Rendering stempel dinamis pada `tiket.php` (stempel MTM dari Azure Blob `mtm/company/stamp_mtm.png` tampil untuk MTM, dan tidak bocor ke perusahaan tanpa stempel seperti LIZ).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/invoice_service.php';

$pass = 0;
$fail = 0;
function check_tk(bool $cond, string $label, string $detail = ''): void
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

function call_php_script(string $relPath, array $query = []): string
{
    $php = PHP_BINARY;
    $target = realpath(__DIR__ . '/../' . $relPath);
    $runner = sys_get_temp_dir() . '/vsudo_tk_runner_' . getmypid() . '.php';
    $code = '<?php ' . "\n"
          . '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
          . '$_GET = ' . var_export($query, true) . ';' . "\n"
          . 'require ' . var_export($target, true) . ';';
    file_put_contents($runner, $code);
    $out = (string) shell_exec('"' . $php . '" "' . $runner . '" 2>&1');
    @unlink($runner);
    return $out;
}

function call_ticket_api(array $query): array
{
    $out = call_php_script('api/ticket.php', $query);
    $pos = strpos($out, '{"ok":');
    $json = $pos !== false ? substr($out, $pos) : $out;
    return json_decode($json, true) ?: ['ok' => false, 'raw' => $out];
}

echo "=== Test Tahap 3: WordPress Ticket Check API & Multi-Brand E-Tiket ===\n";

$db = db();
$paxCode1 = 'PX-TK' . random_int(1000, 9999);
$db->query("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (1, '{$paxCode1}', 'Andi Wijaya MTM', '081234567890')");
$paxId1 = (int) $db->insert_id;

$paxCode5 = 'PX-LZ' . random_int(1000, 9999);
$db->query("INSERT INTO passengers (company_id, code, name, phone_number) VALUES (5, '{$paxCode5}', 'Rina LIZ Wisata', '081355556666')");
$paxId5 = (int) $db->insert_id;

// Create booking for MTM (company 1)
$bkMtm = booking_create('travel', [
    'company_id'                 => 1,
    'branch_id'                  => 1,
    'date'                       => date('Y-m-d'),
    'time'                       => '10:00:00',
    'pickup_address'             => 'Jl. MTM Jemput No. 1',
    'pickup_point_city_id'       => 1,
    'pickup_point_district_id'   => 1,
    'delivery_address'           => 'Jl. MTM Antar No. 2',
    'delivery_point_city_id'     => 2,
    'delivery_point_district_id' => 2,
    'total_price'                => 180000,
    'category_vehicle_id'        => null,
    'vehicle_id'                 => null,
    'passenger_id'               => $paxId1,
    'note'                       => 'Test MTM',
    'created_user'               => 'test',
]);

// Create booking for LIZ (company 5)
$bkLiz = booking_create('travel', [
    'company_id'                 => 5,
    'branch_id'                  => 5,
    'date'                       => date('Y-m-d'),
    'time'                       => '14:00:00',
    'pickup_address'             => 'Jl. LIZ Jemput No. 1',
    'pickup_point_city_id'       => 1,
    'pickup_point_district_id'   => 1,
    'delivery_address'           => 'Jl. LIZ Antar No. 2',
    'delivery_point_city_id'     => 2,
    'delivery_point_district_id' => 2,
    'total_price'                => 220000,
    'category_vehicle_id'        => null,
    'vehicle_id'                 => null,
    'passenger_id'               => $paxId5,
    'note'                       => 'Test LIZ',
    'created_user'               => 'test',
]);

$docMtm = $bkMtm['document_number'];
$docLiz = $bkLiz['document_number'];

// 1. Invalid format check
$badRes = call_ticket_api(['no' => 'INVALID-123']);
check_tk(($badRes['ok'] ?? true) === false && ($badRes['error']['code'] ?? '') === 'INVALID_TICKET_FORMAT', 'Rejects invalid ticket number format');

// 2. Valid MTM ticket check with company=MTM
$okMtm = call_ticket_api(['no' => $docMtm, 'company' => 'MTM']);
check_tk(($okMtm['ok'] ?? false) === true && ($okMtm['data']['document_number'] ?? '') === $docMtm, 'GET /api/ticket.php returns valid MTM ticket when company=MTM');
check_tk(($okMtm['data']['company']['code'] ?? '') === 'MTM' && ($okMtm['data']['pricing']['total']['raw'] ?? 0) === 180000, 'Ticket JSON includes accurate company & pricing breakdown');
check_tk(str_contains((string) ($okMtm['data']['passengers'][0]['phone_masked'] ?? ''), '*'), 'Passenger phone number is masked in public JSON response');
check_tk(!empty($okMtm['data']['links']['print_pdf_url']), 'Ticket JSON includes print_pdf_url');

// 3. Multi-tenant isolation: MTM ticket checked from LIZ landing page (?company=LIZ) must return 404
$crossRes = call_ticket_api(['no' => $docMtm, 'company' => 'LIZ']);
check_tk(($crossRes['ok'] ?? true) === false && ($crossRes['error']['code'] ?? '') === 'TICKET_NOT_FOUND', 'Multi-tenant isolation: MTM ticket is hidden when queried with company=LIZ');

// 4. Dynamic stamp rendering in tiket.php (stempel dari Azure Blob, URL ber-SAS)
$htmlMtm = call_php_script('tiket.php', ['no' => $docMtm, 'company' => 'MTM']);
check_tk(str_contains($htmlMtm, 'mtm/company/stamp_mtm.png') && str_contains($htmlMtm, 'sig='), 'tiket.php renders Azure Blob stamp (mtm/company/stamp_mtm.png + SAS) for MTM');

$htmlLiz = call_php_script('tiket.php', ['no' => $docLiz, 'company' => 'LIZ']);
check_tk(str_contains($htmlLiz, 'LIZ Wisata Alam Indah') && !str_contains($htmlLiz, 'stamp_mtm.png'), 'tiket.php renders LIZ Wisata Alam Indah without leaking MTM stamp');

$htmlCross = call_php_script('tiket.php', ['no' => $docMtm, 'company' => 'LIZ']);
check_tk(str_contains($htmlCross, 'Tiket tidak ditemukan'), 'tiket.php rejects cross-company ticket when ?company=LIZ is passed');

// Cleanup
foreach ([$bkMtm, $bkLiz] as $b) {
    $bid = (int) $b['id'];
    $iid = (int) $b['invoice_id'];
    $db->query("DELETE FROM booking_cost_lines WHERE booking_type = 'travel' AND booking_id = {$bid}");
    $db->query("DELETE FROM booking_status_history WHERE booking_type = 'travel' AND booking_id = {$bid}");
    $db->query("DELETE FROM booking_passengers WHERE booking_type = 'travel' AND booking_id = {$bid}");
    $db->query("DELETE FROM invoices WHERE id = {$iid}");
    $db->query("DELETE FROM travel_ticket_bookings WHERE id = {$bid}");
}
$db->query("DELETE FROM passengers WHERE id IN ({$paxId1}, {$paxId5})");

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

