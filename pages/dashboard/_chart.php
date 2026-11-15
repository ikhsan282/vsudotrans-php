<?php
/**
 * Data grafik dashboard: booking & pendapatan per bulan (6 bulan terakhir),
 * di-scope company_id perusahaan aktif.
 *
 * Query memakai report_source() (pola report yang sudah ada) supaya struktur
 * UNION travel+rental + filter tenant/CSRF tidak ditulis ulang, dengan
 * booking_type='all' dan rentang tanggal bulan berjalan - 5 bulan.
 * Kolom tanggal = `date`, nilai = `total_price` (lihat database/schema.sql).
 */

/** 6 bulan terakhir (termasuk bulan berjalan) sebagai ['Y-m' => 'Label']. */
function chart_months(int $n = 6): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $ts = strtotime("first day of -{$i} month");
        $out[date('Y-m', $ts)] = date('M Y', $ts);
    }
    return $out;
}

/**
 * Ambil data grafik 6 bulan terakhir sekaligus ringkasan Booking Hari Ini & Minggu Ini
 * dalam 1x query UNION ALL agar tidak memindai tabel booking dua kali.
 *
 * @return array{
 *   chart: array{labels:array<int,string>,keys:array<int,string>,bookings:array<int,int>,revenue:array<int,float>},
 *   summary: array{today:array{n:int,total:float},week:array{n:int,total:float}}
 * }
 */
function dashboard_chart_with_summary(string $today, string $week_start, string $week_end): array
{
    require_once __DIR__ . '/../reports/_query.php';

    $months = chart_months();
    $keys   = array_keys($months);
    $first  = $keys[0] . '-01';
    $last   = date('Y-m-t'); // akhir bulan berjalan
    $qFrom  = min($first, $today, $week_start);
    $qTo    = max($last, $today, $week_end);

    $series = [
        'labels'   => array_values($months),
        'keys'     => $keys,
        'bookings' => array_fill(0, count($months), 0),
        'revenue'  => array_fill(0, count($months), 0.0),
    ];
    $todaySum = ['n' => 0, 'total' => 0.0];
    $weekSum  = ['n' => 0, 'total' => 0.0];

    $src = report_source(['from' => $qFrom, 'to' => $qTo, 'booking_type' => 'all']);
    $sql = 'SELECT DATE_FORMAT(x.date, \'%Y-%m\') AS ym,
                   COUNT(*) AS n,
                   COALESCE(SUM(x.total_price), 0) AS total,
                   COALESCE(SUM(CASE WHEN x.date = ? THEN 1 ELSE 0 END), 0) AS today_n,
                   COALESCE(SUM(CASE WHEN x.date = ? THEN x.total_price ELSE 0 END), 0) AS today_total,
                   COALESCE(SUM(CASE WHEN x.date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS week_n,
                   COALESCE(SUM(CASE WHEN x.date BETWEEN ? AND ? THEN x.total_price ELSE 0 END), 0) AS week_total
              FROM ' . $src['sql'] . ' GROUP BY ym';
    $rows = report_run(
        $sql,
        'ssssss' . $src['types'],
        array_merge([$today, $today, $week_start, $week_end, $week_start, $week_end], $src['params'])
    );
    foreach ($rows as $r) {
        $i = array_search($r['ym'], $series['keys'], true);
        if ($i !== false) {
            $series['bookings'][$i] = (int) $r['n'];
            $series['revenue'][$i]  = (float) $r['total'];
        }
        $todaySum['n']     += (int) ($r['today_n'] ?? 0);
        $todaySum['total'] += (float) ($r['today_total'] ?? 0);
        $weekSum['n']      += (int) ($r['week_n'] ?? 0);
        $weekSum['total']  += (float) ($r['week_total'] ?? 0);
    }

    return [
        'chart'   => $series,
        'summary' => ['today' => $todaySum, 'week' => $weekSum],
    ];
}

/** Dua seri grafik: jumlah booking & jumlah nilai per bulan. Bulan tanpa data = 0. */
function dashboard_chart(?array $booking_types = null): array
{
    $today = date('Y-m-d');
    return dashboard_chart_with_summary($today, $today, $today)['chart'];
}

// ponytail: dipakai halaman dashboard; tes memanggil fungsi ini langsung.
if (($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    throw new RuntimeException('_chart.php adalah include, bukan entry point.');
}
