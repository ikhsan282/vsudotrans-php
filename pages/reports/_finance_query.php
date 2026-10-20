<?php
/**
 * Laporan Keuangan — SATU sumber query untuk halaman, export, dan self-check.
 *
 * Filter memakai report_filter()/report_source() dari _query.php (pola sama
 * dengan Laporan Booking): from, to, booking_type, company_id perusahaan aktif.
 *
 * Sumber angka:
 *  - Pendapatan : SUM(total_price) booking travel+rental pada rentang `date`.
 *  - Terbayar   : SUM(total_payment) dari sumber yang sama.
 *  - Piutang    : pendapatan - terbayar.
 *  - Biaya      : SUM(booking_cost_lines.amount) per cost_type, di-scope
 *                 company_id + rentang `created_date` (satu-satunya tanggal yang
 *                 dimiliki baris biaya) + booking_type yang dipilih.
 *  - Laba kotor : pendapatan - biaya.
 *
 * Catatan: biaya TIDAK di-JOIN ke booking. Baris biaya yatim (booking sudah
 * dihapus) tetap dihitung — buku besar tidak boleh kehilangan pengeluaran nyata.
 */

require_once __DIR__ . '/_query.php';

/** Ringkasan arus uang: pendapatan per jenis, terbayar, piutang. */
function fin_revenue(array $f): array
{
    $s = report_summary($f);
    return [
        'travel'     => (float) ($s['travel_price'] ?? 0),
        'rental'     => (float) ($s['rental_price'] ?? 0),
        'total'      => (float) ($s['total_price'] ?? 0),
        'paid'       => (float) ($s['total_payment'] ?? 0),
        'receivable' => (float) ($s['total_receivable'] ?? 0),
    ];
}

/** Total biaya operasional pada rentang + filter jenis. */
function fin_cost_total(array $f): float
{
    $s = fin_cost_source($f);
    $row = report_run('SELECT COALESCE(SUM(z.amount),0) AS total FROM ' . $s['sql'], $s['types'], $s['params']);
    return (float) ($row[0]['total'] ?? 0);
}

/**
 * Sumber biaya: derived table z (filter tenant + tanggal + jenis).
 * Return bentuk yang sama dengan report_source().
 */
