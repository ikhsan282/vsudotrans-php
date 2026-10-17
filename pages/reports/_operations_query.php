<?php
/**
 * Laporan Operasional — SATU sumber query untuk halaman & export.
 *
 * Filter memakai report_filter() dari _query.php (from, to, booking_type).
 *
 * Definisi yang dipakai (semua di-scope company_id perusahaan aktif):
 *  - Utilisasi kendaraan : penugasan pada `trip_assignments` yang `started_at`
 *    jatuh di rentang. Dihitung PER TUGAS (bukan per booking) — sesuai definisi
 *    "jumlah penugasan". Dedup `MAX(id)` TIDAK dipakai di sini karena driver
 *    pengganti = tugas nyata yang juga memakai kendaraan; kalau didedup, hari
 *    kerja kendaraan hilang dari laporan.
 *  - Aktivitas driver   : per karyawan pada `trip_assignments`, selesai
 *    (`ended_at IS NOT NULL`) vs berjalan.
 *  - Tingkat penyelesaian: 3 angka total/selesai/berjalan dari sumber yang sama.
 *  - Booking per status : status diturunkan dari `booking_status_history`
 *    (to_status baris terakhir per booking) — skema TIDAK punya kolom status di
 *    tabel booking, persis pola `pages/travel/bookings/index.php`.
 */

require_once __DIR__ . '/_query.php';

/**
 * Sumber penugasan: filter tenant + rentang started_at + jenis booking.
 * Mengembalikan juga versi "ON" (tanpa ON, dipakai sebagai WHERE) supaya
 * pemanggil bisa memilih: subquery WHERE atau klausa ON untuk LEFT JOIN.
 */
function ops_assign_source(array $f, string $alias = 'ta'): array
{
    [$w, $wt, $wp] = company_scope("{$alias}.company_id");
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

/**
 * Utilisasi kendaraan: penugasan per kendaraan + total hari kalender unik.
 * Kendaraan tanpa penugasan tetap tampil (LEFT JOIN) dengan angka 0 — supaya
 * armada menganggur kelihatan, bukan menghilang.
 */
function ops_vehicle_usage(array $f): array
{
    // Kondisi filter harus di klausa ON agar LEFT JOIN tetap mempertahankan
    // kendaraan tanpa penugasan (di WHERE, LEFT JOIN jadi INNER JOIN).
    $s = ops_assign_source($f);
    return report_run("SELECT v.id, v.vehicle_number, v.vehicle_color,
        COUNT(DISTINCT ta.id) AS tugas,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan,
        COALESCE(COUNT(DISTINCT DATE(ta.started_at)), 0) AS hari
        FROM vehicles v
        LEFT JOIN trip_assignments ta ON ta.vehicle_id = v.id AND {$s['where']}
        GROUP BY v.id, v.vehicle_number, v.vehicle_color
        ORDER BY tugas DESC, v.vehicle_number ASC", $s['types'], $s['params']);
}

/** Aktivitas driver: hanya karyawan yang benar-benar pernah ditugaskan. */
function ops_driver_activity(array $f): array
{
    $s = ops_assign_source($f);
    return report_run("SELECT e.id, e.name, e.code,
        COUNT(*) AS tugas,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan
        FROM trip_assignments ta
        JOIN employees e ON e.id = ta.employee_id
        WHERE {$s['where']}
        GROUP BY e.id, e.name, e.code
        ORDER BY tugas DESC, e.name ASC", $s['types'], $s['params']);
}

/** Tingkat penyelesaian: 3 angka total/selesai/berjalan. */
function ops_completion(array $f): array
{
    $s = ops_assign_source($f);
    $r = report_run("SELECT COUNT(*) AS total,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan
        FROM trip_assignments ta WHERE {$s['where']}", $s['types'], $s['params']);
    $x = $r[0] ?? ['total' => 0, 'selesai' => 0, 'berjalan' => 0];
    $total = (int) $x['total'];
    return [
        'total'    => $total,
        'selesai'  => (int) $x['selesai'],
        'berjalan' => (int) $x['berjalan'],
        'persen'   => $total > 0 ? round((int) $x['selesai'] * 100 / $total, 1) : 0.0,
    ];
}

/**
 * Booking per status: status = to_status terakhir di booking_status_history.
 * Booking tanpa baris history → 'draft' (default aplikasi, lihat
 * booking_current_status()).
 */
function ops_booking_status(array $f): array
{
    // report_joined() membungkus sumber sekali lagi → alias `x` valid di JOIN.
    $j = report_joined($f);
    return report_run("SELECT COALESCE(h.to_status, 'draft') AS status,
        SUM(x.booking_type = 'travel') AS travel,
        SUM(x.booking_type = 'rental') AS rental,
        COUNT(*) AS total
        FROM {$j['sql']}
        LEFT JOIN booking_status_history h
               ON h.id = (SELECT MAX(h2.id) FROM booking_status_history h2
                           WHERE h2.booking_type = x.booking_type
                             AND h2.booking_id = x.id
                             AND h2.company_id = x.company_id)
        GROUP BY status ORDER BY total DESC, status ASC", $j['types'], $j['params']);
}

/** Semua angka operasional sekaligus — halaman & export identik. */
function ops_all(array $f): array
{
    return [
        'vehicles'   => ops_vehicle_usage($f),
        'drivers'    => ops_driver_activity($f),
        'completion' => ops_completion($f),
        'status'     => ops_booking_status($f),
    ];
}

/** Total kolom <tfoot> untuk tabel utilisesi kendaraan / aktivitas driver. */
function ops_totals(array $rows, array $keys): array
{
    $t = array_fill_keys($keys, 0);
    foreach ($rows as $r) {
        foreach ($keys as $k) {
            $t[$k] += (int) $r[$k];
        }
    }
    return $t;
}
