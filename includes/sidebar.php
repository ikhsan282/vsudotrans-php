<?php
/**
 * Layout: sidebar menu, difilter dengan can().
 * Bergantung pada $active yang diset halaman sebelum include header.
 */
$active = $active ?? '';

$menu = [
    ['key' => 'dashboard', 'label' => 'Dashboard',      'icon' => 'speedometer2',    'href' => 'pages/dashboard/index.php', 'perm' => null],
    ['key' => 'companies', 'label' => 'Perusahaan',      'icon' => 'building',        'href' => 'pages/companies/index.php', 'perm' => 'companies.view'],
    ['key' => 'branches',  'label' => 'Cabang',          'icon' => 'diagram-3',       'href' => 'pages/branches/index.php',  'perm' => 'branches.view'],
    ['key' => 'employees', 'label' => 'Karyawan',        'icon' => 'people',          'href' => 'pages/employees/index.php', 'perm' => 'employees.view'],
    ['key' => 'users',     'label' => 'Pengguna',        'icon' => 'person-badge',    'href' => 'pages/users/index.php',     'perm' => 'users.view'],
    ['key' => 'roles',     'label' => 'Role & Hak Akses','icon' => 'shield-lock',     'href' => 'pages/roles/index.php',     'perm' => 'roles.manage'],
    ['key' => 'masters', 'label' => 'Referensi Umum', 'icon' => 'list-ul', 'href' => 'pages/masters/banks/index.php', 'perm' => 'masters.view'],
    ['key' => 'hr',      'label' => 'Master HR',      'icon' => 'briefcase', 'href' => 'pages/hr/job_grades/index.php', 'perm' => 'hr.view'],
    ['key' => 'audit',     'label' => 'Audit Trail',     'icon' => 'clock-history',   'href' => 'pages/audit/index.php',     'perm' => 'audit.view'],
];
?>
<aside class="col-12 col-md-3 col-lg-2 bg-white border-end sidebar">
    <div class="list-group list-group-flush py-2">
        <?php foreach ($menu as $m): ?>
            <?php if ($m['perm'] === null || can($m['perm'])): ?>
                <a href="<?= e(APP_URL . '/' . $m['href']) ?>"
                   class="list-group-item list-group-item-action border-0 rounded-0<?= $active === $m['key'] ? ' active' : '' ?>">
                    <i class="bi bi-<?= e($m['icon']) ?> me-2"></i><?= e($m['label']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</aside>
