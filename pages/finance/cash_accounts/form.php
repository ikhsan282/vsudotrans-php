<?php
/**
 * Form tambah/ubah Akun Kas/Bank (kode, nama, tipe kas/bank, saldo awal, aktif).
 * company_id selalu dari perusahaan aktif — tidak pernah dari POST.
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../_cash_query.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$row = null;
if ($id > 0) {
    $row = cash_account_find($id); // 403 bila akun milik perusahaan lain
    if (!$row) {
        flash('error', 'Akun kas/bank tidak ditemukan.');
        redirect('pages/finance/cash_accounts/index.php');
    }
}
$form = [
    'code'            => (string) ($row['code'] ?? ''),
    'name'            => (string) ($row['name'] ?? ''),
    'type'            => (string) ($row['type'] ?? 'kas'),
    'opening_balance' => $row !== null ? (string) $row['opening_balance'] : '0',
    'bank_name'       => (string) ($row['bank_name'] ?? ''),
    'account_number'  => (string) ($row['account_number'] ?? ''),
    'note'            => (string) ($row['note'] ?? ''),
    'is_active'       => (int) ($row['is_active'] ?? 1),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (['code', 'name', 'type', 'bank_name', 'account_number', 'note'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['code'] = strtoupper($form['code']);
    $form['opening_balance'] = str_replace(['.', ','], ['', '.'], (string) ($_POST['opening_balance'] ?? '0'));
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $form['code'])) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Nama wajib diisi.';
    }
    if (!isset(cash_types()[$form['type']])) {
        $errors[] = 'Tipe harus Kas atau Bank.';
    }
    if (!is_numeric($form['opening_balance'])) {
        $errors[] = 'Saldo awal harus berupa angka.';
    }

    if (!$errors) {
        try {
            $user = (string) ($_SESSION['username'] ?? '');
            $now = date('Y-m-d H:i:s');
            $c = $form['code'];
            $n = mb_substr($form['name'], 0, 255);
            $t = $form['type'];
            $ob = (float) $form['opening_balance'];
            $bn = $form['bank_name'] === '' ? null : mb_substr($form['bank_name'], 0, 255);
            $an = $form['account_number'] === '' ? null : mb_substr($form['account_number'], 0, 50);
            $nt = $form['note'] === '' ? null : mb_substr($form['note'], 0, 2000);
            $ia = (int) $form['is_active'];

            if ($id === 0) {
                $st = db()->prepare('INSERT INTO cash_accounts (company_id, code, name, type, opening_balance,'
                    . ' bank_name, account_number, is_active, note, created_user, created_date)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('isssdssisss', $cid, $c, $n, $t, $ob, $bn, $an, $ia, $nt, $user, $now);
            } else {
                $st = db()->prepare('UPDATE cash_accounts SET code = ?, name = ?, type = ?, opening_balance = ?,'
                    . ' bank_name = ?, account_number = ?, is_active = ?, note = ?, modified_user = ?, modified_date = ?'
                    . ' WHERE id = ? AND company_id = ?');
                $st->bind_param('sssdssissii', $c, $n, $t, $ob, $bn, $an, $ia, $nt, $user, $now, $id, $cid);
            }
            $st->execute();
            $st->close();
            audit('kas_account.' . ($id > 0 ? 'update' : 'create'), 'Akun kas/bank ' . $c . ' ' . $n);
            flash('success', 'Akun kas/bank berhasil ' . ($id > 0 ? 'diperbarui' : 'ditambahkan') . '.');
            redirect('pages/finance/cash_accounts/index.php');
        } catch (mysqli_sql_exception $ex) {
            // 1062 = UNIQUE (company_id, code)
            $errors[] = (int) $ex->getCode() === 1062
                ? 'Kode sudah dipakai pada akun lain di perusahaan ini.'
                : $ex->getMessage();
        }
    }
}

$active = 'finance';
$title  = ($id > 0 ? 'Ubah' : 'Tambah') . ' Akun Kas/Bank';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Akun Kas & Bank',
        'bank',
        $title,
        $id > 0 ? array_values(array_filter([
            $form['code'] ?: null,
            ['text' => $form['is_active'] ? 'Aktif' : 'Nonaktif', 'class' => $form['is_active'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'],
        ])) : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formCashAccount', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formCashAccount">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-ca-detail" data-bs-toggle="tab" data-bs-target="#pane-ca-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-ca-audit" data-bs-toggle="tab" data-bs-target="#pane-ca-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-ca-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-wallet"></i> Informasi Akun</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Kode <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm text-uppercase font-monospace" id="code" name="code" maxlength="15"
                                                   value="<?= e($form['code']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Nama <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" id="name" name="name" maxlength="255" value="<?= e($form['name']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Tipe <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select class="form-select form-select-sm" id="type" name="type" required>
                                                <?php foreach (cash_types() as $k => $lbl): ?>
                                                    <option value="<?= e($k) ?>"<?= $form['type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Saldo Awal</div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" type="number" step="0.01" id="opening_balance" name="opening_balance"
                                                   value="<?= e($form['opening_balance']) ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Status</div>
                                        <div class="rnt-field">
                                            <div class="rnt-check-wrap">
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="is_active">Aktif</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-bank2"></i> Rekening Bank &amp; Catatan</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Nama Bank</div>
                                        <div class="rnt-field">
                                            <?php
                                            $bank_list = master_bank_options();
                                            $cur_bank = (string) ($form['bank_name'] ?? '');
                                            $matched_bank = false;
                                            ?>
                                            <select class="form-select form-select-sm ts-select" id="bank_name" name="bank_name">
                                                <option value="">— Pilih Bank (Khusus Akun Bank) —</option>
                                                <?php foreach ($bank_list as $bk):
                                                    $is_sel = ($cur_bank === $bk['name'] || $cur_bank === $bk['code'] || $cur_bank === ($bk['code'] . ' — ' . $bk['name']));
                                                    if ($is_sel) $matched_bank = true;
                                                ?>
                                                    <option value="<?= e($bk['name']) ?>" <?= $is_sel ? 'selected' : '' ?>><?= e($bk['code'] . ' — ' . $bk['name']) ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($cur_bank !== '' && !$matched_bank): ?>
                                                    <option value="<?= e($cur_bank) ?>" selected><?= e($cur_bank) ?> (Bank Lain)</option>
                                                <?php endif; ?>
                                            </select>
                                            <div class="form-text small">Pilih bank jika tipe akun adalah Bank.</div>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Nomor Rekening</div>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm font-monospace" id="account_number" name="account_number" maxlength="50" value="<?= e($form['account_number']) ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Catatan</div>
                                        <div class="rnt-field">
                                            <textarea class="form-control form-control-sm rnt-ta-compact" id="note" name="note" rows="2" maxlength="2000"><?= e($form['note']) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-ca-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
