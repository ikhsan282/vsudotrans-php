<?php
/**
 * Akun Kas/Bank — daftar akun perusahaan aktif + saldo berjalan tiap akun
 * (saldo awal + Σmasuk − Σkeluar). Saldo per akun dihitung di sini; kartu saldo
 * buku kas memakai cash_summary() agar dua halaman tidak pernah berselisih rumus.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.view');
require_once __DIR__ . '/../_cash_query.php';

$cid = require_active_company();

$st = db()->prepare('SELECT a.*,
        COALESCE((SELECT SUM(CASE WHEN t.direction = \'in\' THEN t.amount ELSE -t.amount END)
            FROM cash_transactions t WHERE t.cash_account_id = a.id AND t.company_id = a.company_id), 0) AS movement
    FROM cash_accounts a WHERE a.company_id = ? ORDER BY a.code');
$st->bind_param('i', $cid);
$st->execute();
$all = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$p = paginate(count($all), (int) ($_GET['page'] ?? 1));
$rows = array_slice($all, $p['offset'], $p['per_page']);
$total_balance = 0.0;
foreach ($all as $a) {
    $total_balance += (float) $a['opening_balance'] + (float) $a['movement'];
}

$active = 'finance';
$title  = 'Akun Kas/Bank';
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Akun Kas/Bank</h4>
        <div class="d-flex gap-2 align-items-center">
            <span class="small text-muted">Total saldo semua akun: <span class="fw-semibold"><?= e(rupiah($total_balance)) ?></span></span>
            <a class="btn btn-outline-primary btn-sm" href="../cash/index.php"><i class="bi bi-journal-text"></i> Buku Kas</a>
            <?php if (can('finance.manage')): ?>
                <a class="btn btn-primary btn-sm" href="form.php"><i class="bi bi-plus-lg"></i> Tambah</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Kode</th><th>Nama</th><th>Tipe</th><th>Bank</th><th>No. Rekening</th>
                    <th class="text-end">Saldo Awal</th><th class="text-end">Saldo Akhir</th><th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
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
                            <a class="btn btn-sm btn-outline-secondary" href="../cash/index.php?account=<?= (int) $r['id'] ?>">Buku</a>
                            <?php if (can('finance.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Hapus akun ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada akun kas/bank.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
