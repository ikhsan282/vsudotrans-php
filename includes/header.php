<?php
/**
 * Layout: head + pembuka wrapper + topbar.
 * Halaman menyetel $active (menu aktif) dan $title sebelum include ini.
 * Struktur mengikuti Arya Green IPL: sidebar tetap (fixed) + topbar sticky.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_login();

$active = $active ?? '';
$title  = $title ?? '';
$user   = current_user();

$companies  = accessible_companies();
$activeCid  = active_company_id();
$activeName = 'Semua Perusahaan';
foreach ($companies as $comp) {
    if ((int) $comp['id'] === $activeCid) {
        $activeName = $comp['name'];
        break;
    }
}
unset($comp);
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ? $title . ' — ' : '') ?><?= e(APP_NAME) ?></title>
    <script>
      // Anti-FOUC: terapkan tema sebelum render
      (function(){try{var t=localStorage.getItem('vst_theme');if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="wrapper d-flex">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="content-wrapper">
    <div class="topbar d-flex align-items-center px-3 gap-2">
        <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler" type="button" aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false">
            <i class="bi bi-list fs-5" aria-hidden="true"></i>
        </button>
        <h6 class="mb-0 fw-semibold"><i class="bi bi-<?= e($nav_icon ?? 'grid') ?> me-1 text-primary"></i> <?= e($title) ?></h6>
        <div class="ms-auto d-flex align-items-center gap-2">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-building"></i> <span class="d-none d-md-inline"><?= e($activeName) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if (!$companies): ?>
                        <li><span class="dropdown-item-text text-muted small">Tidak ada perusahaan</span></li>
                    <?php endif; ?>
                    <?php foreach ($companies as $comp): ?>
                        <li>
                            <form method="post" action="<?= e(APP_URL) ?>/pages/companies/switch.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="company_id" value="<?= (int) $comp['id'] ?>">
                                <button type="submit" class="dropdown-item<?= (int) $comp['id'] === $activeCid ? ' active' : '' ?>">
                                    <?= e($comp['name']) ?> <span class="text-muted small">(<?= e($comp['code']) ?>)</span>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i> <span class="d-none d-md-inline"><?= e($user['name'] ?? '') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted"><?= e($_SESSION['role_name'] ?? '') ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= e(APP_URL) ?>/pages/profile/index.php">
                        <i class="bi bi-person-circle"></i> Profil Saya</a></li>
                    <li><a class="dropdown-item text-danger" href="<?= e(APP_URL) ?>/pages/auth/logout.php">
                        <i class="bi bi-box-arrow-right"></i> Keluar</a></li>
                </ul>
            </div>
        </div>
    </div>
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    <div class="flash-wrap"><?= flash_html() ?></div>
    <div class="content-main-row">
