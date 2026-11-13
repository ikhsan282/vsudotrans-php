<?php
/**
 * AJAX Endpoint QRIS Otomatis pada Tab Pembayaran (Rental & Travel Detail).
 * Aksi:
 *  - act=create : Membuat (atau memakai ulang order PENDING identik) QRIS Dinamis untuk nominal & tipe pembayaran tertentu.
 *  - act=poll   : Memeriksa mutasi order PENDING pada invoice terkait, memfinalisasi bila lunas, dan mengembalikan daftar pembayaran terbaru.
 * Parameter wajib: booking_id, booking_type ('rental'|'travel', default 'rental').
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/booking_service.php';
require_once __DIR__ . '/../../../includes/invoice_service.php';
require_once __DIR__ . '/../../../includes/payment_gateway.php';
require_once __DIR__ . '/../../../includes/azure_blob.php';

header('Content-Type: application/json; charset=utf-8');

function rental_qris_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rental_qris_format_payments(int $invoiceId): array
{
    $rows = invoice_payments($invoiceId);
    $out = [];
    foreach ($rows as $pr) {
        $pDate = (string) ($pr['pay_date'] ?? date('Y-m-d'));
        $ts = strtotime($pDate);
        $blob = (string) ($pr['proof_blob_path'] ?? '');
        $out[] = [
            'id'              => (int) ($pr['id'] ?? 0),
            'pay_type'        => (string) ($pr['pay_type'] ?? 'Pelunasan'),
            'pay_method'      => (string) ($pr['pay_method'] ?? 'QRIS'),
            'pay_date'        => $pDate,
            'pay_date_fmt'    => $ts ? date('d-M-Y', $ts) : $pDate,
            'amount'          => (float) ($pr['amount'] ?? 0),
            'amount_fmt'      => format_nominal((float) ($pr['amount'] ?? 0)),
            'is_auto_qris'    => !empty($pr['is_auto_qris']),
            'proof_blob_path' => $blob,
            'proof_url'       => $blob !== '' ? resolve_asset_url($blob) : '',
            'note'            => (string) ($pr['note'] ?? ''),
        ];
    }
    return $out;
}

function rental_qris_extract_order_visuals(array $order): array
{
    $db = db();
    $payloadArr = !empty($order['payload']) ? (json_decode((string) $order['payload'], true) ?: []) : [];
    if (!is_array($payloadArr)) {
        $payloadArr = [];
    }
    $qrisString = (string) ($payloadArr['qris_string'] ?? '');
    $qrisImgUrl = '';
    if ($qrisString !== '') {
        $qrisImgUrl = gq_qr_url($qrisString);
    } else {
        $qrisImgUrl = trim((string) ($order['qris_url'] ?? ($payloadArr['qris_url'] ?? '')));
    }
    if ($qrisImgUrl !== '' && $qrisImgUrl !== trim((string) ($order['qris_url'] ?? '')) && !empty($order['id'])) {
        $upQr = $db->prepare('UPDATE payment_gateway_orders SET qris_url = ? WHERE id = ?');
        $ordRowId = (int) $order['id'];
        $upQr->bind_param('si', $qrisImgUrl, $ordRowId);
        $upQr->execute();
        $upQr->close();
    }
    $fallbackQrUrl = $qrisString !== ''
        ? ('https://quickchart.io/qr?size=300&margin=4&text=' . rawurlencode($qrisString))
        : '';

    return [$payloadArr, $qrisImgUrl, $fallbackQrUrl, $qrisString];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rental_qris_reply(405, ['ok' => false, 'error' => 'Method tidak diizinkan.']);
}
if (empty($_SESSION['user_id'])) {
    rental_qris_reply(401, ['ok' => false, 'error' => 'Sesi login telah berakhir. Silakan muat ulang halaman.']);
}
$bookingType = trim((string) ($_POST['booking_type'] ?? 'rental'));
if (!in_array($bookingType, ['rental', 'travel'], true)) {
    rental_qris_reply(400, ['ok' => false, 'error' => 'Tipe booking tidak valid.']);
}
if (!can($bookingType . '.view')) {
    rental_qris_reply(403, ['ok' => false, 'error' => 'Anda tidak memiliki izin mengakses data ' . $bookingType . '.']);
}
if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    rental_qris_reply(403, ['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
}

$act       = trim((string) ($_POST['act'] ?? 'poll'));
$bookingId = (int) ($_POST['booking_id'] ?? 0);
$b         = $bookingId > 0 ? booking_find($bookingType, $bookingId) : null;
if (!$b) {
    rental_qris_reply(404, ['ok' => false, 'error' => 'Data pemesanan ' . ($bookingType === 'travel' ? 'travel' : 'sewa') . ' tidak ditemukan.']);
}
if (!is_super_admin() && (int) $b['company_id'] !== active_company_id()) {
    rental_qris_reply(403, ['ok' => false, 'error' => 'Akses perusahaan ditolak.']);
}

$db        = db();
$companyId = (int) $b['company_id'];
$user      = $_SESSION['username'] ?? null;

$stInv = $db->prepare('SELECT id FROM invoices WHERE booking_type = ? AND booking_id = ? AND company_id = ? LIMIT 1');
$stInv->bind_param('sii', $bookingType, $bookingId, $companyId);
$stInv->execute();
$invRow = $stInv->get_result()->fetch_assoc();
$stInv->close();

$invoiceId = $invRow ? (int) $invRow['id'] : 0;

if ($act === 'create') {
    if (!can($bookingType . '.manage')) {
        rental_qris_reply(403, ['ok' => false, 'error' => 'Anda tidak memiliki izin membuat pembayaran QRIS.']);
    }

    $curStatus = booking_current_status($bookingType, $bookingId);
    if ($curStatus === 'cancelled') {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Booking sudah dibatalkan — tidak dapat menerima pembayaran QRIS.']);
    }

    if ($invoiceId <= 0) {
        $invoiceId = invoice_issue(
            $companyId,
            $bookingType,
            $bookingId,
            !empty($b['branch_id']) ? (int) $b['branch_id'] : null,
            (string) $b['document_number'],
            (float) $b['total_price'],
            $user
        );
    }

    $inv = invoice_find($invoiceId);
    if (!$inv || $inv['status'] === 'cancelled') {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Invoice tidak tersedia atau sudah dibatalkan.']);
    }

    $invTotal    = (float) $inv['total'];
    $alreadyPaid = (float) ($inv['paid_total'] ?? invoice_paid_total($invoiceId));
    $remaining   = max(0.0, round($invTotal - $alreadyPaid, 2));
    if ($remaining <= 0) {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Tagihan invoice ini sudah lunas.']);
    }

    $allowedPayTypes = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'];
    $payType = trim((string) ($_POST['pay_type'] ?? 'Pelunasan'));
    if (!in_array($payType, $allowedPayTypes, true)) {
        $payType = 'Pelunasan';
    }

    $billAmount = (float) parse_rupiah_input($_POST['amount'] ?? 0);
    if ($billAmount <= 0) {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Nominal pembayaran QRIS harus lebih dari 0.']);
    }
    if ($billAmount > $remaining + 0.01) {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Nominal melebihi sisa tagihan invoice (' . rupiah($remaining) . ').']);
    }

    $provider = trim((string) ($_POST['provider'] ?? ''));
    $channel  = gateway_active_channel($companyId, $provider !== '' ? $provider : null);
    if (!$channel) {
        rental_qris_reply(422, ['ok' => false, 'error' => 'Channel QRIS aktif belum dikonfigurasi untuk perusahaan ini.']);
    }

    $forceNew = !empty($_POST['force_new']);
    $charge   = gateway_charge_total($channel, $billAmount);
    $fee      = gateway_fee($channel, $billAmount);
    $order    = null;

    if (!$forceNew) {
        $cand = gateway_pending_for_invoice($invoiceId, $channel['provider'] ?? null);
        if ($cand && (empty($cand['expired_at']) || strtotime((string) $cand['expired_at']) > time() + 60)) {
            $candPayload = !empty($cand['payload']) ? (json_decode((string) $cand['payload'], true) ?: []) : [];
            $candBill = isset($candPayload['bill_amount']) ? (float) $candPayload['bill_amount'] : (float) $cand['amount'];
            $candType = trim((string) ($candPayload['pay_type'] ?? ''));
            if ((int) $cand['channel_id'] === (int) $channel['id']
                && abs($candBill - $billAmount) <= 0.01
                && abs((float) $cand['amount'] - $charge) <= 0.01
                && $candType === $payType) {
                $order = $cand;
            }
        }
    }

    if (!$order) {
        $orderId = gateway_make_order_id($invoiceId);
        $descPrefix = $bookingType === 'travel' ? 'Travel ' : 'Sewa ';
        $res = gateway_create($channel, $orderId, $charge, $descPrefix . ($b['document_number'] ?? ''));
        if (empty($res['ok'])) {
            rental_qris_reply(502, ['ok' => false, 'error' => (string) ($res['error'] ?? 'Gagal membuat transaksi QRIS.')]);
        }
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $data['bill_amount']  = $billAmount;
        $data['fee']          = $fee;
        $data['pay_type']     = $payType;
        $data['channel_name'] = (string) ($channel['name'] ?? '');

        gateway_store_order(
            $companyId,
            (int) $channel['id'],
            $orderId,
            $inv,
            $charge,
            $data,
            'admin-' . $bookingType,
            $user
        );
        $order = gateway_find_order($orderId);
    }

    if (!$order) {
        rental_qris_reply(500, ['ok' => false, 'error' => 'Gagal memuat order QRIS yang baru dibuat.']);
    }

    [$payloadArr, $qrisImgUrl, $fallbackQrUrl] = rental_qris_extract_order_visuals($order);
    $totalAmount = (float) $order['total_amount'];
    $uniqueCode  = max(0, (int) round($totalAmount - (float) $order['amount']));
    $provVal     = (string) ($order['provider'] ?? ($channel['provider'] ?? ''));
    $payWebUrl   = APP_URL . '/pages/finance/invoices/gateway_pay.php?no=' . rawurlencode((string) $b['document_number'])
                 . ($provVal !== '' ? ('&provider=' . rawurlencode($provVal)) : '');

    rental_qris_reply(200, [
        'ok'              => true,
        'order_id'        => (string) $order['order_id'],
        'provider'        => $provVal,
        'channel_name'    => (string) ($order['channel_name'] ?? ($channel['name'] ?? 'QRIS')),
        'pay_type'        => (string) ($payloadArr['pay_type'] ?? $payType),
        'bill_amount'     => (float) ($payloadArr['bill_amount'] ?? $billAmount),
        'fee'             => (float) ($payloadArr['fee'] ?? $fee),
        'unique_code'     => $uniqueCode,
        'total_amount'    => $totalAmount,
        'expired_at'      => (string) ($order['expired_at'] ?? ''),
        'expired_ts'      => !empty($order['expired_at']) ? strtotime((string) $order['expired_at']) : null,
        'qris_image_url'  => $qrisImgUrl,
        'fallback_qr_url' => $fallbackQrUrl,
        'direct_url'      => (string) ($order['direct_url'] ?? ''),
        'pay_web_url'     => $payWebUrl,
        'invoice_total'   => $invTotal,
        'paid_total'      => $alreadyPaid,
        'remaining'       => $remaining,
    ]);
}

// Default action: act=poll
$orderId     = trim((string) ($_POST['order_id'] ?? ''));
$orderStatus = $orderId !== '' ? 'NOT_FOUND' : 'NONE';
$note        = '';

if ($invoiceId > 0) {
    $pendingOrders = [];
    $stPend = $db->prepare(
        "SELECT o.*, c.api_key, c.merchant_id, c.mode, c.name AS channel_name, c.provider, c.config
           FROM payment_gateway_orders o
           JOIN payment_channels c ON c.id = o.channel_id
          WHERE o.invoice_id = ? AND o.status = 'PENDING'
          ORDER BY o.id DESC
          LIMIT 5"
    );
    $stPend->bind_param('i', $invoiceId);
    $stPend->execute();
    $pendingOrders = $stPend->get_result()->fetch_all(MYSQLI_ASSOC);
    $stPend->close();

    foreach ($pendingOrders as $ord) {
        $oid = (string) $ord['order_id'];
        if (!empty($ord['expired_at']) && strtotime((string) $ord['expired_at']) <= time()) {
            gateway_mark_expired($oid);
            if ($orderId !== '' && $oid === $orderId) {
                $orderStatus = 'EXPIRED';
            }
            continue;
        }
        $channel = [
            'id'          => (int) $ord['channel_id'],
            'company_id'  => (int) $ord['company_id'],
            'api_key'     => $ord['api_key'],
            'merchant_id' => $ord['merchant_id'],
            'mode'        => $ord['mode'],
            'provider'    => $ord['provider'] ?? '',
            'config'      => $ord['config'] ?? '',
        ];
        $remote = gateway_status($channel, $oid);
        if (!empty($remote['ok'])) {
            $data  = is_array($remote['data'] ?? null) ? $remote['data'] : [];
            $state = strtoupper((string) ($data['status'] ?? ''));

            if (in_array($state, ['SUCCESS', 'PAID', 'SETTLED'], true)
                && isset($data['total_amount'])
                && abs((float) $data['total_amount'] - (float) $ord['total_amount']) <= 0.01) {
                $done = gateway_finalize($oid, (float) $data['total_amount']);
                if (!empty($done['ok']) && $orderId !== '' && $oid === $orderId) {
                    $orderStatus = 'PAID';
                }
            } elseif ($state === 'EXPIRED') {
                gateway_mark_expired($oid);
                if ($orderId !== '' && $oid === $orderId) {
                    $orderStatus = 'EXPIRED';
                }
            } elseif ($orderId !== '' && $oid === $orderId) {
                $orderStatus = 'PENDING';
            }
        } elseif ($orderId !== '' && $oid === $orderId) {
            $orderStatus = 'PENDING';
            $note = 'Menunggu pengecekan mutasi berkala.';
        }
    }

    if ($orderId !== '') {
        $targetOrd = gateway_find_order($orderId);
        if ($targetOrd && (int) $targetOrd['invoice_id'] === $invoiceId) {
            $orderStatus = (string) $targetOrd['status'];
        }
    }
}

$inv        = $invoiceId > 0 ? invoice_find($invoiceId) : null;
$invTotal   = $inv ? (float) $inv['total'] : (float) $b['total_price'];
$paidTotal  = $inv ? (float) ($inv['paid_total'] ?? invoice_paid_total($invoiceId)) : 0.0;
$remaining  = max(0.0, round($invTotal - $paidTotal, 2));
$payments   = $invoiceId > 0 ? rental_qris_format_payments($invoiceId) : [];
$curBkStat  = booking_current_status($bookingType, $bookingId) ?: 'draft';

$hasPending = false;
if ($invoiceId > 0) {
    $stChk = $db->prepare("SELECT 1 FROM payment_gateway_orders WHERE invoice_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    $stChk->bind_param('i', $invoiceId);
    $stChk->execute();
    $hasPending = (bool) $stChk->get_result()->fetch_row();
    $stChk->close();
}

rental_qris_reply(200, [
    'ok'                   => true,
    'booking_type'         => $bookingType,
    'invoice_id'           => $invoiceId,
    'order_id'             => $orderId,
    'order_status'         => $orderStatus,
    'note'                 => $note,
    'booking_status'       => $curBkStat,
    'booking_status_badge' => status_badge($curBkStat),
    'invoice_total'        => $invTotal,
    'paid_total'           => $paidTotal,
    'remaining'            => $remaining,
    'has_pending'          => $hasPending,
    'payments'             => $payments,
]);

