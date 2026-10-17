<?php
/**
 * Absensi harian — daftar + filter rentang tanggal / karyawan / status, discope perusahaan aktif.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.view');
attendance_scope_cid();

$cid = active_company_id();
[$from, $to] = attendance_range($_GET);
$emp_filter = (int) ($_GET['employee_id'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

[$rows, $p] = attendance_list($from, $to, $emp_filter, $status, $page);
$employees = attendance_employee_options($cid);
$query_base = http_build_query(array_filter([
    'from' => $from, 'to' => $to,
    'employee_id' => $emp_filter ?: '', 'status' => $status,
]));

$active = 'attendance';
$title  = 'Absensi Harian';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Absensi Harian</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="summary.php?from=<?= e($from) ?>&to=<?= e($to) ?>">
                <i class="bi bi-clipboard-data"></i> Rekap</a>
            <?php if (can('attendance.manage')): ?>
                <a class="btn btn-sm btn-primary" href="form.php"><i class="bi bi-plus-lg"></i> Input Absensi</a>
            <?php endif; ?>
        </div>
    </div>

    <?= flash_html() ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Karyawan</label>
                    <select class="form-select form-select-sm" name="employee_id">
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
                <div class="col-12 col-lg-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center small text-muted">
            <span><?= (int) $p['total'] ?> baris absensi ditemukan</span>
            <?= pagination_html($p, $query_base) ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Tanggal</th><th>Karyawan</th><th>Status</th>
                    <th>Jam Masuk</th><th>Jam Keluar</th><th>Catatan</th>
                    <?php if (can('attendance.manage')): ?><th class="text-end">Aksi</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(tanggal_indo($r['date'])) ?></td>
                        <td><span class="fw-semibold"><?= e($r['employee_code']) ?></span> — <?= e($r['employee_name']) ?></td>
                        <td><?= attendance_status_badge($r['status']) ?></td>
                        <td><?= $r['check_in'] ? e(substr((string) $r['check_in'], 0, 5)) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $r['check_out'] ? e(substr((string) $r['check_out'], 0, 5)) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= e($r['note'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <?php if (can('attendance.manage')): ?>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                            <form method="post" action="delete.php" class="d-inline"
                                  onsubmit="return confirm('Hapus absensi ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= can('attendance.manage') ? 7 : 6 ?>" class="text-center text-muted py-4">
                        Tidak ada absensi pada rentang tanggal ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
