<?php
/**
 * Buku Kas: input/ubah transaksi (tanggal, arah, jumlah, kategori, keterangan, bukti opsional).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$row = null;
if ($id > 0) {
    $row = cash_tx_find($id); // 403 bila transaksi milik perusahaan lain
    if (!$row) {
        flash('error', 'Transaksi tidak ditemukan.');
        redirect('pages/finance/cash/index.php');
    }
    if (!empty($row['source_type'])) {
        flash('error', 'Transaksi hasil sinkronisasi otomatis hanya dapat diubah dari Invoice atau Booking asal.');
        redirect('pages/finance/cash/index.php');
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
$reqDir = (string) ($_GET['direction'] ?? 'in');
if (!isset(cash_directions()[$reqDir])) {
    $reqDir = 'in';
}
$form = [
    'date'        => (string) ($row['date'] ?? date('Y-m-d')),
    'direction'   => (string) ($row['direction'] ?? $reqDir),
    'amount'      => $row !== null ? (string) $row['amount'] : '',
    'category'    => (string) ($row['category'] ?? ''),
    'description' => (string) ($row['description'] ?? ''),
];
$existingProof = (string) ($row['proof_blob_path'] ?? '');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($form) as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['amount'] = (string) cash_parse_amount($_POST['amount'] ?? '');
    $form['proof_blob_path'] = $existingProof;
    $errors = cash_tx_validate($form, $cid);
    if (!$errors) {
        $subFolder = $form['direction'] === 'in' ? 'incomes' : 'expenses';
        $proofRes = cash_handle_proof_upload($cid, $subFolder, $existingProof, !empty($_POST['remove_proof']));
        if (!empty($proofRes['error'])) {
            $errors[] = $proofRes['error'];
        } else {
            $form['proof_blob_path'] = $proofRes['path'];
        }
    }
    if (!$errors) {
        cash_tx_save($id, $form, $cid, $_SESSION['username'] ?? null);
        audit('kas.' . ($id > 0 ? 'update' : 'create'), 'Transaksi kas #' . ($id > 0 ? $id : '-')
            . ' ' . $form['direction'] . ' ' . rupiah((float) $form['amount']));
        flash('success', 'Transaksi berhasil ' . ($id > 0 ? 'diperbarui' : 'disimpan') . '.');
        redirect('pages/finance/cash/index.php');
    }
}

$active = 'finance';
$title  = $id > 0
    ? 'Ubah Transaksi Kas'
    : ($form['direction'] === 'out' ? 'Tambah Pengeluaran (Kas Keluar)' : 'Tambah Pemasukan (Kas Masuk)');
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Buku Kas',
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
    <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formCash" enctype="multipart/form-data">
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
                                    <?php if (is_super_admin() && $id === 0 && active_company_id() === null): ?>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Perusahaan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" name="company_id" onchange="window.location='form.php?company_id=' + encodeURIComponent(this.value)">
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
                                <div class="rnt-panel-header"><i class="bi bi-journal-text"></i> Kategori, Keterangan &amp; Bukti</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kategori</div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="category" name="category" list="cashCategoryList" maxlength="50" value="<?= e($form['category']) ?>"
                                                   placeholder="mis. BBM, Tol &amp; Parkir, Setoran Modal">
                                            <datalist id="cashCategoryList">
                                                <?php
                                                $initCatList = $form['direction'] === 'out'
                                                    ? array_keys(cash_expense_categories())
                                                    : array_keys(cash_income_categories());
                                                foreach ($initCatList as $cOpt):
                                                ?>
                                                    <option value="<?= e($cOpt) ?>"></option>
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Keterangan</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="description" name="description" rows="2" maxlength="1000"><?= e($form['description']) ?></textarea>
                                        </div>
                                    </div>
                                    <?= cash_render_proof_row($existingProof) ?>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dirSel = document.getElementById('direction');
    var dl = document.getElementById('cashCategoryList');
    if (!dirSel || !dl) return;
    var map = {
        'in': <?= json_encode(array_keys(cash_income_categories()), JSON_UNESCAPED_UNICODE) ?>,
        'out': <?= json_encode(array_keys(cash_expense_categories()), JSON_UNESCAPED_UNICODE) ?>
    };
    function refreshCats() {
        var list = map[dirSel.value] || map['in'];
        dl.innerHTML = '';
        list.forEach(function (v) {
            var opt = document.createElement('option');
            opt.value = v;
            dl.appendChild(opt);
        });
    }
    dirSel.addEventListener('change', refreshCats);
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
