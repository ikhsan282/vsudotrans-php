<?php
/**
 * Laporan Penjadwalan Driver — SATU sumber query untuk halaman & export.
 *
 * Sumber: `trip_assignments` (penugasan = 1 baris, termasuk driver pengganti),
 * JOIN employees (driver), vehicles, dan booking (travel/rental) untuk nomor
 * dokumen + tanggal. Filter from/to via report_filter() dari _query.php.
 *
 * Semua query di-scope company_scope(): klausa tenant SELALU ditulis di kolom
 * tabel sendiri (`ta.company_id`, bukan kolom hasil JOIN) supaya tenant lain
 * tidak bisa lolos lewat baris kendaraan/driver bersama.
 *
 * Catatan skema: `trip_assignments` tidak punya kolom status/dokumen maupun
 * tanggal akhir trip. Status diturunkan dari `ended_at` (NULL = masih jalan),
 * dokumen & tanggal dari tabel booking-nya (lihat dispatch_doc_expr()).
 */

require_once __DIR__ . '/_query.php';

/** Subquery nomor dokumen dari tabel booking sesuai jenis penugasan. */
function dispatch_doc_expr(string $alias = 'ta'): string
{
    return "CASE {$alias}.booking_type
        WHEN 'travel' THEN (SELECT b.document_number FROM travel_ticket_bookings b
                             WHERE b.id = {$alias}.booking_id AND b.company_id = {$alias}.company_id)
        ELSE (SELECT b.document_number FROM rental_car_bookings b
               WHERE b.id = {$alias}.booking_id AND b.company_id = {$alias}.company_id)
    END";
}

/**
 * Sumber penugasan: scoping tenant + rentang started_at + jenis booking.
 * Mengembalikan klausa siap pakai sebagai WHERE (bukan ON) — hasilnya HANYA
 * penugasan yang benar-benar ada, tanpa baris kosong driver menganggur.
 */
function dispatch_assign_source(array $f, string $alias = 'ta'): array
{
    [$w, $wt, $wp] = report_scope("{$alias}.company_id", $f);
    $cond  = $w !== '' ? $w . ' AND ' : '';
    $cond .= "{$alias}.started_at >= ? AND {$alias}.started_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $types  = $wt . 'ss';
    $params = array_merge($wp, [$f['from'], $f['to']]);
    if ($f['booking_type'] !== 'all') {
        $cond .= " AND {$alias}.booking_type = ?";
        $types .= 's';
        $params[] = $f['booking_type'];
    }
    return ['where' => $cond, 'types' => $types, 'params' => $params];
}

/** Kartu ringkasan: total penugasan, selesai, berjalan, driver aktif, dan total hak komisi. */
function dispatch_summary(array $f): array
{
    $s = dispatch_assign_source($f);
    $r = report_run("SELECT COUNT(*) AS total,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan,
        COUNT(DISTINCT ta.employee_id) AS driver
        FROM trip_assignments ta WHERE {$s['where']}", $s['types'], $s['params']);
    $x = $r[0] ?? [];
    $commMap = dispatch_driver_commissions($f);
    return [
        'total'        => (int) ($x['total'] ?? 0),
        'selesai'      => (int) ($x['selesai'] ?? 0),
        'berjalan'     => (int) ($x['berjalan'] ?? 0),
        'driver'       => (int) ($x['driver'] ?? 0),
        'total_komisi' => round(array_sum($commMap), 2),
    ];
}

/**
 * Rekap hak komisi per karyawan (`employee_id`) dari `booking_cost_lines`
 * pada rentang tanggal trip, mengecualikan booking berstatus 'cancelled'.
 *
 * @return array<int, float>
 */
function dispatch_driver_commissions(array $f): array
{
    [$w, $wt, $wp] = report_scope('bcl.company_id', $f);
    $dateExpr = "CASE WHEN bcl.booking_type = 'rental'
                      THEN COALESCE(rb.start_date, rb.date, DATE(bcl.created_date))
                      ELSE COALESCE(tb.date, DATE(bcl.created_date))
                 END";
    $notCancelled = "COALESCE((SELECT bsh.to_status FROM booking_status_history bsh
        WHERE bsh.booking_type = bcl.booking_type AND bsh.booking_id = bcl.booking_id
        ORDER BY bsh.changed_at DESC, bsh.id DESC LIMIT 1), 'draft') <> 'cancelled'";

    $sql = "SELECT bcl.employee_id, COALESCE(SUM(bcl.amount), 0) AS komisi
              FROM booking_cost_lines bcl
              LEFT JOIN travel_ticket_bookings tb
                     ON bcl.booking_type = 'travel' AND tb.id = bcl.booking_id AND tb.company_id = bcl.company_id
              LEFT JOIN rental_car_bookings rb
                     ON bcl.booking_type = 'rental' AND rb.id = bcl.booking_id AND rb.company_id = bcl.company_id
             WHERE " . ($w !== '' ? $w . ' AND ' : '') . "bcl.cost_type <> 'payment'
               AND bcl.employee_id IS NOT NULL AND bcl.employee_id > 0
               AND bcl.amount > 0
               AND {$dateExpr} BETWEEN ? AND ?
               AND {$notCancelled}";
    $types  = $wt . 'ss';
    $params = array_merge($wp, [$f['from'], $f['to']]);
    if (($f['booking_type'] ?? 'all') !== 'all') {
        $sql .= ' AND bcl.booking_type = ?';
        $types .= 's';
        $params[] = $f['booking_type'];
    }
    $sql .= ' GROUP BY bcl.employee_id';

    $rows = report_run($sql, $types, $params);
    $map  = [];
    foreach ($rows as $row) {
        $empId = (int) ($row['employee_id'] ?? 0);
        if ($empId > 0) {
            $map[$empId] = round((float) ($row['komisi'] ?? 0), 2);
        }
    }
    return $map;
}