function fin_cost_source(array $f): array
{
    [$w, $wt, $wp] = company_scope('company_id');
    $sql = 'booking_cost_lines z WHERE ' . ($w !== '' ? $w . ' AND ' : '')
        . 'z.created_date >= ? AND z.created_date < DATE_ADD(?, INTERVAL 1 DAY)';
    $types  = $wt . 'ss';
    $params = array_merge($wp, [$f['from'], $f['to']]);

    if ($f['booking_type'] !== 'all') {
        $sql .= ' AND z.booking_type = ?';
        $types .= 's';
        $params[] = $f['booking_type'];
    }
    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

/**
 * Biaya per jenis (cost_type), urut terbesar.
 * COALESCE(NULLIF(cost_type,''),'(tanpa jenis)') agar string kosong tidak
 * tampil sebagai baris hampa.
 */
function fin_cost_by_type(array $f): array
{
    $s = fin_cost_source($f);
    return report_run("SELECT COALESCE(NULLIF(z.cost_type, ''), '(tanpa jenis)') AS cost_type,
        COUNT(*) AS n_lines, COALESCE(SUM(z.amount),0) AS amount
        FROM {$s['sql']}
        GROUP BY cost_type ORDER BY amount DESC, cost_type ASC", $s['types'], $s['params']);
}

/**
 * Rincian tagihan belum lunas (sisa > 0), urut sisa terbesar.
 * $limit menahan tabel HTML; export memakai $limit = null (semua baris).
 */
function fin_unpaid(array $f, ?int $limit = null): array
{
    $s = report_source($f);
    $sql = "SELECT x.booking_type, x.id, x.document_number, x.date, x.total_price, x.total_payment,
            (x.total_price - x.total_payment) AS outstanding
        FROM {$s['sql']} HAVING outstanding > 0
        ORDER BY outstanding DESC, x.date ASC";
    $types  = $s['types'];
    $params = $s['params'];
    if ($limit !== null) {
        // Kolom derived table bertipe string di UNION → batasi dengan string, bukan int.
        $sql .= ' LIMIT ?';
        $types .= 's';
        $params[] = (string) $limit;
    }
    return report_run($sql, $types, $params);
}

/** Semua angka laporan keuangan sekaligus — halaman & export identik. */
function fin_all(array $f, ?int $unpaid_limit = 200): array
{
    $rev  = fin_revenue($f);
    $cost = fin_cost_total($f);
    return [
        'revenue'      => $rev,
        'cost_total'   => $cost,
        'gross_profit' => $rev['total'] - $cost,
        'cost_types'   => fin_cost_by_type($f),
        'unpaid'       => fin_unpaid($f, $unpaid_limit),
        'monthly'      => fin_monthly($f),
    ];
}

/**
 * Seri bulanan untuk grafik: pendapatan, terbayar, biaya per bulan dalam rentang.
 * Hanya dipakai halaman (bukan export) — export tetap memakai agregat periode.
 * Bulan tanpa data diisi 0 agar sumbu waktu tidak bolong.
 */
function fin_monthly(array $f): array
{
    $s = report_source($f);
    $rev = report_run(
        "SELECT DATE_FORMAT(x.date, '%Y-%m') AS ym, COALESCE(SUM(x.total_price),0) AS total,
                COALESCE(SUM(x.total_payment),0) AS paid
         FROM {$s['sql']} GROUP BY ym",
        $s['types'],
        $s['params']
    );
    $cs = fin_cost_source($f);
    $cost = report_run(
        "SELECT DATE_FORMAT(z.created_date, '%Y-%m') AS ym, COALESCE(SUM(z.amount),0) AS total
         FROM {$cs['sql']} GROUP BY ym",
        $cs['types'],
        $cs['params']
    );

    $rev_map  = array_column($rev, null, 'ym');
    $cost_map = array_column($cost, null, 'ym');

    $out = ['labels' => [], 'revenue' => [], 'paid' => [], 'cost' => [], 'profit' => []];
    $cursor = strtotime(date('Y-m-01', strtotime($f['from'])));
    $end    = strtotime(date('Y-m-01', strtotime($f['to'])));
    // ponytail: maks 36 titik; rentang tahunan = 12, cukup untuk semua filter wajar.
    for ($i = 0; $cursor <= $end && $i < 36; $i++, $cursor = strtotime('+1 month', $cursor)) {
        $ym = date('Y-m', $cursor);
        $r  = (float) ($rev_map[$ym]['total'] ?? 0);
        $p  = (float) ($rev_map[$ym]['paid']  ?? 0);
        $c  = (float) ($cost_map[$ym]['total'] ?? 0);
        $out['labels'][]  = date('M Y', $cursor);
        $out['revenue'][] = $r;
        $out['paid'][]    = $p;
        $out['cost'][]    = $c;
        $out['profit'][]  = $r - $c;
    }
    return $out;
}

/** Total kolom untuk baris <tfoot> tabel biaya. */
function fin_cost_totals(array $rows): array
{
    $t = ['n_lines' => 0, 'amount' => 0.0];
    foreach ($rows as $r) {
        $t['n_lines'] += (int) $r['n_lines'];
        $t['amount']  += (float) $r['amount'];
    }
    return $t;
}

/** Total kolom untuk baris <tfoot> tabel piutang. */
function fin_unpaid_totals(array $rows): array
{
    $t = ['price' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
    foreach ($rows as $r) {
        $t['price']       += (float) $r['total_price'];
        $t['paid']        += (float) $r['total_payment'];
        $t['outstanding'] += (float) $r['outstanding'];
    }
    return $t;
}
