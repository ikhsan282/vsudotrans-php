<?php
/**
 * QRIS Langsung (GoPay Merchant & ShopeePay Partner) — Multi-Tenant VSudoTrans.
 * Mengubah QRIS statis (EMVCo Tag 01 = '11') menjadi QRIS dinamis (Tag 01 = '12',
 * Tag 54 = nominal tepat termasuk kode unik 3 digit, Tag 63 = CRC16-CCITT baru),
 * lalu memverifikasi pembayaran langsung ke mutasi merchant GoPay/ShopeePay.
 */

if (!defined('GQ_UNIQUE_MIN')) define('GQ_UNIQUE_MIN', 100);
if (!defined('GQ_UNIQUE_MAX')) define('GQ_UNIQUE_MAX', 500);
if (!defined('GQ_EXPIRY_SECONDS')) define('GQ_EXPIRY_SECONDS', 900); // 15 menit

/** HTTP POST helper (mendukung cURL dan fallback stream_context). */
function gq_http_post(string $url, string $body, array $headers, int $timeout = 15): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['raw' => $raw, 'code' => $code, 'error' => $err];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
    return ['raw' => $raw, 'code' => $code, 'error' => $raw === false ? 'stream_failed' : ''];
}

/** CRC16-CCITT (0xFFFF, polinomial 0x1021) sesuai spesifikasi EMVCo tag 63. */
function qris_crc16(string $str): string
{
    $crc = 0xFFFF;
    $len = strlen($str);
    for ($c = 0; $c < $len; $c++) {
        $crc ^= ord($str[$c]) << 8;
        for ($i = 0; $i < 8; $i++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
        }
    }
    return strtoupper(str_pad(dechex($crc & 0xFFFF), 4, '0', STR_PAD_LEFT));
}

/** Urai string EMVCo TLV menjadi daftar [[tag, value], ...] dengan urutan asli terjaga. */
function qris_parse_tlv(string $qris): ?array
{
    $out = [];
    $i = 0;
    $n = strlen($qris);
    while ($i < $n) {
        if ($i + 4 > $n) return null;
        $tag = substr($qris, $i, 2);
        $lenStr = substr($qris, $i + 2, 2);
        if (!ctype_digit($tag) || !ctype_digit($lenStr)) return null;
        $len = (int) $lenStr;
        $val = substr($qris, $i + 4, $len);
        if (strlen($val) !== $len) return null;
        $out[] = [$tag, $val];
        $i += 4 + $len;
    }
    return $out ?: null;
}

/** Rakit kembali daftar TLV dan hitung ulang CRC tag 63 di ujung. */
function qris_build_tlv(array $tlvs): string
{
    $body = '';
    foreach ($tlvs as [$tag, $val]) {
        if ($tag === '63') continue;
        $body .= $tag . sprintf('%02d', strlen($val)) . $val;
    }
    $body .= '6304';
    return $body . qris_crc16($body);
}

/**
 * Sisipkan nominal (tag 54) dan ubah Point of Initiation (tag 01) menjadi dinamis ('12').
 * Menghapus whitespace baris agar hasil salin-tempel tetap terbaca rapi.
 */
function qris_make_dynamic(string $staticQris, int $amount): ?string
{
    $staticQris = preg_replace('/[\r\n\t]+/', '', trim($staticQris)) ?? '';
    if ($amount <= 0 || $staticQris === '') return null;
    $tlvs = qris_parse_tlv($staticQris);
    if (!$tlvs || $tlvs[0][0] !== '00') return null;

    $amtVal = (string) $amount;
    $out = [];
    $has01 = false;
    $inserted54 = false;

    foreach ($tlvs as [$tag, $val]) {
        if ($tag === '63') continue;
        if ($tag === '01') {
            $out[] = ['01', '12'];
            $has01 = true;
            continue;
        }
        if ($tag === '54') {
            $out[] = ['54', $amtVal];
            $inserted54 = true;
            continue;
        }
        if (!$inserted54 && in_array($tag, ['55', '56', '57', '58', '59', '60', '61', '62'], true)) {
            $out[] = ['54', $amtVal];
            $inserted54 = true;
        }
        $out[] = [$tag, $val];
    }

    if (!$has01) {
        array_splice($out, 1, 0, [['01', '12']]);
    }
    if (!$inserted54) {
        $out[] = ['54', $amtVal];
    }
    return qris_build_tlv($out);
}

