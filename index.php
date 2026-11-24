<?php
/**
 * Landing page publik VSudoTrans — Platform Multi-Tenant Agen Travel, PO Bus & Rental Mobil.
 * Ekosistem terpadu untuk reservasi, armada, kru, komisi, pembayaran & QRIS, serta e-tiket.
 * Tanpa login untuk publik. Cek tiket tetap valid & diteruskan ke tiket.php.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/booking.php';

// Cek tiket dari landing: bersihkan spasi, validasi format, lalu teruskan ke tiket.php (publik).
$cek_no  = strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['no'] ?? ''))));
$cek_err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!booking_doc_valid($cek_no)) {
        $cek_err = 'Format nomor tiket tidak dikenal. Contoh: 100120261113.';
    } else {
        redirect('tiket.php?no=' . urlencode($cek_no));
    }
}

$title = APP_NAME . ' — Ekosistem Digital Travel, Bus & Rental Mobil';

// Operator aktif untuk showcase tenant + nomor kontak nyata (hindari nomor dummy 021-0000000)
$activeCompanies = [];
$totalVehicles   = 0;
$totalRoutes     = 0;
try {
    $resComp = db()->query("SELECT code, name, city, phone_number, logo FROM companies WHERE is_active = 1 ORDER BY id ASC");
    if ($resComp) {
        $activeCompanies = $resComp->fetch_all(MYSQLI_ASSOC);
    }
    $totalVehicles = (int) (db()->query("SELECT COUNT(*) FROM vehicles v JOIN companies c ON c.id = v.company_id AND c.is_active = 1 WHERE v.is_active = 1")->fetch_row()[0] ?? 0);
    $totalRoutes   = (int) (db()->query("SELECT COUNT(*) FROM rutes r JOIN companies c ON c.id = r.company_id AND c.is_active = 1 WHERE r.is_active = 1")->fetch_row()[0] ?? 0);
} catch (Throwable $e) {
    $activeCompanies = [
        ['code' => 'MTM', 'name' => 'Maura Trans Mandiri', 'city' => 'Tangerang', 'phone_number' => '', 'logo' => ''],
        ['code' => 'LIZ', 'name' => 'LIZ Wisata Alam Indah', 'city' => 'Jakarta', 'phone_number' => '', 'logo' => ''],
        ['code' => 'NTM', 'name' => 'Nugi Trans Mandiri', 'city' => 'Bandung', 'phone_number' => '', 'logo' => ''],
        ['code' => 'GTM', 'name' => 'Gibran Trans Mandiri', 'city' => 'Tangerang', 'phone_number' => '', 'logo' => ''],
        ['code' => 'TTM', 'name' => 'Trisula Trans Mandiri', 'city' => 'Jakarta', 'phone_number' => '', 'logo' => ''],
    ];
}

// Pilih kontak WhatsApp resmi dari operator aktif yang bukan placeholder (bukan akhiran 0000000)
$phone_wa      = '';
$contactDisplay = '';
foreach ($activeCompanies as $ac) {
    $cand = normalize_phone_62((string) ($ac['phone_number'] ?? ''));
    if ($cand !== '' && !str_ends_with($cand, '0000000')) {
        $phone_wa       = $cand;
        $contactDisplay = '+' . $cand;
        break;
    }
}
if ($phone_wa === '') {
    $fallbackWa = normalize_phone_62((string) COMPANY_PHONE);
    if ($fallbackWa !== '' && !str_ends_with($fallbackWa, '0000000')) {
        $phone_wa       = $fallbackWa;
        $contactDisplay = (string) COMPANY_PHONE;
    }
}
$waHref = static function (string $text, string $customWa = '') use ($phone_wa): string {
    $target = ($customWa !== '' && !str_ends_with($customWa, '0000000')) ? $customWa : $phone_wa;
    if ($target === '') {
        return '#faq';
    }
    return 'https://wa.me/' . $target . '?text=' . urlencode($text);
};

// Rute populer: kota asal -> tujuan (singkatan kota) + Daftar Harga dari rute_tariffs
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

// Kategori armada: unit kendaraan aktif milik operator aktif
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
    <meta name="description" content="<?= e(APP_NAME) ?>: Platform ekosistem digital terpadu untuk Agen Travel, PO Bus Pariwisata, dan Rental Mobil. Cek E-Tiket publik, pemesanan travel door-to-door, sewa armada, kalender sopir, komisi kru, invoice &amp; QRIS dinamis.">
    <script>
      (function(){try{var t=localStorage.getItem('vst_theme');if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --vs-bg: #f6f8fc;
            --vs-surface: #ffffff;
            --vs-surface-alt: #f1f5fb;
            --vs-ink: #0f172a;
            --vs-muted: #475569;
            --vs-soft-muted: #64748b;
            --vs-border: rgba(15, 23, 42, 0.09);
            --vs-border-strong: rgba(15, 23, 42, 0.16);
            --vs-accent: #1d4ed8;
            --vs-accent-hover: #1e40af;
            --vs-accent-soft: rgba(29, 78, 216, 0.08);
            --vs-sky: #0284c7;
            --vs-nav-bg: rgba(255, 255, 255, 0.86);
            --vs-grid-dot: rgba(15, 23, 42, 0.06);
            --vs-card-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 32px -12px rgba(15, 23, 42, 0.08);
            --vs-hero-card-shadow: 0 24px 60px -15px rgba(15, 23, 42, 0.14), 0 0 0 1px rgba(15, 23, 42, 0.06);
            --vs-footer-bg: #0b1120;
        }
        html[data-bs-theme="dark"] {
            --vs-bg: #070b14;
            --vs-surface: #0f172a;
            --vs-surface-alt: #0b1222;
            --vs-ink: #f8fafc;
            --vs-muted: #94a3b8;
            --vs-soft-muted: #64748b;
            --vs-border: rgba(148, 163, 184, 0.14);
            --vs-border-strong: rgba(148, 163, 184, 0.24);
            --vs-accent: #3b82f6;
            --vs-accent-hover: #2563eb;
            --vs-accent-soft: rgba(56, 189, 248, 0.12);
            --vs-sky: #38bdf8;
            --vs-nav-bg: rgba(7, 11, 20, 0.84);
            --vs-grid-dot: rgba(148, 163, 184, 0.08);
            --vs-card-shadow: 0 1px 2px rgba(0, 0, 0, 0.25), 0 14px 34px -14px rgba(0, 0, 0, 0.45);
            --vs-hero-card-shadow: 0 28px 64px -16px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(148, 163, 184, 0.16);
            --vs-footer-bg: #04070e;
        }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--vs-bg);
            color: var(--vs-ink);
            -webkit-font-smoothing: antialiased;
        }
        .scroll-mt { scroll-margin-top: 84px; }

        /* ── Navbar ── */
        .landing-nav {
            background-color: var(--vs-nav-bg) !important;
            border-bottom: 1px solid var(--vs-border);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .brand-mark {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #1d4ed8 0%, #0284c7 100%);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 14px -4px rgba(29, 78, 216, 0.45);
        }
        .nav-link-clean {
            color: var(--vs-muted) !important;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 8px;
            transition: color 0.15s ease, background-color 0.15s ease;
        }
        .nav-link-clean:hover {
            color: var(--vs-ink) !important;
            background-color: var(--vs-accent-soft);
        }
        .btn-accent {
            background: var(--vs-accent);
            border-color: var(--vs-accent);
            color: #fff;
        }
        .btn-accent:hover {
            background: var(--vs-accent-hover);
            border-color: var(--vs-accent-hover);
            color: #fff;
        }

        /* ── Hero Canvas (clean dot grid + soft top aura, no harsh cutout) ── */
        .hero-stage {
            position: relative;
            overflow: hidden;
            background-color: var(--vs-bg);
            background-image:
                radial-gradient(60% 55% at 15% 0%, rgba(37, 99, 235, 0.12) 0%, transparent 70%),
                radial-gradient(45% 50% at 90% 10%, rgba(14, 165, 233, 0.11) 0%, transparent 65%),
                radial-gradient(var(--vs-grid-dot) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 24px 24px;
            border-bottom: 1px solid var(--vs-border);
        }
        html[data-bs-theme="dark"] .hero-stage {
            background-image:
                radial-gradient(60% 55% at 15% 0%, rgba(59, 130, 246, 0.16) 0%, transparent 70%),
                radial-gradient(45% 50% at 90% 10%, rgba(56, 189, 248, 0.12) 0%, transparent 65%),
                radial-gradient(var(--vs-grid-dot) 1px, transparent 1px);
        }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--vs-surface);
            border: 1px solid var(--vs-border-strong);
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--vs-muted);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            flex-shrink: 0;
        }
        .hero-title {
            font-size: clamp(2rem, 3.8vw, 3.25rem);
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.03em;
            color: var(--vs-ink);
        }
        .hero-title-accent {
            background: linear-gradient(120deg, #1d4ed8 0%, #0284c7 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        html[data-bs-theme="dark"] .hero-title-accent {
            background: linear-gradient(120deg, #60a5fa 0%, #38bdf8 100%);
            -webkit-background-clip: text;
            background-clip: text;
        }
        .hero-sub {
            font-size: 1.05rem;
            line-height: 1.65;
            color: var(--vs-muted);
            max-width: 560px;
        }
        .hero-metrics {
            border-top: 1px solid var(--vs-border);
            padding-top: 1.25rem;
        }
        .hero-metric-val {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--vs-ink);
        }
        .hero-metric-lbl {
            font-size: 0.78rem;
            color: var(--vs-soft-muted);
        }
        .resi-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            font-size: 0.82em;
            padding: 2px 7px;
            border-radius: 6px;
            background: var(--vs-accent-soft);
            color: var(--vs-accent);
        }
        html[data-bs-theme="dark"] .resi-code {
            color: var(--vs-sky);
        }

        /* ── E-Tiket Card ── */
        .ticket-panel {
            background: var(--vs-surface);
            border-radius: 20px;
            box-shadow: var(--vs-hero-card-shadow);
            overflow: hidden;
        }
        .ticket-panel-bar {
            height: 4px;
            background: linear-gradient(90deg, #1d4ed8, #0ea5e9);
        }
        .ticket-input-wrap {
            background: var(--vs-bg);
            border: 1.5px solid var(--vs-border-strong);
            border-radius: 12px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .ticket-input-wrap:focus-within {
            border-color: var(--vs-accent);
            box-shadow: 0 0 0 4px var(--vs-accent-soft);
        }
        .ticket-input-wrap .form-control {
            background: transparent;
            border: 0;
            box-shadow: none !important;
            color: var(--vs-ink);
            font-size: 1.15rem;
            letter-spacing: 0.06em;
        }

        /* ── Section Surfaces & Headers ── */
        .surface-band {
            background: var(--vs-surface);
            border-bottom: 1px solid var(--vs-border);
        }
        .alt-band {
            background: var(--vs-bg);
            border-bottom: 1px solid var(--vs-border);
        }
        .eyebrow-tag {
            display: inline-block;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: var(--vs-sky);
        }
        .section-heading {
            font-size: clamp(1.55rem, 2.5vw, 2.15rem);
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--vs-ink);
        }

        /* ── Operator Trust Strip ── */
        .op-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 12px;
            background: var(--vs-bg);
            border: 1px solid var(--vs-border);
            transition: border-color 0.18s ease, transform 0.18s ease;
        }
        .op-pill:hover {
            border-color: var(--vs-border-strong);
            transform: translateY(-2px);
        }
        .op-logo-img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            border-radius: 6px;
            background: #fff;
            padding: 2px;
        }
        .op-monogram {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: var(--vs-accent-soft);
            color: var(--vs-accent);
            font-size: 0.68rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        html[data-bs-theme="dark"] .op-monogram { color: var(--vs-sky); }

        /* ── Asymmetric Bento Cards ── */
        .bento-card {
            background: var(--vs-surface);
            border: 1px solid var(--vs-border);
            border-radius: 18px;
            box-shadow: var(--vs-card-shadow);
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .alt-band .bento-card { background: var(--vs-surface); }
        .surface-band .bento-card { background: var(--vs-bg); }
        .bento-card:hover {
            transform: translateY(-3px);
            border-color: rgba(37, 99, 235, 0.35);
        }
        html[data-bs-theme="dark"] .bento-card:hover {
            border-color: rgba(56, 189, 248, 0.4);
        }
        .bento-icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            background: var(--vs-accent-soft);
            color: var(--vs-accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        html[data-bs-theme="dark"] .bento-icon { color: var(--vs-sky); }
        .mini-preview {
            background: var(--vs-surface);
            border: 1px solid var(--vs-border);
            border-radius: 12px;
            padding: 12px 14px;
        }
        html[data-bs-theme="dark"] .surface-band .mini-preview {
            background: rgba(15, 23, 42, 0.9);
        }
        .check-list {
            list-style: none;
            padding: 0;
            margin: 0.75rem 0 0;
        }
        .check-list li {
            font-size: 0.83rem;
            color: var(--vs-muted);
            padding-left: 1.3rem;
            position: relative;
            margin-bottom: 0.35rem;
        }
        .check-list li::before {
            content: "\F26A";
            font-family: "bootstrap-icons";
            position: absolute;
            left: 0;
            color: #10b981;
            font-size: 0.85rem;
        }

        /* ── Route Cards (Transit Style) ── */
        .route-tile {
            background: var(--vs-surface);
            border: 1px solid var(--vs-border);
            border-radius: 16px;
            box-shadow: var(--vs-card-shadow);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .route-tile:hover {
            transform: translateY(-3px);
            border-color: rgba(37, 99, 235, 0.38);
        }
        .route-code-lg {
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            color: var(--vs-ink);
        }
        .route-track {
            flex: 1;
            height: 2px;
            border-top: 2px dashed var(--vs-border-strong);
            position: relative;
            margin: 0 10px;
        }
        .route-track i {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--vs-surface);
            padding: 0 4px;
            color: var(--vs-sky);
            font-size: 0.85rem;
        }
        .route-price {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--vs-accent);
        }
        html[data-bs-theme="dark"] .route-price { color: var(--vs-sky); }

        /* ── Fleet Cards ── */
        .fleet-tile {
            background: var(--vs-bg);
            border: 1px solid var(--vs-border);
            border-radius: 16px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .fleet-tile:hover {
            transform: translateY(-3px);
            border-color: rgba(37, 99, 235, 0.35);
        }

        /* ── Mitra CTA Banner ── */
        .cta-enterprise {
            background: linear-gradient(135deg, #0b1324 0%, #172554 60%, #0f172a 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 22px;
            position: relative;
            overflow: hidden;
        }
        .cta-enterprise::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(50% 80% at 95% 15%, rgba(56, 189, 248, 0.22), transparent 65%);
            pointer-events: none;
        }

        /* ── FAQ Accordion ── */
        .faq-card .accordion-item {
            background: var(--vs-surface);
            border: 1px solid var(--vs-border) !important;
            border-radius: 14px !important;
            overflow: hidden;
        }
        .faq-card .accordion-button {
            background: var(--vs-surface);
            color: var(--vs-ink);
            font-weight: 700;
            font-size: 0.96rem;
            box-shadow: none !important;
        }
        .faq-card .accordion-button:not(.collapsed) {
            background: var(--vs-accent-soft);
            color: var(--vs-accent);
        }
        html[data-bs-theme="dark"] .faq-card .accordion-button:not(.collapsed) {
            color: var(--vs-sky);
        }

        /* ── Theme toggle & Footer ── */
        html[data-bs-theme="dark"] #landingThemeToggle {
            color: #fbbf24;
            border-color: rgba(251, 191, 36, 0.45);
        }
        .landing-footer {
            background-color: var(--vs-footer-bg);
            color: #94a3b8;
        }
        .landing-footer a:hover { color: #f8fafc !important; }
        @media (max-width: 575.98px) {
            .op-pill { padding: 6px 10px; gap: 7px; }
            .op-pill span.small { font-size: 0.78rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .bento-card, .route-tile, .fleet-tile, .op-pill { transition: none; transform: none !important; }
        }
    </style>
</head>
<body>

<!-- ================= NAVBAR ================= -->
<nav class="navbar navbar-expand-lg landing-nav sticky-top py-2 py-lg-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none me-2" href="<?= e(APP_URL) ?>">
            <span class="brand-mark"><i class="bi bi-diagram-3-fill fs-6"></i></span>
            <span class="fw-bold fs-5" style="letter-spacing:-0.02em;color:var(--vs-ink);"><?= e(APP_NAME) ?></span>
            <span class="badge rounded-pill border fw-semibold d-none d-sm-inline" style="background:var(--vs-accent-soft);color:var(--vs-accent);border-color:var(--vs-border-strong) !important;font-size:0.68rem;">Transport ERP</span>
        </a>

        <div class="d-flex align-items-center gap-2 ms-auto order-lg-3">
            <button type="button" id="landingThemeToggle" class="btn btn-sm btn-outline-secondary rounded-pill px-2 px-sm-3" aria-label="Ganti tema terang atau gelap" title="Beralih ke mode gelap">
                <i class="bi bi-moon-stars"></i>
            </button>
            <a class="btn btn-accent btn-sm px-3 fw-semibold rounded-pill d-lg-none" href="<?= e(APP_URL) ?>/pages/auth/login.php">
                Masuk
            </a>
            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-label="Toggle navigasi">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse order-lg-2" id="navMenu">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-1">
                <li class="nav-item"><a class="nav-link nav-link-clean px-3" href="#layanan">Layanan</a></li>
                <?php if (!empty($popularRoutes)): ?>
                <li class="nav-item"><a class="nav-link nav-link-clean px-3" href="#rute">Rute Populer</a></li>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link nav-link-clean px-3" href="#armada">Armada</a></li>
                <li class="nav-item"><a class="nav-link nav-link-clean px-3" href="#mitra">Mitra</a></li>
                <li class="nav-item"><a class="nav-link nav-link-clean px-3" href="#faq">FAQ</a></li>
            </ul>
            <div class="d-none d-lg-flex align-items-center gap-2 me-lg-2">
                <a class="btn btn-sm px-3 fw-semibold rounded-pill border" style="color:var(--vs-ink);border-color:var(--vs-border-strong);" href="#cek-tiket">
                    <i class="bi bi-ticket-perforated me-1"></i>Cek Tiket
                </a>
                <a class="btn btn-accent btn-sm px-3 fw-semibold rounded-pill shadow-sm" href="<?= e(APP_URL) ?>/pages/auth/login.php">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Login Staff
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ================= HERO SECTION ================= -->
<header class="hero-stage py-4 py-lg-5">
    <div class="container py-lg-4">
        <div class="row align-items-center g-4 g-lg-5">
            <!-- Left Narrative -->
            <div class="col-lg-7">
                <div class="hero-eyebrow mb-3">
                    <span class="live-dot"></span>
                    <span><?= count($activeCompanies) ?> Operator Aktif<?php if ($totalRoutes > 0): ?> &middot; <?= number_format($totalRoutes, 0, ',', '.') ?> Rute<?php endif; ?><?php if ($totalVehicles > 0): ?> &middot; <?= number_format($totalVehicles, 0, ',', '.') ?> Armada<?php endif; ?></span>
                </div>

                <h1 class="hero-title mb-3">
                    Satu Platform Operasional untuk <span class="hero-title-accent">Travel, Bus &amp; Rental</span>
                </h1>

                <p class="hero-sub mb-4">
                    Kelola pemesanan door-to-door, sewa multi-hari, jadwal armada &amp; sopir, komisi kru otomatis, hingga invoice QRIS dan E-Tiket PDF — terisolasi rapi untuk tiap operator.
                </p>

                <div class="d-flex flex-wrap align-items-center gap-2 gap-sm-3 mb-4">
                    <a class="btn btn-accent fw-semibold px-4 py-2 rounded-pill shadow-sm" href="#cek-tiket" id="heroCheckTicketBtn">
                        <i class="bi bi-search me-2"></i>Cek E-Tiket Publik
                    </a>
                    <a class="btn fw-semibold px-4 py-2 rounded-pill border" style="background:var(--vs-surface);color:var(--vs-ink);border-color:var(--vs-border-strong);" href="<?= e($waHref('Halo Admin VSudoTrans, saya ingin konsultasi kemitraan dan demo sistem.')) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp text-success me-2"></i>Konsultasi Mitra
                    </a>
                </div>

                <!-- Compact Metric Strip -->
                <div class="row g-3 hero-metrics mt-1">
                    <div class="col-6 col-sm-3">
                        <div class="hero-metric-val"><?= count($activeCompanies) ?> Operator</div>
                        <div class="hero-metric-lbl">Terisolasi per PT</div>
                    </div>
                    <div class="col-6 col-sm-3">
                        <div class="hero-metric-val"><?= $totalRoutes > 0 ? number_format($totalRoutes, 0, ',', '.') . ' Rute' : 'Door-to-Door' ?></div>
                        <div class="hero-metric-lbl">Tarif &amp; kecamatan</div>
                    </div>
                    <div class="col-6 col-sm-3">
                        <div class="hero-metric-val"><?= $totalVehicles > 0 ? number_format($totalVehicles, 0, ',', '.') . ' Armada' : 'Multi-Kelas' ?></div>
                        <div class="hero-metric-lbl">Cek bentrok jadwal</div>
                    </div>
                    <div class="col-6 col-sm-3">
                        <div class="hero-metric-val">QRIS &amp; PDF</div>
                        <div class="hero-metric-lbl">E-Tiket tanpa login</div>
                    </div>
                </div>
            </div>

            <!-- Right Interactive Ticket Lookup Card -->
            <div class="col-lg-5">
                <div class="ticket-panel scroll-mt" id="cek-tiket">
                    <div class="ticket-panel-bar"></div>
                    <div class="p-4 p-md-4 p-xl-5">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="bento-icon" style="width:36px;height:36px;font-size:1.05rem;">
                                    <i class="bi bi-ticket-perforated-fill"></i>
                                </span>
                                <div>
                                    <h4 class="fw-bold mb-0 fs-5">Cek Status E-Tiket</h4>
                                </div>
                            </div>
                            <span class="badge rounded-pill fw-semibold" style="background:var(--vs-accent-soft);color:var(--vs-accent);">Publik</span>
                        </div>
                        <p class="small mb-3" style="color:var(--vs-muted);">
                            Masukkan nomor resi untuk melihat jadwal, armada, daftar penumpang, tagihan, dan unduh PDF E-Tiket.
                        </p>

                        <form method="post" action="">
                            <label class="form-label small fw-semibold mb-1" for="ticket_no" style="color:var(--vs-muted);">
                                Nomor Resi (Travel / Rental)
                            </label>
                            <div class="ticket-input-wrap d-flex align-items-center px-3 py-1 mb-2">
                                <i class="bi bi-upc-scan me-2" style="color:var(--vs-soft-muted);"></i>
                                <input class="form-control text-uppercase fw-bold px-1" id="ticket_no" name="no"
                                       pattern="(\d{10,20}|([Tt][Rr][Vv]|[Rr][Nn][Tt])-[A-Za-z2-9]{8})"
                                       title="Masukkan 10–20 digit nomor Resi (contoh: 100120261113)"
                                       placeholder="100120261113" maxlength="24" autocomplete="off" required
                                       value="<?= e($cek_no) ?>">
                            </div>

                            <?php if ($cek_err !== ''): ?>
                                <div class="alert alert-danger py-2 px-3 small mb-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                                    <span><?= e($cek_err) ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-center small mb-3" style="color:var(--vs-soft-muted);">
                                <span>Contoh resi: <span class="resi-code user-select-all">100120261113</span></span>
                                <span>Tanpa login</span>
                            </div>

                            <button class="btn btn-accent btn-lg w-100 fw-bold rounded-3 shadow-sm" type="submit" style="font-size:0.98rem;">
                                <i class="bi bi-search me-2"></i>Lacak &amp; Lihat E-Tiket
                            </button>
                        </form>

                        <div class="mt-3 pt-3 border-top d-flex align-items-center justify-content-center gap-2 small" style="border-color:var(--vs-border) !important;color:var(--vs-muted);">
                            <i class="bi bi-patch-check-fill text-success"></i>
                            <span>Terhubung langsung ke sistem resmi operator penerbit</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ================= TRUSTED OPERATORS STRIP ================= -->
<?php if (!empty($activeCompanies)): ?>
<section class="surface-band py-3">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-between gap-2 py-1">
            <span class="small fw-semibold text-uppercase" style="letter-spacing:0.06em;color:var(--vs-soft-muted);">Dipercaya Operator:</span>
            <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                <?php foreach ($activeCompanies as $comp):
                    $cCode = trim((string) ($comp['code'] ?? 'OP'));
                    $cLogo = trim((string) ($comp['logo'] ?? ''));
                    $cLogoUrl = $cLogo !== '' ? resolve_asset_url($cLogo) : '';
                ?>
                <div class="op-pill">
                    <?php if ($cLogoUrl !== ''): ?>
                        <img class="op-logo-img" src="<?= e($cLogoUrl) ?>" alt="<?= e($comp['name']) ?>" onerror="this.classList.add('d-none');this.nextElementSibling.classList.remove('d-none');">
                        <span class="op-monogram d-none"><?= e(substr($cCode, 0, 3)) ?></span>
                    <?php else: ?>
                        <span class="op-monogram"><?= e(substr($cCode, 0, 3)) ?></span>
                    <?php endif; ?>
                    <span class="small fw-semibold" style="color:var(--vs-ink);"><?= e($comp['name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ================= BENTO LAYANAN & MODUL UTAMA ================= -->
<section class="alt-band py-5 scroll-mt" id="layanan">
    <div class="container py-3 py-lg-4">
        <div class="row align-items-end mb-4 mb-lg-5 g-3">
            <div class="col-lg-7">
                <span class="eyebrow-tag">Arsitektur Terpadu</span>
                <h2 class="section-heading mt-1 mb-2">Dirancang Mengikuti Alur Nyata Operasional Transportasi</h2>
                <p class="mb-0" style="color:var(--vs-muted);">Dari pemesanan pelanggan hingga rekap laporan keuangan harian dan manajemen kru — semua tersinkron otomatis.</p>
            </div>
        </div>

        <!-- Asymmetric Bento Row 1: 7 + 5 -->
        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="bento-card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="bento-icon"><i class="bi bi-ticket-perforated-fill"></i></span>
                            <span class="badge rounded-pill border" style="background:var(--vs-bg);color:var(--vs-muted);border-color:var(--vs-border) !important;">Travel &amp; Rental</span>
                        </div>
                        <h4 class="fw-bold mb-2">Booking Travel Door-to-Door &amp; Sewa Armada</h4>
                        <p class="small mb-3" style="color:var(--vs-muted);">
                            Atur tarif antar-kota berdasarkan kategori armada, radius kecamatan jemput/antar, penumpang tambahan sesuai kapasitas kursi, serta validasi anti bentrok jadwal unit rental multi-hari.
                        </p>
                    </div>
                    <div class="mini-preview">
                        <div class="row g-2 align-items-center small">
                            <div class="col-sm-6">
                                <div class="fw-semibold"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Rute &amp; Radius Otomatis</div>
                                <div style="color:var(--vs-soft-muted);">Estimasi tarif &amp; batas Min–Maks terjaga</div>
                            </div>
                            <div class="col-sm-6">
                                <div class="fw-semibold"><i class="bi bi-shield-check text-success me-1"></i>Cek Bentrok Unit</div>
                                <div style="color:var(--vs-soft-muted);">Mencegah double-booking armada</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="bento-card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="bento-icon"><i class="bi bi-calendar2-week-fill"></i></span>
                            <span class="badge rounded-pill border" style="background:var(--vs-bg);color:var(--vs-muted);border-color:var(--vs-border) !important;">Operasional</span>
                        </div>
                        <h4 class="fw-bold mb-2">Kalender Jadwal, Dispatch &amp; Komisi Kru</h4>
                        <p class="small mb-0" style="color:var(--vs-muted);">
                            Pantau seluruh trip harian/mingguan/bulanan dalam satu kalender, tugaskan sopir beserta Surat Jalan, dan hitung premi Sopir, Kernet, Montir hingga Agen secara otomatis.
                        </p>
                    </div>
                    <ul class="check-list">
                        <li>Tampilan Bulan, Minggu, Hari &amp; Daftar</li>
                        <li>Peraturan komisi persentase atau nominal tetap</li>
                        <li>Cetak Surat Jalan siap pakai per perjalanan</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Bento Row 2: 3 Columns -->
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="bento-card p-4 h-100">
                    <div class="bento-icon mb-3"><i class="bi bi-qr-code-scan"></i></div>
                    <h5 class="fw-bold mb-2">Pembayaran Satu Pintu &amp; QRIS</h5>
                    <p class="small mb-0" style="color:var(--vs-muted);">
                        Kelola DP, pelunasan, upload bukti, QRIS dinamis, hingga Cetak/PDF Invoice langsung dari detail pemesanan — semua otomatis masuk ke Buku Kas.
                    </p>
                    <ul class="check-list">
                        <li>Validasi tagihan real-time &amp; auto status Lunas</li>
                        <li>Rekap arus kas &amp; laba kotor harian</li>
                    </ul>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="bento-card p-4 h-100">
                    <div class="bento-icon mb-3"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                    <h5 class="fw-bold mb-2">E-Tiket Publik &amp; WhatsApp</h5>
                    <p class="small mb-0" style="color:var(--vs-muted);">
                        Pelanggan cek tiket bermerek perusahaan operator kapan saja tanpa login, lengkap dengan cetak PDF dan notifikasi otomatis via WhatsApp.
                    </p>
                    <ul class="check-list">
                        <li>Berlogo &amp; berstempel resmi tiap perusahaan</li>
                        <li>Integrasi chatbot WhatsApp pemesanan</li>
                    </ul>
                </div>
            </div>

            <div class="col-md-12 col-lg-4">
                <div class="bento-card p-4 h-100">
                    <div class="bento-icon mb-3"><i class="bi bi-buildings-fill"></i></div>
                    <h5 class="fw-bold mb-2">Multi-Tenant, SDM &amp; Laporan</h5>
                    <p class="small mb-0" style="color:var(--vs-muted);">
                        Banyak PT berjalan dalam satu sistem dengan isolasi data ketat per perusahaan, hak akses peran RBAC, komponen gaji, dan ekspor PDF/Excel.
                    </p>
                    <ul class="check-list">
                        <li>Manajemen SDM, komponen gaji &amp; monitoring SIM</li>
                        <li>Ekspor laporan booking, kas &amp; driver</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= RUTE POPULER ================= -->
<?php if (!empty($popularRoutes)): ?>
<section class="surface-band py-5 scroll-mt" id="rute">
    <div class="container py-3 py-lg-4">
        <div class="row align-items-end mb-4 g-3">
            <div class="col-lg-8">
                <span class="eyebrow-tag">Jaringan Perjalanan</span>
                <h2 class="section-heading mt-1 mb-1">Rute Travel Antar-Kota Populer</h2>
                <p class="mb-0 small" style="color:var(--vs-muted);">Tarif ditampilkan mulai dari harga termurah sesuai kategori layanan dan titik jemput/antar.</p>
            </div>
        </div>

        <div class="row g-3 g-lg-4">
            <?php foreach ($popularRoutes as $r):
                $cats = array_values(array_unique(array_filter(explode('~;~', (string) ($r['categories'] ?? '')))));
                $fromCode = $r['from_short'] !== '' ? $r['from_short'] : (string) $r['city_from'];
                $toCode = $r['to_short'] !== '' ? $r['to_short'] : (string) $r['city_to'];
                $routeText = $r['city_from'] . ' → ' . $r['city_to'];
                $compWa = normalize_phone_62((string) ($r['company_phone'] ?? ''));
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="route-tile p-4 h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center flex-grow-1 me-2">
                            <span class="route-code-lg"><?= e($fromCode) ?></span>
                            <span class="route-track"><i class="bi bi-car-front-fill"></i></span>
                            <span class="route-code-lg"><?= e($toCode) ?></span>
                        </div>
                        <span class="badge rounded-pill border fw-semibold" style="background:var(--vs-bg);color:var(--vs-muted);border-color:var(--vs-border-strong) !important;"><?= e((string) $r['company_code']) ?></span>
                    </div>

                    <div class="small mb-3" style="color:var(--vs-muted);"><?= e($routeText) ?></div>

                    <div class="d-flex align-items-baseline justify-content-between mb-3">
                        <span class="small" style="color:var(--vs-soft-muted);">Mulai dari</span>
                        <span class="route-price"><?= e(rupiah((float) $r['min_price'])) ?></span>
                    </div>

                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php foreach (array_slice($cats, 0, 2) as $cat): ?>
                            <span class="badge rounded-pill fw-medium" style="background:var(--vs-accent-soft);color:var(--vs-accent);font-size:0.72rem;"><?= e($cat) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($cats) > 2): ?>
                            <span class="badge rounded-pill border fw-medium" style="background:var(--vs-bg);color:var(--vs-muted);border-color:var(--vs-border) !important;font-size:0.72rem;">+<?= count($cats) - 2 ?> layanan</span>
                        <?php endif; ?>
                    </div>

                    <div class="mt-auto pt-2">
                        <a href="<?= e($waHref('Halo Admin, saya ingin memesan tiket travel ' . $routeText . ' (' . $r['company_name'] . ').', $compWa)) ?>" target="_blank" rel="noopener" class="btn btn-sm w-100 fw-semibold rounded-3 border" style="background:var(--vs-bg);color:var(--vs-ink);border-color:var(--vs-border-strong) !important;">
                            <i class="bi bi-whatsapp text-success me-1"></i>Pesan Rute Ini
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ================= KATEGORI ARMADA ================= -->
<section class="alt-band py-5 scroll-mt" id="armada">
    <div class="container py-3 py-lg-4">
        <div class="row align-items-end mb-4 g-3">
            <div class="col-md-8">
                <span class="eyebrow-tag">Kesiapan Unit</span>
                <h2 class="section-heading mt-1 mb-1">Armada Aktif Berbagai Kapasitas</h2>
                <p class="mb-0 small" style="color:var(--vs-muted);">Dikelola langsung oleh mitra operator lengkap dengan jadwal kru dan histori perjalanan.</p>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="<?= e($waHref('Halo Admin VSudoTrans, saya ingin cek ketersediaan unit armada.')) ?>" target="_blank" rel="noopener" class="btn btn-sm rounded-pill px-3 fw-semibold border" style="background:var(--vs-surface);color:var(--vs-ink);border-color:var(--vs-border-strong) !important;">
                    <i class="bi bi-whatsapp text-success me-1"></i>Tanya Ketersediaan Unit
                </a>
            </div>
        </div>

        <div class="row g-3 g-lg-4 justify-content-center">
            <?php if ($fleetCategories): ?>
                <?php foreach ($fleetCategories as $i => $fc):
                    $seatMin = (int) ($fc['seat_min'] ?? 0);
                    $seatMax = (int) ($fc['seat_max'] ?? 0);
                    $seatLabel = $seatMax > 0 ? ($seatMin > 0 && $seatMin !== $seatMax ? $seatMin . '–' . $seatMax : (string) $seatMax) . ' Kursi' : '';
                    $fcBrands = trim((string) ($fc['brands'] ?? ''));
                ?>
                <div class="col-sm-6 col-lg-3">
                    <div class="fleet-tile p-4 h-100 d-flex flex-column" style="background:var(--vs-surface);">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="bento-icon" style="width:40px;height:40px;font-size:1.15rem;">
                                <i class="bi <?= e($fleetIcons[$i % count($fleetIcons)]) ?>"></i>
                            </span>
                            <?php if ($seatLabel !== ''): ?>
                                <span class="badge rounded-pill border fw-semibold" style="background:var(--vs-bg);color:var(--vs-muted);border-color:var(--vs-border) !important;">
                                    <i class="bi bi-people me-1"></i><?= e($seatLabel) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold mb-1 fs-6"><?= e((string) $fc['name']) ?></h5>
                        <p class="small mb-3" style="color:var(--vs-muted);"><?= $fcBrands !== '' ? e($fcBrands) : 'Kategori unit operasional aktif' ?></p>
                        <div class="mt-auto pt-3 border-top d-flex align-items-center justify-content-between small" style="border-color:var(--vs-border) !important;">
                            <span class="fw-semibold" style="color:var(--vs-ink);"><?= (int) $fc['unit_count'] ?> unit</span>
                            <span style="color:var(--vs-soft-muted);"><?= e((string) $fc['company_codes']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-lg-8">
                    <div class="fleet-tile p-4 text-center" style="background:var(--vs-surface);color:var(--vs-muted);">
                        <i class="bi bi-truck fs-2 d-block mb-2"></i>
                        Daftar armada mitra sedang diperbarui. Hubungi kami untuk informasi unit yang tersedia.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ================= MITRA OPERATOR & ENTERPRISE CTA ================= -->
<section class="surface-band py-5 scroll-mt" id="mitra">
    <div class="container py-3 py-lg-4">
        <div class="text-center mx-auto mb-4 mb-lg-5" style="max-width:660px;">
            <span class="eyebrow-tag">Ekosistem Multi-Tenant</span>
            <h2 class="section-heading mt-1 mb-2">Mitra Operator di Platform <?= e(APP_NAME) ?></h2>
            <p class="small mb-0" style="color:var(--vs-muted);">Setiap operator menjalankan operasional travel, rental, kas, dan pelaporannya secara independen dalam satu instalasi aman.</p>
        </div>

        <div class="row g-3 justify-content-center mb-5">
            <?php foreach ($activeCompanies as $comp):
                $cCode = trim((string) ($comp['code'] ?? 'OP'));
                $cLogo = trim((string) ($comp['logo'] ?? ''));
                $cLogoUrl = $cLogo !== '' ? resolve_asset_url($cLogo) : '';
            ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
                <div class="p-3 rounded-4 border d-flex align-items-center gap-3 h-100" style="background:var(--vs-bg);border-color:var(--vs-border) !important;">
                    <?php if ($cLogoUrl !== ''): ?>
                        <img class="op-logo-img" style="width:40px;height:40px;" src="<?= e($cLogoUrl) ?>" alt="<?= e($comp['name']) ?>" onerror="this.classList.add('d-none');this.nextElementSibling.classList.remove('d-none');">
                        <span class="op-monogram d-none" style="width:40px;height:40px;font-size:0.78rem;"><?= e(substr($cCode, 0, 3)) ?></span>
                    <?php else: ?>
                        <span class="op-monogram" style="width:40px;height:40px;font-size:0.78rem;"><?= e(substr($cCode, 0, 3)) ?></span>
                    <?php endif; ?>
                    <div class="overflow-hidden min-w-0">
                        <div class="text-truncate fw-bold small" title="<?= e($comp['name']) ?>"><?= e($comp['name']) ?></div>
                        <div class="small" style="color:var(--vs-soft-muted);font-size:0.76rem;"><i class="bi bi-geo-alt me-1"></i><?= e($comp['city'] ?: 'Indonesia') ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="cta-enterprise text-white p-4 p-md-5">
            <div class="row align-items-center g-4 position-relative" style="z-index:1;">
                <div class="col-lg-8">
                    <span class="badge rounded-pill bg-white bg-opacity-10 text-info border border-info border-opacity-25 mb-2">Kemitraan Operator</span>
                    <h3 class="fw-bold mb-2">Siap Digitalisasi Operasional Agen Travel atau PO Bus Anda?</h3>
                    <p class="mb-0 small text-white text-opacity-75" style="max-width:620px;line-height:1.6;">
                        Dapatkan sistem pemesanan, kalender armada &amp; sopir, komisi kru, pembayaran QRIS, laporan keuangan harian, hingga e-tiket dan invoice berlogo perusahaan Anda sendiri.
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a class="btn btn-light text-dark fw-bold px-4 py-2 rounded-pill shadow-sm" href="<?= e($waHref('Halo Admin, saya tertarik untuk mendaftarkan operator travel/bus saya ke platform VSudoTrans.')) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp text-success me-2"></i>Daftar Sebagai Mitra
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FAQ SECTION ================= -->
<section class="alt-band py-5 scroll-mt" id="faq">
    <div class="container py-3 py-lg-4">
        <div class="text-center mx-auto mb-4" style="max-width:640px;">
            <span class="eyebrow-tag">Pusat Informasi</span>
            <h2 class="section-heading mt-1">Pertanyaan yang Sering Diajukan</h2>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion accordion-flush faq-card d-flex flex-column gap-3" id="accordionFaq">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                Bagaimana cara mengecek keaslian dan status tiket saya?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body small" style="color:var(--vs-muted);">
                                Masukkan nomor Resi tiket (contoh: <span class="resi-code">100120261113</span>) pada kartu <strong>Cek Status E-Tiket</strong> di atas. Anda dapat melihat jadwal, armada, daftar penumpang, titik jemput/antar, total biaya, dan mengunduh PDF E-Tiket tanpa perlu login.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                Apakah data antar perusahaan operator di platform ini aman dan terpisah?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body small" style="color:var(--vs-muted);">
                                <strong>Ya.</strong> <?= e(APP_NAME) ?> menerapkan arsitektur Multi-Tenant dengan kontrol akses berbasis peran (RBAC). Data pelanggan, pemesanan, armada, kas, dan laporan tiap perusahaan operator terisolasi penuh.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                Apakah komisi sopir, kernet, dan pelaporan keuangan dihitung otomatis?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body small" style="color:var(--vs-muted);">
                                Ya. Modul Peraturan Komisi menghitung premi sopir, cadangan, kernet, montir, hingga agen sesuai kategori kendaraan. Pembayaran DP/pelunasan dan biaya perjalanan juga langsung tersinkron ke Buku Kas serta Rekap Harian Laporan Keuangan.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                Metode pembayaran apa saja yang didukung untuk pelanggan?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#accordionFaq">
                            <div class="accordion-body small" style="color:var(--vs-muted);">
                                Mendukung pembayaran tunai, transfer, EDC/kartu, serta QRIS dinamis yang dapat dipindai dari mobile banking atau e-wallet langsung melalui halaman E-Tiket maupun detail pemesanan.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="landing-footer pt-5 pb-4">
    <div class="container">
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="brand-mark" style="width:34px;height:34px;"><i class="bi bi-diagram-3-fill"></i></span>
                    <span class="fs-5 fw-bold text-white"><?= e(APP_NAME) ?></span>
                </div>
                <p class="small mb-3" style="max-width:320px;">
                    Platform ekosistem digital terpadu untuk Agen Travel, PO Bus Pariwisata, dan Rental Mobil di Indonesia.
                </p>
                <?php if ($contactDisplay !== ''): ?>
                <div class="small">
                    <i class="bi bi-whatsapp text-success me-2"></i><?= e($contactDisplay) ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold text-white mb-3 small text-uppercase" style="letter-spacing:0.05em;">Navigasi</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="#layanan" class="text-secondary text-decoration-none">Layanan &amp; Fitur</a></li>
                    <?php if (!empty($popularRoutes)): ?>
                    <li class="mb-2"><a href="#rute" class="text-secondary text-decoration-none">Rute Populer</a></li>
                    <?php endif; ?>
                    <li class="mb-2"><a href="#armada" class="text-secondary text-decoration-none">Kategori Armada</a></li>
                    <li class="mb-2"><a href="#mitra" class="text-secondary text-decoration-none">Mitra Operator</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="fw-bold text-white mb-3 small text-uppercase" style="letter-spacing:0.05em;">Akses Cepat</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="#cek-tiket" class="text-secondary text-decoration-none">Cek E-Tiket</a></li>
                    <li class="mb-2"><a href="<?= e(APP_URL) ?>/pages/auth/login.php" class="text-secondary text-decoration-none">Login Staff ERP</a></li>
                    <li class="mb-2"><a href="#faq" class="text-secondary text-decoration-none">Pusat FAQ</a></li>
                </ul>
            </div>

            <div class="col-lg-4">
                <h6 class="fw-bold text-white mb-3 small text-uppercase" style="letter-spacing:0.05em;">Bantuan &amp; Kemitraan</h6>
                <p class="small mb-3">
                    Butuh informasi rute, ketersediaan unit, atau demo implementasi sistem untuk armada Anda?
                </p>
                <a href="<?= e($waHref('Halo Admin VSudoTrans, saya ingin bertanya mengenai layanan & kemitraan.')) ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="bi bi-whatsapp me-1 text-success"></i>Chat WhatsApp Support
                </a>
            </div>
        </div>

        <div class="border-top border-secondary border-opacity-25 pt-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 small">
            <div>&copy; <?= date('Y') ?> <strong class="text-white"><?= e(APP_NAME) ?></strong>. Hak Cipta Dilindungi.</div>
            <div>Ekosistem Transportasi Darat Multi-Tenant</div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function () {
    var btn = document.getElementById('landingThemeToggle');
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

    // Bersihkan spasi saat paste/ketik nomor resi & fokus otomatis dari tombol Hero
    var ticketInp = document.getElementById('ticket_no');
    if (ticketInp) {
      ticketInp.addEventListener('input', function () {
        var cleaned = this.value.replace(/\s+/g, '').toUpperCase();
        if (cleaned !== this.value) this.value = cleaned;
      });
    }
    var heroCheckBtn = document.getElementById('heroCheckTicketBtn');
    if (heroCheckBtn && ticketInp) {
      heroCheckBtn.addEventListener('click', function () {
        setTimeout(function () { ticketInp.focus({ preventScroll: true }); }, 260);
      });
    }
  })();
</script>
</body>
</html>
