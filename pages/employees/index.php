<?php
/** Daftar karyawan — list + pagination + search + filter status, scoped company_scope(). */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.view');
employee_scope_cid();

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, ['aktif', 'resign'], true)) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

[$rows, $p] = employee_list($page, $q, $status);
$can_manage = can('employees.manage');
$query_base = http_build_query(array_filter(['q' => $q, 'status' => $status]));

$active = 'employees';
$title = 'Karyawan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
<h4 class="fw-bold mb-4"><?= e($title) ?></h4>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-center mb-3">
            <div class="col-auto flex-grow-1">
                <input type="search" name="q" class="form-control" placeholder="Cari nama atau kode karyawan"
                       value="<?= e($q) ?>">
            </div>
            <div class="col-auto">
                <select name="status" class="form-select">
                    <option value="">Semua status</option>
                    <option value="aktif" <?= $status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="resign" <?= $status === 'resign' ? 'selected' : '' ?>>Resign</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" type="submit">Filter</button>
            </div>
            <?php if ($can_manage): ?>
                <div class="col-auto">
                    <a href="form.php" class="btn btn-primary">Tambah Karyawan</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
            <span><?= $p['total'] ?> karyawan ditemukan</span>
            <?= pagination_html($p, $query_base) ?>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Cabang</th>
                    <th>Kontak</th>
                    <th>Jabatan</th>
                    <th>Masuk</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e($r['branch_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($r['email']): ?><div class="small"><?= e($r['email']) ?></div><?php endif; ?>
                            <?php if ($r['phone_number']): ?><div class="small text-muted"><?= e($r['phone_number']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($r['job_title'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= e($r['join_date']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= $r['status'] === 'aktif' ? 'success' : 'secondary' ?>">
                                <?= e($r['status']) ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Detail</a>
                            <?php if ($can_manage): ?>
                                <a href="form.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <form method="post" action="delete.php" class="d-inline"
                                      onsubmit="return confirm('Hapus karyawan <?= e($r['name']) ?>? Tindakan ini tidak dapat dibatalkan.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data karyawan.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
