<?php
/**
 * Pengeluaran: form Tambah / Ubah transaksi keluar menggunakan Master Kategori Kas.
 * Tersimpan ke `cash_transactions` (direction = 'out') agar otomatis sinkron dengan Buku Kas,
 * Laporan Keuangan, dan Dashboard.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$returnKey = (string) ($_GET['return'] ?? $_POST['return'] ?? 'expenses');
$returnMap = [
    'expenses'  => 'pages/finance/expenses/index.php',
    'cash'      => 'pages/finance/cash/index.php',
    'dashboard' => 'pages/dashboard/index.php',
];
if (!isset($returnMap[$returnKey])) {
    $returnKey = 'expenses';
}
$returnPath = $returnMap[$returnKey];

$row = null;
if ($id > 0) {
    $row = cash_tx_find($id);
    if (!$row) {
        flash('error', 'Data pengeluaran tidak ditemukan.');
        redirect('pages/finance/expenses/index.php');
    }
    if (($row['direction'] ?? '') !== 'out') {
        flash('error', 'Transaksi pemasukan tidak dapat diubah melalui form Pengeluaran. Gunakan Buku Kas.');
        redirect('pages/finance/cash/form.php?id=' . $id);
    }
    if (!empty($row['source_type'])) {
        flash('error', 'Transaksi sinkronisasi otomatis tidak dapat diubah manual.');
        redirect('pages/finance/expenses/index.php');
    }
    $cid = (int) $row['company_id'];
} else {
    $activeCid = active_company_id();
    if ($activeCid !== null) {
        $cid = (int) $activeCid;
    } elseif (is_super_admin()) {
        $cid = (int) ($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
        if ($cid <= 0) {
            $comps = accessible_companies();
            $cid = (int) ($comps[0]['id'] ?? 0);
        }
        if ($cid <= 0) {
            $cid = require_active_company();
        }
    } else {
        $cid = require_active_company();
    }
}

$masterCats    = cash_master_categories('out', $cid, true);
$validCatNames = array_column($masterCats, 'name');
$existingCat   = trim((string) ($row['category'] ?? ''));

$prefillCat = $row === null ? trim((string) ($_GET['category'] ?? '')) : '';
if ($prefillCat !== '' && !in_array($prefillCat, $validCatNames, true)) {
    $prefillCat = '';
}
$prefillDesc = $row === null ? trim((string) ($_GET['description'] ?? '')) : '';

$form = [
    'date'            => (string) ($row['date'] ?? date('Y-m-d')),
    'amount_raw'      => $row !== null ? number_format((float) $row['amount'], 0, ',', '.') : '',
    'category_preset' => $row !== null ? $existingCat : $prefillCat,
    'description'     => (string) ($row['description'] ?? $prefillDesc),
];
$existingProof = (string) ($row['proof_blob_path'] ?? '');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form['date']            = trim((string) ($_POST['date'] ?? ''));
    $form['amount_raw']      = trim((string) ($_POST['amount'] ?? ''));
    $form['category_preset'] = trim((string) ($_POST['category_preset'] ?? $_POST['category'] ?? ''));
    $form['description']     = trim((string) ($_POST['description'] ?? ''));

    $chosenCategory = $form['category_preset'];
    $parsedAmount   = cash_parse_amount($form['amount_raw']);
    $isAllowedCat   = $chosenCategory !== ''
        && (in_array($chosenCategory, $validCatNames, true) || ($row !== null && $chosenCategory === $existingCat));

    if ($chosenCategory === '') {
        $errors[] = 'Kategori pengeluaran wajib dipilih.';
    } elseif (!$isAllowedCat) {
        $errors[] = 'Kategori wajib dipilih dari Master Kategori Kas.';
    }

    $savePayload = [
        'date'            => $form['date'],
        'direction'       => 'out',
        'amount'          => (string) $parsedAmount,
        'category'        => $chosenCategory,
        'description'     => $form['description'],
        'proof_blob_path' => $existingProof,
    ];
    $errors = array_merge($errors, cash_tx_validate($savePayload, $cid));

    if (!$errors) {
        $proofRes = cash_handle_proof_upload($cid, 'expenses', $existingProof, !empty($_POST['remove_proof']));
        if (!empty($proofRes['error'])) {
            $errors[] = $proofRes['error'];
        } else {
            $savePayload['proof_blob_path'] = $proofRes['path'];
        }
    }

    if (!$errors) {
        $savedId = cash_tx_save($id, $savePayload, $cid, $_SESSION['username'] ?? null);
        audit('kas.expense.' . ($id > 0 ? 'update' : 'create'), 'Pengeluaran #' . $savedId . ' [' . $chosenCategory . '] ' . rupiah($parsedAmount));
        flash('success', 'Pengeluaran (' . $chosenCategory . ' — ' . rupiah($parsedAmount) . ') berhasil ' . ($id > 0 ? 'diperbarui' : 'dicatat') . '.');
        if ($id === 0 && (string) ($_POST['save_and_new'] ?? '') === '1') {
            $nextQs = array_filter([
                'return'     => $returnKey !== 'expenses' ? $returnKey : null,
                'company_id' => (is_super_admin() && active_company_id() === null) ? $cid : null,
            ], static fn($v): bool => $v !== null);
            redirect('pages/finance/expenses/form.php' . ($nextQs ? '?' . http_build_query($nextQs) : ''));
        }
        redirect($returnPath);
    }
}

$active = 'expenses';
$title  = ($id > 0 ? 'Ubah' : 'Tambah') . ' Pengeluaran';
$hasLegacyCat = $row !== null && $existingCat !== '' && !in_array($existingCat, $validCatNames, true);
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Pengeluaran',
        'receipt-cutoff',
        $title,
        $id > 0 ? ['ID #' . $id, 'Kas Keluar'] : ['Kas Keluar'],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/' . $returnPath, 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formExpense', 'class' => 'btn-danger'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formExpense" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="return" value="<?= e($returnKey) ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-exp-detail" data-bs-toggle="tab" data-bs-target="#pane-exp-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-exp-audit" data-bs-toggle="tab" data-bs-target="#pane-exp-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-exp-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-cash-stack text-danger"></i> Rincian Pengeluaran</div>
                                <div class="rnt-panel-body">
                                    <?php if (is_super_admin() && $id === 0 && active_company_id() === null): ?>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Perusahaan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" name="company_id" onchange="window.location='form.php?return=<?= e(urlencode($returnKey)) ?>&company_id=' + encodeURIComponent(this.value)">
                                                <?php foreach (accessible_companies() as $co): ?>
                                                    <option value="<?= (int) $co['id'] ?>"<?= $cid === (int) $co['id'] ? ' selected' : '' ?>>
                                                        <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Tanggal <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm bg-white flatpickr-date" type="text" id="date" name="date" data-flatpickr-auto placeholder="dd-MMM-yyyy" value="<?= e($form['date']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Nominal (Rp) <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm font-monospace" type="text" inputmode="numeric" id="amount" name="amount"
                                                   placeholder="mis. 250.000" value="<?= e($form['amount_raw']) ?>" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-tags"></i> Kategori, Keterangan &amp; Bukti</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kategori <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" id="category_preset" name="category_preset" required>
                                                <option value="">— Pilih Kategori —</option>
                                                <?php if ($hasLegacyCat): ?>
                                                    <option value="<?= e($existingCat) ?>"<?= $form['category_preset'] === $existingCat ? ' selected' : '' ?>>
                                                        <?= e($existingCat) ?> (kategori lama)
                                                    </option>
                                                <?php endif; ?>
                                                <?php foreach ($masterCats as $mc): ?>
                                                    <option value="<?= e((string) $mc['name']) ?>"<?= $form['category_preset'] === $mc['name'] ? ' selected' : '' ?>>
                                                        <?= e('[' . $mc['code'] . '] ' . $mc['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text small text-muted mt-1">
                                                <a href="../cash_categories/index.php" class="text-decoration-none"><i class="bi bi-gear me-1"></i>Kelola Master Kategori Kas</a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Keterangan</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="description" name="description" rows="2" maxlength="1000"
                                                      placeholder="mis. Isi solar Hiace B 1234 XY di SPBU KM 57"><?= e($form['description']) ?></textarea>
                                            <div class="form-text small text-muted mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Tercatat otomatis sebagai Kas Keluar di Buku Kas &amp; Laporan Keuangan.
                                            </div>
                                        </div>
                                    </div>
                                    <?= cash_render_proof_row($existingProof) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-exp-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="border-top bg-light px-3 py-2 d-flex flex-wrap justify-content-end gap-2">
                <a href="<?= e(APP_URL . '/' . $returnPath) ?>" class="btn btn-sm btn-outline-secondary">Batal</a>
                <?php if ($id === 0): ?>
                    <button type="submit" name="save_and_new" value="1" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-plus-circle me-1"></i>Simpan &amp; Tambah Lagi
                    </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-danger px-3">
                    <i class="bi bi-check2-circle me-1"></i><?= $id > 0 ? 'Perbarui Pengeluaran' : 'Simpan Pengeluaran' ?>
                </button>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
