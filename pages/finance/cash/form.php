<?php
/**
 * Buku Kas: input/ubah transaksi (akun, tanggal, arah, jumlah, kategori, keterangan).
 * Akun yang boleh dipilih hanya milik perusahaan aktif (divalidasi ulang di server).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$row = null;
if ($id > 0) {
    $row = cash_tx_find($id); // 403 bila transaksi milik perusahaan lain
    if (!$row) {
        flash('error', 'Transaksi tidak ditemukan.');
        redirect('pages/finance/cash/index.php');
    }
}
$form = [
    'cash_account_id' => (string) ($row['cash_account_id'] ?? ''),
    'date'            => (string) ($row['date'] ?? date('Y-m-d')),
    'direction'       => (string) ($row['direction'] ?? 'in'),
    'amount'          => $row !== null ? (string) $row['amount'] : '',
    'category'        => (string) ($row['category'] ?? ''),
    'description'     => (string) ($row['description'] ?? ''),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($form) as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    // Koma/titik ribuan diterima agar nilai tempel dari Excel tidak ditolak.
    $form['amount'] = str_replace(['.', ','], ['', '.'], (string) ($_POST['amount'] ?? ''));
    $errors = cash_tx_validate($form, $cid);
    if (!$errors) {
        cash_tx_save($id, $form, $cid, $_SESSION['username'] ?? null);
        audit('kas.' . ($id > 0 ? 'update' : 'create'), 'Transaksi kas #' . ($id > 0 ? $id : '-')
            . ' akun #' . (int) $form['cash_account_id'] . ' ' . $form['direction'] . ' ' . rupiah((float) $form['amount']));
        flash('success', 'Transaksi berhasil ' . ($id > 0 ? 'diperbarui' : 'disimpan') . '.');
        redirect('pages/finance/cash/index.php');
    }
}

$accounts = cash_account_list(true, $cid);
$active = 'finance';
$title  = ($id > 0 ? 'Ubah' : 'Tambah') . ' Transaksi Kas';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 720px;">
        <div class="card-body">
            <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="mb-3">
                    <label class="form-label" for="cash_account_id">Akun Kas/Bank <span class="text-danger">*</span></label>
                    <select class="form-select" id="cash_account_id" name="cash_account_id" required>
                        <option value="">— pilih —</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"<?= (int) $form['cash_account_id'] === (int) $a['id'] ? ' selected' : '' ?>>
                                <?= e($a['code'] . ' - ' . $a['name']) ?> (<?= e(cash_types()[$a['type']] ?? $a['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$accounts): ?>
                        <div class="form-text text-danger">Belum ada akun aktif.
                            <a href="../cash_accounts/form.php">Tambah akun kas/bank</a> terlebih dahulu.</div>
                    <?php endif; ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="date">Tanggal <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" id="date" name="date" value="<?= e($form['date']) ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="direction">Arah <span class="text-danger">*</span></label>
                        <select class="form-select" id="direction" name="direction" required>
                            <?php foreach (cash_directions() as $k => $lbl): ?>
                                <option value="<?= e($k) ?>"<?= $form['direction'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="amount">Jumlah <span class="text-danger">*</span></label>
                        <input class="form-control" type="number" step="0.01" min="0.01" id="amount" name="amount"
                               value="<?= e($form['amount']) ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="category">Kategori</label>
                    <input class="form-control" id="category" name="category" maxlength="50" value="<?= e($form['category']) ?>"
                           placeholder="mis. setoran, bbm, gaji, sewa">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Keterangan</label>
                    <textarea class="form-control" id="description" name="description" rows="2" maxlength="1000"><?= e($form['description']) ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a class="btn btn-outline-secondary" href="index.php">Batal</a>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
