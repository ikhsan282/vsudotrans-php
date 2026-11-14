<?php
/**
 * Buku Kas: filter rentang tanggal + akun + arah, kartu saldo akhir/masuk/keluar,
 * tabel transaksi dengan paginasi, tombol export CSV/XLSX.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$cid = require_active_company();
$f = cash_filter($_GET);

$p = paginate(cash_tx_count($f), (int) ($_GET['page'] ?? 1));
$rows = cash_tx_list($f, $p['per_page'], $p['offset']);
$sum = cash_summary($f);
$accounts = cash_account_list(false, $cid);
$account_name = '';
foreach ($accounts as $a) {
    if ((int) $a['id'] === $f['account']) {
        $account_name = $a['code'] . ' - ' . $a['name'];
    }
}

$export_qs = http_build_query([
    'format'    => 'csv',
    'from'      => $f['from'],
    'to'        => $f['to'],
    'account'   => $f['account'],
    'direction' => $f['direction'],
]);
// Parameter paginasi dipertahankan agar halaman berikutnya tidak kehilangan filter.
$page_qs = http_build_query(['from' => $f['from'], 'to' => $f['to'], 'account' => $f['account'], 'direction' => $f['direction']]);

$cards = [
    ['Saldo Akhir' . ($account_name !== '' ? ' — ' . $account_name : ($f['account'] > 0 ? '' : ' (semua akun)')),
        rupiah($sum['balance']), 'primary', 'wallet2'],
    ['Total Masuk', rupiah($sum['total_in']), 'success', 'arrow-down-circle'],
    ['Total Keluar', rupiah($sum['total_out']), 'danger', 'arrow-up-circle'],
    ['Saldo Awal', rupiah($sum['opening']), 'secondary', 'flag'],
];

$active = 'finance';
$title  = 'Kas & Bank';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Kas &amp; Bank</h4>
        <div class="d-flex gap-2">
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-success" href="export.php?<?= e($export_qs) ?>">
                    <i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-success" href="export.php?<?= e(str_replace('format=csv', 'format=xlsx', $export_qs)) ?>">
                    <i class="bi bi-file-earmark-excel"></i> Excel</a>
                <a class="btn btn-danger" href="export.php?<?= e($export_qs) ?>&format=pdf">
                    <i class="bi bi-filetype-pdf"></i> PDF</a>
                <a class="btn btn-outline-secondary" href="export.php?<?= e($export_qs) ?>&format=html&print=1" target="_blank">
                    <i class="bi bi-printer"></i> Cetak</a>
            </div>
            <?php if (can('finance.manage')): ?>
                <a class="btn btn-primary btn-sm" href="form.php"><i class="bi bi-plus-lg"></i> Transaksi</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label small mb-1">Akun</label>
                    <select class="form-select form-select-sm" name="account">
                        <option value="0">Semua akun</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $f['account'] ? ' selected' : '' ?>>
                                <?= e($a['code'] . ' - ' . $a['name']) ?><?= $a['is_active'] ? '' : ' (nonaktif)' ?></option>
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
                <div class="col-12 col-lg-3">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Terapkan</button>
                </div>
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
            <table class="table table-hover mb-0 align-middle text-nowrap">
                <thead><tr>
                    <th>Tanggal</th><th>Akun</th><th>Arah</th><th>Kategori</th>
                    <th>Keterangan</th><th class="text-end">Nominal</th><th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(tanggal_indo((string) $r['date'])) ?></td>
                        <td><?= e(trim((string) $r['account_code'] . ' - ' . (string) $r['account_name'], ' -')) ?></td>
                        <td><?= cash_direction_badge((string) $r['direction']) ?></td>
                        <td><?= e((string) $r['category']) ?></td>
                        <td><?= e((string) $r['description']) ?></td>
                        <td class="text-end text-nowrap <?= $r['direction'] === 'in' ? 'text-success' : 'text-danger' ?>">
                            <?= e(($r['direction'] === 'in' ? '+' : '−') . ' ' . rupiah((float) $r['amount'])) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (can('finance.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus transaksi ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="5">Masuk − Keluar pada rentang (<?= (int) $p['total'] ?> transaksi)</td>
                        <td class="text-end text-nowrap"><?= e(rupiah($sum['total_in'] - $sum['total_out'])) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="small text-muted">Saldo akhir (sampai <?= e(tanggal_indo($f['to'])) ?>): <?= e(rupiah($sum['balance'])) ?></span>
            <?= pagination_html($p, $page_qs) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
