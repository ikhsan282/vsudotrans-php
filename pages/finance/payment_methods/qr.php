<?php
/**
 * Sajikan gambar QR metode pembayaran dari Azure Blob Storage (qr_blob_path).
 * Dilindungi permission finance.view; hanya baris milik perusahaan aktif.
 * Diproksi (bukan redirect) agar bisa dipakai juga oleh renderer PDF.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$st = db()->prepare('SELECT company_id, qr_blob_path FROM payment_methods WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row || empty($row['qr_blob_path'])) {
    http_response_code(404);
    exit;
}
// Baris global (company_id NULL) boleh dilihat semua perusahaan.
if ($row['company_id'] !== null) {
    assert_company_access((int) $row['company_id']);
}

$blob = azure_blob_get((string) $row['qr_blob_path']);
if ($blob === null || $blob['data'] === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . ($blob['mime'] ?: 'image/png'));
header('Content-Length: ' . strlen($blob['data']));
header('Cache-Control: private, max-age=300');
echo $blob['data'];
