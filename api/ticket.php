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
 *   GET /api/ticket.php?no=TRV-8K4M9P2X&company=MTM
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/invoice_service.php';
require_once __DIR__ . '/../includes/payment_gateway.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Cache-Control: no-store');

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

$rawPassengers = booking_passengers_list($type, $bId, (int) $row['passenger_id']);
$passengers = [];
foreach ($rawPassengers as $idx => $p) {
    $passengers[] = [
        'no'           => $idx + 1,
        'name'         => $p['name'],
        'is_primary'   => (int) ($p['is_primary'] ?? 0) === 1,
        'phone_masked' => ticket_mask_phone($p['phone_number'] ?? ''),
    ];
}

$scheme  = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . APP_URL_PATH;
$compCode = (string) $row['company_code'];

$hasQris = $rem > 0 && !$isCancelled && gateway_active_channel($cid) !== null;
$routeLabel = trim(($row['city_from'] ?? '') . ' - ' . ($row['city_to'] ?? ''), ' -') ?: '—';

ticket_api_reply(200, [
    'ok'   => true,
    'data' => [
        'valid'            => !$isCancelled,
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
            'pickup_address'       => $row['pickup_address'],
            'delivery_address'     => $row['delivery_address'],
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
            'tiket_url'      => $baseUrl . '/tiket.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode),
            'print_pdf_url'  => $baseUrl . '/tiket.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode) . '&print=1',
            'verifikasi_url' => $baseUrl . '/verifikasi.php?no=' . rawurlencode($row['document_number']) . '&company=' . rawurlencode($compCode),
            'pay_qris_url'   => $hasQris ? ($baseUrl . '/pages/finance/invoices/gateway_pay.php?no=' . rawurlencode($row['document_number'])) : null,
        ],
    ],
]);

