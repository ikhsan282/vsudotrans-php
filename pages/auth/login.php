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
            $_SESSION['vst_login_reveal'] = 1;
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= e(APP_URL) ?>/assets/css/login.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/login.css') ?>" rel="stylesheet">
    <script defer src="<?= e(APP_URL) ?>/assets/js/login_scene.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/login_scene.js') ?>"></script>
</head>
<body class="lgx-body">
<div id="loginPage" class="lgx">
    <!-- ================= KIRI: Story & Interactive Transport Stage ================= -->
    <section class="lgx-story" id="lgxStory" aria-label="VSudoTrans Fleet & Operations Platform">
        <svg class="lgx-contours" viewBox="0 0 700 900" fill="none" aria-hidden="true">
            <g stroke="#93c5fd" stroke-width=".75">
                <ellipse cx="580" cy="240" rx="200" ry="340" transform="rotate(-28 580 240)" />
                <ellipse cx="580" cy="240" rx="245" ry="388" transform="rotate(-28 580 240)" />
                <ellipse cx="580" cy="240" rx="290" ry="436" transform="rotate(-28 580 240)" />
                <ellipse cx="580" cy="240" rx="335" ry="484" transform="rotate(-28 580 240)" />
                <ellipse cx="580" cy="240" rx="380" ry="532" transform="rotate(-28 580 240)" />
            </g>
        </svg>

        <!-- Pembatas gelombang SVG kanan + garis aksen emas -->
        <svg class="lgx-seam" viewBox="0 0 50 1000" preserveAspectRatio="none" aria-hidden="true">
            <path class="lgx-seam-fill" d="M50 0H36C12 180 44 330 24 500S8 820 38 1000H50Z" />
            <path d="M36 0C12 180 44 330 24 500S8 820 38 1000" fill="none" stroke="#f59e0b" stroke-opacity=".8"
                  stroke-width="1.8" vector-effect="non-scaling-stroke" />
        </svg>

        <header class="lgx-intro" id="lgxBrand">
            <div class="lgx-tilt" id="lgxTilt">
                <div class="lgx-logo">
                    <img src="<?= e(APP_URL) ?>/assets/brand/logo-transmandiri.png" alt="<?= e(APP_NAME) ?>">
                </div>
                <div>
                    <p class="lgx-kicker">Fleet &amp; Mobility, Connected</p>
                    <h1 class="lgx-title">
                        <span>VSudoTrans</span>
                        <span class="lgx-t2">Fleet, Travel &amp; Finance ERP</span>
                    </h1>
                </div>
            </div>
            <p class="lgx-lead">Satu kendali terpadu untuk pemesanan travel, sewa kendaraan multi-hari, penjadwalan armada, kas, QRIS, hingga analitik pelanggan.</p>
        </header>

        <div class="lgx-stage">
            <div class="lgx-scene" id="lgxScene" role="img"
                 aria-label="Ilustrasi panggung armada travel dan sewa kendaraan VSudoTrans beserta kartu pratinjau metrik operasional.">
                <svg class="lgx-back" viewBox="0 0 800 450" aria-hidden="true">
                    <ellipse cx="400" cy="232" rx="336" ry="150" fill="none" stroke="#93c5fd" stroke-opacity=".14" transform="rotate(-12 400 232)" />
                    <ellipse cx="400" cy="232" rx="304" ry="118" fill="none" stroke="#93c5fd" stroke-opacity=".18" transform="rotate(14 400 232)" />
                    <circle cx="400" cy="224" r="214" fill="none" stroke="#bfdbfe" stroke-opacity=".22" />
                </svg>

                <div class="lgx-orbit" aria-hidden="true"></div>
                <i class="lgx-spark" style="--x:25%;--y:10%" aria-hidden="true"></i>
                <i class="lgx-spark" style="--x:74%;--y:7%;--d:-1.4s" aria-hidden="true"></i>
                <i class="lgx-spark" style="--x:21%;--y:54%;--d:-2.6s" aria-hidden="true"></i>
                <i class="lgx-spark" style="--x:79%;--y:59%;--d:-.7s" aria-hidden="true"></i>

                <!-- Garis konektor kartu melayang ↔ panggung utama -->
                <svg class="lgx-links" viewBox="0 0 800 450" aria-hidden="true">
                    <path class="lk" d="M174 62C206 62 220 82 246 102M174 205C194 205 200 205 210 205M174 345C194 345 202 332 212 320M626 62C594 62 580 82 554 102M626 205C606 205 600 205 590 205M626 345C606 345 598 332 588 320" />
                    <path class="fl" d="M174 62C206 62 220 82 246 102M174 205C194 205 200 205 210 205M174 345C194 345 202 332 212 320M626 62C594 62 580 82 554 102M626 205C606 205 600 205 590 205M626 345C606 345 598 332 588 320" />
                    <g fill="#fbbf24">
                        <circle cx="246" cy="102" r="2.8" />
                        <circle cx="210" cy="205" r="2.8" />
                        <circle cx="212" cy="320" r="2.8" />
                        <circle cx="554" cy="102" r="2.8" />
                        <circle cx="590" cy="205" r="2.8" />
                        <circle cx="588" cy="320" r="2.8" />
                    </g>
                </svg>

                <!-- Jendela lengkung: armada shuttle/bus melintas antar-kota -->
                <div class="lgx-arch" aria-hidden="true">
                    <svg class="lgx-arch-svg" viewBox="0 0 400 340" preserveAspectRatio="xMidYMid slice">
                        <defs>
                            <linearGradient id="vstSky" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#071a38" />
                                <stop offset=".48" stop-color="#13407c" />
                                <stop offset=".85" stop-color="#2563eb" />
                                <stop offset="1" stop-color="#f59e0b" stop-opacity=".35" />
                            </linearGradient>
                            <radialGradient id="vstGlow">
                                <stop offset="0" stop-color="#fde68a" stop-opacity=".9" />
                                <stop offset=".45" stop-color="#38bdf8" stop-opacity=".32" />
                                <stop offset="1" stop-color="#0b2550" stop-opacity="0" />
                            </radialGradient>
                            <linearGradient id="vstRoad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#1e293b" />
                                <stop offset="1" stop-color="#090f1d" />
                            </linearGradient>
                        </defs>
                        <rect width="400" height="340" fill="url(#vstSky)" />
                        <circle cx="200" cy="175" r="125" fill="url(#vstGlow)" />
                        <!-- Skyline kota & jembatan tol -->
                        <g fill="#0c244b" opacity=".88">
                            <rect x="26" y="135" width="26" height="90" rx="2" />
                            <rect x="58" y="114" width="32" height="111" rx="2" />
                            <rect x="96" y="148" width="22" height="77" rx="2" />
                            <rect x="280" y="128" width="28" height="97" rx="2" />
                            <rect x="314" y="106" width="34" height="119" rx="2" />
                            <rect x="354" y="142" width="24" height="83" rx="2" />
                        </g>
                        <!-- Peta rute lengkung antar hub -->
                        <path d="M55 156Q200 72 345 148" fill="none" stroke="#7dd3fc" stroke-width="1.8" stroke-dasharray="5 5" opacity=".75" />
                        <circle cx="55" cy="156" r="5" fill="#fbbf24" />
                        <circle cx="55" cy="156" r="6" fill="none" stroke="#fbbf24" stroke-width="1.5" class="lgx-hub-ping" />
                        <circle cx="345" cy="148" r="5" fill="#38bdf8" />
                        <circle cx="345" cy="148" r="6" fill="none" stroke="#38bdf8" stroke-width="1.5" class="lgx-hub-ping" />
                        <!-- Jalan raya -->
                        <path d="M0 220H400V340H0Z" fill="url(#vstRoad)" />
                        <path d="M0 220H400" stroke="#38bdf8" stroke-opacity=".45" stroke-width="2" />
                        <path d="M18 265H68M104 265H158M196 265H250M288 265H342" stroke="#fbbf24" stroke-width="3" stroke-linecap="round" opacity=".8" />
                        <!-- Armada Executive Shuttle / Hiace -->
                        <g class="lgx-shuttle">
                            <ellipse cx="200" cy="252" rx="92" ry="7" fill="#020617" opacity=".55" />
                            <path d="M114 238V194C114 182 124 176 138 176H248C268 176 286 190 290 212V238C290 242 286 244 282 244H120C116 244 114 242 114 238Z"
                                  fill="#f8fafc" stroke="#cbd5e1" stroke-width="1.5" />
                            <path d="M114 218H290" stroke="#1d4ed8" stroke-width="5" />
                            <path d="M114 224H288" stroke="#f59e0b" stroke-width="2.2" />
                            <!-- Kaca jendela -->
                            <rect x="126" y="184" width="32" height="24" rx="4" fill="#0f172a" />
                            <rect x="164" y="184" width="34" height="24" rx="4" fill="#0f172a" />
                            <rect x="204" y="184" width="34" height="24" rx="4" fill="#0f172a" />
                            <path d="M244 184H267C275 184 281 192 283 208H244Z" fill="#1e293b" />
                            <!-- Sorot lampu depan -->
                            <path d="M289 230L355 218V244L289 236Z" fill="#fde68a" opacity=".22" />
                            <rect x="284" y="228" width="6" height="7" rx="2" fill="#fef08a" />
                            <!-- Roda -->
                            <g class="lgx-wheel-spin">
                                <circle cx="152" cy="244" r="14" fill="#0f172a" stroke="#475569" stroke-width="2" />
                                <circle cx="152" cy="244" r="6" fill="#cbd5e1" />
                                <path d="M146 244H158M152 238V250" stroke="#475569" stroke-width="1.5" />
                            </g>
                            <g class="lgx-wheel-spin">
                                <circle cx="256" cy="244" r="14" fill="#0f172a" stroke="#475569" stroke-width="2" />
                                <circle cx="256" cy="244" r="6" fill="#cbd5e1" />
                                <path d="M250 244H262M256 238V250" stroke="#475569" stroke-width="1.5" />
                            </g>
                        </g>
                    </svg>
                </div>

                <!-- 4 Floating Sky Mini KPI Pills -->
                <div class="lgx-sky lgx-sky--0" style="--d:0s" aria-hidden="true">
                    <span>Okupansi Armada</span>
                    <span class="sk-donut"></span>
                </div>
                <div class="lgx-sky lgx-sky--1" style="--d:-2s" aria-hidden="true">
                    <span>Trip Harian</span>
                    <span class="sk-bars"><i style="--h:52%"></i><i style="--h:80%"></i><i style="--h:68%"></i><i style="--h:94%"></i></span>
                </div>
                <div class="lgx-sky lgx-sky--2" style="--d:-3.6s" aria-hidden="true">
                    <span>Arus Kas</span>
                    <span class="sk-line"><svg viewBox="0 0 60 20" preserveAspectRatio="none"><path d="M0 16L12 13 24 14 36 8 48 9 60 4" /></svg></span>
                </div>
                <div class="lgx-sky lgx-sky--3" style="--d:-1.2s" aria-hidden="true">
                    <span class="sk-dot-live"></span>
                    <span>Dispatch Live</span>
                </div>

                <!-- 6 Floating Dashboard Preview Tiles (disorot dinamis per bab via data-k) -->
                <div class="lgx-tile lgx-tile--trv" data-k="0" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-ticket-perforated"></i></span>
                    <span class="lgx-tt"><b>Travel &amp; E-Tiket</b><small>Multi-penumpang &amp; rute</small></span>
                </div>
                <div class="lgx-tile lgx-tile--rnt" data-k="1" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-car-front"></i></span>
                    <span class="lgx-tt"><b>Sewa &amp; Charter</b><small>Guard anti bentrok unit</small></span>
                </div>
                <div class="lgx-tile lgx-tile--dsp" data-k="2" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-calendar2-check"></i></span>
                    <span class="lgx-tt"><b>Kalender Dispatch</b><small>Assign sopir &amp; surat jalan</small></span>
                </div>
                <div class="lgx-tile lgx-tile--fin" data-k="3" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-qr-code-scan"></i></span>
                    <span class="lgx-tt"><b>QRIS &amp; Buku Kas</b><small>Pelunasan &amp; piutang</small></span>
                </div>
                <div class="lgx-tile lgx-tile--crm" data-k="4" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-people"></i></span>
                    <span class="lgx-tt"><b>CRM Pelanggan</b><small>Riwayat &amp; pesan ulang</small></span>
                </div>
                <div class="lgx-tile lgx-tile--rpt" data-k="4,3" aria-hidden="true">
                    <span class="lgx-ti"><i class="bi bi-bar-chart-line"></i></span>
                    <span class="lgx-tt"><b>Laba Kategori</b><small>PDF, Excel &amp; CSV</small></span>
                </div>

                <!-- Spotlight Caption Card -->
                <div class="lgx-caption--float" id="lgxCaption">
                    <span class="lgx-cap-no" id="lgxCapNo">01</span>
                    <div class="lgx-cap-txt" id="lgxCap" aria-live="polite">
                        <strong id="lgxCapTitle">Pemesanan Travel &amp; E-Tiket terintegrasi.</strong>
                        <p id="lgxCapDesc">Pemilihan rute antar-kota, banyak penumpang sekaligus, dan penerbitan E-Tiket otomatis.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stepper 5 Bab Interaktif -->
        <nav class="lgx-chapters" aria-label="Fitur Utama VSudoTrans">
            <button type="button" class="lgx-chap is-on" data-step="0" aria-pressed="true"><span>01</span>Travel &amp; E-Tiket</button>
            <button type="button" class="lgx-chap" data-step="1" aria-pressed="false"><span>02</span>Sewa &amp; Charter</button>
            <button type="button" class="lgx-chap" data-step="2" aria-pressed="false"><span>03</span>Kalender Dispatch</button>
            <button type="button" class="lgx-chap" data-step="3" aria-pressed="false"><span>04</span>Keuangan &amp; QRIS</button>
            <button type="button" class="lgx-chap" data-step="4" aria-pressed="false"><span>05</span>Laporan &amp; CRM</button>
        </nav>
    </section>

    <!-- ================= KANAN: Panel Login ================= -->
    <section class="lgx-panel" aria-labelledby="lgxLoginTitle">
        <button type="button" id="loginThemeToggle" class="btn btn-sm btn-outline-secondary lgx-theme-btn rounded-pill"
                aria-label="Ganti tema" title="Ganti tema">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>

        <div class="lgx-card">
            <div class="lgx-login-brand">
                <span class="lgx-login-logo">
                    <img src="<?= e(APP_URL) ?>/assets/brand/logo-transmandiri.png" alt="" aria-hidden="true">
                </span>
                <div>
                    <p class="lgx-eyebrow"><?= e(APP_NAME) ?></p>
                    <h2 class="lgx-login-title" id="lgxLoginTitle">Panel Operasional</h2>
                </div>
            </div>

            <div class="lgx-hint">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 11v5M12 7.6v.2" />
                </svg>
                <span>Gunakan username dan kata sandi akun perusahaan Anda untuk masuk ke dashboard manajemen Travel, Bus &amp; Rental.</span>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 px-3 small d-flex align-items-center gap-2 lgx-alert mb-3" role="alert">
                    <i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" id="loginForm" autocomplete="off">
                <?= csrf_field() ?>

                <div class="lgx-input-group">
                    <label for="username" class="lgx-field-label">Username</label>
                    <div class="lgx-field">
                        <svg class="lgx-ic" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="8" r="3.6" />
                            <path d="M5 20.5v-1.2a7 7 0 0 1 14 0v1.2" />
                        </svg>
                        <input type="text" id="username" name="username" placeholder="Masukkan username Anda"
                               required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                    </div>
                </div>

                <div class="lgx-input-group">
                    <label for="password" class="lgx-field-label">Password</label>
                    <div class="lgx-field">
                        <svg class="lgx-ic" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="5" y="10.5" width="14" height="10" rx="2.6" />
                            <path d="M8 10.5V7.6a4 4 0 0 1 8 0v2.9" />
                        </svg>
                        <input type="password" id="password" name="password" placeholder="Masukkan password Anda" required>
                        <button type="button" class="lgx-eye" id="toggleLoginPwd"
                                aria-label="Tampilkan atau sembunyikan password" aria-pressed="false"
                                title="Tampilkan / Sembunyikan Password">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                <circle cx="12" cy="12" r="3" />
                                <path class="lgx-eye-x" d="M4 4l16 16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button id="loginBtn" type="submit" class="lgx-submit">
                    Masuk <span class="arrow" aria-hidden="true">&rarr;</span>
                </button>
            </form>

            <div class="lgx-card-foot">
                <a href="<?= e(APP_URL) ?>/index.php" class="lgx-back-link">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali ke Beranda
                </a>
            </div>
        </div>

        <p class="lgx-foot">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; Sistem Manajemen Transportasi Terpadu</p>
    </section>

    <!-- Transisi Split Curtain menutup dari kiri & kanan saat submit login -->
    <div id="vstLoginTransit" class="lgx-transit" aria-hidden="true">
        <div class="lgo-half lgo-half--l"></div>
        <div class="lgo-half lgo-half--r"></div>
        <div class="lgo-core">
            <span class="lgo-ring"></span>
            <span class="lgo-logo">
                <img src="<?= e(APP_URL) ?>/assets/brand/logo-transmandiri.png" alt="">
            </span>
        </div>
    </div>
</div>
</body>
</html>
