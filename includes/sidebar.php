<?php
/**
 * Layout: sidebar menu ala Arya Green IPL (fixed, scroll terpisah),
 * dikelompokkan per kategori ERP (Opsi A) dan difilter dengan can().
 * Bergantung pada $active yang diset halaman sebelum include header.
 */
$active = $active ?? '';

/** Menu/entri 'admin_only' hanya untuk Super Admin (digerakkan is_super_admin(), bukan perm). */
if (!function_exists('super_admin_menu')) {
    function super_admin_menu(array $item): bool
    {
        return empty($item['admin_only']) || is_super_admin();
    }
}

/** Cek izin menu: null = bebas, string = can($perm), array = salah satu (OR). */
if (!function_exists('sidebar_perm_ok')) {
    function sidebar_perm_ok($perm): bool
    {
        if ($perm === null) {
            return true;
        }
        if (is_array($perm)) {
            foreach ($perm as $p) {
                if (can((string) $p)) {
                    return true;
                }
            }
            return false;
        }
        return can((string) $perm);
    }
}

/** Cek apakah menu utama atau salah satu key grupnya sedang aktif. */
if (!function_exists('sidebar_is_active')) {
    function sidebar_is_active(array $item, string $active): bool
    {
        if (($item['key'] ?? '') === $active) {
            return true;
        }
        if (!empty($item['keys']) && in_array($active, $item['keys'], true)) {
            return true;
        }
        return false;
    }
}