/** Beban kerja & hak komisi per driver/kru: jumlah tugas, selesai, berjalan, hari bertugas, dan komisi. */
function dispatch_driver_load(array $f): array
{
    $s = dispatch_assign_source($f);
    $rows = report_run("SELECT e.id, e.name, e.code,
        COUNT(*) AS tugas,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan,
        COUNT(DISTINCT DATE(ta.started_at)) AS hari
        FROM trip_assignments ta
        JOIN employees e ON e.id = ta.employee_id
        WHERE {$s['where']}
        GROUP BY e.id, e.name, e.code
        ORDER BY tugas DESC, e.name ASC", $s['types'], $s['params']);

    $commMap = dispatch_driver_commissions($f);
    $seen    = [];
    foreach ($rows as &$r) {
        $empId = (int) ($r['id'] ?? 0);
        $seen[$empId] = true;
        $r['komisi']  = (float) ($commMap[$empId] ?? 0.0);
    }
    unset($r);

    // Sertakan karyawan/kru (mis. kernet/montir atau driver dengan komisi tanpa row trip_assignments) yang memiliki komisi > 0.
    $missingIds = [];
    foreach ($commMap as $empId => $amt) {
        if ($amt > 0 && empty($seen[$empId])) {
            $missingIds[] = (int) $empId;
        }
    }
    if ($missingIds) {
        $ph   = implode(',', array_fill(0, count($missingIds), '?'));
        $emps = report_run(
            "SELECT id, name, code FROM employees WHERE id IN ({$ph}) ORDER BY name ASC",
            str_repeat('i', count($missingIds)),
            $missingIds
        );
        foreach ($emps as $em) {
            $empId  = (int) $em['id'];
            $rows[] = [
                'id'       => $empId,
                'name'     => (string) ($em['name'] ?? ''),
                'code'     => (string) ($em['code'] ?? ''),
                'tugas'    => 0,
                'selesai'  => 0,
                'berjalan' => 0,
                'hari'     => 0,
                'komisi'   => (float) ($commMap[$empId] ?? 0.0),
            ];
        }
    }
    return $rows;
}

/** Detail penugasan per periode (1 baris = 1 penugasan). */
function dispatch_details(array $f): array
{
    $s  = dispatch_assign_source($f);
    $uk = report_scope('v.company_id', $f); // kendaraan tenant lain tidak pernah ikut ter-JOIN
    // Klausa ON LEFT JOIN berada SEBELUM WHERE → placeholder-nya juga lebih dulu.
    $on = $uk[0] !== '' ? ' AND ' . $uk[0] : '';
    return report_run("SELECT ta.id, ta.booking_type, ta.started_at, ta.ended_at, ta.reason,
        e.name AS driver_name, e.code AS driver_code,
        v.vehicle_number, v.vehicle_color,
        " . dispatch_doc_expr('ta') . " AS document_number
        FROM trip_assignments ta
        LEFT JOIN employees e ON e.id = ta.employee_id
        LEFT JOIN vehicles v  ON v.id = ta.vehicle_id{$on}
        WHERE {$s['where']}
        ORDER BY ta.started_at ASC, ta.id ASC", $uk[1] . $s['types'], array_merge($uk[2], $s['params']));
}

/** Semua angka penjadwalan sekaligus — halaman & export identik. */
function dispatch_all(array $f): array
{
    return [
        'summary' => dispatch_summary($f),
        'drivers' => dispatch_driver_load($f),
        'details' => dispatch_details($f),
    ];
}

/** Label status satu baris detail. */
function dispatch_status_label(?string $ended_at): string
{
    return $ended_at === null ? 'Berjalan' : 'Selesai';
}

/** Tanggal & jam ringkas untuk kolom tabel (tanpa detik). */
function dispatch_when(?string $dt): string
{
    return $dt === null || $dt === '' ? '-' : substr($dt, 0, 16);
}

/**
 * Durasi trip dalam menit; nullable kalau started_at tidak bisa diparse.
 * Tugas yang masih berjalan dihitung sampai `$now` — walau started_at berada di
 * masa depan relatif jam server (fixture/selisih tz), hasilnya dibulatkan 0,
 * bukan null: durasi negatif bukan sinyal galat di sini.
 * ponytail: presisi menit cukup untuk rekap; ganti ke TIMESTAMPDIFF di SQL kalau
 * perlu akumulasi lintas zona waktu.
 */
function dispatch_duration(?string $started_at, ?string $ended_at, ?int $now = null): ?int
{
    if ($started_at === null || $started_at === '') {
        return null;
    }
    $a = strtotime($started_at);
    if ($a === false) {
        return null;
    }
    $b = $ended_at === null || $ended_at === '' ? ($now ?? time()) : strtotime($ended_at);
    if ($b === false) {
        return null;
    }
    return max(0, intdiv($b - $a, 60));
}

/** Menit → "3j 15m" (null = '-'). */
function dispatch_duration_label(?int $min): string
{
    if ($min === null) {
        return '-';
    }
    return intdiv($min, 60) . 'j ' . ($min % 60) . 'm';
}

/** Total kolom <tfoot> untuk tabel beban kerja driver. */
function dispatch_totals(array $rows, array $keys): array
{
    $t = array_fill_keys($keys, 0);
    foreach ($rows as $r) {
        foreach ($keys as $k) {
            if ($k === 'komisi') {
                $t[$k] = (float) $t[$k] + (float) ($r[$k] ?? 0);
            } else {
                $t[$k] += (int) ($r[$k] ?? 0);
            }
        }
    }
    return $t;
}
