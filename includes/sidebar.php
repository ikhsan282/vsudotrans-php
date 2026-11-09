<?php
/**
 * Layout: sidebar menu ala Arya Green IPL (fixed, scroll terpisah),
 * difilter dengan can(). Bergantung pada $active yang diset halaman
 * sebelum include header. Item dengan 'sub' = dropdown collapse.
 */
$active = $active ?? '';

/** Menu/entri 'admin_only' hanya untuk Super Admin (digerakkan is_super_admin(), bukan perm). */
function super_admin_menu(array $item): bool
{
    return empty($item['admin_only']) || is_super_admin();
}

$menu = [
    ['key' => 'dashboard', 'label' => 'Dashboard',       'icon' => 'speedometer2', 'href' => 'pages/dashboard/index.php', 'perm' => null],
    ['key' => 'companies', 'label' => 'Perusahaan',      'icon' => 'building',     'href' => 'pages/companies/index.php', 'perm' => 'companies.view'],
    ['key' => 'groups',    'label' => 'Grup Perusahaan', 'icon' => 'diagram-2',    'href' => 'pages/groups/index.php',    'perm' => 'groups.view'],
    ['key' => 'branches',  'label' => 'Cabang',          'icon' => 'diagram-3',    'href' => 'pages/branches/index.php',  'perm' => 'branches.view'],
    ['key' => 'employees', 'label' => 'Karyawan',        'icon' => 'people',       'href' => 'pages/employees/index.php', 'perm' => 'employees.view'],
    ['key' => 'travel', 'label' => 'Travel', 'icon' => 'bus-front', 'perm' => 'travel.view', 'sub' => [
        ['label' => 'Booking',   'href' => 'pages/travel/bookings/index.php',   'perms' => 'travel.view'],
        ['label' => 'Jadwal',    'href' => 'pages/travel/schedules/index.php',  'perms' => 'travel.view'],
        ['label' => 'Rute',      'href' => 'pages/travel/rutes/index.php',      'perms' => 'travel.view'],
        ['label' => 'Penumpang', 'href' => 'pages/travel/passengers/index.php', 'perms' => 'travel.view'],
    ]],
    ['key' => 'rental', 'label' => 'Rental', 'icon' => 'car-front', 'href' => 'pages/rental/bookings/index.php', 'perm' => 'rental.view'],
    ['key' => 'vehicles',  'label' => 'Kendaraan',       'icon' => 'truck',        'href' => 'pages/vehicles/index.php',  'perm' => 'vehicles.view'],
    ['key' => 'users',     'label' => 'Pengguna',        'icon' => 'person-badge', 'href' => 'pages/users/index.php',     'perm' => 'users.view'],
    ['key' => 'roles',     'label' => 'Role & Hak Akses','icon' => 'shield-lock',  'href' => 'pages/roles/index.php',     'perm' => 'roles.manage'],
    ['key' => 'masters', 'label' => 'Referensi Umum', 'icon' => 'list-ul', 'perm' => 'masters.view', 'sub' => [
        ['label' => 'Bank',      'href' => 'pages/masters/banks/index.php',      'perms' => 'masters.view'],
        ['label' => 'Wilayah: Negara', 'href' => 'pages/masters/countries/index.php', 'perms' => 'masters.view'],
        ['label' => 'Provinsi',  'href' => 'pages/masters/provinces/index.php', 'perms' => 'masters.view'],
        ['label' => 'Kota/Kab.', 'href' => 'pages/masters/cities/index.php',    'perms' => 'masters.view'],
        ['label' => 'Kecamatan', 'href' => 'pages/masters/districts/index.php', 'perms' => 'masters.view'],
        ['label' => 'Desa',      'href' => 'pages/masters/villages/index.php',  'perms' => 'masters.view'],
        ['label' => 'Satuan',    'href' => 'pages/masters/unit_measures/index.php', 'perms' => 'masters.view'],
    ]],
    ['key' => 'hr', 'label' => 'Master HR', 'icon' => 'briefcase', 'perm' => 'hr.view', 'sub' => [
        ['label' => 'Grade Jabatan',  'href' => 'pages/hr/job_grades/index.php',        'perms' => 'hr.view'],
        ['label' => 'Jabatan',        'href' => 'pages/hr/job_titles/index.php',        'perms' => 'hr.view'],
        ['label' => 'Posisi',         'href' => 'pages/hr/job_positions/index.php',     'perms' => 'hr.view'],
        ['label' => 'Struktur Org.',  'href' => 'pages/hr/organization_structures/index.php', 'perms' => 'hr.view'],
        ['label' => 'Penghargaan',    'href' => 'pages/hr/employee_recognitions/index.php',   'perms' => 'hr.view'],
    ]],
    ['key' => 'attendance', 'label' => 'Absensi', 'icon' => 'calendar-check', 'perm' => 'attendance.view', 'sub' => [
        ['label' => 'Absensi Harian', 'href' => 'pages/attendance/index.php',   'perms' => 'attendance.view'],
        ['label' => 'Rekap Absensi',  'href' => 'pages/attendance/summary.php', 'perms' => 'attendance.view'],
    ]],
    ['key' => 'dispatch', 'label' => 'Penjadwalan', 'icon' => 'person-badge', 'perm' => null, 'sub' => [
        ['label' => 'Kalender Operasional', 'href' => 'pages/dispatch/calendar.php', 'perms' => 'dispatch.view'],
        ['label' => 'Papan & Riwayat', 'href' => 'pages/dispatch/index.php',  'perms' => 'dispatch.view'],
        ['label' => 'Tugaskan Driver', 'href' => 'pages/dispatch/assign.php', 'perms' => 'dispatch.manage'],
    ]],
    ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'cash-stack', 'href' => 'pages/payroll/periods/index.php', 'perm' => 'payroll.view'],
    ['key' => 'finance', 'label' => 'Keuangan', 'icon' => 'cash-coin', 'perm' => null, 'sub' => [
        ['label' => 'Buku Kas & Bank',   'href' => 'pages/finance/cash/index.php',            'perms' => 'finance.view'],
        ['label' => 'Akun Kas/Bank',     'href' => 'pages/finance/cash_accounts/index.php',   'perms' => 'finance.view'],
        ['label' => 'Invoice',           'href' => 'pages/finance/invoices/index.php',        'perms' => 'invoices.view'],
        ['label' => 'Metode Pembayaran', 'href' => 'pages/finance/payment_methods/index.php', 'perms' => 'finance.view'],
    ]],
    // Grup "Sistem": khusus Super Admin. Backend gate memakai is_super_admin() (halaman
    // memuat seluruh data lintas perusahaan), jadi 'perm' => null di sini dan visibilitas
    // menu diuji langsung dengan super_admin_menu() — bukan perm string.
    // 'system.view' TIDAK dipakai: permissions hanya boleh dikelola dari halaman
    // Role & Hak Akses, dan is_super_admin() tidak melihat daftar permission.
    ['key' => 'system', 'label' => 'Sistem', 'icon' => 'gear', 'perm' => null, 'admin_only' => true, 'sub' => [
        ['label' => 'Backup & Restore', 'href' => 'pages/settings/backup.php', 'perms' => null, 'admin_only' => true],
    ]],
    ['key' => 'reports', 'label' => 'Laporan', 'icon' => 'file-earmark-bar-graph', 'perm' => 'reports.view', 'sub' => [
        ['label' => 'Laporan Booking', 'href' => 'pages/reports/bookings.php', 'perms' => 'reports.view'],
        ['label' => 'Laporan Keuangan', 'href' => 'pages/reports/finance.php', 'perms' => 'finance.view'],
        ['label' => 'Laporan Operasional', 'href' => 'pages/reports/operations.php', 'perms' => 'reports.view'],
        ['label' => 'Laporan Driver', 'href' => 'pages/reports/dispatch.php', 'perms' => 'reports.view'],
    ]],
    ['key' => 'audit',     'label' => 'Audit Trail',     'icon' => 'clock-history', 'href' => 'pages/audit/index.php', 'perm' => 'audit.view'],
    ['key' => 'profile',   'label' => 'Profil Saya',     'icon' => 'person-circle', 'href' => 'pages/profile/index.php', 'perm' => null],
];

