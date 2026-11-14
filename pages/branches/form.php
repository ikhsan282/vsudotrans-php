<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('branches.manage');

$db = db();
$company_id = active_company_id();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id === 0 && $company_id === null) {
    flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
    redirect('pages/branches/index.php');
}
$row = ['code' => '', 'name' => '', 'note' => '', 'phone_number' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM branches WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Cabang tidak ditemukan.');
        redirect('pages/branches/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $company_id = (int) $found['company_id']; // edit: scope ke company milik cabang
    $row = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $row = array_merge($row, [
        'code'         => strtoupper($in('code', 15)),
        'name'         => $in('name', 255),
        'note'         => $in('note', 2000),
        'phone_number' => normalize_phone_62($in('phone_number', 30)) ?? '',
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ]);

    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($row['name'] === '') {
        $errors[] = 'Nama wajib diisi.';
    }
    if (strlen($row['phone_number']) > 20) {
        $errors[] = 'Nomor telepon maksimal 20 digit.';
    }

    if (!$errors) {
        $st = $db->prepare('SELECT id FROM branches WHERE company_id = ? AND code = ? AND id <> ?');
        $st->bind_param('isi', $company_id, $row['code'], $id);
        $st->execute();
        $dup = $st->get_result()->num_rows > 0;
        $st->close();
        if ($dup) {
            $errors[] = 'Kode sudah dipakai cabang lain di perusahaan ini.';
        }
    }

    if (!$errors) {
        $nul = fn (string $v): ?string => $v === '' ? null : $v;
        $user = $_SESSION['username'] ?? null;
        try {
            if ($id > 0) {
                $st = $db->prepare('UPDATE branches SET code = ?, name = ?, note = ?, phone_number = ?,
                    is_active = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                $st->bind_param('ssssisi', $row['code'], $row['name'], $nul($row['note']),
                                $nul($row['phone_number']), $row['is_active'], $user, $id);
                $st->execute();
                $st->close();
                audit('branch.update', "Cabang #{$id} {$row['code']}");
                flash('success', 'Cabang berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO branches (company_id, code, name, note, phone_number, is_active, created_user)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('issssis', $company_id, $row['code'], $row['name'], $nul($row['note']),
                                $nul($row['phone_number']), $row['is_active'], $user);
                $st->execute();
                $new_id = $db->insert_id;
                $st->close();
                audit('branch.create', "Cabang #{$new_id} {$row['code']}");
                flash('success', 'Cabang berhasil ditambahkan.');
            }
            redirect('pages/branches/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'Kode sudah dipakai cabang lain di perusahaan ini.';
            } else {
                throw $ex;
            }
        }
    }
}

$active = 'branches';
$title  = $id > 0 ? ('Ubah Cabang - ' . $row['name']) : 'Tambah Cabang';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Cabang',
        'diagram-2',
        $id > 0 ? (string) $row['name'] : 'Tambah Cabang Baru',
        $id > 0 ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            isset($row['is_active']) ? ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'] : null,
        ])) : [['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border']],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'pages/branches/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
            ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formBranch', 'class' => 'btn btn-primary btn-sm px-3'],
        ]
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="branchFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-branch-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-branch-detail" type="button" role="tab">Detail</button>
            </li>
            <?php if ($id > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-branch-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-branch-audit" type="button" role="tab">Audit Trail</button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/branches/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formBranch">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="tab-content" id="branchFormTabContent">
                    <div class="tab-pane fade show active" id="tab-branch-detail" role="tabpanel" aria-labelledby="tab-branch-detail-btn">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header"><span>Informasi Cabang</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" for="code">Kode <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm text-uppercase font-monospace" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="name">Nama Cabang <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="phone_number">No. Telepon</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="phone_number" name="phone_number" maxlength="20" value="<?= e((string) ($row['phone_number'] ?? '')) ?>" placeholder="62812xxxxxxxx">
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1" for="note">Catatan</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm rnt-ta-compact" id="note" name="note" rows="2" maxlength="2000"><?= e($row['note']) ?></textarea>
                                    </div>
                                </div>
                                <div class="rnt-row mb-0">
                                    <label class="rnt-label" for="is_active">Status Aktif</label>
                                    <div class="rnt-field">
                                        <div class="rnt-check-wrap">
                                            <input class="form-check-input mt-0" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold" for="is_active">Cabang Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($id > 0): ?>
                        <div class="tab-pane fade" id="tab-branch-audit" role="tabpanel" aria-labelledby="tab-branch-audit-btn">
                            <?= ui_erp_audit_panel($row) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
