<?php
/**
 * Dashboard: ringkasan jumlah karyawan, cabang, perusahaan (scoped tenant)
 * + 2 grafik ringkas 6 bulan terakhir (booking & pendapatan), murni HTML/CSS.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_chart.php';
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

$active = 'dashboard';
$title  = 'Dashboard';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-4">Dashboard</h4>
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
