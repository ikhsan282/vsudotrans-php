<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.manage');
require_once __DIR__ . '/../../includes/dispatch_service.php';
require_once __DIR__ . '/../travel/_helpers.php';

$cid  = require_active_company();
$user = $_SESSION['username'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $bt  = (string) ($_POST['booking'] ?? '');
        $sep = strpos($bt, ':');
        $booking_type = $sep === false ? '' : substr($bt, 0, $sep);
        $booking_id   = $sep === false ? 0 : (int) substr($bt, $sep + 1);
        $emp  = (int) ($_POST['employee_id'] ?? 0);
        // 0 = "tanpa kendaraan" di dropdown → kirim null, bukan 0, karena
        // vehicle_id adalah FK ke vehicles (0 melanggar fk_ta_vehicle).
        $veh  = (int) ($_POST['vehicle_id'] ?? 0);
        $note = trim((string) ($_POST['reason'] ?? ''));

        $res = dispatch_assign($cid, $booking_type, $booking_id, $emp, $veh > 0 ? $veh : null,
            $note === '' ? null : $note, (string) $user);
        audit('dispatch.assign', "Penugasan #{$res['id']} booking {$booking_type}#{$booking_id} driver #{$emp}");
        flash('success', 'Driver ' . $res['employee_name'] . ' ditugaskan.');
        redirect('pages/dispatch/index.php');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('pages/dispatch/assign.php');
}

$bookings = dispatch_assignable($cid, 50);
$drivers  = driver_list($cid);
$vehicles = opts_vehicles($cid);

$active = 'dispatch';
$title  = 'Tugaskan Driver';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Tugaskan Driver</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/dispatch/index.php">
                <i class="bi bi-arrow-left me-1"></i>Batal
            </a>
            <button class="btn btn-primary btn-sm px-3" type="submit" form="formDispatchAssign">
                <i class="bi bi-person-check me-1"></i>Tugaskan
            </button>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/dispatch/assign.php" id="formDispatchAssign" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label small mb-1">Booking (belum ada penugasan aktif, 50 terbaru per tipe)</label>
                    <select class="form-select form-select-sm" name="booking" required>
                        <option value="">— pilih booking —</option>
                        <?php foreach ($bookings as $b): ?>
                            <option value="<?= e($b['booking_type'] . ':' . $b['booking_id']) ?>"><?= e($b['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$bookings): ?><div class="form-text">Semua booking pada 50 data terbaru sudah punya penugasan aktif.</div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="dispatch-driver">Driver</label>
                    <select id="dispatch-driver" class="form-select form-select-sm ts-select" name="employee_id" required>
                        <option value="">— pilih driver —</option>
                        <?= select_options($drivers, '') ?>
                    </select>
                    <?php if (!$drivers): ?><div class="form-text">Belum ada karyawan dengan jabatan "Driver" di perusahaan ini.</div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="dispatch-vehicle">Kendaraan (opsional)</label>
                    <select id="dispatch-vehicle" class="form-select form-select-sm ts-select" name="vehicle_id">
                        <option value="">— tanpa kendaraan —</option>
                        <?= select_options($vehicles, '') ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="dispatch-reason">Alasan / catatan</label>
                    <input id="dispatch-reason" class="form-control form-control-sm" name="reason" maxlength="2000" placeholder="mis. shift pagi, driver pengganti">
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
