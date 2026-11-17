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

/**
 * Driver yang sedang bertugas (assignment aktif: ended_at IS NULL) dan booking belum selesai/batal.
 * Diurutkan dengan status 'on_trip' terlebih dahulu agar fokus pada perjalanan yang aktif berjalan.
 */
function dash_active_drivers(): array
{
    [$where, $types, $params] = company_scope('a.company_id');
    $sql = "SELECT a.id, a.booking_type, a.booking_id, a.started_at,
                   e.code, e.name AS driver_name, COALESCE(e.phone_number, '') AS driver_phone,
                   v.vehicle_number,
                   COALESCE(t.document_number, r.document_number) AS document_number,
                   b.name AS branch_name,
                   COALESCE(
                       (SELECT h.to_status FROM booking_status_history h
                         WHERE h.booking_type = a.booking_type AND h.booking_id = a.booking_id
                         ORDER BY h.id DESC LIMIT 1),
                       'draft'
                   ) AS booking_status
            FROM trip_assignments a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN vehicles v ON v.id = a.vehicle_id
            LEFT JOIN travel_ticket_bookings t
                   ON t.id = a.booking_id AND a.booking_type = 'travel'
            LEFT JOIN rental_car_bookings r
                   ON r.id = a.booking_id AND a.booking_type = 'rental'
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE a.ended_at IS NULL
              AND COALESCE(t.id, r.id) IS NOT NULL
              AND {$where}
            HAVING booking_status NOT IN ('done', 'cancelled')
            ORDER BY (booking_status = 'on_trip') DESC, a.started_at DESC";
    $st = db()->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Piutang jatuh tempo: invoice belum lunas (issued atau partial), tanggal booking sudah lewat
 * lebih dari $grace_days hari. Scope perusahaan diterapkan langsung di tiap cabang UNION agar
 * memakai index ix_invoices_company_issued, dan total KPI dihitung dari seluruh baris (bukan hanya LIMIT).
 *
 * @return array{rows: array<int, array<string, mixed>>, total: float, count: int}
 */
function dash_overdue_receivables(int $grace_days = 0, int $limit = 8): array
{
    [$iWhere, $iTypes, $iParams] = company_scope('i.company_id');

    $unionSql = "SELECT i.id AS invoice_id, 'travel' AS booking_type, i.company_id, i.booking_id,
                        i.document_number, i.invoice_no, b.date AS booking_date,
                        i.total,
                        COALESCE(b.total_payment, 0) AS total_payment,
                        i.total - COALESCE(b.total_payment, 0) AS sisa,
                        DATEDIFF(CURDATE(), b.date) AS umur_hari
                 FROM invoices i
                 JOIN travel_ticket_bookings b ON b.id = i.booking_id
                 WHERE i.status IN ('issued', 'partial') AND i.booking_type = 'travel' AND {$iWhere}
                 UNION ALL
                 SELECT i.id AS invoice_id, 'rental' AS booking_type, i.company_id, i.booking_id,
                        i.document_number, i.invoice_no, b.date AS booking_date,
                        i.total,
                        COALESCE(b.total_payment, 0) AS total_payment,
                        i.total - COALESCE(b.total_payment, 0) AS sisa,
                        DATEDIFF(CURDATE(), b.date) AS umur_hari
                 FROM invoices i
                 JOIN rental_car_bookings b ON b.id = i.booking_id
                 WHERE i.status IN ('issued', 'partial') AND i.booking_type = 'rental' AND {$iWhere}";

    $unionTypes  = $iTypes . $iTypes;
    $unionParams = array_merge($iParams, $iParams);
    $db          = db();

    $sqlAgg = "SELECT COUNT(*) AS cnt, COALESCE(SUM(x.sisa), 0) AS total_sisa
               FROM ({$unionSql}) x
               WHERE x.sisa > 0 AND x.umur_hari > ?";
    $stAgg = $db->prepare($sqlAgg);
    $stAgg->bind_param($unionTypes . 'i', ...array_merge($unionParams, [$grace_days]));
    $stAgg->execute();
    $agg = $stAgg->get_result()->fetch_assoc() ?: ['cnt' => 0, 'total_sisa' => 0];
    $stAgg->close();

    $sqlRows = "SELECT * FROM ({$unionSql}) x
                WHERE x.sisa > 0 AND x.umur_hari > ?
                ORDER BY x.sisa DESC
                LIMIT ?";
    $st = $db->prepare($sqlRows);
    $st->bind_param($unionTypes . 'ii', ...array_merge($unionParams, [$grace_days, $limit]));
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return [
        'rows'  => $rows,
        'total' => (float) ($agg['total_sisa'] ?? 0),
        'count' => (int) ($agg['cnt'] ?? 0),
    ];
}

/**
 * Dokumen & legalitas yang mendekati jatuh tempo (H-30) atau sudah kedaluwarsa.
 * Mencakup: STNK Kendaraan, KIR Kendaraan, Pajak Kendaraan, dan SIM Driver/Karyawan.
 * Hitungan expired & warning dihitung sebelum slice $limit agar angka badge header selalu akurat.
 *
 * @return array{items: array, total_expired: int, total_warning: int, total: int, total_all: int}
 */
function dash_expiring_documents(int $days = 30, int $limit = 10, bool $include_vehicles = true, bool $include_employees = true): array
{
    $cutoff   = date('Y-m-d', strtotime("+{$days} days"));
    $today_ts = strtotime(date('Y-m-d'));
    $items    = [];

    if ($include_vehicles) {
        [$v_where, $v_types, $v_params] = company_scope('v.company_id');
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
    }

    if ($include_employees) {
        [$e_where, $e_types, $e_params] = company_scope('e.company_id');
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
    }

    usort($items, static fn(array $a, array $b): int => ($a['days_left'] <=> $b['days_left']) ?: strcmp($a['due_date'], $b['due_date']));

    $expired_count = 0;
    $warning_count = 0;
    foreach ($items as $r) {
        if ((int) $r['days_left'] < 0) {
            $expired_count++;
        } else {
            $warning_count++;
        }
    }

    $total_all = count($items);
    $sliced    = array_slice($items, 0, $limit);

    return [
        'items'         => $sliced,
        'total_expired' => $expired_count,
        'total_warning' => $warning_count,
        'total'         => count($sliced),
        'total_all'     => $total_all,
    ];
}

/**
 * Hitung jumlah booking (Travel + Rental) per 5 status operasional dalam 1x query UNION ALL.
 *
 * @return array<string, int>
 */
function dash_status_counts(bool $include_travel = true, bool $include_rental = true): array
{
    $counts = ['draft' => 0, 'scheduled' => 0, 'on_trip' => 0, 'done' => 0, 'cancelled' => 0];
    if (!$include_travel && !$include_rental) {
        return $counts;
    }

    [$w, $t, $p] = company_scope('b.company_id');
    $parts  = [];
    $types  = '';
    $params = [];

    $mk = static fn(string $tbl, string $bt): string => "SELECT COALESCE(
            (SELECT h.to_status FROM booking_status_history h
              WHERE h.booking_type = '{$bt}' AND h.booking_id = b.id
              ORDER BY h.id DESC LIMIT 1),
            'draft'
        ) AS st
        FROM {$tbl} b
        WHERE {$w}";

    if ($include_travel) {
        $parts[] = $mk('travel_ticket_bookings', 'travel');
        $types  .= $t;
        $params  = array_merge($params, $p);
    }
    if ($include_rental) {
        $parts[] = $mk('rental_car_bookings', 'rental');
        $types  .= $t;
        $params  = array_merge($params, $p);
    }

    $st = db()->prepare('SELECT u.st, COUNT(*) AS cnt FROM (' . implode(' UNION ALL ', $parts) . ') u GROUP BY u.st');
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $k = (string) ($r['st'] ?? 'draft');
        if (isset($counts[$k])) {
            $counts[$k] += (int) $r['cnt'];
        }
    }
    $st->close();
    return $counts;
}

