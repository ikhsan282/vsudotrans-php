<?php
/**
 * Daftar Pemasukan: filter rentang tanggal, kategori & pencarian;
 * ringkasan total pada footer tabel serta export Excel/PDF/CSV/Cetak.
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

$categoriesOpt = cash_distinct_categories($f, 'in');

$exportBase = [
    'from'      => $f['from'],
    'to'        => $f['to'],
    'direction' => 'in',
];
if ($f['category'] !== '') {
    $exportBase['category'] = $f['category'];
}
if ($f['q'] !== '') {
    $exportBase['q'] = $f['q'];
}
if (is_super_admin()) {
    $exportBase['company_id'] = $filter_cid;
}

$pageParams = $exportBase;
unset($pageParams['direction']);
$page_qs = http_build_query($pageParams);

$header_actions = [
    [
        'label' => 'Export',
        'icon'  => 'download',
        'class' => 'btn btn-outline-secondary btn-sm dropdown-toggle',
        'items' => [
            [
                'label'      => 'Excel (.xlsx)',
                'icon'       => 'file-earmark-excel',
                'icon_class' => 'text-success',
                'href'       => 'pages/finance/cash/export.php?' . http_build_query($exportBase + ['format' => 'xlsx']),
            ],
            [
                'label'      => 'PDF (.pdf)',
                'icon'       => 'file-earmark-pdf',
                'icon_class' => 'text-danger',
                'href'       => 'pages/finance/cash/export.php?' . http_build_query($exportBase + ['format' => 'pdf']),
            ],
            [
                'label'      => 'CSV (.csv)',
                'icon'       => 'filetype-csv',
                'icon_class' => 'text-secondary',
                'href'       => 'pages/finance/cash/export.php?' . http_build_query($exportBase + ['format' => 'csv']),
            ],
            [
                'label'      => 'Cetak',
                'icon'       => 'printer',
                'icon_class' => 'text-secondary',
                'href'       => 'pages/finance/cash/export.php?' . http_build_query($exportBase + ['format' => 'html', 'print' => '1']),
                'target'     => '_blank',
            ],
        ],
    ],
];
if (can('finance.manage')) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/finance/incomes/form.php', 'class' => 'btn btn-primary btn-sm'];
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
        $header_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-center erp-filter-bar erp-filter-bar-wide" method="get">
                <div class="col-12 col-md-4 erp-filter-q">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q" placeholder="Cari kategori atau keterangan..." value="<?= e($f['q']) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-2 erp-filter-item">
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="Dari Tanggal" title="Dari Tanggal" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-6 col-md-2 erp-filter-item">
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="Sampai Tanggal" title="Sampai Tanggal" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-6 col-md-2 erp-filter-item">
                    <select class="form-select form-select-sm" name="category" onchange="this.form.submit()" title="Filter Kategori">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categoriesOpt as $catOpt): ?>
                            <option value="<?= e($catOpt) ?>"<?= $f['category'] === $catOpt ? ' selected' : '' ?>><?= e($catOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (is_super_admin()): ?>
                <div class="col-6 col-md-2 erp-filter-item">
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()" title="Pilih Perusahaan">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?= ui_filter_actions(
                    APP_URL . '/pages/finance/incomes/index.php',
                    ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['category'] !== '' || $f['q'] !== '' || (is_super_admin() && $filter_cid !== (active_company_id() ?? 0)))
                ) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th class="text-center" style="width: 52px;">#</th>
                    <th>Tanggal</th>
                    <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th class="text-end">Nominal</th>
                    <th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r):
                    $srcType = (string) ($r['source_type'] ?? '');
                    $isAuto  = $srcType !== '';
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="text-nowrap"><?= e(date('d-M-Y', strtotime((string) $r['date']))) ?></td>
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td class="text-nowrap">
                            <span><?= e((string) ($r['category'] ?: 'Tanpa Kategori')) ?></span>
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
                        <td><?= !empty($r['description']) ? e((string) $r['description']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end text-nowrap fw-semibold text-success">
                            <?= e(rupiah((float) $r['amount'])) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?= cash_proof_action_html($r) ?>
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
                    <tr><td colspan="<?= $show_comp_col ? 7 : 6 ?>" class="text-center text-muted py-4">Tidak ada data pemasukan.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="<?= $show_comp_col ? 5 : 4 ?>">Total Pemasukan</td>
                        <td class="text-end text-nowrap text-success"><?= e(rupiah($sum['total_in'])) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small text-muted">
                <?php if ($p['total'] > 0): ?>
                    Menampilkan <strong><?= number_format($p['offset'] + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($p['total'], $p['offset'] + count($rows)), 0, ',', '.') ?></strong> dari <strong><?= number_format($p['total'], 0, ',', '.') ?></strong> data
                <?php else: ?>
                    Total <strong>0</strong> data
                <?php endif; ?>
            </div>
            <?= pagination_html($p, $page_qs) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
