<?php
/**
 * Kerangka HTML sementara untuk modul employee (layout tim lain di feature/layout).
 */

function employee_html_start(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="<?= e(APP_URL) ?>/pages/dashboard/index.php" class="small text-muted text-decoration-none">Dashboard</a>
            <span class="text-muted small">/</span>
            <a href="<?= e(APP_URL) ?>/pages/employees/index.php" class="small text-muted text-decoration-none">Karyawan</a>
        </div>
        <a href="<?= e(APP_URL) ?>/pages/auth/logout.php" class="btn btn-sm btn-outline-secondary">Keluar</a>
    </div>
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?= flash_html() ?>
    <?php
}

function employee_html_end(): void
{
    ?>
</div>
</body>
</html>
    <?php
}
