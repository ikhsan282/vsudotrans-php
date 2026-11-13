<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/dispatch_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/azure_blob.php';

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
        } elseif ($act === 'pay_manual') {
            $curBkStatus = booking_current_status('travel', $id);
            if ($curBkStatus === 'cancelled') {
                throw new RuntimeException('Booking sudah dibatalkan — tidak bisa menerima pembayaran.');
            }
            $payAmt    = (float) parse_rupiah_input($_POST['amount'] ?? 0);
            $methodId  = (int) ($_POST['payment_method_id'] ?? 0);
            $cashAccId = (int) ($_POST['cash_account_id'] ?? 0);
            $payType   = trim((string) ($_POST['pay_type'] ?? 'Pelunasan'));
            $payNoteIn = trim((string) ($_POST['pay_note'] ?? ''));
            if (!in_array($payType, ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'], true)) {
                $payType = 'Pelunasan';
            }

            $stInvId = db()->prepare("SELECT id FROM invoices WHERE booking_type = 'travel' AND booking_id = ? AND company_id = ? LIMIT 1");
            $stInvId->bind_param('ii', $id, $booking_cid);
            $stInvId->execute();
            $invId = (int) ($stInvId->get_result()->fetch_column() ?: 0);
            $stInvId->close();

            if ($invId <= 0) {
                $invId = invoice_issue(
                    $booking_cid,
                    'travel',
                    $id,
                    !empty($b['branch_id']) ? (int) $b['branch_id'] : null,
                    (string) $b['document_number'],
                    (float) $b['total_price'],
                    $user
                );
            }

            $methodName = 'Transfer';
            if ($methodId > 0) {
                $stPm = db()->prepare('SELECT name FROM payment_methods WHERE id = ? AND is_active = 1 AND (company_id = ? OR company_id IS NULL) LIMIT 1');
                $stPm->bind_param('ii', $methodId, $booking_cid);
                $stPm->execute();
                $pmRow = $stPm->get_result()->fetch_column();
                $stPm->close();
                if ($pmRow) {
                    $methodName = (string) $pmRow;
                } else {
                    $methodId = 0;
                }
            }

            $proofPath = null;
            if (!empty($_FILES['proof_file']['tmp_name'])) {
                $proofPath = azure_blob_handle_upload(
                    'proof_file',
                    $booking_cid,
                    'finance/payment-proofs',
                    date('Y/m'),
                    'travel-' . $id,
                    true,
                    5
                );
            }

            $fullNote = $payType . ' | ' . $methodName . ($payNoteIn !== '' ? (' - ' . $payNoteIn) : '');
            $payRes   = invoice_add_payment(
                $invId,
                $booking_cid,
                $payAmt,
                $methodId > 0 ? $methodId : null,
                $fullNote,
                $user,
                false,
                $proofPath,
                $cashAccId > 0 ? $cashAccId : null
            );
            audit('booking.travel.pay_manual', "Booking #{$id} INV#{$invId} nominal {$payAmt} ({$payRes['status']})");
            flash('success', 'Pembayaran manual ' . rupiah($payAmt) . ' berhasil dicatat.');
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

$st = $db->prepare("SELECT cost_type, description, amount, premi_type, premi_value, premi_amount, note
    FROM booking_cost_lines WHERE booking_type = ? AND booking_id = ? AND cost_type <> 'payment' ORDER BY id");
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

$payment_rows   = $invRow ? invoice_payments((int) $invRow['id']) : [];
$qrisChannels   = gateway_active_channels($booking_cid);
$qrisInvTotal   = $invRow ? (float) ($invRow['total'] ?? 0) : (float) ($b['total_price'] ?? 0);
$qrisPaidTotal  = $invRow ? (float) ($invRow['paid_total'] ?? 0) : (float) ($b['total_payment'] ?? 0);
$qrisRemaining  = max(0.0, round($qrisInvTotal - $qrisPaidTotal, 2));
$hasPendingQris = false;
if ($invRow) {
    $stQChk = $db->prepare("SELECT 1 FROM payment_gateway_orders WHERE invoice_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    $invIdInt = (int) $invRow['id'];
    $stQChk->bind_param('i', $invIdInt);
    $stQChk->execute();
    $hasPendingQris = (bool) $stQChk->get_result()->fetch_row();
    $stQChk->close();
}

$paymentMethods = [];
$cashAccounts   = [];
if ($canManage) {
    $stPm = $db->prepare("SELECT id, name, account_name, account_number FROM payment_methods WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL) AND COALESCE(code, '') <> 'QRIS-AUTO' ORDER BY sort_order, name");
    $stPm->bind_param('i', $booking_cid);
    $stPm->execute();
    $paymentMethods = $stPm->get_result()->fetch_all(MYSQLI_ASSOC);
    $stPm->close();

    $stCa = $db->prepare('SELECT id, name, account_number FROM cash_accounts WHERE is_active = 1 AND company_id = ? ORDER BY name');
    $stCa->bind_param('i', $booking_cid);
    $stCa->execute();
    $cashAccounts = $stCa->get_result()->fetch_all(MYSQLI_ASSOC);
    $stCa->close();
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
                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Tagihan / Invoice &amp; Status Pembayaran</span>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span id="qrisLiveSyncBadge" class="badge bg-info-subtle text-info-emphasis border <?= $hasPendingQris ? '' : 'd-none' ?>">
                                    <i class="bi bi-arrow-repeat me-1"></i>Pantau QRIS Aktif
                                </span>
                                <?php if ($canManage): ?>
                                    <?php if (!empty($qrisChannels)): ?>
                                        <button type="button" class="btn btn-sm btn-success py-0 px-2" id="btnPayQrisAuto" <?= ($status === 'cancelled' || $qrisRemaining <= 0) ? 'disabled' : '' ?>>
                                            <i class="bi bi-qr-code-scan me-1"></i>Bayar via QRIS Otomatis
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Channel QRIS aktif belum dikonfigurasi untuk perusahaan ini">
                                            <i class="bi bi-qr-code me-1"></i>QRIS Belum Aktif
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($invRow && can('finance.view')): ?>
                                    <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $invRow['id'] ?>">Buka Invoice</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rnt-panel-body">
                            <?php if ($invRow):
                                $invDocNo = (string) (!empty($invRow['invoice_no']) ? $invRow['invoice_no'] : ($invRow['document_number'] ?? ('#' . (int) $invRow['id'])));
                                $payStatusNow = (string) ($invRow['pay_status'] ?? 'unpaid');
                            ?>
                            <div class="row g-3 small">
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">No. Invoice</div>
                                    <div class="fw-bold font-monospace"><?= e($invDocNo) ?></div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="text-muted">Status Pembayaran</div>
                                    <div id="travelPayStatusBadgeWrap"><?= invoice_pay_badge($payStatusNow) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Total Tagihan</div>
                                    <div class="fw-semibold"><?= e(rupiah($qrisInvTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Terbayar</div>
                                    <div class="fw-semibold text-success" id="travelPaidTotalDisplay"><?= e(rupiah($qrisPaidTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-2">
                                    <div class="text-muted">Sisa Tagihan</div>
                                    <div class="fw-bold <?= $qrisRemaining > 0 ? 'text-danger' : 'text-success' ?>" id="qrisRemainingDisplay">
                                        <?= $qrisRemaining > 0 ? e(rupiah($qrisRemaining)) : 'Lunas (Rp 0)' ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="row g-3 small align-items-center">
                                <div class="col-6 col-md-4">
                                    <div class="text-muted">Tagihan Travel</div>
                                    <div class="fw-bold"><?= e(rupiah($qrisInvTotal)) ?></div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="text-muted">Terbayar</div>
                                    <div class="fw-semibold text-success" id="travelPaidTotalDisplay"><?= e(rupiah($qrisPaidTotal)) ?></div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="text-muted">Sisa Tagihan</div>
                                    <div class="fw-bold <?= $qrisRemaining > 0 ? 'text-danger' : 'text-success' ?>" id="qrisRemainingDisplay">
                                        <?= $qrisRemaining > 0 ? e(rupiah($qrisRemaining)) : 'Lunas (Rp 0)' ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="qrisAutoNewRowBanner" class="alert alert-success py-2 px-3 small d-none align-items-center justify-content-between mb-3" role="alert">
                        <div>
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <span id="qrisAutoNewRowMsg">Pembayaran QRIS baru telah masuk dan ditambahkan otomatis.</span>
                        </div>
                        <button type="button" class="btn-close btn-sm" aria-label="Tutup" onclick="document.getElementById('qrisAutoNewRowBanner').classList.add('d-none');document.getElementById('qrisAutoNewRowBanner').classList.remove('d-flex');"></button>
                    </div>

                    <div class="rnt-panel">
                        <div class="rnt-panel-header">
                            <span>Riwayat Pembayaran</span>
                            <span class="badge bg-light text-secondary border" id="travelPayCountBadge"><?= count($payment_rows) ?> transaksi</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:120px;">Tanggal</th>
                                        <th>Tipe Pembayaran</th>
                                        <th>Metode</th>
                                        <th>Bukti</th>
                                        <th class="text-end" style="width:150px;">Nominal</th>
                                    </tr>
                                </thead>
                                <tbody id="travelPayTableBody">
                                    <?php foreach ($payment_rows as $pr):
                                        $isAutoQr = !empty($pr['is_auto_qris']);
                                        $pDt = !empty($pr['pay_date']) ? date('d-M-Y', strtotime((string) $pr['pay_date'])) : '-';
                                        $blob = (string) ($pr['proof_blob_path'] ?? '');
                                    ?>
                                    <tr class="travel-pay-row" data-payment-id="<?= (int) ($pr['id'] ?? 0) ?>">
                                        <td class="small text-nowrap"><?= e($pDt) ?></td>
                                        <td><?= e((string) ($pr['pay_type'] ?? 'Pelunasan')) ?></td>
                                        <td>
                                            <?= e((string) ($pr['pay_method'] ?? 'Transfer')) ?>
                                            <?php if ($isAutoQr): ?>
                                                <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-qr-code me-1"></i>QRIS Auto</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAutoQr): ?>
                                                <span class="text-success small">Terverifikasi Otomatis</span>
                                            <?php elseif ($blob !== ''): ?>
                                                <a href="<?= e(resolve_asset_url($blob)) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">Lihat Bukti</a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold text-nowrap"><?= e(rupiah((float) ($pr['amount'] ?? 0))) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$payment_rows): ?>
                                    <tr id="travelPayEmptyRow"><td colspan="5" class="text-center text-muted py-3">Belum ada pembayaran tercatat.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($canManage && $status !== 'cancelled' && $qrisRemaining > 0): ?>
                    <div class="rnt-panel" id="travelManualPayPanel">
                        <div class="rnt-panel-header">
                            <span>Catat Pembayaran Manual</span>
                            <span class="small text-muted">Maksimal sebesar sisa tagihan: <strong class="text-danger" id="travelManualMaxText"><?= e(rupiah($qrisRemaining)) ?></strong></span>
                        </div>
                        <div class="rnt-panel-body">
                            <div id="travelPayOverpayAlert" class="alert alert-danger py-2 px-3 small d-none mb-2" role="alert">
                                <i class="bi bi-exclamation-octagon me-1"></i><span id="travelPayOverpayMsg"></span>
                            </div>
                            <form method="post" action="" enctype="multipart/form-data" id="travelManualPayForm" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="act" value="pay_manual">
                                <input type="hidden" name="active_tab" value="cost">
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Tipe Pembayaran</label>
                                    <select name="pay_type" id="travelManualPayType" class="form-select form-select-sm">
                                        <option value="Down Payment (DP)">Down Payment (DP)</option>
                                        <option value="Pelunasan" <?= $qrisPaidTotal > 0 ? 'selected' : '' ?>>Pelunasan</option>
                                        <option value="Pembayaran Penuh (Full)" <?= $qrisPaidTotal <= 0 ? 'selected' : '' ?>>Pembayaran Penuh (Full)</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Metode Pembayaran</label>
                                    <select name="payment_method_id" class="form-select form-select-sm">
                                        <option value="">-- Transfer / Tunai Manual --</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?= (int) $pm['id'] ?>"><?= e($pm['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Akun Kas / Bank</label>
                                    <select name="cash_account_id" class="form-select form-select-sm">
                                        <option value="">-- Tidak Dicatat ke Kas --</option>
                                        <?php foreach ($cashAccounts as $ca): ?>
                                            <option value="<?= (int) $ca['id'] ?>"><?= e($ca['name']) ?><?= !empty($ca['account_number']) ? ' (' . e($ca['account_number']) . ')' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold mb-1">Nominal Bayar (Rp) <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Rp</span>
                                        <input type="text" inputmode="numeric" class="form-control fw-bold text-end" id="travelPayAmountDisplay" value="<?= e(format_nominal($qrisRemaining)) ?>" autocomplete="off" required>
                                    </div>
                                    <input type="hidden" name="amount" id="travelPayAmountValue" value="<?= (int) round($qrisRemaining) ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Bukti Pembayaran (Opsional)</label>
                                    <input type="file" name="proof_file" class="form-control form-control-sm" accept="image/*,application/pdf">
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label small fw-semibold mb-1">Catatan</label>
                                    <input type="text" name="pay_note" class="form-control form-control-sm" placeholder="No. ref transfer / keterangan...">
                                </div>
                                <div class="col-12 col-md-3 d-flex justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary travel-pay-preset" data-preset="dp50">50%</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary travel-pay-preset" data-preset="full">Lunas</button>
                                    <button type="submit" class="btn btn-sm btn-primary px-3" id="btnSubmitTravelManualPay">
                                        <i class="bi bi-check2-circle me-1"></i>Simpan Bayar
                                    </button>
                                </div>
                            </form>
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

<?php require __DIR__ . '/../../../includes/qris_modal.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const appUrl        = <?= json_encode(APP_URL) ?>;
    const csrfTok       = <?= json_encode(csrf_token()) ?>;
    const qrisBookingId = <?= (int) $id ?>;
    const qrisBookStat  = <?= json_encode($status) ?>;
    let qrisInvTotal    = <?= json_encode((float) $qrisInvTotal) ?>;
    let qrisServerPaid  = <?= json_encode((float) $qrisPaidTotal) ?>;
    let qrisHasPending  = <?= json_encode($hasPendingQris) ?>;

    function fmtRp(num) {
        return window.formatRupiah ? window.formatRupiah(num) : String(Math.round(num || 0));
    }
    function parseRp(v) {
        return window.parseRupiah ? window.parseRupiah(v) : (parseInt(String(v || '0').replace(/[^0-9]/g, ''), 10) || 0);
    }
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

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
            if (target === '#tab-cost') {
                pollTravelQris('');
            }
            refreshBgQrisPoller();
        });
    });

    // Validasi Pembayaran Manual (anti-overpay di sisi client sebelum submit)
    const manualForm    = document.getElementById('travelManualPayForm');
    const manualDisp    = document.getElementById('travelPayAmountDisplay');
    const manualHidden  = document.getElementById('travelPayAmountValue');
    const manualAlert   = document.getElementById('travelPayOverpayAlert');
    const manualAlertMs = document.getElementById('travelPayOverpayMsg');
    const manualTypeSel = document.getElementById('travelManualPayType');
    const manualBtnSub  = document.getElementById('btnSubmitTravelManualPay');

    function validateManualPayAmount() {
        if (!manualDisp || !manualHidden) return true;
        const amt = parseRp(manualHidden.value || manualDisp.value);
        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (amt > rem && rem > 0) {
            if (manualAlert && manualAlertMs) {
                manualAlertMs.textContent = 'Nominal pembayaran (Rp ' + fmtRp(amt) + ') melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').';
                manualAlert.classList.remove('d-none');
            }
            if (manualBtnSub) manualBtnSub.disabled = true;
            return false;
        }
        if (manualAlert) manualAlert.classList.add('d-none');
        if (manualBtnSub) manualBtnSub.disabled = false;
        return amt > 0;
    }

    if (manualDisp && manualHidden && window.attachRupiahInput) {
        window.attachRupiahInput(manualDisp, manualHidden, validateManualPayAmount);
    }
    document.querySelectorAll('.travel-pay-preset').forEach((btn) => {
        btn.addEventListener('click', () => {
            const preset = btn.getAttribute('data-preset');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            const val = preset === 'dp50' ? Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.5))) : rem;
            if (manualDisp) manualDisp.value = fmtRp(val);
            if (manualHidden) manualHidden.value = String(val);
            if (manualTypeSel) {
                manualTypeSel.value = preset === 'dp50'
                    ? 'Down Payment (DP)'
                    : (qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)');
            }
            validateManualPayAmount();
        });
    });
    if (manualForm) {
        manualForm.addEventListener('submit', (e) => {
            const amt = parseRp(manualHidden ? manualHidden.value : '0');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (amt <= 0 || amt > rem) {
                e.preventDefault();
                if (manualAlert && manualAlertMs) {
                    manualAlertMs.textContent = amt <= 0
                        ? 'Masukkan nominal pembayaran lebih dari Rp 0.'
                        : ('Nominal pembayaran (Rp ' + fmtRp(amt) + ') tidak boleh melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').');
                    manualAlert.classList.remove('d-none');
                }
                if (manualDisp) manualDisp.focus();
                return false;
            }
        });
    }

    // QRIS Dinamis Otomatis (berbagi endpoint pages/rental/bookings/qris_ajax.php dengan booking_type=travel)
    const btnPayQrisAuto  = document.getElementById('btnPayQrisAuto');
    const qrisModalEl     = document.getElementById('qrisAutoModal');
    const qrisStepForm    = document.getElementById('qrisStepForm');
    const qrisStepShow    = document.getElementById('qrisStepShow');
    const qrisCreateErr   = document.getElementById('qrisCreateErr');
    const qrisFormSisa    = document.getElementById('qrisFormSisaText');
    const qrisPayTypeSel  = document.getElementById('qrisPayType');
    const qrisProvSel     = document.getElementById('qrisChannelProvider');
    const qrisAmtDisp     = document.getElementById('qrisAmountDisplay');
    const qrisAmtVal      = document.getElementById('qrisAmountValue');
    const btnGenQris      = document.getElementById('btnGenerateQris');
    const qrisRemDisp     = document.getElementById('qrisRemainingDisplay');
    const travelPaidDisp  = document.getElementById('travelPaidTotalDisplay');
    const qrisLiveBadge   = document.getElementById('qrisLiveSyncBadge');
    const qrisNewBanner   = document.getElementById('qrisAutoNewRowBanner');
    const qrisNewMsg      = document.getElementById('qrisAutoNewRowMsg');
    const payTbody        = document.getElementById('travelPayTableBody');
    const payCountBadge   = document.getElementById('travelPayCountBadge');

    let qrisCurrentOrderId   = '';
    let qrisCurrentPayWebUrl = '';
    let qrisModalPollTimer   = null;
    let qrisBgPollTimer      = null;
    let qrisCountdownTimer   = null;
    let qrisPollInFlight     = false;

    function stopQrisModalTimers() {
        if (qrisModalPollTimer) { clearInterval(qrisModalPollTimer); qrisModalPollTimer = null; }
        if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
    }
    function startQrisCountdown(expiredTs) {
        if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
        const cdEl = document.getElementById('qrisShowCountdown');
        if (!cdEl) return;
        if (!expiredTs) { cdEl.textContent = '±15 menit'; return; }
        const tick = () => {
            const left = Math.max(0, expiredTs - Math.floor(Date.now() / 1000));
            if (left <= 0) {
                cdEl.textContent = 'Kedaluwarsa';
                clearInterval(qrisCountdownTimer);
                qrisCountdownTimer = null;
                return;
            }
            const m = Math.floor(left / 60);
            const s = left % 60;
            cdEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        };
        tick();
        qrisCountdownTimer = setInterval(tick, 1000);
    }

    function appendAutoQrisRow(p) {
        if (!payTbody || !p || !p.id) return false;
        if (payTbody.querySelector('tr.travel-pay-row[data-payment-id="' + String(p.id) + '"]')) return false;
        const emptyRow = document.getElementById('travelPayEmptyRow');
        if (emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.className = 'travel-pay-row table-success';
        tr.setAttribute('data-payment-id', String(p.id));
        tr.innerHTML = `
            <td class="small text-nowrap">${escHtml(p.pay_date_fmt || p.pay_date || '-')}</td>
            <td>${escHtml(p.pay_type || 'Pelunasan')}</td>
            <td>${escHtml(p.pay_method || 'QRIS')} <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-qr-code me-1"></i>QRIS Auto</span></td>
            <td><span class="text-success small">Terverifikasi Otomatis</span></td>
            <td class="text-end fw-semibold text-nowrap">Rp ${escHtml(p.amount_fmt || fmtRp(p.amount || 0))}</td>
        `;
        payTbody.appendChild(tr);
        setTimeout(() => tr.classList.remove('table-success'), 4000);
        if (payCountBadge) {
            const cnt = payTbody.querySelectorAll('tr.travel-pay-row').length;
            payCountBadge.textContent = cnt + ' transaksi';
        }
        return true;
    }

    function updateSummaryUi(data) {
        if (typeof data.invoice_total === 'number') qrisInvTotal = data.invoice_total;
        if (typeof data.paid_total === 'number') qrisServerPaid = data.paid_total;
        if (typeof data.has_pending === 'boolean') qrisHasPending = data.has_pending;

        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (travelPaidDisp) travelPaidDisp.textContent = 'Rp ' + fmtRp(qrisServerPaid);
        if (qrisRemDisp) {
            qrisRemDisp.textContent = rem > 0 ? ('Rp ' + fmtRp(rem)) : 'Lunas (Rp 0)';
            qrisRemDisp.className = 'fw-bold ' + (rem > 0 ? 'text-danger' : 'text-success');
        }
        if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + fmtRp(rem);
        const manualMax = document.getElementById('travelManualMaxText');
        if (manualMax) manualMax.textContent = 'Rp ' + fmtRp(rem);
        if (rem <= 0) {
            const manualPanel = document.getElementById('travelManualPayPanel');
            if (manualPanel) manualPanel.classList.add('d-none');
            const badgeWrap = document.getElementById('travelPayStatusBadgeWrap');
            if (badgeWrap) badgeWrap.innerHTML = '<span class="badge bg-success">Lunas</span>';
        }
        if (btnPayQrisAuto) {
            const curSt = data.booking_status || qrisBookStat;
            btnPayQrisAuto.disabled = (curSt === 'cancelled' || rem <= 0);
        }
    }

    async function pollTravelQris(targetOrderId = '') {
        if (qrisPollInFlight) return null;
        qrisPollInFlight = true;
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrfTok);
            fd.append('act', 'poll');
            fd.append('booking_type', 'travel');
            fd.append('booking_id', String(qrisBookingId));
            if (targetOrderId) fd.append('order_id', targetOrderId);

            const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) return data;

            let addedCount = 0;
            let addedSum = 0;
            if (Array.isArray(data.payments)) {
                data.payments.forEach((p) => {
                    if (p && Number(p.id) > 0 && appendAutoQrisRow(p)) {
                        addedCount++;
                        addedSum += Number(p.amount || 0);
                    }
                });
            }
            if (addedCount > 0 && qrisNewBanner && qrisNewMsg) {
                qrisNewMsg.textContent = `Pembayaran QRIS baru sebesar Rp ${fmtRp(addedSum)} telah masuk dan tercatat otomatis.`;
                qrisNewBanner.classList.remove('d-none');
                qrisNewBanner.classList.add('d-flex');
            }
            updateSummaryUi(data);

            if (targetOrderId && qrisCurrentOrderId === targetOrderId) {
                const stBadge = document.getElementById('qrisShowStatusBadge');
                if (data.order_status === 'PAID') {
                    stopQrisModalTimers();
                    if (stBadge) {
                        stBadge.className = 'badge bg-success text-white';
                        stBadge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Lunas / Terverifikasi';
                    }
                    setTimeout(() => {
                        if (qrisModalEl && typeof bootstrap !== 'undefined') {
                            const inst = bootstrap.Modal.getInstance(qrisModalEl);
                            if (inst) inst.hide();
                        }
                    }, 1200);
                } else if (data.order_status === 'EXPIRED') {
                    stopQrisModalTimers();
                    if (stBadge) {
                        stBadge.className = 'badge bg-danger text-white';
                        stBadge.innerHTML = '<i class="bi bi-x-circle me-1"></i>Kedaluwarsa';
                    }
                }
            }
            return data;
        } catch (err) {
            return null;
        } finally {
            qrisPollInFlight = false;
        }
    }

    function refreshBgQrisPoller() {
        const costPane  = document.getElementById('tab-cost');
        const tabActive = costPane && costPane.classList.contains('active');
        const shouldPoll = !document.hidden && (qrisHasPending || tabActive);

        if (shouldPoll && !qrisBgPollTimer) {
            qrisBgPollTimer = setInterval(() => {
                if (!document.hidden) pollTravelQris('');
            }, 6000);
        } else if (!shouldPoll && qrisBgPollTimer) {
            clearInterval(qrisBgPollTimer);
            qrisBgPollTimer = null;
        }
        if (qrisLiveBadge) {
            if (qrisHasPending || tabActive) qrisLiveBadge.classList.remove('d-none');
            else qrisLiveBadge.classList.add('d-none');
        }
    }
    document.addEventListener('visibilitychange', refreshBgQrisPoller);

    if (qrisAmtDisp && qrisAmtVal && window.attachRupiahInput) {
        window.attachRupiahInput(qrisAmtDisp, qrisAmtVal);
    }
    document.querySelectorAll('.qris-preset-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const preset = btn.getAttribute('data-qris-preset');
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            let val = rem;
            if (preset === 'dp50') {
                val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.5)));
                if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
            } else if (preset === 'dp30') {
                val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.3)));
                if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
            } else if (qrisPayTypeSel) {
                qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
            }
            if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(val);
            if (qrisAmtVal) qrisAmtVal.value = String(val);
        });
    });

    if (btnPayQrisAuto && qrisModalEl) {
        btnPayQrisAuto.addEventListener('click', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId   = '';
            qrisCurrentPayWebUrl = '';
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
            const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + fmtRp(rem);
            if (qrisPayTypeSel) qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
            if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(rem);
            if (qrisAmtVal) qrisAmtVal.value = String(rem);
            if (qrisStepForm) qrisStepForm.classList.remove('d-none');
            if (qrisStepShow) qrisStepShow.classList.add('d-none');
            if (typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(qrisModalEl).show();
        });
        qrisModalEl.addEventListener('hidden.bs.modal', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            refreshBgQrisPoller();
        });
    }

    if (btnGenQris) {
        btnGenQris.addEventListener('click', async () => {
            const amt  = parseRp(qrisAmtVal ? qrisAmtVal.value : (qrisAmtDisp ? qrisAmtDisp.value : '0'));
            const pt   = qrisPayTypeSel ? qrisPayTypeSel.value : 'Pelunasan';
            const prov = qrisProvSel ? qrisProvSel.value : '';
            const rem  = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
            if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
            if (rem <= 0 || amt <= 0 || amt > rem) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = rem <= 0
                        ? 'Tagihan invoice ini sudah lunas.'
                        : (amt <= 0 ? 'Masukkan nominal QRIS lebih dari Rp 0.' : 'Nominal QRIS (Rp ' + fmtRp(amt) + ') melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').');
                    qrisCreateErr.classList.remove('d-none');
                }
                return;
            }
            btnGenQris.disabled = true;
            const origHtml = btnGenQris.innerHTML;
            btnGenQris.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membuat QRIS...';
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfTok);
                fd.append('act', 'create');
                fd.append('booking_type', 'travel');
                fd.append('booking_id', String(qrisBookingId));
                fd.append('pay_type', pt);
                fd.append('amount', String(amt));
                if (prov) fd.append('provider', prov);

                const res = await fetch(`${appUrl}/pages/rental/bookings/qris_ajax.php`, { method: 'POST', body: fd });
                const data = await res.json();
                if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || 'Gagal membuat transaksi QRIS.');

                qrisCurrentOrderId   = data.order_id || '';
                qrisCurrentPayWebUrl = data.pay_web_url || '';
                qrisHasPending       = true;

                const imgEl = document.getElementById('qrisShowImg');
                if (imgEl) {
                    const primarySrc  = data.qris_image_url || data.fallback_qr_url || '';
                    const fallbackSrc = data.fallback_qr_url || '';
                    imgEl.onerror = function () { if (fallbackSrc && this.src !== fallbackSrc) this.src = fallbackSrc; };
                    imgEl.src = primarySrc;
                }
                const setTxt = (id, t) => { const e = document.getElementById(id); if (e) e.textContent = t; };
                setTxt('qrisShowChannelBadge', data.channel_name || 'QRIS');
                setTxt('qrisShowOrderId', qrisCurrentOrderId);
                setTxt('qrisShowPayType', data.pay_type || pt);
                setTxt('qrisShowBill', 'Rp ' + fmtRp(Math.round(Number(data.bill_amount || amt))));

                const feeRow = document.getElementById('qrisShowFeeRow');
                const feeNum = Math.round(Number(data.fee || 0));
                if (feeRow) {
                    feeRow.classList.toggle('d-none', feeNum <= 0);
                    setTxt('qrisShowFee', 'Rp ' + fmtRp(feeNum));
                }
                const uniqRow = document.getElementById('qrisShowUniqueRow');
                const uniqNum = Math.round(Number(data.unique_code || 0));
                if (uniqRow) {
                    uniqRow.classList.toggle('d-none', uniqNum <= 0);
                    setTxt('qrisShowUnique', '+Rp ' + fmtRp(uniqNum));
                }
                setTxt('qrisShowTotal', 'Rp ' + fmtRp(Math.round(Number(data.total_amount || amt))));

                const stBadge = document.getElementById('qrisShowStatusBadge');
                if (stBadge) {
                    stBadge.className = 'badge bg-warning-subtle text-warning-emphasis border';
                    stBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;" role="status"></span>Menunggu Pembayaran';
                }
                if (qrisStepForm) qrisStepForm.classList.add('d-none');
                if (qrisStepShow) qrisStepShow.classList.remove('d-none');
                startQrisCountdown(data.expired_ts || null);
                qrisModalPollTimer = setInterval(() => { if (qrisCurrentOrderId) pollTravelQris(qrisCurrentOrderId); }, 4000);
                refreshBgQrisPoller();
            } catch (err) {
                if (qrisCreateErr) {
                    qrisCreateErr.textContent = err.message || 'Terjadi kesalahan saat membuat QRIS.';
                    qrisCreateErr.classList.remove('d-none');
                }
            } finally {
                btnGenQris.disabled = false;
                btnGenQris.innerHTML = origHtml;
            }
        });
    }

    const btnQrisBack = document.getElementById('btnQrisBackToForm');
    if (btnQrisBack) {
        btnQrisBack.addEventListener('click', () => {
            stopQrisModalTimers();
            qrisCurrentOrderId = '';
            if (qrisStepShow) qrisStepShow.classList.add('d-none');
            if (qrisStepForm) qrisStepForm.classList.remove('d-none');
        });
    }
    const btnCheckQrisNow = document.getElementById('btnCheckQrisNow');
    if (btnCheckQrisNow) {
        btnCheckQrisNow.addEventListener('click', async () => {
            if (!qrisCurrentOrderId) return;
            btnCheckQrisNow.disabled = true;
            await pollTravelQris(qrisCurrentOrderId);
            btnCheckQrisNow.disabled = false;
        });
    }
    const btnCopyQrisLink = document.getElementById('btnCopyQrisLink');
    if (btnCopyQrisLink) {
        btnCopyQrisLink.addEventListener('click', async () => {
            if (!qrisCurrentPayWebUrl) return;
            const orig = btnCopyQrisLink.innerHTML;
            try {
                await navigator.clipboard.writeText(qrisCurrentPayWebUrl);
                btnCopyQrisLink.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin';
                setTimeout(() => { btnCopyQrisLink.innerHTML = orig; }, 1800);
            } catch (e) {
                window.prompt('Salin tautan pembayaran QRIS berikut:', qrisCurrentPayWebUrl);
            }
        });
    }
    if (qrisHasPending) refreshBgQrisPoller();
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
