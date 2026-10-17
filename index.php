<?php
/**
 * Landing page publik VSudoTrans — nama APLIKASI/platform (SaaS multi-tenant).
 * Positioning: platform untuk banyak Agen Travel & PO Bus dalam satu instalasi.
 * Tanpa login. Tidak menampilkan data operasional tenant mana pun.
 * Cek tiket tetap tersedia: nomor tiket milik tenant -> diteruskan ke tiket.php.
 */
require_once __DIR__ . '/config/config.php';

// Cek tiket dari landing: validasi format, lalu teruskan ke tiket.php (publik).
$cek_no = strtoupper(trim((string) ($_POST['no'] ?? '')));
$cek_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $cek_no)) {
        $cek_err = 'Format nomor tiket tidak dikenal. Contoh: TRV-AB23CD45.';
    } else {
        redirect('tiket.php?no=' . urlencode($cek_no));
    }
}

$phone_wa = preg_replace('/\D/', '', (string) COMPANY_PHONE);
if (str_starts_with($phone_wa, '0')) {
    $phone_wa = '62' . substr($phone_wa, 1);
}
$title = APP_NAME;

// Apa yang bisa dikelola di dalam platform (bukan layanan satu operator).
$services = [
    ['bi-buildings', 'Satu Aplikasi, Banyak Agen', 'Kelola beberapa Agen Travel, PO Bus, atau Rental sekaligus. Tiap unit punya data, pengguna, cabang, dan laporan sendiri — terpisah dan aman.'],
    ['bi-bus-front', 'Travel & PO Bus', 'Booking, jadwal, rute dan harga, penumpang, sampai E-Tiket siap cetak/PDF untuk setiap perusahaan.'],
    ['bi-car-front', 'Rental Mobil', 'Sewa harian/mingguan dengan driver: periode sewa, trip km, BBM, dan premi per kendaraan.'],
    ['bi-receipt', 'Keuangan & Kas', 'Invoice otomatis, pembayaran bank/QRIS/e-wallet, buku kas & bank dengan saldo berjalan.'],
    ['bi-people', 'Absensi & Payroll', 'Absensi harian dan gaji karyawan (tarik hari hadir otomatis), siap cetak slip.'],
    ['bi-graph-up-arrow', 'Laporan Lengkap', 'Laporan booking, keuangan, operasional, dan beban kerja driver — ekspor CSV/XLSX.'],
];
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Platform Travel &amp; PO Bus</title>
    <meta name="description" content="<?= e(APP_NAME) ?>: satu aplikasi untuk mengelola banyak Agen Travel, PO Bus, dan rental mobil — booking, keuangan, SDM, dan laporan.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .hero { background: linear-gradient(135deg, #0d3b66 0%, #1d5fa8 100%); color: #fff; }
        .hero .btn-light { color: #0d3b66; }
        .service-card .bi { font-size: 1.9rem; color: #1d5fa8; }
        .who-card { border-left: 4px solid #1d5fa8; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand bg-white border-bottom sticky-top">
    <div class="container">
        <span class="navbar-brand fw-bold"><i class="bi bi-diagram-3-fill text-primary me-2"></i><?= e(APP_NAME) ?></span>
        <div class="ms-auto">
            <a class="btn btn-outline-primary btn-sm" href="<?= e(APP_URL) ?>/pages/auth/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Login Staff</a>
        </div>
    </div>
</nav>

<header class="hero py-5">
    <div class="container py-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <span class="badge bg-light text-primary mb-2">Platform Multi-Agen</span>
                <h1 class="fw-bold mb-2">Satu Aplikasi untuk Banyak Agen Travel &amp; PO Bus</h1>
                <p class="mb-4 opacity-75"><?= e(APP_NAME) ?> adalah aplikasi pengelolaan travel, PO bus, dan rental mobil. Satu instalasi melayani banyak Agen Travel atau PO Bus — masing-masing dengan data, pengguna, dan laporan sendiri.</p>
                <a class="btn btn-light fw-semibold me-2" href="#cek-tiket"><i class="bi bi-ticket-perforated me-1"></i>Cek Tiket</a>
                <a class="btn btn-outline-light" href="<?= e('https://wa.me/' . $phone_wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>Info &amp; Demo</a>
            </div>
            <div class="col-lg-5">
                <div class="bg-white text-dark rounded-4 shadow p-4" id="cek-tiket">
                    <h5 class="fw-bold mb-1"><i class="bi bi-ticket-perforated text-primary me-2"></i>Cek Tiket</h5>
                    <p class="text-muted small mb-3">Masukkan nomor tiket dari agen travel / PO bus Anda.</p>
                    <form method="post" action="">
                        <label class="form-label small text-muted" for="no">Nomor tiket</label>
                        <input class="form-control form-control-lg text-uppercase" id="no" name="no" pattern="(TRV|RNT)-[A-Z2-9]{8}"
                               placeholder="TRV-AB23CD45" maxlength="12" required autofocus
                               value="<?= e($cek_no) ?>">
                        <?php if ($cek_err !== ''): ?><div class="text-danger small mt-1"><?= e($cek_err) ?></div><?php endif; ?>
                        <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-search me-1"></i>Lihat Tiket</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>

<section class="container py-5">
    <h2 class="h5 fw-bold mb-3 text-center">Satu aplikasi, semua kebutuhan operasional</h2>
    <div class="row g-4">
        <?php foreach ($services as [$icon, $title, $desc]): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card service-card h-100 border-0 shadow-sm"><div class="card-body p-4">
                <i class="bi <?= e($icon) ?>"></i>
                <h5 class="fw-bold mt-2"><?= e($title) ?></h5>
                <p class="text-muted mb-0"><?= e($desc) ?></p>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="bg-white border-top border-bottom py-5">
    <div class="container">
        <h2 class="h5 fw-bold mb-3">Cocok untuk</h2>
        <div class="row g-3">
            <?php foreach ([
                ['Agen Travel', 'Travel antar-kota, door-to-door, dan paket/dokumen express.'],
                ['PO Bus', 'Operator bus dengan beberapa trayek dan armada yang perlu penjadwalan.'],
                ['Rental Mobil', 'Sewa mobil dengan driver untuk dalam kota maupun luar kota.'],
                ['Grup Usaha', 'Satu grup dengan beberapa unit usaha yang tetap perlu data terpisah.'],
            ] as [$t, $d]): ?>
            <div class="col-md-6">
                <div class="who-card bg-light rounded-3 p-3 h-100">
                    <div class="fw-semibold"><i class="bi bi-check-circle-fill text-primary me-1"></i><?= e($t) ?></div>
                    <div class="small text-muted mt-1"><?= e($d) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="alert alert-primary d-flex align-items-center mt-4 mb-0" role="alert">
            <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
            <div class="small">Data setiap agen/PO <strong>terisolasi</strong>: pengguna, cabang, armada, dan laporan hanya terlihat oleh perusahaan yang berhak.</div>
        </div>
    </div>
</section>

<section class="container py-5">
    <div class="row justify-content-center text-center">
        <div class="col-lg-8">
            <h2 class="h4 fw-bold mb-2">Mulai pakai <?= e(APP_NAME) ?></h2>
            <p class="text-muted mb-4">Aplikasi siap dipasang di shared hosting (cPanel). Data tiap Agen Travel / PO Bus dipisah per perusahaan.</p>
            <a class="btn btn-primary btn-lg" href="<?= e('https://wa.me/' . $phone_wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>Hubungi Kami</a>
            <a class="btn btn-outline-primary btn-lg ms-2" href="<?= e(APP_URL) ?>/pages/auth/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Login Staff</a>
        </div>
    </div>
</section>

<footer class="bg-white border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-2 small text-muted">
        <div><i class="bi bi-diagram-3 me-1"></i><?= e(APP_NAME) ?> — Platform Travel &amp; PO Bus</div>
        <div><i class="bi bi-telephone me-1"></i><?= e(COMPANY_PHONE) ?></div>
    </div>
</footer>
</body>
</html>
