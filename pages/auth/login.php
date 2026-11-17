<?php
require_once __DIR__ . '/../../config/config.php';

// Sudah login: langsung ke dashboard.
if (!empty($_SESSION['user_id'])) {
    redirect('pages/dashboard/index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $error = login_user($username, $password);
        if ($error === null) {
            redirect('pages/dashboard/index.php');
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= e(APP_NAME) ?></title>
    <script>
      (function(){try{var t=localStorage.getItem('vst_theme');if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <button type="button" id="loginThemeToggle" class="btn btn-sm btn-outline-secondary auth-theme-toggle rounded-pill" aria-label="Ganti tema" title="Ganti tema">
            <i class="bi bi-moon-stars"></i>
        </button>

        <div class="text-center mb-4">
            <img src="<?= e(APP_URL) ?>/assets/brand/logo-transmandiri.png" alt="<?= e(APP_NAME) ?>" class="auth-brand-logo mb-2">
            <h4 class="fw-bold mb-1 auth-title"><?= e(APP_NAME) ?></h4>
            <p class="text-body-secondary small mb-0">Masuk ke Panel Manajemen Travel, Bus &amp; Rental</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold small">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required autofocus
                           value="<?= e($_POST['username'] ?? '') ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold small">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required>
                    <button type="button" class="btn btn-outline-secondary" id="toggleLoginPwd" aria-label="Tampilkan atau sembunyikan password" title="Tampilkan / Sembunyikan Password">
                        <i class="bi bi-eye" id="loginPwdEye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-semibold py-2 mt-1">
                <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
            </button>
        </form>

        <div class="text-center mt-3 pt-3 border-top">
            <a href="<?= e(APP_URL) ?>/index.php" class="small text-body-secondary text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Kembali ke Beranda
            </a>
        </div>
    </div>
</div>
    <script>
      (function(){
        var btn = document.getElementById('loginThemeToggle');
        if (btn) {
          function sync() {
            var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var icon = btn.querySelector('i');
            if (icon) {
              icon.classList.toggle('bi-moon-stars', !dark);
              icon.classList.toggle('bi-sun', dark);
            }
            var label = dark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap';
            btn.setAttribute('title', label);
            btn.setAttribute('aria-label', label);
          }
          sync();
          btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('vst_theme', next); } catch (e) {}
            sync();
          });
        }
        var pwdBtn = document.getElementById('toggleLoginPwd');
        var pwdInp = document.getElementById('password');
        var pwdEye = document.getElementById('loginPwdEye');
        if (pwdBtn && pwdInp && pwdEye) {
          pwdBtn.addEventListener('click', function () {
            var show = pwdInp.type === 'password';
            pwdInp.type = show ? 'text' : 'password';
            pwdEye.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
          });
        }
      })();
    </script>
</body>
</html>
