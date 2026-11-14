<?php
/**
 * Laporan Keuangan: arus uang per periode — pendapatan (travel/rental),
 * terbayar, piutang, rincian tagihan belum lunas, biaya operasional per jenis,
 * dan laba kotor. Filter from/to/booking_type seperti Laporan Booking.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('finance.view'); // laporan keuangan memuat pendapatan/biaya/laba — perlu izin Keuangan
require_once __DIR__ . '/_finance_query.php';

$f = report_filter($_GET);
$data = fin_all($f);

/** URL export dengan filter aktif (halaman ini memakai finance_export.php). */
$export_qs = http_build_query([
    'format'       => 'csv',
    'from'         => $f['from'],
    'to'           => $f['to'],
    'booking_type' => $f['booking_type'],
]);
$xlsx_qs = str_replace('format=csv', 'format=xlsx', $export_qs);

$rev   = $data['revenue'];
$costs = $data['cost_types'];
$ct    = fin_cost_totals($costs);
$unpaid = $data['unpaid'];
$ut     = fin_unpaid_totals($unpaid);
$mo     = $data['monthly'];

$cards = [
    ['Laba Kotor',   rupiah($data['gross_profit']), $data['gross_profit'] >= 0 ? 'success' : 'danger', 'graph-up-arrow'],
    ['Pendapatan',   rupiah($rev['total']),         'primary', 'cash-stack'],
    ['Biaya Operasional', rupiah($data['cost_total']), 'warning', 'receipt-cutoff'],
    ['Piutang',      rupiah($rev['receivable']),    'info', 'hourglass-split'],
];

