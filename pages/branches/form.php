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
$row = ['code' => '', 'name' => '', 'note' => '', 'country_code' => '+62', 'phone_number' => '', 'is_active' => 1];

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
    $row = [
        'code'         => strtoupper($in('code', 15)),
        'name'         => $in('name', 255),
        'note'         => $in('note', 2000),
        'country_code' => $in('country_code', 10) ?: '+62',
        'phone_number' => $in('phone_number', 20),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($row['name'] === '') {
        $errors[] = 'Nama wajib diisi.';
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
                $st = $db->prepare('UPDATE branches SET code = ?, name = ?, note = ?, country_code = ?, phone_number = ?,
                    is_active = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                $st->bind_param('sssssisi', $row['code'], $row['name'], $nul($row['note']), $row['country_code'],
                                $nul($row['phone_number']), $row['is_active'], $user, $id);
                $st->execute();
                $st->close();
                audit('branch.update', "Cabang #{$id} {$row['code']}");
                flash('success', 'Cabang berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO branches (company_id, code, name, note, country_code, phone_number, is_active, created_user)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('isssssis', $company_id, $row['code'], $row['name'], $nul($row['note']),
                                $row['country_code'], $nul($row['phone_number']), $row['is_active'], $user);
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
$title  = $id > 0 ? 'Ubah Cabang' : 'Tambah Cabang';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 640px;">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/branches/form.php<?= $id > 0 ? '?id=' . $id : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="mb-3">
                    <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                    <input class="form-control text-uppercase" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="name">Nama <span class="text-danger">*</span></label>
                    <input class="form-control" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="country_code">Kode Negara</label>
                        <input class="form-control" id="country_code" name="country_code" maxlength="10" value="<?= e($row['country_code']) ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="phone_number">Telepon</label>
                        <input class="form-control" id="phone_number" name="phone_number" maxlength="20" value="<?= e($row['phone_number']) ?>">
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label" for="note">Catatan</label>
                    <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e($row['note']) ?></textarea>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Aktif</label>
                </div>
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/branches/index.php">Batal</a>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
