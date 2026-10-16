<?php
/**
 * Sajikan gambar QR metode pembayaran (LONGBLOB) sebagai respons gambar.
 * Dilindungi permission finance.view; hanya baris milik perusahaan aktif.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$st = db()->prepare('SELECT company_id, qr_image, qr_mime FROM payment_methods WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row || $row['qr_image'] === null || $row['qr_image'] === '') {
    http_response_code(404);
    exit;
}
// Baris global (company_id NULL) boleh dilihat semua perusahaan.
if ($row['company_id'] !== null) {
    assert_company_access((int) $row['company_id']);
}

header('Content-Type: ' . ($row['qr_mime'] ?: 'image/png'));
header('Content-Length: ' . strlen($row['qr_image']));
header('Cache-Control: private, max-age=300');
echo $row['qr_image'];