/** Validasi kelengkapan EMVCo, checksum CRC Tag 63, dan tolak data contoh dummy pada string QRIS Statis. */
function qris_validate_static(string $staticQris): ?string
{
    $clean = preg_replace('/[\r\n\t]+/', '', trim($staticQris)) ?? '';
    if ($clean === '') {
        return 'String QRIS Statis belum diisi.';
    }
    $tlvs = qris_parse_tlv($clean);
    if (!$tlvs || $tlvs[0][0] !== '00') {
        return 'String QRIS Statis tidak valid (bukan format EMVCo/QRIS standar).';
    }
    $hasMerchantTag = false;
    $crcVal = null;
    $bodyBeforeCrc = '';
    foreach ($tlvs as [$tag, $val]) {
        $num = (int) $tag;
        if ($num >= 26 && $num <= 51 && $val !== '') {
            $hasMerchantTag = true;
        }
        if ($tag === '63') {
            $crcVal = strtoupper($val);
            continue;
        }
        $bodyBeforeCrc .= $tag . sprintf('%02d', strlen($val)) . $val;
    }
    if (!$hasMerchantTag) {
        return 'String QRIS Statis tidak memiliki data merchant (Tag 26–51).';
    }
    if ($crcVal !== null && strlen($crcVal) === 4 && $crcVal !== qris_crc16($bodyBeforeCrc . '6304')) {
        return 'Checksum CRC16 (Tag 63) pada String QRIS Statis tidak cocok — pastikan tidak terpotong saat disalin.';
    }
    if (str_contains($clean, '936009143123456789')) {
        return 'String QRIS Statis masih berisi data contoh/dummy (936009143123456789). Tempel string hasil scan QRIS toko asli.';
    }
    return null;
}

/** Ekstrak sidik jari tag informasi merchant (Tag 26–51) untuk mendeteksi order stale bila QRIS statis diganti. */
function qris_merchant_fingerprint(string $qris): string
{
    $clean = preg_replace('/[\r\n\t]+/', '', trim($qris)) ?? '';
    $tlvs = $clean !== '' ? qris_parse_tlv($clean) : null;
    if (!$tlvs) {
        return '';
    }
    $parts = [];
    foreach ($tlvs as [$tag, $val]) {
        $num = (int) $tag;
        if ($num >= 26 && $num <= 51) {
            $parts[] = $tag . ':' . $val;
        }
    }
    return implode('|', $parts);
}

/** URL gambar QR untuk string EMVCo dengan quiet zone 4 modul agar mudah dipindai kamera. */
function gq_qr_url(string $qrisString): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&qzone=4&data=' . rawurlencode($qrisString);
}

/**
 * Pilih kode unik (GQ_UNIQUE_MIN..GQ_UNIQUE_MAX) yang membuat total_amount unik
 * di antara order PENDING milik channel tersebut yang belum kedaluwarsa.
 */
function gq_unique_touch(float $baseAmount, string $orderId, int $channelId = 0): int
{
    $span = GQ_UNIQUE_MAX - GQ_UNIQUE_MIN + 1;
    $seed = GQ_UNIQUE_MIN + (abs(crc32($orderId)) % $span);
    $used = [];
    try {
        if ($channelId > 0) {
            $st = db()->prepare(
                "SELECT total_amount FROM payment_gateway_orders
                  WHERE channel_id = ? AND status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW())"
            );
            $st->bind_param('i', $channelId);
            $st->execute();
            $res = $st->get_result();
        } else {
            $res = db()->query(
                "SELECT total_amount FROM payment_gateway_orders
                  WHERE status = 'PENDING' AND (expired_at IS NULL OR expired_at > NOW())"
            );
        }
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $used[(int) round((float) $r['total_amount'])] = true;
            }
        }
    } catch (Throwable $e) {
        return $seed;
    }
    $base = (int) round($baseAmount);
    for ($i = 0; $i < $span; $i++) {
        $code = GQ_UNIQUE_MIN + (($seed - GQ_UNIQUE_MIN + $i) % $span);
        if (empty($used[$base + $code])) return $code;
    }
    return $seed;
}

