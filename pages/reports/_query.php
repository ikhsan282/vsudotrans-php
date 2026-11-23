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
require_once __DIR__ . '/../../includes/transport_helpers.php';

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
        'category_id'  => max(0, (int) ($in['category_id'] ?? 0)),
    ];
}

/**
 * Filter UI laporan: mewarisi report_filter() plus filter_company_id eksplisit
 * untuk dropdown Super Admin (tanpa mengubah kontrak report_filter() untuk test).
 */
function report_ui_filter(?array $in = null): array
{
    $src = $in ?? $_GET;
    $f   = report_filter($src);
    $scope = company_filter_scope('company_id', $src);
    $f['filter_company_id'] = (int) $scope['filter_company_id'];
    return $f;
}

/** Clause scoping tenant laporan (hormati filter_company_id Super Admin jika diset). */
function report_scope(string $column, array $f): array
{
    if (is_super_admin() && isset($f['filter_company_id'])) {
        $fcid = (int) $f['filter_company_id'];
        return $fcid > 0 ? ["{$column} = ?", 'i', [$fcid]] : ['1=1', '', []];
    }
    return company_scope($column);
}

/** Bangun query string export standar laporan + category_id + company_id bila diset Super Admin. */
function report_export_qs(array $f, string $format = 'csv'): string
{
    $p = [
        'format'       => $format,
        'from'         => $f['from'],
        'to'           => $f['to'],
        'booking_type' => $f['booking_type'],
    ];
    if (!empty($f['category_id']) && (int) $f['category_id'] > 0) {
        $p['category_id'] = (int) $f['category_id'];
    }
    if (is_super_admin() && isset($f['filter_company_id'])) {
        $p['company_id'] = (int) $f['filter_company_id'];
    }
    return http_build_query($p);
}

/** Tombol aksi header standar export (dropdown Excel/CSV/PDF + Cetak) untuk halaman laporan. */
function report_header_actions(string $export_script, array $f): array
{
    $csv_qs  = report_export_qs($f, 'csv');
    $xlsx_qs = report_export_qs($f, 'xlsx');
    return [
        [
            'label' => 'Export',
            'icon'  => 'download',
            'class' => 'btn btn-outline-secondary btn-sm dropdown-toggle',
            'items' => [
                [
                    'label'      => 'Excel (.xlsx)',
                    'icon'       => 'file-earmark-excel',
                    'icon_class' => 'text-success',
                    'href'       => $export_script . '?' . $xlsx_qs,
                ],
                [
                    'label'      => 'CSV (.csv)',
                    'icon'       => 'filetype-csv',
                    'icon_class' => 'text-success',
                    'href'       => $export_script . '?' . $csv_qs,
                ],
                [
                    'label'      => 'PDF (.pdf)',
                    'icon'       => 'filetype-pdf',
                    'icon_class' => 'text-danger',
                    'href'       => $export_script . '?' . $csv_qs . '&format=pdf',
                ],
            ],
        ],
        [
            'label'  => 'Cetak',
            'icon'   => 'printer',
            'href'   => $export_script . '?' . $csv_qs . '&format=html&print=1',
            'class'  => 'btn btn-outline-secondary btn-sm',
            'target' => '_blank',
        ],
    ];
}

/** Subtitle periode + nama perusahaan + kategori layanan bila difilter. */
function report_period_subtitle(array $f): string
{
    $sub = 'Periode ' . $f['from'] . ' s/d ' . $f['to'];
    if (is_super_admin() && !empty($f['filter_company_id'])) {
        foreach (accessible_companies() as $co) {
            if ((int) ($co['id'] ?? 0) === (int) $f['filter_company_id']) {
                $sub .= ' (' . ($co['code'] ?? '') . ' - ' . ($co['name'] ?? '') . ')';
                break;
            }
        }
    }
    $catId = (int) ($f['category_id'] ?? 0);
    if ($catId > 0) {
        $stCat = db()->prepare('SELECT code, name FROM category_vehicles WHERE id = ? LIMIT 1');
        $stCat->bind_param('i', $catId);
        $stCat->execute();
        $catRow = $stCat->get_result()->fetch_assoc();
        $stCat->close();
        if ($catRow) {
            $lbl = trim(($catRow['code'] ?? '') . ' - ' . ($catRow['name'] ?? ''));
            if ($lbl !== '') {
                $sub .= ' · Kategori: ' . $lbl;
            }
        }
    }
    return $sub;
}

