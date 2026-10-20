<?php
/**
 * Data operasional dashboard: booking hari ini/minggu ini, driver bertugas,
 * piutang jatuh tempo. Di-scope company aktif lewat company_scope().
 */

/** Booking (travel+rental) pada rentang tanggal [from, to] — jumlah & nilai. */
function dash_bookings(string $from, string $to): array
{
    require_once __DIR__ . '/../reports/_query.php';
    $src = report_source(['from' => $from, 'to' => $to, 'booking_type' => 'all']);
    $rows = report_run(
        'SELECT COUNT(*) AS n, COALESCE(SUM(x.total_price),0) AS total FROM ' . $src['sql'],
        $src['types'],
        $src['params']
    );
    $r = $rows[0] ?? ['n' => 0, 'total' => 0];
    return ['n' => (int) $r['n'], 'total' => (float) $r['total']];
}

/** Driver yang sedang bertugas (assignment aktif: ended_at IS NULL). */
function dash_active_drivers(): array
{
    [$where, $types, $params] = company_scope('a.company_id');
    $sql = "SELECT a.id, a.booking_type, a.booking_id, a.started_at,
                   e.code, e.name AS driver_name,
                   v.vehicle_number,
                   COALESCE(t.document_number, r.document_number) AS document_number,
                   b.name AS branch_name
            FROM trip_assignments a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN vehicles v ON v.id = a.vehicle_id
            LEFT JOIN travel_ticket_bookings t
                   ON t.id = a.booking_id AND a.booking_type = 'travel'
            LEFT JOIN rental_car_bookings r
                   ON r.id = a.booking_id AND a.booking_type = 'rental'
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE a.ended_at IS NULL AND {$where}
            ORDER BY a.started_at DESC";
    $st = db()->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Piutang jatuh tempo: invoice issued, belum lunas, tanggal booking sudah lewat
 * lebih dari $grace_days hari ("jatuh tempo" = tanggal keberangkatan/sewa lewat).
 * Sisa = total - total_payment, diambil dari tabel booking (sumber kebenaran).
 */
function dash_overdue_receivables(int $grace_days = 0, int $limit = 8): array
{
    [$where, $types, $params] = company_scope('i.company_id');

    $sql = "SELECT * FROM (
                SELECT 'travel' AS booking_type, i.booking_id,
                       i.document_number, i.invoice_no, b.date AS booking_date,
                       i.total,
                       COALESCE(b.total_payment, 0) AS total_payment,
                       i.total - COALESCE(b.total_payment, 0) AS sisa,
                       DATEDIFF(CURDATE(), b.date) AS umur_hari
                FROM invoices i
                JOIN travel_ticket_bookings b ON b.id = i.booking_id
                WHERE i.status = 'issued' AND i.booking_type = 'travel'
                UNION ALL
                SELECT 'rental' AS booking_type, i.booking_id,
                       i.document_number, i.invoice_no, b.date AS booking_date,
                       i.total,
                       COALESCE(b.total_payment, 0) AS total_payment,
                       i.total - COALESCE(b.total_payment, 0) AS sisa,
                       DATEDIFF(CURDATE(), b.date) AS umur_hari
                FROM invoices i
                JOIN rental_car_bookings b ON b.id = i.booking_id
                WHERE i.status = 'issued' AND i.booking_type = 'rental'
            ) x
            WHERE x.sisa > 0 AND x.umur_hari > ?
              AND {$where}
            ORDER BY x.sisa DESC
            LIMIT ?";
    $types2  = 'i' . $types . 'i';
    $params2 = array_merge([$grace_days], $params, [$limit]);

    $st = db()->prepare($sql);
    $st->bind_param($types2, ...$params2);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $total = 0.0;
    foreach ($rows as $r) $total += (float) $r['sisa'];
    return ['rows' => $rows, 'total' => $total];
}