/**
 * Daftar pemesanan aktif (draft, scheduled, on_trip) yang perlu dipantau/berangkat terdekat.
 *
 * @return array<int, array<string, mixed>>
 */
function dash_upcoming_bookings(int $limit = 6, bool $include_travel = true, bool $include_rental = true): array
{
    if (!$include_travel && !$include_rental) {
        return [];
    }

    [$w, $t, $p] = company_scope('b.company_id');
    $parts  = [];
    $types  = '';
    $params = [];

    if ($include_travel) {
        $parts[] = "SELECT 'travel' AS booking_type, b.id, b.document_number, b.date AS trip_date, b.date AS end_date, b.time,
                           px.name AS passenger_name, COALESCE(px.phone_number, '') AS passenger_phone,
                           v.vehicle_number,
                           COALESCE(pc.name, '-') AS origin_city, COALESCE(dc.name, '-') AS dest_city,
                           COALESCE(
                               (SELECT h.to_status FROM booking_status_history h
                                 WHERE h.booking_type = 'travel' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1),
                               'draft'
                           ) AS st
                    FROM travel_ticket_bookings b
                    JOIN passengers px ON px.id = b.passenger_id
                    LEFT JOIN vehicles v ON v.id = b.vehicle_id
                    LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                    LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                    WHERE {$w}";
        $types  .= $t;
        $params  = array_merge($params, $p);
    }
    if ($include_rental) {
        $parts[] = "SELECT 'rental' AS booking_type, b.id, b.document_number, b.start_date AS trip_date, b.end_date, b.time,
                           px.name AS passenger_name, COALESCE(px.phone_number, '') AS passenger_phone,
                           v.vehicle_number,
                           COALESCE(pc.name, '-') AS origin_city, COALESCE(dc.name, '-') AS dest_city,
                           COALESCE(
                               (SELECT h.to_status FROM booking_status_history h
                                 WHERE h.booking_type = 'rental' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1),
                               'draft'
                           ) AS st
                    FROM rental_car_bookings b
                    JOIN passengers px ON px.id = b.passenger_id
                    LEFT JOIN vehicles v ON v.id = b.vehicle_id
                    LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                    LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                    WHERE {$w}";
        $types  .= $t;
        $params  = array_merge($params, $p);
    }

    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ") x
            WHERE x.st IN ('draft', 'scheduled', 'on_trip')
            ORDER BY (x.st = 'on_trip') DESC, (x.st = 'draft') DESC, x.trip_date ASC, x.time ASC, x.id DESC
            LIMIT ?";
    $st = db()->prepare($sql);
    $st->bind_param($types . 'i', ...array_merge($params, [$limit]));
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if ($rows) {
        require_once __DIR__ . '/../../includes/transport_helpers.php';
        $tIds = [];
        $rIds = [];
        foreach ($rows as $row) {
            if ($row['booking_type'] === 'travel') {
                $tIds[] = (int) $row['id'];
            } else {
                $rIds[] = (int) $row['id'];
            }
        }
        $tDrv = $tIds ? booking_driver_summaries('travel', $tIds) : [];
        $rDrv = $rIds ? booking_driver_summaries('rental', $rIds) : [];
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['driver_summary'] = $row['booking_type'] === 'travel'
                ? ($tDrv[$id] ?? null)
                : ($rDrv[$id] ?? null);
        }
        unset($row);
    }

    return $rows;
}

