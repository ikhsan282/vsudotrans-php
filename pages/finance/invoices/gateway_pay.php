<?php
/**
 * Halaman Pembayaran QRIS Dinamis Otomatis (GoPay Merchant / ShopeePay Partner / KlikQRIS).
 * Dapat diakses oleh:
 * 1. Admin/Staff melalui `?invoice_id=<id>&provider=<gopay|shopeepay|klikqris>`
 * 2. Pelanggan publik melalui `?no=<document_number>&provider=<opsional>` (dari tiket.php / WordPress / WA)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';

$db = db();
$no = strtoupper(trim((string) ($_GET['no'] ?? '')));
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$provider = trim((string) ($_GET['provider'] ?? ''));

$inv = null;
if ($no !== '' && preg_match('/^(TRV|RNT)-[A-Z2-9]{8}$/', $no)) {
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
if ($remaining <= 0) {
    if ($no !== '') {
        header('Location: ' . APP_URL . '/tiket.php?no=' . rawurlencode($inv['document_number']));
        exit;
    }
    flash('success', 'Invoice ini sudah lunas.');
    redirect('pages/finance/invoices/detail.php?id=' . (int) $inv['id']);
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
        $_SESSION['username'] ?? 'public-web'
    );
    $order = gateway_find_order($orderId);
}

$total      = (float) $order['total_amount'];
$uniqueCode = max(0.0, round($total - $charge, 2));

$payloadArr = !empty($order['payload']) ? (json_decode((string) $order['payload'], true) ?: []) : [];
$qrisString = (string) ($payloadArr['qris_string'] ?? '');
$qrisImgUrl = trim((string) ($order['qris_url'] ?? ''));
if ($qrisImgUrl === '' || $qrisImgUrl === '0') {
    $qrisImgUrl = trim((string) ($payloadArr['qris_url'] ?? ''));
    if (($qrisImgUrl === '' || $qrisImgUrl === '0') && $qrisString !== '') {
        $qrisImgUrl = gq_qr_url($qrisString);
    }
    if ($qrisImgUrl !== '' && $qrisImgUrl !== '0') {
        $upQr = $db->prepare('UPDATE payment_gateway_orders SET qris_url = ? WHERE id = ?');
        $ordRowId = (int) $order['id'];
        $upQr->bind_param('si', $qrisImgUrl, $ordRowId);
        $upQr->execute();
        $upQr->close();
        $order['qris_url'] = $qrisImgUrl;
    }
}
$fallbackQrUrl = $qrisString !== ''
    ? ('https://quickchart.io/qr?size=300&margin=1&text=' . rawurlencode($qrisString))
    : '';

$stComp = $db->prepare('SELECT name, code, logo FROM companies WHERE id = ? LIMIT 1');
$stComp->bind_param('i', $companyId);
$stComp->execute();
$comp = $stComp->get_result()->fetch_assoc() ?: ['name' => APP_NAME, 'code' => '', 'logo' => ''];
$stComp->close();

$backUrl = $no !== ''
    ? (APP_URL . '/tiket.php?no=' . rawurlencode((string) $inv['document_number']))
    : (APP_URL . '/pages/finance/invoices/detail.php?id=' . (int) $inv['id']);
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran QRIS — <?= e($inv['document_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width: 540px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
        <span class="badge bg-dark"><?= e($channel['name']) ?></span>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4 text-center">
            <h5 class="fw-bold mb-1"><?= e($comp['name']) ?></h5>
            <div class="text-muted small mb-3">No. Tiket: <strong><?= e($inv['document_number']) ?></strong> · Invoice: <?= e($inv['invoice_no']) ?></div>

            <div class="p-3 bg-white border rounded-3 d-inline-block mb-3">
                <img src="<?= e($qrisImgUrl) ?>" alt="QRIS Dinamis" width="260" height="260" class="img-fluid"
                     <?php if ($fallbackQrUrl !== ''): ?>onerror="if(this.src!==<?= e(json_encode($fallbackQrUrl)) ?>)this.src=<?= e(json_encode($fallbackQrUrl)) ?>;"<?php endif; ?>>
            </div>

            <div class="border rounded bg-light p-3 text-start mb-3 small">
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
                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                Menunggu pembayaran…
            </div>
        </div>
    </div>
</main>
<script>
const cfg = {
    url: 'gateway_status.php',
    orderId: <?= json_encode($order['order_id']) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
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

