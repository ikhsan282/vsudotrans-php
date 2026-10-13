<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b   = booking_find('travel', $id);
if (!$b) {
    flash('error', 'Booking tidak ditemukan.');
    redirect('pages/travel/bookings/index.php');
}
assert_company_access((int) $b['company_id']);
$b['company_id'] = (int) $b['company_id'];

$user = $_SESSION['username'] ?? null;
$bid_type = 'travel';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string) ($_POST['act'] ?? '');
    $note = trim((string) ($_POST['reason'] ?? ''));
    try {
        if ($act === 'status') {
            $to = (string) ($_POST['to_status'] ?? '');
            booking_change_status('travel', $id, $cid, $to, $note === '' ? 'Ubah status' : $note, $user);
            audit('booking.travel.status', "Booking #{$id} -> {$to}");
            flash('success', 'Status booking diperbarui.');
        } elseif ($act === 'cancel') {
            booking_change_status('travel', $id, $cid, 'cancelled', $note === '' ? null : $note, $user);
            audit('booking.travel.cancel', "Booking #{$id}");
            flash('success', 'Booking dibatalkan.');
        } elseif ($act === 'reschedule') {
            $date = (string) ($_POST['date'] ?? '');
            $time = (string) ($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Tanggal/jam reschedule tidak valid.');
            }
            booking_reschedule('travel', $id, $cid, $date, $time . ':00', $note === '' ? 'Reschedule' : $note, $user);
            audit('booking.travel.reschedule', "Booking #{$id} -> {$date} {$time}");
            flash('success', 'Booking dijadwalkan ulang.');
        } elseif ($act === 'driver') {
            $emp = (int) ($_POST['employee_id'] ?? 0);
            $veh = (int) ($_POST['vehicle_id'] ?? 0);
            booking_assign_driver('travel', $id, $cid, $emp, $veh > 0 ? $veh : null, $user);
            audit('booking.travel.driver', "Booking #{$id} driver #{$emp}");
            flash('success', 'Driver ditugaskan.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() === '' ? 'Aksi gagal dijalankan.' : $ex->getMessage());
    }
    redirect('pages/travel/bookings/detail.php?id=' . $id);
}

$status = booking_current_status('travel', $id);
$next   = BOOKING_TRANSITIONS[$status] ?? [];

$st = db()->prepare('SELECT h.from_status, h.to_status, h.reason, h.changed_by, h.changed_at
    FROM booking_status_history h WHERE h.booking_type = ? AND h.booking_id = ? ORDER BY h.id DESC LIMIT 20');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = db()->prepare('SELECT cost_type, description, amount, premi_type, premi_value, premi_amount, note
    FROM booking_cost_lines WHERE booking_type = ? AND booking_id = ? ORDER BY id');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$costs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = db()->prepare('SELECT t.employee_id, t.vehicle_id, t.started_at, e.name AS employee
    FROM trip_assignments t LEFT JOIN employees e ON e.id = t.employee_id
    WHERE t.booking_type = ? AND t.booking_id = ? AND t.ended_at IS NULL LIMIT 1');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$assignment = $st->get_result()->fetch_assoc();
$st->close();

$st = db()->prepare('SELECT id, name FROM passengers WHERE id = ?');
$st->bind_param('i', $b['passenger_id']);
$st->execute();
$passenger = $st->get_result()->fetch_assoc() ?: ['name' => '-'];
$st->close();

$canManage = can('travel.manage');
$employees = $canManage ? opts_employees($cid) : [];
$vehicles  = $canManage ? opts_vehicles($cid) : [];

$active = 'travel';
$title  = 'Detail Booking Travel';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><?= e($b['document_number']) ?> <?= status_badge($status) ?></h4>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">Kembali</a>
    </div>
    <div class="card shadow-sm border-0 mb-3"><div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-3"><div class="text-muted">Tanggal</div><div class="fw-semibold"><?= e($b['date']) ?> <?= e(substr($b['time'], 0, 5)) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Penumpang</div><div class="fw-semibold"><?= e($passenger['name']) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Total Harga</div><div class="fw-semibold"><?= e(rupiah((float) $b['total_price'])) ?></div></div>
            <div class="col-md-3"><div class="text-muted">Driver Aktif</div><div class="fw-semibold"><?= e($assignment['employee'] ?? '-') ?></div></div>
            <div class="col-md-6"><div class="text-muted">Alamat Asal</div><div><?= e((string) $b['pickup_address']) ?></div></div>
            <div class="col-md-6"><div class="text-muted">Alamat Tujuan</div><div><?= e((string) $b['delivery_address']) ?></div></div>
            <div class="col-12"><div class="text-muted">Catatan</div><div><?= e((string) $b['note']) ?></div></div>
        </div>
    </div></div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Rincian Biaya</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Jenis</th><th>Keterangan</th><th class="text-end">Jumlah</th><th>Premi</th><th class="text-end">Premi</th></tr></thead>
                <tbody>
                <?php foreach ($costs as $c): ?>
                    <tr>
                        <td><?= e($c['cost_type']) ?></td>
                        <td><?= e((string) $c['description']) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $c['amount'])) ?></td>
                        <td><?= e($c['premi_type'] . ' ' . rupiah((float) $c['premi_value'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $c['premi_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$costs): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada rincian biaya.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canManage): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <h6 class="fw-bold">Ubah Status</h6>
            <?php if ($next): ?>
            <form method="post" action="" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="status">
                <select class="form-select form-select-sm" name="to_status">
                    <?php foreach ($next as $n): ?><option value="<?= e($n) ?>"><?= e($n) ?></option><?php endforeach; ?>
                </select>
                <input class="form-control form-control-sm" name="reason" placeholder="Alasan" maxlength="2000">
                <button class="btn btn-sm btn-primary" type="submit">Proses</button>
            </form>
            <?php else: ?><p class="text-muted mb-0 small">Status akhir — tidak ada transisi lanjutan.</p><?php endif; ?>
        </div></div></div>
        <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <h6 class="fw-bold">Reschedule</h6>
            <form method="post" action="" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="reschedule">
                <input type="date" class="form-control form-control-sm" name="date" required value="<?= e($b['date']) ?>">
                <input type="time" class="form-control form-control-sm" name="time" required value="<?= e(substr($b['time'], 0, 5)) ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Simpan</button>
            </form>
        </div></div></div>
        <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <h6 class="fw-bold">Tugaskan Driver</h6>
            <form method="post" action="" class="d-flex gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="driver">
                <select class="form-select form-select-sm" name="employee_id" required>
                    <option value="">-- Karyawan --</option><?= select_options($employees, $assignment['employee_id'] ?? '') ?>
                </select>
                <select class="form-select form-select-sm" name="vehicle_id">
                    <option value="">-- Kendaraan --</option><?= select_options($vehicles, $assignment['vehicle_id'] ?? '') ?>
                </select>
                <button class="btn btn-sm btn-outline-primary" type="submit">Tugaskan</button>
            </form>
        </div></div></div>
        <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-body">
            <h6 class="fw-bold">Batalkan Booking</h6>
            <?php if ($status === 'cancelled'): ?>
                <p class="text-muted mb-0 small">Booking sudah dibatalkan.</p>
            <?php else: ?>
            <form method="post" action="" class="d-flex gap-2" onsubmit="return confirm('Batalkan booking ini?')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="cancel">
                <input class="form-control form-control-sm" name="reason" placeholder="Alasan pembatalan" maxlength="2000">
                <button class="btn btn-sm btn-outline-danger" type="submit">Batalkan</button>
            </form>
            <?php endif; ?>
        </div></div></div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold">Riwayat Status</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Waktu</th><th>Dari</th><th>Ke</th><th>Alasan</th><th>Oleh</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e($h['changed_at']) ?></td>
                        <td><?= e((string) $h['from_status']) ?></td>
                        <td><?= status_badge($h['to_status']) ?></td>
                        <td><?= e((string) $h['reason']) ?></td>
                        <td><?= e((string) $h['changed_by']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
