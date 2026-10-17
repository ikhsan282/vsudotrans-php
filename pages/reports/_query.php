<?php
/**
 * Laporan Trip/Booking — SATU sumber query untuk halaman & export.
 *
 * Filter: from, to, company_id, booking_type ('travel'|'rental'|'all').
 * Semua query di-scope company_scope('company_id'); booking_type masuk whitelist
 * (tidak pernah diinterpolasi ke SQL).
 *
 * Catatan dimensi "per Rute": skema TIDAK punya kolom rute_id di tabel booking
 * (travel_ticket_bookings hanya menyimpan pickup/delivery city & district).
 * Kolom yang benar-benar tersedia dan akurat adalah wilayah asal-tujuan
 * (city asal -> city tujuan). Itu yang dipakai; bukan mengarang join ke `rutes`.
 */

require_once __DIR__ . '/../../includes/booking_service.php';

/** Normalisasi filter dari $_GET/param. Tanggal ngawur → default bulan berjalan. */
function report_filter(?array $in = null): array
{
    $in = $in ?? $_GET;
    $type = (string) ($in['booking_type'] ?? 'all');
    if (!in_array($type, ['travel', 'rental'], true)) {
        $type = 'all'; // ponytail: nilai ngawur → 'all', bukan error 500
    }
    $d = static fn(string $k, string $def): string =>
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) ? $in[$k] : $def;
    return [
        'from'         => $d('from', date('Y-m-01')),
        'to'           => $d('to', date('Y-m-t')),
        'company_id'   => active_company_id(),
        'booking_type' => $type,
    ];
}

/**
 * Derived table bernama x: UNION per tipe terpilih (kolom seragam) + WHERE filter
 * tanggal & tenant. Dipakai sebagai FROM oleh semua query agregat, jadi struktur
 * UNION-nya hanya ada di satu tempat.
 *
 * @return array{sql:string, types:string, params:array}
 */
function report_source(array $f): array
{
    $cols = static fn(string $table, string $type) => "SELECT '{$type}' AS booking_type, b.id, b.company_id,
            b.branch_id, b.date, b.time, b.total_price, b.total_payment,
            (SELECT p.name FROM passengers p WHERE p.id = b.passenger_id) AS passenger_name,
            b.vehicle_id, b.pickup_point_city_id AS origin_city_id,
            b.delivery_point_city_id AS dest_city_id
        FROM {$table} b";
    $parts = [];
    if ($f['booking_type'] !== 'rental') {
        $parts[] = $cols('travel_ticket_bookings', 'travel');
    }
    if ($f['booking_type'] !== 'travel') {
        $parts[] = $cols('rental_car_bookings', 'rental');
    }
    // company_scope() mengembalikan '' untuk Super Admin → klausa dilewati.
    // Scope ditegakkan di kolom company_id tabel asal (bukan hasil UNION).
    [$w, $wt, $wp] = company_scope('company_id');
    $sql = '(' . implode(' UNION ALL ', $parts) . ') x WHERE '
        . ($w !== '' ? $w . ' AND ' : '') . 'x.date BETWEEN ? AND ?';
    return ['sql' => $sql, 'types' => $wt . 'ss', 'params' => array_merge($wp, [$f['from'], $f['to']])];
}

