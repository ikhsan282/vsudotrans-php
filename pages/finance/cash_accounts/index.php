<?php
/**
 * Akun Kas/Bank — daftar akun perusahaan aktif + saldo berjalan tiap akun
 * (saldo awal + Σmasuk − Σkeluar). Saldo per akun dihitung di sini; kartu saldo
 * buku kas memakai cash_summary() agar dua halaman tidak pernah berselisih rumus.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$compScope = company_filter_scope('a.company_id');
$filter_cid = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;

$sqlAcc = 'SELECT a.*, c.code AS company_code,
        COALESCE((SELECT SUM(CASE WHEN t.direction = \'in\' THEN t.amount ELSE -t.amount END)
            FROM cash_transactions t WHERE t.cash_account_id = a.id AND t.company_id = a.company_id), 0) AS movement
    FROM cash_accounts a
    LEFT JOIN companies c ON c.id = a.company_id
    WHERE ' . $compScope['where'] . ' ORDER BY c.code, a.code';
$st = db()->prepare($sqlAcc);
if ($compScope['types'] !== '') {
    $st->bind_param($compScope['types'], ...$compScope['params']);
}
$st->execute();
$all = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$p = paginate(count($all), (int) ($_GET['page'] ?? 1));
$rows = array_slice($all, $p['offset'], $p['per_page']);
$total_balance = 0.0;
$unconf_banks = [];
foreach ($all as $a) {
    $total_balance += (float) $a['opening_balance'] + (float) $a['movement'];
    if (!empty($a['is_active']) && ($a['type'] ?? '') === 'bank' && (trim((string) ($a['bank_name'] ?? '')) === '' || trim((string) ($a['account_number'] ?? '')) === '')) {
        $unconf_banks[] = $a;
    }
}

$header_actions = [
    ['label' => 'Buku Kas', 'icon' => 'journal-text', 'href' => 'pages/finance/cash/index.php' . (is_super_admin() ? '?company_id=' . $filter_cid : ''), 'class' => 'btn btn-outline-primary btn-sm'],
];
if (can('finance.manage')) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/finance/cash_accounts/form.php' . ($filter_cid > 0 ? '?company_id=' . $filter_cid : ''), 'class' => 'btn btn-primary btn-sm'];
}
$query_base = is_super_admin() ? http_build_query(['company_id' => $filter_cid]) : '';

$active = 'finance';
$title  = 'Akun Kas/Bank';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Akun Kas/Bank',
        ['Dashboard' => 'pages/dashboard/index.php', 'Keuangan' => null, 'Akun Kas/Bank' => null],
        [['text' => 'Total Saldo: ' . rupiah($total_balance), 'class' => 'bg-primary-subtle text-primary border']],
        $header_actions
    ) ?>

    <?php if ($unconf_banks): ?>
    <div class="alert alert-warning py-2 px-3 small d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <i class="bi bi-bank me-1"></i>
            <strong>Kesiapan Rekening Bank:</strong> Terdapat <?= count($unconf_banks) ?> akun bank aktif yang Nama Bank / Nomor Rekening-nya belum dilengkapi
            (<?= e(implode(', ', array_map(static fn(array $u): string => trim(($u['company_code'] ?? '') . ' ' . ($u['code'] ?? '')), array_slice($unconf_banks, 0, 5)))) ?><?= count($unconf_banks) > 5 ? ', …' : '' ?>).
        </div>
        <?php if (can('finance.manage') && count($unconf_banks) === 1): ?>
            <a href="form.php?id=<?= (int) $unconf_banks[0]['id'] ?>" class="btn btn-sm btn-warning py-0 px-2">Lengkapi Sekarang</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (is_super_admin()): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/finance/cash_accounts/index.php', $filter_cid !== (active_company_id() ?? 0)) ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?><th>Kode</th><th>Nama</th><th>Tipe</th><th>Bank</th><th>No. Rekening</th>
                    <th class="text-end">Saldo Awal</th><th class="text-end">Saldo Akhir</th><th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td class="fw-semibold"><?= e((string) $r['code']) ?></td>
                        <td><?= e((string) $r['name']) ?></td>
                        <td><span class="badge bg-<?= $r['type'] === 'bank' ? 'info' : 'secondary' ?>">
                            <?= e(cash_types()[$r['type']] ?? (string) $r['type']) ?></span></td>
                        <td><?= e((string) $r['bank_name']) ?></td>
                        <td><?= e((string) $r['account_number']) ?></td>
                        <td class="text-end text-nowrap"><?= e(rupiah((float) $r['opening_balance'])) ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(rupiah((float) $r['opening_balance'] + (float) $r['movement'])) ?></td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="../cash/index.php?account=<?= (int) $r['id'] ?>&amp;company_id=<?= (int) $r['company_id'] ?>" title="Buku Kas" aria-label="Buku Kas"><i class="bi bi-journal-text"></i></a>
                            <?php if (can('finance.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus akun ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $show_comp_col ? 10 : 9 ?>" class="text-center text-muted py-4">Belum ada akun kas/bank.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, $query_base) ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
