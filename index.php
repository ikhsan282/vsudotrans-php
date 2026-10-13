<?php
/**
 * Landing page publik VSudoTrans. Tanpa login.
 * Ringkasan layanan + cek tiket + kontak. Tidak menampilkan data operasional.
 */
require_once __DIR__ . '/config/config.php';

// Cek tiket langsung dari landing: hanya teruskan nomor ke tiket.php.
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
$title = COMPANY_NAME;
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(COMPANY_NAME) ?> — Travel &amp; Rental Mobil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .hero { background: linear-gradient(135deg, #0d3b66 0%, #1d5fa8 100%); color: #fff; }
        .hero .btn-light { color: #0d3b66; }
        .service-card .bi { font-size: 2rem; color: #1d5fa8; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand bg-white border-bottom sticky-top">
    <div class="container">
        <span class="navbar-brand fw-bold"><i class="bi bi-bus-front-fill text-primary me-2"></i><?= e(COMPANY_NAME) ?></span>
        <div class="ms-auto">
            <a class="btn btn-outline-primary btn-sm" href="<?= e(APP_URL) ?>/pages/auth/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Login Staff</a>
        </div>
    </div>
</nav>

<header class="hero py-5">
    <div class="container py-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <h1 class="fw-bold mb-2">Travel Door-to-Door &amp; Rental Mobil</h1>
                <p class="mb-4 opacity-75">Antar-jemput door-to-door, paket door-to-door express, dan sewa mobil dengan driver. Pesan lewat admin, tiket langsung terbit.</p>
                <a class="btn btn-light fw-semibold me-2" href="#cek-tiket"><i class="bi bi-ticket-perforated me-1"></i>Cek Tiket</a>
                <a class="btn btn-outline-light" href="<?= e('https://wa.me/' . $phone_wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>Hubungi Kami</a>
            </div>
            <div class="col-lg-5">
                <div class="bg-white text-dark rounded-4 shadow p-4" id="cek-tiket">
                    <h5 class="fw-bold mb-3"><i class="bi bi-ticket-perforated text-primary me-2"></i>Cek Tiket</h5>
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
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card service-card h-100 border-0 shadow-sm"><div class="card-body p-4">
                <i class="bi bi-bus-front"></i>
                <h5 class="fw-bold mt-2">Travel Door-to-Door</h5>
                <p class="text-muted mb-0">Antar-jemput alamat ke alamat tanpa transit, dengan pemilihan rute dan jam sesuai kebutuhan.</p>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card service-card h-100 border-0 shadow-sm"><div class="card-body p-4">
                <i class="bi bi-box-seam"></i>
                <h5 class="fw-bold mt-2">Paket &amp; Dokumen Express</h5>
                <p class="text-muted mb-0">Kirim paket dan dokumen penting antar kota, tiba lebih cepat dengan armada reguler kami.</p>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card service-card h-100 border-0 shadow-sm"><div class="card-body p-4">
                <i class="bi bi-car-front"></i>
                <h5 class="fw-bold mt-2">Rental dengan Driver</h5>
                <p class="text-muted mb-0">Sewa mobil harian/besok dengan driver berpengalaman untuk perjalanan luar kota maupun dalam kota.</p>
            </div></div>
        </div>
    </div>
</section>

<footer class="bg-white border-top py-4">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-2 small text-muted">
        <div><i class="bi bi-geo-alt me-1"></i><?= e(COMPANY_ADDRESS) ?></div>
        <div><i class="bi bi-telephone me-1"></i><?= e(COMPANY_PHONE) ?></div>
    </div>
</footer>
</body>
</html>