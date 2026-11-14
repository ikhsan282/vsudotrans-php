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

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

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
    $sql_export = "SELECT r.code, r.name, pc.name AS pickup, pp.name AS pickup_province,
                          dc.name AS delivery, dp.name AS delivery_province,
                          (SELECT GROUP_CONCAT(
                                    CONCAT(
                                        cv.name, ': Rp ', FORMAT(rt.price, 0),
                                        CASE WHEN COALESCE(NULLIF(rt.seat_capacity, 0), rt.end_capacity_seat, 0) > 0
                                             THEN CONCAT(' (', COALESCE(NULLIF(rt.seat_capacity, 0), rt.end_capacity_seat), ' kursi)')
                                             ELSE '' END
                                    )
                                    ORDER BY rt.sort_order ASC, rt.id ASC SEPARATOR '; ')
                             FROM rute_tariffs rt
                             JOIN category_vehicles cv ON cv.id = rt.category_vehicle_id
                            WHERE rt.rute_id = r.id) AS tariffs_csv,
                          (SELECT GROUP_CONCAT(rs.departure_time ORDER BY rs.departure_time ASC SEPARATOR ', ')
                             FROM rute_schedules rs WHERE rs.rute_id = r.id) AS schedules_csv,
                          (SELECT GROUP_CONCAT(
                                    CONCAT(d.name, ' (', CASE WHEN rr.extra_price > 0 THEN CONCAT('+Rp ', FORMAT(rr.extra_price, 0)) ELSE 'Gratis' END, ')')
                                    ORDER BY rr.sort_order ASC, d.name ASC SEPARATOR '; ')
                             FROM rute_radius_districts rr JOIN districts d ON d.id = rr.district_id
                            WHERE rr.rute_id = r.id AND rr.radius_type = 'pickup') AS pickup_radius_csv,
                          (SELECT GROUP_CONCAT(
                                    CONCAT(d.name, ' (', CASE WHEN rr.extra_price > 0 THEN CONCAT('+Rp ', FORMAT(rr.extra_price, 0)) ELSE 'Gratis' END, ')')
                                    ORDER BY rr.sort_order ASC, d.name ASC SEPARATOR '; ')
                             FROM rute_radius_districts rr JOIN districts d ON d.id = rr.district_id
                            WHERE rr.rute_id = r.id AND rr.radius_type = 'delivery') AS delivery_radius_csv,
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
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static function (array $r): array {
            $pickupLbl = $r['pickup'] . (!empty($r['pickup_province']) ? ' (' . $r['pickup_province'] . ')' : '');
            $delivLbl  = $r['delivery'] . (!empty($r['delivery_province']) ? ' (' . $r['delivery_province'] . ')' : '');
            $tariffsText = trim((string) ($r['tariffs_csv'] ?? ''));
            if ($tariffsText === '') {
                $sc = (int) ($r['seat_capacity'] ?? 0);
                $tariffsText = (!empty($r['def_cat_name']) ? $r['def_cat_name'] . ': ' : '') . rupiah((float) $r['price']) . ($sc > 0 ? " ({$sc} kursi)" : '');
            }
            $pRadCsv = trim((string) ($r['pickup_radius_csv'] ?? ''));
            $dRadCsv = trim((string) ($r['delivery_radius_csv'] ?? ''));
            return [
                $r['code'],
                $r['name'],
                $pickupLbl,
                $delivLbl,
                trim((string) ($r['schedules_csv'] ?? '')) !== '' ? $r['schedules_csv'] : '-',
                $pRadCsv !== '' ? $pRadCsv : rute_format_radius_summary((float) $r['pickup_free_radius_km'], (float) $r['pickup_max_radius_km'], (float) $r['pickup_extra_price_per_km']),
                $dRadCsv !== '' ? $dRadCsv : rute_format_radius_summary((float) $r['delivery_free_radius_km'], (float) $r['delivery_max_radius_km'], (float) $r['delivery_extra_price_per_km']),
                $tariffsText,
                $r['is_active'] ? 'Aktif' : 'Nonaktif',
            ];
        },
        $max_export_rows
    );

    $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
    grid_export_send(
        'Daftar Rute Travel',
        ['Kode', 'Nama Rute', 'Titik Jemput (Provinsi)', 'Titik Antar (Provinsi)', 'Jadwal Keberangkatan', 'Radius Jemput', 'Radius Antar', 'Tarif per Kategori', 'Status'],
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

$header_actions = ui_export_actions();
if (can('travel.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/rutes/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$has_explicit_company = isset($_GET['company_id']);
$query_base = http_build_query(array_filter(
    ['q' => $q, 'status' => $status, 'company_id' => $has_explicit_company ? (string) $filter_company_id : null],
    static fn($v) => $v !== null && $v !== ''
));
$has_active_filter = ($q !== '' || $status !== '' || ($has_explicit_company && $filter_company_id !== ($active_cid ?? 0)));

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
            <form method="get" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Cari kode, rute, kota, kecamatan..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Perusahaan">
                        <?= ui_company_filter_options($companies, $filter_company_id, $has_explicit_company) ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <?php if ($has_active_filter): ?>
                        <a href="<?= e(APP_URL) ?>/pages/travel/rutes/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Kode</th><th>Nama</th><th>Asal</th><th>Tujuan</th><th>Jadwal Keberangkatan</th><th>Radius Jemput / Antar</th><th>Tarif per Kategori Kendaraan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
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
                    $pRad   = rute_format_district_radius_summary($rRad['pickup'], (float) $r['pickup_free_radius_km'], (float) $r['pickup_max_radius_km'], (float) $r['pickup_extra_price_per_km']);
                    $dRad   = rute_format_district_radius_summary($rRad['delivery'], (float) $r['delivery_free_radius_km'], (float) $r['delivery_max_radius_km'], (float) $r['delivery_extra_price_per_km']);
                    $pTip   = $rRad['pickup'] ? rute_format_district_radius_summary($rRad['pickup'], 0, 0, 0, true) : '';
                    $dTip   = $rRad['delivery'] ? rute_format_district_radius_summary($rRad['delivery'], 0, 0, 0, true) : '';
                ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td class="fw-semibold"><?= e($r['code']) ?></td>
                        <td><?= e($r['name']) ?></td>
                        <td>
                            <div><?= e($r['pickup']) ?></div>
                            <?php if (!empty($r['pickup_province'])): ?><div class="text-muted small"><?= e($r['pickup_province']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div><?= e($r['delivery']) ?></div>
                            <?php if (!empty($r['delivery_province'])): ?><div class="text-muted small"><?= e($r['delivery_province']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($slots): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($slots as $slot): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border font-monospace"><?= e($slot) ?></span>
                                    <?php endforeach; ?>
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
                                                <?php if ($seatCap > 0): ?><span class="text-muted">(<?= $seatCap ?> kursi)</span><?php endif; ?>
                                            </span>
                                            <span class="fw-semibold text-nowrap"><?= e(rupiah((float) $tf['price'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
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
                    <tr><td colspan="10" class="text-center text-muted py-4">Belum ada data rute.</td></tr>
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
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
