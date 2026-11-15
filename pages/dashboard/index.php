<?php
/**
 * Dashboard: ringkasan operasional (booking hari/minggu ini, ringkasan 5 status operasional,
 * pemesanan aktif/mendatang, driver bertugas, piutang jatuh tempo, peringatan STNK/KIR/SIM H-30),
 * jumlah karyawan aktif/armada aktif/cabang/perusahaan (scoped tenant),
 * dan grafik 6 bulan terakhir murni HTML/CSS.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/_chart.php';
require_once __DIR__ . '/_ops.php';
require_login();

/** Hitung baris pada tabel company-scoped memakai company_scope(). */
function count_scoped(string $table): int
{
    [$where, $types, $params] = company_scope('company_id');
    $sql = "SELECT COUNT(*) AS n FROM `{$table}` WHERE {$where}";
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

/** Rupiah singkat: 12500000 → "Rp 12,5 jt". */
function rupiah_singkat(float $n): string
{
    foreach ([[1e9, 'M'], [1e6, 'jt'], [1e3, 'rb']] as [$div, $suf]) {
        if (abs($n) >= $div) {
            return 'Rp ' . number_format($n / $div, ($n / $div) < 100 ? 1 : 0, ',', '.') . ' ' . $suf;
        }
    }
    return 'Rp ' . number_format($n, 0, ',', '.');
}

$canTravel    = can('travel.view');
$canRental    = can('rental.view');
$canBookings  = $canTravel || $canRental;
$canDispatch  = can('dispatch.view');
$canInvoices  = can('invoices.view');
$canFinance   = can('finance.view') || can('reports.view') || $canInvoices;
$canVehicles  = can('vehicles.view');
$canEmployees = can('employees.view');

$counts      = dash_master_counts();
$employees   = $counts['employees'];
$vehicles    = $counts['vehicles'];
$branches    = $counts['branches'];
$n_companies = count(accessible_companies());

// Operasional & grafik dalam 1x pass UNION ALL
$today      = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));
$chart_data = dashboard_chart_with_summary($today, $week_start, $week_end);
$chart      = $chart_data['chart'];
$today_book = $chart_data['summary']['today'];
$week_book  = $chart_data['summary']['week'];

$status_counts = $canBookings ? dash_status_counts($canTravel, $canRental) : [];
$upcoming      = $canBookings ? dash_upcoming_bookings(6, $canTravel, $canRental) : [];
$drivers       = ($canDispatch || $canBookings) ? dash_active_drivers() : [];
$overdue       = $canInvoices ? dash_overdue_receivables(0, 8) : ['rows' => [], 'total' => 0.0, 'count' => 0];
$expiring_docs = ($canVehicles || $canEmployees)
    ? dash_expiring_documents(30, 8, $canVehicles, $canEmployees)
    : ['items' => [], 'total_expired' => 0, 'total_warning' => 0, 'total' => 0, 'total_all' => 0];

$dash_actions = [];
if ($canDispatch) {
    $dash_actions[] = ['label' => 'Kalender', 'icon' => 'calendar2-check', 'href' => 'pages/dispatch/calendar.php', 'class' => 'btn btn-outline-secondary btn-sm'];
}
if (can('travel.manage') && active_company_id() !== null) {
    $dash_actions[] = ['label' => 'Tiket Travel', 'icon' => 'plus-lg', 'href' => 'pages/travel/bookings/form.php', 'class' => 'btn btn-primary btn-sm'];
}
if (can('rental.manage') && active_company_id() !== null) {
    $dash_actions[] = ['label' => 'Sewa Unit', 'icon' => 'plus-lg', 'href' => 'pages/rental/bookings/form.php', 'class' => 'btn btn-outline-primary btn-sm'];
}

