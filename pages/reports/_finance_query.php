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

/** Kondisi SQL untuk mengecualikan booking yang status terakhirnya 'cancelled' (memakai index ix_bsh_booking). */
function fin_not_cancelled_sql(string $typeExpr, string $idExpr): string
{
    return "COALESCE((SELECT bsh.to_status FROM booking_status_history bsh
              WHERE bsh.booking_type = {$typeExpr} AND bsh.booking_id = {$idExpr}
              ORDER BY bsh.id DESC LIMIT 1), 'draft') <> 'cancelled'";
}

/** Ekspresi SQL tanggal efektif biaya trip: mengikuti tanggal trip/mulai sewa (fallback created_date). */
function fin_cost_date_expr(): string
{
    return "CASE WHEN z.booking_type = 'rental'
                 THEN COALESCE(rb.start_date, rb.date, DATE(z.created_date))
                 ELSE COALESCE(tb.date, DATE(z.created_date))
            END";
}

/**
 * Sumber booking UNION khusus Laporan Keuangan:
 * menggunakan tanggal berangkat / mulai sewa & mengecualikan booking 'cancelled'.
 */
function fin_booking_source(array $f): array
{
    [$w, $wt, $wp] = fin_company_scope('b.company_id', $f);
    $catId = (int) ($f['category_id'] ?? 0);

    $parts  = [];
    $types  = '';
    $params = [];

    if ($f['booking_type'] !== 'rental') {
        $cond   = ($w !== '' ? $w . ' AND ' : '')
            . 'b.date BETWEEN ? AND ? AND ' . fin_not_cancelled_sql("'travel'", 'b.id');
        $bTypes  = $wt . 'ss';
        $bParams = array_merge($wp, [$f['from'], $f['to']]);
        if ($catId > 0) {
            $cond     .= ' AND b.category_vehicle_id = ?';
            $bTypes   .= 'i';
            $bParams[] = $catId;
        }
        $parts[] = "SELECT 'travel' AS booking_type, b.id, b.company_id,
                b.branch_id, b.date, b.time, b.total_price, b.total_payment, b.document_number,
                b.passenger_id, b.category_vehicle_id, b.vehicle_id,
                b.pickup_point_city_id AS origin_city_id, b.delivery_point_city_id AS dest_city_id
            FROM travel_ticket_bookings b
            WHERE {$cond}";
        $types  .= $bTypes;
        $params  = array_merge($params, $bParams);
    }

    if ($f['booking_type'] !== 'travel') {
        $cond   = ($w !== '' ? $w . ' AND ' : '')
            . 'b.start_date BETWEEN ? AND ? AND ' . fin_not_cancelled_sql("'rental'", 'b.id');
        $bTypes  = $wt . 'ss';
        $bParams = array_merge($wp, [$f['from'], $f['to']]);
        if ($catId > 0) {
            $cond     .= ' AND b.category_vehicle_id = ?';
            $bTypes   .= 'i';
            $bParams[] = $catId;
        }
        $parts[] = "SELECT 'rental' AS booking_type, b.id, b.company_id,
                b.branch_id, b.start_date AS date, b.time, b.total_price, b.total_payment, b.document_number,
                b.passenger_id, b.category_vehicle_id, b.vehicle_id,
                b.pickup_point_city_id AS origin_city_id, b.delivery_point_city_id AS dest_city_id
            FROM rental_car_bookings b
            WHERE {$cond}";
        $types  .= $bTypes;
        $params  = array_merge($params, $bParams);
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

/** Sumber BBM rental aktif (tidak cancelled) dari `rental_car_bookings.bbm`. */
function fin_rental_bbm_source(array $f): ?array
{
    if (($f['booking_type'] ?? 'all') === 'travel') {
        return null;
    }
    [$w, $wt, $wp] = fin_company_scope('r.company_id', $f);
    $sql = 'rental_car_bookings r WHERE ' . ($w !== '' ? $w . ' AND ' : '')
        . 'r.start_date BETWEEN ? AND ? AND r.bbm > 0 AND '
        . fin_not_cancelled_sql("'rental'", 'r.id');
    $types  = $wt . 'ss';
    $params = array_merge($wp, [$f['from'], $f['to']]);
    $catId  = (int) ($f['category_id'] ?? 0);
    if ($catId > 0) {
        $sql .= ' AND r.category_vehicle_id = ?';
        $types .= 'i';
        $params[] = $catId;
    }
    return ['sql' => $sql, 'types' => $types, 'params' => $params];
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
 * Sumber biaya: derived table z SARGable via UNION ALL (Travel + Rental + Orphan fallback)
 * mengecualikan cost_type = 'payment' dan booking 'cancelled'.
 */
function fin_cost_source(array $f): array
{
    $catId    = (int) ($f['category_id'] ?? 0);
    $btFilter = (string) ($f['booking_type'] ?? 'all');
    $parts    = [];
    $types    = '';
    $params   = [];

    if ($btFilter !== 'rental') {
        [$w, $wt, $wp] = fin_company_scope('tb.company_id', $f);
        $cond    = ($w !== '' ? $w . ' AND ' : '')
            . "tb.date BETWEEN ? AND ? AND z.cost_type <> 'payment' AND "
            . fin_not_cancelled_sql("'travel'", 'tb.id');
        $bTypes  = $wt . 'ss';
        $bParams = array_merge($wp, [$f['from'], $f['to']]);
        if ($catId > 0) {
            $cond     .= ' AND tb.category_vehicle_id = ?';
            $bTypes   .= 'i';
            $bParams[] = $catId;
        }
        $parts[] = "SELECT z.id, z.company_id, z.booking_type, z.booking_id, z.cost_type, z.employee_id, z.amount,
                           tb.date AS trip_date, COALESCE(tb.category_vehicle_id, 0) AS category_id
                      FROM travel_ticket_bookings tb
                      JOIN booking_cost_lines z
                        ON z.booking_type = 'travel' AND z.booking_id = tb.id AND z.company_id = tb.company_id
                     WHERE {$cond}";
        $types  .= $bTypes;
        $params  = array_merge($params, $bParams);
    }

    if ($btFilter !== 'travel') {
        [$w, $wt, $wp] = fin_company_scope('rb.company_id', $f);
        $cond    = ($w !== '' ? $w . ' AND ' : '')
            . "rb.start_date BETWEEN ? AND ? AND z.cost_type <> 'payment' AND "
            . fin_not_cancelled_sql("'rental'", 'rb.id');
        $bTypes  = $wt . 'ss';
        $bParams = array_merge($wp, [$f['from'], $f['to']]);
        if ($catId > 0) {
            $cond     .= ' AND rb.category_vehicle_id = ?';
            $bTypes   .= 'i';
            $bParams[] = $catId;
        }
        $parts[] = "SELECT z.id, z.company_id, z.booking_type, z.booking_id, z.cost_type, z.employee_id, z.amount,
                           rb.start_date AS trip_date, COALESCE(rb.category_vehicle_id, 0) AS category_id
                      FROM rental_car_bookings rb
                      JOIN booking_cost_lines z
                        ON z.booking_type = 'rental' AND z.booking_id = rb.id AND z.company_id = rb.company_id
                     WHERE {$cond}";
        $types  .= $bTypes;
        $params  = array_merge($params, $bParams);
    }

    // Fallback biaya yatim (tanpa header booking; diabaikan saat filter category_id > 0)
    if ($catId === 0) {
        [$w, $wt, $wp] = fin_company_scope('z.company_id', $f);
        $cond = ($w !== '' ? $w . ' AND ' : '')
            . "z.created_date >= ? AND z.created_date < DATE_ADD(?, INTERVAL 1 DAY)
               AND z.cost_type <> 'payment'
               AND tb.id IS NULL AND rb.id IS NULL AND "
            . fin_not_cancelled_sql('z.booking_type', 'z.booking_id');
        $bTypes  = $wt . 'ss';
        $bParams = array_merge($wp, [$f['from'], $f['to']]);
        if ($btFilter !== 'all') {
            $cond     .= ' AND z.booking_type = ?';
            $bTypes   .= 's';
            $bParams[] = $btFilter;
        }
        $parts[] = "SELECT z.id, z.company_id, z.booking_type, z.booking_id, z.cost_type, z.employee_id, z.amount,
                           DATE(z.created_date) AS trip_date, 0 AS category_id
                      FROM booking_cost_lines z
                      LEFT JOIN travel_ticket_bookings tb
                             ON z.booking_type = 'travel' AND tb.id = z.booking_id AND tb.company_id = z.company_id
                      LEFT JOIN rental_car_bookings rb
                             ON z.booking_type = 'rental' AND rb.id = z.booking_id AND rb.company_id = z.company_id
                     WHERE {$cond}";
        $types  .= $bTypes;
        $params  = array_merge($params, $bParams);
    }

    return ['sql' => '(' . implode(' UNION ALL ', $parts) . ') z', 'types' => $types, 'params' => $params];
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
            COALESCE(SUM(CASE WHEN t.direction = 'out' AND (t.source_type = 'crew_commission' OR LOWER(TRIM(t.category)) = 'biaya komisi karyawan') THEN t.amount ELSE 0 END), 0) AS out_commission,
            COALESCE(SUM(CASE WHEN t.direction = 'out' AND t.source_type = 'booking_bbm' THEN t.amount ELSE 0 END), 0) AS out_bbm,
            COALESCE(SUM(CASE WHEN t.direction = 'out' AND (t.source_type IS NULL OR t.source_type <> 'payroll_period') THEN t.amount ELSE 0 END), 0) AS out_other
        FROM cash_transactions t
        WHERE " . ($w !== '' ? $w . ' AND ' : '') . 't.date BETWEEN ? AND ?';
    $r = report_run($sql, $wt . 'ss', array_merge($wp, [$f['from'], $f['to']]))[0] ?? [];
    $inB    = (float) ($r['in_booking'] ?? 0);
    $inO    = (float) ($r['in_other'] ?? 0);
    $outP   = (float) ($r['out_payroll'] ?? 0);
    $outC   = (float) ($r['out_commission'] ?? 0);
    $outBbm = (float) ($r['out_bbm'] ?? 0);
    $outO   = (float) ($r['out_other'] ?? 0);
    $outTot = $outP + $outO;
    $outTripSynced = $outC + $outBbm;
    return [
        'in_booking'      => $inB,
        'in_other'        => $inO,
        'in_total'        => $inB + $inO,
        'out_payroll'     => $outP,
        'out_commission'  => $outC,
        'out_bbm'         => $outBbm,
        'out_trip_synced' => $outTripSynced,
        'out_other'       => $outO,
        'out_operating'   => max(0.0, $outTot - $outTripSynced),
        'out_total'       => $outTot,
        'net_cash_flow'   => ($inB + $inO) - $outTot,
    ];
}

/** Rincian pengeluaran Buku Kas per kategori dalam rentang filter. */
function fin_cash_expense_by_category(array $f): array
{
    [$w, $wt, $wp] = fin_company_scope('t.company_id', $f);
    $sql = "SELECT COALESCE(NULLIF(TRIM(t.category), ''), 'Tanpa Kategori') AS category,
                   COUNT(*) AS tx_count,
                   COALESCE(SUM(t.amount), 0) AS amount,
                   COALESCE(SUM(CASE WHEN t.source_type = 'crew_commission' OR LOWER(TRIM(t.category)) = 'biaya komisi karyawan' THEN t.amount ELSE 0 END), 0) AS commission_amount
              FROM cash_transactions t
             WHERE " . ($w !== '' ? $w . ' AND ' : '') . "t.direction = 'out' AND t.date BETWEEN ? AND ?
             GROUP BY category
             ORDER BY amount DESC, category ASC";
    $rows = report_run($sql, $wt . 'ss', array_merge($wp, [$f['from'], $f['to']]));
    $grand = 0.0;
    foreach ($rows as $r) {
        $grand += (float) ($r['amount'] ?? 0);
    }
    foreach ($rows as &$r) {
        $amt = (float) ($r['amount'] ?? 0);
        $r['tx_count']          = (int) ($r['tx_count'] ?? 0);
        $r['amount']            = $amt;
        $r['commission_amount'] = (float) ($r['commission_amount'] ?? 0);
        $r['share_pct']         = $grand > 0 ? round(($amt * 100) / $grand, 1) : 0.0;
    }
    unset($r);
    return $rows;
}

/** Total kolom <tfoot> tabel Pengeluaran Kas per Kategori. */
function fin_expense_category_totals(array $rows): array
{
    $t = ['tx_count' => 0, 'amount' => 0.0, 'commission_amount' => 0.0];
    foreach ($rows as $r) {
        $t['tx_count']          += (int) ($r['tx_count'] ?? 0);
        $t['amount']            += (float) ($r['amount'] ?? 0);
        $t['commission_amount'] += (float) ($r['commission_amount'] ?? 0);
    }
    return $t;
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

/** Pendapatan, terbayar, piutang, biaya operasional trip (+ BBM rental), laba kotor & margin per Kategori Layanan. */
function fin_by_category(array $f): array
{
    $map = [];
    $ensure = static function (string $bt, int $catId) use (&$map): string {
        $btNorm = $bt === 'rental' ? 'rental' : 'travel';
        $k = $btNorm . ':' . $catId;
        if (!isset($map[$k])) {
            $map[$k] = [
                'category_id'    => $catId,
                'booking_type'   => $btNorm,
                'service_label'  => $btNorm === 'rental' ? 'Rental' : 'Travel',
                'category_code'  => '',
                'category_name'  => '',
                'category_label' => 'Tanpa Kategori',
                'booking_count'  => 0,
                'revenue'        => 0.0,
                'paid'           => 0.0,
                'receivable'     => 0.0,
                'cost'           => 0.0,
                'gross_profit'   => 0.0,
                'margin_pct'     => 0.0,
            ];
        }
        return $k;
    };

    $j = fin_booking_source($f);
    $revRows = report_run(
        "SELECT COALESCE(x.category_vehicle_id, 0) AS category_id,
                x.booking_type,
                COUNT(*) AS booking_count,
                COALESCE(SUM(x.total_price), 0) AS revenue,
                COALESCE(SUM(x.total_payment), 0) AS paid,
                COALESCE(SUM(x.total_price - x.total_payment), 0) AS receivable
           FROM {$j['sql']}
          GROUP BY category_id, x.booking_type",
        $j['types'],
        $j['params']
    );
    foreach ($revRows as $rr) {
        $k = $ensure((string) $rr['booking_type'], (int) $rr['category_id']);
        $map[$k]['booking_count'] += (int) ($rr['booking_count'] ?? 0);
        $map[$k]['revenue']       += (float) ($rr['revenue'] ?? 0);
        $map[$k]['paid']          += (float) ($rr['paid'] ?? 0);
        $map[$k]['receivable']    += (float) ($rr['receivable'] ?? 0);
    }

    $cs = fin_cost_source($f);
    $costRows = report_run(
        "SELECT z.booking_type,
                z.category_id,
                COALESCE(SUM(z.amount), 0) AS cost_amt
           FROM {$cs['sql']}
          GROUP BY z.booking_type, z.category_id",
        $cs['types'],
        $cs['params']
    );
    foreach ($costRows as $cr) {
        $k = $ensure((string) $cr['booking_type'], (int) $cr['category_id']);
        $map[$k]['cost'] += (float) ($cr['cost_amt'] ?? 0);
    }

    $bbm = fin_rental_bbm_source($f);
    if ($bbm !== null) {
        $bbmRows = report_run(
            "SELECT COALESCE(r.category_vehicle_id, 0) AS category_id,
                    COALESCE(SUM(r.bbm), 0) AS cost_amt
               FROM {$bbm['sql']}
              GROUP BY category_id",
            $bbm['types'],
            $bbm['params']
        );
        foreach ($bbmRows as $br) {
            $k = $ensure('rental', (int) $br['category_id']);
            $map[$k]['cost'] += (float) ($br['cost_amt'] ?? 0);
        }
    }

    $catIds = [];
    foreach ($map as $row) {
        if ((int) $row['category_id'] > 0) {
            $catIds[(int) $row['category_id']] = (int) $row['category_id'];
        }
    }
    if ($catIds) {
        $catIdVals = array_values($catIds);
        $ph        = implode(',', array_fill(0, count($catIdVals), '?'));
        $catMeta   = [];
        $catRows   = report_run(
            "SELECT id, code, name FROM category_vehicles WHERE id IN ({$ph})",
            str_repeat('i', count($catIdVals)),
            $catIdVals
        );
        foreach ($catRows as $cm) {
            $catMeta[(int) $cm['id']] = $cm;
        }
        foreach ($map as &$row) {
            $cid = (int) $row['category_id'];
            if ($cid > 0 && isset($catMeta[$cid])) {
                $row['category_code']  = (string) ($catMeta[$cid]['code'] ?? '');
                $row['category_name']  = (string) ($catMeta[$cid]['name'] ?? '');
                $lbl = trim(($row['category_code'] !== '' ? $row['category_code'] . ' - ' : '') . $row['category_name']);
                $row['category_label'] = $lbl !== '' ? $lbl : ('Kategori #' . $cid);
            }
        }
        unset($row);
    }

    foreach ($map as &$row) {
        $row['gross_profit'] = (float) $row['revenue'] - (float) $row['cost'];
        $row['margin_pct']   = (float) $row['revenue'] > 0
            ? round(($row['gross_profit'] * 100) / (float) $row['revenue'], 1)
            : 0.0;
    }
    unset($row);

    $rows = array_values($map);
    usort($rows, static fn(array $a, array $b): int =>
        ((float) $b['revenue'] <=> (float) $a['revenue'])
        ?: ((float) $b['cost'] <=> (float) $a['cost'])
        ?: strcmp((string) $a['category_label'], (string) $b['category_label'])
    );
    return $rows;
}

/** Total kolom <tfoot> tabel Pendapatan & Laba per Kategori Layanan. */
function fin_category_totals(array $rows): array
{
    $t = [
        'booking_count' => 0,
        'revenue'       => 0.0,
        'paid'          => 0.0,
        'receivable'    => 0.0,
        'cost'          => 0.0,
        'gross_profit'  => 0.0,
        'margin_pct'    => 0.0,
    ];
    foreach ($rows as $r) {
        $t['booking_count'] += (int) ($r['booking_count'] ?? 0);
        $t['revenue']       += (float) ($r['revenue'] ?? 0);
        $t['paid']          += (float) ($r['paid'] ?? 0);
        $t['receivable']    += (float) ($r['receivable'] ?? 0);
        $t['cost']          += (float) ($r['cost'] ?? 0);
        $t['gross_profit']  += (float) ($r['gross_profit'] ?? 0);
    }
    $t['margin_pct'] = $t['revenue'] > 0 ? round(($t['gross_profit'] * 100) / $t['revenue'], 1) : 0.0;
    return $t;
}

/** Semua angka laporan keuangan sekaligus — halaman & export identik. */
function fin_all(array $f, ?int $unpaid_limit = 200): array
{
    $rev        = fin_revenue($f);
    $cost       = fin_cost_total($f);
    $gross      = $rev['total'] - $cost;
    $cash       = fin_cash_summary($f);
    $expCats    = fin_cash_expense_by_category($f);
    $inOther    = (float) ($cash['in_other'] ?? 0);
    $outOp      = (float) ($cash['out_operating'] ?? 0);
    $netProfit  = $gross + $inOther - $outOp;
    $baseRev    = (float) $rev['total'] + $inOther;
    $netMargin  = $baseRev > 0 ? round(($netProfit * 100) / $baseRev, 1) : 0.0;

    return [
        'revenue'            => $rev,
        'cost_total'         => $cost,
        'gross_profit'       => $gross,
        'net_profit'         => $netProfit,
        'net_summary'        => [
            'revenue_total'  => (float) $rev['total'],
            'trip_cost'      => $cost,
            'gross_profit'   => $gross,
            'in_other'       => $inOther,
            'out_operating'  => $outOp,
            'out_commission'  => (float) ($cash['out_commission'] ?? 0),
            'out_bbm'         => (float) ($cash['out_bbm'] ?? 0),
            'out_trip_synced' => (float) ($cash['out_trip_synced'] ?? 0),
            'net_profit'      => $netProfit,
            'net_margin_pct' => $netMargin,
        ],
        'cost_types'         => fin_cost_by_type($f),
        'expense_categories' => $expCats,
        'categories'         => fin_by_category($f),
        'unpaid'             => fin_unpaid($f, $unpaid_limit),
        'monthly'            => fin_monthly($f),
        'daily'              => fin_daily($f),
        'cash'               => $cash,
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
        "SELECT DATE_FORMAT(z.trip_date, '%Y-%m') AS ym, COALESCE(SUM(z.amount),0) AS total
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
            "SELECT DATE_FORMAT(r.start_date, '%Y-%m') AS ym, COALESCE(SUM(r.bbm),0) AS total
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

/**
 * Rekap harian pemasukan & pengeluaran dalam rentang filter, urut kronologis (ASC).
 * Menggabungkan Buku Kas (Kas Masuk / Kas Keluar nyata) & kinerja trip harian
 * (Omzet Booking, Biaya Trip termasuk BBM rental) dari sumber yang sama dengan kartu ringkasan.
 */
function fin_daily(array $f): array
{
    $map = [];
    $ensure = static function (string $d) use (&$map): void {
        if (!isset($map[$d])) {
            $map[$d] = [
                'date'          => $d,
                'in_booking'    => 0.0,
                'in_other'      => 0.0,
                'in_total'      => 0.0,
                'out_payroll'   => 0.0,
                'out_other'     => 0.0,
                'out_total'     => 0.0,
                'net_cash_flow' => 0.0,
                'booking_count' => 0,
                'booking_total' => 0.0,
                'booking_paid'  => 0.0,
                'trip_cost'     => 0.0,
                'gross_profit'  => 0.0,
            ];
        }
    };

    // 1. Arus kas harian dari Buku Kas & Bank (cash_transactions).
    [$w, $wt, $wp] = fin_company_scope('t.company_id', $f);
    $cashRows = report_run(
        "SELECT DATE_FORMAT(t.date, '%Y-%m-%d') AS d,
                COALESCE(SUM(CASE WHEN t.direction = 'in' AND t.source_type = 'booking_payment' THEN t.amount ELSE 0 END), 0) AS in_booking,
                COALESCE(SUM(CASE WHEN t.direction = 'in' AND (t.source_type IS NULL OR t.source_type <> 'booking_payment') THEN t.amount ELSE 0 END), 0) AS in_other,
                COALESCE(SUM(CASE WHEN t.direction = 'out' AND t.source_type = 'payroll_period' THEN t.amount ELSE 0 END), 0) AS out_payroll,
                COALESCE(SUM(CASE WHEN t.direction = 'out' AND (t.source_type IS NULL OR t.source_type <> 'payroll_period') THEN t.amount ELSE 0 END), 0) AS out_other
         FROM cash_transactions t
         WHERE " . ($w !== '' ? $w . ' AND ' : '') . "t.date BETWEEN ? AND ?
         GROUP BY d",
        $wt . 'ss',
        array_merge($wp, [$f['from'], $f['to']])
    );
    foreach ($cashRows as $cr) {
        $d = (string) ($cr['d'] ?? '');
        if ($d === '') {
            continue;
        }
        $ensure($d);
        $inB = (float) ($cr['in_booking'] ?? 0);
        $inO = (float) ($cr['in_other'] ?? 0);
        $outP = (float) ($cr['out_payroll'] ?? 0);
        $outO = (float) ($cr['out_other'] ?? 0);
        $map[$d]['in_booking']    = $inB;
        $map[$d]['in_other']      = $inO;
        $map[$d]['in_total']      = $inB + $inO;
        $map[$d]['out_payroll']   = $outP;
        $map[$d]['out_other']     = $outO;
        $map[$d]['out_total']     = $outP + $outO;
        $map[$d]['net_cash_flow'] = ($inB + $inO) - ($outP + $outO);
    }

    // 2. Omzet booking & penerimaan booking per tanggal booking.
    $bs = fin_booking_source($f);
    $revRows = report_run(
        "SELECT DATE_FORMAT(x.date, '%Y-%m-%d') AS d,
                COUNT(*) AS booking_count,
                COALESCE(SUM(x.total_price), 0) AS booking_total,
                COALESCE(SUM(x.total_payment), 0) AS booking_paid
         FROM {$bs['sql']}
         GROUP BY d",
        $bs['types'],
        $bs['params']
    );
    foreach ($revRows as $rr) {
        $d = (string) ($rr['d'] ?? '');
        if ($d === '') {
            continue;
        }
        $ensure($d);
        $map[$d]['booking_count'] = (int) ($rr['booking_count'] ?? 0);
        $map[$d]['booking_total'] = (float) ($rr['booking_total'] ?? 0);
        $map[$d]['booking_paid']  = (float) ($rr['booking_paid'] ?? 0);
    }

    // 3. Biaya operasional trip per tanggal berangkat/mulai sewa + BBM rental.
    $cs = fin_cost_source($f);
    $costRows = report_run(
        "SELECT DATE_FORMAT(z.trip_date, '%Y-%m-%d') AS d,
                COALESCE(SUM(z.amount), 0) AS total
         FROM {$cs['sql']}
         GROUP BY d",
        $cs['types'],
        $cs['params']
    );
    foreach ($costRows as $cr) {
        $d = (string) ($cr['d'] ?? '');
        if ($d === '') {
            continue;
        }
        $ensure($d);
        $map[$d]['trip_cost'] += (float) ($cr['total'] ?? 0);
    }
    $bbm = fin_rental_bbm_source($f);
    if ($bbm !== null) {
        $bbmRows = report_run(
            "SELECT DATE_FORMAT(r.start_date, '%Y-%m-%d') AS d,
                    COALESCE(SUM(r.bbm), 0) AS total
             FROM {$bbm['sql']}
             GROUP BY d",
            $bbm['types'],
            $bbm['params']
        );
        foreach ($bbmRows as $br) {
            $d = (string) ($br['d'] ?? '');
            if ($d === '') {
                continue;
            }
            $ensure($d);
            $map[$d]['trip_cost'] += (float) ($br['total'] ?? 0);
        }
    }

    ksort($map);
    foreach ($map as &$row) {
        $row['gross_profit'] = $row['booking_total'] - $row['trip_cost'];
    }
    unset($row);
    return array_values($map);
}

/** Total kolom untuk baris <tfoot> tabel Rekap Harian. */
function fin_daily_totals(array $rows): array
{
    $t = [
        'in_booking'    => 0.0,
        'in_other'      => 0.0,
        'in_total'      => 0.0,
        'out_payroll'   => 0.0,
        'out_other'     => 0.0,
        'out_total'     => 0.0,
        'net_cash_flow' => 0.0,
        'booking_count' => 0,
        'booking_total' => 0.0,
        'booking_paid'  => 0.0,
        'trip_cost'     => 0.0,
        'gross_profit'  => 0.0,
    ];
    foreach ($rows as $r) {
        $t['in_booking']    += (float) ($r['in_booking'] ?? 0);
        $t['in_other']      += (float) ($r['in_other'] ?? 0);
        $t['in_total']      += (float) ($r['in_total'] ?? 0);
        $t['out_payroll']   += (float) ($r['out_payroll'] ?? 0);
        $t['out_other']     += (float) ($r['out_other'] ?? 0);
        $t['out_total']     += (float) ($r['out_total'] ?? 0);
        $t['net_cash_flow'] += (float) ($r['net_cash_flow'] ?? 0);
        $t['booking_count'] += (int) ($r['booking_count'] ?? 0);
        $t['booking_total'] += (float) ($r['booking_total'] ?? 0);
        $t['booking_paid']  += (float) ($r['booking_paid'] ?? 0);
        $t['trip_cost']     += (float) ($r['trip_cost'] ?? 0);
        $t['gross_profit']  += (float) ($r['gross_profit'] ?? 0);
    }
    return $t;
}
