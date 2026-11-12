<?php
/**
 * Landing page publik VSudoTrans — Platform Multi-Tenant Agen Travel, PO Bus & Rental Mobil.
 * Positioning: Ekosistem terpadu untuk reservasi, armada, kru, komisi, dan e-tiket.
 * Tanpa login untuk publik. Cek tiket tetap valid & diteruskan ke tiket.php.
 */
require_once __DIR__ . '/config/config.php';

// Cek tiket dari landing: validasi format, lalu teruskan ke tiket.php (publik).
$cek_no = strtoupper(trim((string) ($_POST['no'] ?? '')));
$cek_err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $cek_no)) {
        $cek_err = 'Format nomor tiket tidak dikenal. Contoh: TRV-AB23CD45 atau RNT-W44J89Z3.';
    } else {
        redirect('tiket.php?no=' . urlencode($cek_no));
    }
}

$phone_wa = preg_replace('/\D/', '', (string) COMPANY_PHONE);
if (str_starts_with($phone_wa, '0')) {
    $phone_wa = '62' . substr($phone_wa, 1);
}
$title = APP_NAME . ' — Platform Ekosistem Travel, Bus & Rental Mobil';

// Ambil operator aktif untuk showcase tenant
$activeCompanies = [];
try {
    $resComp = db()->query("SELECT code, name, city, phone_number FROM companies WHERE is_active = 1 ORDER BY id ASC");
    if ($resComp) {
        $activeCompanies = $resComp->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    // Fallback jika query bermasalah
    $activeCompanies = [
        ['code' => 'MTM', 'name' => 'Maura Trans Mandiri', 'city' => 'Tangerang'],
        ['code' => 'LIZ', 'name' => 'LIZ Wisata Alam Indah', 'city' => 'Jakarta'],
        ['code' => 'NTM', 'name' => 'Nugi Trans Mandiri', 'city' => 'Bandung'],
        ['code' => 'GTM', 'name' => 'Gibran Trans Mandiri', 'city' => 'Tangerang'],
        ['code' => 'TTM', 'name' => 'Trisula Trans Mandiri', 'city' => 'Jakarta'],
    ];
}

// Ambil rute populer aktif
$popularRoutes = [];
try {
    $resRute = db()->query(
        "SELECT r.id, r.code, r.name, r.price, cv.name AS category_name, c.name AS company_name
           FROM rutes r
      LEFT JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
      LEFT JOIN companies c ON c.id = r.company_id
          WHERE r.is_active = 1
          LIMIT 4"
    );
    if ($resRute) {
        $popularRoutes = $resRute->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    $popularRoutes = [];
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e(APP_NAME) ?>: Platform ekosistem digital terpadu untuk Agen Travel, PO Bus Pariwisata, dan Rental Mobil. Reservasi tiket, manajemen armada, peraturan komisi kru, dan e-tiket QR code.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --vs-primary: #0d47a1;
            --vs-primary-dark: #072a63;
            --vs-accent: #0284c7;
            --vs-gold: #f59e0b;
        }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .navbar-brand-badge {
            font-size: 0.68rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .hero {
            background: radial-gradient(circle at 80% 20%, rgba(2, 132, 199, 0.25) 0%, transparent 50%),
                        linear-gradient(135deg, #091e3a 0%, #0d47a1 60%, #1565c0 100%);
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 40px;
            background: linear-gradient(to top, #f8fafc, transparent);
        }
        .ticket-card {
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .stat-badge {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px 16px;
        }
        .feature-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(13, 71, 161, 0.08);
            border-color: #93c5fd;
        }
        .feature-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: #eff6ff;
            color: var(--vs-primary);
        }
        .fleet-card {
            border-radius: 14px;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        .fleet-card:hover {
            border-color: #38bdf8;
            box-shadow: 0 10px 20px rgba(0,0,0,0.06);
        }
        .tenant-logo-badge {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 14px 20px;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .section-title-tag {
            color: var(--vs-accent);
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<!-- ================= NAVBAR ================= -->
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top py-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="<?= e(APP_URL) ?>">
            <div class="bg-primary text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width:38px;height:38px;">
                <i class="bi bi-diagram-3-fill fs-5"></i>
            </div>
            <div>
                <span class="fw-bold text-dark fs-5 tracking-tight"><?= e(APP_NAME) ?></span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1 navbar-brand-badge">ERP Ecosystem</span>
            </div>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 fw-medium">
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#layanan">Layanan &amp; Fitur</a></li>
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#armada">Kategori Armada</a></li>
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#rute">Rute Populer</a></li>
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#mitra">Mitra Operator</a></li>
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#faq">Bantuan / FAQ</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-primary btn-sm px-3 fw-semibold rounded-pill" href="#cek-tiket">
                    <i class="bi bi-ticket-perforated me-1"></i>Cek Tiket
                </a>
                <a class="btn btn-primary btn-sm px-3 fw-semibold rounded-pill shadow-sm" href="<?= e(APP_URL) ?>/pages/auth/login.php">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Login Staff
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ================= HERO SECTION ================= -->
<header class="hero py-5">
    <div class="container py-lg-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-10 text-white rounded-pill px-3 py-1 mb-3 border border-white border-opacity-25 small fw-semibold">
                    <i class="bi bi-shield-check text-warning"></i> Solusi Enterprise Multi-Tenant Terpadu
                </div>
                <h1 class="display-5 fw-extrabold text-white mb-3" style="font-weight: 800; line-height: 1.2;">
                    Platform Ekosistem Digital Agen Travel, PO Bus &amp; Rental Mobil
                </h1>
                <p class="lead mb-4 text-white text-opacity-75 fs-6" style="line-height: 1.6;">
                    Satu instalasi terpadu untuk operasional reservasi shuttle door-to-door, car rental pariwisata, jadwal dispatching, peraturan komisi kru otomatis, e-tiket QR code scanner, hingga pembukuan multi-cabang dengan isolasi data mandiri.
                </p>

                <div class="d-flex flex-wrap gap-3 mb-5">
                    <a class="btn btn-warning text-dark fw-bold px-4 py-2 rounded-pill shadow" href="#cek-tiket">
                        <i class="bi bi-ticket-perforated-fill me-1"></i>Cek Status Tiket
                    </a>
                    <a class="btn btn-outline-light px-4 py-2 rounded-pill fw-semibold" href="<?= e('https://wa.me/' . $phone_wa . '?text=' . urlencode('Halo Admin VSudoTrans, saya ingin konsultasi mengenai layanan dan kemitraan sistem.')) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp me-1"></i>Konsultasi &amp; Info
                    </a>
                </div>

                <!-- Stats Badges -->
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="stat-badge">
                            <div class="fs-4 fw-bold text-white"><?= count($activeCompanies) ?>+ Mitra</div>
                            <div class="small text-white text-opacity-75">Operator Travel &amp; Bus</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="stat-badge">
                            <div class="fs-4 fw-bold text-white">100% Terisolasi</div>
                            <div class="small text-white text-opacity-75">Data Multi-Tenant Aman</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="stat-badge">
                            <div class="fs-4 fw-bold text-white">QR E-Tiket</div>
                            <div class="small text-white text-opacity-75">Cek Tiket Real-Time</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Cek Tiket Instan -->
            <div class="col-lg-5">
                <div class="ticket-card p-4 p-md-5 text-dark" id="cek-tiket">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-qr-code-scan text-primary me-2"></i>Cek E-Tiket
                        </h4>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Publik</span>
                    </div>
                    <p class="text-secondary small mb-4">
                        Lacak jadwal penjemputan, armada, nomor sopir, dan status pembayaran tiket Anda langsung dari platform.
                    </p>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-secondary" for="ticket_no">
                                Nomor Tiket (Travel / Rental)
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="bi bi-hash"></i>
                                </span>
                                <input class="form-control text-uppercase fw-bold border-start-0 ps-0" id="ticket_no" name="no"
                                       pattern="(TRV|RNT)-[A-Z2-9]{8}"
                                       placeholder="TRV-AB23CD45" maxlength="12" required
                                       value="<?= e($cek_no) ?>" style="letter-spacing: 0.05em;">
                            </div>
                            <?php if ($cek_err !== ''): ?>
                                <div class="alert alert-danger py-2 px-3 small mt-2 mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span><?= e($cek_err) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="form-text small mt-1">
                                Contoh: <code>TRV-XXXXXXXX</code> (Travel Antar-Kota) atau <code>RNT-XXXXXXXX</code> (Rental Mobil).
                            </div>
                        </div>

                        <button class="btn btn-primary btn-lg w-100 fw-bold py-2 shadow-sm rounded-3 mt-2" type="submit">
                            <i class="bi bi-search me-2"></i>Lacak Tiket Sekarang
                        </button>
                    </form>

                    <div class="mt-4 pt-3 border-top text-center">
                        <span class="small text-muted">
                            <i class="bi bi-shield-lock-fill text-success me-1"></i>Tiket terenkripsi &amp; diverifikasi langsung oleh operator terkait.
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ================= FITUR & MODUL UTAMA ================= -->
<section class="py-5 bg-white border-bottom" id="layanan">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="section-title-tag">Layanan &amp; Modul Operasional</span>
            <h2 class="fw-bold mt-2">Didesain Khusus untuk Bisnis Transportasi Darat</h2>
            <p class="text-muted">Setiap fitur dirancang mengikuti alur kerja nyata perusahaan travel, PO bus reguler/pariwisata, dan rental kendaraan.</p>
        </div>

        <div class="row g-4">
            <!-- Travel Antar Kota -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-bus-front"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Travel Shuttle Antar-Kota</h5>
                    <p class="text-secondary small mb-0">
                        Pemesanan kursi per rute, titik jemput (door-to-door), titik antar, manifest penumpang, serta e-tiket otomatis siap cetak atau dikirim via WhatsApp.
                    </p>
                </div>
            </div>

            <!-- Rental Mobil Pariwisata -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-car-front-fill"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Rental Mobil &amp; Bus Wisata</h5>
                    <p class="text-secondary small mb-0">
                        Manajemen sewa harian/mingguan dengan driver atau lepas kunci, rincian biaya BBM, jam sewa, kilometer awal-akhir, dan klausul sewa resmi.
                    </p>
                </div>
            </div>

            <!-- Peraturan Komisi Kru & Multi-Driver -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-sliders"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Peraturan Komisi Karyawan</h5>
                    <p class="text-secondary small mb-0">
                        Otomatisasi premi komisi Sopir, Sopir Cadangan, Kernet, Montir, Agen, hingga alokasi BBM berdasarkan kategori armada dan trip secara instan.
                    </p>
                </div>
            </div>

            <!-- Dispatching & Kalender Jadwal -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Dispatch &amp; Kalender Armada</h5>
                    <p class="text-secondary small mb-0">
                        Plotting jadwal armada harian, penugasan kru sopir, deteksi unit bentrok (double booking), serta pantauan kesiapan armada secara visual.
                    </p>
                </div>
            </div>

            <!-- Invoice & Dynamic QRIS -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-receipt-cutoff"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Invoice &amp; Dynamic QRIS</h5>
                    <p class="text-secondary small mb-0">
                        Invoice otomatis, pencatatan DP/pelunasan, kas &amp; bank, serta integrasi QRIS real-time untuk memudahkan pembayaran pelanggan tanpa ribet.
                    </p>
                </div>
            </div>

            <!-- Keamanan Multi-Tenant -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Multi-Tenant Terisolasi</h5>
                    <p class="text-secondary small mb-0">
                        Banyak perusahaan / anak cabang dapat beroperasi dalam satu instalasi dengan data armada, kru, keuangan, dan laporan yang terisolasi aman.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= KATEGORI ARMADA ================= -->
<section class="py-5" id="armada">
    <div class="container py-4">
        <div class="row align-items-end mb-4">
            <div class="col-md-8">
                <span class="section-title-tag">Pilihan Armada</span>
                <h2 class="fw-bold mt-1">Armada Lengkap untuk Berbagai Kebutuhan</h2>
                <p class="text-secondary mb-0">Dikelola oleh mitra operator terpercaya dengan standar kebersihan, keamanan, dan kru berpengalaman.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="<?= e('https://wa.me/' . $phone_wa . '?text=' . urlencode('Halo Admin VSudoTrans, saya ingin sewa unit armada.')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary rounded-pill btn-sm px-3 fw-semibold">
                    <i class="bi bi-whatsapp me-1"></i>Cek Ketersediaan Unit
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Toyota HiAce Executive -->
            <div class="col-md-6 col-lg-3">
                <div class="fleet-card h-100 p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Travel Shuttle</span>
                        <span class="fw-bold text-muted small"><i class="bi bi-people-fill me-1"></i>10 - 14 Seat</span>
                    </div>
                    <h5 class="fw-bold mb-1">Toyota HiAce Executive</h5>
                    <p class="text-muted small mb-3">Kategori: <code>HIACE</code></p>
                    <p class="text-secondary small flex-grow-1">
                        Pilihan favorit travel eksekutif antar-kota door-to-door dengan suspensi nyaman, AC ducting dingin, dan reclining seat.
                    </p>
                    <div class="border-top pt-3 mt-2 d-flex align-items-center justify-content-between">
                        <span class="text-muted small">Tersedia di</span>
                        <span class="badge bg-light text-dark border">MTM &amp; Mitra</span>
                    </div>
                </div>
            </div>

            <!-- Bus Besar Pariwisata -->
            <div class="col-md-6 col-lg-3">
                <div class="fleet-card h-100 p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Pariwisata</span>
                        <span class="fw-bold text-muted small"><i class="bi bi-people-fill me-1"></i>45 - 59 Seat</span>
                    </div>
                    <h5 class="fw-bold mb-1">Bus Besar (Big Bus)</h5>
                    <p class="text-muted small mb-3">Kategori: <code>BB</code></p>
                    <p class="text-secondary small flex-grow-1">
                        Kapasitas besar untuk rombongan gathering perusahaan, tour luar kota, ziarah, dengan bagasi lapang dan audio karaoke.
                    </p>
                    <div class="border-top pt-3 mt-2 d-flex align-items-center justify-content-between">
                        <span class="text-muted small">Tersedia di</span>
                        <span class="badge bg-light text-dark border">PO Bus Mitra</span>
                    </div>
                </div>
            </div>

            <!-- Bus Sedang / Medium Bus -->
            <div class="col-md-6 col-lg-3">
                <div class="fleet-card h-100 p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">Fleksibel</span>
                        <span class="fw-bold text-muted small"><i class="bi bi-people-fill me-1"></i>29 - 35 Seat</span>
                    </div>
                    <h5 class="fw-bold mb-1">Bus Sedang (Medium)</h5>
                    <p class="text-muted small mb-3">Kategori: <code>BS</code></p>
                    <p class="text-secondary small flex-grow-1">
                        Manuver lincah di jalur pegunungan dan destinasi wisata perkotaan dengan kapasitas sedang yang pas untuk keluarga besar.
                    </p>
                    <div class="border-top pt-3 mt-2 d-flex align-items-center justify-content-between">
                        <span class="text-muted small">Tersedia di</span>
                        <span class="badge bg-light text-dark border">LIZ, MTM</span>
                    </div>
                </div>
            </div>

            <!-- Toyota Innova Reborn -->
            <div class="col-md-6 col-lg-3">
                <div class="fleet-card h-100 p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">VIP Rental</span>
                        <span class="fw-bold text-muted small"><i class="bi bi-people-fill me-1"></i>6 - 7 Seat</span>
                    </div>
                    <h5 class="fw-bold mb-1">Innova Reborn / Zenix</h5>
                    <p class="text-muted small mb-3">Kategori: <code>INNOVA</code></p>
                    <p class="text-secondary small flex-grow-1">
                        Sewa privat untuk dinas pejabat, operasional instansi, antar jemput bandara VIP, dan perjalanan keluarga yang elegan.
                    </p>
                    <div class="border-top pt-3 mt-2 d-flex align-items-center justify-content-between">
                        <span class="text-muted small">Tersedia di</span>
                        <span class="badge bg-light text-dark border">LIZ Wisata &amp; Mitra</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= RUTE POPULER ================= -->
<?php if (!empty($popularRoutes)): ?>
<section class="py-5 bg-white border-top border-bottom" id="rute">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-4">
            <span class="section-title-tag">Rute Reguler</span>
            <h2 class="fw-bold mt-1">Jalur Trayek &amp; Shuttle Terjadwal</h2>
            <p class="text-secondary">Pesan tiket perjalanan shuttle harian dengan jadwal keberangkatan pasti dan armada prima.</p>
        </div>

        <div class="row g-3 justify-content-center">
            <?php foreach ($popularRoutes as $r): ?>
            <div class="col-md-6 col-lg-5">
                <div class="card border border-primary-subtle shadow-sm rounded-4 p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="badge bg-primary text-white px-2 py-1 small"><?= e($r['code'] ?: 'RUTE') ?></span>
                        <span class="text-primary fw-bold fs-5">Rp <?= number_format((float) ($r['price'] ?? 0), 0, ',', '.') ?></span>
                    </div>
                    <h5 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= e($r['name']) ?></h5>
                    <div class="text-muted small mb-3">
                        Operator: <strong class="text-dark"><?= e($r['company_name'] ?? 'Mitra Travel') ?></strong> &middot; Unit: <?= e($r['category_name'] ?? 'HiAce / Shuttle') ?>
                    </div>
                    <div class="mt-auto d-flex gap-2">
                        <a href="<?= e('https://wa.me/' . $phone_wa . '?text=' . urlencode('Halo Admin, saya ingin memesan tiket untuk rute ' . $r['name'])) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                            <i class="bi bi-whatsapp me-1"></i>Pesan Tiket Rute Ini
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ================= MITRA OPERATOR / TENANT SHOWCASE ================= -->
<section class="py-5" id="mitra">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="section-title-tag">Ekosistem Multi-Tenant</span>
            <h2 class="fw-bold mt-1">Didukung Mitra Operator Transportasi Terpercaya</h2>
            <p class="text-secondary">Platform VSudoTrans menjadi tulang punggung digital bagi berbagai perusahaan jasa transportasi terkemuka.</p>
        </div>

        <div class="row g-3 justify-content-center">
            <?php foreach ($activeCompanies as $comp): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="tenant-logo-badge h-100">
                    <div class="bg-primary text-white rounded-3 p-2 fw-bold text-center" style="width:40px;height:40px;line-height:24px;">
                        <?= e(substr($comp['code'] ?? 'OP', 0, 3)) ?>
                    </div>
                    <div class="overflow-hidden">
                        <div class="text-truncate fw-bold text-dark" title="<?= e($comp['name']) ?>"><?= e($comp['name']) ?></div>
                        <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= e($comp['city'] ?: 'Indonesia') ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card bg-primary text-white border-0 rounded-4 mt-5 p-4 p-md-5">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <h3 class="fw-bold mb-2">Ingin Menggabungkan Agen Travel atau PO Bus Anda?</h3>
                    <p class="mb-0 text-white text-opacity-75">
                        Dapatkan sistem reservasi mandiri, laporan keuangan otomatis, manajemen penugasan kru sopir, hingga pencetakan e-tiket berlogo perusahaan Anda sendiri.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a class="btn btn-warning text-dark fw-bold btn-lg px-4 rounded-pill shadow" href="<?= e('https://wa.me/' . $phone_wa . '?text=' . urlencode('Halo Admin, saya tertarik untuk mendaftarkan operator travel/bus saya ke platform VSudoTrans.')) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp me-1"></i>Daftar Sebagai Mitra
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FAQ SECTION ================= -->
<section class="py-5 bg-white border-top border-bottom" id="faq">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="section-title-tag">Pertanyaan Umum</span>
            <h2 class="fw-bold mt-1">Frequently Asked Questions</h2>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion accordion-flush" id="accordionFaq">
                    <div class="accordion-item border rounded-3 mb-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                Bagaimana cara mengecek keaslian dan status tiket saya?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body text-secondary small">
                                Masukkan kode tiket berformat <code>TRV-XXXXXXXX</code> (untuk Travel Shuttle) atau <code>RNT-XXXXXXXX</code> (untuk Rental Kendaraan) pada form <strong>Cek Tiket</strong> di atas. Anda akan langsung melihat detail jadwal, kendaraan, nama sopir, dan status pembayaran secara real-time.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border rounded-3 mb-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                Apakah data antar perusahaan operator di platform ini aman dan terpisah?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body text-secondary small">
                                <strong>Ya, 100% terisolasi.</strong> Sistem VSudoTrans menerapkan arsitektur Multi-Tenant dengan hak akses ketat (RBAC). Data pelanggan, armada, laporan kas, dan omzet masing-masing operator hanya dapat dilihat oleh staf berwenang di perusahaan terkait.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border rounded-3 mb-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                Apakah tersedia fitur penghitungan komisi dan premi kru sopir?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body text-secondary small">
                                Ya. Tersedia modul <em>Peraturan Komisi Karyawan</em> yang menghitung otomatis hak bagi hasil atau premi untuk Sopir Utama, Sopir Cadangan, Kernet, Montir, hingga Agen berdasarkan persentase atau nominal tetap per trip.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border rounded-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed fw-bold rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                Metode pembayaran apa saja yang didukung?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body text-secondary small">
                                Sistem mendukung pembayaran tunai (cash on delivery), transfer rekening bank resmi operator, dan dynamic QRIS yang dapat di-scan langsung dari semua aplikasi perbankan serta e-wallet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="bg-dark text-white pt-5 pb-4">
    <div class="container">
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-primary text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                        <i class="bi bi-diagram-3-fill"></i>
                    </div>
                    <span class="fs-5 fw-bold text-white"><?= e(APP_NAME) ?></span>
                </div>
                <p class="text-secondary small mb-3">
                    Platform ekosistem digital ERP terpadu untuk Agen Travel, PO Bus Pariwisata, dan Rental Mobil di seluruh Indonesia.
                </p>
                <div class="text-secondary small">
                    <i class="bi bi-telephone text-primary me-2"></i><?= e(COMPANY_PHONE) ?>
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold text-white mb-3">Navigasi</h6>
                <ul class="list-unstyled small text-secondary">
                    <li class="mb-2"><a href="#layanan" class="text-secondary text-decoration-none">Layanan</a></li>
                    <li class="mb-2"><a href="#armada" class="text-secondary text-decoration-none">Kategori Armada</a></li>
                    <li class="mb-2"><a href="#rute" class="text-secondary text-decoration-none">Rute Populer</a></li>
                    <li class="mb-2"><a href="#mitra" class="text-secondary text-decoration-none">Mitra Operator</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold text-white mb-3">Akses Cepat</h6>
                <ul class="list-unstyled small text-secondary">
                    <li class="mb-2"><a href="#cek-tiket" class="text-secondary text-decoration-none">Cek Tiket Publik</a></li>
                    <li class="mb-2"><a href="<?= e(APP_URL) ?>/pages/auth/login.php" class="text-secondary text-decoration-none">Login Staff ERP</a></li>
                    <li class="mb-2"><a href="<?= e('https://wa.me/' . $phone_wa) ?>" target="_blank" rel="noopener" class="text-secondary text-decoration-none">Hubungi Support</a></li>
                </ul>
            </div>

            <div class="col-lg-4">
                <h6 class="fw-bold text-white mb-3">Hubungi Operator Pusat</h6>
                <p class="text-secondary small mb-3">
                    Ada pertanyaan seputar kerja sama armada atau bantuan teknis sistem? Hubungi customer support kami.
                </p>
                <a href="<?= e('https://wa.me/' . $phone_wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="bi bi-whatsapp me-1 text-success"></i>WhatsApp Live Chat
                </a>
            </div>
        </div>

        <div class="border-top border-secondary border-opacity-25 pt-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small text-secondary">
            <div>
                &copy; <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong>. Hak Cipta Dilindungi.
            </div>
            <div>
                Platform Solusi Transportasi Terpadu &middot; Multi-Tenant Enterprise
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
