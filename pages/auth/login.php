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
</head>
<body class="bg-body-tertiary d-flex align-items-center justify-content-center vh-100 position-relative">
    <button type="button" id="loginThemeToggle" class="btn btn-sm btn-outline-secondary position-fixed top-0 end-0 m-3" aria-label="Ganti tema" title="Ganti tema">
        <i class="bi bi-moon-stars"></i>
    </button>
    <div class="card shadow-sm" style="width: 100%; max-width: 380px;">
        <div class="card-body p-4">
            <h4 class="fw-bold text-center mb-1"><?= e(APP_NAME) ?></h4>
            <p class="text-muted text-center small mb-4">Masuk untuk melanjutkan</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required autofocus
                           value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Masuk</button>
            </form>
        </div>
    </div>
    <script>
      (function(){
        var btn = document.getElementById('loginThemeToggle');
        if (!btn) return;
        function sync() {
          var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
          var icon = btn.querySelector('i');
          if (icon) {
            icon.classList.toggle('bi-moon-stars', !dark);
            icon.classList.toggle('bi-sun', dark);
          }
          btn.setAttribute('title', dark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap');
        }
        sync();
        btn.addEventListener('click', function () {
          var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
          document.documentElement.setAttribute('data-bs-theme', next);
          try { localStorage.setItem('vst_theme', next); } catch (e) {}
          sync();
        });
      })();
    </script>
</body>
</html>
