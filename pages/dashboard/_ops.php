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

/**
 * Hitung Booking Hari Ini & Minggu Ini sekaligus dalam 1x query UNION ALL.
 *
 * @return array{today: array{n: int, total: float}, week: array{n: int, total: float}}
 */
function dash_bookings_summary(string $today, string $week_start, string $week_end): array
{
    require_once __DIR__ . '/../reports/_query.php';
    $from = min($today, $week_start);
    $to   = max($today, $week_end);
    $src  = report_source(['from' => $from, 'to' => $to, 'booking_type' => 'all']);
    $sql  = 'SELECT
                COALESCE(SUM(CASE WHEN x.date = ? THEN 1 ELSE 0 END), 0) AS today_n,
                COALESCE(SUM(CASE WHEN x.date = ? THEN x.total_price ELSE 0 END), 0) AS today_total,
                COALESCE(SUM(CASE WHEN x.date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS week_n,
                COALESCE(SUM(CASE WHEN x.date BETWEEN ? AND ? THEN x.total_price ELSE 0 END), 0) AS week_total
             FROM ' . $src['sql'];
    $rows = report_run(
        $sql,
        'ssssss' . $src['types'],
        array_merge([$today, $today, $week_start, $week_end, $week_start, $week_end], $src['params'])
    );
    $r = $rows[0] ?? [];
    return [
        'today' => ['n' => (int) ($r['today_n'] ?? 0), 'total' => (float) ($r['today_total'] ?? 0)],
        'week'  => ['n' => (int) ($r['week_n'] ?? 0),  'total' => (float) ($r['week_total'] ?? 0)],
    ];
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

/**
 * Dokumen & legalitas yang mendekati jatuh tempo (H-30) atau sudah kedaluwarsa.
 * Mencakup: STNK Kendaraan, KIR Kendaraan, Pajak Kendaraan, dan SIM Driver/Karyawan.
 *
 * @return array{items: array, total_expired: int, total_warning: int, total: int}
 */
function dash_expiring_documents(int $days = 30, int $limit = 10): array
{
    [$v_where, $v_types, $v_params] = company_scope('v.company_id');
    [$e_where, $e_types, $e_params] = company_scope('e.company_id');

    $cutoff = date('Y-m-d', strtotime("+{$days} days"));
    $today_ts = strtotime(date('Y-m-d'));
    $items = [];

    // 1x Pass untuk Vehicles (STNK, KIR, Pajak Kendaraan sekaligus)
    $sql_v = "SELECT v.id, v.vehicle_number, v.stnk_due, v.kir_due, v.tax_due
              FROM vehicles v
              WHERE v.is_active = 1
                AND (
                    (v.stnk_due IS NOT NULL AND v.stnk_due <= ?)
                    OR (v.kir_due IS NOT NULL AND v.kir_due <= ?)
                    OR (v.tax_due IS NOT NULL AND v.tax_due <= ?)
                )
                AND {$v_where}";
    $st_v = db()->prepare($sql_v);
    $st_v->bind_param('sss' . $v_types, ...array_merge([$cutoff, $cutoff, $cutoff], $v_params));
    $st_v->execute();
    $v_rows = $st_v->get_result()->fetch_all(MYSQLI_ASSOC);
    $st_v->close();

    $doc_cols = [
        'stnk_due' => 'STNK Kendaraan',
        'kir_due'  => 'KIR Kendaraan',
        'tax_due'  => 'Pajak Kendaraan',
    ];
    foreach ($v_rows as $vr) {
        foreach ($doc_cols as $col => $label) {
            if (!empty($vr[$col]) && $vr[$col] <= $cutoff) {
                $days_left = (int) round((strtotime($vr[$col]) - $today_ts) / 86400);
                $items[] = [
                    'ref_type'   => 'vehicle',
                    'ref_id'     => (int) $vr['id'],
                    'title'      => $vr['vehicle_number'],
                    'doc_type'   => $label,
                    'due_date'   => $vr[$col],
                    'days_left'  => $days_left,
                    'url_prefix' => 'pages/vehicles/detail.php?id=',
                ];
            }
        }
    }

    // 1x Pass untuk SIM Driver / Karyawan
    $sql_e = "SELECT e.id, e.name, e.sim_due
              FROM employees e
              WHERE e.status = 'aktif'
                AND e.sim_due IS NOT NULL
                AND e.sim_due <= ?
                AND {$e_where}";
    $st_e = db()->prepare($sql_e);
    $st_e->bind_param('s' . $e_types, ...array_merge([$cutoff], $e_params));
    $st_e->execute();
    $e_rows = $st_e->get_result()->fetch_all(MYSQLI_ASSOC);
    $st_e->close();

    foreach ($e_rows as $er) {
        $days_left = (int) round((strtotime($er['sim_due']) - $today_ts) / 86400);
        $items[] = [
            'ref_type'   => 'employee',
            'ref_id'     => (int) $er['id'],
            'title'      => $er['name'],
            'doc_type'   => 'SIM Driver',
            'due_date'   => $er['sim_due'],
            'days_left'  => $days_left,
            'url_prefix' => 'pages/employees/detail.php?id=',
        ];
    }

    usort($items, static fn(array $a, array $b): int => ($a['days_left'] <=> $b['days_left']) ?: strcmp($a['due_date'], $b['due_date']));
    $items = array_slice($items, 0, $limit);

    $expired_count = 0;
    $warning_count = 0;
    foreach ($items as $r) {
        if ((int) $r['days_left'] < 0) {
            $expired_count++;
        } else {
            $warning_count++;
        }
    }

    return [
        'items' => $items,
        'total_expired' => $expired_count,
        'total_warning' => $warning_count,
        'total' => count($items),
    ];
}
