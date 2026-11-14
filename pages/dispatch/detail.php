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
$title  = 'Detail Penjadwalan - ' . $booking['document_number'];
require __DIR__ . '/../../includes/header.php';

$dispActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/dispatch/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ['label' => 'Lihat Booking', 'icon' => 'box-arrow-up-right', 'href' => $booking_link . '?id=' . $id, 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($aktif) {
    $dispActions[] = ['label' => 'Cetak Surat Jalan', 'icon' => 'printer', 'href' => 'pages/dispatch/manifest.php?id=' . (int) $aktif['id'], 'target' => '_blank', 'class' => 'btn btn-dark btn-sm'];
}
if (can('dispatch.manage')) {
    $dispActions[] = ['label' => 'Tugaskan Driver', 'icon' => 'plus-lg', 'href' => 'pages/dispatch/assign.php', 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Penjadwalan Driver',
        'geo-alt',
        (string) $booking['document_number'],
        [
            ['html' => status_badge($status)],
            ['text' => strtoupper($type), 'class' => 'bg-info-subtle text-info-emphasis border font-monospace'],
        ],
        $dispActions
    ) ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="dispatchDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-disp-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-disp-detail" type="button" role="tab">
                    Detail &amp; Riwayat Penugasan<?php if ($riwayat): ?> <span class="badge bg-secondary-subtle text-dark border ms-1"><?= count($riwayat) ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-disp-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-disp-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="dispatchDetailTabContent">
                <div class="tab-pane fade show active" id="tab-disp-detail" role="tabpanel" aria-labelledby="tab-disp-detail-btn">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Ringkasan Booking</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('No. Dokumen', (string) $booking['document_number'], false, true) ?>
                                    <?= ui_erp_ro_row('Tipe Layanan', ucfirst($type)) ?>
                                    <?= ui_erp_ro_row('Jadwal', trim((string) $booking['date'] . ' ' . substr((string) $booking['time'], 0, 5))) ?>
                                    <?= ui_erp_ro_row('Driver Aktif', (string) ($aktif['employee_name'] ?? '-')) ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Rute &amp; Nilai Transaksi</span></div>
                                <div class="rnt-panel-body">
                                    <?= ui_erp_ro_row('Alamat Asal', (string) ($booking['pickup_address'] ?? ''), true) ?>
                                    <?= ui_erp_ro_row('Alamat Tujuan', (string) ($booking['delivery_address'] ?? ''), true) ?>
                                    <?= ui_erp_ro_row('Total Biaya', rupiah((float) $booking['total_price'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Riwayat Penugasan Driver</span>
                            <?php if (can('dispatch.manage')): ?>
                                <a class="rnt-header-action" href="<?= e(APP_URL) ?>/pages/dispatch/assign.php">
                                    <i class="bi bi-plus-circle me-1"></i>Tugaskan Driver
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body p-0">
                            <div class="table-responsive">
                                <table class="erp-grid-table">
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
                                            <td><?= e($r['ended_at'] ? (string) $r['ended_at'] : '-') ?></td>
                                            <td><span class="badge bg-<?= $is_active ? 'warning text-dark' : 'secondary' ?>"><?= $is_active ? 'sedang jalan' : 'selesai' ?></span></td>
                                            <td><?= e((string) $r['reason']) ?></td>
                                            <td><?= e((string) $r['created_user']) ?></td>
                                            <td class="text-end text-nowrap">
                                                <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(APP_URL) ?>/pages/dispatch/manifest.php?id=<?= (int) $r['id'] ?>" title="Cetak Surat Jalan" aria-label="Cetak Surat Jalan"><i class="bi bi-printer"></i></a>
                                                    <?php if ($is_active && can('dispatch.manage')): ?>
                                                    <form method="post" action="<?= e($post_url) ?>" class="d-inline-flex gap-1 justify-content-end">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="type" value="<?= e($type) ?>">
                                                        <input type="hidden" name="id" value="<?= $id ?>">
                                                        <input type="hidden" name="assignment_id" value="<?= (int) $r['id'] ?>">
                                                        <input class="form-control form-control-sm" name="reason" placeholder="Catatan selesai" maxlength="2000" style="max-width:10rem">
                                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Selesaikan Tugas" aria-label="Selesaikan Tugas" data-confirm="Selesaikan tugas ini?"><i class="bi bi-check2-circle"></i></button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$riwayat): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-3">Belum ada penugasan driver untuk booking ini.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-disp-audit" role="tabpanel" aria-labelledby="tab-disp-audit-btn">
                    <?= ui_erp_audit_panel($booking) ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
