<?php
/**
 * Layout: bagian atas HTML + navbar + company switcher.
 * Halaman menyetel $active (menu aktif) dan $title sebelum include ini.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_login();

$active = $active ?? '';
$title  = $title ?? '';
$user   = current_user();

$companies  = accessible_companies();
$activeCid  = active_company_id();
$activeName = 'Semua Perusahaan';
foreach ($companies as $c) {
    if ((int) $c['id'] === $activeCid) {
        $activeName = $c['name'];
        break;
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ? $title . ' — ' : '') ?><?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>.sidebar { min-height: calc(100vh - 56px); }</style>
</head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= e(APP_URL) ?>/pages/dashboard/index.php">
            <i class="bi bi-truck"></i> <?= e(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="topbar">
            <ul class="navbar-nav me-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-building"></i> <?= e($activeName) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <?php if (!$companies): ?>
                            <li><span class="dropdown-item-text text-muted small">Tidak ada perusahaan</span></li>
                        <?php endif; ?>
                        <?php foreach ($companies as $c): ?>
                            <li>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/companies/switch.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="dropdown-item<?= (int) $c['id'] === $activeCid ? ' active' : '' ?>">
                                        <?= e($c['name']) ?> <span class="text-muted small">(<?= e($c['code']) ?>)</span>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= e($user['name'] ?? '') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted"><?= e($_SESSION['role_name'] ?? '') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= e(APP_URL) ?>/pages/auth/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Keluar</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container-fluid px-0">
    <?= flash_html() ?>
    <div class="row g-0">
