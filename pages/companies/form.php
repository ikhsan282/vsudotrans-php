<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.manage');

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Membuat perusahaan baru: hanya Super Admin.
if ($id === 0 && !is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$row = ['group_id' => '', 'code' => '', 'name' => '', 'note' => '', 'country_code' => '+62',
        'phone_number' => '', 'website' => '', 'email' => '', 'address' => '', 'city' => '', 'is_active' => 1];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Perusahaan tidak ditemukan.');
        redirect('pages/companies/index.php');
    }
    assert_company_access((int) $found['id']);
    $row = $found;
}

$groups = $db->query('SELECT id, name FROM groups WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $row = [
        'group_id'     => (string) (int) ($_POST['group_id'] ?? 0),
        'code'         => strtoupper($in('code', 15)),
        'name'         => $in('name', 255),
        'note'         => $in('note', 2000),
        'country_code' => $in('country_code', 10) ?: '+62',
        'phone_number' => $in('phone_number', 20),
        'website'      => $in('website', 300),
        'email'        => $in('email', 200),
        'address'      => $in('address', 1000),
        'city'         => $in('city', 100),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];
    $group_id = (int) $row['group_id'];

    if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
        $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
    }
    if ($row['name'] === '') {
        $errors[] = 'Nama wajib diisi.';
    }
    if ($group_id === 0 || !in_array($group_id, array_column($groups, 'id'), false)) {
        $errors[] = 'Grup tidak valid.';
    }
    if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if ($row['website'] !== '' && !filter_var($row['website'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Format website tidak valid.';
    }

    if (!$errors) {
        $st = $db->prepare('SELECT id FROM companies WHERE code = ? AND id <> ?');
        $st->bind_param('si', $row['code'], $id);
        $st->execute();
        $dup = $st->get_result()->num_rows > 0;
        $st->close();
        if ($dup) {
            $errors[] = 'Kode sudah dipakai perusahaan lain.';
        }
    }

    if (!$errors) {
        $nul = fn (string $v): ?string => $v === '' ? null : $v;
        $user = $_SESSION['username'] ?? null;
        $vals = [$group_id, $row['code'], $row['name'], $nul($row['note']), $row['country_code'],
                 $nul($row['phone_number']), $nul($row['website']), $nul($row['email']),
                 $nul($row['address']), $nul($row['city']), $row['is_active']];
        try {
            if ($id > 0) {
                $st = $db->prepare('UPDATE companies SET group_id = ?, code = ?, name = ?, note = ?, country_code = ?,
                    phone_number = ?, website = ?, email = ?, address = ?, city = ?, is_active = ?,
                    modified_user = ?, modified_date = NOW() WHERE id = ?');
                $st->bind_param('isssssssssisi', ...[...$vals, $user, $id]);
                $st->execute();
                $st->close();
                audit('company.update', "Perusahaan #{$id} {$row['code']}");
                flash('success', 'Perusahaan berhasil diperbarui.');
            } else {
                $st = $db->prepare('INSERT INTO companies (group_id, code, name, note, country_code, phone_number,
                    website, email, address, city, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('isssssssssis', ...[...$vals, $user]);
                $st->execute();
                $new_id = $db->insert_id;
                $st->close();
                audit('company.create', "Perusahaan #{$new_id} {$row['code']}");
                flash('success', 'Perusahaan berhasil ditambahkan.');
            }
            redirect('pages/companies/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'Kode sudah dipakai perusahaan lain.';
            } else {
                throw $ex;
            }
        }
    }
}

$active = 'companies';
$title  = $id > 0 ? 'Ubah Perusahaan' : 'Tambah Perusahaan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="card shadow-sm border-0" style="max-width: 760px;">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/companies/form.php<?= $id > 0 ? '?id=' . $id : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="name">Nama <span class="text-danger">*</span></label>
                        <input class="form-control" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="group_id">Grup <span class="text-danger">*</span></label>
                        <select class="form-select" id="group_id" name="group_id" required>
                            <option value="">— pilih grup —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= (int) $g['id'] ?>" <?= (string) $g['id'] === (string) $row['group_id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="city">Kota</label>
                        <input class="form-control" id="city" name="city" maxlength="100" value="<?= e($row['city']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Alamat</label>
                        <textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e($row['address']) ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="country_code">Kode Negara</label>
                        <input class="form-control" id="country_code" name="country_code" maxlength="10" value="<?= e($row['country_code']) ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="phone_number">Telepon</label>
                        <input class="form-control" id="phone_number" name="phone_number" maxlength="20" value="<?= e($row['phone_number']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" type="email" id="email" name="email" maxlength="200" value="<?= e($row['email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="website">Website</label>
                        <input class="form-control" type="url" id="website" name="website" maxlength="300" value="<?= e($row['website']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">Catatan</label>
                        <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e($row['note']) ?></textarea>
                    </div>
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Aktif</label>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                    <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/companies/index.php">Batal</a>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