/**
 * Buat order QRIS dinamis secara lokal dari QRIS statis di `channel['merchant_id']`.
 * Format balikan seragam dengan `gateway_create()`.
 */
function gq_create(array $channel, string $orderId, float $amount): array
{
    $static = trim((string) ($channel['merchant_id'] ?? ''));
    if ($static === '') {
        return ['ok' => false, 'error' => 'String QRIS Statis belum diisi pada pengaturan channel.'];
    }
    $channelId = (int) ($channel['id'] ?? 0);
    $total = (int) round($amount) + gq_unique_touch($amount, $orderId, $channelId);
    $dyn = qris_make_dynamic($static, $total);
    if ($dyn === null) {
        return ['ok' => false, 'error' => 'Isian String QRIS Statis tidak valid (bukan format EMVCo/QRIS).'];
    }
    return [
        'ok'   => true,
        'data' => [
            'order_id'     => $orderId,
            'amount'       => (int) round($amount),
            'total_amount' => $total,
            'qris_string'  => $dyn,
            'qris_url'     => gq_qr_url($dyn),
            'direct_url'   => '',
            'expired_at'   => date('Y-m-d H:i:s', time() + GQ_EXPIRY_SECONDS),
        ],
    ];
}

/** Pecah daftar "Key: Value" (satu per baris, JSON object map, atau JSON array) menjadi header cURL bersih. */
function gq_split(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    if ($raw[0] === '{' || $raw[0] === '[') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            $out = [];
            foreach ($arr as $k => $v) {
                if (is_int($k)) {
                    $line = trim((string) $v);
                    if ($line !== '') $out[] = $line;
                } else {
                    $out[] = "$k: $v";
                }
            }
            return $out;
        }
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, ':') !== false) $out[] = $line;
    }
    return $out;
}

/** HTTP GET/POST helper (cURL). */
function gq_http_request(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['raw' => $raw, 'code' => $code, 'error' => $err];
    }

    $ctxOpts = [
        'method'        => $method,
        'header'        => implode("\r\n", $headers) . "\r\n",
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ];
    if ($body !== null) {
        $ctxOpts['content'] = $body;
    }
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $ctxOpts]));
    $code = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
    return ['raw' => $raw, 'code' => $code, 'error' => $raw === false ? 'stream_failed' : ''];
}

/**
 * Klaim ID transaksi merchant untuk suatu order agar 1 transaksi tidak melunasi 2 order
 * yang kebetulan bernominal sama. Menggunakan kolom `signature` di `payment_gateway_orders`.
 */
function gq_claim(int $channelId, string $orderId, string $txId): bool
{
    if ($txId === '') return true;
    $db = db();
    $st = $db->prepare(
        'SELECT order_id FROM payment_gateway_orders WHERE channel_id = ? AND signature = ? LIMIT 1'
    );
    $st->bind_param('is', $channelId, $txId);
    $st->execute();
    $existing = $st->get_result()->fetch_assoc();
    $st->close();
    if ($existing && $existing['order_id'] !== $orderId) {
        return false;
    }
    $u = $db->prepare('UPDATE payment_gateway_orders SET signature = ? WHERE order_id = ?');
    $u->bind_param('ss', $txId, $orderId);
    $u->execute();
    $u->close();
    return true;
}

/**
 * Cek mutasi GoPay/ShopeePay untuk satu order: cocokkan nominal tepat (`total_amount`)
 * pada transaksi yang terjadi setelah order dibuat.
 */
