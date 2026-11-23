<?php
/**
 * Profil & Riwayat Pelanggan (CRM Mini) + CTA Pesan Ulang 1-Klik.
 * Akses baca mengikuti izin transportasi (travel.view / rental.view).
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
transport_require_view();
category_service_ensure_schema();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Pelanggan tidak ditemukan.');
    redirect('pages/transport/passengers/index.php');
}

$db = db();
$stPax = $db->prepare(
    "SELECT p.*, c.code AS comp_code, c.name AS comp_name
       FROM passengers p
       JOIN companies c ON c.id = p.company_id
      WHERE p.id = ?
      LIMIT 1"
);
$stPax->bind_param('i', $id);
$stPax->execute();
$pax = $stPax->get_result()->fetch_assoc();
$stPax->close();

if (!$pax) {
    flash('error', 'Data penumpang tidak ditemukan.');
    redirect('pages/transport/passengers/index.php');
}
assert_company_access((int) $pax['company_id']);

$paxCid          = (int) $pax['company_id'];
$canManage       = transport_can_manage();
$activeCid       = active_company_id();
$canBookHere     = ($activeCid !== null && $activeCid === $paxCid);
$canTravelView   = can('travel.view') || can('travel.manage');
$canRentalView   = can('rental.view') || can('rental.manage');
$canTravelManage = can('travel.manage') && $canBookHere;
$canRentalManage = can('rental.manage') && $canBookHere;

$typeFilter = strtolower(trim((string) ($_GET['type'] ?? 'all')));
if (!in_array($typeFilter, ['all', 'travel', 'rental'], true)) {
    $typeFilter = 'all';
}

$allBookings = [];
if ($canTravelView) {
    $stT = $db->prepare(
        "SELECT 'travel' AS booking_type,
                b.id, b.company_id, b.document_number, b.date,
                b.date AS start_date, b.date AS end_date, b.time,
                b.total_price, b.total_payment, b.pickup_address, b.delivery_address,
                (b.passenger_id = ?) AS is_primary,
                COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name,
                COALESCE(v.vehicle_number, '') AS vehicle_number,
                COALESCE(pc.name, '') AS pickup_city_name, COALESCE(pd.name, '') AS pickup_district_name,
                COALESCE(dc.name, '') AS delivery_city_name, COALESCE(dd.name, '') AS delivery_district_name,
                COALESCE((SELECT h.to_status FROM booking_status_history h
                           WHERE h.booking_type = 'travel' AND h.booking_id = b.id
                           ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
                COALESCE((SELECT i.status FROM invoices i
                           WHERE i.booking_type = 'travel' AND i.booking_id = b.id
                           ORDER BY i.id DESC LIMIT 1), 'issued') AS invoice_status
           FROM travel_ticket_bookings b
           LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
           LEFT JOIN vehicles v ON v.id = b.vehicle_id
           LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
           LEFT JOIN districts pd ON pd.id = b.pickup_point_district_id
           LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
           LEFT JOIN districts dd ON dd.id = b.delivery_point_district_id
          WHERE b.company_id = ?
            AND (b.passenger_id = ?
                 OR EXISTS (SELECT 1 FROM booking_passengers bp
                             WHERE bp.booking_type = 'travel' AND bp.booking_id = b.id AND bp.passenger_id = ?))
          ORDER BY b.date DESC, b.id DESC
          LIMIT 200"
    );
    $stT->bind_param('iiii', $id, $paxCid, $id, $id);
    $stT->execute();
    $allBookings = array_merge($allBookings, $stT->get_result()->fetch_all(MYSQLI_ASSOC));
    $stT->close();
}

if ($canRentalView) {
    $stR = $db->prepare(
        "SELECT 'rental' AS booking_type,
                b.id, b.company_id, b.document_number, b.date,
                COALESCE(NULLIF(b.start_date, ''), b.date) AS start_date,
                COALESCE(NULLIF(b.end_date, ''), b.date) AS end_date,
                b.time,
                b.total_price, b.total_payment, b.pickup_address, b.delivery_address,
                (b.passenger_id = ?) AS is_primary,
                COALESCE(cv.code, '') AS category_code, COALESCE(cv.name, '') AS category_name,
                COALESCE(v.vehicle_number, '') AS vehicle_number,
                COALESCE(pc.name, '') AS pickup_city_name, COALESCE(pd.name, '') AS pickup_district_name,
                COALESCE(dc.name, '') AS delivery_city_name, COALESCE(dd.name, '') AS delivery_district_name,
                COALESCE((SELECT h.to_status FROM booking_status_history h
                           WHERE h.booking_type = 'rental' AND h.booking_id = b.id
                           ORDER BY h.id DESC LIMIT 1), 'draft') AS status,
                COALESCE((SELECT i.status FROM invoices i
                           WHERE i.booking_type = 'rental' AND i.booking_id = b.id
                           ORDER BY i.id DESC LIMIT 1), 'issued') AS invoice_status
           FROM rental_car_bookings b
           LEFT JOIN category_vehicles cv ON cv.id = b.category_vehicle_id
           LEFT JOIN vehicles v ON v.id = b.vehicle_id
           LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
           LEFT JOIN districts pd ON pd.id = b.pickup_point_district_id
           LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
           LEFT JOIN districts dd ON dd.id = b.delivery_point_district_id
          WHERE b.company_id = ?
            AND (b.passenger_id = ?
                 OR EXISTS (SELECT 1 FROM booking_passengers bp
                             WHERE bp.booking_type = 'rental' AND bp.booking_id = b.id AND bp.passenger_id = ?))
          ORDER BY start_date DESC, b.id DESC
          LIMIT 200"
    );
    $stR->bind_param('iiii', $id, $paxCid, $id, $id);
    $stR->execute();
    $allBookings = array_merge($allBookings, $stR->get_result()->fetch_all(MYSQLI_ASSOC));
    $stR->close();
}

usort($allBookings, static function (array $a, array $b): int {
    $da = (string) ($a['start_date'] ?: $a['date']);
    $db = (string) ($b['start_date'] ?: $b['date']);
    return strcmp($db, $da) ?: ((int) $b['id'] <=> (int) $a['id']);
});

$totalTrips      = 0;
$travelTrips     = 0;
$rentalTrips     = 0;
$cancelledTrips  = 0;
$companionTrips  = 0;
$totalOmzet      = 0.0;
$totalPaid       = 0.0;
$totalReceivable = 0.0;
$routeFreq       = [];

foreach ($allBookings as $bk) {
    $stCode = (string) ($bk['status'] ?: 'draft');
    if ($stCode === 'cancelled') {
        $cancelledTrips++;
        continue;
    }
    $totalTrips++;
    if ($bk['booking_type'] === 'travel') {
        $travelTrips++;
    } else {
        $rentalTrips++;
    }
    if ((int) ($bk['is_primary'] ?? 0) === 1) {
        $price = (float) ($bk['total_price'] ?? 0);
        $paid  = (float) ($bk['total_payment'] ?? 0);
        $totalOmzet      += $price;
        $totalPaid       += $paid;
        $totalReceivable += max(0.0, $price - $paid);
    } else {
        $companionTrips++;
    }
    $pkLbl = trim((string) ($bk['pickup_city_name'] ?: $bk['pickup_district_name']));
    $dlLbl = trim((string) ($bk['delivery_city_name'] ?: $bk['delivery_district_name']));
    if ($pkLbl !== '' || $dlLbl !== '') {
        $rtKey = ($pkLbl ?: '-') . ' → ' . ($dlLbl ?: '-');
        $routeFreq[$rtKey] = ($routeFreq[$rtKey] ?? 0) + 1;
    }
}
arsort($routeFreq);
$favRoute     = $routeFreq ? (string) array_key_first($routeFreq) : '';
$favRouteCnt  = $favRoute !== '' ? (int) ($routeFreq[$favRoute] ?? 0) : 0;
$lastLoc      = passenger_last_booking_locations($paxCid, $id, 'travel');

$filteredBookings = $typeFilter === 'all'
    ? $allBookings
    : array_values(array_filter($allBookings, static fn(array $r): bool => $r['booking_type'] === $typeFilter));

$phoneRaw  = trim((string) ($pax['phone_number'] ?? ''));
$phoneWa   = normalize_phone_62($phoneRaw);
$nameParts = preg_split('/\s+/', trim((string) $pax['name'])) ?: ['P'];
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
if ($initials === '') {
    $initials = 'P';
}

$fmtDateTime = static function (?string $raw): string {
    $s = trim((string) $raw);
    if ($s === '') {
        return '-';
    }
    $ts = strtotime($s);
    return $ts !== false ? date('d-M-Y H:i', $ts) : $s;
};

$actions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/transport/passengers/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($canManage) {
    $actions[] = ['label' => 'Ubah Data', 'icon' => 'pencil-square', 'href' => 'pages/transport/passengers/form.php?id=' . $id, 'class' => 'btn btn-outline-primary btn-sm'];
}
if ($canTravelManage) {
    $actions[] = ['label' => 'Pesan Travel Lagi', 'icon' => 'bus-front', 'href' => 'pages/travel/bookings/form.php?passenger_id=' . $id . '&prefill_last=1', 'class' => 'btn btn-primary btn-sm'];
}
if ($canRentalManage) {
    $actions[] = ['label' => 'Pesan Sewa Lagi', 'icon' => 'car-front', 'href' => 'pages/rental/bookings/form.php?passenger_id=' . $id . '&prefill_last=1', 'class' => 'btn btn-success btn-sm'];
}

$active = 'passengers';
$title  = 'Profil Pelanggan - ' . (string) $pax['name'];
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        (string) $pax['name'],
        ['Dashboard' => 'pages/dashboard/index.php', 'Penumpang' => 'pages/transport/passengers/index.php', (string) $pax['name'] => null],
        [
            ['text' => (string) $pax['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => trim((string) $pax['comp_code'] . ' - ' . (string) $pax['comp_name']), 'class' => 'bg-light text-secondary border'],
        ],
        $actions
    ) ?>

    <?php if ((can('travel.manage') || can('rental.manage')) && !$canBookHere): ?>
    <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-info-circle-fill"></i>
        <span>Untuk membuat pemesanan ulang 1-klik bagi pelanggan ini, pilih perusahaan aktif <strong><?= e($pax['comp_code'] . ' - ' . $pax['comp_name']) ?></strong> pada menu topbar.</span>
    </div>
    <?php endif; ?>

    <!-- KPI Ringkasan Pelanggan -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total Perjalanan</div>
                        <div class="fs-5 fw-bold text-primary"><?= number_format($totalTrips, 0, ',', '.') ?></div>
                        <div class="text-muted" style="font-size:.75rem;">
                            <?= $travelTrips ?> Travel · <?= $rentalTrips ?> Sewa<?= $cancelledTrips > 0 ? ' · ' . $cancelledTrips . ' Batal' : '' ?>
                        </div>
                    </div>
                    <i class="bi bi-compass fs-3 text-primary opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total Omzet</div>
                        <div class="fs-5 fw-bold text-success"><?= e(rupiah($totalOmzet)) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">Pelanggan utama<?= $companionTrips > 0 ? ' (+' . $companionTrips . ' ikut serta)' : '' ?></div>
                    </div>
                    <i class="bi bi-cash-stack fs-3 text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total Dibayar</div>
                        <div class="fs-5 fw-bold text-info"><?= e(rupiah($totalPaid)) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">Akumulasi pembayaran masuk</div>
                    </div>
                    <i class="bi bi-check2-circle fs-3 text-info opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Sisa Piutang</div>
                        <div class="fs-5 fw-bold <?= $totalReceivable > 0 ? 'text-danger' : 'text-secondary' ?>"><?= e(rupiah($totalReceivable)) ?></div>
                        <div class="text-muted" style="font-size:.75rem;"><?= $totalReceivable > 0 ? 'Belum lunas' : 'Seluruh tagihan lunas' ?></div>
                    </div>
                    <i class="bi bi-hourglass-split fs-3 <?= $totalReceivable > 0 ? 'text-danger' : 'text-secondary' ?> opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Profil & Lokasi Terakhir -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-person-vcard me-1 text-primary"></i>Informasi Pelanggan</span>
                    <?php if ($favRoute !== ''): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-wrap text-start">
                            <i class="bi bi-signpost-split me-1"></i>Rute Favorit: <?= e($favRoute) ?> (<?= $favRouteCnt ?>x)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="pax-crm-avatar flex-shrink-0"><?= e($initials) ?></div>
                        <div class="flex-grow-1 min-w-0">
                            <h5 class="fw-bold mb-1"><?= e((string) $pax['name']) ?></h5>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="badge bg-secondary-subtle text-secondary border font-monospace"><?= e((string) $pax['code']) ?></span>
                                <?php if ($phoneWa !== null): ?>
                                    <a class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none"
                                       href="https://wa.me/<?= e($phoneWa) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-whatsapp me-1"></i><?= e($phoneRaw ?: $phoneWa) ?>
                                    </a>
                                <?php elseif ($phoneRaw !== ''): ?>
                                    <span class="badge bg-light text-secondary border"><i class="bi bi-telephone me-1"></i><?= e($phoneRaw) ?></span>
                                <?php else: ?>
                                    <span class="small text-muted">Belum ada telepon</span>
                                <?php endif; ?>
                                <?php if (!empty($pax['email'])): ?>
                                    <a class="badge bg-light text-body border text-decoration-none" href="mailto:<?= e((string) $pax['email']) ?>">
                                        <i class="bi bi-envelope me-1"></i><?= e((string) $pax['email']) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="small border-top pt-2">
                        <div class="text-muted fw-semibold mb-1">Catatan / Preferensi</div>
                        <div><?= trim((string) ($pax['note'] ?? '')) !== '' ? nl2br(e((string) $pax['note'])) : '<span class="text-muted">Belum ada catatan pelanggan.</span>' ?></div>
                    </div>
                    <div class="small text-muted border-top mt-2 pt-2 d-flex flex-wrap justify-content-between gap-2">
                        <span>Terdaftar: <?= e($fmtDateTime($pax['created_date'] ?? null)) ?></span>
                        <?php if (!empty($pax['modified_date'])): ?>
                            <span>Diperbarui: <?= e($fmtDateTime($pax['modified_date'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-geo-alt me-1 text-primary"></i>Lokasi Jemput &amp; Antar Terakhir</span>
                    <?php if ($lastLoc): ?>
                        <span class="badge bg-light text-secondary border">
                            Dari <?= e($lastLoc['doc_no']) ?><?= !empty($lastLoc['date']) ? ' · ' . e(tanggal_indo($lastLoc['date'])) : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($lastLoc):
                        $pkGeo = implode(', ', array_filter([$lastLoc['pickup']['district_name'] ?? '', $lastLoc['pickup']['city_name'] ?? '', $lastLoc['pickup']['province_name'] ?? '']));
                        $dlGeo = implode(', ', array_filter([$lastLoc['delivery']['district_name'] ?? '', $lastLoc['delivery']['city_name'] ?? '', $lastLoc['delivery']['province_name'] ?? '']));
                    ?>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-sm-6">
                                <div class="pax-crm-loc-box rounded p-2 h-100">
                                    <div class="small fw-semibold text-primary mb-1"><i class="bi bi-pin-map me-1"></i>Penjemputan</div>
                                    <div class="small fw-medium"><?= e($pkGeo ?: '-') ?></div>
                                    <?php if (!empty($lastLoc['pickup']['address'])): ?>
                                        <div class="small text-muted mt-1"><?= e($lastLoc['pickup']['address']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="pax-crm-loc-box rounded p-2 h-100">
                                    <div class="small fw-semibold text-success mb-1"><i class="bi bi-flag me-1"></i>Pengantaran</div>
                                    <div class="small fw-medium"><?= e($dlGeo ?: '-') ?></div>
                                    <?php if (!empty($lastLoc['delivery']['address'])): ?>
                                        <div class="small text-muted mt-1"><?= e($lastLoc['delivery']['address']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($canTravelManage || $canRentalManage): ?>
                            <div class="small text-muted">
                                Klik <strong>Pesan Travel Lagi</strong> atau <strong>Pesan Sewa Lagi</strong> di kanan atas untuk langsung membuka form booking dengan pelanggan &amp; alamat di atas terisi otomatis.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4 small">
                            Belum ada riwayat penjemputan/pengantaran non-batal untuk pelanggan ini.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Riwayat Pemesanan Gabungan -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">
                <i class="bi bi-clock-history me-1 text-primary"></i>Riwayat Pemesanan (<?= count($filteredBookings) ?>)
            </span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter jenis pemesanan">
                <?php foreach (['all' => 'Semua (' . count($allBookings) . ')', 'travel' => 'Travel', 'rental' => 'Rental'] as $tk => $tlbl):
                    $isAct = ($typeFilter === $tk);
                    $fHref = APP_URL . '/pages/transport/passengers/detail.php?id=' . $id . ($tk !== 'all' ? '&type=' . $tk : '');
                ?>
                    <a href="<?= e($fHref) ?>" class="btn btn-sm <?= $isAct ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($tlbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>No. Dokumen</th>
                        <th>Jenis &amp; Layanan</th>
                        <th>Tanggal</th>
                        <th>Rute</th>
                        <th>Armada</th>
                        <th>Status</th>
                        <th class="text-end">Tagihan &amp; Bayar</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filteredBookings as $r):
                    $bt       = (string) $r['booking_type'];
                    $bid      = (int) $r['id'];
                    $bMod     = $bt === 'rental' ? 'rental' : 'travel';
                    $detHref  = APP_URL . '/pages/' . $bMod . '/bookings/detail.php?id=' . $bid;
                    $sjHref   = APP_URL . '/pages/dispatch/manifest.php?type=' . $bMod . '&booking_id=' . $bid;
                    $d1       = (string) ($r['start_date'] ?: $r['date']);
                    $d2       = (string) ($r['end_date'] ?: $d1);
                    $dateText = ($bt === 'rental' && $d2 !== '' && $d2 !== $d1)
                        ? (tanggal_indo($d1) . ' s/d ' . tanggal_indo($d2))
                        : tanggal_indo($d1);
                    $timeText = substr((string) ($r['time'] ?? ''), 0, 5);
                    $pkText   = trim((string) ($r['pickup_city_name'] ?: $r['pickup_district_name'])) ?: '-';
                    $dlText   = trim((string) ($r['delivery_city_name'] ?: $r['delivery_district_name'])) ?: '-';
                    $catText  = trim(($r['category_code'] !== '' ? $r['category_code'] . ' - ' : '') . $r['category_name']);
                    $price    = (float) ($r['total_price'] ?? 0);
                    $paid     = (float) ($r['total_payment'] ?? 0);
                    $rem      = max(0.0, $price - $paid);
                    $paySt    = invoice_pay_status(['total' => $price, 'paid_total' => $paid, 'status' => (string) ($r['invoice_status'] ?: 'issued')]);
                ?>
                    <tr>
                        <td class="text-nowrap">
                            <a class="text-decoration-none fw-semibold font-monospace" href="<?= e($detHref) ?>">
                                <?= e((string) $r['document_number']) ?>
                            </a>
                            <?php if ((int) ($r['is_primary'] ?? 0) !== 1): ?>
                                <div><span class="badge bg-secondary-subtle text-secondary border" style="font-size:.68rem;">Penumpang Tambahan</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $bt === 'rental' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>">
                                <?= $bt === 'rental' ? 'Rental' : 'Travel' ?>
                            </span>
                            <?php if ($catText !== ''): ?>
                                <div class="small text-muted mt-1"><?= e($catText) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <div><?= e($dateText) ?></div>
                            <?php if ($timeText !== ''): ?>
                                <div class="small text-muted"><?= e($timeText) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small fw-medium"><?= e($pkText) ?> &rarr; <?= e($dlText) ?></div>
                            <?php if (!empty($r['pickup_address']) || !empty($r['delivery_address'])): ?>
                                <div class="small text-muted text-truncate" style="max-width:260px;" title="<?= e(trim(($r['pickup_address'] ?? '') . ' -> ' . ($r['delivery_address'] ?? ''))) ?>">
                                    <?= e(trim((string) ($r['pickup_address'] ?? ''))) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?= $r['vehicle_number'] !== '' ? '<span class="font-monospace">' . e((string) $r['vehicle_number']) . '</span>' : '<span class="text-muted">-</span>' ?>
                        </td>
                        <td class="text-nowrap">
                            <?= status_badge((string) ($r['status'] ?: 'draft')) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="fw-semibold"><?= e(rupiah($price)) ?></div>
                            <?php if ($paySt === 'paid'): ?>
                                <div class="small text-success">Lunas</div>
                            <?php elseif ($rem > 0): ?>
                                <div class="small text-danger">Sisa <?= e(rupiah($rem)) ?></div>
                            <?php else: ?>
                                <div class="small text-muted">Terbayar <?= e(rupiah($paid)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e($detHref) ?>" title="Lihat Detail Pemesanan" aria-label="Lihat Detail Pemesanan"><i class="bi bi-eye"></i></a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e($sjHref) ?>" target="_blank" rel="noopener" title="Cetak Surat Jalan" aria-label="Cetak Surat Jalan"><i class="bi bi-printer"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$filteredBookings): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada riwayat pemesanan untuk pelanggan ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
