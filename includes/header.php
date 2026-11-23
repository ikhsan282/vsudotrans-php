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
$activeCode = '';
$activeLogo = '';
foreach ($companies as $comp) {
    if ((int) $comp['id'] === $activeCid) {
        $activeName = $comp['name'];
        $activeCode = (string) ($comp['code'] ?? '');
        $activeLogo = (string) ($comp['logo'] ?? '');
        break;
    }
}
unset($comp);

$userNameRaw = trim((string) ($user['name'] ?? ($user['full_name'] ?? 'User')));
$userParts   = preg_split('/\s+/', $userNameRaw) ?: ['U'];
$userInitials = strtoupper(substr($userParts[0], 0, 1) . (isset($userParts[1]) ? substr($userParts[1], 0, 1) : ''));
if ($userInitials === '') {
    $userInitials = 'U';
}
$roleLabel = trim((string) ($_SESSION['role_name'] ?? 'Pengguna'));
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ? $title . ' — ' : '') ?><?= e(APP_NAME) ?></title>
    <script>
      // Anti-FOUC: terapkan tema & status collapse sidebar sebelum render
      (function(){try{var t=localStorage.getItem('vst_theme');if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);if(localStorage.getItem('vst_sidebar_collapsed')==='1')document.documentElement.classList.add('sidebar-collapsed');}catch(e){}})();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="wrapper d-flex">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="content-wrapper">
    <div class="topbar d-flex align-items-center px-3 gap-2">
        <button class="topbar-icon-btn flex-shrink-0" id="sidebarToggler" type="button" aria-label="Toggle menu sidebar" title="Tampilkan/Sembunyikan Sidebar" aria-controls="sidebar" aria-expanded="false">
            <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
        </button>
        <span class="topbar-divider d-none d-sm-inline-block" aria-hidden="true"></span>
        <div class="topbar-title-wrap min-w-0">
            <h6 class="mb-0 fw-semibold"><?= e($title) ?></h6>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
            <button type="button" id="globalSearchTrigger" class="topbar-search-btn"
                    title="Pencarian Cepat Global (Ctrl+K)" aria-label="Pencarian Cepat Global">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="d-none d-md-inline topbar-search-placeholder">Cari cepat...</span>
                <kbd class="d-none d-lg-inline topbar-search-kbd">Ctrl+K</kbd>
            </button>
            <div class="dropdown">
                <button class="topbar-pill dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Perusahaan Aktif: <?= e($activeName) ?>">
                    <span class="topbar-company-dot" aria-hidden="true"></span>
                    <span class="topbar-company-code"><?= e($activeCode !== '' ? $activeCode : 'PT') ?></span>
                    <span class="topbar-company-name d-none d-lg-inline text-truncate"><?= e($activeName) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end topbar-dropdown">
                    <li class="topbar-dropdown-header">Perusahaan Aktif</li>
                    <?php if (!$companies): ?>
                        <li><span class="dropdown-item-text text-muted small">Tidak ada perusahaan</span></li>
                    <?php endif; ?>
                    <?php foreach ($companies as $comp):
                        $isCompActive = (int) $comp['id'] === $activeCid;
                    ?>
                        <li>
                            <form method="post" action="<?= e(APP_URL) ?>/pages/companies/switch.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="company_id" value="<?= (int) $comp['id'] ?>">
                                <button type="submit" class="dropdown-item topbar-company-item<?= $isCompActive ? ' active' : '' ?>">
                                    <span class="topbar-item-code"><?= e($comp['code']) ?></span>
                                    <span class="flex-grow-1 text-truncate"><?= e($comp['name']) ?></span>
                                    <?php if ($isCompActive): ?>
                                        <i class="bi bi-check2-circle ms-2" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="dropdown">
                <button class="topbar-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e($userNameRaw) ?> (<?= e($roleLabel) ?>)">
                    <span class="topbar-avatar" aria-hidden="true"><?= e($userInitials) ?></span>
                    <span class="topbar-user-meta d-none d-xl-flex flex-column text-start">
                        <span class="topbar-user-name text-truncate"><?= e($userNameRaw) ?></span>
                        <span class="topbar-user-role text-truncate"><?= e($roleLabel) ?></span>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end topbar-dropdown">
                    <li class="px-3 py-2 border-bottom">
                        <div class="fw-bold small text-truncate"><?= e($userNameRaw) ?></div>
                        <div class="text-muted" style="font-size:.73rem"><?= e($roleLabel) ?></div>
                    </li>
                    <li class="pt-1"><a class="dropdown-item d-flex align-items-center gap-2" href="<?= e(APP_URL) ?>/pages/profile/index.php">
                        <i class="bi bi-person-circle" aria-hidden="true"></i> Profil Saya</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item text-danger d-flex align-items-center gap-2" href="<?= e(APP_URL) ?>/pages/auth/logout.php">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Keluar</a></li>
                </ul>
            </div>
        </div>
    </div>
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    <div class="flash-wrap"><?= flash_html() ?></div>
    <div class="content-main-row">