$active = 'dashboard';
$title  = 'Dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Dashboard',
        ['Dashboard' => null],
        [],
        $dash_actions
    ) ?>

    <!-- Operasional hari ini -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 text-primary me-3"><i class="bi bi-calendar-day"></i></div>
                    <div>
                        <div class="text-muted small">Booking Hari Ini</div>
                        <div class="fs-4 fw-bold"><?= $today_book['n'] ?></div>
                        <?php if ($canFinance): ?>
                            <div class="small text-muted"><?= e(rupiah_singkat($today_book['total'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 text-info me-3"><i class="bi bi-calendar-week"></i></div>
                    <div>
                        <div class="text-muted small">Booking Minggu Ini</div>
                        <div class="fs-4 fw-bold"><?= $week_book['n'] ?></div>
                        <?php if ($canFinance): ?>
                            <div class="small text-muted"><?= e(rupiah_singkat($week_book['total'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 text-success me-3"><i class="bi bi-truck"></i></div>
                    <div>
                        <div class="text-muted small">Driver Bertugas</div>
                        <div class="fs-4 fw-bold"><?= count($drivers) ?></div>
                        <div class="small text-muted">penugasan aktif</div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($canInvoices): ?>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100 <?= $overdue['total'] > 0 ? 'border-start border-danger border-3' : '' ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 <?= $overdue['total'] > 0 ? 'text-danger' : 'text-secondary' ?> me-3">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Piutang Jatuh Tempo</div>
                        <div class="fs-6 fw-bold <?= $overdue['total'] > 0 ? 'text-danger' : '' ?>">
                            <?= e(rupiah_singkat($overdue['total'])) ?>
                        </div>
                        <div class="small text-muted"><?= (int) $overdue['count'] ?> invoice</div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 text-secondary me-3"><i class="bi bi-car-front"></i></div>
                    <div>
                        <div class="text-muted small">Armada Aktif</div>
                        <div class="fs-4 fw-bold"><?= $vehicles ?></div>
                        <div class="small text-muted">unit siap operasi</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($canBookings && $status_counts):
        $statusFilterTarget = $canTravel ? 'pages/travel/bookings/index.php' : 'pages/rental/bookings/index.php';
        $statusPalette = [
            'draft'     => 'border-secondary-subtle text-secondary',
            'scheduled' => 'border-primary-subtle text-primary',
            'on_trip'   => 'border-warning text-warning-emphasis',
            'done'      => 'border-success-subtle text-success',
            'cancelled' => 'border-danger-subtle text-danger',
        ];
    ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="small fw-semibold text-muted text-uppercase">Status Pemesanan</span>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php foreach (booking_status_options() as $sk => $sl):
                    $cnt = (int) ($status_counts[$sk] ?? 0);
                    $cls = $statusPalette[$sk] ?? 'border-secondary-subtle text-secondary';
                ?>
                    <a href="<?= e(APP_URL . '/' . $statusFilterTarget . '?status=' . urlencode($sk)) ?>"
                       class="badge rounded-pill bg-body border <?= e($cls) ?> text-decoration-none px-3 py-2 fw-medium">
                        <?= e($sl) ?>: <strong><?= number_format($cnt, 0, ',', '.') ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Driver bertugas + piutang jatuh tempo -->
    <div class="row g-3 mb-3">
        <div class="col-12 <?= $canInvoices ? 'col-xl-6' : '' ?>">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-truck me-1 text-success"></i> Driver Sedang Bertugas</span>
                    <?php if ($canDispatch): ?>
                        <a href="<?= e(APP_URL) ?>/pages/dispatch/calendar.php" class="small text-decoration-none">Kalender →</a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light"><tr>
                                <th>Driver</th><th>Kendaraan</th><th>Booking</th><th>Mulai</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($drivers as $d):
                                $bkType = ($d['booking_type'] ?? 'travel') === 'rental' ? 'rental' : 'travel';
                                $canViewBk = $bkType === 'travel' ? $canTravel : $canRental;
                                $bkUrl = APP_URL . '/pages/' . $bkType . '/bookings/detail.php?id=' . (int) $d['booking_id'];
                                $dPhone = trim((string) ($d['driver_phone'] ?? ''));
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= e($d['driver_name']) ?></div>
                                        <div class="text-muted" style="font-size:.75rem">
                                            <?= e($dPhone !== '' ? $dPhone : ($d['code'] ?: '-')) ?>
                                        </div>
                                    </td>
                                    <td class="small text-nowrap"><?= e($d['vehicle_number'] ?: '—') ?></td>
                                    <td class="small">
                                        <div class="d-flex flex-wrap align-items-center gap-1">
                                            <span class="badge bg-secondary text-uppercase" style="font-size:.65rem"><?= e($bkType) ?></span>
                                            <?php if ($canViewBk): ?>
                                                <a class="fw-semibold text-decoration-none" href="<?= e($bkUrl) ?>"><?= e($d['document_number'] ?: '—') ?></a>
                                            <?php else: ?>
                                                <span><?= e($d['document_number'] ?: '—') ?></span>
                                            <?php endif; ?>
                                            <?= status_badge((string) ($d['booking_status'] ?? 'scheduled')) ?>
                                        </div>
                                    </td>
                                    <td class="small text-muted text-nowrap"><?= e(date('d/m H:i', strtotime($d['started_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$drivers): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada driver yang bertugas saat ini.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($canInvoices): ?>
        <div class="col-12 col-xl-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>
                        <i class="bi bi-exclamation-triangle me-1 text-danger"></i> Piutang Jatuh Tempo
                        <?php if ($overdue['count'] > count($overdue['rows'])): ?>
                            <span class="small text-muted fw-normal ms-1">(<?= count($overdue['rows']) ?> dari <?= (int) $overdue['count'] ?>)</span>
                        <?php endif; ?>
                    </span>
                    <a href="<?= e(APP_URL) ?>/pages/finance/invoices/index.php" class="small text-decoration-none">Semua →</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light"><tr>
                                <th>Invoice</th><th>Tanggal</th><th class="text-end">Sisa</th><th class="text-end">Umur</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($overdue['rows'] as $o): ?>
                                <tr>
                                    <td class="small">
                                        <span class="badge bg-secondary text-uppercase" style="font-size:.6rem"><?= e($o['booking_type']) ?></span>
                                        <a class="fw-semibold text-decoration-none" href="<?= e(APP_URL) ?>/pages/finance/invoices/detail.php?id=<?= (int) $o['invoice_id'] ?>">
                                            <?= e($o['invoice_no']) ?>
                                        </a>
                                    </td>
                                    <td class="small text-muted text-nowrap"><?= e(date('d/m/Y', strtotime($o['booking_date']))) ?></td>
                                    <td class="small text-end text-danger fw-semibold text-nowrap"><?= e(rupiah_singkat((float) $o['sisa'])) ?></td>
                                    <td class="small text-end text-nowrap"><?= (int) $o['umur_hari'] ?> hr</td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$overdue['rows']): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">
                                    <i class="bi bi-check-circle text-success me-1"></i>Tidak ada piutang jatuh tempo.
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($canBookings): ?>
    <!-- Pemesanan Aktif & Perlu Tindak Lanjut -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-journal-check me-1 text-primary"></i> Pemesanan Aktif &amp; Terjadwal</span>
                    <div class="d-flex gap-3 small">
                        <?php if ($canTravel): ?>
                            <a href="<?= e(APP_URL) ?>/pages/travel/bookings/index.php" class="text-decoration-none">Travel →</a>
                        <?php endif; ?>
                        <?php if ($canRental): ?>
                            <a href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php" class="text-decoration-none">Sewa →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Resi</th>
                                    <th>Penumpang</th>
                                    <th>Armada</th>
                                    <th>Sopir</th>
                                    <th>Jadwal &amp; Rute</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($upcoming as $ub):
                                $ubType = $ub['booking_type'] === 'rental' ? 'rental' : 'travel';
                                $ubUrl  = APP_URL . '/pages/' . $ubType . '/bookings/detail.php?id=' . (int) $ub['id'];
                                $ubJam  = !empty($ub['time']) ? substr((string) $ub['time'], 0, 5) : '-';
                                $ubTgl  = !empty($ub['trip_date']) ? date('d-M-Y', strtotime((string) $ub['trip_date'])) : '-';
                            ?>
                                <tr>
                                    <td class="small text-nowrap">
                                        <span class="badge bg-body-secondary text-body border text-uppercase me-1" style="font-size:.65rem"><?= e($ubType) ?></span>
                                        <a class="fw-semibold text-decoration-none" href="<?= e($ubUrl) ?>"><?= e($ub['document_number']) ?></a>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-body"><?= e($ub['passenger_name']) ?></div>
                                        <div class="text-muted" style="font-size:0.75rem"><?= e($ub['passenger_phone'] ?: '-') ?></div>
                                    </td>
                                    <td class="small text-nowrap"><?= e($ub['vehicle_number'] ?: '-') ?></td>
                                    <td><?= booking_driver_cell_html($ub['driver_summary'] ?? null) ?></td>
                                    <td class="small">
                                        <div class="text-nowrap"><?= e($ubTgl) ?> · <?= e($ubJam) ?></div>
                                        <div class="text-muted" style="font-size:0.75rem"><?= e($ub['origin_city']) ?> → <?= e($ub['dest_city']) ?></div>
                                    </td>
                                    <td class="text-center text-nowrap"><?= status_badge((string) $ub['st']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$upcoming): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Belum ada pemesanan aktif (Draft, Terjadwal, atau Sedang Jalan).</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canVehicles || $canEmployees): ?>
    <!-- Peringatan Jatuh Tempo Dokumen & SIM Driver (H-30) -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <i class="bi bi-shield-exclamation me-1 <?= $expiring_docs['total_expired'] > 0 ? 'text-danger' : 'text-warning' ?>"></i>
                        <span>Peringatan Dokumen &amp; Legalitas (STNK, KIR, Pajak &amp; SIM Driver)</span>
                        <?php if ($expiring_docs['total_expired'] > 0): ?>
                            <span class="badge bg-danger ms-2"><?= $expiring_docs['total_expired'] ?> Kedaluwarsa</span>
                        <?php endif; ?>
                        <?php if ($expiring_docs['total_warning'] > 0): ?>
                            <span class="badge bg-warning text-dark ms-1"><?= $expiring_docs['total_warning'] ?> Segera Habis (&le; 30 Hari)</span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                        <?= $expiring_docs['total_all'] > $expiring_docs['total']
                            ? 'Menampilkan ' . (int) $expiring_docs['total'] . ' dari ' . (int) $expiring_docs['total_all'] . ' berkas'
                            : 'Pantauan berkas operasional' ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Tipe Dokumen</th>
                                    <th>Unit / Karyawan</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Sisa Waktu</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($expiring_docs['items'] as $doc): ?>
                                <?php
                                    $dl = (int) $doc['days_left'];
                                    if ($dl < 0) {
                                        $badge_class = 'bg-danger';
                                        $status_text = 'Lewat ' . abs($dl) . ' hari';
                                    } elseif ($dl === 0) {
                                        $badge_class = 'bg-danger';
                                        $status_text = 'Hari Ini';
                                    } else {
                                        $badge_class = 'bg-warning text-dark';
                                        $status_text = 'Sisa ' . $dl . ' hari';
                                    }
                                    $canDocDetail = ($doc['ref_type'] === 'vehicle' && $canVehicles)
                                        || ($doc['ref_type'] === 'employee' && $canEmployees);
                                ?>
                                <tr>
                                    <td class="small fw-semibold"><?= e($doc['doc_type']) ?></td>
                                    <td class="small">
                                        <span class="badge bg-secondary-subtle text-body border me-1"><?= $doc['ref_type'] === 'vehicle' ? 'Armada' : 'Driver' ?></span>
                                        <?= e($doc['title']) ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('d/m/Y', strtotime($doc['due_date']))) ?></td>
                                    <td class="small"><span class="badge <?= $badge_class ?>"><?= e($status_text) ?></span></td>
                                    <td class="small text-end text-nowrap">
                                        <?php if ($canDocDetail): ?>
                                            <a href="<?= e(APP_URL) ?>/<?= e($doc['url_prefix']) ?><?= (int) $doc['ref_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Lihat Detail" aria-label="Lihat Detail"><i class="bi bi-eye"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$expiring_docs['items']): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        <i class="bi bi-check-circle text-success me-1"></i>Semua STNK, KIR, Pajak Kendaraan, dan SIM Driver dalam kondisi aman (tidak ada yang kedaluwarsa dalam 30 hari ke depan).
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistik dasar -->
    <h6 class="text-muted text-uppercase small fw-semibold mb-2">Ringkasan Data</h6>
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="text-muted small">Karyawan Aktif</div>
                        <div class="fs-3 fw-bold"><?= e((string) $employees) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-info me-3"><i class="bi bi-truck"></i></div>
                    <div>
                        <div class="text-muted small">Armada Aktif</div>
                        <div class="fs-3 fw-bold"><?= e((string) $vehicles) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-success me-3"><i class="bi bi-diagram-3"></i></div>
                    <div>
                        <div class="text-muted small">Cabang</div>
                        <div class="fs-3 fw-bold"><?= e((string) $branches) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-warning me-3"><i class="bi bi-building"></i></div>
                    <div>
                        <div class="text-muted small">Perusahaan</div>
                        <div class="fs-3 fw-bold"><?= e((string) $n_companies) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Grafik batang ringkas: lebar batang = persen dari nilai maksimum seri.
    // Murni div/CSS — tanpa CDN/SVG eksternal, aman di shared hosting.
    $bars = [
        ['key' => 'bookings', 'title' => 'Booking per Bulan', 'style' => 'bg-primary'],
    ];
    if ($canFinance) {
        $bars[] = ['key' => 'revenue', 'title' => 'Pendapatan per Bulan', 'style' => 'bg-success'];
    }
    $barColClass = count($bars) > 1 ? 'col-12 col-lg-6' : 'col-12';
    ?>
    <div class="row g-3 mt-1">
        <?php foreach ($bars as $b):
            $vals = $chart[$b['key']];
            $max  = max($vals) ?: 0;
            $fmt  = $b['key'] === 'revenue'
                ? static fn(float $v): string => rupiah_singkat($v)
                : static fn(float $v): string => number_format($v, 0, ',', '.');
        ?>
        <div class="<?= e($barColClass) ?>">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold"><?= e($b['title']) ?></div>
                <div class="card-body">
                    <?php foreach ($vals as $i => $v): $pct = $max > 0 ? ((float) $v / $max * 100) : 0; ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="text-muted small flex-shrink-0" style="width:5.5rem">
                                <?= e($chart['labels'][$i]) ?>
                            </div>
                            <div class="flex-grow-1 bg-body-secondary rounded"
                                 style="height:1.25rem; overflow:hidden">
                                <div class="<?= e($b['style']) ?> rounded"
                                     style="width:<?= e(number_format($pct, 1, '.', '')) ?>%; height:100%; min-width:<?= $v > 0 ? '4px' : '0' ?>"></div>
                            </div>
                            <div class="small text-end flex-shrink-0" style="width:7rem">
                                <?= e($fmt((float) $v)) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($max <= 0): ?>
                        <div class="text-muted small text-center py-2">Belum ada data 6 bulan terakhir.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
