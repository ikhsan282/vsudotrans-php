<?php
/**
 * Public Ticket Check API untuk Website Landing Page WordPress Multi-Tenant:
 * - Maura Trans Mandiri (mauratransmandiri.com -> ?company=MTM)
 * - Nugi Trans Mandiri (nugitransmandiri.com -> ?company=NTM)
 * - Gibran Trans Mandiri (gibrantransmandiri.com -> ?company=GTM)
 * - Trisula Trans Mandiri (trisulatransmandiri.com -> ?company=TTM)
 * - LIZ Wisata Alam Indah (lizwisataalamindah.com -> ?company=LIZ)
 *
 * Contoh pemanggilan:
 *   GET /api/ticket.php?no=100120261113&company=MTM
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/invoice_service.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

/** Ekstrak origin `scheme://host[:port]` yang valid dari URL/Origin header. */
function ticket_normalize_origin(string $rawUrl): ?string
{
    $p = @parse_url(trim($rawUrl));
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return null;
    }
    $scheme = strtolower((string) $p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower((string) $p['host']);
    $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
    return $scheme . '://' . $host . $port;
}

/** Terapkan CORS allowlist dari domain perusahaan (`companies.website`), `APP_URL`, dan env. */
function ticket_apply_cors(): void
{
    $origin = ticket_normalize_origin((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === null) {
        return;
    }
    $allowed = [];
    $appOrig = ticket_normalize_origin(APP_URL);
    if ($appOrig !== null) {
        $allowed[$appOrig] = true;
    }
    $extra = trim((string) (getenv('VSUDO_CORS_ALLOWED_ORIGINS') ?: ''));
    if ($extra !== '') {
        foreach (explode(',', $extra) as $item) {
            $norm = ticket_normalize_origin($item);
            if ($norm !== null) {
                $allowed[$norm] = true;
            }
        }
    }
    try {
        $res = db()->query("SELECT website FROM companies WHERE website IS NOT NULL AND website <> ''");
        if ($res) {
            while ($cw = $res->fetch_assoc()) {
                $norm = ticket_normalize_origin((string) $cw['website']);
                if ($norm === null) {
                    continue;
                }
                $allowed[$norm] = true;
                $hp = parse_url($norm);
                if (!empty($hp['host'])) {
                    $h = (string) $hp['host'];
                    $altHost = str_starts_with($h, 'www.') ? substr($h, 4) : ('www.' . $h);
                    $altPort = isset($hp['port']) ? ':' . (int) $hp['port'] : '';
                    $allowed[$hp['scheme'] . '://' . $altHost . $altPort] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // fallback ke allowlist env/APP_URL bila DB sementara gagal
    }

    $isLocalDev = (bool) preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d{1,5})?$#', $origin);
    if ($isLocalDev || isset($allowed[$origin])) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ticket_apply_cors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ticket_api_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Rate limit per IP untuk mencegah scraping/enumerasi nomor tiket publik. */
function ticket_api_rate_limit(int $maxReq = 30, int $windowSec = 60): void
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' && PHP_SAPI === 'cli') {
        return;
    }
    if ($ip === '') {
        $ip = 'unknown';
    }
    $envMax = (int) (getenv('VSUDO_TICKET_RATE_LIMIT') ?: 0);
    if ($envMax > 0) {
        $maxReq = $envMax;
    }

    $file = sys_get_temp_dir() . '/vsudo_ticket_rl_' . md5($ip) . '.json';
    $fp   = @fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    $now = time();
    $blockedRetry = 0;
    if (@flock($fp, LOCK_EX)) {
        $raw  = stream_get_contents($fp);
        $hits = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($hits)) {
            $hits = [];
        }
        $cutoff = $now - $windowSec;
        $hits   = array_values(array_filter($hits, static fn($ts): bool => is_int($ts) && $ts > $cutoff));
        if (count($hits) >= $maxReq) {
            $oldest       = (int) ($hits[0] ?? $now);
            $blockedRetry = max(1, ($oldest + $windowSec) - $now);
        } else {
            $hits[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($hits));
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    if ($blockedRetry > 0) {
        header('Retry-After: ' . $blockedRetry);
        ticket_api_reply(429, [
            'ok'    => false,
            'error' => [
                'code'    => 'RATE_LIMITED',
                'message' => 'Terlalu banyak permintaan cek tiket. Silakan coba lagi sebentar lagi.',
            ],
        ]);
    }
}

ticket_api_rate_limit();

function ticket_mask_phone(?string $phone): string
{
    $p = trim((string) $phone);
    if ($p === '') return '—';
    $len = strlen($p);
    if ($len <= 6) return substr($p, 0, 2) . '****';
    return substr($p, 0, 4) . str_repeat('*', max(3, $len - 7)) . substr($p, -3);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    ticket_api_reply(405, ['ok' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Hanya metode GET yang diizinkan.']]);
}

$no = strtoupper(trim((string) ($_GET['no'] ?? ($_GET['ticket'] ?? ''))));
$companyFilter = strtoupper(trim((string) ($_GET['company'] ?? '')));

if ($no === '' || !booking_doc_valid($no)) {
    ticket_api_reply(400, [
        'ok'    => false,
        'error' => [
            'code'    => 'INVALID_TICKET_FORMAT',
            'message' => 'Format nomor tiket tidak valid. Contoh: 10000720261110.',
        ],
    ]);
}

$type  = booking_resolve_type_by_doc($no, $companyFilter);
$table = $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
$extraCols = $type === 'rental' ? 'b.start_date, b.end_date, b.trip,' : 'NULL AS start_date, NULL AS end_date, 0 AS trip,';

$sql = "SELECT b.id, b.company_id, b.document_number, b.date, b.time, {$extraCols}
               b.pickup_address, b.delivery_address, b.total_price, b.total_payment,
               b.created_user, b.passenger_id,
               c.code AS company_code, c.name AS company_name, c.address AS company_address,
               c.city AS company_city, c.phone_number AS company_phone, c.email AS company_email,
               c.website AS company_website, c.logo AS company_logo, c.stamp AS company_stamp,
               i.id AS invoice_id, i.invoice_no, i.status AS invoice_status, i.total AS invoice_total,
               cp1.name AS city_from, cp2.name AS city_to,
               d1.name AS district_from, d2.name AS district_to,
               COALESCE((SELECT h.to_status FROM booking_status_history h
                          WHERE h.booking_type = '{$type}' AND h.booking_id = b.id
                          ORDER BY h.id DESC LIMIT 1), 'draft') AS booking_status,
               COALESCE((SELECT SUM(bcl.amount) FROM booking_cost_lines bcl
                          WHERE bcl.cost_type = 'payment' AND bcl.description = CONCAT('INV#', i.id)), 0) AS invoice_paid
          FROM {$table} b
          JOIN companies c ON c.id = b.company_id
          LEFT JOIN invoices i ON i.document_number = b.document_number
          LEFT JOIN cities cp1 ON cp1.id = b.pickup_point_city_id
          LEFT JOIN cities cp2 ON cp2.id = b.delivery_point_city_id
          LEFT JOIN districts d1 ON d1.id = b.pickup_point_district_id
          LEFT JOIN districts d2 ON d2.id = b.delivery_point_district_id
         WHERE b.document_number = ?";

if ($companyFilter !== '') {
    $sql .= ' AND c.code = ? LIMIT 1';
    $st = db()->prepare($sql);
    $st->bind_param('ss', $no, $companyFilter);
} else {
    $sql .= ' LIMIT 1';
    $st = db()->prepare($sql);
    $st->bind_param('s', $no);
}
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
    ticket_api_reply(404, [
        'ok'    => false,
        'error' => [
            'code'    => 'TICKET_NOT_FOUND',
            'message' => 'Tiket tidak ditemukan. Pastikan nomor tiket dan perusahaan sudah sesuai.',
        ],
    ]);
}

$cid      = (int) $row['company_id'];
$bId      = (int) $row['id'];
$invId    = (int) ($row['invoice_id'] ?? 0);
$bStatus  = (string) ($row['booking_status'] ?: 'draft');
$invTotal = (float) ($row['invoice_total'] ?? $row['total_price']);
$paid     = $invId > 0 ? (float) ($row['invoice_paid'] ?? 0) : (float) $row['total_payment'];
$rem      = max(0.0, round($invTotal - $paid, 2));

$isCancelled = strtolower((string) ($row['invoice_status'] ?? '')) === 'cancelled' || $bStatus === 'cancelled';
$payStatus   = $isCancelled ? 'cancelled' : ($paid <= 0 ? 'unpaid' : ($rem <= 0.01 ? 'paid' : 'partial'));

$compCode      = (string) $row['company_code'];
$rawPassengers = booking_passengers_list($type, $bId, (int) $row['passenger_id']);
$phone4Input   = preg_replace('/\D+/', '', (string) ($_GET['phone4'] ?? ''));
$sigInput      = trim((string) ($_GET['sig'] ?? ''));
$expectedSig   = ticket_pii_signature((string) $row['document_number'], $compCode);

$piiVerified = false;
if ($sigInput !== '' && hash_equals($expectedSig, $sigInput)) {
    $piiVerified = true;
} elseif (strlen($phone4Input) === 4) {
    foreach ($rawPassengers as $p) {
        $digits = preg_replace('/\D+/', '', (string) ($p['phone_number'] ?? ''));
        if ($digits !== '' && str_ends_with($digits, $phone4Input)) {
            $piiVerified = true;
            break;
        }
    }
}

$passengers = [];
foreach ($rawPassengers as $idx => $p) {
    $passengers[] = [
        'no'           => $idx + 1,
        'name'         => $piiVerified ? (string) ($p['name'] ?? '') : ticket_mask_name($p['name'] ?? ''),
        'is_primary'   => (int) ($p['is_primary'] ?? 0) === 1,
        'phone_masked' => ticket_mask_phone($p['phone_number'] ?? ''),
    ];
}

$baseUrl    = rtrim(APP_URL, '/');
$sigQuery   = $piiVerified ? ('&sig=' . rawurlencode($expectedSig)) : '';
$hasQris    = $rem > 0 && !$isCancelled && gateway_active_channel($cid) !== null;
$routeLabel = trim(($row['city_from'] ?? '') . ' - ' . ($row['city_to'] ?? ''), ' -') ?: '—';

ticket_api_reply(200, [
    'ok'   => true,
    'data' => [
        'valid'            => !$isCancelled,
        'pii_verified'     => $piiVerified,
        'document_number'  => $row['document_number'],
        'invoice_no'       => $row['invoice_no'],
        'booking_type'     => $type,
        'booking_status'   => $bStatus,
        'payment_status'   => $payStatus,
        'company'          => [
            'id'        => $cid,
            'code'      => $compCode,
            'name'      => $row['company_name'],
            'phone'     => $row['company_phone'],
            'email'     => $row['company_email'],
            'website'   => $row['company_website'],
            'address'   => $row['company_address'],
            'city'      => $row['company_city'],
            'logo_url'  => !empty($row['company_logo']) ? (azure_blob_url((string) $row['company_logo']) ?: null) : null,
            'stamp_url' => !empty($row['company_stamp']) ? (azure_blob_url((string) $row['company_stamp']) ?: null) : null,
        ],
        'route'            => [
            'origin_city'          => $row['city_from'],
            'origin_district'      => $row['district_from'],
            'destination_city'     => $row['city_to'],
            'destination_district' => $row['district_to'],
            'label'                => $routeLabel,
            'pickup_address'       => $piiVerified ? $row['pickup_address'] : null,
            'delivery_address'     => $piiVerified ? $row['delivery_address'] : null,
        ],
        'schedule'         => [
            'date'           => $row['date'],
            'date_formatted' => tanggal_indo((string) $row['date']),
            'time'           => substr((string) $row['time'], 0, 5),
            'start_date'     => $row['start_date'],
            'end_date'       => $row['end_date'],
        ],
        'pricing'          => [
            'total'     => ['raw' => (int) round($invTotal), 'formatted' => rupiah($invTotal)],
            'paid'      => ['raw' => (int) round($paid), 'formatted' => rupiah($paid)],
            'remaining' => ['raw' => (int) round($rem), 'formatted' => rupiah($rem)],
        ],
        'passengers_count' => count($passengers),
        'passengers'       => $passengers,
        'links'            => [
            'tiket_url'      => $baseUrl . '/tiket.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode) . $sigQuery,
            'print_pdf_url'  => $baseUrl . '/tiket.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode) . '&print=1' . $sigQuery,
            'verifikasi_url' => $baseUrl . '/verifikasi.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode),
            'pay_qris_url'   => $hasQris ? ($baseUrl . '/pages/finance/invoices/gateway_pay.php?no=' . rawurlencode($row['document_number'])) : null,
        ],
    ],
]);