$menu = [
    // ── UTAMA ───────────────────────────────────────────────────────────────
    ['key' => 'dashboard', 'section' => 'Utama', 'label' => 'Dashboard', 'icon' => 'speedometer2', 'href' => 'pages/dashboard/index.php', 'perm' => null],

    // ── OPERASIONAL & ARMADA ────────────────────────────────────────────────
    ['key' => 'travel', 'section' => 'Operasional & Armada', 'label' => 'Travel', 'icon' => 'bus-front', 'perm' => 'travel.view', 'sub' => [
        ['label' => 'Pemesanan Tiket Perjalanan', 'href' => 'pages/travel/bookings/index.php',  'perms' => 'travel.view'],
        ['label' => 'Jadwal',                     'href' => 'pages/travel/schedules/index.php', 'perms' => 'travel.view'],
        ['label' => 'Rute',                       'href' => 'pages/travel/rutes/index.php',     'perms' => 'travel.view'],
    ]],
    ['key' => 'rental', 'section' => 'Operasional & Armada', 'label' => 'Rental', 'icon' => 'car-front', 'perm' => 'rental.view', 'sub' => [
        ['label' => 'Pemesanan Sewa Kendaraan', 'href' => 'pages/rental/bookings/index.php',    'perms' => 'rental.view'],
    ]],
    ['key' => 'transport', 'keys' => ['transport', 'passengers', 'regulations'], 'section' => 'Operasional & Armada', 'label' => 'Transportasi', 'icon' => 'signpost-split', 'perm' => ['travel.view', 'rental.view'], 'sub' => [
        ['key' => 'passengers',  'label' => 'Penumpang',        'href' => 'pages/transport/passengers/index.php',  'perms' => ['travel.view', 'rental.view']],
        ['key' => 'regulations', 'label' => 'Peraturan Komisi', 'href' => 'pages/transport/regulations/index.php', 'perms' => ['travel.view', 'rental.view']],
    ]],
    ['key' => 'dispatch',   'section' => 'Operasional & Armada', 'label' => 'Penjadwalan',  'icon' => 'calendar2-check', 'perm' => null, 'sub' => [
        ['label' => 'Kalender Operasional', 'href' => 'pages/dispatch/calendar.php', 'perms' => 'dispatch.view'],
        ['label' => 'Papan & Riwayat',      'href' => 'pages/dispatch/index.php',    'perms' => 'dispatch.view'],
        ['label' => 'Tugaskan Driver',      'href' => 'pages/dispatch/assign.php',   'perms' => 'dispatch.manage'],
    ]],
    ['key' => 'vehicles',   'section' => 'Operasional & Armada', 'label' => 'Kendaraan',    'icon' => 'truck', 'perm' => 'vehicles.view', 'sub' => [
        ['label' => 'Daftar Kendaraan', 'href' => 'pages/vehicles/index.php',  'perms' => 'vehicles.view'],
        ['label' => 'Master Kategori',  'href' => 'pages/vehicles/master.php', 'perms' => 'vehicles.view'],
    ]],

    // ── KEUANGAN & AKUNTANSI ────────────────────────────────────────────────
    ['key' => 'finance', 'section' => 'Keuangan & Akuntansi', 'label' => 'Keuangan', 'icon' => 'cash-coin', 'perm' => null, 'sub' => [
        ['label' => 'Invoice',            'href' => 'pages/finance/invoices/index.php',         'perms' => 'invoices.view'],
        ['label' => 'Buku Kas & Bank',    'href' => 'pages/finance/cash/index.php',             'perms' => 'finance.view'],
        ['label' => 'Akun Kas/Bank',      'href' => 'pages/finance/cash_accounts/index.php',    'perms' => 'finance.view'],
        ['label' => 'Metode Pembayaran',  'href' => 'pages/finance/payment_methods/index.php',  'perms' => 'finance.view'],
        ['label' => 'Saluran QRIS/Gopay', 'href' => 'pages/finance/payment_channels/index.php', 'perms' => 'companies.manage'],
    ]],

    // ── SDM & PAYROLL ───────────────────────────────────────────────────────
    ['key' => 'employees',  'section' => 'SDM & Payroll', 'label' => 'Karyawan',  'icon' => 'person-vcard',   'href' => 'pages/employees/index.php',       'perm' => 'employees.view'],
    ['key' => 'attendance', 'section' => 'SDM & Payroll', 'label' => 'Absensi',   'icon' => 'calendar-check', 'perm' => 'attendance.view', 'sub' => [
        ['label' => 'Absensi Harian', 'href' => 'pages/attendance/index.php',   'perms' => 'attendance.view'],
        ['label' => 'Rekap Absensi',  'href' => 'pages/attendance/summary.php', 'perms' => 'attendance.view'],
    ]],
    ['key' => 'payroll',    'section' => 'SDM & Payroll', 'label' => 'Payroll',   'icon' => 'cash-stack',     'href' => 'pages/payroll/periods/index.php', 'perm' => 'payroll.view'],
    ['key' => 'hr',         'section' => 'SDM & Payroll', 'label' => 'Master HR', 'icon' => 'briefcase',      'perm' => 'hr.view', 'sub' => [
        ['label' => 'Grade Jabatan', 'href' => 'pages/hr/job_grades/index.php',              'perms' => 'hr.view'],
        ['label' => 'Jabatan',       'href' => 'pages/hr/job_titles/index.php',              'perms' => 'hr.view'],
        ['label' => 'Posisi',        'href' => 'pages/hr/job_positions/index.php',           'perms' => 'hr.view'],
        ['label' => 'Struktur Org.', 'href' => 'pages/hr/organization_structures/index.php', 'perms' => 'hr.view'],
        ['label' => 'Penghargaan',   'href' => 'pages/hr/employee_recognitions/index.php',   'perms' => 'hr.view'],
    ]],

    // ── LAPORAN ─────────────────────────────────────────────────────────────
    ['key' => 'reports', 'section' => 'Laporan', 'label' => 'Laporan', 'icon' => 'file-earmark-bar-graph', 'perm' => 'reports.view', 'sub' => [
        ['label' => 'Laporan Booking',     'href' => 'pages/reports/bookings.php',   'perms' => 'reports.view'],
        ['label' => 'Laporan Keuangan',    'href' => 'pages/reports/finance.php',    'perms' => 'finance.view'],
        ['label' => 'Laporan Operasional', 'href' => 'pages/reports/operations.php', 'perms' => 'reports.view'],
        ['label' => 'Laporan Driver',      'href' => 'pages/reports/dispatch.php',   'perms' => 'reports.view'],
    ]],

    // ── MASTER DATA & ORGANISASI ────────────────────────────────────────────
    ['key' => 'organization', 'keys' => ['groups', 'companies', 'branches'], 'section' => 'Master Data & Organisasi', 'label' => 'Organisasi', 'icon' => 'building', 'perm' => null, 'sub' => [
        ['key' => 'groups',    'label' => 'Grup Perusahaan', 'href' => 'pages/groups/index.php',    'perms' => 'groups.view'],
        ['key' => 'companies', 'label' => 'Perusahaan',      'href' => 'pages/companies/index.php', 'perms' => 'companies.view'],
        ['key' => 'branches',  'label' => 'Cabang',          'href' => 'pages/branches/index.php',  'perms' => 'branches.view'],
    ]],
    ['key' => 'masters', 'section' => 'Master Data & Organisasi', 'label' => 'Referensi Umum', 'icon' => 'list-ul', 'perm' => 'masters.view', 'sub' => [
        ['label' => 'Bank',            'href' => 'pages/masters/banks/index.php',         'perms' => 'masters.view'],
        ['label' => 'Wilayah: Negara', 'href' => 'pages/masters/countries/index.php',     'perms' => 'masters.view'],
        ['label' => 'Provinsi',        'href' => 'pages/masters/provinces/index.php',     'perms' => 'masters.view'],
        ['label' => 'Kota/Kab.',       'href' => 'pages/masters/cities/index.php',        'perms' => 'masters.view'],
        ['label' => 'Kecamatan',       'href' => 'pages/masters/districts/index.php',     'perms' => 'masters.view'],
        ['label' => 'Desa',            'href' => 'pages/masters/villages/index.php',      'perms' => 'masters.view'],
        ['label' => 'Satuan',          'href' => 'pages/masters/unit_measures/index.php', 'perms' => 'masters.view'],
    ]],

    // ── ADMINISTRASI SISTEM ─────────────────────────────────────────────────
    ['key' => 'access', 'keys' => ['users', 'roles', 'audit'], 'section' => 'Administrasi Sistem', 'label' => 'Pengguna & Akses', 'icon' => 'shield-lock', 'perm' => null, 'sub' => [
        ['key' => 'users', 'label' => 'Pengguna',         'href' => 'pages/users/index.php', 'perms' => 'users.view'],
        ['key' => 'roles', 'label' => 'Role & Hak Akses', 'href' => 'pages/roles/index.php', 'perms' => 'roles.manage'],
        ['key' => 'audit', 'label' => 'Audit Trail',      'href' => 'pages/audit/index.php', 'perms' => 'audit.view'],
    ]],
    // Grup "Sistem": khusus Super Admin (id="menu-system" dipertahankan untuk kompatibilitas).
    ['key' => 'system', 'section' => 'Administrasi Sistem', 'label' => 'Sistem', 'icon' => 'gear', 'perm' => null, 'admin_only' => true, 'sub' => [
        ['label' => 'Backup & Restore', 'href' => 'pages/settings/backup.php', 'perms' => null, 'admin_only' => true],
    ]],
];

