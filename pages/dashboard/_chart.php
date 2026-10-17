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

/** Dua seri grafik: jumlah booking & jumlah nilai per bulan. Bulan tanpa data = 0. */
function dashboard_chart(?array $booking_types = null): array
{
    require_once __DIR__ . '/../reports/_query.php';

    $months = chart_months();
    $first  = array_key_first($months) . '-01';
    $last   = date('Y-m-t'); // akhir bulan berjalan

    $series = [
        'labels'   => array_values($months),
        'keys'     => array_keys($months),
        'bookings' => array_fill(0, count($months), 0),
        'revenue'  => array_fill(0, count($months), 0.0),
    ];
    $src = report_source(['from' => $first, 'to' => $last, 'booking_type' => 'all']);
    $rows = report_run(
        'SELECT DATE_FORMAT(x.date, \'%Y-%m\') AS ym, COUNT(*) AS n, COALESCE(SUM(x.total_price), 0) AS total '
        . 'FROM ' . $src['sql'] . ' GROUP BY ym',
        $src['types'],
        $src['params']
    );
    foreach ($rows as $r) {
        $i = array_search($r['ym'], $series['keys'], true);
        if ($i !== false) {
            $series['bookings'][$i] = (int) $r['n'];
            $series['revenue'][$i]  = (float) $r['total'];
        }
    }
    return $series;
}

// ponytail: dipakai halaman dashboard; tes memanggil fungsi ini langsung.
if (($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    throw new RuntimeException('_chart.php adalah include, bukan entry point.');
}
