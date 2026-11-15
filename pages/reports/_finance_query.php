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
require_once __DIR__ . '/../../includes/transport_helpers.php';

/** Filter khusus Laporan Keuangan (mendukung filter perusahaan eksplisit Super Admin). */
function fin_report_filter(?array $in = null): array
{
    $f = report_filter($in);
    $src = $in ?? $_GET;
    $scope = company_filter_scope('company_id', $src);
    $f['filter_company_id'] = (int) $scope['filter_company_id'];
    return $f;
}

/** Clause company scope untuk laporan keuangan (pakai filter eksplisit jika diset oleh Super Admin). */
function fin_company_scope(string $column, array $f): array
{
    if (is_super_admin() && isset($f['filter_company_id'])) {
        $fcid = (int) $f['filter_company_id'];
        return $fcid > 0 ? ["{$column} = ?", 'i', [$fcid]] : ['1=1', '', []];
    }
    return company_scope($column);
}

/** Sumber booking UNION (identik report_source, menghormati filter_company_id UI jika diset). */
function fin_booking_source(array $f): array
{
    if (!is_super_admin() || empty($f['filter_company_id'])) {
        return report_source($f);
    }
    $fcid = (int) $f['filter_company_id'];
    $inner_where = 'b.company_id = ? AND b.date BETWEEN ? AND ?';
    $bTypes = 'iss';
    $bParams = [$fcid, $f['from'], $f['to']];
    $cols = static fn(string $table, string $type) => "SELECT '{$type}' AS booking_type, b.id, b.company_id,
            b.branch_id, b.date, b.time, b.total_price, b.total_payment, b.document_number,
            b.passenger_id, b.vehicle_id, b.pickup_point_city_id AS origin_city_id,
            b.delivery_point_city_id AS dest_city_id
        FROM {$table} b
        WHERE {$inner_where}";
    $parts = [];
    $types = '';
    $params = [];
    if ($f['booking_type'] !== 'rental') {
        $parts[] = $cols('travel_ticket_bookings', 'travel');
        $types .= $bTypes;
        $params = array_merge($params, $bParams);
    }
    if ($f['booking_type'] !== 'travel') {
        $parts[] = $cols('rental_car_bookings', 'rental');
        $types .= $bTypes;
        $params = array_merge($params, $bParams);
    }
    return ['sql' => '(' . implode(' UNION ALL ', $parts) . ') x', 'types' => $types, 'params' => $params];
}

/** Ringkasan arus uang: pendapatan per jenis, terbayar, piutang. */
function fin_revenue(array $f): array
{
    $s = fin_booking_source($f);
    $rows = report_run('SELECT ' . report_agg() . ' FROM ' . $s['sql'], $s['types'], $s['params']);
    $s = $rows[0] ?? [];
    return [
        'travel'     => (float) ($s['travel_price'] ?? 0),
        'rental'     => (float) ($s['rental_price'] ?? 0),
        'total'      => (float) ($s['total_price'] ?? 0),
        'paid'       => (float) ($s['total_payment'] ?? 0),
        'receivable' => (float) ($s['total_receivable'] ?? 0),
    ];
}

/** Sumber BBM rental yang disimpan langsung di `rental_car_bookings.bbm`. */
function fin_rental_bbm_source(array $f): ?array
{
    if (($f['booking_type'] ?? 'all') === 'travel') {
        return null;
    }
    [$w, $wt, $wp] = fin_company_scope('r.company_id', $f);
    $sql = 'rental_car_bookings r WHERE ' . ($w !== '' ? $w . ' AND ' : '')
        . "COALESCE(NULLIF(r.start_date, ''), r.date) BETWEEN ? AND ? AND r.bbm > 0";
    return ['sql' => $sql, 'types' => $wt . 'ss', 'params' => array_merge($wp, [$f['from'], $f['to']])];
}

/** Total biaya operasional pada rentang + filter jenis (tidak menghitung baris pembayaran 'payment'). */
function fin_cost_total(array $f): float
{
    $s = fin_cost_source($f);
    $row = report_run('SELECT COALESCE(SUM(z.amount),0) AS total FROM ' . $s['sql'], $s['types'], $s['params']);
    $tot = (float) ($row[0]['total'] ?? 0);

    $bbm = fin_rental_bbm_source($f);
    if ($bbm !== null) {
        $bRow = report_run('SELECT COALESCE(SUM(r.bbm),0) AS total FROM ' . $bbm['sql'], $bbm['types'], $bbm['params']);
        $tot += (float) ($bRow[0]['total'] ?? 0);
    }
    return $tot;
}

/**
 * Sumber biaya: derived table z (filter tenant + tanggal + jenis).
 * Selalu mengecualikan cost_type = 'payment' karena itu penerimaan dari pelanggan, bukan biaya.
 */