/** Daftar preset periode cepat standar laporan. */
function report_period_presets(): array
{
    $today = date('Y-m-d');
    $dow   = (int) date('N');
    $mon   = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
    $sun   = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days'));
    return [
        'today'   => ['label' => 'Hari Ini',   'from' => $today, 'to' => $today],
        'week'    => ['label' => 'Minggu Ini', 'from' => $mon,   'to' => $sun],
        'month'   => ['label' => 'Bulan Ini',  'from' => date('Y-m-01'), 'to' => date('Y-m-t')],
        'prev_mo' => [
            'label' => 'Bulan Lalu',
            'from'  => date('Y-m-01', strtotime('first day of last month')),
            'to'    => date('Y-m-t', strtotime('last day of last month')),
        ],
        '90d'     => ['label' => '90 Hari', 'from' => date('Y-m-d', strtotime('-89 days')), 'to' => $today],
    ];
}

/** Render bar preset periode cepat yang mempertahankan filter lain. */
function report_preset_pills_html(string $basePath, array $f): string
{
    $html = '<div class="rpt-preset-bar d-flex flex-wrap align-items-center gap-1 mt-2 pt-2 border-top">';
    $html .= '<span class="small text-muted me-1"><i class="bi bi-calendar-range me-1"></i>Periode Cepat:</span>';
    foreach (report_period_presets() as $key => $p) {
        $isAct = ($f['from'] === $p['from'] && $f['to'] === $p['to']);
        $q = [
            'from'         => $p['from'],
            'to'           => $p['to'],
            'booking_type' => $f['booking_type'] ?? 'all',
        ];
        if (!empty($f['category_id']) && (int) $f['category_id'] > 0) {
            $q['category_id'] = (int) $f['category_id'];
        }
        if (is_super_admin() && isset($f['filter_company_id'])) {
            $q['company_id'] = (int) $f['filter_company_id'];
        }
        $href = APP_URL . '/' . ltrim($basePath, '/') . '?' . http_build_query($q);
        $cls  = 'btn btn-sm rpt-preset-pill ' . ($isAct ? 'btn-primary active' : 'btn-outline-secondary');
        $html .= '<a href="' . e($href) . '" data-preset="' . e($key) . '" class="' . e($cls) . '">' . e($p['label']) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/** Ambil daftar kategori layanan ter-scope tenant + jenis booking. */
function report_category_options(array $f): array
{
    category_service_ensure_schema();
    [$w, $wt, $wp] = report_scope('cv.company_id', $f);
    $sql = "SELECT cv.id, cv.code, cv.name, cv.service_type, c.code AS comp_code
              FROM category_vehicles cv
              JOIN companies c ON c.id = cv.company_id
             WHERE " . ($w !== '' ? $w . ' AND ' : '') . 'cv.is_active = 1';
    $types  = $wt;
    $params = $wp;
    $bt = (string) ($f['booking_type'] ?? 'all');
    if (in_array($bt, ['travel', 'rental'], true)) {
        $sql .= ' AND cv.service_type = ?';
        $types .= 's';
        $params[] = $bt;
    }
    $sql .= ' ORDER BY c.code ASC, cv.service_type ASC, cv.name ASC';
    return report_run($sql, $types, $params);
}

/** Render kolom filter Kategori Layanan agar seragam di halaman Laporan. */
function report_category_filter_col(array $f): string
{
    $sel       = (int) ($f['category_id'] ?? 0);
    $cats      = report_category_options($f);
    $multiComp = is_super_admin() && empty($f['filter_company_id']);
    $html      = '<div class="col-12 col-sm-6 col-lg-2">'
        . '<label class="form-label small mb-1">Kategori Layanan</label>'
        . '<select class="form-select form-select-sm" name="category_id">'
        . '<option value="0"' . ($sel === 0 ? ' selected' : '') . '>Semua Kategori</option>';
    foreach ($cats as $cv) {
        $cvId  = (int) $cv['id'];
        $srv   = ($cv['service_type'] ?? 'travel') === 'rental' ? 'Rental' : 'Travel';
        $label = trim(($cv['code'] ?? '') . ' - ' . ($cv['name'] ?? '')) . ' (' . $srv . ')';
        if ($multiComp && !empty($cv['comp_code'])) {
            $label = '[' . $cv['comp_code'] . '] ' . $label;
        }
        $html .= '<option value="' . $cvId . '"' . ($sel === $cvId ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select></div>';
}

/** Render kolom filter Perusahaan khusus Super Admin agar seragam di semua halaman Laporan. */
function report_company_filter_col(array $f): string
{
    if (!is_super_admin()) {
        return '';
    }
    $sel = (int) ($f['filter_company_id'] ?? 0);
    $html = '<div class="col-12 col-sm-6 col-lg-3">'
        . '<label class="form-label small mb-1">Perusahaan</label>'
        . '<select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">'
        . '<option value="0"' . ($sel === 0 ? ' selected' : '') . '>Semua Perusahaan</option>';
    foreach (accessible_companies() as $co) {
        $cid = (int) ($co['id'] ?? 0);
        $lbl = trim(($co['code'] ?? '') . ' - ' . ($co['name'] ?? ''));
        $html .= '<option value="' . $cid . '"' . ($sel === $cid ? ' selected' : '') . '>' . e($lbl) . '</option>';
    }
    return $html . '</select></div>';
}

/** Header download dual-form (ASCII fallback + filename* UTF-8). */
function report_send_download_headers(string $base, string $ext, string $mime): void
{
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
    $ascii = str_replace('"', '', (string) $ascii);
    $utf8  = rawurlencode($base . '.' . $ext);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii . '.' . $ext . '"; filename*=UTF-8\'\'' . $utf8);
    header('Cache-Control: no-store');
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
    [$w, $wt, $wp] = report_scope('b.company_id', $f);
    $inner_where = ($w !== '' ? $w . ' AND ' : '') . 'b.date BETWEEN ? AND ?';
    $branch_types = $wt . 'ss';
    $branch_params = array_merge($wp, [$f['from'], $f['to']]);

    $catId = (int) ($f['category_id'] ?? 0);
    if ($catId > 0) {
        $inner_where .= ' AND b.category_vehicle_id = ?';
        $branch_types .= 'i';
        $branch_params[] = $catId;
    }

    $cols = static fn(string $table, string $type) => "SELECT '{$type}' AS booking_type, b.id, b.company_id,
            b.branch_id, b.date, b.time, b.total_price, b.total_payment, b.document_number,
            b.passenger_id, b.category_vehicle_id, b.vehicle_id, b.pickup_point_city_id AS origin_city_id,
            b.delivery_point_city_id AS dest_city_id
        FROM {$table} b
        WHERE {$inner_where}";

    $parts = [];
    $types = '';
    $params = [];
    if ($f['booking_type'] !== 'rental') {
        $parts[] = $cols('travel_ticket_bookings', 'travel');
        $types .= $branch_types;
        $params = array_merge($params, $branch_params);
    }
    if ($f['booking_type'] !== 'travel') {
        $parts[] = $cols('rental_car_bookings', 'rental');
        $types .= $branch_types;
        $params = array_merge($params, $branch_params);
    }

    $sql = '(' . implode(' UNION ALL ', $parts) . ') x';
    return ['sql' => $sql, 'types' => $types, 'params' => $params];
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

/** Ekspresi agregat yang sama untuk semua dimensi.
 *  travel_price/rental_price dipakai laporan Keuangan (pendapatan per jenis). */
function report_agg(): string
{
    return "COUNT(*) AS total_bookings,
        COALESCE(SUM(x.total_price),0) AS total_price,
        COALESCE(SUM(x.total_payment),0) AS total_payment,
        COALESCE(SUM(x.total_price - x.total_payment),0) AS total_receivable,
        COALESCE(SUM(x.total_price * (x.booking_type = 'travel')),0) AS travel_price,
        COALESCE(SUM(x.total_price * (x.booking_type = 'rental')),0) AS rental_price";
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

/** Per kategori layanan (booking tanpa kategori → label 'Tanpa Kategori'). */
function report_by_category(array $f): array
{
    $j = report_joined($f);
    $rows = report_run(
        "SELECT COALESCE(x.category_vehicle_id, 0) AS category_id,
                x.booking_type,
                COALESCE(cv.code, '') AS category_code,
                COALESCE(cv.name, '') AS category_name, "
            . report_agg() . "
           FROM {$j['sql']}
           LEFT JOIN category_vehicles cv ON cv.id = x.category_vehicle_id
          GROUP BY category_id, x.booking_type, category_code, category_name
          ORDER BY total_price DESC, total_bookings DESC, category_name ASC",
        $j['types'],
        $j['params']
    );
    foreach ($rows as &$r) {
        $lbl = trim(($r['category_code'] !== '' ? $r['category_code'] . ' - ' : '') . $r['category_name']);
        $r['category_label'] = $lbl !== '' ? $lbl : 'Tanpa Kategori';
        $r['service_label']  = $r['booking_type'] === 'rental' ? 'Rental' : 'Travel';
    }
    unset($r);
    return $rows;
}

/** Lima dimensi sekaligus — dipakai halaman & export agar angka identik. */
function report_all(array $f): array
{
    return [
        'summary'  => report_summary($f),
        'category' => report_by_category($f),
        'period'   => report_by_period($f),
        'route'    => report_by_route($f),
        'vehicle'  => report_by_vehicle($f),
        'driver'   => report_by_driver($f),
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
