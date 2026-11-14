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
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Kas & Bank',
        'wallet2',
        $title,
        $id > 0 ? ['ID #' . $id, cash_directions()[$form['direction']] ?? $form['direction']] : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formCash', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formCash">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-cash-detail" data-bs-toggle="tab" data-bs-target="#pane-cash-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-cash-audit" data-bs-toggle="tab" data-bs-target="#pane-cash-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-cash-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-cash-coin"></i> Rincian Transaksi</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row<?= !$accounts ? ' rnt-row-top' : '' ?>">
                                        <div class="rnt-label">Akun Kas/Bank <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" id="cash_account_id" name="cash_account_id" required>
                                                <option value="">— pilih —</option>
                                                <?php foreach ($accounts as $a): ?>
                                                    <option value="<?= (int) $a['id'] ?>"<?= (int) $form['cash_account_id'] === (int) $a['id'] ? ' selected' : '' ?>>
                                                        <?= e($a['code'] . ' - ' . $a['name']) ?> (<?= e(cash_types()[$a['type']] ?? $a['type']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (!$accounts): ?>
                                                <div class="form-text small text-danger">Belum ada akun aktif.
                                                    <a href="../cash_accounts/form.php">Tambah akun kas/bank</a> terlebih dahulu.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Tanggal <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" type="date" id="date" name="date" value="<?= e($form['date']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Arah <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" id="direction" name="direction" required>
                                                <?php foreach (cash_directions() as $k => $lbl): ?>
                                                    <option value="<?= e($k) ?>"<?= $form['direction'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Jumlah <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" type="number" step="0.01" min="0.01" id="amount" name="amount"
                                                   value="<?= e($form['amount']) ?>" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-journal-text"></i> Kategori &amp; Keterangan</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kategori</div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="category" name="category" maxlength="50" value="<?= e($form['category']) ?>"
                                                   placeholder="mis. setoran, bbm, gaji, sewa">
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Keterangan</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="description" name="description" rows="2" maxlength="1000"><?= e($form['description']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-cash-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
