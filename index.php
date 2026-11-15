<?php
/**
 * Landing page publik VSudoTrans — Platform Multi-Tenant Agen Travel, PO Bus & Rental Mobil.
 * Positioning: Ekosistem terpadu untuk reservasi, armada, kru, komisi, dan e-tiket.
 * Tanpa login untuk publik. Cek tiket tetap valid & diteruskan ke tiket.php.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/booking.php';

// Cek tiket dari landing: validasi format, lalu teruskan ke tiket.php (publik).
$cek_no = strtoupper(trim((string) ($_POST['no'] ?? '')));
$cek_err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!booking_doc_valid($cek_no)) {
        $cek_err = 'Format nomor tiket tidak dikenal. Contoh: 100120261113.';
    } else {
        redirect('tiket.php?no=' . urlencode($cek_no));
    }
}

$phone_wa = normalize_phone_62((string) COMPANY_PHONE) ?: '';
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

// Rute populer: kota asal → tujuan (singkatan kota) + Daftar Harga dari rute_tariffs
// (urut tarif termurah), diprioritaskan rute yang paling sering dipesan.
$popularRoutes = [];
try {
    $resRute = db()->query(
        "SELECT r.id, c.code AS company_code, c.name AS company_name, c.phone_number AS company_phone,
                oc.name AS city_from, dc.name AS city_to,
                COALESCE(oc.short_name, '') AS from_short, COALESCE(dc.short_name, '') AS to_short,
                MIN(rt.price) AS min_price,
                GROUP_CONCAT(cv.name ORDER BY rt.price ASC, rt.sort_order ASC, rt.id ASC SEPARATOR '~;~') AS categories,
                (SELECT COUNT(*) FROM travel_ticket_bookings b
                  WHERE b.company_id = r.company_id
                    AND b.pickup_point_city_id = r.pickup_point_city_id
                    AND b.delivery_point_city_id = r.delivery_point_city_id) AS booking_count
           FROM rutes r
           JOIN companies c ON c.id = r.company_id AND c.is_active = 1
           JOIN cities oc ON oc.id = r.pickup_point_city_id
           JOIN cities dc ON dc.id = r.delivery_point_city_id
           JOIN rute_tariffs rt ON rt.rute_id = r.id AND rt.price > 0
           JOIN category_vehicles cv ON cv.id = rt.category_vehicle_id AND cv.is_active = 1
          WHERE r.is_active = 1
          GROUP BY r.id
          ORDER BY booking_count DESC, min_price ASC, r.id ASC
          LIMIT 6"
    );
    if ($resRute) {
        $popularRoutes = $resRute->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    $popularRoutes = [];
}

// Kategori armada: diambil dari unit kendaraan aktif milik operator aktif (bukan data statis).
$fleetCategories = [];
try {
    $resFleet = db()->query(
        "SELECT COALESCE(NULLIF(v.vehicle_category, ''), 'Mini Bus') AS name,
                GROUP_CONCAT(DISTINCT bv.name ORDER BY bv.name SEPARATOR ', ') AS brands,
                COUNT(DISTINCT v.id) AS unit_count,
                MIN(NULLIF(v.seat, 0)) AS seat_min, MAX(v.seat) AS seat_max,
                GROUP_CONCAT(DISTINCT c.code ORDER BY c.code SEPARATOR ', ') AS company_codes
           FROM vehicles v
           LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
           JOIN companies c ON c.id = v.company_id AND c.is_active = 1
          WHERE v.is_active = 1
          GROUP BY COALESCE(NULLIF(v.vehicle_category, ''), 'Mini Bus')
          ORDER BY unit_count DESC, name ASC
          LIMIT 8"
    );
    if ($resFleet) {
        $fleetCategories = $resFleet->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    $fleetCategories = [];
}
$fleetIcons = ['bi-bus-front', 'bi-truck-front', 'bi-car-front-fill', 'bi-taxi-front'];
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e(APP_NAME) ?>: Platform ekosistem digital terpadu untuk Agen Travel, PO Bus Pariwisata, dan Rental Mobil. Reservasi tiket, kalender operasional armada &amp; sopir, peraturan komisi kru, invoice &amp; QRIS, serta e-tiket PDF.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
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
            background: linear-gradient(to top, #ffffff, transparent);
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
        .max-w-700 { max-width: 700px; }
        .scroll-mt { scroll-margin-top: 90px; }
        .route-code {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: var(--vs-primary);
        }
        .route-arrow { color: #94a3b8; }
        .route-cat-badge {
            font-size: 0.72rem;
            font-weight: 600;
        }
        .feature-list {
            list-style: none;
            padding-left: 0;
            margin: 0.75rem 0 0;
        }
        .feature-list li {
            font-size: 0.8rem;
            color: #475569;
            padding-left: 1.2rem;
            position: relative;
            margin-bottom: 0.25rem;
        }
        .feature-list li::before {
            content: "\F26E";
            font-family: "bootstrap-icons";
            position: absolute;
            left: 0;
            color: #16a34a;
        }
        .ticket-card .form-control:focus { box-shadow: none; }
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
                <?php if (!empty($popularRoutes)): ?>
                <li class="nav-item"><a class="nav-link px-3 text-secondary" href="#rute">Rute Populer</a></li>
                <?php endif; ?>
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
                    Satu instalasi terpadu untuk pemesanan tiket travel door-to-door, sewa kendaraan multi-hari, kalender operasional armada &amp; sopir, peraturan komisi kru otomatis, invoice &amp; QRIS dinamis, hingga e-tiket PDF berlogo operator — dengan isolasi data tiap perusahaan.
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
                            <div class="fs-4 fw-bold text-white"><?= count($activeCompanies) ?> Operator</div>
                            <div class="small text-white text-opacity-75">Travel, Bus &amp; Rental</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="stat-badge">
                            <div class="fs-4 fw-bold text-white">Multi-Tenant</div>
                            <div class="small text-white text-opacity-75">Data Tiap Operator Terisolasi</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="stat-badge">
                            <div class="fs-4 fw-bold text-white">E-Tiket PDF</div>
                            <div class="small text-white text-opacity-75">Cek &amp; Unduh Tanpa Login</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Cek Tiket Instan -->
            <div class="col-lg-5">
                <div class="ticket-card p-4 p-md-5 text-dark scroll-mt" id="cek-tiket">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-ticket-perforated text-primary me-2"></i>Cek E-Tiket
                        </h4>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Publik</span>
                    </div>
                    <p class="text-secondary small mb-4">
                        Lihat jadwal keberangkatan, rute jemput &amp; antar, armada, daftar penumpang, dan total harga tiket Anda — lalu cetak atau unduh sebagai PDF.
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
                                       pattern="(\d{10,20}|([Tt][Rr][Vv]|[Rr][Nn][Tt])-[A-Za-z2-9]{8})"
                                       title="Masukkan 10–20 digit nomor Resi (contoh: 100120261113)"
                                       placeholder="100120261113" maxlength="20" autocomplete="off" required
                                       value="<?= e($cek_no) ?>" style="letter-spacing: 0.05em;">
                            </div>
                            <?php if ($cek_err !== ''): ?>
                                <div class="alert alert-danger py-2 px-3 small mt-2 mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span><?= e($cek_err) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="form-text small mt-1">
                                Nomor tiket = nomor <strong>Resi</strong> pada E-Tiket, contoh: <code>100120261113</code>.
                            </div>
                        </div>

                        <button class="btn btn-primary btn-lg w-100 fw-bold py-2 shadow-sm rounded-3 mt-2" type="submit">
                            <i class="bi bi-search me-2"></i>Lacak Tiket Sekarang
                        </button>
                    </form>

                    <div class="mt-4 pt-3 border-top text-center">
                        <span class="small text-muted">
                            <i class="bi bi-shield-lock-fill text-success me-1"></i>Keaslian tiket dapat diverifikasi langsung ke operator penerbit.
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ================= FITUR & MODUL UTAMA ================= -->
<section class="py-5 bg-white border-bottom scroll-mt" id="layanan">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="section-title-tag">Layanan &amp; Modul Operasional</span>
            <h2 class="fw-bold mt-2">Didesain Khusus untuk Bisnis Transportasi Darat</h2>
            <p class="text-muted">Setiap modul mengikuti alur kerja nyata agen travel, PO bus reguler/pariwisata, dan rental kendaraan — dari pemesanan sampai laporan.</p>
        </div>

        <div class="row g-4">
            <!-- Travel -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-ticket-perforated"></i></div>
                    <h5 class="fw-bold mb-2">Pemesanan Tiket Perjalanan</h5>
                    <p class="text-secondary small mb-0">Travel door-to-door antar-kota dengan rute bertarif per kategori armada dan resi otomatis.</p>
                    <ul class="feature-list">
                        <li>Daftar harga per kategori, urut tarif termurah</li>
                        <li>Radius jemput/antar per kecamatan</li>
                        <li>Penumpang utama &amp; penumpang tambahan</li>
                    </ul>
                </div>
            </div>

            <!-- Rental -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-car-front-fill"></i></div>
                    <h5 class="fw-bold mb-2">Sewa Kendaraan</h5>
                    <p class="text-secondary small mb-0">Sewa bus, elf, hiace, hingga mobil pribadi beserta sopir untuk perjalanan satu hari maupun multi-hari.</p>
                    <ul class="feature-list">
                        <li>Periode sewa &amp; lama trip otomatis</li>
                        <li>Biaya BBM &amp; kru per pemesanan</li>
                        <li>Cek ketersediaan unit sebelum dipesan</li>
                    </ul>
                </div>
            </div>

            <!-- Kalender Operasional -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-calendar-week"></i></div>
                    <h5 class="fw-bold mb-2">Kalender Operasional &amp; Sopir</h5>
                    <p class="text-secondary small mb-0">Pantau seluruh jadwal travel &amp; rental dalam satu kalender, lengkap dengan armada dan sopir yang bertugas.</p>
                    <ul class="feature-list">
                        <li>Tampilan Bulan, Minggu, Hari &amp; Daftar</li>
                        <li>Penugasan sopir &amp; status perjalanan</li>
                        <li>Cetak Surat Jalan untuk kru</li>
                    </ul>
                </div>
            </div>

            <!-- Komisi -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-sliders"></i></div>
                    <h5 class="fw-bold mb-2">Peraturan Komisi Kru</h5>
                    <p class="text-secondary small mb-0">Premi Sopir, Sopir Cadangan, Kernet, Montir, hingga Agen dihitung otomatis berdasarkan kategori armada dan trip.</p>
                    <ul class="feature-list">
                        <li>Persentase atau nominal tetap per trip</li>
                        <li>Biaya operasional tercatat per booking</li>
                    </ul>
                </div>
            </div>

            <!-- Invoice & QRIS -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-receipt-cutoff"></i></div>
                    <h5 class="fw-bold mb-2">Invoice &amp; QRIS Dinamis</h5>
                    <p class="text-secondary small mb-0">Invoice terbit otomatis saat pemesanan, pembayaran DP/pelunasan tercatat, dan pelanggan bisa bayar via QRIS.</p>
                    <ul class="feature-list">
                        <li>Status Belum Bayar, Sebagian/DP &amp; Lunas</li>
                        <li>Buku Kas &amp; Bank per perusahaan</li>
                    </ul>
                </div>
            </div>

            <!-- Multi-tenant, SDM & Laporan -->
            <div class="col-md-6 col-lg-4">
                <div class="feature-card p-4 h-100">
                    <div class="feature-icon-wrapper mb-3"><i class="bi bi-shield-lock"></i></div>
                    <h5 class="fw-bold mb-2">Multi-Tenant, SDM &amp; Laporan</h5>
                    <p class="text-secondary small mb-0">Banyak perusahaan beroperasi dalam satu instalasi dengan data yang terpisah dan hak akses per peran.</p>
                    <ul class="feature-list">
                        <li>Karyawan, absensi &amp; payroll</li>
                        <li>Laporan booking, keuangan &amp; driver (PDF/XLSX)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= KATEGORI ARMADA ================= -->
<section class="py-5 scroll-mt" id="armada">
    <div class="container py-4">
        <div class="row align-items-end mb-4">
            <div class="col-md-8">
                <span class="section-title-tag">Pilihan Armada</span>
                <h2 class="fw-bold mt-1">Armada Lengkap untuk Berbagai Kebutuhan</h2>
                <p class="text-secondary mb-0">Unit dikelola langsung oleh mitra operator dengan kru berpengalaman.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="<?= e('https://wa.me/' . $phone_wa . '?text=' . urlencode('Halo Admin VSudoTrans, saya ingin cek ketersediaan unit armada.')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary rounded-pill btn-sm px-3 fw-semibold">
                    <i class="bi bi-whatsapp me-1"></i>Cek Ketersediaan Unit
                </a>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            <?php if ($fleetCategories): ?>
                <?php foreach ($fleetCategories as $i => $fc):
                    $seatMin = (int) ($fc['seat_min'] ?? 0);
                    $seatMax = (int) ($fc['seat_max'] ?? 0);
                    $seatLabel = $seatMax > 0 ? ($seatMin > 0 && $seatMin !== $seatMax ? $seatMin . ' – ' . $seatMax : (string) $seatMax) . ' Seat' : '';
                ?>
                <div class="col-md-6 col-lg-3">
                    <div class="fleet-card h-100 p-4 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="feature-icon-wrapper" style="width:44px;height:44px;font-size:1.25rem;">
                                <i class="bi <?= e($fleetIcons[$i % count($fleetIcons)]) ?>"></i>
                            </div>
                            <?php if ($seatLabel !== ''): ?>
                                <span class="fw-bold text-muted small"><i class="bi bi-people-fill me-1"></i><?= e($seatLabel) ?></span>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold mb-1"><?= e((string) $fc['name']) ?></h5>
                        <?php $fcBrands = trim((string) ($fc['brands'] ?? '')); ?>
                        <p class="text-muted small mb-3"><?= $fcBrands !== '' ? 'Merek: ' . e($fcBrands) : 'Kategori armada terdaftar' ?></p>
                        <div class="border-top pt-3 mt-auto d-flex align-items-center justify-content-between">
                            <span class="text-muted small"><i class="bi bi-truck me-1"></i><?= (int) $fc['unit_count'] ?> unit aktif</span>
                            <span class="badge bg-light text-dark border"><?= e((string) $fc['company_codes']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-lg-8">
                    <div class="fleet-card p-4 text-center text-secondary">
                        <i class="bi bi-truck fs-2 d-block mb-2"></i>
                        Daftar armada mitra sedang diperbarui. Hubungi kami untuk informasi unit yang tersedia.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ================= RUTE POPULER ================= -->
<?php if (!empty($popularRoutes)): ?>
<section class="py-5 bg-white border-top border-bottom scroll-mt" id="rute">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-4">
            <span class="section-title-tag">Rute Populer</span>
            <h2 class="fw-bold mt-1">Rute Travel Antar-Kota</h2>
            <p class="text-secondary">Harga ditampilkan mulai dari tarif termurah. Tarif akhir mengikuti kategori layanan dan titik jemput/antar.</p>
        </div>

        <div class="row g-3">
            <?php foreach ($popularRoutes as $r):
                $cats = array_values(array_unique(array_filter(explode('~;~', (string) ($r['categories'] ?? '')))));
                $fromCode = $r['from_short'] !== '' ? $r['from_short'] : (string) $r['city_from'];
                $toCode = $r['to_short'] !== '' ? $r['to_short'] : (string) $r['city_to'];
                $routeText = $r['city_from'] . ' → ' . $r['city_to'];
                $compWa = normalize_phone_62((string) ($r['company_phone'] ?? ''));
                $routeWa = ($compWa && !str_ends_with($compWa, '0000000')) ? $compWa : $phone_wa;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border border-primary-subtle shadow-sm rounded-4 p-3 h-100">
                    <div class="d-flex align-items-start justify-content-between mb-1">
                        <div class="route-code">
                            <?= e($fromCode) ?> <i class="bi bi-arrow-right route-arrow"></i> <?= e($toCode) ?>
                        </div>
                        <span class="badge bg-body-secondary text-body border"><?= e((string) $r['company_code']) ?></span>
                    </div>
                    <div class="text-secondary small mb-2"><?= e($routeText) ?></div>
                    <div class="mb-3">
                        <span class="text-muted small">Mulai dari</span>
                        <span class="text-primary fw-bold fs-5 ms-1"><?= e(rupiah((float) $r['min_price'])) ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php foreach (array_slice($cats, 0, 3) as $cat): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle route-cat-badge"><?= e($cat) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($cats) > 3): ?>
                            <span class="badge bg-light text-secondary border route-cat-badge">+<?= count($cats) - 3 ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-auto">
                        <a href="<?= e('https://wa.me/' . $routeWa . '?text=' . urlencode('Halo Admin, saya ingin memesan tiket travel ' . $routeText . ' (' . $r['company_name'] . ').')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
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
<section class="py-5 scroll-mt" id="mitra">
    <div class="container py-4">
        <div class="text-center max-w-700 mx-auto mb-5">
            <span class="section-title-tag">Ekosistem Multi-Tenant</span>
            <h2 class="fw-bold mt-1">Mitra Operator yang Menggunakan <?= e(APP_NAME) ?></h2>
            <p class="text-secondary">Setiap operator mengelola pemesanan, armada, kru, dan keuangannya sendiri dalam satu platform.</p>
        </div>

        <div class="row g-3 justify-content-center">
            <?php foreach ($activeCompanies as $comp): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="tenant-logo-badge h-100">
                    <div class="bg-primary text-white rounded-3 p-2 fw-bold text-center flex-shrink-0" style="width:40px;height:40px;line-height:24px;">
                        <?= e(substr($comp['code'] ?? 'OP', 0, 3)) ?>
                    </div>
                    <div class="overflow-hidden min-w-0">
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
                        Dapatkan sistem pemesanan travel &amp; rental, kalender operasional sopir, invoice &amp; QRIS, laporan keuangan, hingga e-tiket berlogo dan berstempel perusahaan Anda sendiri.
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
<section class="py-5 bg-white border-top border-bottom scroll-mt" id="faq">
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
                                Masukkan nomor Resi tiket (contoh: <code>100120261113</code>) pada form <strong>Cek E-Tiket</strong> di atas. Anda akan melihat E-Tiket berisi jadwal &amp; jam berangkat, layanan, armada, daftar penumpang, alamat jemput/antar, dan total harga — yang bisa dicetak atau diunduh sebagai PDF. Keaslian tiket juga dapat dicek melalui tautan verifikasi di bagian bawah E-Tiket.
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
                                <strong>Ya.</strong> <?= e(APP_NAME) ?> menerapkan arsitektur Multi-Tenant dengan hak akses berbasis peran (RBAC). Data pelanggan, armada, kas, dan laporan masing-masing operator hanya dapat dilihat oleh staf berwenang di perusahaan terkait.
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
                                Ya. Modul <em>Peraturan Komisi</em> menghitung otomatis premi untuk Sopir Utama, Sopir Cadangan, Kernet, Montir, hingga Agen berdasarkan persentase atau nominal tetap per trip, sesuai kategori armada.
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
                                Pembayaran dapat dilakukan tunai, transfer ke rekening resmi operator, atau QRIS dinamis yang bisa dipindai dari aplikasi perbankan maupun e-wallet. Jika tagihan belum lunas, tombol <strong>Bayar via QRIS</strong> tampil langsung di halaman E-Tiket (bila operator mengaktifkannya).
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
                    <li class="mb-2"><a href="#layanan" class="text-secondary text-decoration-none">Layanan &amp; Fitur</a></li>
                    <li class="mb-2"><a href="#armada" class="text-secondary text-decoration-none">Kategori Armada</a></li>
                    <?php if (!empty($popularRoutes)): ?>
                    <li class="mb-2"><a href="#rute" class="text-secondary text-decoration-none">Rute Populer</a></li>
                    <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
