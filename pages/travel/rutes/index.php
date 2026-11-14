<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

$scope = company_filter_scope('r.company_id');
$filter_company_id = $scope['filter_company_id'];
$where             = $scope['where'];
$types             = $scope['types'];
$params            = $scope['params'];
$companies         = $scope['companies'];
$active_cid        = $scope['active_cid'];

rute_ensure_schema();

$q                  = trim((string) ($_GET['q'] ?? ''));
$status             = trim((string) ($_GET['status'] ?? ''));
$filter_pickup_city = max(0, (int) ($_GET['pickup_city_id'] ?? 0));
$filter_deliv_city  = max(0, (int) ($_GET['delivery_city_id'] ?? 0));
$filter_category_id = max(0, (int) ($_GET['category_vehicle_id'] ?? 0));

if ($q !== '') {
    $q_like = '%' . $q . '%';
    $where .= ' AND (r.code LIKE ? OR r.name LIKE ? OR pc.name LIKE ? OR pp.name LIKE ? OR dc.name LIKE ? OR dp.name LIKE ?
                     OR EXISTS (SELECT 1 FROM rute_tariffs rt2 JOIN category_vehicles cv2 ON cv2.id = rt2.category_vehicle_id
                                 WHERE rt2.rute_id = r.id AND (cv2.name LIKE ? OR cv2.code LIKE ?))
                     OR EXISTS (SELECT 1 FROM rute_radius_districts rr2 JOIN districts d2 ON d2.id = rr2.district_id
                                 WHERE rr2.rute_id = r.id AND d2.name LIKE ?))';
    $types .= 'sssssssss';
    array_push($params, $q_like, $q_like, $q_like, $q_like, $q_like, $q_like, $q_like, $q_like, $q_like);
}
if ($status === '1' || $status === '0') {
    $where .= ' AND r.is_active = ?';
    $types .= 'i';
    $params[] = (int) $status;
}
if ($filter_pickup_city > 0) {
    $where .= ' AND r.pickup_point_city_id = ?';
    $types .= 'i';
    $params[] = $filter_pickup_city;
}
if ($filter_deliv_city > 0) {
    $where .= ' AND r.delivery_point_city_id = ?';
    $types .= 'i';
    $params[] = $filter_deliv_city;
}
if ($filter_category_id > 0) {
    $where .= ' AND (r.category_vehicle_id = ? OR EXISTS (SELECT 1 FROM rute_tariffs rt_f WHERE rt_f.rute_id = r.id AND rt_f.category_vehicle_id = ?))';
    $types .= 'ii';
    $params[] = $filter_category_id;
    $params[] = $filter_category_id;
}

