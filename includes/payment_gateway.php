<?php
/**
 * Integrasi QRIS Dinamis Multi-Tenant (GoPay Merchant, ShopeePay Partner, KlikQRIS)
 * untuk VSudoTrans.
 */

require_once __DIR__ . '/payment_gateway_direct.php';
require_once __DIR__ . '/invoice_service.php';
require_once __DIR__ . '/booking_service.php';
require_once __DIR__ . '/wa.php';

if (!defined('KLIKQRIS_BASE')) define('KLIKQRIS_BASE', 'https://klikqris.com/api/qrisv2');

function gateway_is_paid(array $data): bool
{
    return in_array(strtoupper((string) ($data['status'] ?? '')), ['PAID', 'SUCCESS', 'SETTLED'], true);
}

/** Pastikan 3 baris channel default (gopay, shopeepay, klikqris) tersedia untuk perusahaan ini. */
function gateway_ensure_company_channels(int $companyId): void
{
    $db = db();
    $defaults = [
        ['QRIS-GOPAY', 'QRIS GoPay Merchant', 'gopay', 1],
        ['QRIS-SPAY',  'QRIS ShopeePay Partner', 'shopeepay', 2],
        ['QRIS-KLIK',  'QRIS KlikQRIS', 'klikqris', 3],
    ];
    $ins = $db->prepare(
        "INSERT IGNORE INTO payment_channels
            (company_id, code, name, provider, mode, webhook_token, is_active, sort_order)
         VALUES (?, ?, ?, ?, 'production', ?, 0, ?)"
    );
    foreach ($defaults as [$code, $name, $prov, $sort]) {
        $tok = bin2hex(random_bytes(24));
        $ins->bind_param('issssi', $companyId, $code, $name, $prov, $tok, $sort);
        $ins->execute();
    }
    $ins->close();
}

function gateway_webhook_url(array $channel): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $token  = rawurlencode((string) ($channel['webhook_token'] ?? ''));
    $cid    = (int) ($channel['company_id'] ?? 0);
    return $scheme . '://' . $host . APP_URL_PATH . '/pages/finance/invoices/gateway_webhook.php?company_id=' . $cid . '&token=' . $token;
}

