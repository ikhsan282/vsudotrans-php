<?php
/**
 * Halaman Pembayaran QRIS Dinamis Otomatis (GoPay Merchant / ShopeePay Partner / KlikQRIS).
 * Dapat diakses oleh:
 * 1. Admin/Staff melalui `?invoice_id=<id>&provider=<gopay|shopeepay|klikqris>`
 * 2. Pelanggan publik melalui `?no=<document_number>&provider=<opsional>` (dari tiket.php / WordPress / WA)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/booking.php';
require_once __DIR__ . '/../../../includes/qr_svg.php';

$db = db();
$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$provider = trim((string) ($_GET['provider'] ?? ''));

$inv = null;
if ($no !== '' && booking_doc_valid($no)) {
    $st = $db->prepare('SELECT * FROM invoices WHERE document_number = ? LIMIT 1');
    $st->bind_param('s', $no);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $inv = invoice_find((int) $row['id']);
    }
} elseif ($invoiceId > 0) {
    require_login();
    $inv = invoice_find($invoiceId);
    if ($inv) {
        assert_company_access((int) $inv['company_id']);
    }
}

if (!$inv || $inv['status'] === 'cancelled') {
    http_response_code(404);
    exit('Tagihan/Invoice tidak ditemukan atau sudah dibatalkan.');
}

$companyId = (int) $inv['company_id'];
$remaining = max(0.0, round((float) $inv['total'] - (float) $inv['paid_total'], 2));
$bookingPayRel = 'pages/' . (($inv['booking_type'] ?? '') === 'rental' ? 'rental' : 'travel')
    . '/bookings/detail.php?id=' . (int) ($inv['booking_id'] ?? 0) . '&tab=payment#tab-payment';
if ($remaining <= 0) {
    if ($no !== '') {
        header('Location: ' . APP_URL . '/tiket.php?no=' . rawurlencode($inv['document_number']));
        exit;
    }
    flash('success', 'Invoice ini sudah lunas.');
    redirect($bookingPayRel);
}

$channel = gateway_active_channel($companyId, $provider !== '' ? $provider : null);
if (!$channel) {
    http_response_code(503);
    exit('Channel QRIS Otomatis belum diaktifkan untuk perusahaan ini.');
}

$activeProvider = (string) $channel['provider'];
$order = gateway_pending_for_invoice((int) $inv['id'], $activeProvider);
if ($order && !empty($order['expired_at']) && strtotime((string) $order['expired_at']) <= time()) {
    gateway_mark_expired((string) $order['order_id']);
    $order = null;
}

$fee    = gateway_fee($channel, $remaining);
$charge = gateway_charge_total($channel, $remaining);
$sessUser = $_SESSION['username'] ?? 'public-web';
$payCsrf  = csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$order) {
    $orderId = gateway_make_order_id((int) $inv['id']);
    $res = gateway_create($channel, $orderId, $charge, 'Tiket ' . $inv['document_number']);
    if (!$res['ok']) {
        http_response_code(502);
        exit('Gagal membuat QRIS Dinamis: ' . e((string) ($res['error'] ?? 'Unknown error')));
    }
    $data = $res['data'];
    gateway_store_order(
        $companyId,
        (int) $channel['id'],
        $orderId,
        $inv,
        $charge,
        $data,
        'web',
        $sessUser
    );
    $order = gateway_find_order($orderId);
}

$total      = (float) $order['total_amount'];
$uniqueCode = max(0.0, round($total - $charge, 2));

$payloadArr = !empty($order['payload']) ? (json_decode((string) $order['payload'], true) ?: []) : [];
$qrisString = (string) ($payloadArr['qris_string'] ?? '');
$qrisImgUrl = $qrisString !== ''
    ? gq_qr_url($qrisString)
    : trim((string) ($order['qris_url'] ?? ($payloadArr['qris_url'] ?? '')));
if ($qrisImgUrl !== '' && $qrisImgUrl !== trim((string) ($order['qris_url'] ?? ''))) {
    $upQr = $db->prepare('UPDATE payment_gateway_orders SET qris_url = ? WHERE id = ?');
    $ordRowId = (int) $order['id'];
    $upQr->bind_param('si', $qrisImgUrl, $ordRowId);
    $upQr->execute();
    $upQr->close();
    $order['qris_url'] = $qrisImgUrl;
}
$fallbackQrUrl = $qrisString !== ''
    ? ('https://quickchart.io/qr?size=300&margin=4&text=' . rawurlencode($qrisString))
    : '';
$localQrDataUri = $qrisString !== '' ? qr_svg_data_uri($qrisString, 4, 260) : '';
$primaryQrSrc   = $localQrDataUri !== '' ? $localQrDataUri : $qrisImgUrl;
$primaryFallback = $localQrDataUri !== '' ? $qrisImgUrl : $fallbackQrUrl;

$stComp = $db->prepare('SELECT name, code, logo FROM companies WHERE id = ? LIMIT 1');
$stComp->bind_param('i', $companyId);
$stComp->execute();
$comp = $stComp->get_result()->fetch_assoc() ?: ['name' => APP_NAME, 'code' => '', 'logo' => ''];
$stComp->close();

$backUrl = $no !== ''
    ? (APP_URL . '/tiket.php?no=' . rawurlencode((string) $inv['document_number']))
    : (APP_URL . '/' . $bookingPayRel);
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran QRIS — <?= e($inv['document_number']) ?></title>
    <script>
      (function(){try{var t=localStorage.getItem('vst_theme');if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
      .vst-skel {
        display: block;
        border-radius: 8px;
        background: linear-gradient(90deg, #e5eaf2 20%, #f4f7fc 44%, #e5eaf2 68%);
        background-size: 320% 100%;
        animation: vst-shimmer 1.35s ease-in-out infinite;
      }
      @keyframes vst-shimmer {
        0% { background-position: 100% 50%; }
        100% { background-position: 0 50%; }
      }
      .vst-qr-img-wrap { position: relative; display: inline-block; }
      .vst-qr-img-skel {
        position: absolute;
        inset: 10px;
        border-radius: 8px;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .18s ease, visibility .18s ease;
      }
      .vst-qr-img-wrap.is-loading .vst-qr-img-skel { opacity: 1; visibility: visible; }
      .vst-live-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #b45309;
        vertical-align: middle;
        animation: vst-live-pulse 1.25s ease-in-out infinite;
      }
      @keyframes vst-live-pulse {
        0%, 100% { transform: scale(.85); opacity: .65; }
        50% { transform: scale(1.1); opacity: 1; }
      }
      @media (prefers-reduced-motion: reduce) {
        .vst-skel, .vst-live-dot { animation: none !important; }
      }
    </style>
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width: 540px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
        <span class="badge bg-secondary"><?= e($channel['name']) ?></span>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4 text-center">
            <h5 class="fw-bold mb-1"><?= e($comp['name']) ?></h5>
            <div class="text-muted small mb-3">No. Tiket: <strong><?= e($inv['document_number']) ?></strong> · Invoice: <?= e($inv['invoice_no']) ?></div>

            <div id="gatewayQrWrap" class="p-3 bg-white border rounded-3 d-inline-block mb-3 qr-code-frame vst-qr-img-wrap is-loading">
                <span class="vst-skel vst-qr-img-skel" aria-hidden="true"></span>
                <img id="gatewayQrImg" src="<?= e($primaryQrSrc) ?>" alt="QRIS Dinamis" width="260" height="260" class="img-fluid"
                     <?php if ($primaryFallback !== ''): ?>onerror="if(this.src!==<?= e(json_encode($primaryFallback)) ?>)this.src=<?= e(json_encode($primaryFallback)) ?>;"<?php endif; ?>>
            </div>

            <div class="border rounded bg-body-secondary p-3 text-start mb-3 small">
                <div class="d-flex justify-content-between">
                    <span>Pokok Tagihan</span>
                    <span class="fw-semibold"><?= e(rupiah($remaining)) ?></span>
                </div>
                <?php if ($fee > 0): ?>
                <div class="d-flex justify-content-between mt-1 text-muted">
                    <span>Biaya Layanan QRIS</span>
                    <span><?= e(rupiah($fee)) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($uniqueCode > 0): ?>
                <div class="d-flex justify-content-between mt-1 text-muted">
                    <span>Kode Unik Verifikasi Otomatis</span>
                    <span>+<?= e(rupiah($uniqueCode)) ?></span>
                </div>
                <?php endif; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between fw-bold fs-6">
                    <span>Total Bayar Tepat</span>
                    <span class="text-primary"><?= e(rupiah($total)) ?></span>
                </div>
            </div>

            <h3 class="fw-bold mb-1"><?= e(rupiah($total)) ?></h3>
            <p class="text-danger small mb-2">Scan QRIS di atas menggunakan aplikasi m-Banking / GoPay / ShopeePay / OVO / DANA. Nominal sudah terisi otomatis.</p>
            <p class="small text-muted mb-3">Berlaku sampai: <strong><?= e((string) ($order['expired_at'] ?? '—')) ?></strong></p>

            <?php if (!empty($channel['instructions'])): ?>
                <div class="alert alert-secondary small text-start py-2 mb-3"><?= nl2br(e((string) $channel['instructions'])) ?></div>
            <?php endif; ?>

            <div id="payStatus" class="badge bg-warning text-dark fs-6 px-3 py-2" role="status" aria-live="polite">
                <span class="vst-live-dot me-1" role="status" aria-hidden="true"></span>
                Menunggu pembayaran…
            </div>
        </div>
    </div>
</main>
<script>
(function () {
    const wrap = document.getElementById('gatewayQrWrap');
    const img  = document.getElementById('gatewayQrImg');
    if (wrap && img) {
        const done = () => wrap.classList.remove('is-loading');
        if (img.complete && img.naturalWidth > 0) done();
        else {
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', function () { setTimeout(done, 400); }, { once: true });
        }
    }
})();
const cfg = {
    url: 'gateway_status.php',
    orderId: <?= json_encode($order['order_id']) ?>,
    csrf: <?= json_encode($payCsrf) ?>,
    redirectUrl: <?= json_encode($backUrl) ?>
};
const label = document.getElementById('payStatus');
async function poll() {
    try {
        const body = new URLSearchParams({ order_id: cfg.orderId, csrf_token: cfg.csrf });
        const r = await fetch(cfg.url, { method: 'POST', body, credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        if (j && j.status === 'PAID') {
            label.className = 'badge bg-success fs-6 px-3 py-2';
            label.textContent = 'Pembayaran Berhasil! Mengalihkan…';
            setTimeout(() => { location.href = cfg.redirectUrl; }, 1000);
            return;
        }
        if (j && (j.status === 'EXPIRED' || j.status === 'FAILED')) {
            label.className = 'badge bg-danger fs-6 px-3 py-2';
            label.textContent = 'QRIS Kedaluwarsa. Muat ulang halaman untuk membuat QR baru.';
            return;
        }
    } catch (e) {
        // abaikan gangguan jaringan sementara, lanjut polling
    }
    setTimeout(poll, 5000);
}
setTimeout(poll, 5000);
</script>
</body>
</html>

