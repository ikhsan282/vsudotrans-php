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

$fullName = trim((string) ($me['full_name'] ?? '')) ?: 'Pengguna';
$username = trim((string) ($me['username'] ?? '')) ?: '-';
$roleName = trim((string) ($me['role_name'] ?? ($_SESSION['role_name'] ?? ''))) ?: '-';
$email    = trim((string) ($me['email'] ?? ''));
$phone    = trim((string) ($me['phone_number'] ?? ''));
$company  = trim((string) ($me['company_name'] ?? '')) ?: 'Lintas perusahaan';

$nameParts = preg_split('/\s+/', $fullName !== 'Pengguna' ? $fullName : $username) ?: ['U'];
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
if ($initials === '') {
    $initials = 'U';
}

$lastLoginRaw   = trim((string) ($me['last_login'] ?? ''));
$lastLoginTs    = $lastLoginRaw !== '' ? strtotime($lastLoginRaw) : false;
$lastLoginLabel = $lastLoginTs !== false ? (date('d M Y, H:i', $lastLoginTs) . ' WIB') : 'Belum tercatat';

$active = 'profile';
$title  = 'Profil Saya';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1 min-w-0">
    <?= ui_page_header(
        'Profil Saya',
        ['Dashboard' => 'pages/dashboard/index.php', 'Profil Saya' => null],
        [
            ['text' => $roleName, 'class' => 'bg-primary-subtle text-primary border border-primary-subtle'],
        ],
        [
            ['label' => 'Kembali ke Dashboard', 'icon' => 'arrow-left', 'href' => 'pages/dashboard/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
        ],
        'Informasi akun dan pengaturan keamanan kata sandi'
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3 d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0" aria-hidden="true"></i>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 h-100 d-flex flex-column">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-person-vcard me-1 text-primary" aria-hidden="true"></i>Data Akun</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif</span>
                </div>
                <div class="card-body flex-grow-1">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="profile-avatar" aria-hidden="true"><?= e($initials) ?></div>
                        <div class="min-w-0">
                            <h5 class="fw-bold mb-1 text-truncate"><?= e($fullName) ?></h5>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="font-monospace small text-muted">@<?= e($username) ?></span>
                                <span class="badge bg-secondary-subtle text-secondary border"><?= e($roleName) ?></span>
                            </div>
                        </div>
                    </div>

                    <ul class="profile-info-list">
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-at" aria-hidden="true"></i>Username</span>
                            <span class="profile-info-value font-monospace"><?= e($username) ?></span>
                        </li>
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-envelope" aria-hidden="true"></i>Email</span>
                            <span class="profile-info-value">
                                <?php if ($email !== ''): ?>
                                    <a href="mailto:<?= e($email) ?>" class="text-decoration-none"><?= e($email) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-telephone" aria-hidden="true"></i>No. Telepon</span>
                            <span class="profile-info-value font-monospace">
                                <?= $phone !== '' ? e($phone) : '<span class="text-muted fw-normal">-</span>' ?>
                            </span>
                        </li>
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-person-badge" aria-hidden="true"></i>Role</span>
                            <span class="profile-info-value">
                                <span class="badge bg-secondary"><?= e($roleName) ?></span>
                            </span>
                        </li>
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-building" aria-hidden="true"></i>Perusahaan</span>
                            <span class="profile-info-value"><?= e($company) ?></span>
                        </li>
                        <li class="profile-info-item">
                            <span class="profile-info-label"><i class="bi bi-clock-history" aria-hidden="true"></i>Login Terakhir</span>
                            <span class="profile-info-value"><?= e($lastLoginLabel) ?></span>
                        </li>
                    </ul>
                </div>
                <div class="card-footer bg-transparent border-top small text-muted py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
                    <span>Data akun hanya dapat diubah oleh administrator.</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <form method="post" autocomplete="off" id="formProfilePassword" class="card shadow-sm border-0 h-100 d-flex flex-column">
                <?= csrf_field() ?>
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-shield-lock me-1 text-primary" aria-hidden="true"></i>Ganti Password</span>
                    <span class="small fw-normal text-muted">Keamanan Akun</span>
                </div>
                <div class="card-body flex-grow-1">
                    <div class="mb-3">
                        <label for="profilePwOld" class="form-label small fw-semibold mb-1">
                            Password Lama <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="profilePwOld" name="password_lama" class="form-control" autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary profile-pw-toggle" data-pw-target="profilePwOld" aria-label="Tampilkan password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="profilePwNew" class="form-label small fw-semibold mb-1">
                            Password Baru <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="profilePwNew" name="password_baru" class="form-control" autocomplete="new-password" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary profile-pw-toggle" data-pw-target="profilePwNew" aria-label="Tampilkan password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="profile-pw-meter" aria-hidden="true">
                            <div class="profile-pw-meter-bar" id="profilePwBar"></div>
                        </div>
                        <div class="form-text small mb-0" id="profilePwHint">Minimal 8 karakter.</div>
                    </div>

                    <div class="mb-3">
                        <label for="profilePwConfirm" class="form-label small fw-semibold mb-1">
                            Konfirmasi Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="password" id="profilePwConfirm" name="konfirmasi" class="form-control" autocomplete="new-password" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary profile-pw-toggle" data-pw-target="profilePwConfirm" aria-label="Tampilkan password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="form-text small mb-0" id="profilePwMatch"></div>
                    </div>

                    <div class="profile-security-tip d-flex align-items-start gap-2 mb-0">
                        <i class="bi bi-shield-check text-primary mt-1 flex-shrink-0" aria-hidden="true"></i>
                        <span>Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol agar akun lebih aman. Sesi aktif akan diperbarui otomatis setelah password disimpan.</span>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top py-2 px-3 d-flex justify-content-end gap-2">
                    <button type="reset" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Simpan Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('formProfilePassword');
    var newIn = document.getElementById('profilePwNew');
    var confirmIn = document.getElementById('profilePwConfirm');
    var barEl = document.getElementById('profilePwBar');
    var hintEl = document.getElementById('profilePwHint');
    var matchEl = document.getElementById('profilePwMatch');

    document.querySelectorAll('.profile-pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-pw-target');
            var input = targetId ? document.getElementById(targetId) : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }
        });
    });

    function updateStrength() {
        if (!newIn || !barEl || !hintEl) return;
        var v = newIn.value || '';
        if (v === '') {
            barEl.style.width = '0%';
            barEl.style.backgroundColor = '';
            hintEl.textContent = 'Minimal 8 karakter.';
            hintEl.className = 'form-text small mb-0';
            return;
        }
        var score = 0;
        if (v.length >= 8) score++;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;

        if (v.length < 8) {
            barEl.style.width = '25%';
            barEl.style.backgroundColor = '#dc3545';
            hintEl.textContent = 'Terlalu pendek (' + v.length + '/8 karakter).';
            hintEl.className = 'form-text small mb-0 text-danger';
        } else if (score <= 2) {
            barEl.style.width = '50%';
            barEl.style.backgroundColor = '#f59e0b';
            hintEl.textContent = 'Kekuatan password: Cukup. Tambahkan huruf kapital, angka, atau simbol.';
            hintEl.className = 'form-text small mb-0 text-warning-emphasis';
        } else if (score <= 3) {
            barEl.style.width = '75%';
            barEl.style.backgroundColor = '#0d6efd';
            hintEl.textContent = 'Kekuatan password: Baik.';
            hintEl.className = 'form-text small mb-0 text-primary';
        } else {
            barEl.style.width = '100%';
            barEl.style.backgroundColor = '#198754';
            hintEl.textContent = 'Kekuatan password: Kuat.';
            hintEl.className = 'form-text small mb-0 text-success';
        }
    }

    function updateMatch() {
        if (!newIn || !confirmIn || !matchEl) return;
        var a = newIn.value || '';
        var b = confirmIn.value || '';
        if (b === '') {
            matchEl.textContent = '';
            matchEl.className = 'form-text small mb-0';
            return;
        }
        if (a === b) {
            matchEl.textContent = 'Konfirmasi password cocok.';
            matchEl.className = 'form-text small mb-0 text-success';
        } else {
            matchEl.textContent = 'Konfirmasi password belum cocok.';
            matchEl.className = 'form-text small mb-0 text-danger';
        }
    }

    if (newIn) {
        newIn.addEventListener('input', function () {
            updateStrength();
            updateMatch();
        });
    }
    if (confirmIn) {
        confirmIn.addEventListener('input', updateMatch);
    }
    if (form) {
        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                updateStrength();
                updateMatch();
                form.querySelectorAll('.profile-pw-toggle').forEach(function (btn) {
                    var targetId = btn.getAttribute('data-pw-target');
                    var input = targetId ? document.getElementById(targetId) : null;
                    if (input) input.type = 'password';
                    btn.setAttribute('aria-label', 'Tampilkan password');
                    var icon = btn.querySelector('i');
                    if (icon) {
                        icon.classList.add('bi-eye');
                        icon.classList.remove('bi-eye-slash');
                    }
                });
            }, 0);
        });
    }
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