/**
 * Hitung ringkasan master operasional dalam 1x round-trip query:
 * karyawan aktif, armada aktif, dan cabang.
 *
 * @return array{employees:int, vehicles:int, branches:int}
 */
function dash_master_counts(): array
{
    [$w, $t, $p] = company_scope('company_id');
    $sql = "SELECT
                (SELECT COUNT(*) FROM employees WHERE status = 'aktif' AND {$w}) AS employees,
                (SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND {$w}) AS vehicles,
                (SELECT COUNT(*) FROM branches WHERE {$w}) AS branches";
    $st = db()->prepare($sql);
    $types  = $t . $t . $t;
    $params = array_merge($p, $p, $p);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    return [
        'employees' => (int) ($row['employees'] ?? 0),
        'vehicles'  => (int) ($row['vehicles'] ?? 0),
        'branches'  => (int) ($row['branches'] ?? 0),
    ];
}

/**
 * Ringkasan saldo & arus kas Buku Kas (`cash_transactions`): total saldo berjalan sampai hari ini
 * serta total kas masuk & kas keluar bulan berjalan.
 *
 * @return array{balance:float, month_in:float, month_out:float}
 */
function dash_cash_summary(): array
{
    [$tw, $tt, $tp] = company_scope('company_id');
    $monthStart = date('Y-m-01');
    $today      = date('Y-m-d');
    $sql = "SELECT
                COALESCE(SUM(CASE WHEN date <= ? THEN (CASE WHEN direction = 'in' THEN amount WHEN direction = 'out' THEN -amount ELSE 0 END) ELSE 0 END), 0) AS balance,
                COALESCE(SUM(CASE WHEN direction = 'in'  AND date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS month_in,
                COALESCE(SUM(CASE WHEN direction = 'out' AND date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS month_out
            FROM cash_transactions
            WHERE {$tw}";
    $st = db()->prepare($sql);
    $types  = 'sssss' . $tt;
    $params = array_merge([$today, $monthStart, $today, $monthStart, $today], $tp);
    $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    return [
        'balance'   => (float) ($row['balance'] ?? 0),
        'month_in'  => (float) ($row['month_in'] ?? 0),
        'month_out' => (float) ($row['month_out'] ?? 0),
    ];
}
