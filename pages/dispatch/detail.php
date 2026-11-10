<?php
/**
 * Detail booking + riwayat penugasan driver untuk booking tersebut.
 * Halaman ini yang dipakai saat ada masalah di jalan: satu tempat melihat
 * driver aktif, kendaraan, dan siapa yang pernah bertugas.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.view');
require_once __DIR__ . '/../../includes/dispatch_service.php';
require_once __DIR__ . '/../travel/_helpers.php';

$cid  = require_active_company();
$user = $_SESSION['username'] ?? null;
$type = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Tipe ngawur dari query string bukan error 500: balikkan ke papan.
if (!in_array($type, DISPATCH_TYPES, true)) {
    flash('error', 'Tipe booking tidak dikenal.');
    redirect('pages/dispatch/index.php');
}
$booking = booking_find($type, $id);
if (!$booking) {
    flash('error', 'Booking tidak ditemukan.');
    redirect('pages/dispatch/index.php');
}
assert_company_access((int) $booking['company_id']);

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
    redirect('pages/dispatch/detail.php?type=' . urlencode($type) . '&id=' . $id);
}

$riwayat = dispatch_by_booking($type, $id);
$status  = booking_current_status($type, $id);
$aktif   = null;
foreach ($riwayat as $r) {
    if (empty($r['ended_at'])) { $aktif = $r; break; }
}

$booking_link = $type === 'rental' ? 'pages/rental/bookings/detail.php' : 'pages/travel/bookings/detail.php';

$post_url = APP_URL . '/pages/dispatch/detail.php?type=' . urlencode($type) . '&id=' . $id;

$active = 'dispatch';
$title  = 'Detail Penjadwalan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><?= e((string) $booking['document_number']) ?> <?= status_badge($status) ?></h4>
        <div>
            <?php if ($aktif): ?>
                <a class="btn btn-primary btn-sm" target="_blank" href="<?= e(APP_URL) ?>/pages/dispatch/manifest.php?id=<?= (int) $aktif['id'] ?>"><i class="bi bi-printer me-1"></i> Cetak Surat Jalan</a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/<?= e($booking_link) ?>?id=<?= $id ?>">Lihat Booking</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/dispatch/index.php">Kembali</a>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Ringkasan Booking</div>
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-md-3"><div class="text-muted">Tipe</div><div class="fw-semibold"><?= e($type) ?></div></div>
                <div class="col-md-3"><div class="text-muted">Tanggal</div><div class="fw-semibold"><?= e((string) $booking['date']) ?> <?= e(substr((string) $booking['time'], 0, 5)) ?></div></div>
                <div class="col-md-3"><div class="text-muted">Total</div><div class="fw-semibold"><?= e(rupiah((float) $booking['total_price'])) ?></div></div>
                <div class="col-md-3"><div class="text-muted">Driver Aktif</div><div class="fw-semibold"><?= e((string) ($aktif['employee_name'] ?? '—')) ?></div></div>
                <div class="col-md-6"><div class="text-muted">Alamat Asal</div><div><?= e((string) $booking['pickup_address']) ?></div></div>
                <div class="col-md-6"><div class="text-muted">Alamat Tujuan</div><div><?= e((string) $booking['delivery_address']) ?></div></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold">Riwayat Penugasan</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr>
                    <th>#</th><th>Driver</th><th>Kendaraan</th><th>Mulai</th><th>Selesai</th>
                    <th>Status</th><th>Catatan</th><th>Oleh</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($riwayat as $r): ?>
                    <?php $is_active = empty($r['ended_at']); ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td class="fw-semibold"><?= e((string) $r['employee_name']) ?></td>
                        <td><?= e((string) ($r['vehicle_number'] ?? '-')) ?></td>
                        <td><?= e((string) $r['started_at']) ?></td>
                        <td><?= e($r['ended_at'] ? (string) $r['ended_at'] : '—') ?></td>
                        <td><span class="badge bg-<?= $is_active ? 'warning text-dark' : 'secondary' ?>"><?= $is_active ? 'sedang jalan' : 'selesai' ?></span></td>
                        <td><?= e((string) $r['reason']) ?></td>
                        <td><?= e((string) $r['created_user']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" href="<?= e(APP_URL) ?>/pages/dispatch/manifest.php?id=<?= (int) $r['id'] ?>" title="Cetak Surat Jalan"><i class="bi bi-printer"></i></a>
                            <?php if ($is_active && can('dispatch.manage')): ?>
                            <form method="post" action="<?= e($post_url) ?>" class="d-inline-flex gap-1 justify-content-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="type" value="<?= e($type) ?>">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="assignment_id" value="<?= (int) $r['id'] ?>">
                                <input class="form-control form-control-sm" name="reason" placeholder="Catatan selesai" maxlength="2000" style="max-width:10rem">
                                <button class="btn btn-sm btn-outline-success" type="submit" data-confirm="Selesaikan tugas ini?">Selesaikan Tugas</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$riwayat): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada penugasan driver untuk booking ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (can('dispatch.manage')): ?>
        <div class="card-footer bg-white">
            <a class="btn btn-sm btn-primary" href="<?= e(APP_URL) ?>/pages/dispatch/assign.php"><i class="bi bi-plus-lg"></i> Tugaskan Driver</a>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
