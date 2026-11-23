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
    $catId = (int) ($f['category_id'] ?? 0);
    if ($catId > 0) {
        $cond .= " AND (({$alias}.booking_type = 'travel' AND EXISTS (SELECT 1 FROM travel_ticket_bookings tb WHERE tb.id = {$alias}.booking_id AND tb.company_id = {$alias}.company_id AND tb.category_vehicle_id = ?))"
              . " OR ({$alias}.booking_type = 'rental' AND EXISTS (SELECT 1 FROM rental_car_bookings rb WHERE rb.id = {$alias}.booking_id AND rb.company_id = {$alias}.company_id AND rb.category_vehicle_id = ?)))";
        $types .= 'ii';
        $params[] = $catId;
        $params[] = $catId;
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
    [$vw, $vt, $vp] = report_scope('v.company_id', $f);
    $catId = (int) ($f['category_id'] ?? 0);
    $having = $catId > 0 ? ' HAVING tugas > 0 OR v.category_vehicle_id = ?' : '';
    $types  = $s['types'] . $vt . ($catId > 0 ? 'i' : '');
    $params = array_merge($s['params'], $vp, $catId > 0 ? [$catId] : []);
    return report_run("SELECT v.id, v.vehicle_number, v.vehicle_color, v.category_vehicle_id,
        COUNT(DISTINCT ta.id) AS tugas,
        COALESCE(SUM(ta.ended_at IS NOT NULL), 0) AS selesai,
        COALESCE(SUM(ta.ended_at IS NULL), 0) AS berjalan,
        COALESCE(COUNT(DISTINCT DATE(ta.started_at)), 0) AS hari
        FROM vehicles v
        LEFT JOIN trip_assignments ta ON ta.vehicle_id = v.id AND {$s['where']}
        WHERE {$vw}
        GROUP BY v.id, v.vehicle_number, v.vehicle_color, v.category_vehicle_id{$having}
        ORDER BY tugas DESC, v.vehicle_number ASC", $types, $params);
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

/** Operasional booking per kategori layanan (aktif/berjalan, selesai, batal, tingkat selesai %). */
function ops_by_category(array $f): array
{
    $j = report_joined($f);
    $rows = report_run(
        "SELECT COALESCE(x.category_vehicle_id, 0) AS category_id,
                x.booking_type,
                COALESCE(cv.code, '') AS category_code,
                COALESCE(cv.name, '') AS category_name,
                COUNT(*) AS total,
                COALESCE(SUM(COALESCE(h.to_status, 'draft') NOT IN ('done', 'cancelled')), 0) AS berjalan,
                COALESCE(SUM(COALESCE(h.to_status, 'draft') = 'done'), 0) AS selesai,
                COALESCE(SUM(COALESCE(h.to_status, 'draft') = 'cancelled'), 0) AS dibatalkan
           FROM {$j['sql']}
           LEFT JOIN category_vehicles cv ON cv.id = x.category_vehicle_id
           LEFT JOIN booking_status_history h
                  ON h.id = (SELECT MAX(h2.id) FROM booking_status_history h2
                              WHERE h2.booking_type = x.booking_type
                                AND h2.booking_id = x.id
                                AND h2.company_id = x.company_id)
          GROUP BY category_id, x.booking_type, category_code, category_name
          ORDER BY total DESC, category_name ASC",
        $j['types'],
        $j['params']
    );
    foreach ($rows as &$r) {
        $lbl = trim(($r['category_code'] !== '' ? $r['category_code'] . ' - ' : '') . $r['category_name']);
        $r['category_label'] = $lbl !== '' ? $lbl : 'Tanpa Kategori';
        $r['service_label']  = $r['booking_type'] === 'rental' ? 'Rental' : 'Travel';
        $activeCnt = max(0, (int) $r['total'] - (int) $r['dibatalkan']);
        $r['persen'] = $activeCnt > 0 ? round(((int) $r['selesai'] * 100) / $activeCnt, 1) : 0.0;
    }
    unset($r);
    return $rows;
}

/** Semua angka operasional sekaligus — halaman & export identik. */
function ops_all(array $f): array
{
    return [
        'vehicles'   => ops_vehicle_usage($f),
        'drivers'    => ops_driver_activity($f),
        'completion' => ops_completion($f),
        'status'     => ops_booking_status($f),
        'categories' => ops_by_category($f),
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