function gq_check(array $channel, array $order): array
{
    if ($order['status'] === 'PAID') {
        return ['ok' => true, 'data' => ['status' => 'PAID', 'total_amount' => (float) $order['total_amount']]];
    }
    if (!empty($order['expired_at']) && strtotime($order['expired_at']) <= time()) {
        return ['ok' => true, 'data' => ['status' => 'EXPIRED']];
    }
    $provider = $channel['provider'] ?? '';
    if ($provider === 'gopay') {
        return gq_check_gopay($channel, $order);
    }
    if ($provider === 'shopeepay') {
        return gq_check_shopeepay($channel, $order);
    }
    return ['ok' => false, 'error' => 'Provider tidak didukung: ' . $provider];
}

function gq_check_gopay(array $channel, array $order): array
{
    require_once __DIR__ . '/gopay_auth.php';
    $headers = gq_split((string) ($channel['api_key'] ?? ''));
    if (!$headers) {
        return ['ok' => false, 'error' => 'Akun GoPay Merchant belum dihubungkan.'];
    }
    $cfg = json_decode((string) ($channel['config'] ?? ''), true);
    $cfg = is_array($cfg) ? $cfg : [];
    $merchantId = trim((string) ($cfg['merchant_ids'] ?? ($cfg['merchant_id'] ?? '')));

    $since = (!empty($order['created_at']) ? strtotime((string) $order['created_at']) : (time() - 3600)) - 60;
    $q = [
        'from'          => 0,
        'size'          => 50,
        'statuses'      => 'SETTLEMENT,CAPTURE',
        'payment_types' => 'QRIS,GOPAY',
        'start_time'    => gmdate('Y-m-d\TH:i:s\Z', $since),
        'end_time'      => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
    ];
    if ($merchantId !== '') {
        $q['merchant_ids'] = $merchantId;
    }

    $base = getenv('GOPAY_API_URL') ?: 'https://api.gojekapi.com';
    $url  = $base . '/merchant-analytics/v2/merchants/transactions?' . http_build_query($q);

    $res = gq_http_request('GET', $url, $headers, null, 20);
    if (in_array((int) $res['code'], [401, 403], true) && !empty($channel['id'])) {
        $ref = gopay_refresh_channel((int) $channel['id']);
        if ($ref['ok']) {
            $res = gq_http_request('GET', $url, gq_split($ref['api_key']), null, 20);
        }
    }
    if ($res['raw'] === false) {
        return ['ok' => false, 'error' => 'GoPay cURL: ' . $res['error']];
    }
    $json = json_decode((string) $res['raw'], true);
    $transactions = $json['transactions'] ?? ($json['data'] ?? ($json['hits'] ?? null));
    if ($res['code'] >= 400 || !is_array($transactions) || ($json['success'] ?? true) === false) {
        return ['ok' => false, 'error' => 'Respons transaksi GoPay tidak dikenali (HTTP ' . $res['code'] . ').'];
    }

    $wantAmount = (int) round((float) $order['total_amount']);
    foreach ($transactions as $tx) {
        $amountRaw = $tx['gross_amount'] ?? ($tx['real_gross_amount'] ?? ($tx['amount'] ?? null));
        if ($amountRaw === null) continue;
        // gross_amount dalam SEN (1000100 = Rp10.001)
        $txAmt = (int) round(((float) $amountRaw) / 100);
        if ($txAmt !== $wantAmount && (int) round((float) $amountRaw) !== $wantAmount) {
            continue;
        }
        $st = strtoupper((string) ($tx['transaction_status'] ?? ($tx['status'] ?? '')));
        if ($st !== '' && !in_array($st, ['SETTLEMENT', 'CAPTURE', 'SUCCESS', 'PAID'], true)) {
            continue;
        }
        $txTime = strtotime((string) ($tx['transaction_time'] ?? ($tx['create_time'] ?? ($tx['time'] ?? ''))));
        if ($txTime !== false && $txTime < $since) {
            continue;
        }
        $txId = (string) ($tx['transaction_id'] ?? ($tx['order_sn'] ?? ($tx['id'] ?? ($tx['reference_id'] ?? ''))));
        if (!gq_claim((int) ($channel['id'] ?? 0), (string) $order['order_id'], $txId)) {
            continue;
        }
        return [
            'ok'   => true,
            'data' => [
                'status'       => 'PAID',
                'total_amount' => (float) $order['total_amount'],
                'tx_id'        => $txId,
            ],
        ];
    }
    return ['ok' => true, 'data' => ['status' => 'PENDING']];
}

