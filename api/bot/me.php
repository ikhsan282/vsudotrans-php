<?php
/**
 * GET /api/bot/me.php
 * Identitas pemilik nomor WhatsApp pada perusahaan yang dituju (`?company=MTM`).
 * Pelanggan baru yang belum pernah memesan akan memiliki `registered = false`,
 * namun tetap bisa melihat rute/jadwal (`/api/bot/routes.php`) dan memesan tiket
 * langsung (`/api/bot/booking_create.php`).
 */
require __DIR__ . '/_bootstrap.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$cid     = (int) $company['id'];

$paxIds = array_map('intval', array_column($actor['passengers'], 'id'));
$activeBookingsCount = 0;
$unpaidInvoicesCount = 0;

if ($paxIds) {
    $in = implode(',', array_fill(0, count($paxIds), '?'));
    $types = 'i' . str_repeat('i', count($paxIds));
    foreach (['travel_ticket_bookings' => 'travel', 'rental_car_bookings' => 'rental'] as $tbl => $bType) {
        $st = $db->prepare(
            "SELECT b.id, i.id AS invoice_id, i.total, i.status AS inv_status, b.total_payment
               FROM {$tbl} b
               LEFT JOIN invoices i ON i.document_number = b.document_number
              WHERE b.company_id = ? AND b.passenger_id IN ({$in})"
        );
        $st->bind_param($types, $cid, ...$paxIds);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (($r['inv_status'] ?? 'issued') !== 'cancelled') {
                $activeBookingsCount++;
                if ((float) $r['total_payment'] + 0.01 < (float) ($r['total'] ?? 0)) {
                    $unpaidInvoicesCount++;
                }
            }
        }
        $st->close();
    }
}

$primaryName = $actor['passengers'][0]['name']
    ?? ($actor['staff']['name'] ?? ($actor['employee']['name'] ?? null));

bot_reply(200, [
    'company' => [
        'id'      => $cid,
        'code'    => $company['code'],
        'name'    => $company['name'],
        'phone'   => $company['phone_number'],
        'email'   => $company['email'],
        'website' => $company['website'],
        'city'    => $company['city'],
    ],
    'phone'                 => $ctx['phone'],
    'registered'            => $actor['registered'],
    'name'                  => $primaryName,
    'is_passenger'          => !empty($actor['passengers']),
    'passengers'            => array_map(static fn(array $p): array => [
        'passenger_id' => (int) $p['id'],
        'code'         => $p['code'],
        'name'         => $p['name'],
        'phone'        => $p['phone_number'],
    ], $actor['passengers']),
    'active_bookings_count' => $activeBookingsCount,
    'unpaid_invoices_count' => $unpaidInvoicesCount,
    'is_staff'              => $actor['is_staff'],
    'staff'                 => $actor['staff'],
    'is_driver'             => $actor['is_driver'],
    'driver'                => $actor['employee'],
]);

