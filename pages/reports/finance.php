<?php
/**
 * Laporan Keuangan: arus uang per periode — pendapatan (travel/rental),
 * terbayar, piutang, rincian tagihan belum lunas, biaya operasional per jenis,
 * dan laba kotor. Filter from/to/booking_type seperti Laporan Booking.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('finance.view'); // laporan keuangan memuat pendapatan/biaya/laba — perlu izin Keuangan
require_once __DIR__ . '/_finance_query.php';

$f = fin_report_filter($_GET);
$compScope = company_filter_scope('company_id', $_GET);
$data = fin_all($f);

$rev     = $data['revenue'];
$costs   = $data['cost_types'];
$ct      = fin_cost_totals($costs);
$expCats = $data['expense_categories'] ?? [];
$expCt   = fin_expense_category_totals($expCats);
$expVehs = $data['expense_vehicles'] ?? [];
$netSum  = $data['net_summary'] ?? [
    'revenue_total' => (float) $rev['total'],
    'trip_cost' => (float) $data['cost_total'],
    'gross_profit' => (float) $data['gross_profit'],
    'in_other' => 0.0,
    'out_operating' => 0.0,
    'out_commission' => 0.0,
    'net_profit' => (float) ($data['net_profit'] ?? $data['gross_profit']),
    'net_margin_pct' => 0.0,
];
$unpaid  = $data['unpaid'];
$ut      = fin_unpaid_totals($unpaid);
$mo      = $data['monthly'];
$cash    = $data['cash'] ?? ['in_total' => 0, 'in_booking' => 0, 'in_other' => 0, 'out_total' => 0, 'out_payroll' => 0, 'out_commission' => 0, 'out_operating' => 0, 'out_other' => 0, 'net_cash_flow' => 0];

$cards = [
    ['Laba Bersih Operasional', rupiah((float) $netSum['net_profit']), $netSum['net_profit'] >= 0 ? 'success' : 'danger', 'award'],
    ['Laba Kotor Trip',         rupiah((float) $data['gross_profit']), $data['gross_profit'] >= 0 ? 'primary' : 'danger', 'graph-up-arrow'],
    ['Pendapatan Booking',      rupiah((float) $rev['total']),        'info', 'cash-stack'],
    ['Pengeluaran Kas (Periode)', rupiah((float) $cash['out_total']), 'danger', 'receipt-cutoff'],
];

$header_actions = report_header_actions('pages/reports/finance_export.php', $f);

$active = 'reports';
$title  = 'Laporan Keuangan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Laporan Keuangan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Laporan' => null, 'Keuangan' => null],
        [['text' => tanggal_indo($f['from']) . ' – ' . tanggal_indo($f['to']), 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= ((int) ($f['filter_company_id'] ?? 0) === 0) ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= ((int) ($f['filter_company_id'] ?? 0) === (int) $co['id']) ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Jenis Booking</label>
                    <select class="form-select form-select-sm" name="booking_type">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= report_category_filter_col($f) ?>
                <?= ui_filter_actions(APP_URL . '/pages/reports/finance.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['booking_type'] !== 'all' || !empty($f['category_id']) || (is_super_admin() && !empty($f['filter_company_id'])))) ?>
            </form>
            <?= report_preset_pills_html('pages/reports/finance.php', $f) ?>
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
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-wallet2 me-1 text-success"></i>Arus Kas Nyata (Buku Kas)</span>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-success btn-sm py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/incomes/index.php?from=<?= e($f['from']) ?>&to=<?= e($f['to']) ?><?= is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '' ?>">
                    Pemasukan <i class="bi bi-arrow-right-short"></i>
                </a>
                <a class="btn btn-outline-danger btn-sm py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/expenses/index.php?from=<?= e($f['from']) ?>&to=<?= e($f['to']) ?><?= is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '' ?>">
                    Pengeluaran <i class="bi bi-arrow-right-short"></i>
                </a>
                <a class="btn btn-outline-warning btn-sm py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/commissions/index.php?from=<?= e($f['from']) ?>&to=<?= e($f['to']) ?><?= is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '' ?>">
                    Komisi Kru <i class="bi bi-arrow-right-short"></i>
                </a>
                <a class="btn btn-outline-primary btn-sm py-0 px-2" href="<?= e(APP_URL) ?>/pages/finance/cash/index.php?from=<?= e($f['from']) ?>&to=<?= e($f['to']) ?><?= is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '' ?>">
                    Buka Buku Kas <i class="bi bi-arrow-right-short"></i>
                </a>
            </div>
        </div>
        <div class="card-body py-2">
            <div class="row g-3 text-center text-md-start align-items-center">
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Kas Masuk (Periode)</div>
                    <div class="fw-bold text-success"><?= e(rupiah((float) $cash['in_total'])) ?></div>
                    <div class="text-muted" style="font-size:.75rem;">Booking: <?= e(rupiah((float) $cash['in_booking'])) ?> · Lain: <?= e(rupiah((float) $cash['in_other'])) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Kas Keluar (Periode)</div>
                    <div class="fw-bold text-danger"><?= e(rupiah((float) $cash['out_total'])) ?></div>
                    <div class="text-muted" style="font-size:.75rem;">
                        Operasional: <?= e(rupiah((float) ($cash['out_operating'] ?? $cash['out_other']))) ?>
                        <?php if ((float) ($cash['out_commission'] ?? 0) > 0): ?> · Komisi Kru: <?= e(rupiah((float) $cash['out_commission'])) ?><?php endif; ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Arus Kas Bersih</div>
                    <div class="fw-bold text-<?= $cash['net_cash_flow'] >= 0 ? 'primary' : 'danger' ?>"><?= e(rupiah((float) $cash['net_cash_flow'])) ?></div>
                    <div class="text-muted" style="font-size:.75rem;">Kas Masuk − Kas Keluar</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">Piutang / Terbayar Booking</div>
                    <div class="fw-bold text-info-emphasis"><?= e(rupiah((float) $rev['receivable'])) ?></div>
                    <div class="text-muted" style="font-size:.75rem;">Terbayar <?= e(rupiah($rev['paid'])) ?> dari <?= e(rupiah($rev['total'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3" id="laba-bersih-operasional">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-calculator me-1 text-primary"></i>Ringkasan Laba Bersih Operasional</span>
            <span class="badge <?= $netSum['net_profit'] >= 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' ?>">
                Margin Bersih <?= e(number_format((float) $netSum['net_margin_pct'], 1, ',', '.')) ?>%
            </span>
        </div>
        <div class="card-body py-2">
            <div class="row g-2 align-items-center text-center text-md-start">
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">Pendapatan Booking</div>
                    <div class="fw-semibold text-primary"><?= e(rupiah((float) $netSum['revenue_total'])) ?></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">− Biaya Trip</div>
                    <div class="fw-semibold text-warning-emphasis"><?= e(rupiah((float) $netSum['trip_cost'])) ?></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">= Laba Kotor Trip</div>
                    <div class="fw-semibold"><?= e(rupiah((float) $netSum['gross_profit'])) ?></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">+ Pemasukan Kas Lain</div>
                    <div class="fw-semibold text-success"><?= e(rupiah((float) $netSum['in_other'])) ?></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">− Pengeluaran Kas Ops</div>
                    <div class="fw-semibold text-danger"><?= e(rupiah((float) $netSum['out_operating'])) ?></div>
                </div>
                <div class="col-6 col-lg-2">
                    <div class="small text-muted">= Laba Bersih Ops</div>
                    <div class="fs-6 fw-bold text-<?= $netSum['net_profit'] >= 0 ? 'success' : 'danger' ?>"><?= e(rupiah((float) $netSum['net_profit'])) ?></div>
                </div>
            </div>
            <?php if ((float) ($netSum['out_commission'] ?? 0) > 0): ?>
            <div class="text-muted mt-2 pt-2 border-top" style="font-size:.78rem;">
                <i class="bi bi-info-circle me-1"></i>Pencairan Komisi Kru pada Buku Kas sebesar <strong><?= e(rupiah((float) $netSum['out_commission'])) ?></strong> sudah tercermin di Biaya Operasional Trip sehingga tidak dikurangkan dua kali pada Laba Bersih Operasional.
            </div>
            <?php endif; ?>
        </div>
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

    <?php
    $catRows = $data['categories'] ?? [];
    $ftCat   = fin_category_totals($catRows);
    ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold">Pendapatan &amp; Laba per Kategori Layanan</span>
            <span class="small text-muted">Margin Total <?= e(number_format((float) $ftCat['margin_pct'], 1, ',', '.')) ?>%</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Kategori Layanan</th>
                    <th>Layanan</th>
                    <th class="text-end">Booking</th>
                    <th class="text-end">Pendapatan</th>
                    <th class="text-end">Terbayar</th>
                    <th class="text-end">Piutang</th>
                    <th class="text-end">Biaya Trip</th>
                    <th class="text-end">Laba Kotor</th>
                    <th class="text-end">Margin</th>
                </tr></thead>
                <tbody>
                <?php foreach ($catRows as $r):
                    $gpCat = (float) ($r['gross_profit'] ?? 0);
                ?>
                    <tr>
                        <td><?= e((string) $r['category_label']) ?></td>
                        <td><span class="badge <?= $r['booking_type'] === 'rental' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>"><?= e((string) $r['service_label']) ?></span></td>
                        <td class="text-end"><?= (int) $r['booking_count'] ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['revenue'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['paid'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['receivable'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $r['cost'])) ?></td>
                        <td class="text-end fw-semibold <?= $gpCat >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah($gpCat)) ?></td>
                        <td class="text-end"><?= e(number_format((float) $r['margin_pct'], 1, ',', '.')) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$catRows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">Belum ada transaksi per kategori pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($catRows): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= (int) $ftCat['booking_count'] ?></td>
                        <td class="text-end"><?= e(rupiah((float) $ftCat['revenue'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $ftCat['paid'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $ftCat['receivable'])) ?></td>
                        <td class="text-end"><?= e(rupiah((float) $ftCat['cost'])) ?></td>
                        <td class="text-end <?= $ftCat['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah((float) $ftCat['gross_profit'])) ?></td>
                        <td class="text-end"><?= e(number_format((float) $ftCat['margin_pct'], 1, ',', '.')) ?>%</td>
                    </tr>
                </tfoot>
                <?php endif; ?>
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

    <div class="row g-3 mb-3" id="pengeluaran-kas-kategori">
        <div class="col-12 <?= $expVehs ? 'col-xl-7' : 'col-xl-6' ?>">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold"><i class="bi bi-tags me-1 text-danger"></i>Pengeluaran Kas per Kategori</span>
                    <span class="small text-muted">Total <?= e(rupiah($expCt['amount'])) ?> (<?= (int) $expCt['tx_count'] ?> transaksi)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead><tr>
                            <th>Kategori Pengeluaran</th>
                            <th class="text-end">Transaksi</th>
                            <th class="text-end">Jumlah</th>
                            <th class="text-end">Porsi</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($expCats as $ec):
                            $ecCat  = (string) $ec['category'];
                            $ecHref = APP_URL . '/pages/finance/expenses/index.php?from=' . urlencode($f['from'])
                                . '&to=' . urlencode($f['to'])
                                . '&category=' . urlencode($ecCat)
                                . (is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '');
                        ?>
                            <tr>
                                <td>
                                    <a href="<?= e($ecHref) ?>" class="text-decoration-none fw-medium"><?= e($ecCat) ?></a>
                                </td>
                                <td class="text-end"><?= (int) $ec['tx_count'] ?></td>
                                <td class="text-end text-danger fw-semibold"><?= e(rupiah((float) $ec['amount'])) ?></td>
                                <td class="text-end"><?= e(number_format((float) $ec['share_pct'], 1, ',', '.')) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$expCats): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada pengeluaran tercatat pada Buku Kas pada periode ini.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <?php if ($expCats): ?>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td>Total</td>
                                <td class="text-end"><?= (int) $expCt['tx_count'] ?></td>
                                <td class="text-end text-danger"><?= e(rupiah($expCt['amount'])) ?></td>
                                <td class="text-end">100,0%</td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 <?= $expVehs ? 'col-xl-5' : 'col-xl-6' ?>">
            <?php if ($expVehs): ?>
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold"><i class="bi bi-truck me-1 text-primary"></i>Pengeluaran Kas per Armada</span>
                    <span class="small text-muted"><?= count($expVehs) ?> unit</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead><tr>
                            <th>No. Polisi</th>
                            <th class="text-end">Transaksi</th>
                            <th class="text-end">Jumlah</th>
                            <th class="text-end">Porsi</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($expVehs as $ev):
                            $evHref = APP_URL . '/pages/finance/expenses/index.php?from=' . urlencode($f['from'])
                                . '&to=' . urlencode($f['to'])
                                . '&vehicle_id=' . (int) $ev['vehicle_id']
                                . (is_super_admin() ? '&company_id=' . (int) ($f['filter_company_id'] ?? 0) : '');
                        ?>
                            <tr>
                                <td>
                                    <a href="<?= e($evHref) ?>" class="text-decoration-none fw-medium font-monospace"><?= e((string) $ev['vehicle_number']) ?></a>
                                    <?php if (!empty($ev['vehicle_category'])): ?>
                                        <span class="small text-muted ms-1"><?= e((string) $ev['vehicle_category']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= (int) $ev['tx_count'] ?></td>
                                <td class="text-end text-danger fw-semibold"><?= e(rupiah((float) $ev['amount'])) ?></td>
                                <td class="text-end"><?= e(number_format((float) $ev['share_pct'], 1, ',', '.')) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-1 text-danger"></i>Distribusi Pengeluaran Kas</div>
                <div class="card-body">
                    <?php if (!$expCats): ?>
                        <div class="text-center text-muted py-4">Tidak ada pengeluaran kas pada periode ini.</div>
                    <?php else: ?>
                        <canvas id="finCashExpCat" height="220"></canvas>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $daily = $data['daily'] ?? [];
    $dt    = fin_daily_totals($daily);
    ?>
    <div class="card shadow-sm border-0 mb-3" id="rekap-harian">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold">Rekap Harian (Pemasukan &amp; Pengeluaran)</span>
            <span class="small text-muted">
                <?= count($daily) ?> hari aktif
                <?php if ($daily): ?>
                    · Arus Kas Bersih <strong class="<?= $dt['net_cash_flow'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah($dt['net_cash_flow'])) ?></strong>
                <?php endif; ?>
            </span>
        </div>
        <?php if (($f['booking_type'] ?? 'all') !== 'all' || !empty($f['category_id'])): ?>
        <div class="px-3 py-2 border-bottom small text-muted">
            Catatan: Kas Masuk &amp; Kas Keluar mengikuti Buku Kas perusahaan (semua transaksi), sedangkan Omzet Booking &amp; Biaya Trip mengikuti filter jenis/kategori.
        </div>
        <?php endif; ?>
        <?php if ($daily): ?>
        <div class="p-3 border-bottom" style="position:relative; height:190px;">
            <canvas id="finDailyChart"></canvas>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Tanggal</th>
                    <th class="text-end">Kas Masuk</th>
                    <th class="text-end">Kas Keluar</th>
                    <th class="text-end">Arus Kas Bersih</th>
                    <th class="text-end">Omzet Booking</th>
                    <th class="text-end">Biaya Trip</th>
                    <th class="text-end">Laba Kotor Trip</th>
                </tr></thead>
                <tbody>
                <?php foreach ($daily as $r):
                    $netCash = (float) $r['net_cash_flow'];
                    $gp      = (float) $r['gross_profit'];
                ?>
                    <tr>
                        <td class="text-nowrap"><?= e(tanggal_indo((string) $r['date'])) ?></td>
                        <td class="text-end text-nowrap">
                            <span class="fw-semibold text-success"><?= e(rupiah((float) $r['in_total'])) ?></span>
                            <?php if ((float) $r['in_other'] > 0): ?>
                                <div class="small text-muted">Booking <?= e(rupiah((float) $r['in_booking'])) ?> · Lain <?= e(rupiah((float) $r['in_other'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <span class="fw-semibold text-danger"><?= e(rupiah((float) $r['out_total'])) ?></span>
                            <?php if ((float) $r['out_payroll'] > 0): ?>
                                <div class="small text-muted">Gaji <?= e(rupiah((float) $r['out_payroll'])) ?> · Lain <?= e(rupiah((float) $r['out_other'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold <?= $netCash >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= e(rupiah($netCash)) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?= e(rupiah((float) $r['booking_total'])) ?>
                            <?php if ((int) $r['booking_count'] > 0): ?>
                                <div class="small text-muted"><?= (int) $r['booking_count'] ?> booking</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e(rupiah((float) $r['trip_cost'])) ?></td>
                        <td class="text-end text-nowrap fw-semibold <?= $gp >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= e(rupiah($gp)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$daily): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Belum ada transaksi kas, booking, maupun biaya pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($daily): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total (<?= count($daily) ?> hari)</td>
                        <td class="text-end text-success"><?= e(rupiah($dt['in_total'])) ?></td>
                        <td class="text-end text-danger"><?= e(rupiah($dt['out_total'])) ?></td>
                        <td class="text-end <?= $dt['net_cash_flow'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah($dt['net_cash_flow'])) ?></td>
                        <td class="text-end"><?= e(rupiah($dt['booking_total'])) ?></td>
                        <td class="text-end"><?= e(rupiah($dt['trip_cost'])) ?></td>
                        <td class="text-end <?= $dt['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah($dt['gross_profit'])) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3" id="tagihan-belum-lunas">
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
                <?php foreach ($unpaid as $r):
                    $unpHref = APP_URL . '/pages/' . ($r['booking_type'] === 'rental' ? 'rental' : 'travel') . '/bookings/detail.php?id=' . (int) $r['id'] . '&tab=payment#tab-payment';
                ?>
                    <tr>
                        <td><a class="text-decoration-none fw-medium" href="<?= e($unpHref) ?>"><?= e((string) $r['document_number']) ?></a></td>
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
  const charts = [];

  function themePalette() {
    const cs = getComputedStyle(document.documentElement);
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    return {
      text: (cs.getPropertyValue('--text-color') || '').trim() || (dark ? '#e3e8ee' : '#212529'),
      grid: dark ? 'rgba(255,255,255,0.09)' : 'rgba(0,0,0,0.08)',
      surface: (cs.getPropertyValue('--surface') || '').trim() || (dark ? '#1b222b' : '#ffffff'),
    };
  }

  function applyChartTheme() {
    const pal = themePalette();
    Chart.defaults.color = pal.text;
    Chart.defaults.borderColor = pal.grid;
    charts.forEach(ch => {
      if (!ch || !ch.options) return;
      if (ch.options.plugins && ch.options.plugins.legend && ch.options.plugins.legend.labels) {
        ch.options.plugins.legend.labels.color = pal.text;
      }
      if (ch.options.scales) {
        Object.values(ch.options.scales).forEach(sc => {
          if (sc.ticks) sc.ticks.color = pal.text;
          if (sc.grid) sc.grid.color = pal.grid;
        });
      }
      if (ch.config && ch.config.type === 'doughnut' && ch.data && ch.data.datasets && ch.data.datasets[0]) {
        ch.data.datasets[0].borderColor = pal.surface;
      }
      ch.update('none');
    });
  }

  const initPal = themePalette();
  Chart.defaults.color = initPal.text;
  Chart.defaults.borderColor = initPal.grid;

  // 1) Tren pendapatan vs biaya (garis)
  const elTrend = document.getElementById('finTrend');
  if (elTrend) {
    charts.push(new Chart(elTrend, {
      type: 'line',
      data: {
        labels: mo.labels,
        datasets: [
          { label: 'Pendapatan', data: mo.revenue, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.14)', fill: true, tension: .3 },
          { label: 'Terbayar',   data: mo.paid,    borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.14)',  fill: true, tension: .3 },
          { label: 'Biaya',      data: mo.cost,    borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.14)',  fill: true, tension: .3 }
        ]
      },
      options: {
        plugins: {
          legend: { position: 'bottom', labels: { color: initPal.text } },
          tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.parsed.y) } }
        },
        scales: {
          x: { ticks: { color: initPal.text }, grid: { color: initPal.grid } },
          y: { ticks: { color: initPal.text, callback: jt }, grid: { color: initPal.grid } }
        }
      }
    }));
  }

  // 2) Pendapatan per jenis booking (donat)
  const elType = document.getElementById('finByType');
  if (elType) {
    charts.push(new Chart(elType, {
      type: 'doughnut',
      data: {
        labels: ['Travel', 'Rental'],
        datasets: [{
          data: [<?= (float) $rev['travel'] ?>, <?= (float) $rev['rental'] ?>],
          backgroundColor: ['#0d6efd', '#fd7e14'],
          borderColor: initPal.surface,
          borderWidth: 2
        }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom', labels: { color: initPal.text } },
          tooltip: { callbacks: { label: c => c.label + ': ' + fmt(c.parsed) } }
        }
      }
    }));
  }

  // 3) Biaya per jenis (batang horizontal)
  const elCost = document.getElementById('finCost');
  if (elCost) {
    charts.push(new Chart(elCost, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($costs, 'cost_type'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Biaya',
          data: <?= json_encode(array_map('floatval', array_column($costs, 'amount'))) ?>,
          backgroundColor: 'rgba(220,53,69,.78)',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => fmt(c.parsed.x) } }
        },
        scales: {
          x: { ticks: { color: initPal.text, callback: jt }, grid: { color: initPal.grid } },
          y: { ticks: { color: initPal.text }, grid: { color: initPal.grid } }
        }
      }
    }));
  }

  // 3b) Pengeluaran Kas per Kategori (jika canvas tampil)
  const elCashExpCat = document.getElementById('finCashExpCat');
  if (elCashExpCat) {
    charts.push(new Chart(elCashExpCat, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($expCats, 'category'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
          label: 'Pengeluaran Kas',
          data: <?= json_encode(array_map('floatval', array_column($expCats, 'amount'))) ?>,
          backgroundColor: 'rgba(220,53,69,.78)',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => fmt(c.parsed.x) } }
        },
        scales: {
          x: { ticks: { color: initPal.text, callback: jt }, grid: { color: initPal.grid } },
          y: { ticks: { color: initPal.text }, grid: { color: initPal.grid } }
        }
      }
    }));
  }

  // 4) Rekap harian: Kas Masuk vs Kas Keluar (batang) + Omzet Booking (garis)
  const elDaily = document.getElementById('finDailyChart');
  const dailyRows = <?= json_encode($daily, JSON_UNESCAPED_UNICODE) ?>;
  if (elDaily && Array.isArray(dailyRows) && dailyRows.length > 0) {
    const dailyLabels = dailyRows.map(r => {
      const p = String(r.date || '').split('-');
      return p.length === 3 ? (p[2] + '/' + p[1]) : String(r.date || '');
    });
    charts.push(new Chart(elDaily, {
      type: 'bar',
      data: {
        labels: dailyLabels,
        datasets: [
          {
            label: 'Kas Masuk',
            data: dailyRows.map(r => Number(r.in_total || 0)),
            backgroundColor: 'rgba(25,135,84,.78)',
            borderRadius: 3
          },
          {
            label: 'Kas Keluar',
            data: dailyRows.map(r => Number(r.out_total || 0)),
            backgroundColor: 'rgba(220,53,69,.78)',
            borderRadius: 3
          },
          {
            type: 'line',
            label: 'Omzet Booking',
            data: dailyRows.map(r => Number(r.booking_total || 0)),
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13,110,253,.12)',
            tension: 0.25,
            pointRadius: dailyRows.length > 31 ? 0 : 2.5
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { color: initPal.text } },
          tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmt(c.parsed.y) } }
        },
        scales: {
          x: { ticks: { color: initPal.text, autoSkip: true, maxTicksLimit: 15 }, grid: { color: initPal.grid } },
          y: { ticks: { color: initPal.text, callback: jt }, grid: { color: initPal.grid } }
        }
      }
    }));
  }

  window.addEventListener('vst:themechange', applyChartTheme);
})();
</script>
