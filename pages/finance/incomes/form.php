<?php
/**
 * Pemasukan: form Tambah / Ubah transaksi masuk dengan preset kategori & kategori kustom.
 * Tersimpan ke `cash_transactions` (direction = 'in') agar otomatis sinkron dengan Buku Kas,
 * Laporan Keuangan, dan Dashboard.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$returnKey = (string) ($_GET['return'] ?? $_POST['return'] ?? 'incomes');
$returnMap = [
    'incomes'   => 'pages/finance/incomes/index.php',
    'cash'      => 'pages/finance/cash/index.php',
    'dashboard' => 'pages/dashboard/index.php',
];
if (!isset($returnMap[$returnKey])) {
    $returnKey = 'incomes';
}
$returnPath = $returnMap[$returnKey];

$row = null;
if ($id > 0) {
    $row = cash_tx_find($id);
    if (!$row) {
        flash('error', 'Data pemasukan tidak ditemukan.');
        redirect('pages/finance/incomes/index.php');
    }
    if (($row['direction'] ?? '') !== 'in') {
        flash('error', 'Transaksi pengeluaran tidak dapat diubah melalui form Pemasukan. Gunakan form Pengeluaran.');
        redirect('pages/finance/expenses/form.php?id=' . $id);
    }
    if (!empty($row['source_type'])) {
        flash('error', 'Transaksi sinkronisasi otomatis hanya dapat diubah dari Invoice atau Booking asal.');
        redirect('pages/finance/incomes/index.php');
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

$presetMap   = cash_income_categories();
$presetKeys  = array_keys($presetMap);
$existingCat = trim((string) ($row['category'] ?? ''));
if ($row === null) {
    $initPreset = 'Setoran Modal';
    $initCustom = '';
} elseif ($existingCat !== '' && in_array($existingCat, $presetKeys, true)) {
    $initPreset = $existingCat;
    $initCustom = '';
} else {
    $initPreset = '__custom__';
    $initCustom = $existingCat;
}

$amtInit = $row !== null ? (float) $row['amount'] : 0.0;
$amtFmt  = $row !== null
    ? (abs($amtInit - round($amtInit)) < 0.005 ? number_format($amtInit, 0, ',', '.') : number_format($amtInit, 2, ',', '.'))
    : '';

$form = [
    'payment_method_id' => (string) ($row['payment_method_id'] ?? ''),
    'vehicle_id'        => (string) ($row['vehicle_id'] ?? ''),
    'date'              => (string) ($row['date'] ?? date('Y-m-d')),
    'amount_raw'        => $amtFmt,
    'category_preset'   => $initPreset,
    'category_custom'   => $initCustom,
    'description'       => (string) ($row['description'] ?? ''),
];
$existingProof = (string) ($row['proof_blob_path'] ?? '');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form['payment_method_id'] = trim((string) ($_POST['payment_method_id'] ?? ''));
    $form['vehicle_id']        = trim((string) ($_POST['vehicle_id'] ?? ''));
    $form['date']              = trim((string) ($_POST['date'] ?? ''));
    $form['amount_raw']        = trim((string) ($_POST['amount'] ?? ''));
    $form['category_preset']   = trim((string) ($_POST['category_preset'] ?? ''));
    $form['category_custom']   = trim((string) ($_POST['category_custom'] ?? ''));
    $form['description']       = trim((string) ($_POST['description'] ?? ''));

    $chosenCategory = $form['category_preset'] === '__custom__'
        ? $form['category_custom']
        : $form['category_preset'];
    $parsedAmount = cash_parse_amount($form['amount_raw']);

    if ($chosenCategory === '') {
        $errors[] = 'Kategori pemasukan wajib dipilih atau diisi.';
    }

    $savePayload = [
        'payment_method_id' => $form['payment_method_id'],
        'vehicle_id'        => $form['vehicle_id'],
        'date'              => $form['date'],
        'direction'         => 'in',
        'amount'            => (string) $parsedAmount,
        'category'          => $chosenCategory,
        'description'       => $form['description'],
        'proof_blob_path'   => $existingProof,
    ];
    $errors = array_merge($errors, cash_tx_validate($savePayload, $cid));

    if (!$errors) {
        $proofRes = cash_handle_proof_upload($cid, 'incomes', $existingProof, !empty($_POST['remove_proof']));
        if (!empty($proofRes['error'])) {
            $errors[] = $proofRes['error'];
        } else {
            $savePayload['proof_blob_path'] = $proofRes['path'];
        }
    }

    if (!$errors) {
        $savedId = cash_tx_save($id, $savePayload, $cid, $_SESSION['username'] ?? null);
        audit('kas.income.' . ($id > 0 ? 'update' : 'create'), 'Pemasukan #' . $savedId . ' [' . $chosenCategory . '] ' . rupiah($parsedAmount));
        flash('success', 'Pemasukan (' . $chosenCategory . ' — ' . rupiah($parsedAmount) . ') berhasil ' . ($id > 0 ? 'diperbarui' : 'dicatat') . '.');
        if ($id === 0 && (string) ($_POST['save_and_new'] ?? '') === '1') {
            $nextQs = array_filter([
                'return'     => $returnKey !== 'incomes' ? $returnKey : null,
                'company_id' => (is_super_admin() && active_company_id() === null) ? $cid : null,
            ], static fn($v): bool => $v !== null);
            redirect('pages/finance/incomes/form.php' . ($nextQs ? '?' . http_build_query($nextQs) : ''));
        }
        redirect($returnPath);
    }
}

$methods  = cash_method_list($cid);
$vehicles = cash_vehicle_list($cid);
$active   = 'incomes';
$title   = ($id > 0 ? 'Ubah' : 'Tambah') . ' Pemasukan';
$isCustomMode = $form['category_preset'] === '__custom__';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Pemasukan',
        'cash-coin',
        $title,
        $id > 0 ? ['ID #' . $id, 'Kas Masuk'] : ['Kas Masuk'],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/' . $returnPath, 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formIncome', 'class' => 'btn-success'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formIncome" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="return" value="<?= e($returnKey) ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-inc-detail" data-bs-toggle="tab" data-bs-target="#pane-inc-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-inc-audit" data-bs-toggle="tab" data-bs-target="#pane-inc-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-inc-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-cash-coin text-success"></i> Rincian Pemasukan</div>
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
                                                   placeholder="mis. 500.000" value="<?= e($form['amount_raw']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Metode Pembayaran</div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" id="payment_method_id" name="payment_method_id">
                                                <option value="">— opsional (Tunai/Transfer/dll) —</option>
                                                <?php foreach ($methods as $m): ?>
                                                    <option value="<?= (int) $m['id'] ?>"<?= (int) $form['payment_method_id'] === (int) $m['id'] ? ' selected' : '' ?>>
                                                        <?= e((string) $m['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?= cash_render_vehicle_row($vehicles, $form['vehicle_id']) ?>
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
                                                <?php foreach ($presetMap as $catKey => $catDesc): ?>
                                                    <option value="<?= e($catKey) ?>"<?= $form['category_preset'] === $catKey ? ' selected' : '' ?>>
                                                        <?= e($catKey . ' — ' . $catDesc) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <option value="__custom__"<?= $isCustomMode ? ' selected' : '' ?>>+ Kategori Kustom (ketik sendiri)...</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row<?= $isCustomMode ? '' : ' d-none' ?>" id="wrapCategoryCustom">
                                        <div class="rnt-label">Nama Kustom <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" type="text" id="category_custom" name="category_custom" maxlength="50"
                                                   placeholder="mis. Komisi Mitra, Titipan Paket" value="<?= e($form['category_custom']) ?>"<?= $isCustomMode ? ' required' : '' ?>>
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Keterangan</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="description" name="description" rows="2" maxlength="1000"
                                                      placeholder="mis. Setoran kas harian pool Tangerang"><?= e($form['description']) ?></textarea>
                                            <div class="form-text small text-muted mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Tercatat otomatis sebagai Kas Masuk di Buku Kas &amp; Laporan Keuangan.
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
                    <div class="tab-pane fade" id="pane-inc-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="border-top bg-light px-3 py-2 d-flex flex-wrap justify-content-end gap-2">
                <a href="<?= e(APP_URL . '/' . $returnPath) ?>" class="btn btn-sm btn-outline-secondary">Batal</a>
                <?php if ($id === 0): ?>
                    <button type="submit" name="save_and_new" value="1" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-plus-circle me-1"></i>Simpan &amp; Tambah Lagi
                    </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-success px-3">
                    <i class="bi bi-check2-circle me-1"></i><?= $id > 0 ? 'Perbarui Pemasukan' : 'Simpan Pemasukan' ?>
                </button>
            </div>
        </div>
    </form>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('category_preset');
    var wrap = document.getElementById('wrapCategoryCustom');
    var inp = document.getElementById('category_custom');
    if (!sel || !wrap || !inp) return;
    function syncCustomCategory() {
        var isCustom = sel.value === '__custom__';
        wrap.classList.toggle('d-none', !isCustom);
        inp.required = isCustom;
        if (isCustom && document.activeElement === sel) {
            inp.focus();
        }
    }
    sel.addEventListener('change', syncCustomCategory);
    syncCustomCategory();
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