/** Jalankan prepared query; kembalikan semua baris assoc. */
function report_run(string $sql, string $types, array $params): array
{
    $st = db()->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Ekspresi agregat yang sama untuk semua dimensi. */
function report_agg(): string
{
    return "COUNT(*) AS total_bookings,
        COALESCE(SUM(x.total_price),0) AS total_price,
        COALESCE(SUM(x.total_payment),0) AS total_payment,
        COALESCE(SUM(x.total_price - x.total_payment),0) AS total_receivable";
}

/** Ringkasan: total booking, nilai, terbayar, sisa piutang. */
function report_summary(array $f): array
{
    $s = report_source($f);
    $rows = report_run('SELECT ' . report_agg() . ' FROM ' . $s['sql'], $s['types'], $s['params']);
    return $rows[0] ?? [];
}

/** Per periode (GROUP BY tanggal). */
function report_by_period(array $f): array
{
    $s = report_source($f);
    return report_run("SELECT DATE_FORMAT(x.date, '%Y-%m-%d') AS period, " . report_agg() . "
        FROM {$s['sql']} GROUP BY period ORDER BY period ASC", $s['types'], $s['params']);
}

/**
 * Derived table siap di-join: `(SELECT * FROM (<union+filter>) y) x`.
 * Join ke tabel dimensi harus di level terluar, jadi sumber dibungkus sekali lagi.
 */
function report_joined(array $f): array
{
    $s = report_source($f);
    return ['sql' => '(SELECT * FROM ' . $s['sql'] . ') x', 'types' => $s['types'], 'params' => $s['params']];
}

/**
 * Per wilayah asal→tujuan. Skema tidak punya rute_id di booking, jadi dimensi
 * "rute" direpresentasikan sebagai pasangan kota asal/tujuan (lihat catatan atas).
 */
function report_by_route(array $f): array
{
    $j = report_joined($f);
    $sql = "SELECT x.origin_city_id, x.dest_city_id,
            COALESCE(oc.name, '-') AS origin_name, COALESCE(dc.name, '-') AS dest_name, "
        . report_agg() . "
        FROM {$j['sql']}
        LEFT JOIN cities oc ON oc.id = x.origin_city_id
        LEFT JOIN cities dc ON dc.id = x.dest_city_id
        GROUP BY x.origin_city_id, x.dest_city_id, origin_name, dest_name
        ORDER BY total_bookings DESC, origin_name ASC";
    $rows = report_run($sql, $j['types'], $j['params']);
    foreach ($rows as &$r) {
        $r['route_label'] = $r['origin_name'] . ' - ' . $r['dest_name'];
    }
    return $rows;
}

/** Per kendaraan (booking tanpa kendaraan → label '-'). */
function report_by_vehicle(array $f): array
{
    $j = report_joined($f);
    return report_run("SELECT COALESCE(v.vehicle_number, '-') AS vehicle_number,
        COALESCE(v.vehicle_color, '-') AS vehicle_color, " . report_agg() . "
        FROM {$j['sql']}
        LEFT JOIN vehicles v ON v.id = x.vehicle_id
        GROUP BY vehicle_number, vehicle_color
        ORDER BY total_bookings DESC, vehicle_number ASC", $j['types'], $j['params']);
}

/** Per driver: LEFT JOIN penugasan ke karyawan; booking tanpa driver = '-'.
 *  Dedup: satu booking bisa punya >1 penugasan (driver diganti / riwayat) — ambil yang TERBARU
 *  saja agar COUNT()/SUM() tidak fan-out dan total antar-dimensi tetap sama. */
function report_by_driver(array $f): array
{
    $j = report_joined($f);
    return report_run("SELECT COALESCE(e.name, '-') AS driver_name, " . report_agg() . "
        FROM {$j['sql']}
        LEFT JOIN trip_assignments ta
               ON ta.id = (SELECT MAX(ta2.id) FROM trip_assignments ta2
                            WHERE ta2.booking_type = x.booking_type
                              AND ta2.booking_id = x.id
                              AND ta2.company_id = x.company_id)
        LEFT JOIN employees e ON e.id = ta.employee_id
        GROUP BY driver_name
        ORDER BY total_bookings DESC, driver_name ASC", $j['types'], $j['params']);
}

/** Empat dimensi sekaligus — dipakai halaman & export agar angka identik. */
function report_all(array $f): array
{
    return [
        'summary' => report_summary($f),
        'period'  => report_by_period($f),
        'route'   => report_by_route($f),
        'vehicle' => report_by_vehicle($f),
        'driver'  => report_by_driver($f),
    ];
}

/** Total kolom untuk baris <tfoot>. */
function report_totals(array $rows): array
{
    $t = ['total_bookings' => 0, 'total_price' => 0.0, 'total_payment' => 0.0, 'total_receivable' => 0.0];
    foreach ($rows as $r) {
        $t['total_bookings']   += (int) $r['total_bookings'];
        $t['total_price']      += (float) $r['total_price'];
        $t['total_payment']    += (float) $r['total_payment'];
        $t['total_receivable'] += (float) $r['total_receivable'];
    }
    return $t;
}