$active = 'reports';
$title  = 'Laporan Keuangan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Laporan Keuangan</h4>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-success" href="finance_export.php?<?= e($export_qs) ?>">
                <i class="bi bi-filetype-csv"></i> CSV</a>
            <a class="btn btn-success" href="finance_export.php?<?= e($xlsx_qs) ?>">
                <i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="btn btn-danger" href="finance_export.php?<?= e($export_qs) ?>&format=pdf">
                <i class="bi bi-filetype-pdf"></i> PDF</a>
            <a class="btn btn-outline-secondary" href="finance_export.php?<?= e($export_qs) ?>&format=html&print=1" target="_blank">
                <i class="bi bi-printer"></i> Cetak</a>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Jenis Booking</label>
                    <select class="form-select form-select-sm" name="booking_type">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/reports/finance.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['booking_type'] !== 'all')) ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ($cards as [$lbl, $val, $clr, $icon]): ?>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small"><?= e($lbl) ?></div>
                            <div class="fs-5 fw-bold text-<?= e($clr) ?>"><?= e($val) ?></div>
                        </div>
                        <i class="bi bi-<?= e($icon) ?> fs-3 text-<?= e($clr) ?> opacity-50"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-bar-chart-line me-1 text-primary"></i>Tren Pendapatan vs Biaya</span>
            <span class="small text-muted"><?= e($f['from']) ?> s/d <?= e($f['to']) ?></span>
        </div>
        <div class="card-body">
            <?php if (count($mo['labels']) < 2): ?>
                <div class="text-center text-muted py-4">
                    Grafik butuh rentang minimal 2 bulan. Perluas filter tanggal.
                </div>
            <?php else: ?>
                <canvas id="finTrend" height="260"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Pendapatan per Jenis Booking</div>
                <div class="card-body">
                    <canvas id="finByType" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Biaya Operasional per Jenis</div>
                <div class="card-body">
                    <?php if (!$costs): ?>
                        <div class="text-center text-muted py-4">Tidak ada biaya pada periode ini.</div>
                    <?php else: ?>
                        <canvas id="finCost" height="220"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Pendapatan per Jenis Booking</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Jenis</th><th class="text-end">Pendapatan</th><th class="text-end">Terbayar</th><th class="text-end">Piutang</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Travel</td>
                        <td class="text-end"><?= e(rupiah($rev['travel'])) ?></td>
                        <td class="text-end text-muted" colspan="2">—</td>
                    </tr>
                    <tr>
                        <td>Rental</td>
                        <td class="text-end"><?= e(rupiah($rev['rental'])) ?></td>
                        <td class="text-end text-muted" colspan="2">—</td>
                    </tr>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= e(rupiah($rev['total'])) ?></td>
                        <td class="text-end"><?= e(rupiah($rev['paid'])) ?></td>
                        <td class="text-end"><?= e(rupiah($rev['receivable'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Biaya Operasional per Jenis</span>
            <span class="small text-muted">Total <?= e(rupiah($ct['amount'])) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Jenis Biaya</th><th class="text-end">Baris</th><th class="text-end">Jumlah</th></tr></thead>
                <tbody>
                <?php foreach ($costs as $r): ?>
                    <tr>
                        <td><?= e((string) $r['cost_type']) ?></td>
                        <td class="text-end"><?= (int) $r['n_lines'] ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$costs): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada biaya pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= (int) $ct['n_lines'] ?></td>
                        <td class="text-end"><?= e(rupiah($ct['amount'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Tagihan Belum Lunas</span>
            <span class="small text-muted">
                Total sisa <?= e(rupiah($ut['outstanding'])) ?>
                <?php if (count($unpaid) >= 200): ?>(200 terbesar; Excel memuat semua)<?php endif; ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>No. Dokumen</th><th>Tanggal</th><th>Jenis</th>
                    <th class="text-end">Nilai</th><th class="text-end">Terbayar</th><th class="text-end">Sisa</th>
                </tr></thead>
                <tbody>
                <?php foreach ($unpaid as $r): ?>
                    <tr>
                        <td><?= e((string) $r['document_number']) ?></td>
                        <td><?= e(tanggal_indo((string) $r['date'])) ?></td>
                        <td><?= e(ucfirst((string) $r['booking_type'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_price'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['total_payment'])) ?></td>
                        <td class="text-end fw-semibold text-danger"><?= e(rupiah((float) $r['outstanding'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$unpaid): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Semua tagihan lunas pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($unpaid): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="3">Total</td>
                        <td class="text-end"><?= e(rupiah($ut['price'])) ?></td>
                        <td class="text-end"><?= e(rupiah($ut['paid'])) ?></td>
                        <td class="text-end"><?= e(rupiah($ut['outstanding'])) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  const fmt = v => 'Rp ' + Number(v).toLocaleString('id-ID');
  const jt  = v => 'Rp ' + (v / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
  const mo  = <?= json_encode($mo, JSON_UNESCAPED_UNICODE) ?>;

  // 1) Tren pendapatan vs biaya (garis)
  const elTrend = document.getElementById('finTrend');
  if (elTrend) {
    new Chart(elTrend, {
      type: 'line',
      data: {
        labels: mo.labels,
        datasets: [
          { label: 'Pendapatan', data: mo.revenue, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .3 },
          { label: 'Terbayar',   data: mo.paid,    borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.12)',  fill: true, tension: .3 },
          { label: 'Biaya',      data: mo.cost,    borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.12)',  fill: true, tension: .3 }
        ]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.parsed.y) } }
        },
        scales: { y: { ticks: { callback: jt } } }
      }
    });
  }

  // 2) Pendapatan per jenis booking (donat)
  const elType = document.getElementById('finByType');
  if (elType) {
    new Chart(elType, {
      type: 'doughnut',
      data: {
        labels: ['Travel', 'Rental'],
        datasets: [{
          data: [<?= (float) $rev['travel'] ?>, <?= (float) $rev['rental'] ?>],
          backgroundColor: ['#0d6efd', '#fd7e14']
        }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: c => c.label + ': ' + fmt(c.parsed) } }
        }
      }
    });
  }

  // 3) Biaya per jenis (batang horizontal)
  const elCost = document.getElementById('finCost');
  if (elCost) {
    new Chart(elCost, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($costs, 'cost_type'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Biaya',
          data: <?= json_encode(array_map('floatval', array_column($costs, 'amount'))) ?>,
          backgroundColor: 'rgba(220,53,69,.7)',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => fmt(c.parsed.x) } }
        },
        scales: { x: { ticks: { callback: jt } } }
      }
    });
  }
})();
</script>
