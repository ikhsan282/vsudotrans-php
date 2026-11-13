<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/dispatch_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b   = booking_find('travel', $id);
if (!$b) {
    flash('error', 'Booking tidak ditemukan.');
    redirect('pages/travel/bookings/index.php');
}
assert_company_access((int) $b['company_id']);
$booking_cid      = (int) $b['company_id'];
$b['company_id']  = $booking_cid;

$user      = $_SESSION['username'] ?? null;
$bid_type  = 'travel';
$canManage = can('travel.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengelola pemesanan travel.');
        redirect('pages/travel/bookings/detail.php?id=' . $id);
    }
    $act       = (string) ($_POST['act'] ?? '');
    $note      = trim((string) ($_POST['reason'] ?? ''));
    $targetTab = trim((string) ($_POST['active_tab'] ?? ''));
    try {
        if ($act === 'status') {
            $to = (string) ($_POST['to_status'] ?? '');
            booking_change_status('travel', $id, $booking_cid, $to, $note === '' ? 'Ubah status' : $note, $user);
            audit('booking.travel.status', "Booking #{$id} -> {$to}");
            flash('success', 'Status booking diperbarui.');
        } elseif ($act === 'cancel') {
            booking_change_status('travel', $id, $booking_cid, 'cancelled', $note === '' ? null : $note, $user);
            audit('booking.travel.cancel', "Booking #{$id}");
            flash('success', 'Booking dibatalkan.');
        } elseif ($act === 'reschedule') {
            $date = (string) ($_POST['date'] ?? '');
            $time = (string) ($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Tanggal/jam reschedule tidak valid.');
            }
            booking_reschedule('travel', $id, $booking_cid, $date, $time . ':00', $note === '' ? 'Reschedule' : $note, $user);
            audit('booking.travel.reschedule', "Booking #{$id} -> {$date} {$time}");
            flash('success', 'Booking dijadwalkan ulang.');
        } elseif ($act === 'driver') {
            $emp = (int) ($_POST['employee_id'] ?? 0);
            $veh = (int) ($_POST['vehicle_id'] ?? 0);
            booking_assign_driver('travel', $id, $booking_cid, $emp, $veh > 0 ? $veh : null, $user);
            audit('booking.travel.driver', "Booking #{$id} driver #{$emp}");
            flash('success', 'Driver ditugaskan.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() === '' ? 'Aksi gagal dijalankan.' : $ex->getMessage());
    }
    $hash = in_array($targetTab, ['detail', 'ops', 'cost', 'note', 'audit'], true) ? ('#tab-' . $targetTab) : '';
    redirect('pages/travel/bookings/detail.php?id=' . $id . $hash);
}

$status = booking_current_status('travel', $id);
$next   = BOOKING_TRANSITIONS[$status] ?? [];

$db = db();

$st = $db->prepare('SELECT h.from_status, h.to_status, h.reason, h.changed_by, h.changed_at
    FROM booking_status_history h WHERE h.booking_type = ? AND h.booking_id = ? ORDER BY h.id DESC LIMIT 20');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = $db->prepare('SELECT cost_type, description, amount, premi_type, premi_value, premi_amount, note
    FROM booking_cost_lines WHERE booking_type = ? AND booking_id = ? ORDER BY id');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$costs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$st = $db->prepare('SELECT t.employee_id, t.vehicle_id, t.started_at, e.name AS employee
    FROM trip_assignments t LEFT JOIN employees e ON e.id = t.employee_id
    WHERE t.booking_type = ? AND t.booking_id = ? AND t.ended_at IS NULL LIMIT 1');
$st->bind_param('si', $bid_type, $id);
$st->execute();
$assignment = $st->get_result()->fetch_assoc();
$st->close();

// Daftar lengkap penumpang (primary + tambahan)
$paxList = booking_passengers_list('travel', $id, (int) $b['passenger_id']);
$primaryPax   = null;
$extraPaxList = [];
foreach ($paxList as $px) {
    if ($primaryPax === null && ((int) $px['is_primary'] === 1 || (int) $px['passenger_id'] === (int) $b['passenger_id'])) {
        $primaryPax = $px;
    } else {
        $extraPaxList[] = $px;
    }
}
if ($primaryPax === null) {
    $primaryPax = ['passenger_id' => (int) $b['passenger_id'], 'name' => '-', 'phone_number' => '', 'is_primary' => 1];
}

// Lookup metadata ringan O(1) via Primary Key
$stComp = $db->prepare("SELECT id, CONCAT(code, ' - ', name) AS label FROM companies WHERE id = ? LIMIT 1");
$stComp->bind_param('i', $booking_cid);
$stComp->execute();
$curCompany = $stComp->get_result()->fetch_assoc() ?: ['id' => $booking_cid, 'label' => active_company_name()];
$stComp->close();

$branchName = '-';
if (!empty($b['branch_id'])) {
    $stBr = $db->prepare('SELECT name FROM branches WHERE id = ? LIMIT 1');
    $brId = (int) $b['branch_id'];
    $stBr->bind_param('i', $brId);
    $stBr->execute();
    $branchName = (string) ($stBr->get_result()->fetch_column() ?: '-');
    $stBr->close();
}

$catName = '-';
if (!empty($b['category_vehicle_id'])) {
    $stCat = $db->prepare("SELECT CONCAT(code, ' - ', name) FROM category_vehicles WHERE id = ? LIMIT 1");
    $catId = (int) $b['category_vehicle_id'];
    $stCat->bind_param('i', $catId);
    $stCat->execute();
    $catName = (string) ($stCat->get_result()->fetch_column() ?: '-');
    $stCat->close();
}

$vehicleLabel = '-';
$displayVehId = (int) ($assignment['vehicle_id'] ?? $b['vehicle_id'] ?? 0);
if ($displayVehId > 0) {
    $stVeh = $db->prepare("SELECT CONCAT_WS(' - ', v.vehicle_number, NULLIF(CONCAT_WS(' ', bv.name, mu.name), ''), NULLIF(v.vehicle_color, ''))
        FROM vehicles v
        LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
        LEFT JOIN model_units mu ON mu.id = v.model_unit_id
        WHERE v.id = ? LIMIT 1");
    $stVeh->bind_param('i', $displayVehId);
    $stVeh->execute();
    $vehicleLabel = (string) ($stVeh->get_result()->fetch_column() ?: '-');
    $stVeh->close();
}

$geoLookup = static function (?int $districtId, ?int $cityId) use ($db): array {
    $out = ['province' => '-', 'city' => '-', 'district' => '-'];
    $dId = (int) ($districtId ?? 0);
    $cId = (int) ($cityId ?? 0);
    if ($dId > 0) {
        $stG = $db->prepare('SELECT d.name AS district, c.name AS city, p.name AS province
            FROM districts d
            LEFT JOIN cities c ON c.id = d.city_id
            LEFT JOIN provinces p ON p.id = COALESCE(d.province_id, c.province_id)
            WHERE d.id = ? LIMIT 1');
        $stG->bind_param('i', $dId);
        $stG->execute();
        $rowG = $stG->get_result()->fetch_assoc();
        $stG->close();
        if ($rowG) {
            $out['district'] = (string) ($rowG['district'] ?: '-');
            $out['city']     = (string) ($rowG['city'] ?: '-');
            $out['province'] = (string) ($rowG['province'] ?: '-');
            return $out;
        }
    }
    if ($cId > 0) {
        $stG = $db->prepare('SELECT c.name AS city, p.name AS province
            FROM cities c
            LEFT JOIN provinces p ON p.id = c.province_id
            WHERE c.id = ? LIMIT 1');
        $stG->bind_param('i', $cId);
        $stG->execute();
        $rowG = $stG->get_result()->fetch_assoc();
        $stG->close();
        if ($rowG) {
            $out['city']     = (string) ($rowG['city'] ?: '-');
            $out['province'] = (string) ($rowG['province'] ?: '-');
        }
    }
    return $out;
};

$pickupGeo   = $geoLookup((int) ($b['pickup_point_district_id'] ?? 0), (int) ($b['pickup_point_city_id'] ?? 0));
$deliveryGeo = $geoLookup((int) ($b['delivery_point_district_id'] ?? 0), (int) ($b['delivery_point_city_id'] ?? 0));

$invRow = null;
$stInv = $db->prepare("SELECT * FROM invoices WHERE booking_type = 'travel' AND booking_id = ? LIMIT 1");
$stInv->bind_param('i', $id);
$stInv->execute();
$invRow = $stInv->get_result()->fetch_assoc();
$stInv->close();
if ($invRow) {
    $invRow['paid_total'] = invoice_paid_total((int) $invRow['id']);
    $invRow['pay_status'] = invoice_pay_status($invRow);
}

$costSum  = 0.0;
$premiSum = 0.0;
foreach ($costs as $c) {
    $costSum  += (float) ($c['amount'] ?? 0);
    $premiSum += (float) ($c['premi_amount'] ?? 0);
}

// Riwayat penugasan driver (modul Penjadwalan) untuk booking ini.
$assignments = dispatch_by_booking('travel', $id);

$employees = $canManage ? opts_employees($booking_cid) : [];
$vehicles  = $canManage ? opts_vehicles($booking_cid, [$displayVehId]) : [];

$active = 'travel';
$title  = 'Detail Booking Travel - ' . $b['document_number'];
require __DIR__ . '/../../../includes/header.php';
?>
<style>
    .rnt-tabs {
        border-bottom: 1px solid #dee2e6;
        display: flex;
        flex-wrap: wrap;
    }
    .rnt-tabs .nav-link {
        color: #495057;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: 1px solid #dee2e6;
        margin-right: 4px;
        margin-bottom: -1px;
        font-size: .875rem;
        font-weight: 500;
        padding: .5rem 1rem;
        border-top-left-radius: .375rem;
        border-top-right-radius: .375rem;
    }
    .rnt-tabs .nav-link.active {
        color: #0d6efd;
        background: #fff;
        font-weight: 600;
        border-bottom: 2px solid #fff;
        z-index: 2;
    }
    .rnt-panel {
        border: 1px solid #dee2e6;
        border-radius: .375rem;
        background: #fff;
        margin-bottom: 1rem;
    }
    .rnt-panel-header {
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        padding: .5rem .875rem;
        font-size: .85rem;
        font-weight: 600;
        color: #343a40;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top-left-radius: .375rem;
        border-top-right-radius: .375rem;
    }
    .rnt-panel-body {
        padding: .875rem;
    }
    .rnt-row {
        display: flex;
        align-items: center;
        margin-bottom: .5rem;
    }
    .rnt-row:last-child {
        margin-bottom: 0;
    }
    .rnt-label {
        width: 165px;
        flex-shrink: 0;
        font-size: .84rem;
        color: #343a40;
        font-weight: 500;
        padding-right: .5rem;
    }
    .rnt-field {
        flex: 1 1 auto;
        min-width: 0;
    }
    .rnt-field .ts-control {
        min-height: calc(1.5em + 0.5rem + 2px);
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
        border-radius: 0.25rem;
    }
    @media (max-width: 767.98px) {
        .rnt-row { flex-direction: column; align-items: stretch; }
        .rnt-label { width: 100%; margin-bottom: .25rem; }
    }
</style>

<main class="p-2 p-md-3 w-100 flex-grow-1">
    <!-- Top Action Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-ticket-perforated-fill me-1"></i>Booking Travel</span>
            <?= status_badge($status) ?>
            <span class="fw-bold font-monospace"><?= e($b['document_number']) ?></span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($curCompany['label']) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
            <?php if ($canManage): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= e(APP_URL) ?>/pages/travel/bookings/form.php?id=<?= (int) $b['id'] ?>">
                    <i class="bi bi-pencil-square me-1"></i>Ubah
                </a>
            <?php endif; ?>
            <?php if (can('dispatch.manage')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(APP_URL) ?>/pages/dispatch/assign.php">
                    <i class="bi bi-person-badge me-1"></i>Tugaskan Driver
                </a>
            <?php endif; ?>
            <a class="btn btn-dark btn-sm" target="_blank" href="<?= e(APP_URL) ?>/tiket.php?no=<?= urlencode((string) $b['document_number']) ?>&print=1">
                <i class="bi bi-printer me-1"></i>Cetak E-Tiket
            </a>
        </div>
    </div>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="travelDetailTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-detail" type="button" role="tab">
                    Detail
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-ops-btn" data-bs-toggle="tab" data-bs-target="#tab-ops" type="button" role="tab">
                    Driver &amp; Tindakan<?php if ($assignments): ?> <span class="badge bg-secondary-subtle text-dark border ms-1"><?= count($assignments) ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-cost-btn" data-bs-toggle="tab" data-bs-target="#tab-cost" type="button" role="tab">
                    Biaya &amp; Tagihan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-note-btn" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">
                    Keterangan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-audit" type="button" role="tab">
                    Audit Trail
                </button>
            </li>
        </ul>

        <div class="erp-content-body">
            <div class="tab-content" id="travelDetailTabContent">
                <!-- ================= TAB 1: DETAIL ================= -->
                <div class="tab-pane fade show active" id="tab-detail" role="tabpanel">
                    <!-- Informasi Umum & Armada -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Informasi Pemesanan</span>
                            <span class="badge bg-light text-dark border font-monospace"><?= e($b['document_number']) ?></span>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <div class="rnt-row">
                                        <div class="rnt-label">No. Tiket / Resi</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm fw-bold bg-light font-monospace" value="<?= e($b['document_number']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Perusahaan</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light" value="<?= e($curCompany['label']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Cabang</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light" value="<?= e($branchName) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Tanggal &amp; Jam</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light fw-semibold" value="<?= e(date('d-M-Y', strtotime((string) $b['date']))) ?> pukul <?= e(substr((string) $b['time'], 0, 5)) ?> WIB" readonly>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kategori Armada</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light" value="<?= e($catName) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Unit Kendaraan</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light" value="<?= e($vehicleLabel) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Driver Aktif</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light fw-semibold" value="<?= e($assignment['employee'] ?? '-') ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Total Harga</div>
                                        <div class="rnt-field">
                                            <input type="text" class="form-control form-control-sm bg-light fw-bold text-primary text-end" value="<?= e(rupiah((float) $b['total_price'])) ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Penumpang (Utama + Tambahan) -->
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Daftar Penumpang</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($paxList) ?> Penumpang</span>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <div class="rnt-label">Penumpang Utama</div>
                                <div class="rnt-field">
                                    <div class="d-flex flex-wrap align-items-center gap-2 border rounded px-2 py-1 bg-light">
                                        <span class="fw-semibold text-dark"><?= e((string) $primaryPax['name']) ?></span>
                                        <?php if (!empty($primaryPax['phone_number'])): ?>
                                            <span class="text-muted small"><i class="bi bi-telephone me-1"></i><?= e((string) $primaryPax['phone_number']) ?></span>
                                        <?php endif; ?>
                                        <span class="badge bg-primary ms-auto">Utama</span>
                                    </div>
                                </div>
                            </div>
                            <div class="rnt-row align-items-start">
                                <div class="rnt-label pt-1">Penumpang Tambahan</div>
                                <div class="rnt-field">
                                    <?php if ($extraPaxList): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($extraPaxList as $ep): ?>
                                                <span class="badge bg-light text-dark border fw-normal py-2 px-2">
                                                    <i class="bi bi-person me-1 text-primary"></i>
                                                    <span class="fw-semibold"><?= e((string) $ep['name']) ?></span>
                                                    <?php if (!empty($ep['phone_number'])): ?>
                                                        <span class="text-muted ms-1">(<?= e((string) $ep['phone_number']) ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-control form-control-sm bg-light text-muted">Tidak ada penumpang tambahan.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Penjemputan & Pengantaran (2 Kolom) -->
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header">
                                    <span>Penjemputan (Asal)</span>
                                </div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($pickupGeo['province']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota / Kab</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($pickupGeo['city']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($pickupGeo['district']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Alamat Jemput</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm bg-light" rows="3" readonly><?= e((string) $b['pickup_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header">
                                    <span>Pengantaran (Tujuan)</span>
                                </div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Provinsi</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($deliveryGeo['province']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kota / Kab</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($deliveryGeo['city']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kecamatan</div>
                                        <div class="rnt-field"><input type="text" class="form-control form-control-sm bg-light" value="<?= e($deliveryGeo['district']) ?>" readonly></div>
                                    </div>
                                    <div class="rnt-row align-items-start">
                                        <div class="rnt-label pt-1">Alamat Antar</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm bg-light" rows="3" readonly><?= e((string) $b['delivery_address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 2: DRIVER & TINDAKAN ================= -->
                <div class="tab-pane fade" id="tab-ops" role="tabpanel">
                    <?php if ($canManage): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Ubah Status</span></div>
                                <div class="rnt-panel-body">
                                    <?php if ($next): ?>
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="status">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Status Tujuan</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" name="to_status">
                                                    <?php foreach ($next as $n): ?><option value="<?= e($n) ?>"><?= e($n) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Alasan</label>
                                            <div class="rnt-field">
                                                <input class="form-control form-control-sm" name="reason" placeholder="Catatan perubahan status..." maxlength="2000">
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Proses Status</button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                        <p class="text-muted mb-0 small">Status akhir — tidak ada transisi lanjutan.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Reschedule Jadwal</span></div>
                                <div class="rnt-panel-body">
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="reschedule">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tanggal Baru</label>
                                            <div class="rnt-field"><input type="date" class="form-control form-control-sm" name="date" required value="<?= e($b['date']) ?>"></div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Jam Baru</label>
                                            <div class="rnt-field"><input type="time" class="form-control form-control-sm" name="time" required value="<?= e(substr($b['time'], 0, 5)) ?>"></div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-calendar-check me-1"></i>Simpan Jadwal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Tugaskan Driver</span></div>
                                <div class="rnt-panel-body">
                                    <form method="post" action="" class="d-flex flex-column gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="driver">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Driver</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" name="employee_id" required>
                                                    <option value="">-- Karyawan --</option><?= select_options($employees, $assignment['employee_id'] ?? '') ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kendaraan</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" name="vehicle_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php"
                                                    data-ts-company="<?= (int) $booking_cid ?>"
                                                    data-ts-limit="30">
                                                    <option value="">-- Kendaraan --</option><?= select_options($vehicles, $assignment['vehicle_id'] ?? '') ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-person-check me-1"></i>Tugaskan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><span>Batalkan Booking</span></div>
                                <div class="rnt-panel-body">
                                    <?php if ($status === 'cancelled'): ?>
                                        <p class="text-muted mb-0 small">Booking sudah dibatalkan.</p>
                                    <?php else: ?>
                                    <form method="post" action="" class="d-flex flex-column gap-2" onsubmit="return confirm('Batalkan booking ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="act" value="cancel">
                                        <input type="hidden" name="active_tab" value="ops">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Alasan Batal</label>
                                            <div class="rnt-field">
                                                <input class="form-control form-control-sm" name="reason" placeholder="Alasan pembatalan..." maxlength="2000">
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Batalkan Booking</button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header">
                            <span>Riwayat Penugasan Driver</span>
                            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= e(APP_URL) ?>/pages/dispatch/detail.php?type=travel&amp;id=<?= $id ?>">Kelola di Penjadwalan</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Driver</th><th>Kendaraan</th><th>Mulai</th><th>Selesai</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($assignments as $a): ?>
                                    <tr>
                                        <td><?= e((string) $a['employee_name']) ?></td>
                                        <td><?= e((string) ($a['vehicle_number'] ?? '-')) ?></td>
                                        <td><?= e((string) $a['started_at']) ?></td>
                                        <td><?= e($a['ended_at'] ? (string) $a['ended_at'] : '—') ?></td>
                                        <td><span class="badge bg-<?= empty($a['ended_at']) ? 'warning text-dark' : 'secondary' ?>"><?= empty($a['ended_at']) ? 'sedang jalan' : 'selesai' ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$assignments): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada penugasan driver.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 3: BIAYA & TAGIHAN ================= -->
                <div class="tab-pane fade" id="tab-cost" role="tabpanel">
                    <?php if ($invRow):
                        $invTotal = (float) ($invRow['total'] ?? 0);
                        $invPaid  = (float) ($invRow['paid_total'] ?? 0);
                        $invRem   = max(0.0, $invTotal - $invPaid);
                        $invDocNo = (string) (!empty($invRow['invoice_no']) ? $invRow['invoice_no'] : ($invRow['document_number'] ?? ('#' . (int) $invRow['id'])));
                    ?>
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Tagihan / Invoice Terkait</span>
                            <?php if (can('finance.view')): ?>
                                <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $invRow['id'] ?>">Buka Invoice</a>
                            <?php endif; ?>
                        </div>
                        <div class="rnt-panel-body">
                            <div class="row g-3 small">
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">No. Invoice</div>
                                    <div class="fw-bold font-monospace"><?= e($invDocNo) ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">Status Invoice</div>
                                    <div><?= status_badge((string) $invRow['status']) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Total Tagihan</div>
                                    <div class="fw-semibold"><?= e(rupiah($invTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Terbayar</div>
                                    <div class="fw-semibold text-success"><?= e(rupiah($invPaid)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Sisa Tagihan</div>
                                    <div class="fw-bold text-danger"><?= e(rupiah($invRem)) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Rincian Biaya &amp; Premi</span></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Jenis</th><th>Keterangan</th><th class="text-end">Jumlah</th><th>Premi</th><th class="text-end">Nilai Premi</th></tr></thead>
                                <tbody>
                                <?php foreach ($costs as $c): ?>
                                    <tr>
                                        <td><?= e((string) $c['cost_type']) ?></td>
                                        <td><?= e((string) $c['description']) ?></td>
                                        <td class="text-end"><?= e(rupiah((float) $c['amount'])) ?></td>
                                        <td><?= e((string) $c['premi_type'] . ' ' . rupiah((float) $c['premi_value'])) ?></td>
                                        <td class="text-end"><?= e(rupiah((float) $c['premi_amount'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$costs): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada rincian biaya.</td></tr>
                                <?php endif; ?>
                                </tbody>
                                <?php if ($costs): ?>
                                <tfoot class="table-light fw-semibold">
                                    <tr>
                                        <td colspan="2" class="text-end">Total</td>
                                        <td class="text-end"><?= e(rupiah($costSum)) ?></td>
                                        <td></td>
                                        <td class="text-end"><?= e(rupiah($premiSum)) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 4: KETERANGAN ================= -->
                <div class="tab-pane fade" id="tab-note" role="tabpanel">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Keterangan / Catatan Perjalanan</span></div>
                        <div class="rnt-panel-body">
                            <textarea class="form-control form-control-sm bg-light" rows="8" readonly><?= e((string) ($b['note'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 5: AUDIT TRAIL ================= -->
                <div class="tab-pane fade" id="tab-audit" role="tabpanel">
                    <div class="rnt-panel">
                        <div class="rnt-panel-header"><span>Rekam Pembuatan &amp; Perubahan</span></div>
                        <div class="rnt-panel-body">
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($b['created_user'] ?? '')) ?: '-') ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Dibuat Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['created_date']) ? date('d-M-Y H:i', strtotime((string) $b['created_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Oleh</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($b['modified_user'] ?? '')) ?: '-') ?>" readonly>
                                </div>
                            </div>
                            <div class="rnt-row">
                                <label class="rnt-label">Diubah Tanggal</label>
                                <div class="rnt-field">
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($b['modified_date']) ? date('d-M-Y H:i', strtotime((string) $b['modified_date'])) : '-' ?>" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><span>Riwayat Status</span></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light"><tr><th>Waktu</th><th>Dari</th><th>Ke</th><th>Alasan</th><th>Oleh</th></tr></thead>
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
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var hash = window.location.hash;
    if (hash && /^#tab-(detail|ops|cost|note|audit)$/.test(hash)) {
        var btn = document.querySelector('#travelDetailTabs [data-bs-target="' + hash + '"]');
        if (btn && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(btn).show();
        }
    }
    document.querySelectorAll('#travelDetailTabs [data-bs-toggle="tab"]').forEach(function (tabEl) {
        tabEl.addEventListener('shown.bs.tab', function (ev) {
            var target = ev.target.getAttribute('data-bs-target');
            if (target && history.replaceState) {
                history.replaceState(null, '', target);
            }
        });
    });
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
