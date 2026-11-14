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
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Dispatch',
        'person-check',
        'Tugaskan Driver',
        [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/pages/dispatch/index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Tugaskan', 'icon' => 'person-check', 'type' => 'submit', 'form' => 'formDispatchAssign', 'class' => 'btn-primary'],
        ]
    ) ?>

    <form method="post" action="<?= e(APP_URL) ?>/pages/dispatch/assign.php" id="formDispatchAssign">
        <?= csrf_field() ?>
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-disp-assign" data-bs-toggle="tab" data-bs-target="#pane-disp-assign" type="button" role="tab">Penugasan Driver</button>
                </li>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-disp-assign" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-journal-check"></i> Target Booking</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Booking <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm ts-select" name="booking" required
                                                data-ts-url="<?= e(APP_URL) ?>/pages/dispatch/booking_options.php"
                                                data-ts-company="<?= (int) $cid ?>"
                                                data-ts-limit="30">
                                                <option value="">— pilih booking —</option>
                                                <?php foreach ($bookings as $b): ?>
                                                    <option value="<?= e($b['booking_type'] . ':' . $b['booking_id']) ?>"><?= e($b['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text small">Booking tanpa penugasan aktif (50 terbaru per tipe).</div>
                                            <?php if (!$bookings): ?><div class="form-text small text-warning">Semua booking pada 50 data terbaru sudah punya penugasan aktif.</div><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Alasan / Catatan</div>
                                        <div class="rnt-field">
                                            <input id="dispatch-reason" class="form-control form-control-sm" name="reason" maxlength="2000" placeholder="mis. shift pagi, driver pengganti">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-truck"></i> Driver &amp; Armada</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Driver <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select id="dispatch-driver" class="form-select form-select-sm ts-select" name="employee_id" required
                                                data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php?job_position=Driver"
                                                data-ts-company="<?= (int) $cid ?>"
                                                data-ts-limit="30">
                                                <option value="">— pilih driver —</option>
                                                <?= select_options($drivers, '') ?>
                                            </select>
                                            <?php if (!$drivers): ?><div class="form-text small text-warning">Belum ada karyawan dengan jabatan "Driver" di perusahaan ini.</div><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kendaraan</div>
                                        <div class="rnt-field">
                                            <select id="dispatch-vehicle" class="form-select form-select-sm ts-select" name="vehicle_id"
                                                data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                                data-ts-company="<?= (int) $cid ?>"
                                                data-ts-limit="30">
                                                <option value="">— tanpa kendaraan —</option>
                                                <?= select_options($vehicles, '') ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