/** Ambil satu channel QRIS milik perusahaan berdasarkan provider ('gopay', 'shopeepay', 'klikqris'). */
function gateway_channel(int $companyId, string $provider = 'klikqris'): ?array
{
    $db = db();
    $s = $db->prepare('SELECT * FROM payment_channels WHERE company_id = ? AND provider = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
    $s->bind_param('is', $companyId, $provider);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?: null;
    $s->close();
    return $row;
}

/** Ambil channel QRIS pertama yang aktif & siap pakai untuk perusahaan ini. */
function gateway_active_channel(int $companyId, ?string $preferredProvider = null): ?array
{
    $db = db();
    if ($preferredProvider !== null && $preferredProvider !== '') {
        $ch = gateway_channel($companyId, $preferredProvider);
        if ($ch && !empty($ch['api_key']) && !empty($ch['merchant_id'])) {
            return $ch;
        }
    }
    $st = $db->prepare(
        "SELECT * FROM payment_channels
          WHERE company_id = ? AND is_active = 1
            AND api_key IS NOT NULL AND api_key <> ''
            AND merchant_id IS NOT NULL AND merchant_id <> ''
          ORDER BY sort_order ASC, id ASC LIMIT 1"
    );
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $row;
}

/** Ambil semua channel QRIS yang aktif & siap pakai milik perusahaan ini. */
function gateway_active_channels(int $companyId): array
{
    $st = db()->prepare(
        "SELECT * FROM payment_channels
          WHERE company_id = ? AND is_active = 1
            AND api_key IS NOT NULL AND api_key <> ''
            AND merchant_id IS NOT NULL AND merchant_id <> ''
          ORDER BY sort_order ASC, id ASC"
    );
    $st->bind_param('i', $companyId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function gateway_make_order_id(int $invoiceId): string
{
    return 'INV-' . $invoiceId . '-' . time() . '-' . bin2hex(random_bytes(3));
}

/** Biaya layanan QRIS dari config JSON channel (default 0). */
function gateway_fee(?array $channel, float $billAmount): float
{
    if ($billAmount <= 0 || !$channel) return 0.0;
    $cfg = !empty($channel['config']) ? json_decode((string) $channel['config'], true) : [];
    if (!is_array($cfg)) return 0.0;
    $type = (string) ($cfg['fee_type'] ?? 'none');
    $val  = (float) ($cfg['fee_value'] ?? 0);
    if ($val <= 0) return 0.0;
    if ($type === 'percent') return (float) round($billAmount * $val / 100);
    if ($type === 'flat')    return (float) round($val);
    return 0.0;
}

function gateway_charge_total(?array $channel, float $billAmount): float
{
    return $billAmount + gateway_fee($channel, $billAmount);
}

function gateway_post_klikqris(string $path, array $body, string $apiKey, string $idMerchant): array
{
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'id_merchant: ' . $idMerchant,
    ];
    $res = gq_http_post(KLIKQRIS_BASE . $path, json_encode($body), $headers, 20);
    if ($res['raw'] === false) return ['ok' => false, 'error' => 'cURL: ' . $res['error']];
    $json = json_decode((string) $res['raw'], true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Invalid JSON (' . $res['code'] . ')'];
    if ($res['code'] >= 400 || empty($json['status'])) {
        return ['ok' => false, 'error' => $json['message'] ?? ('HTTP ' . $res['code']), 'raw' => $json];
    }
    return ['ok' => true, 'data' => $json['data'] ?? [], 'raw' => $json];
}

function gateway_create(array $channel, string $orderId, float $amount, string $description = ''): array
{
    $provider = $channel['provider'] ?? '';
    if (in_array($provider, ['gopay', 'shopeepay'], true)) {
        return gq_create($channel, $orderId, $amount);
    }
    return gateway_post_klikqris(
        '/create',
        [
            'order_id'    => $orderId,
            'id_merchant' => (string) $channel['merchant_id'],
            'amount'      => (int) round($amount),
            'description' => $description !== '' ? $description : ('Pembayaran ' . $orderId),
        ],
        (string) $channel['api_key'],
        (string) $channel['merchant_id']
    );
}

function gateway_status(array $channel, string $orderId): array
{
    $provider = $channel['provider'] ?? '';
    if (in_array($provider, ['gopay', 'shopeepay'], true)) {
        $order = gateway_find_order($orderId);
        if (!$order) return ['ok' => false, 'error' => 'Order tidak ditemukan.'];
        return gq_check($channel, $order);
    }

    $url = KLIKQRIS_BASE . '/status/' . rawurlencode((string) $channel['merchant_id']) . '/' . rawurlencode($orderId);
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . (string) $channel['api_key'],
        'id_merchant: ' . (string) $channel['merchant_id'],
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $err = $raw === false ? 'stream_failed' : '';
        $code = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
    }
    if ($raw === false) return ['ok' => false, 'error' => 'cURL: ' . $err];
    $json = json_decode((string) $raw, true);
    if (!is_array($json) || $code >= 400 || empty($json['status'])) {
        return ['ok' => false, 'error' => $json['message'] ?? ('HTTP ' . $code), 'raw' => $json];
    }
    return ['ok' => true, 'data' => $json['data'] ?? [], 'raw' => $json];
}

function gateway_store_order(
    int $companyId,
    int $channelId,
    string $orderId,
    array $inv,
    float $amount,
    array $data,
    string $source = 'web',
    ?string $user = null
): void {
    $db = db();
    $invId = (int) $inv['id'];
    $bType = (string) $inv['booking_type'];
    $bId   = (int) $inv['booking_id'];
    $total = (float) ($data['total_amount'] ?? $amount);
    $qris  = (string) ($data['qris_url'] ?? '');
    $durl  = (string) ($data['direct_url'] ?? '');
    $exp   = !empty($data['expired_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['expired_at'])) : null;
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $s = $db->prepare(
        "INSERT INTO payment_gateway_orders
            (company_id, channel_id, order_id, invoice_id, booking_type, booking_id,
             amount, total_amount, status, qris_url, direct_url, source, payload, expired_at, created_user)
         VALUES (?,?,?,?,?,?,?,?, 'PENDING', ?,?,?,?,?,?)"
    );
    $s->bind_param(
        'iisisiddssssss',
        $companyId, $channelId, $orderId, $invId, $bType, $bId,
        $amount, $total, $qris, $durl, $source, $payload, $exp, $user
    );
    $s->execute();
    $s->close();
}

/** Pastikan order QRIS lokal PENDING masih cocok dengan QRIS Statis channel terkini (Tag 26–51). */
function gateway_order_matches_channel(array $order): bool
{
    $prov = (string) ($order['provider'] ?? '');
    if (!in_array($prov, ['gopay', 'shopeepay'], true)) {
        return true;
    }
    $chanStatic = (string) ($order['merchant_id'] ?? '');
    if ($chanStatic === '') {
        return false;
    }
    $payloadArr = !empty($order['payload']) ? json_decode((string) $order['payload'], true) : [];
    $qrisStr = is_array($payloadArr) ? (string) ($payloadArr['qris_string'] ?? '') : '';
    if ($qrisStr === '') {
        return false;
    }
    if (str_contains($qrisStr, '936009143123456789') && !str_contains($chanStatic, '936009143123456789')) {
        return false;
    }
    $fpOrd  = qris_merchant_fingerprint($qrisStr);
    $fpChan = qris_merchant_fingerprint($chanStatic);
    return $fpOrd !== '' && $fpChan !== '' && $fpOrd === $fpChan;
}

function gateway_pending_for_invoice(int $invoiceId, ?string $provider = null): ?array
{
    $db = db();
    $sql = "SELECT o.*, c.provider, c.name AS channel_name, c.api_key, c.merchant_id, c.mode, c.config
              FROM payment_gateway_orders o
              JOIN payment_channels c ON c.id = o.channel_id
             WHERE o.invoice_id = ? AND o.status = 'PENDING'";
    if ($provider !== null && $provider !== '') {
        $sql .= ' AND c.provider = ? ORDER BY o.id DESC LIMIT 5';
        $s = $db->prepare($sql);
        $s->bind_param('is', $invoiceId, $provider);
    } else {
        $sql .= ' ORDER BY o.id DESC LIMIT 5';
        $s = $db->prepare($sql);
        $s->bind_param('i', $invoiceId);
    }
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    foreach ($rows as $row) {
        if (!gateway_order_matches_channel($row)) {
            gateway_mark_expired((string) $row['order_id']);
            continue;
        }
        return $row;
    }
    return null;
}

function gateway_find_order(string $orderId): ?array
{
    $s = db()->prepare(
        "SELECT o.*, c.api_key, c.merchant_id, c.mode, c.name AS channel_name, c.provider, c.config
           FROM payment_gateway_orders o
           JOIN payment_channels c ON c.id = o.channel_id
          WHERE o.order_id = ? LIMIT 1"
    );
    $s->bind_param('s', $orderId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?: null;
    $s->close();
    return $row;
}

function gateway_mark_expired(string $orderId): void
{
    $s = db()->prepare("UPDATE payment_gateway_orders SET status = 'EXPIRED' WHERE order_id = ? AND status = 'PENDING'");
    $s->bind_param('s', $orderId);
    $s->execute();
    $s->close();
}

/** Pastikan ada baris di `payment_methods` untuk QRIS Online perusahaan ini. */
function gateway_ensure_payment_method(int $companyId, string $channelName = 'QRIS Online'): int
{
    $db = db();
    $st = $db->prepare("SELECT id FROM payment_methods WHERE company_id = ? AND code = 'QRIS-AUTO' LIMIT 1");
    $st->bind_param('i', $companyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        return (int) $row['id'];
    }
    $ins = $db->prepare(
        "INSERT INTO payment_methods (company_id, code, name, auto_verify, is_active, sort_order, created_user)
         VALUES (?, 'QRIS-AUTO', ?, 1, 1, 1, 'system-qris')"
    );
    $ins->bind_param('is', $companyId, $channelName);
    $ins->execute();
    $id = (int) $ins->insert_id;
    $ins->close();
    return $id;
}

/**
 * Tandai order PAID secara atomik:
 * 1. Kunci `payment_gateway_orders` FOR UPDATE (idempoten).
 * 2. Catat pembayaran pada `booking_cost_lines` (nominal sisa tagihan invoice, ditambah catatan kode unik).
 * 3. Sinkron `total_payment` pada tabel booking (`travel_ticket_bookings` / `rental_car_bookings`).
 * 4. Jika status booking masih `'draft'` dan invoice menjadi lunas, ubah status booking menjadi `'confirmed'`.
 * 5. Catat event `payment.verified` ke `wa_outbox` untuk ditarik/dikirim Hermes.
 */
function gateway_finalize(string $orderId, ?float $paidAmount = null): array
{
    $db = db();
    $db->begin_transaction();
    try {
        $s = $db->prepare('SELECT * FROM payment_gateway_orders WHERE order_id = ? FOR UPDATE');
        $s->bind_param('s', $orderId);
        $s->execute();
        $ord = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$ord) {
            $db->rollback();
            return ['ok' => false, 'error' => 'order_not_found'];
        }
        if ($ord['status'] === 'PAID') {
            $db->commit();
            return [
                'ok'           => true,
                'cost_line_id' => (int) $ord['cost_line_id'],
                'invoice_id'   => (int) $ord['invoice_id'],
            ];
        }

        $companyId = (int) $ord['company_id'];
        $invoiceId = (int) $ord['invoice_id'];
        $bType     = (string) $ord['booking_type'];
        $bId       = (int) $ord['booking_id'];

        $stInv = $db->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $stInv->bind_param('i', $invoiceId);
        $stInv->execute();
        $inv = $stInv->get_result()->fetch_assoc();
        $stInv->close();
        if (!$inv) {
            throw new RuntimeException('Invoice tidak ditemukan.');
        }

        $alreadyPaid = invoice_paid_total($invoiceId);
        $invTotal    = (float) $inv['total'];
        $remaining   = max(0.0, round($invTotal - $alreadyPaid, 2));

        $payloadArr = !empty($ord['payload']) ? json_decode((string) $ord['payload'], true) : [];
        if (!is_array($payloadArr)) {
            $payloadArr = [];
        }
        $orderBillAmount = isset($payloadArr['bill_amount']) && (float) $payloadArr['bill_amount'] > 0
            ? (float) $payloadArr['bill_amount']
            : (float) $ord['amount'];

        // Nominal yang dicatat ke pelunasan invoice adalah nominal pokok tagihan (tanpa fee/kode unik),
        // sedangkan nominal aktual QRIS (termasuk kode unik) dicatat di note.
        // Jangan pernah mencatat melebihi sisa tagihan ($remaining) jika sudah terbayar oleh sumber lain.
        $principalPaid = round(min($remaining, $orderBillAmount), 2);
        if ($principalPaid < 0) {
            $principalPaid = 0.0;
        }
        $actualQrisPaid = $paidAmount ?? (float) $ord['total_amount'];
        $uniqueCode = max(0, (int) round($actualQrisPaid - (float) $ord['amount']));

        $chosenPayType = trim((string) ($payloadArr['pay_type'] ?? ''));
        $allowedPayTypes = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)'];
        if ($chosenPayType !== '' && !in_array($chosenPayType, $allowedPayTypes, true)) {
            $chosenPayType = '';
        }

        $pmId = gateway_ensure_payment_method($companyId, 'QRIS Dinamis Otomatis');
        $desc = 'INV#' . $invoiceId;
        $qrisLabel = 'QRIS Otomatis #' . $orderId . ' (Dibayar: ' . rupiah($actualQrisPaid)
                   . ($uniqueCode > 0 ? ', kode unik +' . $uniqueCode : '') . ')';
        $note = ($chosenPayType !== '' ? ($chosenPayType . ' | ') : '') . $qrisLabel;
        $user = 'system-qris';

        $CostLineId = (int) ($ord['cost_line_id'] ?? 0);
        if ($CostLineId <= 0 && $inv['status'] !== 'cancelled' && $principalPaid > 0.001) {
            $ins = $db->prepare(
                "INSERT INTO booking_cost_lines
                    (company_id, booking_type, booking_id, cost_type, description, amount, payment_method_id, note, created_user)
                 VALUES (?, ?, ?, 'payment', ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('isisdiss', $companyId, $bType, $bId, $desc, $principalPaid, $pmId, $note, $user);
            $ins->execute();
            $CostLineId = (int) $ins->insert_id;
            $ins->close();

            $newPaid = round($alreadyPaid + $principalPaid, 2);
            $uInv = $db->prepare('UPDATE invoices SET payment_method_id = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
            $uInv->bind_param('isi', $pmId, $user, $invoiceId);
            $uInv->execute();
            $uInv->close();

            $tbl = booking_table($bType);
            $uBk = $db->prepare("UPDATE {$tbl} SET total_payment = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
            $uBk->bind_param('dsii', $newPaid, $user, $bId, $companyId);
            $uBk->execute();
            $uBk->close();

            // Bila booking masih draft dan sekarang lunas, otomatis konfirmasi booking!
            $curStatus = booking_current_status($bType, $bId);
            if ($curStatus === 'draft' && ($newPaid + 0.001 >= $invTotal)) {
                booking_insert_history($bType, $bId, $companyId, 'draft', 'confirmed', 'Otomatis dikonfirmasi setelah lunas QRIS #' . $orderId, $user);
            }
        }

        $uOrd = $db->prepare(
            "UPDATE payment_gateway_orders
                SET status = 'PAID', paid_at = NOW(), cost_line_id = NULLIF(?, 0)
              WHERE id = ?"
        );
        $ordDbId = (int) $ord['id'];
        $uOrd->bind_param('ii', $CostLineId, $ordDbId);
        $uOrd->execute();
        $uOrd->close();

        $db->commit();

        // Antrekan notifikasi WA untuk Hermes / WA Gateway di luar transaksi utama
        gateway_queue_wa_receipt($companyId, $bType, $bId, $inv, $orderId, $actualQrisPaid);

        return [
            'ok'           => true,
            'cost_line_id' => $CostLineId,
            'invoice_id'   => $invoiceId,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('gateway_finalize: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Antrekan pesan WA otomatis ke `wa_outbox` saat QRIS lunas. */
function gateway_queue_wa_receipt(int $companyId, string $bType, int $bId, array $inv, string $orderId, float $paidAmount): void
{
    try {
        $db = db();
        $tbl = booking_table($bType);
        $st = $db->prepare(
            "SELECT b.document_number, b.date, b.time, p.name AS passenger_name, p.phone_number AS passenger_phone,
                    c.name AS company_name, c.code AS company_code
               FROM {$tbl} b
               JOIN companies c ON c.id = b.company_id
               LEFT JOIN passengers p ON p.id = b.passenger_id
              WHERE b.id = ? LIMIT 1"
        );
        $st->bind_param('i', $bId);
        $st->execute();
        $bk = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$bk) return;

        $phone = wa_phone_normalize((string) ($bk['passenger_phone'] ?? ''));
        if ($phone === '') return;

        $docNo = (string) $bk['document_number'];
        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $tiketUrl = $scheme . '://' . $host . APP_URL_PATH . '/tiket.php?no=' . rawurlencode($docNo);

        $msg = "*Pembayaran Berhasil Diterima* ✅\n"
             . "Perusahaan: {$bk['company_name']}\n"
             . "No. Tiket: *{$docNo}*\n"
             . "No. Invoice: {$inv['invoice_no']}\n"
             . "Nominal: *" . rupiah($paidAmount) . "*\n"
             . "Status: *LUNAS & TERKONFIRMASI*\n\n"
             . "Unduh E-Tiket Anda:\n{$tiketUrl}";

        $eventId = 'pay-' . $orderId;
        $payload = json_encode([
            'company_id'      => $companyId,
            'company_code'    => $bk['company_code'],
            'company_name'    => $bk['company_name'],
            'document_number' => $docNo,
            'invoice_no'      => $inv['invoice_no'],
            'order_id'        => $orderId,
            'amount_paid'     => (int) round($paidAmount),
            'amount_formatted'=> rupiah($paidAmount),
            'tiket_url'       => $tiketUrl,
            'passenger_name'  => $bk['passenger_name'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ins = $db->prepare(
            "INSERT IGNORE INTO wa_outbox
                (company_id, booking_type, booking_id, event_id, event_type, phone, message, payload, status, next_try_at)
             VALUES (?, ?, ?, ?, 'payment.verified', ?, ?, ?, 'pending', NOW())"
        );
        $ins->bind_param('isissss', $companyId, $bType, $bId, $eventId, $phone, $msg, $payload);
        $ins->execute();
        $ins->close();
    } catch (Throwable $e) {
        error_log('gateway_queue_wa_receipt: ' . $e->getMessage());
    }
}