// $nav_icon dipakai topbar (header.php) untuk ikon di samping judul.
$nav_icon = 'grid';
foreach ($menu as $m) {
    if ($m['key'] === $active) { $nav_icon = $m['icon']; break; }
}
?>
<nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-0">
  <a href="<?= e(APP_URL) ?>/pages/dashboard/index.php"
     class="d-flex align-items-center justify-content-center p-3 text-white text-decoration-none sidebar-brand"
     title="<?= e(APP_NAME) ?>">
    <img src="<?= e(APP_URL) ?>/assets/brand/logo-transmandiri.png" alt="<?= e(APP_NAME) ?>" class="sidebar-logo">
  </a>
  <hr class="text-white m-0">

  <div class="nav-scroll">
    <ul class="nav nav-pills flex-column px-2 py-2">
      <?php foreach ($menu as $m): ?>
        <?php if (!super_admin_menu($m)) continue; ?>
        <?php if ($m['perm'] !== null && !can($m['perm'])) continue; ?>
        <?php if (!empty($m['sub'])): ?>
          <?php
          // Sembunyikan grup kalau semua sub-nya tak terlihat.
          $visible = array_filter($m['sub'], fn($s) =>
              super_admin_menu($s) && ($s['perms'] === null || can($s['perms'])));
          if (!$visible) continue;
          ?>
          <li class="nav-item">
            <a class="nav-link text-white d-flex align-items-center"
               data-bs-toggle="collapse" href="#menu-<?= e($m['key']) ?>" role="button"
               aria-expanded="<?= $active === $m['key'] ? 'true' : 'false' ?>"
               aria-controls="menu-<?= e($m['key']) ?>">
              <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?>
              <i class="bi bi-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse<?= $active === $m['key'] ? ' show' : '' ?>" id="menu-<?= e($m['key']) ?>">
              <ul class="nav flex-column ms-3">
                <?php foreach ($visible as $s): ?>
                  <li class="nav-item">
                    <a href="<?= e(APP_URL . '/' . $s['href']) ?>"
                       class="nav-link text-white py-1 ps-2 sidebar-sub"><?= e($s['label']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a href="<?= e(APP_URL . '/' . $m['href']) ?>"
               class="nav-link text-white<?= $active === $m['key'] ? ' active' : '' ?>">
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
      <div class="avatar-sm me-2">
        <i class="bi bi-person-circle fs-4 text-white-50"></i>
      </div>
      <div class="text-white lh-1 flex-grow-1 overflow-hidden">
        <div class="fw-semibold text-truncate" style="font-size:.85rem"><?= e($user['name'] ?? '') ?></div>
        <small class="text-white-50"><?= e($_SESSION['role_name'] ?? '') ?></small>
      </div>
      <button type="button" class="dark-toggle sidebar-theme-toggle text-white ms-2" aria-label="Ganti tema" title="Ganti tema">
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
