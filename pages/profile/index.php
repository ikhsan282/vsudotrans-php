<?php
/**
 * Halaman "Profil Saya": data akun (read-only) + ganti password sendiri.
 * Tidak memakai require_permission()/require_active_company(): semua user login boleh,
 * dan data diambil dari $_SESSION + DB sendiri agar tidak mati saat super admin belum pilih perusahaan.
 */
require_once __DIR__ . '/../../config/config.php';
require_login();
require_once __DIR__ . '/_helpers.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$db  = db();

$st   = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
$st->bind_param('i', $uid);
$st->execute();
$hash = (string) ($st->get_result()->fetch_row()[0] ?? '');
$st->close();
if ($hash === '') {
    logout_user();
    redirect('pages/auth/login.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $lama       = (string) ($_POST['password_lama'] ?? '');
    $baru       = (string) ($_POST['password_baru'] ?? '');
    $konfirmasi = (string) ($_POST['konfirmasi'] ?? '');

    $errors = profile_password_errors($lama, $baru, $konfirmasi, $hash);
    if (!$errors) {
        if (profile_change_password($db, $uid, $lama, $baru, $konfirmasi, $hash)) {
            audit('user.password', 'Ganti password sendiri');
            session_regenerate_id(true); // sesi lama tidak lagi valid
            flash('success', 'Password berhasil diganti.');
            redirect('pages/profile/index.php');
        }
        $errors[] = 'Password gagal disimpan. Coba lagi.';
    }
}

$me = profile_fetch($db, $uid) ?? [];

$active = 'profile';
$title  = 'Profil Saya';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Akun',
        'person-circle',
        'Profil Saya',
        [$me['role_name'] ?? ($_SESSION['role_name'] ?? '-')],
        [
            ['label' => 'Simpan Password', 'icon' => 'shield-lock', 'type' => 'submit', 'form' => 'formProfilePassword', 'class' => 'btn-primary'],
        ]
    ) ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-profile-detail" data-bs-toggle="tab" data-bs-target="#pane-profile-detail" type="button" role="tab">Profil &amp; Keamanan</button>
            </li>
        </ul>
        <div class="erp-content-body tab-content">
            <div class="tab-pane fade show active" id="pane-profile-detail" role="tabpanel">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header"><i class="bi bi-person-vcard"></i> Data Akun</div>
                            <div class="rnt-panel-body">
                                <?= ui_erp_ro_row('Nama', $me['full_name'] ?? null) ?>
                                <?= ui_erp_ro_row('Username', $me['username'] ?? null, false, true) ?>
                                <?= ui_erp_ro_row('Email', $me['email'] ?? null) ?>
                                <?= ui_erp_ro_row('No. Telepon', ($me['phone_number'] ?? '') !== '' ? $me['phone_number'] : null) ?>
                                <?= ui_erp_ro_html_row('Role', '<span class="badge bg-secondary">' . e($me['role_name'] ?? ($_SESSION['role_name'] ?? '-')) . '</span>') ?>
                                <?= ui_erp_ro_row('Perusahaan Aktif', $me['company_name'] ?? 'Lintas perusahaan') ?>
                                <?= ui_erp_ro_row('Login Terakhir', $me['last_login'] ?? null) ?>
                                <div class="px-3 py-2 text-muted small border-top">Data akun hanya dapat diubah oleh administrator.</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <form method="post" autocomplete="off" id="formProfilePassword">
                            <?= csrf_field() ?>
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-key"></i> Ganti Password</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Password Lama <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="password" name="password_lama" class="form-control form-control-sm" autocomplete="current-password" required>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Password Baru <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="password" name="password_baru" class="form-control form-control-sm" autocomplete="new-password" minlength="8" required>
                                            <div class="form-text small">Minimal 8 karakter.</div>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Konfirmasi Password <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="password" name="konfirmasi" class="form-control form-control-sm" autocomplete="new-password" minlength="8" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
