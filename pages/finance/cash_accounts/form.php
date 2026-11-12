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
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0"><?= e($title) ?></h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="index.php">
                <i class="bi bi-arrow-left me-1"></i>Batal
            </a>
            <button type="submit" form="formCashAccount" class="btn btn-primary btn-sm px-3">
                <i class="bi bi-check2 me-1"></i>Simpan
            </button>
        </div>
    </div>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 720px;">
        <div class="card-body">
            <form method="post" action="form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formCashAccount">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="code" name="code" maxlength="15"
                               value="<?= e($form['code']) ?>" required>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label" for="name">Nama <span class="text-danger">*</span></label>
                        <input class="form-control" id="name" name="name" maxlength="255" value="<?= e($form['name']) ?>" required>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="type">Tipe <span class="text-danger">*</span></label>
                        <select class="form-select" id="type" name="type" required>
                            <?php foreach (cash_types() as $k => $lbl): ?>
                                <option value="<?= e($k) ?>"<?= $form['type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="opening_balance">Saldo Awal</label>
                        <input class="form-control" type="number" step="0.01" id="opening_balance" name="opening_balance"
                               value="<?= e($form['opening_balance']) ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Aktif</label>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="bank_name">Nama Bank</label>
                        <?php
                        $bank_list = master_bank_options();
                        $cur_bank = (string) ($form['bank_name'] ?? '');
                        $matched_bank = false;
                        ?>
                        <select class="form-select ts-select" id="bank_name" name="bank_name">
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
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="account_number">Nomor Rekening</label>
                        <input class="form-control" id="account_number" name="account_number" maxlength="50" value="<?= e($form['account_number']) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="note">Catatan</label>
                    <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e($form['note']) ?></textarea>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
