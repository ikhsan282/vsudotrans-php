<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.view');
require_once __DIR__ . '/../../includes/dispatch_service.php';
require_once __DIR__ . '/../travel/_helpers.php';

$cid  = require_active_company();
$user = $_SESSION['username'] ?? null;

// POST-handler dulu (pola halaman proyek), lalu HTML.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('dispatch.manage');
    verify_csrf();
    $aid    = (int) ($_POST['assignment_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $res = dispatch_end($aid, $cid, $reason === '' ? null : $reason, (string) $user);
        audit('dispatch.end', "Penugasan #{$aid} selesai");
        flash('success', 'Tugas selesai pada ' . $res['ended_at'] . '.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('pages/dispatch/index.php');
}

$tab   = ($_GET['tab'] ?? 'aktif') === 'riwayat' ? 'riwayat' : 'aktif';
$from  = (string) ($_GET['from'] ?? '');
$to    = (string) ($_GET['to'] ?? '');

$rows = $tab === 'riwayat'
    ? dispatch_history($cid, $from ?: null, $to ?: null)
    : dispatch_active($cid);

$aktif_count = count(dispatch_active($cid));

$active = 'dispatch';
$title  = 'Penjadwalan';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Penjadwalan Driver</h4>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary"><?= (int) $aktif_count ?> sedang jalan</span>
            <?php if (can('dispatch.manage')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/dispatch/assign.php"><i class="bi bi-plus-lg"></i> Tugaskan Driver</a>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link<?= $tab === 'aktif' ? ' active' : '' ?>" href="?tab=aktif">Aktif</a></li>
        <li class="nav-item"><a class="nav-link<?= $tab === 'riwayat' ? ' active' : '' ?>" href="?tab=riwayat">Riwayat</a></li>
    </ul>

    <?php if ($tab === 'riwayat'): ?>
    <form class="row g-2 mb-3" method="get">
        <input type="hidden" name="tab" value="riwayat">
        <div class="col-auto"><label class="col-form-label-sm">Dari</label></div>
        <div class="col-auto"><input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>"></div>
        <div class="col-auto"><label class="col-form-label-sm">Sampai</label></div>
        <div class="col-auto"><input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>"></div>
        <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
        <div class="col-auto"><a class="btn btn-sm btn-link" href="?tab=riwayat">Reset</a></div>
    </form>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Booking</th><th>Tipe</th><th>Driver</th><th>Kendaraan</th>
                    <th><?= $tab === 'riwayat' ? 'Mulai' : 'Mulai' ?></th>
                    <?php if ($tab === 'riwayat'): ?><th>Selesai</th><th>Status</th><?php endif; ?>
                    <th>Catatan</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $is_active = empty($r['ended_at']); ?>
                    <tr>
                        <td class="fw-semibold"><?= e((string) $r['document_number']) ?>
                            <div class="text-muted small"><?= e((string) $r['booking_type']) ?> #<?= (int) $r['booking_id'] ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= e((string) $r['booking_type']) ?></span></td>
                        <td><?= e((string) $r['employee_name']) ?></td>
                        <td><?= e((string) ($r['vehicle_number'] ?? '-')) ?></td>
                        <td><?= e(substr((string) $r['started_at'], 0, 16)) ?></td>
                        <?php if ($tab === 'riwayat'): ?>
                            <td><?= e($r['ended_at'] ? substr((string) $r['ended_at'], 0, 16) : '-') ?></td>
                            <td><span class="badge bg-<?= $is_active ? 'warning text-dark' : 'secondary' ?>"><?= $is_active ? 'Berjalan' : 'Selesai' ?></span></td>
                        <?php endif; ?>
                        <td><?= e((string) $r['reason']) ?></td>
                        <td class="text-end">
                            <?php if ($is_active && can('dispatch.manage')): ?>
                            <form method="post" action="<?= e(APP_URL) ?>/pages/dispatch/index.php" class="d-flex gap-1 justify-content-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="assignment_id" value="<?= (int) $r['id'] ?>">
                                <input class="form-control form-control-sm" name="reason" placeholder="Catatan selesai" maxlength="2000" style="max-width:10rem">
                                <button class="btn btn-sm btn-outline-success" type="submit" onclick="return confirm('Selesaikan tugas ini?')">Selesaikan Tugas</button>
                            </form>
                            <?php elseif ($is_active): ?>
                            <span class="badge bg-warning text-dark">sedang jalan</span>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/dispatch/detail.php?type=<?= e((string) $r['booking_type']) ?>&amp;id=<?= (int) $r['booking_id'] ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $tab === 'riwayat' ? 9 : 7 ?>" class="text-center text-muted py-4">
                        <?= $tab === 'riwayat' ? 'Belum ada riwayat penugasan pada rentang ini.' : 'Tidak ada penugasan aktif.' ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