function fin_cost_source(array $f): array
{
    [$w, $wt, $wp] = fin_company_scope('z.company_id', $f);
    $sql = 'booking_cost_lines z WHERE ' . ($w !== '' ? $w . ' AND ' : '')
        . "z.cost_type <> 'payment' AND z.created_date >= ? AND z.created_date < DATE_ADD(?, INTERVAL 1 DAY)";
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
 * Biaya per jenis (cost_type), urut terbesar (termasuk BBM rental dari rental_car_bookings.bbm > 0).
 */
function fin_cost_by_type(array $f): array
{
    $s = fin_cost_source($f);
    $rows = report_run("SELECT COALESCE(NULLIF(z.cost_type, ''), '(tanpa jenis)') AS cost_type,
        COUNT(*) AS n_lines, COALESCE(SUM(z.amount),0) AS amount
        FROM {$s['sql']}
        GROUP BY cost_type ORDER BY amount DESC, cost_type ASC", $s['types'], $s['params']);

    $bbm = fin_rental_bbm_source($f);
    if ($bbm !== null) {
        $bRow = report_run('SELECT COUNT(*) AS n_lines, COALESCE(SUM(r.bbm),0) AS amount FROM ' . $bbm['sql'], $bbm['types'], $bbm['params']);
        $bAmt = (float) ($bRow[0]['amount'] ?? 0);
        $bCnt = (int) ($bRow[0]['n_lines'] ?? 0);
        if ($bAmt > 0 && $bCnt > 0) {
            $merged = false;
            foreach ($rows as &$rw) {
                if (strcasecmp((string) $rw['cost_type'], 'BBM') === 0) {
                    $rw['n_lines'] = (int) $rw['n_lines'] + $bCnt;
                    $rw['amount']  = (float) $rw['amount'] + $bAmt;
                    $merged = true;
                    break;
                }
            }
            unset($rw);
            if (!$merged) {
                $rows[] = ['cost_type' => 'BBM', 'n_lines' => $bCnt, 'amount' => $bAmt];
            }
            usort($rows, static fn(array $a, array $b): int =>
                ((float) $b['amount'] <=> (float) $a['amount']) ?: strcmp((string) $a['cost_type'], (string) $b['cost_type'])
            );
        }
    }
    return $rows;
}

/** Ringkasan arus kas nyata (Buku Kas & Bank) dalam rentang filter. */
function fin_cash_summary(array $f): array
{
    [$w, $wt, $wp] = fin_company_scope('t.company_id', $f);
    $sql = "SELECT
            COALESCE(SUM(CASE WHEN t.direction = 'in' AND t.source_type = 'booking_payment' THEN t.amount ELSE 0 END), 0) AS in_booking,
            COALESCE(SUM(CASE WHEN t.direction = 'in' AND (t.source_type IS NULL OR t.source_type <> 'booking_payment') THEN t.amount ELSE 0 END), 0) AS in_other,
            COALESCE(SUM(CASE WHEN t.direction = 'out' AND t.source_type = 'payroll_period' THEN t.amount ELSE 0 END), 0) AS out_payroll,
            COALESCE(SUM(CASE WHEN t.direction = 'out' AND (t.source_type IS NULL OR t.source_type <> 'payroll_period') THEN t.amount ELSE 0 END), 0) AS out_other
        FROM cash_transactions t
        WHERE " . ($w !== '' ? $w . ' AND ' : '') . 't.date BETWEEN ? AND ?';
    $r = report_run($sql, $wt . 'ss', array_merge($wp, [$f['from'], $f['to']]))[0] ?? [];
    $inB  = (float) ($r['in_booking'] ?? 0);
    $inO  = (float) ($r['in_other'] ?? 0);
    $outP = (float) ($r['out_payroll'] ?? 0);
    $outO = (float) ($r['out_other'] ?? 0);
    return [
        'in_booking'    => $inB,
        'in_other'      => $inO,
        'in_total'      => $inB + $inO,
        'out_payroll'   => $outP,
        'out_other'     => $outO,
        'out_total'     => $outP + $outO,
        'net_cash_flow' => ($inB + $inO) - ($outP + $outO),
    ];
}

/**
 * Rincian tagihan belum lunas (sisa > 0), urut sisa terbesar.
 * $limit menahan tabel HTML; export memakai $limit = null (semua baris).
 */
function fin_unpaid(array $f, ?int $limit = null): array
{
    $s = fin_booking_source($f);
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
        'cash'         => fin_cash_summary($f),
    ];
}

/**
 * Seri bulanan untuk grafik: pendapatan, terbayar, biaya per bulan dalam rentang.
 * Hanya dipakai halaman (bukan export) — export tetap memakai agregat periode.
 * Bulan tanpa data diisi 0 agar sumbu waktu tidak bolong.
 */
function fin_monthly(array $f): array
{
    $s = fin_booking_source($f);
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
    $cost_map = [];
    foreach ($cost as $cr) {
        $cost_map[(string) $cr['ym']] = (float) ($cr['total'] ?? 0);
    }
    $bbm = fin_rental_bbm_source($f);
    if ($bbm !== null) {
        $bbmRows = report_run(
            "SELECT DATE_FORMAT(COALESCE(NULLIF(r.start_date, ''), r.date), '%Y-%m') AS ym, COALESCE(SUM(r.bbm),0) AS total
             FROM {$bbm['sql']} GROUP BY ym",
            $bbm['types'],
            $bbm['params']
        );
        foreach ($bbmRows as $br) {
            $ym = (string) $br['ym'];
            $cost_map[$ym] = ($cost_map[$ym] ?? 0.0) + (float) ($br['total'] ?? 0);
        }
    }

    $out = ['labels' => [], 'revenue' => [], 'paid' => [], 'cost' => [], 'profit' => []];
    $cursor = strtotime(date('Y-m-01', strtotime($f['from'])));
    $end    = strtotime(date('Y-m-01', strtotime($f['to'])));
    // ponytail: maks 36 titik; rentang tahunan = 12, cukup untuk semua filter wajar.
    for ($i = 0; $cursor <= $end && $i < 36; $i++, $cursor = strtotime('+1 month', $cursor)) {
        $ym = date('Y-m', $cursor);
        $r  = (float) ($rev_map[$ym]['total'] ?? 0);
        $p  = (float) ($rev_map[$ym]['paid']  ?? 0);
        $c  = (float) ($cost_map[$ym] ?? 0);
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
