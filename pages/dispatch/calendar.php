<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_permission('dispatch.view');

$activeCid = active_company_id();
$cidInt = (int) ($activeCid ?? 0);
$companyIds = $activeCid !== null ? [$cidInt] : accessible_company_ids(0);
$canCreateTravel = $activeCid !== null && can('travel.manage');
$canCreateRental = $activeCid !== null && can('rental.manage');
$canCreateAny = $canCreateTravel || $canCreateRental;
$canTravelView = can('travel.view');
$canRentalView = can('rental.view');
$canDispatchManage = can('dispatch.manage');

// Preload opsi sopir (LIMIT 50 + dukung Driver/Sopir; remote search via Tom Select menampung jutaan baris)
$driverOptions = [];
if ($companyIds) {
    $compIn = implode(',', array_map('intval', $companyIds));
    $multiComp = $activeCid === null;
    $sqlDrv = "SELECT e.id, e.code, e.name AS raw_name,
                      CONCAT(COALESCE(NULLIF(e.code, ''), ''), IF(e.code <> '', ' - ', ''), e.name" . ($multiComp ? ", ' [', c.code, ']'" : '') . ") AS name,
                      COALESCE(e.phone_number, '') AS phone,
                      COALESCE(e.job_position, '') AS job_position
                 FROM employees e
                 JOIN companies c ON c.id = e.company_id
                WHERE e.company_id IN ({$compIn}) AND e.status = 'aktif'
                ORDER BY (CASE WHEN LOWER(COALESCE(e.job_position, '')) IN ('driver', 'sopir') THEN 0 ELSE 1 END), e.name ASC
                LIMIT 50";
    $resDrv = db()->query($sqlDrv);
    if ($resDrv) {
        $driverOptions = $resDrv->fetch_all(MYSQLI_ASSOC);
        $resDrv->close();
    }
}

$vehicleLimit = $activeCid !== null ? 50 : 100;
$vehicleOptions = $companyIds ? opts_vehicles_detailed($cidInt, $companyIds, null, null, $vehicleLimit) : [];
$statusOptions = booking_status_options();

$headerActions = [
    ['label' => 'Penjadwalan', 'href' => 'pages/dispatch/index.php', 'icon' => 'kanban', 'class' => 'btn btn-sm btn-outline-secondary'],
];
if ($canCreateTravel) {
    $headerActions[] = ['label' => 'Pemesanan Tiket Perjalanan', 'href' => 'pages/travel/bookings/form.php', 'icon' => 'plus-lg', 'class' => 'btn btn-sm btn-primary'];
}
if ($canCreateRental) {
    $headerActions[] = ['label' => 'Pemesanan Sewa Kendaraan', 'href' => 'pages/rental/bookings/form.php', 'icon' => 'plus-lg', 'class' => 'btn btn-sm btn-success'];
}

$headerBadges = [];
if ($activeCid === null) {
    $headerBadges[] = ['text' => 'Semua Perusahaan', 'class' => 'bg-info-subtle text-info-emphasis border'];
}

