<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_permission('dispatch.view');
category_service_ensure_schema();

$activeCid = active_company_id();
$cidInt = (int) ($activeCid ?? 0);
$companyIds = $activeCid !== null ? [$cidInt] : accessible_company_ids(0);
$canCreateTravel = $activeCid !== null && can('travel.manage');
$canCreateRental = $activeCid !== null && can('rental.manage');
$canCreateAny = $canCreateTravel || $canCreateRental;
$canTravelView = can('travel.view');
$canRentalView = can('rental.view');

// Preload opsi sopir (LIMIT 50 + dukung Driver/Sopir; remote search via Tom Select menampung jutaan baris)
$driverOptions = [];
if ($companyIds) {
    $compIn = implode(',', array_map('intval', $companyIds));
    $multiComp = $activeCid === null;
    $sqlDrv = "SELECT e.id, e.company_id, e.code, e.name AS raw_name,
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

$categoryOptions = [];
if ($companyIds) {
    $compIn = implode(',', array_map('intval', $companyIds));
    $multiComp = $activeCid === null;
    $sqlCat = "SELECT cv.id, cv.code, cv.name, cv.service_type, cv.pricing_unit, c.code AS company_code
                 FROM category_vehicles cv
                 JOIN companies c ON c.id = cv.company_id
                WHERE cv.company_id IN ({$compIn}) AND cv.is_active = 1
                ORDER BY cv.service_type ASC, cv.name ASC, cv.id ASC";
    $resCat = db()->query($sqlCat);
    if ($resCat) {
        foreach ($resCat->fetch_all(MYSQLI_ASSOC) as $catRow) {
            if ($catRow['service_type'] === 'travel' && !$canTravelView) {
                continue;
            }
            if ($catRow['service_type'] === 'rental' && !$canRentalView) {
                continue;
            }
            $prefix = trim((string) ($catRow['code'] ?? ''));
            $compSuffix = ($multiComp && !empty($catRow['company_code'])) ? ' (' . $catRow['company_code'] . ')' : '';
            $catRow['label'] = ($prefix !== '' ? '[' . $prefix . '] ' : '') . $catRow['name'] . $compSuffix;
            $categoryOptions[] = $catRow;
        }
        $resCat->close();
    }
}

$vehicleLimit = $activeCid !== null ? 50 : 100;
$vehicleOptions = $companyIds ? opts_vehicles_detailed($cidInt, $companyIds, null, null, $vehicleLimit) : [];
$statusOptions = booking_status_options();

$headerActions = [];
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
$statusUpdateUrl = APP_URL . '/pages/dispatch/calendar_status.php';
$assignUrl = APP_URL . '/pages/dispatch/calendar_assign.php';
$rescheduleUrl = APP_URL . '/pages/dispatch/calendar_reschedule.php';
$availCheckUrl = APP_URL . '/pages/transport/availability_check.php';
$csrfToken = csrf_token();
?>
<main class="p-2 px-lg-2 py-lg-3 w-100 flex-grow-1 ops-calendar-page">
    <?= ui_page_header(
        'Kalender Operasional',
        ['Dashboard' => 'pages/dashboard/index.php', 'Kalender Operasional' => null],
        $headerBadges,
        $headerActions,
        $activeCid === null ? 'Pilih perusahaan aktif untuk membuat pemesanan baru langsung dari kalender' : 'Klik jadwal untuk aksi cepat, atau klik/seret tanggal untuk membuat pemesanan'
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center erp-filter-bar ops-cal-filter-bar" id="cal-filter-form">
                <div class="col-12 col-md-5 col-xl-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm flex-grow-1 min-w-0">
                            <span class="input-group-text bg-body-tertiary text-body-secondary"><i class="bi bi-search" aria-hidden="true"></i></span>
                            <input type="search" id="cal-filter-q" class="form-control" placeholder="Cari resi / pelanggan / sopir / plat / kota... (tekan /)" autocomplete="off">
                        </div>
                        <button type="button" id="cal-mobile-filter-toggle"
                                class="btn btn-sm btn-outline-secondary d-md-none flex-shrink-0 text-nowrap"
                                data-bs-toggle="collapse" data-bs-target="#cal-filter-advanced"
                                aria-expanded="false" aria-controls="cal-filter-advanced">
                            <i class="bi bi-sliders me-1" aria-hidden="true"></i>Filter
                            <span id="cal-mobile-filter-badge" class="badge bg-primary rounded-pill ms-1 d-none">0</span>
                        </button>
                    </div>
                </div>
                <div class="collapse col-12" id="cal-filter-advanced">
                    <div class="row g-2 align-items-center">
                        <div class="col-6 col-md-4 col-xl-3">
                            <select id="cal-filter-category" class="form-select form-select-sm" aria-label="Filter kategori layanan">
                                <option value="">Semua Layanan</option>
                                <?php foreach ($categoryOptions as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>" data-service-type="<?= e($cat['service_type']) ?>"><?= e($cat['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <select id="cal-filter-status" class="form-select form-select-sm" aria-label="Filter status operasional">
                                <option value="">Semua Status</option>
                                <?php foreach ($statusOptions as $stKey => $stLabel): ?>
                                    <option value="<?= e($stKey) ?>"><?= e($stLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-xl-4 d-flex flex-wrap align-items-center justify-content-between justify-content-xl-end gap-2">
                            <div class="form-check form-switch mb-0 small me-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="cal-filter-hide-cancelled">
                                <label class="form-check-label text-nowrap" for="cal-filter-hide-cancelled">Sembunyikan Dibatalkan</label>
                            </div>
                            <button type="button" id="cal-filter-reset" class="btn btn-sm btn-outline-secondary text-nowrap" title="Reset semua filter">
                                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 mt-2 border-top small text-body-secondary ops-calendar-icon-legend">
                <div class="ops-cal-chip-group" role="group" aria-label="Filter cepat jenis layanan dan status">
                    <?php if ($canTravelView): ?>
                        <button type="button" class="ops-cal-chip" data-cal-group="regular" aria-pressed="false">
                            <span class="ops-calendar-legend-swatch" style="background:#0d6efd"></span> Reguler
                        </button>
                        <button type="button" class="ops-cal-chip" data-cal-group="package" aria-pressed="false">
                            <span class="ops-calendar-legend-swatch" style="background:#6f42c1"></span> Paket
                        </button>
                        <button type="button" class="ops-cal-chip" data-cal-group="carter" aria-pressed="false">
                            <span class="ops-calendar-legend-swatch" style="background:#0e7490"></span> Carter
                        </button>
                    <?php endif; ?>
                    <?php if ($canRentalView): ?>
                        <button type="button" class="ops-cal-chip" data-cal-group="rental" aria-pressed="false">
                            <span class="ops-calendar-legend-swatch" style="background:#198754"></span> Rental
                        </button>
                    <?php endif; ?>
                    <span class="text-body-tertiary d-none d-sm-inline px-1" aria-hidden="true">|</span>
                    <button type="button" class="ops-cal-chip" data-cal-status="scheduled" aria-pressed="false">
                        <i class="bi bi-clock text-primary" aria-hidden="true"></i> Terjadwal
                    </button>
                    <button type="button" class="ops-cal-chip" data-cal-status="on_trip" aria-pressed="false">
                        <span class="fc-vst-live-dot" aria-hidden="true"></span> Sedang Jalan
                    </button>
                    <button type="button" class="ops-cal-chip" data-cal-status="draft" aria-pressed="false">
                        <i class="bi bi-pencil-square text-secondary" aria-hidden="true"></i> Draft
                    </button>
                    <button type="button" class="ops-cal-chip" data-cal-status="done" aria-pressed="false">
                        <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Selesai
                    </button>
                    <button type="button" class="ops-cal-chip" data-cal-status="cancelled" aria-pressed="false">
                        <i class="bi bi-x-circle text-danger" aria-hidden="true"></i> Dibatalkan
                    </button>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <span id="cal-visible-count" class="badge bg-body-secondary text-body border">0 jadwal tampil</span>
                    <span class="d-none d-lg-inline-flex align-items-center text-body-tertiary"
                          title="Shortcut Keyboard: / fokus pencarian, T hari ini, ←/→ pindah periode, M Bulan, W Minggu, D Hari, L Daftar">
                        <i class="bi bi-keyboard" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-2">
            <div class="ops-cal-toolbar-meta">
                <div class="ops-cal-kpi-strip" role="group" aria-label="Ringkasan operasional periode ini">
                    <button type="button" class="ops-cal-kpi-item active" id="cal-kpi-total-btn" data-kpi="all" aria-pressed="true">
                        <span>Total</span>
                        <span class="ops-cal-kpi-num" id="cal-kpi-total-num">0</span>
                    </button>
                    <button type="button" class="ops-cal-kpi-item" id="cal-kpi-ontrip-btn" data-kpi="on_trip" aria-pressed="false">
                        <span class="fc-vst-live-dot" aria-hidden="true"></span>
                        <span>Sedang Jalan</span>
                        <span class="ops-cal-kpi-num" id="cal-kpi-ontrip-num">0</span>
                    </button>
                    <button type="button" class="ops-cal-kpi-item" id="cal-kpi-unassigned-btn" data-kpi="unassigned" aria-pressed="false">
                        <i class="bi bi-exclamation-triangle-fill text-warning" aria-hidden="true"></i>
                        <span>Butuh Sopir/Armada</span>
                        <span class="ops-cal-kpi-num" id="cal-kpi-unassigned-num">0</span>
                    </button>
                    <button type="button" class="ops-cal-kpi-item" id="cal-kpi-unpaid-btn" data-kpi="unpaid" aria-pressed="false">
                        <i class="bi bi-cash-coin text-danger" aria-hidden="true"></i>
                        <span>Belum Lunas</span>
                        <span class="ops-cal-kpi-num" id="cal-kpi-unpaid-num">0</span>
                    </button>
                </div>
                <div class="ops-cal-jump-wrap">
                    <label for="cal-jump-date" class="small text-body-secondary mb-0 text-nowrap">Ke Tanggal:</label>
                    <input type="date" id="cal-jump-date" class="form-control form-control-sm ops-cal-jump-input"
                           aria-label="Lompat ke tanggal" title="Lompat langsung ke tanggal tertentu">
                </div>
            </div>

            <div id="cal-success-banner" class="alert alert-success d-none py-2 px-3 mb-2 d-flex align-items-center justify-content-between gap-2" role="status" data-no-autodismiss>
                <div class="d-flex align-items-center gap-2 small">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    <span id="cal-success-text">Perubahan berhasil disimpan.</span>
                </div>
                <button type="button" id="cal-success-close" class="btn-close btn-sm" aria-label="Tutup"></button>
            </div>

            <div id="cal-error-banner" class="alert alert-danger d-none py-2 px-3 mb-2" role="alert" data-no-autodismiss>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2 small">
                        <i class="bi bi-wifi-off" aria-hidden="true"></i>
                        <span id="cal-error-text">Gagal memuat data kalender.</span>
                    </div>
                    <button type="button" id="cal-retry-btn" class="btn btn-sm btn-outline-danger flex-shrink-0">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Coba Lagi
                    </button>
                </div>
            </div>

            <div class="ops-cal-stage">
                <div id="cal-skeleton-overlay" class="vst-cal-skeleton d-none" aria-hidden="true">
                    <div class="vst-sk-cal-toolbar">
                        <div class="vst-sk-header-right">
                            <span class="vst-skel vst-skel-btn" style="width:76px"></span>
                            <span class="vst-skel vst-skel-btn" style="width:74px"></span>
                        </div>
                        <span class="vst-skel vst-skel-line mb-0" style="width:160px;height:18px"></span>
                        <div class="vst-sk-header-right d-none d-md-flex">
                            <span class="vst-skel vst-skel-btn" style="width:62px"></span>
                            <span class="vst-skel vst-skel-btn" style="width:62px"></span>
                            <span class="vst-skel vst-skel-btn" style="width:62px"></span>
                        </div>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="vst-sk-cal-weekdays">
                            <?php for ($wd = 0; $wd < 7; $wd++): ?>
                                <span class="vst-skel vst-skel-line mb-0"></span>
                            <?php endfor; ?>
                        </div>
                        <div class="vst-sk-cal-grid">
                            <?php
                            $calSkPills = [
                                2  => [92, 70],
                                4  => [84],
                                9  => [96],
                                11 => [76, 88],
                                16 => [90],
                                18 => [74],
                                22 => [95, 68],
                                26 => [94],
                            ];
                            for ($ci = 0; $ci < 35; $ci++):
                            ?>
                                <div class="vst-sk-cal-cell">
                                    <span class="vst-skel vst-skel-line vst-sk-cal-num"></span>
                                    <?php foreach ($calSkPills[$ci] ?? [] as $pw): ?>
                                        <span class="vst-skel vst-skel-pill" style="width:<?= $pw ?>%"></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="d-md-none vst-sk-cal-list">
                        <?php for ($li = 0; $li < 5; $li++): ?>
                            <div class="vst-sk-cal-list-row">
                                <div class="vst-sk-cal-list-top">
                                    <span class="vst-skel vst-skel-pill" style="width:58px"></span>
                                    <span class="vst-skel vst-skel-pill" style="width:72px"></span>
                                    <span class="vst-skel vst-skel-line mb-0" style="width:42%"></span>
                                </div>
                                <span class="vst-skel vst-skel-line mb-0" style="width:76%;height:10px"></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div id="ops-calendar" class="<?= $canCreateAny ? 'fc-can-create' : '' ?>" aria-label="Kalender booking travel dan rental"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Pratinjau & Aksi Cepat Jadwal Operasional -->
<div class="modal fade" id="calEventModal" tabindex="-1" aria-labelledby="calEventModalTitle" aria-hidden="true" data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ops-cal-modal-dialog">
        <div class="modal-content ops-cal-modal border-0 shadow-lg overflow-hidden">
            <div class="modal-header ops-cal-modal-header flex-column align-items-stretch gap-2 border-bottom">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="d-flex flex-wrap align-items-center gap-2 min-w-0">
                        <span id="calModalKindBadge" class="badge rounded-pill px-2">Travel</span>
                        <span id="calModalCategoryBadge" class="badge rounded-pill px-2 d-none"></span>
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
                <div id="calModalActionFeedback" class="alert py-2 px-3 mb-0 small d-none" role="status" data-no-autodismiss></div>

                <div class="ops-cal-quick-status rounded-3 p-2 px-3 d-none" id="calModalQuickStatusWrap">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span class="ops-cal-section-label mb-0">
                            <i class="bi bi-lightning-charge text-warning me-1" aria-hidden="true"></i>Ubah Status Cepat
                        </span>
                        <div class="d-flex flex-wrap gap-1" id="calModalQuickStatusBtns"></div>
                    </div>
                </div>

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
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                <div class="ops-cal-section-label mb-0">
                                    <i class="bi bi-truck text-primary me-1" aria-hidden="true"></i>Armada &amp; Sopir
                                </div>
                                <button type="button" id="calModalToggleAssignBtn"
                                        class="btn btn-sm btn-link p-0 text-decoration-none fw-semibold d-none">
                                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Atur Unit
                                </button>
                            </div>
                            <div class="fw-semibold ops-cal-tabular" id="calModalVehicle">-</div>
                            <div class="small text-body-secondary mt-1" id="calModalDriver">-</div>
                            <div id="calModalDriverPhoneWrap" class="mt-auto pt-2"></div>
                        </div>
                    </div>
                </div>

                <div class="ops-cal-quick-assign rounded-3 p-3 d-none" id="calModalQuickAssignBox">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <span class="ops-cal-section-label mb-0">
                            <i class="bi bi-truck-front text-primary me-1" aria-hidden="true"></i>Penugasan Cepat Armada &amp; Sopir
                        </span>
                        <button type="button" id="calModalAssignCancelBtn" class="btn-close btn-sm" aria-label="Tutup penugasan cepat"></button>
                    </div>
                    <div id="calModalAssignFeedback" class="alert py-1 px-2 mb-2 small d-none" role="status" data-no-autodismiss></div>
                    <div id="calModalAssignAvailWarn" class="alert alert-warning py-1 px-2 mb-2 small d-none" role="alert" data-no-autodismiss></div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-sm-6">
                            <label for="calModalAssignVehicle" class="form-label small fw-semibold mb-1">Armada</label>
                            <select id="calModalAssignVehicle" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="calModalAssignDriver" class="form-label small fw-semibold mb-1">Sopir</label>
                            <select id="calModalAssignDriver" class="form-select form-select-sm"></select>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-2 pt-1">
                        <button type="button" id="calModalAssignSaveBtn" class="btn btn-sm btn-primary">
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Simpan Penugasan
                        </button>
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
                    <a href="#" id="calModalManifestBtn" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none">
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>Cetak Surat Jalan
                    </a>
                </div>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                    <a href="#" id="calModalDetailBtn" class="btn btn-sm btn-primary px-3">
                        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Lihat Detail Booking
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
                            <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Pemesanan Tiket Perjalanan
                        </a>
                    <?php endif; ?>
                    <?php if ($canCreateRental): ?>
                        <a href="#" id="calDateCreateRentalBtn" class="btn btn-success btn-sm">
                            <i class="bi bi-car-front me-1" aria-hidden="true"></i>Pemesanan Sewa Kendaraan
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Konfirmasi Pindah Jadwal (Drag & Drop) -->
<div class="modal fade" id="calRescheduleModal" tabindex="-1" aria-labelledby="calReschedTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm ops-cal-resched-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6 fw-bold mb-0" id="calReschedTitle">
                    <i class="bi bi-calendar-event text-primary me-1" aria-hidden="true"></i>Pindahkan Jadwal
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body py-3 d-flex flex-column gap-2">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <span class="badge bg-primary-subtle text-primary-emphasis border ops-cal-tabular" id="calReschedDocBadge">-</span>
                    <span class="small text-body-secondary text-truncate" id="calReschedPassenger">-</span>
                </div>
                <div class="ops-cal-resched-diff rounded-3 p-2 small">
                    <div class="text-body-secondary">Jadwal lama: <strong class="text-body" id="calReschedOldPeriod">-</strong></div>
                    <div class="text-primary mt-1">Jadwal baru: <strong id="calReschedNewPeriod">-</strong></div>
                </div>
                <div>
                    <label for="calReschedTimeInput" class="form-label small fw-semibold mb-1">Jam Keberangkatan</label>
                    <input type="time" id="calReschedTimeInput" class="form-control form-control-sm">
                </div>
                <div>
                    <label for="calReschedReasonInput" class="form-label small fw-semibold mb-1">Catatan Reschedule (opsional)</label>
                    <input type="text" id="calReschedReasonInput" class="form-control form-control-sm" maxlength="200" placeholder="Mis. permintaan pelanggan / rotasi unit">
                </div>
                <div id="calReschedFeedback" class="alert py-1 px-2 mb-0 small d-none" role="alert" data-no-autodismiss></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" id="calReschedCancelBtn" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="calReschedConfirmBtn" class="btn btn-sm btn-primary">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Simpan Jadwal Baru
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const eventsBaseUrl = <?= json_encode($eventsUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const statusUpdateUrl = <?= json_encode($statusUpdateUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const assignUrl = <?= json_encode($assignUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const rescheduleUrl = <?= json_encode($rescheduleUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const availCheckUrl = <?= json_encode($availCheckUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const travelFormBase = <?= json_encode(APP_URL . '/pages/travel/bookings/form.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const rentalFormBase = <?= json_encode(APP_URL . '/pages/rental/bookings/form.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const canCreateAny = <?= $canCreateAny ? 'true' : 'false' ?>;
    const assignVehiclesData = <?= json_encode(array_map(static fn($v) => [
        'id' => (int) $v['id'],
        'company_id' => (int) ($v['company_id'] ?? $cidInt),
        'name' => (string) ($v['name'] ?? $v['vehicle_number'] ?? ''),
        'vehicle_number' => (string) ($v['vehicle_number'] ?? ''),
    ], $vehicleOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const assignDriversData = <?= json_encode(array_map(static fn($d) => [
        'id' => (int) $d['id'],
        'company_id' => (int) ($d['company_id'] ?? $cidInt),
        'name' => (string) ($d['name'] ?? $d['raw_name'] ?? ''),
        'phone' => (string) ($d['phone'] ?? ''),
        'job_position' => (string) ($d['job_position'] ?? ''),
    ], $driverOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    const STORAGE_HIDE_CANCELLED = 'vst_cal_hide_cancelled';
    const allowedViews = ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek', 'listMonth'];
    const allowedGroups = ['regular', 'package', 'carter', 'rental'];
    const allowedAttention = ['', 'on_trip', 'unassigned', 'unpaid'];

    const elQ = document.getElementById('cal-filter-q');
    const elCategory = document.getElementById('cal-filter-category');
    const elStatus = document.getElementById('cal-filter-status');
    const elHideCancelled = document.getElementById('cal-filter-hide-cancelled');
    const elReset = document.getElementById('cal-filter-reset');
    const elCalSkeleton = document.getElementById('cal-skeleton-overlay');
    const elCount = document.getElementById('cal-visible-count');
    const elJumpDate = document.getElementById('cal-jump-date');
    const elErrorBanner = document.getElementById('cal-error-banner');
    const elErrorText = document.getElementById('cal-error-text');
    const elRetryBtn = document.getElementById('cal-retry-btn');
    const elMobileFilterBadge = document.getElementById('cal-mobile-filter-badge');

    let activeCategoryGroup = '';
    let activeAttention = '';
    let lastFetchKey = '';
    let lastFetchedEvents = null;
    let lastHeightMode = null;
    let activeModalProps = null;
    let hoverTimer = null;

    const escHtml = (str) => String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const compactCityName = (name, shortName) => {
        const shortVal = String(shortName || '').trim();
        if (shortVal !== '') return shortVal;
        return String(name || '')
            .trim()
            .replace(/^(?:kota\s+administrasi|kota\s+admin\.?|kota|kabupaten|kab\.?)\s+/i, '');
    };

    const compactRouteLabel = (p) => {
        const orig = compactCityName(p.orig_city, p.orig_city_short);
        const dest = compactCityName(p.dest_city, p.dest_city_short);
        if (orig && dest) return orig + ' → ' + dest;
        if (orig || dest) return orig || dest;
        const fallback = compactCityName(p.route_label, '');
        return (fallback && fallback !== '—') ? fallback : '';
    };

    const isValidYmd = (val) => /^\d{4}-\d{2}-\d{2}$/.test(String(val || ''));

    const formatYmdLocal = (dt) => {
        if (!(dt instanceof Date) || Number.isNaN(dt.getTime())) return '';
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    };

    const addDaysYmd = (ymd, delta) => {
        if (!isValidYmd(ymd)) return ymd;
        const [y, m, d] = ymd.split('-').map(Number);
        const dt = new Date(Date.UTC(y, m - 1, d + delta));
        return dt.toISOString().slice(0, 10);
    };

    const diffDaysInclusive = (startYmd, endYmd) => {
        if (!isValidYmd(startYmd) || !isValidYmd(endYmd)) return 1;
        const [y1, m1, d1] = startYmd.split('-').map(Number);
        const [y2, m2, d2] = endYmd.split('-').map(Number);
        const diff = Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86400000) + 1;
        return Math.max(1, diff);
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

    const categoryBadgeClass = {
        regular: 'badge rounded-pill px-2 bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        package: 'badge rounded-pill px-2 ops-cal-cat-package',
        carter: 'badge rounded-pill px-2 bg-info-subtle text-info-emphasis border border-info-subtle',
        rental: 'badge rounded-pill px-2 bg-success-subtle text-success-emphasis border border-success-subtle'
    };

    const statusIconHtml = (status) => {
        if (status === 'on_trip') return '<span class="fc-vst-live-dot" aria-label="Sedang Jalan"></span>';
        if (status === 'done') return '<i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>';
        if (status === 'draft') return '<i class="bi bi-pencil-square flex-shrink-0" aria-hidden="true"></i>';
        if (status === 'cancelled') return '<i class="bi bi-x-circle flex-shrink-0" aria-hidden="true"></i>';
        return '<i class="bi bi-clock flex-shrink-0" aria-hidden="true"></i>';
    };

    const applyOptionSelected = (el, val) => {
        if (!el || val === '') return;
        const opt = Array.from(el.options).find(o => o.value === val);
        if (opt) el.value = val;
    };

    // Hidrasi state awal dari URL & localStorage sebelum Calendar dirender
    const urlParams = new URLSearchParams(window.location.search);
    const initView = allowedViews.includes(urlParams.get('view'))
        ? urlParams.get('view')
        : (window.matchMedia('(max-width: 767px)').matches ? 'listWeek' : 'dayGridMonth');
    const initDate = isValidYmd(urlParams.get('date')) ? urlParams.get('date') : undefined;

    if (elQ && urlParams.has('q')) elQ.value = urlParams.get('q') || '';
    if (elCategory && urlParams.has('category_id')) applyOptionSelected(elCategory, urlParams.get('category_id') || '');
    if (elStatus && urlParams.has('status')) applyOptionSelected(elStatus, urlParams.get('status') || '');

    const initGroup = String(urlParams.get('category_group') || '').toLowerCase();
    if (allowedGroups.includes(initGroup)) activeCategoryGroup = initGroup;
    const initAttention = String(urlParams.get('attention') || '').toLowerCase();
    if (allowedAttention.includes(initAttention)) activeAttention = initAttention;

    if (elHideCancelled) {
        if (urlParams.has('hide_cancelled')) {
            elHideCancelled.checked = urlParams.get('hide_cancelled') === '1';
        } else {
            let storedHide = null;
            try { storedHide = localStorage.getItem(STORAGE_HIDE_CANCELLED); } catch (e) {}
            elHideCancelled.checked = storedHide !== null ? (storedHide === '1') : true;
        }
        if (elStatus && elStatus.value === 'cancelled') {
            elHideCancelled.checked = false;
        }
    }

    const hasAnyActiveFilter = () => Boolean(
        (elQ && elQ.value.trim() !== '') ||
        (elCategory && elCategory.value !== '') ||
        activeCategoryGroup !== '' ||
        activeAttention !== '' ||
        (elStatus && elStatus.value !== '') ||
        (elHideCancelled && elHideCancelled.checked)
    );

    const updateMobileFilterBadge = () => {
        if (!elMobileFilterBadge) return;
        let cnt = 0;
        if (elCategory && elCategory.value !== '') cnt++;
        if (elStatus && elStatus.value !== '') cnt++;
        if (elHideCancelled && elHideCancelled.checked) cnt++;
        elMobileFilterBadge.textContent = String(cnt);
        elMobileFilterBadge.classList.toggle('d-none', cnt === 0);
    };

    const syncChipAndKpiStates = () => {
        const curStatus = elStatus ? elStatus.value : '';
        document.querySelectorAll('.ops-cal-chip[data-cal-group]').forEach(btn => {
            const grp = btn.getAttribute('data-cal-group') || '';
            const isOn = activeCategoryGroup === grp;
            btn.classList.toggle('active', isOn);
            btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
        });
        document.querySelectorAll('.ops-cal-chip[data-cal-status]').forEach(btn => {
            const st = btn.getAttribute('data-cal-status') || '';
            const isOn = curStatus === st;
            btn.classList.toggle('active', isOn);
            btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
        });
        document.querySelectorAll('.ops-cal-kpi-item[data-kpi]').forEach(btn => {
            const kpi = btn.getAttribute('data-kpi') || 'all';
            const isOn = (kpi === 'all' && activeAttention === '') || (kpi !== 'all' && activeAttention === kpi);
            btn.classList.toggle('active', isOn);
            btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
        });
        updateMobileFilterBadge();
    };

    const applyAttentionFilter = (events) => {
        if (!Array.isArray(events)) return [];
        if (activeAttention === 'on_trip') {
            return events.filter(ev => (ev.extendedProps || {}).status === 'on_trip');
        }
        if (activeAttention === 'unassigned') {
            return events.filter(ev => Boolean((ev.extendedProps || {}).needs_dispatch));
        }
        if (activeAttention === 'unpaid') {
            return events.filter(ev => Boolean((ev.extendedProps || {}).is_unpaid));
        }
        return events;
    };

    const updateKpiStrip = (events) => {
        const list = Array.isArray(events) ? events : [];
        let onTripCnt = 0;
        let unassignedCnt = 0;
        let unpaidCnt = 0;
        list.forEach(ev => {
            const p = ev.extendedProps || {};
            if (p.status === 'on_trip') onTripCnt++;
            if (p.needs_dispatch) unassignedCnt++;
            if (p.is_unpaid) unpaidCnt++;
        });
        const totalEl = document.getElementById('cal-kpi-total-num');
        const tripEl = document.getElementById('cal-kpi-ontrip-num');
        const unasEl = document.getElementById('cal-kpi-unassigned-num');
        const unpdEl = document.getElementById('cal-kpi-unpaid-num');
        if (totalEl) totalEl.textContent = String(list.length);
        if (tripEl) tripEl.textContent = String(onTripCnt);
        if (unasEl) unasEl.textContent = String(unassignedCnt);
        if (unpdEl) unpdEl.textContent = String(unpaidCnt);

        const unasBtn = document.getElementById('cal-kpi-unassigned-btn');
        const unpdBtn = document.getElementById('cal-kpi-unpaid-btn');
        if (unasBtn) unasBtn.classList.toggle('is-warn', unassignedCnt > 0);
        if (unpdBtn) unpdBtn.classList.toggle('is-danger', unpaidCnt > 0);
    };

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

    // Hover Preview Card ringan
    const canHoverDesktop = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches && window.innerWidth >= 768;
    const hoverCard = document.createElement('div');
    hoverCard.id = 'ops-cal-hover-card';
    hoverCard.className = 'ops-cal-hover-card d-none';
    hoverCard.setAttribute('aria-hidden', 'true');
    document.body.appendChild(hoverCard);

    const hideHoverCard = () => {
        clearTimeout(hoverTimer);
        hoverCard.classList.add('d-none');
    };

    const showHoverCard = (fcEvent, targetEl) => {
        if (!canHoverDesktop() || !targetEl || !fcEvent) return;
        const p = fcEvent.extendedProps || {};
        const catChip = p.category_name
            ? '<span class="badge bg-body-secondary text-body border">' + escHtml(p.category_name) + '</span>'
            : '';
        const vehText = p.vehicle_number
            ? [p.vehicle_category, p.vehicle_number].filter(Boolean).join(' · ')
            : 'Armada belum ditentukan';
        const drvText = p.driver || 'Sopir belum ditugaskan';
        const warnRow = (!p.has_vehicle || !p.has_driver)
            ? '<div class="text-warning-emphasis fw-semibold mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>'
              + (!p.has_vehicle && !p.has_driver ? 'Belum ada mobil &amp; sopir' : (!p.has_vehicle ? 'Armada belum ditentukan' : 'Sopir belum ditugaskan'))
              + '</div>'
            : '';
        const payRow = p.status !== 'cancelled'
            ? '<div class="d-flex justify-content-between gap-2 mt-1 pt-1 border-top">'
              + '<span class="text-body-secondary">' + escHtml(p.payment_label || '') + '</span>'
              + '<strong class="' + (p.is_unpaid ? 'text-danger' : 'text-success') + '">'
              + (p.is_unpaid ? ('Sisa ' + escHtml(p.balance_fmt || 'Rp 0')) : escHtml(p.total_price_fmt || 'Rp 0'))
              + '</strong></div>'
            : '';

        hoverCard.innerHTML = ''
            + '<div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">'
            + '<div class="d-flex flex-wrap align-items-center gap-1">'
            + '<span class="badge ' + (p.kind === 'rental' ? 'bg-success' : 'bg-primary') + '">' + escHtml(p.kind_label || 'Travel') + '</span>'
            + catChip
            + '</div>'
            + '<span class="' + (statusBadgeClass[p.status] || 'badge bg-secondary') + '">' + escHtml(p.status_label || '') + '</span>'
            + '</div>'
            + '<div class="fw-bold text-truncate">' + escHtml(p.passenger || '-') + ' <span class="fw-normal text-body-secondary">(' + escHtml(p.document_number || '') + ')</span></div>'
            + '<div class="text-body-secondary mt-1"><i class="bi bi-calendar3 me-1"></i>' + escHtml(p.period_label || '-') + '</div>'
            + (p.route_label && p.route_label !== '—' ? '<div class="text-truncate mt-1"><i class="bi bi-signpost-split me-1 text-primary"></i>' + escHtml(p.route_label) + '</div>' : '')
            + '<div class="text-truncate text-body-secondary mt-1"><i class="bi bi-truck me-1"></i>' + escHtml(vehText) + ' · ' + escHtml(drvText) + '</div>'
            + warnRow
            + payRow;

        hoverCard.classList.remove('d-none');
        const rect = targetEl.getBoundingClientRect();
        const cardW = hoverCard.offsetWidth || 295;
        const cardH = hoverCard.offsetHeight || 160;
        let left = rect.right + 10;
        if (left + cardW > window.innerWidth - 12) {
            left = rect.left - cardW - 10;
        }
        if (left < 12) {
            left = Math.max(12, Math.min(rect.left, window.innerWidth - cardW - 12));
        }
        let top = rect.top;
        if (top + cardH > window.innerHeight - 12) {
            top = Math.max(12, window.innerHeight - cardH - 12);
        }
        hoverCard.style.left = Math.round(left) + 'px';
        hoverCard.style.top = Math.round(top) + 'px';
    };

    const quickTransitionMap = {
        draft: [
            { to: 'scheduled', label: 'Terjadwal', cls: 'btn-primary', icon: 'bi-clock' },
            { to: 'on_trip', label: 'Sedang Jalan', cls: 'btn-warning', icon: 'bi-play-circle' },
            { to: 'cancelled', label: 'Batalkan', cls: 'btn-outline-danger', icon: 'bi-x-circle' }
        ],
        scheduled: [
            { to: 'on_trip', label: 'Sedang Jalan', cls: 'btn-warning', icon: 'bi-play-circle' },
            { to: 'done', label: 'Selesai', cls: 'btn-success', icon: 'bi-check-circle' },
            { to: 'cancelled', label: 'Batalkan', cls: 'btn-outline-danger', icon: 'bi-x-circle' }
        ],
        on_trip: [
            { to: 'done', label: 'Selesai', cls: 'btn-success', icon: 'bi-check-circle' },
            { to: 'scheduled', label: 'Terjadwal', cls: 'btn-outline-primary', icon: 'bi-clock' },
            { to: 'cancelled', label: 'Batalkan', cls: 'btn-outline-danger', icon: 'bi-x-circle' }
        ],
        done: [
            { to: 'scheduled', label: 'Buka Kembali (Terjadwal)', cls: 'btn-outline-primary', icon: 'bi-arrow-counterclockwise' }
        ],
        cancelled: [
            { to: 'scheduled', label: 'Aktifkan (Terjadwal)', cls: 'btn-outline-primary', icon: 'bi-arrow-counterclockwise' }
        ]
    };

    const setModalFeedback = (kind, msg) => {
        const fb = document.getElementById('calModalActionFeedback');
        if (!fb) return;
        if (!msg) {
            fb.classList.add('d-none');
            fb.textContent = '';
            return;
        }
        fb.className = 'alert py-2 px-3 mb-0 small ' + (kind === 'success' ? 'alert-success' : 'alert-danger');
        fb.textContent = msg;
        fb.classList.remove('d-none');
    };

    const renderModalQuickStatusButtons = (props) => {
        const p = props || {};
        const wrap = document.getElementById('calModalQuickStatusWrap');
        const container = document.getElementById('calModalQuickStatusBtns');
        if (!wrap || !container) return;

        if (!p.can_manage || !p.booking_id) {
            wrap.classList.add('d-none');
            container.replaceChildren();
            return;
        }

        const actions = quickTransitionMap[p.status] || quickTransitionMap.scheduled;
        wrap.classList.remove('d-none');
        container.replaceChildren();

        actions.forEach(act => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm ' + act.cls;
            btn.innerHTML = '<i class="bi ' + act.icon + ' me-1" aria-hidden="true"></i>' + escHtml(act.label);
            btn.addEventListener('click', () => handleQuickStatusChange(p, act.to, btn));
            container.appendChild(btn);
        });
    };

    const openEventModal = (fcEvent) => {
        hideHoverCard();
        activeModalProps = Object.assign({}, fcEvent.extendedProps || {});
        const p = activeModalProps;
        const modalEl = document.getElementById('calEventModal');
        if (!modalEl || !window.bootstrap?.Modal) {
            if (fcEvent.url) window.location.href = fcEvent.url;
            return;
        }

        setModalFeedback('', '');

        const kindBadge = document.getElementById('calModalKindBadge');
        kindBadge.textContent = p.kind_label || 'Travel';
        kindBadge.className = p.kind === 'rental'
            ? 'badge rounded-pill px-2 bg-success'
            : 'badge rounded-pill px-2 bg-primary';

        const catBadge = document.getElementById('calModalCategoryBadge');
        if (catBadge) {
            if (p.category_name) {
                catBadge.textContent = p.category_name;
                catBadge.className = categoryBadgeClass[p.service_group] || 'badge rounded-pill px-2 bg-body-secondary text-body border';
                catBadge.classList.remove('d-none');
            } else {
                catBadge.classList.add('d-none');
            }
        }

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
        const vehParts = [];
        [p.vehicle_category, p.brand_name, p.vehicle_number].forEach(val => {
            const s = String(val || '').trim();
            if (s && !vehParts.some(x => x.toLowerCase() === s.toLowerCase())) vehParts.push(s);
        });
        const vehLabel = vehParts.join(' · ');
        vehEl.textContent = vehLabel || 'Armada belum ditentukan';
        vehEl.className = vehLabel
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

        renderModalQuickStatusButtons(p);

        const detailBtn = document.getElementById('calModalDetailBtn');
        detailBtn.href = p.detail_url || fcEvent.url || '#';

        const manifestBtn = document.getElementById('calModalManifestBtn');
        if (p.manifest_url && p.status !== 'cancelled') {
            manifestBtn.href = p.manifest_url;
            manifestBtn.classList.remove('d-none');
        } else {
            manifestBtn.classList.add('d-none');
        }

        setupModalQuickAssign(p);

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    const setAssignFeedback = (kind, msg) => {
        const fb = document.getElementById('calModalAssignFeedback');
        if (!fb) return;
        if (!msg) {
            fb.classList.add('d-none');
            fb.textContent = '';
            return;
        }
        fb.className = 'alert py-1 px-2 mb-2 small ' + (kind === 'success' ? 'alert-success' : 'alert-danger');
        fb.textContent = msg;
        fb.classList.remove('d-none');
    };

    let lastAssignAvailData = null;

    const getAssignVal = (el) => {
        if (!el) return '0';
        const raw = el.tomselect ? el.tomselect.getValue() : el.value;
        return String(raw || '0');
    };

    const ensureQuickAssignTs = (el, placeholder) => {
        if (!el || !window.initTsSelect || !window.TomSelect) return null;
        if (el.tomselect) return el.tomselect;
        return window.initTsSelect(el, {
            allowEmptyOption: true,
            create: false,
            maxOptions: 300,
            dropdownParent: 'body',
            placeholder: placeholder || 'Cari...',
            searchField: ['text', 'plate', 'phone'],
            sortField: [{ field: '$score' }, { field: '$order' }]
        });
    };

    const closeQuickAssignDropdowns = () => {
        ['calModalAssignVehicle', 'calModalAssignDriver'].forEach(id => {
            const el = document.getElementById(id);
            if (el && el.tomselect) el.tomselect.close();
        });
    };

    const syncAssignSelect = (sel, items, selectedVal, placeholder) => {
        if (!sel) return;
        const valStr = String(selectedVal || '0');
        sel.replaceChildren();
        items.forEach(it => {
            const o = document.createElement('option');
            o.value = String(it.value);
            o.textContent = String(it.text || '');
            o.dataset.baseLabel = String(it.baseLabel || it.text || '');
            if (it.plate) o.dataset.plate = String(it.plate);
            if (it.phone) o.dataset.phone = String(it.phone);
            sel.appendChild(o);
        });
        sel.value = valStr;

        const ts = ensureQuickAssignTs(sel, placeholder);
        if (ts) {
            ts.close();
            ts.clear(true);
            ts.clearOptions();
            items.forEach(it => ts.addOption(Object.assign({}, it)));
            ts.refreshOptions(false);
            ts.setValue(valStr, true);
        }
    };

    const refreshAssignConflictBanner = () => {
        const warnEl = document.getElementById('calModalAssignAvailWarn');
        const selV = document.getElementById('calModalAssignVehicle');
        const selD = document.getElementById('calModalAssignDriver');
        if (!warnEl) return;
        const avail = lastAssignAvailData || {};
        const busyV = avail.busy_vehicles || {};
        const busyD = avail.busy_employees || {};
        const vId = getAssignVal(selV);
        const dId = getAssignVal(selD);
        const msgs = [];
        if (vId !== '0' && vId !== '' && busyV[vId]) {
            const m = busyV[vId];
            const what = [m.type_label || m.kind_label || 'jadwal lain', m.doc_no || m.doc || ''].filter(Boolean).join(' ');
            msgs.push('Armada sedang bertugas pada ' + what + (m.period ? ' (' + m.period + ')' : '') + '.');
        }
        if (dId !== '0' && dId !== '' && busyD[dId]) {
            const m = busyD[dId];
            const what = [m.type_label || m.kind_label || 'jadwal lain', m.doc_no || m.doc || ''].filter(Boolean).join(' ');
            msgs.push('Sopir sedang bertugas pada ' + what + (m.period ? ' (' + m.period + ')' : '') + '.');
        }
        if (msgs.length > 0) {
            warnEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>' + msgs.map(escHtml).join(' ');
            warnEl.classList.remove('d-none');
        } else {
            warnEl.classList.add('d-none');
            warnEl.textContent = '';
        }
    };

    const checkQuickAssignAvailability = async (props) => {
        const p = props || {};
        const selV = document.getElementById('calModalAssignVehicle');
        const selD = document.getElementById('calModalAssignDriver');
        lastAssignAvailData = null;
        refreshAssignConflictBanner();
        if (!p.company_id || !p.starts_on || !selV || !selD) return;
        const qs = new URLSearchParams({
            company_id: String(p.company_id),
            booking_type: String(p.kind || 'travel'),
            exclude_booking_id: String(p.booking_id || 0),
            start_date: String(p.starts_on || ''),
            end_date: String(p.ends_on_inclusive || p.starts_on || '')
        });
        if (window.vstLoader && window.vstLoader.setFieldLoading) {
            window.vstLoader.setFieldLoading(selV, true);
            window.vstLoader.setFieldLoading(selD, true);
        }
        try {
            const res = await fetch(availCheckUrl + '?' + qs.toString(), { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok || !activeModalProps || activeModalProps.booking_id !== p.booking_id) return;
            lastAssignAvailData = data;
            const busyV = data.busy_vehicles || {};
            const busyD = data.busy_employees || {};
            const updateBusyOptions = (sel, busyMap) => {
                if (!sel) return;
                if (sel.tomselect) {
                    const ts = sel.tomselect;
                    Object.keys(ts.options || {}).forEach(val => {
                        const opt = ts.options[val];
                        if (!opt) return;
                        const base = opt.baseLabel || opt.text || '';
                        const nextText = (val !== '0' && busyMap[val]) ? (base + ' [Sedang Bertugas]') : base;
                        if (opt.text !== nextText) {
                            ts.updateOption(val, Object.assign({}, opt, { baseLabel: base, text: nextText }));
                        }
                    });
                    ts.refreshOptions(false);
                    ts.refreshItems();
                } else {
                    Array.from(sel.options).forEach(opt => {
                        if (!opt.dataset.baseLabel) opt.dataset.baseLabel = opt.textContent;
                        opt.textContent = (opt.value !== '0' && busyMap[opt.value]) ? (opt.dataset.baseLabel + ' [Sedang Bertugas]') : opt.dataset.baseLabel;
                    });
                }
            };
            updateBusyOptions(selV, busyV);
            updateBusyOptions(selD, busyD);
            refreshAssignConflictBanner();
        } catch (e) {} finally {
            if (window.vstLoader && window.vstLoader.setFieldLoading) {
                window.vstLoader.setFieldLoading(selV, false);
                window.vstLoader.setFieldLoading(selD, false);
            }
        }
    };

    const populateQuickAssignOptions = (props) => {
        const p = props || {};
        const selV = document.getElementById('calModalAssignVehicle');
        const selD = document.getElementById('calModalAssignDriver');
        if (!selV || !selD) return;
        const cid = Number(p.company_id || 0);

        const vehItems = [{ value: '0', text: '— Belum Ada Armada —', baseLabel: '— Belum Ada Armada —', plate: '' }];
        const curVid = Number(p.vehicle_id || 0);
        let foundCurV = curVid === 0;
        assignVehiclesData.forEach(v => {
            if (cid > 0 && Number(v.company_id) !== cid) return;
            const label = v.name || v.vehicle_number || ('Armada #' + v.id);
            if (Number(v.id) === curVid) foundCurV = true;
            vehItems.push({ value: String(v.id), text: label, baseLabel: label, plate: String(v.vehicle_number || '') });
        });
        if (!foundCurV && curVid > 0) {
            const extraLbl = [p.vehicle_number, p.brand_name, p.vehicle_category].filter(Boolean).join(' · ') || ('Armada #' + curVid);
            vehItems.push({ value: String(curVid), text: extraLbl, baseLabel: extraLbl, plate: String(p.vehicle_number || '') });
        }
        syncAssignSelect(selV, vehItems, String(curVid), 'Cari armada / plat nomor...');

        const drvItems = [{ value: '0', text: '— Belum Ada Sopir —', baseLabel: '— Belum Ada Sopir —', phone: '' }];
        const curDid = Number(p.driver_id || 0);
        let foundCurD = curDid === 0;
        assignDriversData.forEach(d => {
            if (cid > 0 && Number(d.company_id) !== cid) return;
            const label = d.name + (d.job_position && d.job_position.toLowerCase() !== 'driver' && d.job_position.toLowerCase() !== 'sopir' ? ' (' + d.job_position + ')' : '');
            if (Number(d.id) === curDid) foundCurD = true;
            drvItems.push({ value: String(d.id), text: label, baseLabel: label, phone: String(d.phone || '') });
        });
        if (!foundCurD && curDid > 0) {
            const extraDLbl = p.driver || ('Sopir #' + curDid);
            drvItems.push({ value: String(curDid), text: extraDLbl, baseLabel: extraDLbl, phone: String(p.driver_phone || '') });
        }
        syncAssignSelect(selD, drvItems, String(curDid), 'Cari sopir / no. HP...');
    };

    function setupModalQuickAssign(props) {
        const p = props || {};
        const toggleBtn = document.getElementById('calModalToggleAssignBtn');
        const box = document.getElementById('calModalQuickAssignBox');
        const editableAssign = !!(p.can_manage && p.booking_id && p.status !== 'cancelled');
        setAssignFeedback('', '');
        lastAssignAvailData = null;
        refreshAssignConflictBanner();
        if (toggleBtn) toggleBtn.classList.toggle('d-none', !editableAssign);
        if (!box) return;
        if (!editableAssign) {
            closeQuickAssignDropdowns();
            box.classList.add('d-none');
            return;
        }
        populateQuickAssignOptions(p);
        if (p.needs_dispatch) {
            box.classList.remove('d-none');
            checkQuickAssignAvailability(p);
        } else {
            closeQuickAssignDropdowns();
            box.classList.add('d-none');
        }
    }

    const formatIndoLongDate = (ymd) => {
        if (!isValidYmd(ymd)) return ymd;
        const dt = new Date(ymd + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return ymd;
        try {
            return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(dt);
        } catch (e) {
            return ymd;
        }
    };

    const openDateCreateModal = (startYmd, endYmd) => {
        if (!canCreateAny || !isValidYmd(startYmd)) return;
        const finalEnd = (isValidYmd(endYmd) && endYmd >= startYmd) ? endYmd : startYmd;
        const modalEl = document.getElementById('calDateCreateModal');
        if (!modalEl || !window.bootstrap?.Modal) return;

        let labelText = formatIndoLongDate(startYmd);
        if (finalEnd > startYmd) {
            labelText = formatIndoLongDate(startYmd) + ' – ' + formatIndoLongDate(finalEnd) + ' (' + diffDaysInclusive(startYmd, finalEnd) + ' hari)';
        }
        document.getElementById('calDateCreateLabel').textContent = labelText;

        const btnTrv = document.getElementById('calDateCreateTravelBtn');
        const btnRnt = document.getElementById('calDateCreateRentalBtn');
        if (btnTrv) btnTrv.href = travelFormBase + '?date=' + encodeURIComponent(startYmd);
        if (btnRnt) {
            btnRnt.href = rentalFormBase + '?date=' + encodeURIComponent(startYmd)
                + (finalEnd > startYmd ? '&end_date=' + encodeURIComponent(finalEnd) : '');
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    let calendar = null;

    const syncUrlState = () => {
        if (!calendar || !window.history?.replaceState) return;
        const params = new URLSearchParams();
        const viewType = calendar.view ? calendar.view.type : initView;
        const centerYmd = formatYmdLocal(calendar.getDate());
        params.set('view', viewType);
        if (centerYmd) params.set('date', centerYmd);

        const qVal = elQ ? elQ.value.trim() : '';
        if (qVal) params.set('q', qVal);
        if (elCategory && elCategory.value !== '') params.set('category_id', elCategory.value);
        if (activeCategoryGroup) params.set('category_group', activeCategoryGroup);
        if (elStatus && elStatus.value !== '') params.set('status', elStatus.value);
        if (elHideCancelled) params.set('hide_cancelled', elHideCancelled.checked ? '1' : '0');
        if (activeAttention) params.set('attention', activeAttention);

        const nextUrl = window.location.pathname + '?' + params.toString();
        window.history.replaceState(null, '', nextUrl);
    };

    calendar = new FullCalendar.Calendar(document.getElementById('ops-calendar'), {
        initialView: initView,
        initialDate: initDate,
        locale: 'id',
        height: initView.startsWith('timeGrid') ? Math.max(540, Math.min(760, window.innerHeight - 230)) : 'auto',
        scrollTime: '06:30:00',
        nowIndicator: true,
        dayMaxEvents: 4,
        editable: true,
        selectable: canCreateAny,
        selectMirror: true,
        unselectAuto: true,
        allDayText: 'Harian',
        noEventsText: 'Tidak ada jadwal pada rentang ini.',
        buttonText: {
            today: 'Hari Ini',
            month: 'Bulan',
            week: 'Minggu',
            day: 'Hari',
            list: 'Daftar'
        },
        views: {
            listWeek: { buttonText: 'Daftar' },
            listMonth: { buttonText: 'Daftar Bulan' }
        },
        moreLinkText: (n) => '+' + n + ' lagi',
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek,listMonth' },
        datesSet: function (info) {
            hideHoverCard();
            const isTimeGrid = info.view && info.view.type && info.view.type.startsWith('timeGrid');
            const targetMode = isTimeGrid ? 'timegrid' : 'auto';
            if (targetMode !== lastHeightMode) {
                lastHeightMode = targetMode;
                calendar.setOption('height', isTimeGrid ? Math.max(540, Math.min(760, window.innerHeight - 230)) : 'auto');
            }
            if (elJumpDate) {
                const jumpYmd = formatYmdLocal(calendar.getDate());
                if (jumpYmd && elJumpDate.value !== jumpYmd) elJumpDate.value = jumpYmd;
            }
            syncUrlState();
        },
        events: function (fetchInfo, successCallback, failureCallback) {
            const params = new URLSearchParams({
                start: fetchInfo.startStr,
                end: fetchInfo.endStr,
                category_id: elCategory ? elCategory.value : '',
                category_group: activeCategoryGroup,
                status: elStatus ? elStatus.value : '',
                hide_cancelled: elHideCancelled && elHideCancelled.checked ? '1' : '0',
                q: elQ ? elQ.value.trim() : ''
            });
            const fetchKey = params.toString();
            if (fetchKey === lastFetchKey && lastFetchedEvents !== null) {
                if (elErrorBanner) elErrorBanner.classList.add('d-none');
                updateKpiStrip(lastFetchedEvents);
                successCallback(applyAttentionFilter(lastFetchedEvents));
                return;
            }
            fetch(eventsBaseUrl + '?' + fetchKey, { credentials: 'same-origin' })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(data => {
                    if (!Array.isArray(data)) throw new Error('Respon kalender tidak valid');
                    lastFetchKey = fetchKey;
                    lastFetchedEvents = data;
                    if (elErrorBanner) elErrorBanner.classList.add('d-none');
                    updateKpiStrip(data);
                    successCallback(applyAttentionFilter(data));
                })
                .catch(err => {
                    console.error(err);
                    if (elErrorText) {
                        elErrorText.textContent = 'Gagal memuat data kalender (' + (err && err.message ? err.message : 'Koneksi terputus') + ').';
                    }
                    if (elErrorBanner) elErrorBanner.classList.remove('d-none');
                    failureCallback(err);
                });
        },
        loading: function (isLoading) {
            if (elCount) elCount.classList.toggle('vst-field-shimmer', !!isLoading);
            if (elCalSkeleton) elCalSkeleton.classList.toggle('d-none', !isLoading);
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
            const showCatTag = p.kind === 'travel' && p.service_group !== 'regular' && (p.category_code || p.service_group_label);
            const catTag = showCatTag
                ? '<span class="fc-vst-cat-tag">' + escHtml(p.category_code || p.service_group_label) + '</span>'
                : '';
            const alertIcons = (p.needs_dispatch || p.is_unpaid)
                ? '<span class="fc-vst-pill-alerts">'
                  + (p.needs_dispatch ? '<span class="fc-vst-alert-warn" title="Armada/Sopir belum lengkap"><i class="bi bi-exclamation-triangle-fill"></i></span>' : '')
                  + (p.is_unpaid ? '<span class="fc-vst-alert-unpaid" title="Belum Lunas">Rp!</span>' : '')
                  + '</span>'
                : '';

            if (viewType.startsWith('list')) {
                const stCls = statusBadgeClass[p.status] || 'badge bg-secondary';
                const pmCls = payBadgeClass[p.payment_status] || 'badge bg-secondary';
                const kindCls = p.kind === 'rental' ? 'badge bg-success' : 'badge bg-primary';
                const listVeh = [p.vehicle_category, p.brand_name, p.vehicle_number]
                    .map(v => String(v || '').trim())
                    .filter((v, i, arr) => v && arr.findIndex(x => x.toLowerCase() === v.toLowerCase()) === i)
                    .join(' · ');
                const subBits = [
                    p.route_label && p.route_label !== '—' ? '<i class="bi bi-geo-alt me-1"></i>' + escHtml(p.route_label) : null,
                    p.category_name ? '<i class="bi bi-tag me-1"></i>' + escHtml(p.category_name) : null,
                    (p.vehicle_number || listVeh) ? '<i class="bi bi-truck me-1"></i>' + escHtml(listVeh) : '<span class="text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i>Tanpa Mobil</span>',
                    p.driver ? '<i class="bi bi-person-badge me-1"></i>' + escHtml(p.driver) : '<span class="text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i>Tanpa Sopir</span>',
                    p.period_label ? '<i class="bi bi-calendar-range me-1"></i>' + escHtml(p.period_label) : null
                ].filter(Boolean);

                const listPrimaryText = p.passenger || '';

                return {
                    html: '<div class="d-flex flex-column gap-1">'
                        + '<div class="d-flex flex-wrap align-items-center gap-1">'
                        + '<span class="' + kindCls + '">' + escHtml(p.kind_label || 'Travel') + '</span>'
                        + (p.category_code ? '<span class="badge bg-body-secondary text-body border">' + escHtml(p.category_code) + '</span>' : '')
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
                const vehText = p.vehicle_number
                    ? escHtml([p.vehicle_category, p.vehicle_number].filter(Boolean).join(' · '))
                    : '<span class="fc-vst-missing-chip">Tanpa Mobil</span>';
                const drvText = p.driver
                    ? escHtml(p.driver)
                    : '<span class="fc-vst-missing-chip">Tanpa Sopir</span>';
                return {
                    html: '<div class="px-1 py-0 overflow-hidden">'
                        + '<div class="fc-vst-pill-inner">'
                        + statusIconHtml(p.status)
                        + (p.starts_at ? '<span class="fc-vst-time">' + escHtml(p.starts_at) + '</span>' : '')
                        + compTag
                        + catTag
                        + '<strong class="text-truncate flex-grow-1">' + escHtml(p.passenger || '') + '</strong>'
                        + alertIcons
                        + '</div>'
                        + '<div class="fc-vst-sub text-truncate">' + vehText + ' · ' + drvText + '</div>'
                        + (p.route_label && p.route_label !== '—' ? '<div class="fc-vst-sub text-truncate">' + escHtml(p.route_label) + '</div>' : '')
                        + '</div>'
                };
            }

            const timeChunk = (p.kind !== 'rental' && p.starts_at)
                ? '<span class="fc-vst-time">' + escHtml(p.starts_at) + '</span>'
                : '';
            const primaryText = p.passenger || '-';
            const routeShort = compactRouteLabel(p);
            const row2Bits = [];
            if (routeShort) {
                row2Bits.push(escHtml(routeShort));
            }
            if (p.vehicle_number) {
                const vehSummary = [p.vehicle_category, p.vehicle_number].filter(Boolean).join(' · ');
                row2Bits.push('<span class="fc-vst-plate">' + escHtml(vehSummary) + '</span>');
            } else {
                row2Bits.push('<span class="fc-vst-missing-chip">Tanpa Mobil</span>');
            }
            if (p.driver) {
                row2Bits.push(escHtml(p.driver));
            } else {
                row2Bits.push('<span class="fc-vst-missing-chip">Tanpa Sopir</span>');
            }
            const row2Html = row2Bits.join(' · ');

            return {
                html: '<div class="fc-vst-pill-stack">'
                    + '<div class="fc-vst-pill-inner">'
                    + statusIconHtml(p.status)
                    + timeChunk
                    + compTag
                    + catTag
                    + '<strong class="text-truncate flex-grow-1">' + escHtml(primaryText) + '</strong>'
                    + alertIcons
                    + '</div>'
                    + (row2Html ? '<div class="fc-vst-pill-route text-truncate">' + row2Html + '</div>' : '')
                    + '</div>'
            };
        },
        eventDidMount: function (info) {
            if (info.event.extendedProps && info.event.extendedProps.details) {
                info.el.setAttribute('aria-label', info.event.extendedProps.details);
                if (!canHoverDesktop()) {
                    info.el.title = info.event.extendedProps.details;
                }
            }
            info.el.addEventListener('mouseenter', function () {
                if (!canHoverDesktop()) return;
                clearTimeout(hoverTimer);
                hoverTimer = setTimeout(() => showHoverCard(info.event, info.el), 140);
            });
            info.el.addEventListener('mouseleave', hideHoverCard);
            if (info.view && info.view.type && info.view.type.startsWith('list')) {
                info.el.querySelectorAll('a[href]').forEach(function (a) {
                    a.removeAttribute('href');
                    a.style.cursor = 'pointer';
                });
                info.el.addEventListener('click', function (e) {
                    if (e.ctrlKey || e.metaKey) {
                        const url = (info.event.extendedProps && info.event.extendedProps.detail_url) || info.event.url;
                        if (url) window.open(url, '_blank', 'noopener');
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    openEventModal(info.event);
                });
            }
        },
        eventClick: function (info) {
            hideHoverCard();
            if (info.jsEvent && (info.jsEvent.ctrlKey || info.jsEvent.metaKey)) {
                return;
            }
            info.jsEvent.preventDefault();
            openEventModal(info.event);
        },
        dateClick: function (info) {
            if (!canCreateAny) return;
            const ymd = String(info.dateStr || '').slice(0, 10);
            openDateCreateModal(ymd, ymd);
        },
        select: function (info) {
            if (!canCreateAny) return;
            const startYmd = String(info.startStr || '').slice(0, 10);
            const endExclYmd = String(info.endStr || '').slice(0, 10);
            const endInclYmd = addDaysYmd(endExclYmd, -1);
            if (isValidYmd(startYmd) && isValidYmd(endInclYmd) && endInclYmd > startYmd) {
                openDateCreateModal(startYmd, endInclYmd);
            }
        },
        eventDrop: function (info) {
            hideHoverCard();
            const p = Object.assign({}, info.event.extendedProps || {});
            if (!p.booking_id || !p.can_manage || p.status === 'done' || p.status === 'cancelled') {
                info.revert();
                return;
            }
            const newStart = formatYmdLocal(info.event.start);
            if (!isValidYmd(newStart)) {
                info.revert();
                return;
            }
            let newEnd = newStart;
            if (p.kind === 'rental') {
                const origSpan = Math.max(1, diffDaysInclusive(p.starts_on, p.ends_on_inclusive || p.starts_on), Number(p.trip_days || 0));
                newEnd = addDaysYmd(newStart, origSpan - 1);
            }
            let newTime = String(p.starts_at || '05:00').slice(0, 5);
            if (!/^\d{2}:\d{2}$/.test(newTime)) newTime = '05:00';
            if (info.view && info.view.type && info.view.type.startsWith('timeGrid') && !info.event.allDay && info.event.start) {
                const hh = String(info.event.start.getHours()).padStart(2, '0');
                const mm = String(info.event.start.getMinutes()).padStart(2, '0');
                newTime = hh + ':' + mm;
            }
            const origEnd = p.kind === 'rental' ? (p.ends_on_inclusive || p.starts_on) : p.starts_on;
            if (newStart === p.starts_on && newEnd === origEnd && newTime === String(p.starts_at || '').slice(0, 5)) {
                info.revert();
                return;
            }
            openRescheduleConfirmModal(info, p, newStart, newEnd, newTime);
        }
    });

    function showCalSuccessBanner(msg) {
        const b = document.getElementById('cal-success-banner');
        const t = document.getElementById('cal-success-text');
        if (!b || !t) return;
        t.textContent = msg || 'Perubahan berhasil disimpan.';
        b.classList.remove('d-none');
    }

    const successCloseBtn = document.getElementById('cal-success-close');
    if (successCloseBtn) {
        successCloseBtn.addEventListener('click', () => {
            const b = document.getElementById('cal-success-banner');
            if (b) b.classList.add('d-none');
        });
    }

    let pendingDrop = null;

    function formatReschedLabel(kind, startYmd, endYmd, timeStr) {
        const sLbl = formatIndoLongDate(startYmd);
        if (kind === 'rental' && endYmd && endYmd > startYmd) {
            return sLbl + ' – ' + formatIndoLongDate(endYmd) + ' · ' + timeStr;
        }
        return sLbl + ' · ' + timeStr;
    }

    function openRescheduleConfirmModal(info, props, newStart, newEnd, newTime) {
        const modalEl = document.getElementById('calRescheduleModal');
        if (!modalEl || !window.bootstrap?.Modal) {
            info.revert();
            return;
        }
        pendingDrop = { info, props, newStart, newEnd, newTime, saved: false };
        document.getElementById('calReschedDocBadge').textContent = props.document_number || '-';
        document.getElementById('calReschedPassenger').textContent = props.passenger || '-';
        document.getElementById('calReschedOldPeriod').textContent = props.period_label || formatReschedLabel(props.kind, props.starts_on, props.ends_on_inclusive, props.starts_at || '05:00');
        document.getElementById('calReschedNewPeriod').textContent = formatReschedLabel(props.kind, newStart, newEnd, newTime);

        const timeInput = document.getElementById('calReschedTimeInput');
        if (timeInput) timeInput.value = newTime;
        const reasonInput = document.getElementById('calReschedReasonInput');
        if (reasonInput) reasonInput.value = '';
        const fb = document.getElementById('calReschedFeedback');
        if (fb) {
            fb.classList.add('d-none');
            fb.textContent = '';
        }
        const confirmBtn = document.getElementById('calReschedConfirmBtn');
        if (confirmBtn) {
            if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                window.vstLoader.clearButtonLoading(confirmBtn);
            }
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Simpan Jadwal Baru';
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    const reschedTimeEl = document.getElementById('calReschedTimeInput');
    if (reschedTimeEl) {
        reschedTimeEl.addEventListener('input', () => {
            if (!pendingDrop) return;
            const v = /^\d{2}:\d{2}$/.test(reschedTimeEl.value) ? reschedTimeEl.value : pendingDrop.newTime;
            pendingDrop.newTime = v;
            document.getElementById('calReschedNewPeriod').textContent = formatReschedLabel(
                pendingDrop.props.kind,
                pendingDrop.newStart,
                pendingDrop.newEnd,
                v
            );
        });
    }

    const reschedModalEl = document.getElementById('calRescheduleModal');
    if (reschedModalEl) {
        reschedModalEl.addEventListener('hidden.bs.modal', () => {
            if (pendingDrop && !pendingDrop.saved && pendingDrop.info && typeof pendingDrop.info.revert === 'function') {
                pendingDrop.info.revert();
            }
            pendingDrop = null;
        });
    }

    const reschedConfirmBtn = document.getElementById('calReschedConfirmBtn');
    if (reschedConfirmBtn) {
        reschedConfirmBtn.addEventListener('click', async () => {
            if (!pendingDrop) return;
            const dropState = pendingDrop;
            const fb = document.getElementById('calReschedFeedback');
            if (fb) {
                fb.classList.add('d-none');
                fb.textContent = '';
            }
            reschedConfirmBtn.disabled = true;
            if (window.vstLoader && window.vstLoader.setButtonLoading) {
                window.vstLoader.setButtonLoading(reschedConfirmBtn, 'Menyimpan…');
            } else {
                reschedConfirmBtn.innerHTML = '<span class="vst-btn-dot me-2" role="status" aria-hidden="true"></span>Menyimpan…';
            }
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('kind', String(dropState.props.kind || ''));
                fd.append('booking_id', String(dropState.props.booking_id || 0));
                fd.append('start_date', dropState.newStart);
                fd.append('end_date', dropState.newEnd);
                const tVal = (reschedTimeEl && /^\d{2}:\d{2}$/.test(reschedTimeEl.value)) ? reschedTimeEl.value : dropState.newTime;
                fd.append('time', tVal);
                const rInput = document.getElementById('calReschedReasonInput');
                if (rInput && rInput.value.trim()) fd.append('reason', rInput.value.trim());

                const res = await fetch(rescheduleUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || ('HTTP ' + res.status));
                }
                dropState.saved = true;
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(reschedConfirmBtn);
                }
                reschedConfirmBtn.disabled = false;
                reschedConfirmBtn.innerHTML = '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Simpan Jadwal Baru';
                if (reschedModalEl) bootstrap.Modal.getOrCreateInstance(reschedModalEl).hide();
                showCalSuccessBanner(data.message || 'Jadwal pemesanan berhasil dipindahkan.');
                lastFetchKey = '';
                lastFetchedEvents = null;
                calendar.refetchEvents();
            } catch (err) {
                if (fb) {
                    fb.className = 'alert alert-danger py-1 px-2 mb-0 small';
                    fb.textContent = err && err.message ? err.message : 'Gagal memindahkan jadwal.';
                    fb.classList.remove('d-none');
                }
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(reschedConfirmBtn);
                }
                reschedConfirmBtn.disabled = false;
                reschedConfirmBtn.innerHTML = '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Simpan Jadwal Baru';
            }
        });
    }

    const assignToggleBtn = document.getElementById('calModalToggleAssignBtn');
    const assignCancelBtn = document.getElementById('calModalAssignCancelBtn');
    const assignVehSel = document.getElementById('calModalAssignVehicle');
    const assignDrvSel = document.getElementById('calModalAssignDriver');
    const assignSaveBtn = document.getElementById('calModalAssignSaveBtn');

    if (assignToggleBtn) {
        assignToggleBtn.addEventListener('click', () => {
            if (!activeModalProps) return;
            const box = document.getElementById('calModalQuickAssignBox');
            if (!box) return;
            const willOpen = box.classList.contains('d-none');
            box.classList.toggle('d-none', !willOpen);
            if (willOpen) {
                setAssignFeedback('', '');
                populateQuickAssignOptions(activeModalProps);
                checkQuickAssignAvailability(activeModalProps);
            } else {
                closeQuickAssignDropdowns();
            }
        });
    }
    if (assignCancelBtn) {
        assignCancelBtn.addEventListener('click', () => {
            closeQuickAssignDropdowns();
            const box = document.getElementById('calModalQuickAssignBox');
            if (box) box.classList.add('d-none');
        });
    }
    const calEventModalEl = document.getElementById('calEventModal');
    if (calEventModalEl) {
        calEventModalEl.addEventListener('hide.bs.modal', closeQuickAssignDropdowns);
        const modalBodyEl = calEventModalEl.querySelector('.modal-body');
        if (modalBodyEl) {
            modalBodyEl.addEventListener('scroll', closeQuickAssignDropdowns, { passive: true });
        }
    }
    if (assignVehSel) assignVehSel.addEventListener('change', refreshAssignConflictBanner);
    if (assignDrvSel) assignDrvSel.addEventListener('change', refreshAssignConflictBanner);

    if (assignSaveBtn) {
        assignSaveBtn.addEventListener('click', async () => {
            if (!activeModalProps) return;
            const p = activeModalProps;
            const vVal = getAssignVal(assignVehSel);
            const dVal = getAssignVal(assignDrvSel);
            const clearDriver = Number(p.driver_id || 0) > 0 && Number(dVal) === 0;
            const origHtml = assignSaveBtn.innerHTML;
            assignSaveBtn.disabled = true;
            if (window.vstLoader && window.vstLoader.setButtonLoading) {
                window.vstLoader.setButtonLoading(assignSaveBtn, 'Menyimpan…');
            } else {
                assignSaveBtn.innerHTML = '<span class="vst-btn-dot me-2" role="status" aria-hidden="true"></span>Menyimpan…';
            }
            setAssignFeedback('', '');

            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('kind', String(p.kind || ''));
                fd.append('booking_id', String(p.booking_id || 0));
                fd.append('vehicle_id', vVal);
                fd.append('driver_id', dVal);
                fd.append('clear_driver', clearDriver ? '1' : '0');

                const res = await fetch(assignUrl, { method: 'POST', credentials: 'same-origin', body: fd });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || ('HTTP ' + res.status));
                }

                p.vehicle_id = Number(data.vehicle_id || 0);
                p.vehicle_number = data.vehicle_number || '';
                p.vehicle_category = data.vehicle_category || '';
                p.brand_name = data.brand_name || '';
                p.driver_id = Number(data.driver_id || 0);
                p.driver = data.driver || '';
                p.driver_phone = data.driver_phone || '';
                p.driver_phone_62 = data.driver_phone_62 || '';
                p.has_vehicle = !!data.has_vehicle;
                p.has_driver = !!data.has_driver;
                p.needs_dispatch = !!data.needs_dispatch;
                p.status = data.status || p.status;
                p.status_label = data.status_label || p.status_label;

                const vehEl = document.getElementById('calModalVehicle');
                const vehParts = [];
                [p.vehicle_category, p.brand_name, p.vehicle_number].forEach(val => {
                    const s = String(val || '').trim();
                    if (s && !vehParts.some(x => x.toLowerCase() === s.toLowerCase())) vehParts.push(s);
                });
                if (vehEl) {
                    const vLbl = vehParts.join(' · ');
                    vehEl.textContent = vLbl || 'Armada belum ditentukan';
                    vehEl.className = vLbl ? 'fw-semibold ops-cal-tabular' : 'small text-body-secondary fst-italic';
                }
                const drvEl = document.getElementById('calModalDriver');
                if (drvEl) drvEl.textContent = p.driver ? ('Sopir: ' + p.driver) : 'Sopir belum ditugaskan';
                const drvPhoneWrap = document.getElementById('calModalDriverPhoneWrap');
                if (drvPhoneWrap) {
                    drvPhoneWrap.replaceChildren();
                    const drvChip = buildWaChip(p.driver_phone, p.driver_phone_62);
                    if (drvChip) drvPhoneWrap.appendChild(drvChip);
                }
                const stBadge = document.getElementById('calModalStatusBadge');
                if (stBadge) {
                    stBadge.textContent = p.status_label || '';
                    stBadge.className = statusBadgeClass[p.status] || 'badge rounded-pill bg-secondary';
                }
                renderModalQuickStatusButtons(p);
                setModalFeedback('success', data.message || 'Penugasan berhasil disimpan.');
                const box = document.getElementById('calModalQuickAssignBox');
                if (box && !p.needs_dispatch) {
                    box.classList.add('d-none');
                }
                lastFetchKey = '';
                lastFetchedEvents = null;
                calendar.refetchEvents();
            } catch (err) {
                setAssignFeedback('error', err && err.message ? err.message : 'Gagal menyimpan penugasan.');
            } finally {
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(assignSaveBtn);
                } else {
                    assignSaveBtn.innerHTML = origHtml;
                }
                assignSaveBtn.disabled = false;
            }
        });
    }

    async function handleQuickStatusChange(props, toStatus, triggerBtn) {
        const p = props || {};
        if (!p.booking_id || !p.kind) return;
        if (toStatus === 'cancelled' && !window.confirm('Yakin ingin membatalkan pemesanan ' + (p.document_number || '') + '?')) {
            return;
        }
        const btns = Array.from(document.querySelectorAll('#calModalQuickStatusBtns button'));
        const origHtml = triggerBtn ? triggerBtn.innerHTML : '';
        btns.forEach(b => { b.disabled = true; });
        if (triggerBtn) {
            if (window.vstLoader && window.vstLoader.setButtonLoading) {
                window.vstLoader.setButtonLoading(triggerBtn, 'Menyimpan…');
            } else {
                triggerBtn.innerHTML = '<span class="vst-btn-dot me-2" role="status" aria-hidden="true"></span>Menyimpan…';
            }
        }
        setModalFeedback('', '');

        try {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('kind', p.kind);
            fd.append('booking_id', String(p.booking_id));
            fd.append('to_status', toStatus);

            const res = await fetch(statusUpdateUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('HTTP ' + res.status));
            }

            const nextStatus = data.status || toStatus;
            const nextLabel = data.status_label || toStatus;
            p.status = nextStatus;
            p.status_label = nextLabel;
            p.needs_dispatch = nextStatus !== 'cancelled' && nextStatus !== 'done' && (!p.has_vehicle || !p.has_driver);
            const stBadge = document.getElementById('calModalStatusBadge');
            if (stBadge) {
                stBadge.textContent = nextLabel;
                stBadge.className = statusBadgeClass[nextStatus] || 'badge rounded-pill bg-secondary';
            }
            const manifestBtn = document.getElementById('calModalManifestBtn');
            if (manifestBtn && p.manifest_url) {
                manifestBtn.classList.toggle('d-none', nextStatus === 'cancelled');
            }
            renderModalQuickStatusButtons(p);
            setModalFeedback('success', data.message || 'Status pemesanan berhasil diperbarui.');
            lastFetchKey = '';
            lastFetchedEvents = null;
            calendar.refetchEvents();
        } catch (err) {
            setModalFeedback('error', err && err.message ? err.message : 'Gagal mengubah status pemesanan.');
            btns.forEach(b => { b.disabled = false; });
            if (triggerBtn) {
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(triggerBtn);
                } else {
                    triggerBtn.innerHTML = origHtml;
                }
            }
        }
    }

    const dateCreateModalEl = document.getElementById('calDateCreateModal');
    if (dateCreateModalEl) {
        dateCreateModalEl.addEventListener('hidden.bs.modal', () => {
            calendar.unselect();
        });
    }

    calendar.render();
    syncChipAndKpiStates();
    updateMobileFilterBadge();

    const triggerRefetch = (invalidateCache = true) => {
        hideHoverCard();
        if (invalidateCache) {
            lastFetchKey = '';
            lastFetchedEvents = null;
        }
        syncChipAndKpiStates();
        syncUrlState();
        calendar.refetchEvents();
    };

    const refetch = () => triggerRefetch(true);

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
            } else if (e.key === 'Escape') {
                if (elQ.value !== '') {
                    elQ.value = '';
                    clearTimeout(qTimer);
                    refetch();
                } else {
                    elQ.blur();
                }
            }
        });
    }

    if (elCategory) {
        elCategory.addEventListener('change', () => {
            activeCategoryGroup = '';
            refetch();
        });
    }

    if (elStatus) {
        elStatus.addEventListener('change', () => {
            if (elStatus.value === 'cancelled' && elHideCancelled && elHideCancelled.checked) {
                elHideCancelled.checked = false;
            }
            if (elStatus.value !== '' && activeAttention === 'on_trip' && elStatus.value !== 'on_trip') {
                activeAttention = '';
            }
            refetch();
        });
    }

    if (elHideCancelled) {
        elHideCancelled.addEventListener('change', () => {
            try { localStorage.setItem(STORAGE_HIDE_CANCELLED, elHideCancelled.checked ? '1' : '0'); } catch (e) {}
            refetch();
        });
    }

    // Unified clickable legend chips
    document.querySelectorAll('.ops-cal-chip[data-cal-group]').forEach(btn => {
        btn.addEventListener('click', () => {
            const grp = btn.getAttribute('data-cal-group') || '';
            activeCategoryGroup = (activeCategoryGroup === grp) ? '' : grp;
            if (elCategory && elCategory.value !== '') {
                elCategory.value = '';
            }
            refetch();
        });
    });

    document.querySelectorAll('.ops-cal-chip[data-cal-status]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!elStatus) return;
            const st = btn.getAttribute('data-cal-status') || '';
            elStatus.value = (elStatus.value === st) ? '' : st;
            if (elStatus.value === 'cancelled' && elHideCancelled && elHideCancelled.checked) {
                elHideCancelled.checked = false;
            }
            refetch();
        });
    });

    // Clickable KPI summary strip (instant client-side attention filtering when cache matches)
    document.querySelectorAll('.ops-cal-kpi-item[data-kpi]').forEach(btn => {
        btn.addEventListener('click', () => {
            const kpi = btn.getAttribute('data-kpi') || 'all';
            const nextAtt = (kpi === 'all' || activeAttention === kpi) ? '' : kpi;
            activeAttention = nextAtt;
            triggerRefetch(false);
        });
    });

    // Jump-to-date control
    if (elJumpDate) {
        elJumpDate.addEventListener('change', () => {
            if (isValidYmd(elJumpDate.value)) {
                calendar.gotoDate(elJumpDate.value);
            }
        });
    }

    if (elRetryBtn) {
        elRetryBtn.addEventListener('click', refetch);
    }

    if (elReset) {
        elReset.addEventListener('click', () => {
            if (elQ) elQ.value = '';
            if (elCategory) elCategory.value = '';
            if (elStatus) elStatus.value = '';
            activeCategoryGroup = '';
            activeAttention = '';
            if (elHideCancelled) {
                elHideCancelled.checked = false;
                try { localStorage.setItem(STORAGE_HIDE_CANCELLED, '0'); } catch (e) {}
            }
            refetch();
        });
    }

    window.addEventListener('scroll', hideHoverCard, { passive: true });
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (document.querySelector('.modal.show')) return;
        const t = e.target;
        const tag = t && t.tagName ? t.tagName.toUpperCase() : '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
        if (document.querySelector('.ts-wrapper.dropdown-active')) return;

        if (e.key === '/' && elQ) {
            e.preventDefault();
            elQ.focus();
            elQ.select();
        } else if (e.key === 't' || e.key === 'T') {
            e.preventDefault();
            calendar.today();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            calendar.prev();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            calendar.next();
        } else if (e.key === 'm' || e.key === 'M') {
            e.preventDefault();
            calendar.changeView('dayGridMonth');
        } else if (e.key === 'w' || e.key === 'W') {
            e.preventDefault();
            calendar.changeView('timeGridWeek');
        } else if (e.key === 'd' || e.key === 'D') {
            e.preventDefault();
            calendar.changeView('timeGridDay');
        } else if (e.key === 'l' || e.key === 'L') {
            e.preventDefault();
            calendar.changeView('listWeek');
        }
    });
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