$st = db()->prepare("SELECT COUNT(*) FROM rutes r
                     JOIN cities pc ON pc.id = r.pickup_point_city_id
                     LEFT JOIN provinces pp ON pp.id = pc.province_id
                     JOIN cities dc ON dc.id = r.delivery_point_city_id
                     LEFT JOIN provinces dp ON dp.id = dc.province_id
                     WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $isPdf = $export_format === 'pdf';
    $sql_export = "SELECT r.code, pc.name AS pickup, pp.name AS pickup_province,
                          dc.name AS delivery, dp.name AS delivery_province,
                          (SELECT GROUP_CONCAT(
                                    CONCAT(
                                        cv.name, '~|~', rt.price, '~|~',
                                        COALESCE(NULLIF(rt.seat_capacity, 0), rt.end_capacity_seat, 0)
                                    )
                                    ORDER BY rt.sort_order ASC, rt.id ASC SEPARATOR '~;~')
                             FROM rute_tariffs rt
                             JOIN category_vehicles cv ON cv.id = rt.category_vehicle_id
                            WHERE rt.rute_id = r.id) AS tariffs_raw,
                          (SELECT GROUP_CONCAT(rs.departure_time ORDER BY rs.departure_time ASC SEPARATOR ', ')
                             FROM rute_schedules rs WHERE rs.rute_id = r.id) AS schedules_csv,
                          (SELECT GROUP_CONCAT(d.name ORDER BY rr.sort_order ASC, d.name ASC SEPARATOR '~;~')
                             FROM rute_radius_districts rr JOIN districts d ON d.id = rr.district_id
                            WHERE rr.rute_id = r.id AND rr.radius_type = 'pickup') AS pickup_radius_raw,
                          (SELECT GROUP_CONCAT(d.name ORDER BY rr.sort_order ASC, d.name ASC SEPARATOR '~;~')
                             FROM rute_radius_districts rr JOIN districts d ON d.id = rr.district_id
                            WHERE rr.rute_id = r.id AND rr.radius_type = 'delivery') AS delivery_radius_raw,
                          r.pickup_free_radius_km, r.pickup_max_radius_km, r.pickup_extra_price_per_km,
                          r.delivery_free_radius_km, r.delivery_max_radius_km, r.delivery_extra_price_per_km,
                          r.price, COALESCE(NULLIF(r.seat_capacity, 0), r.end_capacity_seat, 0) AS seat_capacity,
                          cv_def.name AS def_cat_name, r.is_active
                   FROM rutes r
                   JOIN cities pc ON pc.id = r.pickup_point_city_id
                   LEFT JOIN provinces pp ON pp.id = pc.province_id
                   JOIN cities dc ON dc.id = r.delivery_point_city_id
                   LEFT JOIN provinces dp ON dp.id = dc.province_id
                   LEFT JOIN category_vehicles cv_def ON cv_def.id = r.category_vehicle_id
                   WHERE {$where} ORDER BY r.code";
    $max_export_rows = $isPdf ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r) use ($isPdf): array {
            $asal   = rute_format_loc_cell((string) $r['pickup'], (string) ($r['pickup_province'] ?? ''), $isPdf);
            $tujuan = rute_format_loc_cell((string) $r['delivery'], (string) ($r['delivery_province'] ?? ''), $isPdf);
            $jadwal = rute_format_schedules_text((string) ($r['schedules_csv'] ?? ''), $isPdf ? RUTE_GRID_MAX_VISIBLE_SLOTS : 0);
            $pRad   = rute_format_district_names_export((string) ($r['pickup_radius_raw'] ?? ''), (float) $r['pickup_free_radius_km'], (float) $r['pickup_max_radius_km'], (float) $r['pickup_extra_price_per_km'], $isPdf);
            $dRad   = rute_format_district_names_export((string) ($r['delivery_radius_raw'] ?? ''), (float) $r['delivery_free_radius_km'], (float) $r['delivery_max_radius_km'], (float) $r['delivery_extra_price_per_km'], $isPdf);
            $harga  = rute_format_tariffs_export_cell($r, $isPdf ? "\n" : '; ');
            $status = $r['is_active'] ? 'Aktif' : 'Nonaktif';

            if ($isPdf) {
                $radLines = [];
                if ($pRad !== '-') {
                    $radLines[] = 'Jemput: ' . $pRad;
                }
                if ($dRad !== '-') {
                    $radLines[] = 'Antar: ' . $dRad;
                }
                return [
                    $r['code'],
                    $asal,
                    $tujuan,
                    $jadwal,
                    $radLines ? implode("\n", $radLines) : '-',
                    $harga,
                    $status,
                ];
            }

            return [
                $r['code'],
                $asal,
                $tujuan,
                $jadwal,
                $pRad,
                $dRad,
                $harga,
                $status,
            ];
        },
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    $headers = $isPdf
        ? ['Kode', 'Asal', 'Tujuan', 'Jadwal Keberangkatan', 'Radius Jemput / Antar', 'Daftar Harga', 'Status']
        : ['Kode', 'Asal', 'Tujuan', 'Jadwal Keberangkatan', 'Radius Jemput', 'Radius Antar', 'Daftar Harga', 'Status'];
    grid_export_send(
        'Daftar Rute Travel',
        $headers,
        $row_stream,
        $export_format,
        'landscape',
        $sub,
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT r.id, r.code, r.name, r.price, r.start_capacity_seat, r.end_capacity_seat, r.seat_capacity, r.is_active,
                            pc.name AS pickup, pp.name AS pickup_province,
                            dc.name AS delivery, dp.name AS delivery_province,
                            cv.name AS def_cat_name,
                            r.pickup_free_radius_km, r.pickup_max_radius_km, r.pickup_extra_price_per_km,
                            r.delivery_free_radius_km, r.delivery_max_radius_km, r.delivery_extra_price_per_km
                     FROM rutes r
                     JOIN cities pc ON pc.id = r.pickup_point_city_id
                     LEFT JOIN provinces pp ON pp.id = pc.province_id
                     JOIN cities dc ON dc.id = r.delivery_point_city_id
                     LEFT JOIN provinces dp ON dp.id = dc.province_id
                     LEFT JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
                     WHERE {$where} ORDER BY r.code LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$ruteIds         = array_column($rows, 'id');
$ruteScheduleMap = rute_schedules_by_rute_ids($ruteIds);
$ruteTariffMap   = rute_tariffs_by_rute_ids($ruteIds);
$ruteRadiusMap   = rute_radius_by_rute_ids($ruteIds);

$pickupCityOpts = opts_cities(null, $filter_pickup_city > 0 ? [$filter_pickup_city] : [], 50, true);
$delivCityOpts  = opts_cities(null, $filter_deliv_city > 0 ? [$filter_deliv_city] : [], 50, true);

$catFilterOpts = [];
if ($filter_company_id > 0) {
    $catFilterOpts = array_map(
        static fn (array $cv): array => ['id' => (int) $cv['id'], 'label' => (string) ($cv['name'] ?? '')],
        opts_categories($filter_company_id, $filter_category_id > 0 ? [$filter_category_id] : [], 200)
    );
} else {
    $allowedCids = accessible_company_ids($active_cid ?? 0);
    if ($allowedCids) {
        $phCat = implode(',', array_fill(0, count($allowedCids), '?'));
        $stCat = db()->prepare(
            "SELECT cv.id, CONCAT(c.code, ' | ', cv.code, ' - ', cv.name) AS label
               FROM category_vehicles cv
               LEFT JOIN companies c ON c.id = cv.company_id
              WHERE cv.company_id IN ({$phCat})
                AND (cv.is_active = 1 OR cv.id = ?)
              ORDER BY c.code, cv.code, cv.name
              LIMIT 200"
        );
        $stCat->bind_param(str_repeat('i', count($allowedCids)) . 'i', ...[...$allowedCids, $filter_category_id]);
        $stCat->execute();
        $catFilterOpts = $stCat->get_result()->fetch_all(MYSQLI_ASSOC);
        $stCat->close();
    }
}

$header_actions = ui_export_actions();
if (can('travel.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/rutes/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$has_explicit_company = isset($_GET['company_id']);
$query_base = http_build_query(array_filter(
    [
        'q'                   => $q,
        'status'              => $status,
        'pickup_city_id'      => $filter_pickup_city > 0 ? (string) $filter_pickup_city : null,
        'delivery_city_id'    => $filter_deliv_city > 0 ? (string) $filter_deliv_city : null,
        'category_vehicle_id' => $filter_category_id > 0 ? (string) $filter_category_id : null,
        'company_id'          => $has_explicit_company ? (string) $filter_company_id : null,
    ],
    static fn($v) => $v !== null && $v !== ''
));
$return_query = $query_base . ($p['current'] > 1 ? (($query_base !== '' ? '&' : '') . 'page=' . $p['current']) : '');
$has_active_filter = ($q !== '' || $status !== '' || $filter_pickup_city > 0 || $filter_deliv_city > 0 || $filter_category_id > 0 || ($has_explicit_company && $filter_company_id !== ($active_cid ?? 0)));

$active = 'travel';
$title  = 'Rute';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Rute Travel',
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Rute' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Rute', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" id="rutesFilterForm" class="row g-2 align-items-center rutes-filter-bar">
                <div class="col-12 col-sm-6 col-md-4 rutes-filter-q">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari kode, rute, kota, kec..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-md-4 rutes-filter-item">
                    <select name="company_id" class="form-select form-select-sm" onchange="var f=this.form,c=f.querySelector('[name=category_vehicle_id]');if(c)c.value='';f.submit()" title="Pilih Perusahaan">
                        <?= ui_company_filter_options($companies, $filter_company_id, $has_explicit_company) ?>
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-md-4 rutes-filter-item">
                    <select name="category_vehicle_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Kategori Kendaraan">
                        <option value="">Semua Kategori Kendaraan</option>
                        <?php foreach ($catFilterOpts as $cv): ?>
                            <option value="<?= (int) $cv['id'] ?>" <?= ((int) $cv['id'] === $filter_category_id) ? 'selected' : '' ?>><?= e($cv['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-4 rutes-filter-item">
                    <select name="pickup_city_id" id="filterRutePickupCity" class="form-select form-select-sm ts-select"
                        data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                        data-ts-with-province="1"
                        data-ts-limit="50"
                        data-ts-placeholder="Semua Kota Asal">
                        <option value="">Semua Kota Asal</option>
                        <?php foreach ($pickupCityOpts as $ct): ?>
                            <option value="<?= (int) $ct['id'] ?>" <?= ((int) $ct['id'] === $filter_pickup_city) ? 'selected' : '' ?>><?= e($ct['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-4 rutes-filter-item">
                    <select name="delivery_city_id" id="filterRuteDeliveryCity" class="form-select form-select-sm ts-select"
                        data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                        data-ts-with-province="1"
                        data-ts-limit="50"
                        data-ts-placeholder="Semua Kota Tujuan">
                        <option value="">Semua Kota Tujuan</option>
                        <?php foreach ($delivCityOpts as $ct): ?>
                            <option value="<?= (int) $ct['id'] ?>" <?= ((int) $ct['id'] === $filter_deliv_city) ? 'selected' : '' ?>><?= e($ct['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-md-2 rutes-filter-status">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Status">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/travel/rutes/index.php', $has_active_filter, 'col-auto d-flex gap-1 ms-auto rutes-filter-actions erp-filter-actions') ?>
            </form>
        </div>
    </div>
    <div id="ruteIndexStatusFeedback" class="alert d-none mb-2 py-2 px-3 small" role="alert"></div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">#</th><th>Kode</th><th>Asal</th><th>Tujuan</th><th>Jadwal Keberangkatan</th><th>Radius Jemput / Antar</th><th>Daftar Harga</th><th class="text-center text-nowrap" style="width: 68px;">Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r):
                    $slots   = $ruteScheduleMap[(int) $r['id']] ?? [];
                    $tariffs = $ruteTariffMap[(int) $r['id']] ?? [];
                    if (!$tariffs && (float) ($r['price'] ?? 0) > 0) {
                        $sc = (int) (($r['seat_capacity'] ?? 0) > 0 ? $r['seat_capacity'] : ($r['end_capacity_seat'] ?? 0));
                        $tariffs = [[
                            'category_name'       => (string) ($r['def_cat_name'] ?? 'Standar'),
                            'price'               => (float) $r['price'],
                            'seat_capacity'       => $sc,
                            'start_capacity_seat' => $sc > 0 ? 1 : 0,
                            'end_capacity_seat'   => $sc,
                        ]];
                    }
                    $rRad   = $ruteRadiusMap[(int) $r['id']] ?? ['pickup' => [], 'delivery' => []];
                    $pRad   = rute_format_district_radius_summary($rRad['pickup'], (float) $r['pickup_free_radius_km'], (float) $r['pickup_max_radius_km'], (float) $r['pickup_extra_price_per_km'], false, false, false);
                    $dRad   = rute_format_district_radius_summary($rRad['delivery'], (float) $r['delivery_free_radius_km'], (float) $r['delivery_max_radius_km'], (float) $r['delivery_extra_price_per_km'], false, false, false);
                    $pTip   = $rRad['pickup'] ? rute_format_district_radius_summary($rRad['pickup'], 0, 0, 0, true, false, false) : '';
                    $dTip   = $rRad['delivery'] ? rute_format_district_radius_summary($rRad['delivery'], 0, 0, 0, true, false, false) : '';
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="fw-semibold rute-code-cell" title="<?= e($r['name']) ?>"><?= e($r['code']) ?></td>
                        <?php foreach ([[$r['pickup'], (string) ($r['pickup_province'] ?? '')], [$r['delivery'], (string) ($r['delivery_province'] ?? '')]] as [$locCity, $locProv]): ?>
                            <td>
                                <?php if (trim($locProv) !== ''): ?>
                                    <div class="rute-province-name"><?= e($locProv) ?></div>
                                    <div class="text-muted rute-city-name"><?= e($locCity) ?></div>
                                <?php else: ?>
                                    <div class="rute-province-name"><?= e($locCity) ?></div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($slots):
                                $visibleSlots   = array_slice($slots, 0, RUTE_GRID_MAX_VISIBLE_SLOTS);
                                $extraSlotCount = count($slots) - count($visibleSlots);
                            ?>
                                <div class="d-flex flex-wrap gap-1 rute-slots-wrap"<?= $extraSlotCount > 0 ? ' title="' . e(implode(', ', $slots)) . '"' : '' ?>>
                                    <?php foreach ($visibleSlots as $slot): ?>
                                        <span class="badge rute-slot-badge bg-info-subtle text-info-emphasis border font-monospace"><?= e($slot) ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($extraSlotCount > 0): ?>
                                        <span class="badge rute-slot-badge rute-slot-more bg-light text-muted border">+<?= $extraSlotCount ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($pRad === '-' && $dRad === '-'): ?>
                                <span class="text-muted">-</span>
                            <?php else: ?>
                                <?php if ($pRad !== '-'): ?><div<?= $pTip !== '' ? ' title="' . e($pTip) . '"' : '' ?>><span class="text-muted">Jemput:</span> <?= e($pRad) ?></div><?php endif; ?>
                                <?php if ($dRad !== '-'): ?><div<?= $dTip !== '' ? ' title="' . e($dTip) . '"' : '' ?>><span class="text-muted">Antar:</span> <?= e($dRad) ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($tariffs): ?>
                                <div class="d-flex flex-column gap-1">
                                    <?php foreach ($tariffs as $tf):
                                        $seatCap = (int) (($tf['seat_capacity'] ?? 0) > 0 ? $tf['seat_capacity'] : ($tf['end_capacity_seat'] ?? 0));
                                    ?>
                                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                                            <span>
                                                <span class="fw-medium"><?= e($tf['category_name']) ?></span>
                                                <?php if ($seatCap > 0): ?><span class="text-muted">(<?= $seatCap ?> seat)</span><?php endif; ?>
                                            </span>
                                            <span class="fw-semibold text-nowrap"><?= e(format_nominal((float) $tf['price'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php $isAct = !empty($r['is_active']); ?>
                            <?php if (can('travel.manage')): ?>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/travel/rutes/toggle_status.php" class="d-inline rute-status-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="return_query" value="<?= e($return_query) ?>">
                                    <button type="submit"
                                        class="btn btn-link p-0 border-0 shadow-none text-decoration-none rute-toggle-status <?= $isAct ? 'text-success' : 'text-danger' ?>"
                                        data-active="<?= $isAct ? '1' : '0' ?>"
                                        title="<?= $isAct ? 'Aktif — klik untuk nonaktifkan' : 'Nonaktif — klik untuk aktifkan' ?>"
                                        aria-label="<?= $isAct ? 'Aktif' : 'Nonaktif' ?>">
                                        <i class="bi <?= $isAct ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?> fs-6"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="<?= $isAct ? 'text-success' : 'text-danger' ?> fs-6" title="<?= $isAct ? 'Aktif' : 'Nonaktif' ?>" aria-label="<?= $isAct ? 'Aktif' : 'Nonaktif' ?>"><i class="bi <?= $isAct ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (can('travel.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/rutes/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/travel/rutes/delete.php" class="d-inline" onsubmit="return confirm('Hapus rute ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada data rute.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small text-muted">
                <?php if ($total > 0): ?>
                    Menampilkan <strong><?= number_format($p['offset'] + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($total, $p['offset'] + count($rows)), 0, ',', '.') ?></strong> dari <strong><?= number_format($total, 0, ',', '.') ?></strong> data
                <?php else: ?>
                    Total <strong>0</strong> data
                <?php endif; ?>
            </div>
            <?= pagination_html($p, $query_base) ?>
        </div>
    </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('rutesFilterForm');
    if (filterForm && typeof window.bindTsChange === 'function') {
        ['filterRutePickupCity', 'filterRuteDeliveryCity'].forEach(function (elId) {
            const el = document.getElementById(elId);
            if (el) {
                window.bindTsChange(el, function () {
                    filterForm.submit();
                });
            }
        });
    }

    const feedbackEl = document.getElementById('ruteIndexStatusFeedback');
    let feedbackTimer = null;
    function showFeedback(ok, msg) {
        if (!feedbackEl || !msg) return;
        feedbackEl.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' mb-2 py-2 px-3 small';
        feedbackEl.textContent = msg;
        if (feedbackTimer) clearTimeout(feedbackTimer);
        feedbackTimer = setTimeout(function () {
            feedbackEl.classList.add('d-none');
        }, 3500);
    }

    document.querySelectorAll('.rute-status-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = form.querySelector('.rute-toggle-status');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (resp) {
                    return resp.json().catch(function () { return null; }).then(function (data) {
                        return { ok: resp.ok, data: data };
                    });
                })
                .then(function (res) {
                    if (!res.ok || !res.data || !res.data.ok) {
                        throw new Error((res.data && res.data.message) ? res.data.message : 'Gagal mengubah status rute.');
                    }
                    const active = Number(res.data.is_active) === 1;
                    btn.setAttribute('data-active', active ? '1' : '0');
                    btn.classList.toggle('text-success', active);
                    btn.classList.toggle('text-danger', !active);
                    btn.setAttribute('title', active ? 'Aktif — klik untuk nonaktifkan' : 'Nonaktif — klik untuk aktifkan');
                    btn.setAttribute('aria-label', active ? 'Aktif' : 'Nonaktif');
                    const icon = btn.querySelector('i.bi');
                    if (icon) {
                        icon.classList.toggle('bi-check-circle-fill', active);
                        icon.classList.toggle('bi-x-circle-fill', !active);
                    }
                    showFeedback(true, res.data.message || 'Status rute diperbarui.');
                })
                .catch(function (err) {
                    showFeedback(false, err && err.message ? err.message : 'Gagal mengubah status rute.');
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    });
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
