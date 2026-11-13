<?php
/**
 * AJAX Endpoint: Tambah penumpang cepat dari modal form booking (Travel & Rental).
 * POST: name, phone_number, email (opsional), csrf_token
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode request tidak diizinkan.']);
    exit;
}

if (!can('travel.manage') && !can('rental.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Anda tidak memiliki hak akses menambah penumpang.']);
    exit;
}

$activeCid = (int) (active_company_id() ?? 0);
$reqCid    = (int) ($_POST['company_id'] ?? $activeCid);
$allowed   = accessible_company_ids($activeCid);

if ($reqCid > 0 && (is_super_admin() || in_array($reqCid, $allowed, true))) {
    $cid = $reqCid;
} elseif ($activeCid > 0) {
    $cid = $activeCid;
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Perusahaan aktif belum dipilih.']);
    exit;
}

// CSRF check
$token = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan refresh halaman.']);
    exit;
}

$name  = trim((string) ($_POST['name'] ?? ''));
$phone = trim((string) ($_POST['phone_number'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Nama penumpang wajib diisi.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Format email tidak valid.']);
    exit;
}

$db = db();
$user = (string) ($_SESSION['username'] ?? 'system');

// Generate unique code P-XXXXXX
$code = '';
for ($i = 0; $i < 10; $i++) {
    $c = 'P-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $st = $db->prepare('SELECT 1 FROM passengers WHERE company_id = ? AND code = ? LIMIT 1');
    $st->bind_param('is', $cid, $c);
    $st->execute();
    $dup = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$dup) {
        $code = $c;
        break;
    }
}
if ($code === '') {
    $code = 'P-' . time();
}

$country_code = '+62';
$val_phone = $phone !== '' ? $phone : null;
$val_email = $email !== '' ? $email : null;
$note = 'Ditambahkan via Quick-Add Modal Booking';

$st = $db->prepare('INSERT INTO passengers (company_id, code, name, country_code, phone_number, email, note, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$st->bind_param('isssssss', $cid, $code, $name, $country_code, $val_phone, $val_email, $note, $user);
if (!$st->execute()) {
    $err = $st->error;
    $st->close();
    echo json_encode(['ok' => false, 'error' => 'Gagal menyimpan penumpang: ' . $err]);
    exit;
}
$new_id = (int) $db->insert_id;
$st->close();

audit('passenger.quick_create', "Tambah cepat penumpang #{$new_id} {$code} — {$name}");

echo json_encode([
    'ok' => true,
    'data' => [
        'id'    => $new_id,
        'code'  => $code,
        'name'  => $name,
        'label' => $name . ($phone ? ' (' . $phone . ')' : '') . ' [' . $code . ']',
    ]
]);

