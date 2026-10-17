<?php
/**
 * Layout: sidebar menu, difilter dengan can().
 * Bergantung pada $active yang diset halaman sebelum include header.
 * Item dengan 'sub' = dropdown collapse; 'perm' menjaga seluruh grup,
 * tiap sub punya 'perms' sendiri (cek per item).
 */
$active = $active ?? '';

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
    ['key' => 'reports', 'label' => 'Laporan', 'icon' => 'file-earmark-bar-graph', 'perm' => 'reports.view', 'sub' => [
        ['label' => 'Laporan Booking', 'href' => 'pages/reports/bookings.php', 'perms' => 'reports.view'],
        ['label' => 'Laporan Keuangan', 'href' => 'pages/reports/finance.php', 'perms' => 'reports.view'],
        ['label' => 'Laporan Operasional', 'href' => 'pages/reports/operations.php', 'perms' => 'reports.view'],
    ]],
    ['key' => 'audit',     'label' => 'Audit Trail',     'icon' => 'clock-history', 'href' => 'pages/audit/index.php', 'perm' => 'audit.view'],
    ['key' => 'profile',   'label' => 'Profil Saya',     'icon' => 'person-circle', 'href' => 'pages/profile/index.php', 'perm' => null],
];
?>
<aside class="col-12 col-md-3 col-lg-2 bg-white border-end sidebar">
    <nav class="list-group list-group-flush py-2">
        <?php foreach ($menu as $m): ?>
            <?php if ($m['perm'] !== null && !can($m['perm'])) continue; ?>
            <?php if (!empty($m['sub'])): ?>
                <a class="list-group-item list-group-item-action border-0 rounded-0<?= $active === $m['key'] ? ' active' : '' ?>"
                   data-bs-toggle="collapse" href="#menu-<?= e($m['key']) ?>" role="button"
                   aria-expanded="<?= $active === $m['key'] ? 'true' : 'false' ?>" aria-controls="menu-<?= e($m['key']) ?>">
                    <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?><i class="bi bi-chevron-down float-end small"></i>
                </a>
                <div class="collapse<?= $active === $m['key'] ? ' show' : '' ?>" id="menu-<?= e($m['key']) ?>">
                    <?php foreach ($m['sub'] as $s): ?>
                        <?php if (!can($s['perms'])) continue; ?>
                        <a href="<?= e(APP_URL . '/' . $s['href']) ?>"
                           class="list-group-item list-group-item-action border-0 ps-4 py-1 small">
                            <?= e($s['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a href="<?= e(APP_URL . '/' . $m['href']) ?>"
                   class="list-group-item list-group-item-action border-0 rounded-0<?= $active === $m['key'] ? ' active' : '' ?>">
                    <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>