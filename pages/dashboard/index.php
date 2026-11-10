<?php
/**
 * Dashboard: ringkasan operasional (booking hari/minggu ini, driver bertugas,
 * piutang jatuh tempo), jumlah karyawan/cabang/perusahaan (scoped tenant),
 * dan 2 grafik ringkas 6 bulan terakhir (booking & pendapatan), murni HTML/CSS.
 */
require_once __DIR__ . '/../../config/config.php';
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

$employees = count_scoped('employees');
$branches  = count_scoped('branches');
$n_companies = count(accessible_companies());
$chart = dashboard_chart();

// Operasional hari ini
$today      = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));
$book_sum   = dash_bookings_summary($today, $week_start, $week_end);
$today_book = $book_sum['today'];
$week_book  = $book_sum['week'];
$drivers    = dash_active_drivers();
$overdue    = dash_overdue_receivables(0, 8);
$expiring_docs = dash_expiring_documents(30, 8);

$active = 'dashboard';
$title  = 'Dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <h4 class="fw-bold mb-4">Dashboard</h4>

    <!-- Operasional hari ini -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-2 text-primary me-3"><i class="bi bi-calendar-day"></i></div>
                    <div>
                        <div class="text-muted small">Booking Hari Ini</div>
                        <div class="fs-4 fw-bold"><?= $today_book['n'] ?></div>
                        <div class="small text-muted"><?= e(rupiah_singkat($today_book['total'])) ?></div>
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
                        <div class="small text-muted"><?= e(rupiah_singkat($week_book['total'])) ?></div>
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
                        <div class="small text-muted">sedang jalan</div>
                    </div>
                </div>
            </div>
        </div>
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
                        <div class="small text-muted"><?= count($overdue['rows']) ?> invoice</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver bertugas + piutang jatuh tempo -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-truck me-1 text-success"></i> Driver Sedang Bertugas</span>
                    <a href="<?= e(APP_URL) ?>/pages/dispatch/index.php" class="small text-decoration-none">Semua →</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light"><tr>
                                <th>Driver</th><th>Kendaraan</th><th>Booking</th><th>Mulai</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($drivers as $d): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= e($d['driver_name']) ?></div>
                                        <div class="text-muted" style="font-size:.75rem"><?= e($d['code']) ?></div>
                                    </td>
                                    <td class="small"><?= e($d['vehicle_number'] ?? '—') ?></td>
                                    <td class="small">
                                        <span class="badge bg-secondary text-uppercase" style="font-size:.65rem"><?= e($d['booking_type']) ?></span>
                                        <?= e($d['document_number'] ?? '—') ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('d/m H:i', strtotime($d['started_at']))) ?></td>
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

        <div class="col-12 col-xl-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-exclamation-triangle me-1 text-danger"></i> Piutang Jatuh Tempo</span>
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
                                        <?= e($o['invoice_no']) ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('d/m/Y', strtotime($o['booking_date']))) ?></td>
                                    <td class="small text-end text-danger fw-semibold"><?= e(rupiah_singkat((float) $o['sisa'])) ?></td>
                                    <td class="small text-end"><?= (int) $o['umur_hari'] ?> hr</td>
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
    </div>

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
                    <div class="small text-muted">Pantauan berkas operasional</div>
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
                                ?>
                                <tr>
                                    <td class="small fw-semibold"><?= e($doc['doc_type']) ?></td>
                                    <td class="small">
                                        <span class="badge bg-secondary-subtle text-dark border me-1"><?= $doc['ref_type'] === 'vehicle' ? 'Armada' : 'Driver' ?></span>
                                        <?= e($doc['title']) ?>
                                    </td>
                                    <td class="small text-muted"><?= e(date('d/m/Y', strtotime($doc['due_date']))) ?></td>
                                    <td class="small"><span class="badge <?= $badge_class ?>"><?= e($status_text) ?></span></td>
                                    <td class="small text-end">
                                        <a href="<?= e(APP_URL) ?>/<?= e($doc['url_prefix']) ?><?= (int) $doc['ref_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">Lihat Detail →</a>
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

    <!-- Statistik dasar -->
    <h6 class="text-muted text-uppercase small fw-semibold mb-2">Ringkasan Data</h6>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="text-muted small">Karyawan</div>
                        <div class="fs-3 fw-bold"><?= e((string) $employees) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
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
        <div class="col-12 col-md-4">
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
        ['key' => 'revenue',  'title' => 'Pendapatan per Bulan', 'style' => 'bg-success'],
    ];
    ?>
    <div class="row g-3 mt-1">
        <?php foreach ($bars as $b):
            $vals = $chart[$b['key']];
            $max  = max($vals) ?: 0;
            $fmt  = $b['key'] === 'revenue'
                ? static fn(float $v): string => rupiah_singkat($v)
                : static fn(float $v): string => number_format($v, 0, ',', '.');
        ?>
        <div class="col-12 col-lg-6">
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