// $nav_icon dipakai topbar (header.php) untuk ikon di samping judul.
$nav_icon = $active === 'profile' ? 'person-circle' : 'grid';
foreach ($menu as $m) {
    if (sidebar_is_active($m, $active)) {
        $nav_icon = $m['icon'];
        break;
    }
}
$req_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$default_brand_logo = APP_URL . '/assets/brand/logo-transmandiri.png';
$sidebar_logo_url = $default_brand_logo;
if (!empty($activeLogo) && !preg_match('#/logo_[a-z]+\.png$#i', (string) $activeLogo) && function_exists('resolve_asset_url')) {
    $resolved = resolve_asset_url((string) $activeLogo);
    if ($resolved !== '') {
        $sidebar_logo_url = $resolved;
    }
}
?>
<nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-0">
  <a href="<?= e(APP_URL) ?>/pages/dashboard/index.php"
     class="d-flex flex-column align-items-center justify-content-center px-3 py-2 text-white text-decoration-none sidebar-brand"
     title="<?= e($activeName ?? APP_NAME) ?>">
    <img src="<?= e($sidebar_logo_url) ?>"
         onerror="this.onerror=null;this.src='<?= e($default_brand_logo) ?>';"
         alt="<?= e($activeName ?? APP_NAME) ?>" class="sidebar-logo">
    <?php if (!empty($activeName) && $activeName !== 'Semua Perusahaan'): ?>
      <div class="sidebar-tenant-badge text-truncate mt-1" title="<?= e($activeName) ?>">
        <?php if (!empty($activeCode)): ?><span class="fw-bold"><?= e($activeCode) ?></span> &middot; <?php endif; ?><?= e($activeName) ?>
      </div>
    <?php endif; ?>
  </a>
  <hr class="text-white m-0">

  <div class="nav-scroll">
    <ul class="nav nav-pills flex-column px-2 py-2">
      <?php
      $last_section = null;
      foreach ($menu as $m):
          if (!super_admin_menu($m)) continue;
          if (!sidebar_perm_ok($m['perm'] ?? null)) continue;

          $visible_sub = [];
          if (!empty($m['sub'])) {
              $visible_sub = array_filter($m['sub'], fn($s) =>
                  super_admin_menu($s) && sidebar_perm_ok($s['perms'] ?? null));
              if (!$visible_sub) continue;
          }

          $sec = $m['section'] ?? '';
          if ($sec !== '' && $sec !== 'Utama' && $sec !== $last_section):
              $last_section = $sec;
      ?>
          <li class="sidebar-section"><?= e($sec) ?></li>
      <?php endif; ?>

      <?php if (!empty($m['sub'])):
          $is_open = sidebar_is_active($m, $active);
      ?>
          <li class="nav-item">
            <a class="nav-link text-white d-flex align-items-center<?= $is_open ? ' has-active' : '' ?>"
               data-bs-toggle="collapse" href="#menu-<?= e($m['key']) ?>" role="button"
               aria-expanded="<?= $is_open ? 'true' : 'false' ?>"
               aria-controls="menu-<?= e($m['key']) ?>">
              <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?>
              <i class="bi bi-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse<?= $is_open ? ' show' : '' ?>" id="menu-<?= e($m['key']) ?>">
              <ul class="nav flex-column ms-3 ps-2 sidebar-tree">
                <?php foreach ($visible_sub as $s):
                    $s_href_clean = explode('?', $s['href'])[0];
                    $s_dir = dirname($s_href_clean);
                    $is_index_entry = basename($s_href_clean) === 'index.php';
                    $req_base = basename($req_path);
                    $sub_active = (!empty($s['key']) && $s['key'] === $active)
                        || ($req_path !== '' && (
                            str_ends_with($req_path, '/' . $s_href_clean)
                            || ($is_index_entry && $s_dir !== '.' && str_contains($req_path, '/' . $s_dir . '/') && in_array($req_base, ['index.php', 'form.php', 'detail.php', 'view.php'], true))
                        ));
                ?>
                  <li class="nav-item">
                    <a href="<?= e(APP_URL . '/' . $s['href']) ?>"
                       class="nav-link text-white py-1 ps-2 sidebar-sub<?= $sub_active ? ' active' : '' ?>"><?= e($s['label']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </li>
      <?php else: ?>
          <li class="nav-item">
            <a href="<?= e(APP_URL . '/' . $m['href']) ?>"
               class="nav-link text-white<?= sidebar_is_active($m, $active) ? ' active' : '' ?>">
              <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?>
            </a>
          </li>
      <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </div>

  <hr class="text-white m-0">
  <div class="p-3 sidebar-footer">
    <div class="d-flex align-items-center mb-2">
      <a href="<?= e(APP_URL) ?>/pages/profile/index.php"
         class="d-flex align-items-center text-white text-decoration-none flex-grow-1 overflow-hidden sidebar-user-link"
         title="Buka Profil Saya">
        <div class="avatar-sm me-2 flex-shrink-0 d-flex align-items-center justify-content-center">
          <i class="bi bi-person-circle fs-4 text-white-50"></i>
        </div>
        <div class="text-white lh-1 flex-grow-1 overflow-hidden">
          <div class="fw-semibold text-truncate" style="font-size:.85rem"><?= e($user['name'] ?? '') ?></div>
          <small class="text-white-50 text-truncate d-block mt-1"><?= e($_SESSION['role_name'] ?? '') ?></small>
        </div>
      </a>
      <button type="button" class="dark-toggle sidebar-theme-toggle text-white ms-2 flex-shrink-0" aria-label="Ganti tema" title="Ganti tema">
        <i class="bi bi-moon-stars"></i>
      </button>
    </div>
    <a href="<?= e(APP_URL) ?>/pages/auth/logout.php"
       class="btn btn-sm btn-outline-light w-100"
       onclick="return confirm('Yakin ingin keluar?')">
      <i class="bi bi-box-arrow-right me-1"></i> Keluar
    </a>
  </div>
</nav>
