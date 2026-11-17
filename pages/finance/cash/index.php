<?php
/**
 * Buku Kas: filter rentang tanggal + metode bayar + arah, kartu saldo akhir/masuk/keluar/awal,
 * tabel transaksi dengan paginasi, tombol export CSV/XLSX/PDF/Cetak.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$compScope = company_filter_scope('t.company_id');
$filter_cid = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;

$f = cash_filter($_GET);
if (is_super_admin()) {
    $f['company_id'] = $filter_cid;
}

$p = paginate(cash_tx_count($f), (int) ($_GET['page'] ?? 1));
$rows = cash_tx_list($f, $p['per_page'], $p['offset']);
$sum = cash_summary($f);
$methods = cash_method_list($filter_cid);
$method_name = '';
foreach ($methods as $m) {
    if ((int) $m['id'] === $f['method']) {
        $method_name = (string) $m['name'];
    }
}

$qsParams = [
    'format'    => 'csv',
    'from'      => $f['from'],
    'to'        => $f['to'],
    'method'    => $f['method'],
    'direction' => $f['direction'],
];
if (is_super_admin()) {
    $qsParams['company_id'] = $filter_cid;
}
$export_qs = http_build_query($qsParams);
// Parameter paginasi dipertahankan agar halaman berikutnya tidak kehilangan filter.
unset($qsParams['format']);
$page_qs = http_build_query($qsParams);

$cards = [
    ['Saldo Akhir' . ($method_name !== '' ? ' (' . $method_name . ')' : ''),
        rupiah($sum['balance']), 'primary', 'wallet2'],
    ['Total Masuk', rupiah($sum['total_in']), 'success', 'arrow-down-circle'],
    ['Total Keluar', rupiah($sum['total_out']), 'danger', 'arrow-up-circle'],
    ['Saldo Awal (sblm ' . date('d M', strtotime($f['from'])) . ')', rupiah($sum['opening']), 'secondary', 'flag'],
];

$header_actions = [
    ['label' => 'CSV',   'icon' => 'filetype-csv',       'href' => 'pages/finance/cash/export.php?' . $export_qs, 'class' => 'btn btn-outline-success btn-sm'],
    ['label' => 'Excel', 'icon' => 'file-earmark-excel', 'href' => 'pages/finance/cash/export.php?' . str_replace('format=csv', 'format=xlsx', $export_qs), 'class' => 'btn btn-success btn-sm'],
    ['label' => 'PDF',   'icon' => 'filetype-pdf',       'href' => 'pages/finance/cash/export.php?' . $export_qs . '&format=pdf', 'class' => 'btn btn-danger btn-sm'],
    ['label' => 'Cetak', 'icon' => 'printer',            'href' => 'pages/finance/cash/export.php?' . $export_qs . '&format=html&print=1', 'class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank'],
];
if (can('finance.manage')) {
    $header_actions[] = ['label' => 'Transaksi', 'icon' => 'plus-lg', 'href' => 'pages/finance/cash/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$active = 'finance';
$title  = 'Buku Kas';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Buku Kas',
        ['Dashboard' => 'pages/dashboard/index.php', 'Keuangan' => null, 'Buku Kas' => null],
        [['text' => number_format((int) $p['total'], 0, ',', '.') . ' Transaksi', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
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
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Metode</label>
                    <select class="form-select form-select-sm" name="method">
                        <option value="0">Semua metode</option>
                        <?php foreach ($methods as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"<?= (int) $m['id'] === $f['method'] ? ' selected' : '' ?>>
                                <?= e((string) $m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Arah</label>
                    <select class="form-select form-select-sm" name="direction">
                        <option value="all">Semua</option>
                        <?php foreach (cash_directions() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['direction'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/finance/cash/index.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['method'] > 0 || $f['direction'] !== 'all' || (is_super_admin() && $filter_cid !== (active_company_id() ?? 0)))) ?>
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

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th>Tanggal</th><?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?><th>Arah</th><th>Kategori</th>
                    <th>Metode</th><th>Keterangan</th><th class="text-end">Nominal</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $srcType = (string) ($r['source_type'] ?? '');
                    $srcId   = (int) ($r['source_id'] ?? 0);
                    $isAuto  = $srcType !== '';
                ?>
                    <tr>
                        <td class="text-nowrap"><?= e(date('d-M-Y', strtotime((string) $r['date']))) ?></td>
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td class="text-nowrap"><?= cash_direction_badge((string) $r['direction']) ?></td>
                        <td class="text-nowrap">
                            <?= e((string) $r['category']) ?>
                            <?php if ($srcType === 'booking_payment' && !empty($r['ref_booking_id'])):
                                $refBookHref = '../../' . ($r['ref_booking_type'] === 'rental' ? 'rental' : 'travel') . '/bookings/detail.php?id=' . (int) $r['ref_booking_id'] . '&tab=payment#tab-payment';
                            ?>
                                <a class="badge bg-primary-subtle text-primary border text-decoration-none ms-1" href="<?= e($refBookHref) ?>" title="Sumber otomatis dari pembayaran pemesanan (Invoice #<?= (int) ($r['ref_invoice_id'] ?? 0) ?>)"><i class="bi bi-link-45deg"></i>Auto Booking</a>
                            <?php elseif ($srcType === 'booking_payment'): ?>
                                <span class="badge bg-primary-subtle text-primary border ms-1">Auto Booking</span>
                            <?php elseif ($srcType === 'payroll_period' && $srcId > 0): ?>
                                <a class="badge bg-info-subtle text-info-emphasis border text-decoration-none ms-1" href="../../payroll/periods/detail.php?id=<?= $srcId ?>" title="Sumber otomatis dari Payroll"><i class="bi bi-link-45deg"></i>Auto Payroll</a>
                            <?php elseif ($isAuto): ?>
                                <span class="badge bg-secondary-subtle text-secondary border ms-1">Auto</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= !empty($r['method_name']) ? e((string) $r['method_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= e((string) $r['description']) ?></td>
                        <td class="text-end text-nowrap fw-semibold <?= $r['direction'] === 'in' ? 'text-success' : 'text-danger' ?>">
                            <?= e(($r['direction'] === 'in' ? '+' : '−') . ' ' . rupiah((float) $r['amount'])) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (can('finance.manage')): ?>
                                <?php if ($isAuto): ?>
                                    <span class="text-muted small" title="Transaksi tersinkron otomatis dari sumber (Invoice/Booking/Payroll)"><i class="bi bi-lock-fill"></i></span>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                    <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus transaksi ini?')">
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
                    <tr><td colspan="<?= $show_comp_col ? 8 : 7 ?>" class="text-center text-muted py-4">Tidak ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="<?= $show_comp_col ? 6 : 5 ?>">Masuk − Keluar pada rentang (<?= (int) $p['total'] ?> transaksi)</td>
                        <td class="text-end text-nowrap"><?= e(rupiah($sum['total_in'] - $sum['total_out'])) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <span class="small text-muted">Saldo akhir (sampai <?= e(tanggal_indo($f['to'])) ?>): <?= e(rupiah($sum['balance'])) ?></span>
            <?= pagination_html($p, $page_qs) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
