<?php
/**
 * Endpoint bantu isi form: teks bebas → kandidat field booking (JSON).
 * Tidak pernah menulis ke DB. Model hanya memilih dari daftar yang dikirim;
 * setiap balasan divalidasi ulang di PHP (ai_sanitize_candidate).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage'); // fitur AI mengikuti hak kelola modul
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/ai.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode harus POST.']);
    exit;
}
verify_csrf();

$raw = trim((string) ($_POST['text'] ?? ''));
if ($raw === '' || mb_strlen($raw) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Teks 1–1000 karakter.']);
    exit;
}
if (!defined('AI_API_KEY') || AI_API_KEY === '' || !defined('AI_BASE_URL') || AI_BASE_URL === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Fitur AI belum dikonfigurasi.']);
    exit;
}

$cid = require_active_company();

// ---- Daftar pilihan ASLI dari DB (sumber kebenaran) --------------------
$branches   = opts_branches($cid);
$passengers = opts_passengers($cid);
$cats       = opts_categories($cid);
$vehicles   = opts_vehicles($cid);
$districts  = db()->query('SELECT id, name FROM districts ORDER BY name')->fetch_all(MYSQLI_ASSOC);

function map_id_name(array $rows): array
{
    $m = [];
    foreach ($rows as $r) {
        $m[(int) $r['id']] = (string) $r['name'];
    }
    return $m;
}

$peta = [
    'branch_id'    => map_id_name($branches),
    'passenger_id' => map_id_name($passengers),
    'category_vehicle_id' => map_id_name($cats),
    'vehicle_id'   => map_id_name($vehicles),
    'pickup_point_district_id'  => map_id_name($districts),
    'delivery_point_district_id' => map_id_name($districts),
];
$fields = ['date' => 10, 'time' => 5, 'pickup_address' => 500, 'delivery_address' => 500, 'total_price' => 15, 'note' => 500];

// ---- Prompt: konteks hari ini + daftar pilihan, minta JSON saja --------
$hari_ini = date('Y-m-d');
$pilih_txt = '';
foreach ($peta as $f => $opts) {
    $pilih_txt .= $f . ": " . json_encode($opts, JSON_UNESCAPED_UNICODE) . "\n";
}
$system = "Kamu parser input booking travel perusahaan transportasi. "
    . "Hari ini {$hari_ini}. \"besok\" = " . date('Y-m-d', strtotime('+1 day'))
    . ", \"lusa\" = " . date('Y-m-d', strtotime('+2 days')) . ". Tanggal hasil harus antara {$hari_ini} dan "
    . date('Y-m-d', strtotime('+60 days')) . ". "
    . "Balas HANYA JSON valid tanpa penjelasan, skema: "
    . '{"branch_id":int,"passenger_id":int,"category_vehicle_id":int,"vehicle_id":int,'
    . '"pickup_point_district_id":int,"delivery_point_district_id":int,'
    . '"date":"YYYY-MM-DD","time":"HH:MM","pickup_address":string,"delivery_address":string,'
    . '"total_price":number,"note":string}. '
    . "Semua field id WAJIB dipilih dari daftar yang diberikan (jangan mengarang id). "
    . "Field yang tidak disebut user biarkan kosong/0. Nama pelanggan dicocokkan ke daftar passenger_id.";
$user = "Daftar pilihan:\n{$pilih_txt}\nInput user:\n{$raw}";

$res = ai_chat_json([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $user],
]);

if (!$res['ok']) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

// ---- Validasi ulang: hanya id yang ADA di daftar + teks terbatas -------
$candidate = ai_sanitize_candidate($res['data'], $peta, $fields);

// Tanggal/jam dinormalisasi & dicek rentangnya (bukan kepercayaan model).
$tanggal_ok = function (string $s): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $t = strtotime($s);
    if ($t === false) return null;
    $min = strtotime(date('Y-m-d'));
    $max = strtotime('+60 days');
    if ($t < $min || $t > $max) return null;
    return date('Y-m-d', $t);
};
if (isset($candidate['date'])) {
    $candidate['date'] = $tanggal_ok((string) $candidate['date']) ?? '';
}
if (isset($candidate['time'])) {
    $candidate['time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $candidate['time']) ? $candidate['time'] : '';
}
if (isset($candidate['total_price'])) {
    $candidate['total_price'] = is_numeric((string) $candidate['total_price']) && (float) $candidate['total_price'] >= 0
        ? (float) $candidate['total_price'] : '';
}

echo json_encode([
    'ok' => true,
    'candidate' => $candidate,     // hanya field yang lolos validasi
    'peta' => $peta,               // id -> label, untuk preview di UI
], JSON_UNESCAPED_UNICODE);