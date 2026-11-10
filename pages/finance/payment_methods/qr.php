<?php
/**
 * Sajikan gambar QR metode pembayaran (Azure Blob Storage atau LONGBLOB fallback).
 * Dilindungi permission finance.view; hanya baris milik perusahaan aktif.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$st = db()->prepare('SELECT company_id, qr_blob_path, qr_image, qr_mime FROM payment_methods WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    http_response_code(404);
    exit;
}
// Baris global (company_id NULL) boleh dilihat semua perusahaan.
if ($row['company_id'] !== null) {
    assert_company_access((int) $row['company_id']);
}

// 1. Utamakan ambil dari Azure Blob Storage bila qr_blob_path terisi
if (!empty($row['qr_blob_path'])) {
    $blob = azure_blob_get((string) $row['qr_blob_path']);
    if ($blob !== null && $blob['data'] !== '') {
        header('Content-Type: ' . ($row['qr_mime'] ?: $blob['mime'] ?: 'image/png'));
        header('Content-Length: ' . strlen($blob['data']));
        header('Cache-Control: private, max-age=300');
        echo $blob['data'];
        exit;
    }
}

// 2. Fallback ke kolom biner qr_image
if ($row['qr_image'] === null || $row['qr_image'] === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . ($row['qr_mime'] ?: 'image/png'));
header('Content-Length: ' . strlen($row['qr_image']));
header('Cache-Control: private, max-age=300');
echo $row['qr_image'];