$active = 'dispatch';
$title = 'Kalender Operasional';
require __DIR__ . '/../../includes/header.php';
$eventsUrl = APP_URL . '/pages/dispatch/calendar_events.php';
?>
<main class="p-2 px-lg-2 py-lg-3 w-100 flex-grow-1 ops-calendar-page">
    <?= ui_page_header(
        'Kalender Operasional',
        ['Dashboard' => 'pages/dashboard/index.php', 'Penjadwalan' => 'pages/dispatch/index.php', 'Kalender Operasional' => null],
        $headerBadges,
        $headerActions,
        $activeCid === null ? 'Pilih perusahaan aktif untuk membuat pemesanan baru langsung dari kalender' : 'Klik jadwal untuk detail cepat atau klik tanggal untuk buat pemesanan'
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center erp-filter-bar erp-filter-bar-wide" id="cal-filter-form">
                <div class="col-12 col-md-6 erp-filter-q">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-body-tertiary text-body-secondary"><i class="bi bi-search"></i></span>
                        <input type="search" id="cal-filter-q" class="form-control" placeholder="Cari resi / penumpang / sopir / plat / kota..." autocomplete="off">
                    </div>
                </div>
                <div class="col-6 col-md-3 erp-filter-compact">
                    <select id="cal-filter-kind" class="form-select form-select-sm" aria-label="Filter jenis layanan">
                        <option value="all">Semua Jenis</option>
                        <?php if ($canTravelView): ?><option value="travel">Travel</option><?php endif; ?>
                        <?php if ($canRentalView): ?><option value="rental">Rental</option><?php endif; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 erp-filter-compact">
                    <select id="cal-filter-status" class="form-select form-select-sm" aria-label="Filter status operasional">
                        <option value="">Semua Status</option>
                        <?php foreach ($statusOptions as $stKey => $stLabel): ?>
                            <option value="<?= e($stKey) ?>"><?= e($stLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 erp-filter-item">
                    <select id="cal-filter-driver"
                            class="form-select form-select-sm ts-select"
                            data-ts-placeholder="Semua Sopir"
                            data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                            data-ts-limit="50"
                            <?php if ($cidInt > 0): ?>data-ts-company="<?= (int) $cidInt ?>"<?php endif; ?>
                            aria-label="Filter sopir">
                        <option value="">Semua Sopir</option>
                        <?php foreach ($driverOptions as $drv): ?>
                            <option value="<?= (int) $drv['id'] ?>" data-phone="<?= e($drv['phone'] ?? '') ?>"><?= e($drv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 erp-filter-item">
                    <select id="cal-filter-vehicle"
                            class="form-select form-select-sm ts-select"
                            data-ts-placeholder="Semua Armada"
                            <?php if ($cidInt > 0): ?>data-ts-url="<?= e(APP_URL) ?>/pages/vehicles/options.php" data-ts-limit="50" data-ts-company="<?= (int) $cidInt ?>"<?php endif; ?>
                            aria-label="Filter armada">
                        <option value="">Semua Armada</option>
                        <?php foreach ($vehicleOptions as $veh): ?>
                            <option value="<?= (int) $veh['id'] ?>" data-plate="<?= e($veh['vehicle_number'] ?? '') ?>"><?= e($veh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto erp-filter-actions d-flex align-items-center gap-2">
                    <div class="form-check form-switch mb-0 small me-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="cal-filter-hide-cancelled">
                        <label class="form-check-label text-nowrap" for="cal-filter-hide-cancelled">Sembunyikan Dibatalkan</label>
                    </div>
                    <button type="button" id="cal-filter-reset" class="btn btn-sm btn-outline-secondary text-nowrap" title="Reset semua filter">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </button>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 mt-2 border-top small">
                <div class="d-flex flex-wrap align-items-center gap-3 text-body-secondary">
                    <span class="d-inline-flex align-items-center gap-1"><span class="ops-calendar-legend-swatch" style="background:#0d6efd"></span> Travel</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="ops-calendar-legend-swatch" style="background:#198754"></span> Rental</span>
                    <span class="text-body-tertiary d-none d-sm-inline">|</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="ops-calendar-legend-swatch" style="background:#0284c7;border-left:3px solid #f59e0b"></span> Sedang Jalan</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="ops-calendar-legend-swatch" style="background:#4f7fc8;border:1px dashed #1e40af"></span> Draft</span>
                    <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-check-circle-fill text-success"></i> Selesai</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="ops-calendar-legend-swatch" style="background:#6c757d;opacity:.65"></span> <span class="text-decoration-line-through">Dibatalkan</span></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="cal-loading-badge" class="badge bg-warning-subtle text-warning-emphasis border d-none">
                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Memuat...
                    </span>
                    <span id="cal-visible-count" class="badge bg-body-secondary text-body border">0 jadwal tampil</span>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-3 pt-2 mt-2 border-top small text-body-secondary ops-calendar-icon-legend">
                <span class="fw-semibold text-body">Tanda Ikon:</span>
                <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-clock text-primary" aria-hidden="true"></i> Terjadwal</span>
                <span class="d-inline-flex align-items-center gap-1"><span class="fc-vst-live-dot" aria-hidden="true"></span> Sedang Jalan</span>
                <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-pencil-square text-secondary" aria-hidden="true"></i> Draft</span>
                <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Selesai</span>
                <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-x-circle text-danger" aria-hidden="true"></i> Dibatalkan</span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-1 p-md-2">
            <div id="ops-calendar" class="<?= $canCreateAny ? 'fc-can-create' : '' ?>" aria-label="Kalender booking travel dan rental"></div>
        </div>
    </div>
</main>

<!-- Modal Pratinjau Jadwal Operasional -->
<div class="modal fade" id="calEventModal" tabindex="-1" aria-labelledby="calEventModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered ops-cal-modal-dialog">
        <div class="modal-content ops-cal-modal border-0 shadow-lg overflow-hidden">
            <div class="modal-header ops-cal-modal-header flex-column align-items-stretch gap-2 border-bottom">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="d-flex flex-wrap align-items-center gap-2 min-w-0">
                        <span id="calModalKindBadge" class="badge rounded-pill px-2">Travel</span>
                        <h5 class="modal-title fs-6 fw-bold mb-0 ops-cal-tabular" id="calEventModalTitle">-</h5>
                        <span id="calModalCompanyBadge" class="badge bg-body-secondary text-body border d-none"></span>
                    </div>
                    <button type="button" class="btn-close mt-0" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small">
                    <div class="d-inline-flex align-items-center gap-2 text-body-secondary">
                        <i class="bi bi-calendar3 text-primary" aria-hidden="true"></i>
                        <span class="fw-semibold text-body" id="calModalPeriod">-</span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <span id="calModalStatusBadge" class="badge rounded-pill">Terjadwal</span>
                        <span id="calModalPaymentBadge" class="badge rounded-pill">Belum Bayar</span>
                    </div>
                </div>
            </div>
            <div class="modal-body p-3 d-flex flex-column gap-3">
                <div class="ops-cal-detail-card rounded-3 p-3">
                    <div class="ops-cal-section-label mb-1">
                        <i class="bi bi-signpost-split text-primary me-1" aria-hidden="true"></i>Rute Perjalanan
                    </div>
                    <div class="fw-semibold mb-0" id="calModalRoute">-</div>
                    <div class="ops-cal-route-stops mt-2 pt-2 border-top d-none" id="calModalAddresses">
                        <div class="ops-cal-route-stop d-none" id="calModalPickupWrap">
                            <span class="ops-cal-stop-dot ops-cal-stop-dot-pickup" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <span class="ops-cal-stop-tag">Jemput</span>
                                <div class="text-body-secondary small" id="calModalPickup"></div>
                            </div>
                        </div>
                        <div class="ops-cal-route-stop d-none" id="calModalDeliveryWrap">
                            <span class="ops-cal-stop-dot ops-cal-stop-dot-delivery" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <span class="ops-cal-stop-tag">Antar</span>
                                <div class="text-body-secondary small" id="calModalDelivery"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-stretch">
                    <div class="col-sm-6">
                        <div class="ops-cal-detail-card rounded-3 p-3 h-100 d-flex flex-column">
                            <div class="ops-cal-section-label mb-1">
                                <i class="bi bi-person text-primary me-1" aria-hidden="true"></i>Pelanggan
                            </div>
                            <div class="fw-semibold text-break" id="calModalPassenger">-</div>
                            <div class="mt-1 d-none" id="calModalPassengerExtraWrap">
                                <span class="badge bg-body-secondary text-body-secondary border fw-normal" id="calModalPassengerExtra"></span>
                            </div>
                            <div id="calModalPassengerPhoneWrap" class="mt-auto pt-2"></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="ops-cal-detail-card rounded-3 p-3 h-100 d-flex flex-column">
                            <div class="ops-cal-section-label mb-1">
                                <i class="bi bi-truck text-primary me-1" aria-hidden="true"></i>Armada &amp; Sopir
                            </div>
                            <div class="fw-semibold ops-cal-tabular" id="calModalVehicle">-</div>
                            <div class="small text-body-secondary mt-1" id="calModalDriver">-</div>
                            <div id="calModalDriverPhoneWrap" class="mt-auto pt-2"></div>
                        </div>
                    </div>
                </div>

                <div class="ops-cal-detail-card rounded-3 p-3">
                    <div class="row g-2 text-start">
                        <div class="col-12 col-sm-4">
                            <div class="ops-cal-section-label">Total Harga</div>
                            <div class="fw-bold ops-cal-money text-body" id="calModalTotalPrice">-</div>
                        </div>
                        <div class="col-12 col-sm-4 ops-cal-money-sep">
                            <div class="ops-cal-section-label">Dibayar</div>
                            <div class="fw-bold ops-cal-money text-success" id="calModalTotalPayment">-</div>
                        </div>
                        <div class="col-12 col-sm-4 ops-cal-money-sep">
                            <div class="ops-cal-section-label">Sisa Tagihan</div>
                            <div class="fw-bold ops-cal-money" id="calModalBalance">-</div>
                        </div>
                    </div>
                </div>

                <div class="ops-cal-note-box rounded-end px-3 py-2 d-none" id="calModalNoteWrap">
                    <div class="ops-cal-section-label mb-1">
                        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>Catatan
                    </div>
                    <div class="small text-body" id="calModalNote"></div>
                </div>
            </div>
            <div class="modal-footer ops-cal-modal-footer py-2 px-3 d-flex flex-wrap justify-content-between gap-2 border-top">
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($canDispatchManage): ?>
                        <a href="#" id="calModalDispatchBtn" class="btn btn-sm btn-outline-warning d-none">
                            <i class="bi bi-person-plus me-1"></i>Tugaskan Driver
                        </a>
                    <?php endif; ?>
                    <a href="#" id="calModalManifestBtn" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none">
                        <i class="bi bi-printer me-1"></i>Cetak Surat Jalan
                    </a>
                </div>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="#" id="calModalDetailBtn" class="btn btn-sm btn-primary px-3">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Lihat Detail Booking
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canCreateAny): ?>
<!-- Modal Tambah Pemesanan dari Tanggal Kalender -->
<div class="modal fade" id="calDateCreateModal" tabindex="-1" aria-labelledby="calDateCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6 fw-bold mb-0" id="calDateCreateTitle">Buat Pemesanan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body py-3">
                <p class="small text-body-secondary mb-3">
                    Tanggal dipilih: <strong class="text-body" id="calDateCreateLabel">-</strong>
                </p>
                <div class="d-grid gap-2">
                    <?php if ($canCreateTravel): ?>
                        <a href="#" id="calDateCreateTravelBtn" class="btn btn-primary btn-sm">
                            <i class="bi bi-ticket-perforated me-1"></i>Pemesanan Tiket Perjalanan
                        </a>
                    <?php endif; ?>
                    <?php if ($canCreateRental): ?>
                        <a href="#" id="calDateCreateRentalBtn" class="btn btn-success btn-sm">
                            <i class="bi bi-car-front me-1"></i>Pemesanan Sewa Kendaraan
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const eventsBaseUrl = <?= json_encode($eventsUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const travelFormBase = <?= json_encode(APP_URL . '/pages/travel/bookings/form.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const rentalFormBase = <?= json_encode(APP_URL . '/pages/rental/bookings/form.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const canCreateAny = <?= $canCreateAny ? 'true' : 'false' ?>;

    const elQ = document.getElementById('cal-filter-q');
    const elKind = document.getElementById('cal-filter-kind');
    const elStatus = document.getElementById('cal-filter-status');
    const elDriver = document.getElementById('cal-filter-driver');
    const elVehicle = document.getElementById('cal-filter-vehicle');
    const elHideCancelled = document.getElementById('cal-filter-hide-cancelled');
    const elReset = document.getElementById('cal-filter-reset');
    const elLoading = document.getElementById('cal-loading-badge');
    const elCount = document.getElementById('cal-visible-count');

    const escHtml = (str) => String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const getSelectValue = (el) => {
        if (!el) return '';
        const raw = el.tomselect ? el.tomselect.getValue() : el.value;
        return Array.isArray(raw) ? String(raw[0] || '') : String(raw || '');
    };

    const statusBadgeClass = {
        draft: 'badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        scheduled: 'badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        on_trip: 'badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        done: 'badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle',
        cancelled: 'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle'
    };

    const payBadgeClass = {
        paid: 'badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle',
        partial: 'badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        unpaid: 'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        cancelled: 'badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle'
    };

    const statusIconHtml = (status) => {
        if (status === 'on_trip') return '<span class="fc-vst-live-dot" title="Sedang Jalan"></span>';
        if (status === 'done') return '<i class="bi bi-check-circle-fill flex-shrink-0" title="Selesai"></i>';
        if (status === 'draft') return '<i class="bi bi-pencil-square flex-shrink-0" title="Draft"></i>';
        if (status === 'cancelled') return '<i class="bi bi-x-circle flex-shrink-0" title="Dibatalkan"></i>';
        return '<i class="bi bi-clock flex-shrink-0" title="Terjadwal"></i>';
    };

    const hasAnyActiveFilter = () => Boolean(
        (elQ && elQ.value.trim() !== '') ||
        (elKind && elKind.value !== 'all') ||
        (elStatus && elStatus.value !== '') ||
        getSelectValue(elDriver) !== '' ||
        getSelectValue(elVehicle) !== '' ||
        (elHideCancelled && elHideCancelled.checked)
    );

    const buildWaChip = (phoneDisplay, phone62) => {
        if (!phoneDisplay) return null;
        if (!phone62) {
            const span = document.createElement('span');
            span.className = 'text-body-secondary small';
            span.textContent = phoneDisplay;
            return span;
        }
        const a = document.createElement('a');
        a.href = 'https://wa.me/' + encodeURIComponent(phone62);
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'btn btn-sm btn-outline-success rounded-pill py-1 px-2 d-inline-flex align-items-center gap-1 ops-cal-wa-btn';
        a.innerHTML = '<i class="bi bi-whatsapp"></i><span>' + escHtml(phoneDisplay) + '</span>';
        return a;
    };

    const openEventModal = (fcEvent) => {
        const p = fcEvent.extendedProps || {};
        const modalEl = document.getElementById('calEventModal');
        if (!modalEl || !window.bootstrap?.Modal) {
            if (fcEvent.url) window.location.href = fcEvent.url;
            return;
        }

        const kindBadge = document.getElementById('calModalKindBadge');
        kindBadge.textContent = p.kind_label || 'Travel';
        kindBadge.className = p.kind === 'rental'
            ? 'badge rounded-pill px-2 bg-success'
            : 'badge rounded-pill px-2 bg-primary';

        document.getElementById('calEventModalTitle').textContent = p.document_number || fcEvent.title;

        const compBadge = document.getElementById('calModalCompanyBadge');
        if (p.multi_company && p.company_code) {
            compBadge.textContent = p.company_code;
            compBadge.classList.remove('d-none');
        } else {
            compBadge.classList.add('d-none');
        }

        const stBadge = document.getElementById('calModalStatusBadge');
        stBadge.textContent = p.status_label || p.status || '-';
        stBadge.className = statusBadgeClass[p.status] || 'badge rounded-pill bg-secondary';

        const pmBadge = document.getElementById('calModalPaymentBadge');
        pmBadge.textContent = p.payment_label || 'Belum Bayar';
        pmBadge.className = payBadgeClass[p.payment_status] || 'badge rounded-pill bg-secondary';

        document.getElementById('calModalPeriod').textContent = p.period_label || '-';
        document.getElementById('calModalRoute').textContent = p.route_label || '—';

        const addrWrap = document.getElementById('calModalAddresses');
        const pickupWrap = document.getElementById('calModalPickupWrap');
        const deliveryWrap = document.getElementById('calModalDeliveryWrap');
        document.getElementById('calModalPickup').textContent = p.pickup_address || '';
        document.getElementById('calModalDelivery').textContent = p.delivery_address || '';
        pickupWrap.classList.toggle('d-none', !p.pickup_address);
        deliveryWrap.classList.toggle('d-none', !p.delivery_address);
        addrWrap.classList.toggle('d-none', !p.pickup_address && !p.delivery_address);

        document.getElementById('calModalPassenger').textContent = p.passenger || '-';
        const extraWrap = document.getElementById('calModalPassengerExtraWrap');
        const extraBadge = document.getElementById('calModalPassengerExtra');
        if (p.extra_pax > 0) {
            extraBadge.textContent = '+' + p.extra_pax + ' penumpang lain';
            extraWrap.classList.remove('d-none');
        } else {
            extraWrap.classList.add('d-none');
        }

        const paxPhoneWrap = document.getElementById('calModalPassengerPhoneWrap');
        paxPhoneWrap.replaceChildren();
        const paxChip = buildWaChip(p.passenger_phone, p.passenger_phone_62);
        if (paxChip) paxPhoneWrap.appendChild(paxChip);

        const vehEl = document.getElementById('calModalVehicle');
        vehEl.textContent = p.vehicle_number || 'Armada belum ditentukan';
        vehEl.className = p.vehicle_number
            ? 'fw-semibold ops-cal-tabular'
            : 'small text-body-secondary fst-italic';
        document.getElementById('calModalDriver').textContent = p.driver ? ('Sopir: ' + p.driver) : 'Sopir belum ditugaskan';

        const drvPhoneWrap = document.getElementById('calModalDriverPhoneWrap');
        drvPhoneWrap.replaceChildren();
        const drvChip = buildWaChip(p.driver_phone, p.driver_phone_62);
        if (drvChip) drvPhoneWrap.appendChild(drvChip);

        document.getElementById('calModalTotalPrice').textContent = p.total_price_fmt || 'Rp 0';
        document.getElementById('calModalTotalPayment').textContent = p.total_payment_fmt || 'Rp 0';

        const balEl = document.getElementById('calModalBalance');
        const balAmt = Number(p.balance_amount || 0);
        if (p.status === 'cancelled' || p.payment_status === 'cancelled') {
            balEl.textContent = 'Dibatalkan';
            balEl.className = 'fw-semibold ops-cal-money text-body-secondary';
        } else if (balAmt <= 0 || p.payment_status === 'paid') {
            balEl.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Rp 0';
            balEl.className = 'fw-bold ops-cal-money text-success';
        } else {
            balEl.textContent = p.balance_fmt || 'Rp 0';
            balEl.className = 'fw-bold ops-cal-money text-danger';
        }

        const noteWrap = document.getElementById('calModalNoteWrap');
        const noteEl = document.getElementById('calModalNote');
        if (p.note) {
            noteEl.textContent = p.note;
            noteWrap.classList.remove('d-none');
        } else {
            noteWrap.classList.add('d-none');
        }

        const detailBtn = document.getElementById('calModalDetailBtn');
        detailBtn.href = p.detail_url || fcEvent.url || '#';

        const manifestBtn = document.getElementById('calModalManifestBtn');
        if (p.manifest_url && p.status !== 'cancelled') {
            manifestBtn.href = p.manifest_url;
            manifestBtn.classList.remove('d-none');
        } else {
            manifestBtn.classList.add('d-none');
        }

        const dispatchBtn = document.getElementById('calModalDispatchBtn');
        if (dispatchBtn) {
            if (!p.has_driver && p.status !== 'cancelled' && p.status !== 'done' && p.dispatch_url) {
                dispatchBtn.href = p.dispatch_url;
                dispatchBtn.classList.remove('d-none');
            } else {
                dispatchBtn.classList.add('d-none');
            }
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    const formatIndoLongDate = (ymd) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return ymd;
        const dt = new Date(ymd + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return ymd;
        try {
            return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(dt);
        } catch (e) {
            return ymd;
        }
    };

    const openDateCreateModal = (ymd) => {
        if (!canCreateAny || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return;
        const modalEl = document.getElementById('calDateCreateModal');
        if (!modalEl || !window.bootstrap?.Modal) return;
        document.getElementById('calDateCreateLabel').textContent = formatIndoLongDate(ymd);
        const btnTrv = document.getElementById('calDateCreateTravelBtn');
        const btnRnt = document.getElementById('calDateCreateRentalBtn');
        if (btnTrv) btnTrv.href = travelFormBase + '?date=' + encodeURIComponent(ymd);
        if (btnRnt) btnRnt.href = rentalFormBase + '?date=' + encodeURIComponent(ymd);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    const calendar = new FullCalendar.Calendar(document.getElementById('ops-calendar'), {
        initialView: window.matchMedia('(max-width: 767px)').matches ? 'listWeek' : 'dayGridMonth',
        locale: 'id',
        height: 'auto',
        nowIndicator: true,
        dayMaxEvents: true,
        allDayText: 'Harian',
        noEventsText: 'Tidak ada jadwal pada rentang ini.',
        buttonText: {
            today: 'Hari Ini',
            month: 'Bulan',
            week: 'Minggu',
            day: 'Hari',
            list: 'Daftar'
        },
        moreLinkText: (n) => '+' + n + ' lagi',
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        events: function (fetchInfo, successCallback, failureCallback) {
            const params = new URLSearchParams({
                start: fetchInfo.startStr,
                end: fetchInfo.endStr,
                kind: elKind ? elKind.value : 'all',
                status: elStatus ? elStatus.value : '',
                hide_cancelled: elHideCancelled && elHideCancelled.checked ? '1' : '0',
                driver_id: getSelectValue(elDriver),
                vehicle_id: getSelectValue(elVehicle),
                q: elQ ? elQ.value.trim() : ''
            });
            fetch(eventsBaseUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(data => {
                    if (!Array.isArray(data)) throw new Error('Respon kalender tidak valid');
                    successCallback(data);
                })
                .catch(err => {
                    console.error(err);
                    failureCallback(err);
                });
        },
        loading: function (isLoading) {
            if (elLoading) elLoading.classList.toggle('d-none', !isLoading);
        },
        eventsSet: function (evList) {
            if (!elCount) return;
            const n = Array.isArray(evList) ? evList.length : 0;
            elCount.textContent = n + ' jadwal tampil' + (hasAnyActiveFilter() ? ' (terfilter)' : '');
        },
        eventContent: function (arg) {
            const p = arg.event.extendedProps || {};
            const viewType = arg.view ? arg.view.type : 'dayGridMonth';
            const compTag = (p.multi_company && p.company_code)
                ? '<span class="fc-vst-kind-tag">' + escHtml(p.company_code) + '</span>'
                : '';

            if (viewType.startsWith('list')) {
                const stCls = statusBadgeClass[p.status] || 'badge bg-secondary';
                const pmCls = payBadgeClass[p.payment_status] || 'badge bg-secondary';
                const kindCls = p.kind === 'rental' ? 'badge bg-success' : 'badge bg-primary';
                const subBits = [
                    p.route_label && p.route_label !== '—' ? '<i class="bi bi-geo-alt me-1"></i>' + escHtml(p.route_label) : null,
                    p.vehicle_number ? '<i class="bi bi-truck me-1"></i>' + escHtml(p.vehicle_number) : null,
                    p.driver ? '<i class="bi bi-person-badge me-1"></i>' + escHtml(p.driver) : '<span class="text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i>Tanpa Sopir</span>',
                    p.kind === 'rental' && p.period_label ? '<i class="bi bi-calendar-range me-1"></i>' + escHtml(p.period_label) : null
                ].filter(Boolean);

                const listPrimaryText = (p.kind === 'rental' && p.vehicle_number)
                    ? (p.vehicle_number + ' · ' + (p.passenger || ''))
                    : (p.passenger || '');

                return {
                    html: '<div class="d-flex flex-column gap-1">'
                        + '<div class="d-flex flex-wrap align-items-center gap-1">'
                        + '<span class="' + kindCls + '">' + escHtml(p.kind_label || 'Travel') + '</span>'
                        + (p.multi_company && p.company_code ? '<span class="badge bg-body-secondary text-body border">' + escHtml(p.company_code) + '</span>' : '')
                        + '<span class="' + stCls + '">' + escHtml(p.status_label || '') + '</span>'
                        + '<span class="' + pmCls + '">' + escHtml(p.payment_label || '') + '</span>'
                        + '<strong class="fc-vst-list-doc ms-1">' + escHtml(listPrimaryText) + '</strong>'
                        + '<span class="text-body-secondary small">(' + escHtml(p.document_number || '') + ')</span>'
                        + '</div>'
                        + '<div class="small text-body-secondary d-flex flex-wrap gap-3">' + subBits.map(b => '<span>' + b + '</span>').join('') + '</div>'
                        + '</div>'
                };
            }

            if (viewType.startsWith('timeGrid') && !arg.event.allDay) {
                const line2 = [p.vehicle_number, p.driver].filter(Boolean).join(' · ');
                return {
                    html: '<div class="px-1 py-0 overflow-hidden">'
                        + '<div class="fc-vst-pill-inner">'
                        + statusIconHtml(p.status)
                        + (p.starts_at ? '<span class="fc-vst-time">' + escHtml(p.starts_at) + '</span>' : '')
                        + compTag
                        + '<strong class="text-truncate">' + escHtml(p.passenger || '') + '</strong>'
                        + '</div>'
                        + (line2 ? '<div class="fc-vst-sub text-truncate">' + escHtml(line2) + '</div>' : '')
                        + (p.route_label && p.route_label !== '—' ? '<div class="fc-vst-sub text-truncate">' + escHtml(p.route_label) + '</div>' : '')
                        + '</div>'
                };
            }

            const isRentalWithPlate = (p.kind === 'rental' && Boolean(p.vehicle_number));
            const hasTravelPlate = (p.kind !== 'rental' && Boolean(p.vehicle_number));
            const timeChunk = (p.kind !== 'rental' && p.starts_at)
                ? '<span class="fc-vst-time">' + escHtml(p.starts_at) + '</span>'
                : '';
            const primaryText = isRentalWithPlate ? p.vehicle_number : (p.passenger || '');
            const primaryCls = isRentalWithPlate ? 'flex-shrink-0' : 'text-truncate';
            const extraHint = isRentalWithPlate
                ? (p.passenger || (p.route_label && p.route_label !== '—' ? p.route_label : ''))
                : (p.vehicle_number || (p.route_label && p.route_label !== '—' ? p.route_label : ''));
            const extraCls = hasTravelPlate ? 'fc-vst-sub fc-vst-plate flex-shrink-0' : 'fc-vst-sub text-truncate';

            return {
                html: '<div class="fc-vst-pill-inner">'
                    + statusIconHtml(p.status)
                    + timeChunk
                    + compTag
                    + '<strong class="' + primaryCls + '">' + escHtml(primaryText) + '</strong>'
                    + (extraHint ? '<span class="' + extraCls + '">· ' + escHtml(extraHint) + '</span>' : '')
                    + '</div>'
            };
        },
        eventDidMount: function (info) {
            if (info.event.extendedProps && info.event.extendedProps.details) {
                info.el.title = info.event.extendedProps.details;
            }
        },
        eventClick: function (info) {
            if (info.jsEvent && (info.jsEvent.ctrlKey || info.jsEvent.metaKey)) {
                return;
            }
            info.jsEvent.preventDefault();
            openEventModal(info.event);
        },
        dateClick: function (info) {
            if (!canCreateAny) return;
            const ymd = String(info.dateStr || '').slice(0, 10);
            openDateCreateModal(ymd);
        }
    });
    calendar.render();

    const refetch = () => calendar.refetchEvents();
    let qTimer = null;
    if (elQ) {
        elQ.addEventListener('input', () => {
            clearTimeout(qTimer);
            qTimer = setTimeout(refetch, 280);
        });
        elQ.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(qTimer);
                refetch();
            }
        });
    }
    if (elKind) elKind.addEventListener('change', refetch);
    if (elStatus) {
        elStatus.addEventListener('change', () => {
            if (elStatus.value === 'cancelled' && elHideCancelled && elHideCancelled.checked) {
                elHideCancelled.checked = false;
            }
            refetch();
        });
    }
    if (elHideCancelled) elHideCancelled.addEventListener('change', refetch);

    if (window.bindTsChange) {
        if (elDriver) window.bindTsChange(elDriver, refetch);
        if (elVehicle) window.bindTsChange(elVehicle, refetch);
    } else {
        if (elDriver) elDriver.addEventListener('change', refetch);
        if (elVehicle) elVehicle.addEventListener('change', refetch);
    }

    if (elReset) {
        elReset.addEventListener('click', () => {
            if (elQ) elQ.value = '';
            if (elKind) elKind.value = 'all';
            if (elStatus) elStatus.value = '';
            if (elHideCancelled) elHideCancelled.checked = false;
            if (window.setTsValueSilent) {
                if (elDriver) window.setTsValueSilent(elDriver, '');
                if (elVehicle) window.setTsValueSilent(elVehicle, '');
            } else {
                if (elDriver) elDriver.value = '';
                if (elVehicle) elVehicle.value = '';
            }
            refetch();
        });
    }
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
