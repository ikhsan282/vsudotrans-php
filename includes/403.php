<?php
// Halaman 403 (dipanggil oleh require_permission / assert_company_access).
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Ditolak — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="text-center">
        <h1 class="display-5 fw-bold">403</h1>
        <p class="text-muted">Anda tidak memiliki akses ke halaman ini.</p>
        <a href="<?= e(APP_URL) ?>/pages/dashboard/index.php" class="btn btn-primary">Kembali ke Dashboard</a>
    </div>
</body>
</html>