function gq_check_shopeepay(array $channel, array $order): array
{
    $token = trim((string) ($channel['api_key'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Token Authorization ShopeePay belum diisi.'];
    }
    $since = (!empty($order['created_at']) ? strtotime((string) $order['created_at']) : (time() - 3600)) - 60;
    $url   = getenv('SHOPEEPAY_API_URL') ?: 'https://shopeepay.shopee.co.id/merchant/v1/partner-web/get-transaction-list';

    $body = json_encode([
        'data' => [
            'metadata' => [
                'token'    => $token,
                'language' => 'id',
                'timezone' => 'Asia/Jakarta',
            ],
            'pageSize' => 50,
            'filter'   => [
                'startTime'   => $since,
                'endTime'     => time() + 60,
                'serviceList' => [1, 3],
            ],
            'sorter'        => ['field' => 'createTime', 'order' => 'descend'],
            'next_position' => '',
        ],
    ]);

    $headers = [
        'Content-Type: application/json',
        'Origin: https://partner.shopee.co.id',
        'Referer: https://partner.shopee.co.id/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'X-Timestamp-Ms: ' . (string) (time() * 1000),
    ];

    $res = gq_http_request('POST', $url, $headers, $body, 20);
    if ($res['raw'] === false) return ['ok' => false, 'error' => 'ShopeePay cURL: ' . $res['error']];
    $json = json_decode((string) $res['raw'], true);
    $code = $json['code'] ?? null;
    if ($res['code'] >= 400 || !is_array($json) || ($code !== null && $code !== 0 && $code !== '0')) {
        $err = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : ('HTTP ' . $res['code']);
        return ['ok' => false, 'error' => 'Respons ShopeePay tidak valid: ' . $err];
    }

    $transactions = $json['data']['list']
        ?? ($json['data']['transactionList']
        ?? ($json['data']['transactions']
        ?? ($json['data']['transaction_list'] ?? null)));
    $total = (int) ($json['data']['total'] ?? 0);
    if ($transactions === null && is_array($json['data'] ?? null) && $total === 0) {
        $transactions = [];
    }
    if (!is_array($transactions)) {
        return ['ok' => false, 'error' => 'Respons transaksi ShopeePay tidak dikenali.'];
    }

    $wantAmount = (int) round((float) $order['total_amount']);
    foreach ($transactions as $tx) {
        $txAmt = (int) round((float) str_replace(['.', ','], '', (string) ($tx['amount'] ?? '0')));
        if ($txAmt !== $wantAmount) continue;
        $txStatus = $tx['status'] ?? null;
        if ($txStatus !== null && (int) $txStatus !== 3) continue;
        if (isset($tx['transactionType']) && (int) $tx['transactionType'] !== 1) continue;
        $txTime = (int) ($tx['createTime'] ?? ($tx['create_time'] ?? 0));
        if ($txTime > 0 && $txTime < $since) continue;
        $txId = (string) ($tx['transaction_id'] ?? ($tx['order_sn'] ?? ($tx['reference_id'] ?? ($tx['id'] ?? ''))));
        if (!gq_claim((int) ($channel['id'] ?? 0), (string) $order['order_id'], $txId)) continue;
        return [
            'ok'   => true,
            'data' => [
                'status'       => 'PAID',
                'total_amount' => (float) $order['total_amount'],
                'tx_id'        => $txId,
            ],
        ];
    }
    return ['ok' => true, 'data' => ['status' => 'PENDING']];
}

