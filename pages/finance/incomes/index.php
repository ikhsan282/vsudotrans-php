<?php
/**
 * Daftar Pemasukan: filter rentang tanggal, kategori, metode bayar & pencarian;
 * ringkasan total, breakdown per kategori, serta export CSV/XLSX/PDF/Cetak.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$compScope     = company_filter_scope('t.company_id');
$filter_cid    = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;

$f = cash_filter($_GET);
$f['direction'] = 'in';
if (is_super_admin()) {
    $f['company_id'] = $filter_cid;
}

$p    = paginate(cash_tx_count($f), (int) ($_GET['page'] ?? 1));
$rows = cash_tx_list($f, $p['per_page'], $p['offset']);
$sum  = cash_summary($f);

$breakdownFilter = $f;
$breakdownFilter['category'] = '';
$breakdown = cash_income_category_breakdown($breakdownFilter);

$methods       = cash_method_list($filter_cid);
$categoriesOpt = cash_distinct_categories($f, 'in');
$topCat        = $breakdown[0] ?? null;
$avgIn         = $p['total'] > 0 ? ($sum['total_in'] / $p['total']) : 0.0;

$qsParams = [
    'format'    => 'csv',
    'from'      => $f['from'],
    'to'        => $f['to'],
    'method'    => $f['method'],
    'direction' => 'in',
];
if ($f['category'] !== '') {
    $qsParams['category'] = $f['category'];
}
if ($f['q'] !== '') {
    $qsParams['q'] = $f['q'];
}
if (is_super_admin()) {
    $qsParams['company_id'] = $filter_cid;
}
$export_qs = http_build_query($qsParams);

unset($qsParams['format'], $qsParams['direction']);
$page_qs = http_build_query($qsParams);

$chipBaseParams = $qsParams;
unset($chipBaseParams['category']);
$chipBaseQs = http_build_query($chipBaseParams);

$cards = [
    ['Total Pemasukan', rupiah($sum['total_in']), 'success', 'arrow-down-circle'],
    ['Jumlah Transaksi', number_format((int) $p['total'], 0, ',', '.'), 'secondary', 'receipt'],
    ['Rata-rata / Transaksi', rupiah($avgIn), 'info', 'calculator'],
    [
        'Kategori Terbesar',
        $topCat ? ($topCat['category'] . ' (' . rupiah((float) $topCat['total_amount']) . ')') : '—',
        'primary',
        'pie-chart',
    ],
];

$header_actions = [
    ['label' => 'CSV',      'icon' => 'filetype-csv',       'href' => 'pages/finance/cash/export.php?' . $export_qs, 'class' => 'btn btn-outline-success btn-sm'],
    ['label' => 'Excel',    'icon' => 'file-earmark-excel', 'href' => 'pages/finance/cash/export.php?' . str_replace('format=csv', 'format=xlsx', $export_qs), 'class' => 'btn btn-success btn-sm'],
    ['label' => 'PDF',      'icon' => 'filetype-pdf',       'href' => 'pages/finance/cash/export.php?' . $export_qs . '&format=pdf', 'class' => 'btn btn-danger btn-sm'],
    ['label' => 'Cetak',    'icon' => 'printer',            'href' => 'pages/finance/cash/export.php?' . $export_qs . '&format=html&print=1', 'class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank'],
    ['label' => 'Buku Kas', 'icon' => 'wallet2',            'href' => 'pages/finance/cash/index.php', 'class' => 'btn btn-outline-primary btn-sm'],
];
if (can('finance.manage')) {
    $header_actions[] = ['label' => 'Tambah Pemasukan', 'icon' => 'plus-lg', 'href' => 'pages/finance/incomes/form.php', 'class' => 'btn btn-success btn-sm'];
}

$active = 'incomes';
$title  = 'Pemasukan';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        'Pemasukan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Keuangan' => null, 'Pemasukan' => null],
        [['text' => number_format((int) $p['total'], 0, ',', '.') . ' Pemasukan', 'class' => 'bg-light text-secondary border']],
        $header_actions,
        'Catat pemasukan manual maupun pantau pelunasan booking — otomatis tersinkron ke Buku Kas & Laporan Keuangan'
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar erp-filter-bar-wide" method="get">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-6 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-6 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Kategori</label>
                    <select class="form-select form-select-sm" name="category" onchange="this.form.submit()">
                        <option value="">Semua kategori</option>
                        <?php foreach ($categoriesOpt as $catOpt): ?>
                            <option value="<?= e($catOpt) ?>"<?= $f['category'] === $catOpt ? ' selected' : '' ?>><?= e($catOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Metode</label>
                    <select class="form-select form-select-sm" name="method" onchange="this.form.submit()">
                        <option value="0">Semua metode</option>
                        <?php foreach ($methods as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"<?= (int) $m['id'] === $f['method'] ? ' selected' : '' ?>>
                                <?= e((string) $m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 erp-filter-q">
                    <label class="form-label small mb-1">Pencarian</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q" placeholder="Kategori atau keterangan..." value="<?= e($f['q']) ?>">
                    </div>
                </div>
                <?= ui_filter_actions(
                    APP_URL . '/pages/finance/incomes/index.php',
                    ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['method'] > 0 || $f['category'] !== '' || $f['q'] !== '' || (is_super_admin() && $filter_cid !== (active_company_id() ?? 0)))
                ) ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ($cards as [$lbl, $val, $clr, $icon]): ?>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100 overflow-hidden">
                    <div class="card-body d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="text-muted small"><?= e($lbl) ?></div>
                            <div class="fs-6 fw-bold text-<?= e($clr) ?> text-truncate" title="<?= e($val) ?>"><?= e($val) ?></div>
                        </div>
                        <i class="bi bi-<?= e($icon) ?> fs-3 text-<?= e($clr) ?> opacity-50 flex-shrink-0"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($breakdown): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-2">
            <span class="small fw-semibold text-muted me-1"><i class="bi bi-tags me-1"></i>Per Kategori:</span>
            <?php if ($f['category'] !== ''): ?>
                <a href="<?= e(APP_URL . '/pages/finance/incomes/index.php' . ($chipBaseQs !== '' ? '?' . $chipBaseQs : '')) ?>"
                   class="badge rounded-pill bg-secondary text-white text-decoration-none px-2 py-1">Semua</a>
            <?php endif; ?>
            <?php foreach ($breakdown as $bd):
                $bdCat = (string) $bd['category'];
                $isSel = $f['category'] === $bdCat;
                $chipHref = APP_URL . '/pages/finance/incomes/index.php?' . ($chipBaseQs !== '' ? $chipBaseQs . '&' : '') . 'category=' . urlencode($bdCat);
            ?>
                <a href="<?= e($chipHref) ?>"
                   class="badge rounded-pill text-decoration-none px-2 py-1 border <?= $isSel ? 'bg-success text-white border-success' : 'bg-success-subtle text-success-emphasis border-success-subtle' ?>">
                    <?= e($bdCat) ?>: <strong><?= e(rupiah((float) $bd['total_amount'])) ?></strong>
                    <span class="opacity-75">(<?= (int) $bd['tx_count'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th>Tanggal</th>
                    <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
                    <th>Kategori</th>
                    <th>Metode</th>
                    <th>Keterangan</th>
                    <th class="text-end">Nominal</th>
                    <th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $srcType = (string) ($r['source_type'] ?? '');
                    $isAuto  = $srcType !== '';
                ?>
                    <tr>
                        <td class="text-nowrap"><?= e(date('d-M-Y', strtotime((string) $r['date']))) ?></td>
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td class="text-nowrap">
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= e((string) ($r['category'] ?: 'Tanpa Kategori')) ?></span>
                            <?php if ($srcType === 'booking_payment' && !empty($r['ref_booking_id'])):
                                $refBookHref = '../../' . ($r['ref_booking_type'] === 'rental' ? 'rental' : 'travel') . '/bookings/detail.php?id=' . (int) $r['ref_booking_id'] . '&tab=payment#tab-payment';
                            ?>
                                <a class="badge bg-primary-subtle text-primary border text-decoration-none ms-1" href="<?= e($refBookHref) ?>" title="Sumber otomatis dari pembayaran pemesanan (Invoice #<?= (int) ($r['ref_invoice_id'] ?? 0) ?>)"><i class="bi bi-link-45deg"></i>Auto Booking</a>
                            <?php elseif ($srcType === 'booking_payment'): ?>
                                <span class="badge bg-primary-subtle text-primary border ms-1">Auto Booking</span>
                            <?php elseif ($isAuto): ?>
                                <span class="badge bg-secondary-subtle text-secondary border ms-1">Auto</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= !empty($r['method_name']) ? e((string) $r['method_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= !empty($r['description']) ? e((string) $r['description']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end text-nowrap fw-semibold text-success">
                            <?= e('+ ' . rupiah((float) $r['amount'])) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (can('finance.manage')): ?>
                                <?php if ($isAuto): ?>
                                    <span class="text-muted small" title="Transaksi tersinkron otomatis dari sumber (Invoice/Booking)"><i class="bi bi-lock-fill"></i></span>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                    <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus pemasukan ini? Saldo Buku Kas akan otomatis menyesuaikan.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= $show_comp_col ? 7 : 6 ?>" class="text-center text-muted py-4">
                            <div>Belum ada pemasukan tercatat pada periode &amp; filter ini.</div>
                            <?php if (can('finance.manage')): ?>
                                <a href="<?= e(APP_URL) ?>/pages/finance/incomes/form.php" class="btn btn-sm btn-outline-success mt-2">
                                    <i class="bi bi-plus-lg me-1"></i>Catat Pemasukan Pertama
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="<?= $show_comp_col ? 5 : 4 ?>">Total Pemasukan pada rentang (<?= (int) $p['total'] ?> transaksi)</td>
                        <td class="text-end text-nowrap text-success"><?= e(rupiah($sum['total_in'])) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <span class="small text-muted">Periode <?= e(tanggal_indo($f['from'])) ?> s/d <?= e(tanggal_indo($f['to'])) ?></span>
            <?= pagination_html($p, $page_qs) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
