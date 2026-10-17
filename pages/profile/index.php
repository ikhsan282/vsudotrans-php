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
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3">Profil Saya</h4>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Data Akun</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted fw-normal">Nama</dt>
                        <dd class="col-sm-8"><?= e($me['full_name'] ?? '—') ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Username</dt>
                        <dd class="col-sm-8"><?= e($me['username'] ?? '—') ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Email</dt>
                        <dd class="col-sm-8"><?= e($me['email'] ?? '—') ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">No. Telepon</dt>
                        <dd class="col-sm-8"><?= e(($me['phone_number'] ?? '') !== '' ? $me['phone_number'] : '—') ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Role</dt>
                        <dd class="col-sm-8"><span class="badge bg-secondary"><?= e($me['role_name'] ?? ($_SESSION['role_name'] ?? '—')) ?></span></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Perusahaan Aktif</dt>
                        <dd class="col-sm-8"><?= e($me['company_name'] ?? 'Lintas perusahaan') ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Login Terakhir</dt>
                        <dd class="col-sm-8"><?= e($me['last_login'] ?? '—') ?></dd>
                    </dl>
                    <p class="text-muted small mb-0 mt-3">Data akun hanya dapat diubah oleh administrator.</p>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Ganti Password</div>
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label">Password Lama</label>
                            <input type="password" name="password_lama" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password Baru</label>
                            <input type="password" name="password_baru" class="form-control" autocomplete="new-password" minlength="8" required>
                            <div class="form-text">Minimal 8 karakter.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" name="konfirmasi" class="form-control" autocomplete="new-password" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Simpan Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
