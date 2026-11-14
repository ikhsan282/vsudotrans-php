<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('users.manage');

$db  = db();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$uid = (int) ($_SESSION['user_id'] ?? 0);

// Level aktor (angka lebih kecil = role lebih tinggi). Default PHP_INT_MAX = tolak bila tidak ditemukan.
$st = $db->prepare('SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
$st->bind_param('i', $uid);
$st->execute();
$actorLevel = (int) ($st->get_result()->fetch_row()[0] ?? PHP_INT_MAX);
$st->close();

$roles     = $db->query('SELECT id, name, level FROM roles ORDER BY level')->fetch_all(MYSQLI_ASSOC);
$superId   = (int) ($db->query("SELECT id FROM roles WHERE name = 'Super Admin' LIMIT 1")->fetch_row()[0] ?? 0);
$companies = $db->query('SELECT id, code, name FROM companies ORDER BY name')->fetch_all(MYSQLI_ASSOC);
if (!is_super_admin()) {
    // Non-Super Admin hanya boleh menetapkan user ke perusahaan aktifnya.
    $companies = array_values(array_filter($companies, fn (array $c): bool => (int) $c['id'] === active_company_id()));
}

$row = ['username' => '', 'email' => '', 'full_name' => '', 'phone_number' => '',
        'role_id' => 0, 'company_id' => '', 'is_active' => 1];

if ($id > 0) {
    // Kelola user Super Admin hanya boleh oleh Super Admin.
    $st = $db->prepare('SELECT u.*, r.name AS role_name, r.level AS role_level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Pengguna tidak ditemukan.');
        redirect('pages/users/index.php');
    }
    assert_company_access($found['company_id'] !== null ? (int) $found['company_id'] : null);
    if ($found['role_name'] === 'Super Admin' && !is_super_admin()) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
    // Non-Super hanya boleh mengubah user berlevel lebih rendah, atau dirinya sendiri.
    if (!is_super_admin() && (int) $found['id'] !== $uid && (int) $found['role_level'] <= $actorLevel) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
    $row = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $row = array_merge($row, [
        'username'     => strtolower($in('username', 50)),
        'email'        => $in('email', 100),
        'full_name'    => $in('full_name', 100),
        'phone_number' => normalize_phone_62($in('phone_number', 30)) ?? '',
        'role_id'      => (int) ($_POST['role_id'] ?? 0),
        'company_id'   => (string) (int) ($_POST['company_id'] ?? 0),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ]);
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $company_id = (int) $row['company_id'];

    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $row['username'])) {
        $errors[] = 'Username 3–50 karakter: huruf kecil, angka, titik, strip, atau garis bawah.';
    }
    if ($row['full_name'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    }
    if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if (strlen($row['phone_number']) > 20) {
        $errors[] = 'No. Telepon maksimal 20 digit (format 62...).';
    }
    if (!in_array($row['role_id'], array_column($roles, 'id'), false)) {
        $errors[] = 'Role tidak valid.';
    } elseif (!is_super_admin()) {
        // Server-side: role target harus berlevel sama atau lebih rendah dari aktor (angka >=).
        foreach ($roles as $r) {
            if ((int) $r['id'] === $row['role_id'] && (int) $r['level'] < $actorLevel) {
                $errors[] = 'Anda tidak dapat menetapkan role yang lebih tinggi dari role Anda.';
            }
        }
    }
    // Ubah password diri sendiri (non-Super) wajib password lama yang benar.
    if (!is_super_admin() && $id > 0 && $id === $uid && $password !== '') {
        $cur = (string) ($_POST['current_password'] ?? '');
        if ($cur === '' || !password_verify($cur, $found['password'])) {
            $errors[] = 'Password lama tidak sesuai.';
        }
    }
    if ($company_id > 0 && !in_array($company_id, array_column($companies, 'id'), false)) {
        $errors[] = 'Perusahaan tidak valid.';
    }

    // Aturan pembuatan/penyimpanan role: hanya Super Admin boleh menetapkan Super Admin.
    $is_super_target = $row['role_id'] === $superId;
    if ($is_super_target && !is_super_admin()) {
        $errors[] = 'Hanya Super Admin yang boleh membuat atau mengubah pengguna dengan role Super Admin.';
    }
    // Tidak boleh menaikkan role diri sendiri menjadi Super Admin.
    if ($id === $uid && $is_super_target && !is_super_admin()) {
        $errors[] = 'Anda tidak dapat memberi role Super Admin kepada diri sendiri.';
    }
    // User non-super tidak boleh lintas perusahaan.
    if ($company_id === 0 && !is_super_admin()) {
        $errors[] = 'Perusahaan wajib dipilih (hanya Super Admin yang boleh lintas perusahaan).';
    }

    if ($id === 0 && $password === '') {
        $errors[] = 'Password wajib diisi untuk pengguna baru.';
    }
    if ($password !== '') {
        if (strlen($password) < 8) {
            $errors[] = 'Password minimal 8 karakter.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Konfirmasi password tidak cocok.';
        }
    }

    // Non-Super Admin hanya boleh menetapkan user ke perusahaan aktif miliknya.
    if (!is_super_admin() && $company_id !== active_company_id()) {
        $errors[] = 'Perusahaan harus perusahaan aktif Anda.';
    }

    if (!$errors) {
        $st = $db->prepare('SELECT 1 FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $st->bind_param('ssi', $row['username'], $row['email'], $id);
        $st->execute();
        $dup = $st->get_result()->num_rows > 0;
        $st->close();
        if ($dup) {
            $errors[] = 'Username atau email sudah dipakai.';
        }
    }

    if (!$errors) {
        $phone = $row['phone_number'] === '' ? null : $row['phone_number'];
        $cid   = $company_id > 0 ? $company_id : null;
        $uname = $row['username'];
        $mail  = $row['email'];
        $fname = $row['full_name'];
        $rid   = $row['role_id'];
        $act   = $row['is_active'];
        try {
            if ($id > 0) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $st = $db->prepare('UPDATE users SET username = ?, email = ?, full_name = ?, phone_number = ?,
                        role_id = ?, company_id = ?, is_active = ?, password = ? WHERE id = ?');
                    $st->bind_param('ssssiiisi', $uname, $mail, $fname, $phone, $rid, $cid, $act, $hash, $id);
                } else {
                    $st = $db->prepare('UPDATE users SET username = ?, email = ?, full_name = ?, phone_number = ?,
                        role_id = ?, company_id = ?, is_active = ? WHERE id = ?');
                    $st->bind_param('ssssiiii', $uname, $mail, $fname, $phone, $rid, $cid, $act, $id);
                }
                $st->execute();
                $st->close();
                audit('user.update', "Pengguna #{$id} {$uname}");
                flash('success', 'Pengguna berhasil diperbarui.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $st = $db->prepare('INSERT INTO users (username, email, password, full_name, phone_number, role_id, company_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('sssssiii', $uname, $mail, $hash, $fname, $phone, $rid, $cid, $act);
                $st->execute();
                $new_id = (int) $db->insert_id;
                $st->close();
                audit('user.create', "Pengguna #{$new_id} {$uname}");
                flash('success', 'Pengguna berhasil ditambahkan.');
            }
            redirect('pages/users/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'Username atau email sudah dipakai.';
            } else {
                throw $ex;
            }
        }
    }
}

$active = 'users';
$title  = $id > 0 ? 'Ubah Pengguna' : 'Tambah Pengguna';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Pengguna',
        'person-badge',
        $title,
        $id > 0 ? [['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => !empty($row['is_active']) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary']] : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => APP_URL . '/pages/users/index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formUser', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <form method="post" id="formUser">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-user-detail" data-bs-toggle="tab" data-bs-target="#pane-user-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-user-audit" data-bs-toggle="tab" data-bs-target="#pane-user-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-user-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-person-lines-fill"></i> Identitas Pengguna</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Username <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="text" name="username" class="form-control form-control-sm" value="<?= e($row['username']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Nama Lengkap <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="text" name="full_name" class="form-control form-control-sm" value="<?= e($row['full_name']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Email <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="email" name="email" class="form-control form-control-sm" value="<?= e($row['email']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">No. Telepon</div>
                                        <div class="rnt-field">
                                            <input type="text" name="phone_number" class="form-control form-control-sm" maxlength="20" placeholder="62812xxxxxxxx" value="<?= e($row['phone_number'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Status</div>
                                        <div class="rnt-field">
                                            <div class="rnt-check-wrap">
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"<?= $row['is_active'] ? ' checked' : '' ?>>
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
                                <div class="rnt-panel-header"><i class="bi bi-shield-lock"></i> Akses &amp; Keamanan</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Role <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select name="role_id" class="form-select form-select-sm" required>
                                                <option value="">— pilih role —</option>
                                                <?php foreach ($roles as $r): ?>
                                                    <?php if (!is_super_admin() && ((int) $r['level'] < $actorLevel)) continue; ?>
                                                    <option value="<?= (int) $r['id'] ?>"<?= (int) $row['role_id'] === (int) $r['id'] ? ' selected' : '' ?>>
                                                        <?= e($r['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Perusahaan<?= !is_super_admin() ? ' <span class="text-danger">*</span>' : '' ?></div>
                                        <div class="rnt-field">
                                            <select name="company_id" class="form-select form-select-sm"<?= is_super_admin() ? '' : ' required' ?>>
                                                <?php if (is_super_admin()): ?>
                                                    <option value="0"<?= !$row['company_id'] ? ' selected' : '' ?>>— lintas perusahaan —</option>
                                                <?php endif; ?>
                                                <?php foreach ($companies as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>"<?= (int) $row['company_id'] === (int) $c['id'] ? ' selected' : '' ?>>
                                                        <?= e($c['name']) ?> (<?= e($c['code']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row rnt-row-top">
                                        <div class="rnt-label">Password<?= $id === 0 ? ' <span class="text-danger">*</span>' : '' ?></div>
                                        <div class="rnt-field">
                                            <input type="password" name="password" class="form-control form-control-sm" autocomplete="new-password"<?= $id === 0 ? ' required' : '' ?>>
                                            <?php if ($id > 0): ?><div class="form-text small">Kosongkan bila tidak diubah.</div><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Konfirmasi Password</div>
                                        <div class="rnt-field">
                                            <input type="password" name="password_confirm" class="form-control form-control-sm" autocomplete="new-password">
                                        </div>
                                    </div>
                                    <?php if ($id > 0 && $id === $uid && !is_super_admin()): ?>
                                        <div class="rnt-row rnt-row-top">
                                            <div class="rnt-label">Password Lama</div>
                                            <div class="rnt-field">
                                                <input type="password" name="current_password" class="form-control form-control-sm" autocomplete="current-password">
                                                <div class="form-text small">Wajib diisi jika mengubah password sendiri.</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-user-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row, 'mb-0', ui_erp_ro_row('Login Terakhir', $row['last_login'] ?? null)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
