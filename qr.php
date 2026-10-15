<?php
/**
 * QR tiket — dibuat lokal, tanpa layanan luar (offline, tetap jalan di cPanel).
 * GET /qr.php?d=<data>&s=<ukuran px>.  Dipakai tiket.php untuk gambar QR cek tiket.
 */
require_once __DIR__ . '/config/config.php';

$data = (string) ($_GET['d'] ?? '');
$size = max(80, min(600, (int) ($_GET['s'] ?? 180)));
if ($data === '' || strlen($data) > 500) {
    http_response_code(400);
    exit('data tidak valid');
}

$py = '/opt/data/cache/scratch/pdfvenv/bin/python';
$cmd = escapeshellcmd($py) . ' -c ' . escapeshellarg(
    'import sys,io;'
    . 'import segno;'
    . 'q=segno.make(sys.argv[1], error="m");'
    . 'b=io.BytesIO();'
    . 'q.save(b, kind="png", scale=1, border=2);'
    . 'sys.stdout.buffer.write(b.getvalue())'
) . ' ' . escapeshellarg($data);

$png = shell_exec($cmd);
if (!$png || !str_starts_with($png, "\x89PNG")) {
    http_response_code(500);
    exit('gagal membuat QR');
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
echo $png;
