<?php
/**
 * Absensi harian — daftar + filter rentang tanggal / karyawan / status, discope perusahaan aktif.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.view');
if (!is_super_admin() && active_company_id() === null) {
    attendance_scope_cid();
}

$compScope     = company_filter_scope('a.company_id');
$filter_cid    = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;
$cid           = is_super_admin() ? ($filter_cid > 0 ? $filter_cid : active_company_id()) : active_company_id();

[$from, $to] = attendance_range($_GET);
$emp_filter = (int) ($_GET['employee_id'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

[$rows, $p] = attendance_list($from, $to, $emp_filter, $status, $page, is_super_admin() ? $filter_cid : null);
$employees = attendance_employee_options($cid, $emp_filter > 0 ? [$emp_filter] : [], 50);
$query_base = http_build_query(array_filter([
    'company_id'  => is_super_admin() ? (string) $filter_cid : '',
    'from'        => $from,
    'to'          => $to,
    'employee_id' => $emp_filter ?: '',
    'status'      => $status,
], static fn($v): bool => $v !== ''));

$att_actions = [
    ['label' => 'Rekap Absensi', 'icon' => 'clipboard-data', 'href' => 'pages/attendance/summary.php?from=' . urlencode($from) . '&to=' . urlencode($to) . (is_super_admin() ? '&company_id=' . $filter_cid : ''), 'class' => 'btn btn-outline-secondary btn-sm'],
];
if (can('attendance.manage') && active_company_id() !== null) {
    $att_actions[] = ['label' => 'Input Absensi', 'icon' => 'plus-lg', 'href' => 'pages/attendance/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'attendance';
$title  = 'Absensi Harian';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Absensi Harian',
        ['Dashboard' => 'pages/dashboard/index.php', 'Absensi Harian' => null],
        [['text' => number_format($p['total'], 0, ',', '.') . ' Baris', 'class' => 'bg-light text-secondary border']],
        $att_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-lg-3">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($from) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($to) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Karyawan</label>
                    <select class="form-select form-select-sm ts-select" name="employee_id"
                        data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                        <?= $cid !== null ? 'data-ts-company="' . (int) $cid . '"' : '' ?>
                        data-ts-limit="30">
                        <option value="">Semua karyawan</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int) $emp['id'] ?>"<?= $emp_filter === (int) $emp['id'] ? ' selected' : '' ?>>
                                <?= e($emp['code'] . ' - ' . $emp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="">Semua status</option>
                        <?php foreach (attendance_statuses() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/attendance/index.php', ($from !== date('Y-m-01') || $to !== date('Y-m-t') || $emp_filter > 0 || $status !== '' || (is_super_admin() && $filter_cid > 0))) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
            <span><?= (int) $p['total'] ?> baris absensi ditemukan</span>
            <?= pagination_html($p, $query_base) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
                    <th>Tanggal</th><th>Karyawan</th><th>Status</th>
                    <th>Jam Masuk</th><th>Jam Keluar</th><th>Catatan</th>
                    <?php if (can('attendance.manage')): ?><th class="text-end">Aksi</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td class="text-nowrap"><?= e(date('d-M-Y', strtotime((string) $r['date']))) ?></td>
                        <td><span class="fw-semibold"><?= e($r['employee_code']) ?></span> — <?= e($r['employee_name']) ?></td>
                        <td><?= attendance_status_badge($r['status']) ?></td>
                        <td><?= $r['check_in'] ? e(substr((string) $r['check_in'], 0, 5)) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $r['check_out'] ? e(substr((string) $r['check_out'], 0, 5)) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= e($r['note'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <?php if (can('attendance.manage')): ?>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                            <form method="post" action="delete.php" class="d-inline"
                                  onsubmit="return confirm('Hapus absensi ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= ($show_comp_col ? 1 : 0) + (can('attendance.manage') ? 7 : 6) ?>" class="text-center text-muted py-4">
                        Tidak ada absensi pada rentang tanggal ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
