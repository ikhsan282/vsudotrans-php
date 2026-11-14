<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';

$cid = require_active_company();
rute_ensure_schema();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = [
    'code' => '', 'name' => '', 'pickup_point_city_id' => '', 'delivery_point_city_id' => '',
    'category_vehicle_id' => '', 'min_price' => 0, 'price' => 0, 'max_price' => 0,
    'start_capacity_seat' => 0, 'end_capacity_seat' => 0, 'seat_capacity' => 0,
    'pickup_free_radius_km' => 0, 'pickup_max_radius_km' => 0, 'pickup_extra_price_per_km' => 0,
    'delivery_free_radius_km' => 0, 'delivery_max_radius_km' => 0, 'delivery_extra_price_per_km' => 0,
    'note' => '', 'is_active' => 1,
    'created_user' => $_SESSION['username'] ?? '', 'created_date' => '', 'modified_user' => '', 'modified_date' => '',
];
$selectedSlots  = [];
$tariffRows     = [];
$radiusRows     = ['pickup' => [], 'delivery' => []];
$pickupProvId   = 0;
$deliveryProvId = 0;

$allowedTabs = ['detail', 'schedules', 'radius', 'audit'];
$activeTab   = (string) ($_POST['active_tab'] ?? $_GET['tab'] ?? 'detail');
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'detail';
}

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM rutes WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Rute tidak ditemukan.');
        redirect('pages/travel/rutes/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $cid = (int) $found['company_id'];
    $row = $found;
    $selectedSlots = rute_schedule_times($id, $cid);
    $tariffRows    = rute_tariffs_for_rute($id, $cid);
    $radiusRows    = rute_radius_for_rute($id, $cid);
    if (!$tariffRows && (int) ($row['category_vehicle_id'] ?? 0) > 0) {
        $legacySeat = (int) ($row['seat_capacity'] ?? 0);
        if ($legacySeat <= 0) {
            $legacySeat = (int) ($row['end_capacity_seat'] ?? 0);
        }
        $tariffRows[] = [
            'category_vehicle_id' => (int) $row['category_vehicle_id'],
            'min_price'           => (float) ($row['min_price'] ?? 0),
            'price'               => (float) ($row['price'] ?? 0),
            'max_price'           => (float) ($row['max_price'] ?? 0),
            'seat_capacity'       => $legacySeat,
            'start_capacity_seat' => (int) ($row['start_capacity_seat'] ?? 0),
            'end_capacity_seat'   => $legacySeat,
            'note'                => (string) ($row['note'] ?? ''),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = [
        'code'     => strtoupper(trim((string) ($_POST['code'] ?? ''))),
        'name'     => trim((string) ($_POST['name'] ?? '')),
        'pickup'   => (int) ($_POST['pickup_point_city_id'] ?? 0),
        'delivery' => (int) ($_POST['delivery_point_city_id'] ?? 0),
        'p_free'   => round((float) ($_POST['pickup_free_radius_km'] ?? 0), 2),
        'p_max'    => round((float) ($_POST['pickup_max_radius_km'] ?? 0), 2),
        'p_extra'  => round(rute_parse_signed_amount($_POST['pickup_extra_price_per_km'] ?? 0), 2),
        'd_free'   => round((float) ($_POST['delivery_free_radius_km'] ?? 0), 2),
        'd_max'    => round((float) ($_POST['delivery_max_radius_km'] ?? 0), 2),
        'd_extra'  => round(rute_parse_signed_amount($_POST['delivery_extra_price_per_km'] ?? 0), 2),
        'note'     => trim((string) ($_POST['note'] ?? '')),
        'active'   => isset($_POST['is_active']) ? 1 : 0,
    ];
    $pickupProvId   = (int) ($_POST['pickup_point_province_id'] ?? 0);
    $deliveryProvId = (int) ($_POST['delivery_point_province_id'] ?? 0);

    // Ambil list tarif per kategori kendaraan (kompatibel juga dengan POST single-row & kolom seat legacy)
    $rawTariffPost = [];
    if (isset($_POST['tariff_category_vehicle_id']) && is_array($_POST['tariff_category_vehicle_id'])) {
        $tCats  = $_POST['tariff_category_vehicle_id'];
        $tMins  = (array) ($_POST['tariff_min_price'] ?? []);
        $tPrcs  = (array) ($_POST['tariff_price'] ?? []);
        $tMaxs  = (array) ($_POST['tariff_max_price'] ?? []);
        $tSeats = (array) ($_POST['tariff_seat_capacity'] ?? []);
        $tSeatS = (array) ($_POST['tariff_start_seat'] ?? []);
        $tSeatE = (array) ($_POST['tariff_end_seat'] ?? []);
        $tNotes = (array) ($_POST['tariff_note'] ?? []);
        foreach ($tCats as $idx => $catRaw) {
            $item = [
                'category_vehicle_id' => (int) $catRaw,
                'min_price'           => $tMins[$idx] ?? 0,
                'price'               => $tPrcs[$idx] ?? 0,
                'max_price'           => $tMaxs[$idx] ?? 0,
                'note'                => trim((string) ($tNotes[$idx] ?? '')),
            ];
            if (array_key_exists($idx, $tSeats)) {
                $item['seat_capacity'] = (int) $tSeats[$idx];
            } else {
                $item['start_capacity_seat'] = (int) ($tSeatS[$idx] ?? 0);
                $item['end_capacity_seat']   = (int) ($tSeatE[$idx] ?? 0);
            }
            $rawTariffPost[] = $item;
        }
    } elseif (isset($_POST['category_vehicle_id'])) {
        $item = [
            'category_vehicle_id' => (int) ($_POST['category_vehicle_id'] ?? 0),
            'min_price'           => $_POST['min_price'] ?? 0,
            'price'               => $_POST['price'] ?? 0,
            'max_price'           => $_POST['max_price'] ?? 0,
            'note'                => $in['note'],
        ];
        if (isset($_POST['seat_capacity'])) {
            $item['seat_capacity'] = (int) $_POST['seat_capacity'];
        } else {
            $item['start_capacity_seat'] = (int) ($_POST['start_capacity_seat'] ?? 0);
            $item['end_capacity_seat']   = (int) ($_POST['end_capacity_seat'] ?? 0);
        }
        $rawTariffPost[] = $item;
    }

    // Repopulasi tampilan baris bila validasi gagal
    $tariffRows = array_map(static function (array $r): array {
        $sc = array_key_exists('seat_capacity', $r) ? (int) $r['seat_capacity'] : (int) ($r['end_capacity_seat'] ?? 0);
        return [
            'category_vehicle_id' => (int) ($r['category_vehicle_id'] ?? 0),
            'min_price'           => rute_parse_signed_amount($r['min_price'] ?? 0),
            'price'               => rute_parse_signed_amount($r['price'] ?? 0),
            'max_price'           => rute_parse_signed_amount($r['max_price'] ?? 0),
            'seat_capacity'       => $sc,
            'start_capacity_seat' => (int) ($r['start_capacity_seat'] ?? ($sc > 0 ? 1 : 0)),
            'end_capacity_seat'   => $sc,
            'note'                => (string) ($r['note'] ?? ''),
        ];
    }, $rawTariffPost);

    $rawSlots = (array) ($_POST['departure_times'] ?? []);
    $parseRadiusPost = static function (string $prefix): array {
        $dids  = (array) ($_POST[$prefix . '_district_id'] ?? []);
        $exps  = (array) ($_POST[$prefix . '_extra_price'] ?? []);
        $notes = (array) ($_POST[$prefix . '_note'] ?? []);
        $out   = [];
        foreach ($dids as $idx => $didRaw) {
            $out[] = [
                'district_id' => (int) $didRaw,
                'extra_price' => $exps[$idx] ?? 0,
                'note'        => trim((string) ($notes[$idx] ?? '')),
            ];
        }
        return $out;
    };
    $rawRadiusPickup   = $parseRadiusPost('radius_pickup');
    $rawRadiusDelivery = $parseRadiusPost('radius_delivery');

    $postedDistIds = array_merge(
        array_column($rawRadiusPickup, 'district_id'),
        array_column($rawRadiusDelivery, 'district_id')
    );
    $postedDistMap = [];
    foreach (opts_districts_with_city($postedDistIds) as $dr) {
        $postedDistMap[(int) $dr['id']] = (string) $dr['name'];
    }
    $repopRadius = static function (array $items) use ($postedDistMap): array {
        $out = [];
        foreach ($items as $it) {
            $did   = (int) ($it['district_id'] ?? 0);
            $extra = max(0, rute_parse_signed_amount($it['extra_price'] ?? 0));
            $note  = (string) ($it['note'] ?? '');
            if ($did <= 0 && $extra == 0.0 && $note === '') {
                continue;
            }
            $out[] = [
                'district_id' => $did,
                'label'       => $postedDistMap[$did] ?? ($did > 0 ? ('Kecamatan #' . $did) : ''),
                'extra_price' => $extra,
                'note'        => $note,
            ];
        }
        return $out;
    };
    $radiusRows = [
        'pickup'   => $repopRadius($rawRadiusPickup),
        'delivery' => $repopRadius($rawRadiusDelivery),
    ];

    $err = '';
    try {
        $selectedSlots = rute_schedule_normalize_slots($rawSlots);
    } catch (InvalidArgumentException $slotEx) {
        $err = $slotEx->getMessage();
        $activeTab = 'schedules';
        $selectedSlots = array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), $rawSlots))));
    }

    $normalizedTariffs        = [];
    $normalizedPickupRadius   = [];
    $normalizedDeliveryRadius = [];
    if ($err === '') {
        if ($in['code'] === '' || strlen($in['code']) > 15) {
            $err = 'Kode wajib diisi (maks 15 karakter).';
            $activeTab = 'detail';
        } elseif ($in['name'] === '' || strlen($in['name']) > 255) {
            $err = 'Nama wajib diisi (maks 255 karakter).';
            $activeTab = 'detail';
        } elseif ($in['pickup'] <= 0 || $in['delivery'] <= 0) {
            $err = 'Kota asal dan kota tujuan wajib dipilih.';
            $activeTab = 'detail';
        } elseif (resolve_city_province_id($in['pickup']) <= 0 || resolve_city_province_id($in['delivery']) <= 0) {
            $err = 'Kota asal atau kota tujuan tidak ditemukan di master wilayah.';
            $activeTab = 'detail';
        } elseif ($in['p_free'] < 0 || $in['p_max'] < 0 || $in['p_extra'] < 0 || $in['d_free'] < 0 || $in['d_max'] < 0 || $in['d_extra'] < 0) {
            $err = 'Radius penjemputan, pengantaran, dan tarif tambahan tidak boleh negatif.';
            $activeTab = 'radius';
        } elseif ($in['p_max'] > 0 && $in['p_free'] > $in['p_max']) {
            $err = 'Radius gratis penjemputan tidak boleh melebihi radius maksimal penjemputan.';
            $activeTab = 'radius';
        } elseif ($in['d_max'] > 0 && $in['d_free'] > $in['d_max']) {
            $err = 'Radius gratis pengantaran tidak boleh melebihi radius maksimal pengantaran.';
            $activeTab = 'radius';
        } else {
            try {
                $normalizedTariffs = rute_tariffs_normalize_and_validate($rawTariffPost, $cid);
            } catch (InvalidArgumentException $tEx) {
                $err = $tEx->getMessage();
                $activeTab = 'detail';
            }
            if ($err === '') {
                try {
                    $normalizedPickupRadius   = rute_radius_normalize_and_validate($rawRadiusPickup, 'pickup');
                    $normalizedDeliveryRadius = rute_radius_normalize_and_validate($rawRadiusDelivery, 'delivery');
                } catch (InvalidArgumentException $rEx) {
                    $err = $rEx->getMessage();
                    $activeTab = 'radius';
                }
            }
        }
    }

    if ($err === '') {
        $db = db();
        $primaryTariff = $normalizedTariffs[0];
        try {
            $db->begin_transaction();
            $user = $_SESSION['username'] ?? null;
            if ($id > 0) {
                $st = $db->prepare('UPDATE rutes SET code=?, name=?, pickup_point_city_id=?, delivery_point_city_id=?,
                    category_vehicle_id=?, min_price=?, price=?, max_price=?, start_capacity_seat=?, end_capacity_seat=?, seat_capacity=?,
                    pickup_free_radius_km=?, pickup_max_radius_km=?, pickup_extra_price_per_km=?,
                    delivery_free_radius_km=?, delivery_max_radius_km=?, delivery_extra_price_per_km=?,
                    note=?, is_active=?, modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?');
                $st->bind_param(
                    'ssiiidddiiiddddddsisii',
                    $in['code'], $in['name'], $in['pickup'], $in['delivery'],
                    $primaryTariff['category_vehicle_id'], $primaryTariff['min_price'], $primaryTariff['price'], $primaryTariff['max_price'],
                    $primaryTariff['start_capacity_seat'], $primaryTariff['end_capacity_seat'], $primaryTariff['seat_capacity'],
                    $in['p_free'], $in['p_max'], $in['p_extra'],
                    $in['d_free'], $in['d_max'], $in['d_extra'],
                    $in['note'], $in['active'], $user, $id, $cid
                );
                $st->execute();
                $st->close();
                rute_tariffs_sync($id, $cid, $normalizedTariffs, $user);
                rute_schedule_sync($id, $cid, $selectedSlots, $user);
                rute_radius_sync($id, $cid, $normalizedPickupRadius, $normalizedDeliveryRadius, $user);
                $db->commit();
                audit('rute.update', "Rute #{$id} {$in['code']} (" . count($normalizedTariffs) . ' kategori, ' . count($selectedSlots) . ' jadwal, ' . (count($normalizedPickupRadius) + count($normalizedDeliveryRadius)) . ' kec radius)');
            } else {
                $st = $db->prepare('INSERT INTO rutes (company_id, code, name, pickup_point_city_id, delivery_point_city_id,
                    category_vehicle_id, min_price, price, max_price, start_capacity_seat, end_capacity_seat, seat_capacity,
                    pickup_free_radius_km, pickup_max_radius_km, pickup_extra_price_per_km,
                    delivery_free_radius_km, delivery_max_radius_km, delivery_extra_price_per_km,
                    note, is_active, created_user) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $st->bind_param(
                    'issiiidddiiiddddddsis',
                    $cid, $in['code'], $in['name'], $in['pickup'], $in['delivery'],
                    $primaryTariff['category_vehicle_id'], $primaryTariff['min_price'], $primaryTariff['price'], $primaryTariff['max_price'],
                    $primaryTariff['start_capacity_seat'], $primaryTariff['end_capacity_seat'], $primaryTariff['seat_capacity'],
                    $in['p_free'], $in['p_max'], $in['p_extra'],
                    $in['d_free'], $in['d_max'], $in['d_extra'],
                    $in['note'], $in['active'], $user
                );
                $st->execute();
                $id = (int) $db->insert_id;
                $st->close();
                rute_tariffs_sync($id, $cid, $normalizedTariffs, $user);
                rute_schedule_sync($id, $cid, $selectedSlots, $user);
                rute_radius_sync($id, $cid, $normalizedPickupRadius, $normalizedDeliveryRadius, $user);
                $db->commit();
                audit('rute.create', "Rute #{$id} {$in['code']} (" . count($normalizedTariffs) . ' kategori, ' . count($selectedSlots) . ' jadwal, ' . (count($normalizedPickupRadius) + count($normalizedDeliveryRadius)) . ' kec radius)');
            }
            flash('success', 'Rute berhasil disimpan.');
            redirect('pages/travel/rutes/index.php');
        } catch (mysqli_sql_exception $ex) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            $err = (int) $ex->getCode() === 1062 ? 'Kode rute sudah dipakai.' : 'Gagal menyimpan rute.';
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
            $activeTab = 'detail';
        } catch (Throwable $ex) {
            try {
                $db->rollback();
            } catch (Throwable $ignore) {
            }
            throw $ex;
        }
    }
    flash('error', $err);
    $row = array_merge($row, [
        'code' => $in['code'], 'name' => $in['name'], 'pickup_point_city_id' => $in['pickup'],
        'delivery_point_city_id' => $in['delivery'],
        'pickup_free_radius_km' => $in['p_free'], 'pickup_max_radius_km' => $in['p_max'],
        'pickup_extra_price_per_km' => $in['p_extra'],
        'delivery_free_radius_km' => $in['d_free'], 'delivery_max_radius_km' => $in['d_max'],
        'delivery_extra_price_per_km' => $in['d_extra'],
        'note' => $in['note'], 'is_active' => $in['active'],
    ]);
}

if (!$tariffRows) {
    $tariffRows = [[
        'category_vehicle_id' => 0,
        'min_price'           => 0,
        'price'               => 0,
        'max_price'           => 0,
        'seat_capacity'       => 0,
        'start_capacity_seat' => 0,
        'end_capacity_seat'   => 0,
        'note'                => '',
    ]];
}

if ((int) $row['pickup_point_city_id'] > 0) {
    $resolvedPickupProv = resolve_city_province_id((int) $row['pickup_point_city_id']);
    if ($resolvedPickupProv > 0) {
        $pickupProvId = $resolvedPickupProv;
    }
}
if ((int) $row['delivery_point_city_id'] > 0) {
    $resolvedDeliveryProv = resolve_city_province_id((int) $row['delivery_point_city_id']);
    if ($resolvedDeliveryProv > 0) {
        $deliveryProvId = $resolvedDeliveryProv;
    }
}

$provinces       = opts_provinces(array_filter([$pickupProvId, $deliveryProvId]), 100);
$pickupCities    = opts_cities($pickupProvId > 0 ? $pickupProvId : null, array_filter([(int) $row['pickup_point_city_id']]), $pickupProvId > 0 ? 100 : 50, true);
$deliveryCities  = opts_cities($deliveryProvId > 0 ? $deliveryProvId : null, array_filter([(int) $row['delivery_point_city_id']]), $deliveryProvId > 0 ? 100 : 50, true);
$cats            = opts_categories($cid, array_filter(array_column($tariffRows, 'category_vehicle_id')), 200);
$catDefaultSeats = rute_category_default_seats($cid);
$radiusTotalCnt  = count($radiusRows['pickup']) + count($radiusRows['delivery']);
$hasRadiusRule   = ($radiusTotalCnt > 0
    || (float) $row['pickup_free_radius_km'] > 0 || (float) $row['pickup_max_radius_km'] > 0 || (float) $row['pickup_extra_price_per_km'] > 0
    || (float) $row['delivery_free_radius_km'] > 0 || (float) $row['delivery_max_radius_km'] > 0 || (float) $row['delivery_extra_price_per_km'] > 0);
$radiusAreas     = [
    [
        'type'       => 'pickup',
        'prefix'     => 'radius_pickup',
        'title'      => 'Radius Penjemputan (Kota Asal & Sekitarnya)',
        'icon'       => 'bi-geo-alt text-primary',
        'cityInput'  => 'rute_pickup_city',
        'fillLabel'  => 'Isi Kecamatan Kota Asal',
        'rows'       => $radiusRows['pickup'],
    ],
    [
        'type'       => 'delivery',
        'prefix'     => 'radius_delivery',
        'title'      => 'Radius Pengantaran (Kota Tujuan & Sekitarnya)',
        'icon'       => 'bi-geo text-success',
        'cityInput'  => 'rute_delivery_city',
        'fillLabel'  => 'Isi Kecamatan Kota Tujuan',
        'rows'       => $radiusRows['delivery'],
    ],
];
$active = 'travel';
$title  = $id > 0 ? 'Ubah Rute' : 'Tambah Rute';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        $title,
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Rute' => 'pages/travel/rutes/index.php', ($id > 0 ? $row['name'] : 'Tambah Baru') => null],
        $id > 0 ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            ['text' => count($tariffRows) . ' Kategori', 'class' => 'bg-info-subtle text-info-emphasis border'],
            isset($row['is_active']) ? ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'] : null,
        ])) : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'pages/travel/rutes/index.php', 'class' => 'btn btn-sm btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formRute', 'class' => 'btn btn-sm btn-primary'],
        ]
    ) ?>
    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="ruteFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'detail' ? ' active' : '' ?>" id="tab-rute-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-rute-detail" data-tab-key="detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'schedules' ? ' active' : '' ?>" id="tab-rute-schedules-btn" data-bs-toggle="tab" data-bs-target="#tab-rute-schedules" data-tab-key="schedules" type="button" role="tab">
                    Jadwal Keberangkatan
                    <span id="ruteScheduleTabBadge" class="badge bg-primary-subtle text-primary border ms-1"><?= count($selectedSlots) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'radius' ? ' active' : '' ?>" id="tab-rute-radius-btn" data-bs-toggle="tab" data-bs-target="#tab-rute-radius" data-tab-key="radius" type="button" role="tab">
                    Radius Jemput &amp; Antar
                    <span id="ruteRadiusTabBadge" class="badge bg-info-subtle text-info-emphasis border ms-1"><?= $radiusTotalCnt ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'audit' ? ' active' : '' ?>" id="tab-rute-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-rute-audit" data-tab-key="audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="" id="formRute">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="active_tab" id="ruteActiveTabInput" value="<?= e($activeTab) ?>">

                <div class="tab-content" id="ruteFormTabContent">
                    <!-- ================= TAB 1: DETAIL ================= -->
                    <div class="tab-pane fade<?= $activeTab === 'detail' ? ' show active' : '' ?>" id="tab-rute-detail" role="tabpanel" aria-labelledby="tab-rute-detail-btn">
                        <div class="rnt-panel">
                            <div class="rnt-panel-header"><span>Informasi Rute</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Kode <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Nama Rute <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1">Catatan Umum</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm" name="note" rows="2" maxlength="2000" placeholder="Keterangan umum rute (opsional)..."><?= e((string) $row['note']) ?></textarea>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Status</label>
                                    <div class="rnt-field">
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="is_active">Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span><i class="bi bi-geo-alt me-1 text-primary"></i>Wilayah Asal (Penjemputan)</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi Asal</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rute_pickup_province" name="pickup_point_province_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                    data-ts-with-code="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Provinsi (filter kota) --">
                                                    <option value="">-- Pilih Provinsi --</option>
                                                    <?= select_options($provinces, $pickupProvId) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kota / Kab Asal <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rute_pickup_city" name="pickup_point_city_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-with-province="1"
                                                    data-ts-parent="#rute_pickup_province"
                                                    data-ts-param="province_id"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kota/Kab Asal --">
                                                    <option value="">-- Pilih Kota/Kab --</option>
                                                    <?php foreach ($pickupCities as $c):
                                                        $sel = ((string) $c['id'] === (string) $row['pickup_point_city_id']) ? ' selected' : '';
                                                    ?>
                                                        <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) ($c['province_id'] ?? 0) ?>" data-province-name="<?= e($c['province_name'] ?? '') ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span><i class="bi bi-geo me-1 text-success"></i>Wilayah Tujuan (Pengantaran)</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Provinsi Tujuan</label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rute_delivery_province" name="delivery_point_province_id"
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/provinces/options.php"
                                                    data-ts-with-code="1"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Provinsi (filter kota) --">
                                                    <option value="">-- Pilih Provinsi --</option>
                                                    <?= select_options($provinces, $deliveryProvId) ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kota / Kab Tujuan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm ts-select" id="rute_delivery_city" name="delivery_point_city_id" required
                                                    data-ts-url="<?= e(APP_URL) ?>/pages/masters/cities/options.php"
                                                    data-ts-with-province="1"
                                                    data-ts-parent="#rute_delivery_province"
                                                    data-ts-param="province_id"
                                                    data-ts-limit="50"
                                                    data-ts-placeholder="-- Pilih Kota/Kab Tujuan --">
                                                    <option value="">-- Pilih Kota/Kab --</option>
                                                    <?php foreach ($deliveryCities as $c):
                                                        $sel = ((string) $c['id'] === (string) $row['delivery_point_city_id']) ? ' selected' : '';
                                                    ?>
                                                        <option value="<?= (int) $c['id'] ?>" data-province="<?= (int) ($c['province_id'] ?? 0) ?>" data-province-name="<?= e($c['province_name'] ?? '') ?>"<?= $sel ?>><?= e($c['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span>Daftar Tarif &amp; Kapasitas per Kategori Kendaraan</span>
                                    <span id="ruteTariffCountBadge" class="badge bg-primary-subtle text-primary border"><?= count($tariffRows) ?> Kategori</span>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <?php if (can('vehicles.manage')): ?>
                                        <a href="<?= e(APP_URL) ?>/pages/vehicles/master.php?t=category" target="_blank" class="rnt-header-action">
                                            <i class="bi bi-tags me-1"></i>Kelola Kategori
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($cats): ?>
                                        <button type="button" id="btnFillAllTariffCats" class="rnt-header-action" title="Tambahkan seluruh kategori kendaraan aktif yang belum ada di tabel">
                                            <i class="bi bi-list-check me-1"></i>Isi Semua Kategori
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" id="btnAddRuteTariff" class="rnt-header-action">
                                        <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
                                    </button>
                                </div>
                            </div>
                            <div class="rnt-panel-body p-0">
                                <div id="ruteTariffDupWarn" class="alert alert-warning py-1 px-3 small mb-0 rounded-0 border-0 border-bottom d-none" role="alert" data-no-autodismiss="1">
                                    <i class="bi bi-exclamation-triangle me-1"></i><span id="ruteTariffDupMsg">Terdapat kategori kendaraan ganda. Kategori kendaraan yang sama tidak boleh dipilih lebih dari 1 kali per rute.</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead class="table-light small">
                                            <tr>
                                                <th class="text-center" style="width:38px;">No</th>
                                                <th style="min-width:210px;">Kategori Kendaraan <span class="text-danger">*</span></th>
                                                <th class="text-end" style="min-width:125px;" title="Harga paling mentok, batas bawah tidak boleh ditawar lagi">Harga Min (Rp)</th>
                                                <th class="text-end" style="min-width:135px;" title="Harga normal ke customer & acuan otomatis form pemesanan">Harga Standar (Rp) <span class="text-danger">*</span></th>
                                                <th class="text-end" style="min-width:125px;" title="Harga tertinggi di rute & kategori ini">Harga Maks (Rp)</th>
                                                <th class="text-center" style="width:110px;" title="Kapasitas jumlah kursi per kategori (mis. Hiace Commuter 14, Elf Long 19, Bus Sedang 31, Bus Besar 59)">Jumlah Kursi</th>
                                                <th style="min-width:160px;">Catatan</th>
                                                <th class="text-center" style="width:42px;">Hapus</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ruteTariffBody">
                                            <?php foreach ($tariffRows as $idx => $tr):
                                                $rowSeat = (int) (($tr['seat_capacity'] ?? 0) > 0 ? $tr['seat_capacity'] : ($tr['end_capacity_seat'] ?? 0));
                                            ?>
                                                <tr class="rute-tariff-row">
                                                    <td class="text-center text-muted small tariff-row-num"><?= $idx + 1 ?></td>
                                                    <td>
                                                        <select class="form-select form-select-sm ts-select tariff-cat-select" name="tariff_category_vehicle_id[]" required data-ts-dropdown-parent="body" data-ts-placeholder="-- Pilih Kategori --">
                                                            <option value="">-- Pilih Kategori --</option>
                                                            <?= select_options($cats, $tr['category_vehicle_id'] ?? '') ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end tariff-rp-disp" data-target="min" value="<?= e(format_nominal((float) ($tr['min_price'] ?? 0))) ?>" placeholder="0">
                                                        <input type="hidden" name="tariff_min_price[]" class="tariff-rp-val" value="<?= e((string) round((float) ($tr['min_price'] ?? 0), 2)) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold tariff-rp-disp" data-target="price" required value="<?= e(format_nominal((float) ($tr['price'] ?? 0))) ?>" placeholder="0">
                                                        <input type="hidden" name="tariff_price[]" class="tariff-rp-val" value="<?= e((string) round((float) ($tr['price'] ?? 0), 2)) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end tariff-rp-disp" data-target="max" value="<?= e(format_nominal((float) ($tr['max_price'] ?? 0))) ?>" placeholder="0">
                                                        <input type="hidden" name="tariff_max_price[]" class="tariff-rp-val" value="<?= e((string) round((float) ($tr['max_price'] ?? 0), 2)) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" min="0" max="999" class="form-control form-control-sm text-end tariff-seat-input" name="tariff_seat_capacity[]" value="<?= $rowSeat ?>" placeholder="0">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm" name="tariff_note[]" maxlength="2000" value="<?= e((string) ($tr['note'] ?? '')) ?>" placeholder="Keterangan (opsional)">
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-tariff" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="px-3 py-2 bg-light border-top small text-muted">
                                    <strong>Harga Min:</strong> batas bawah mentok (tidak boleh ditawar lebih rendah) ·
                                    <strong>Harga Standar:</strong> tarif normal ke customer ·
                                    <strong>Harga Maks:</strong> batas tertinggi kategori ini ·
                                    <strong>Jumlah Kursi:</strong> kapasitas seat (mis. Hiace Commuter 14, Elf Long 19, Bus Sedang 31, Bus Besar 59).
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ================= TAB 2: JADWAL KEBERANGKATAN ================= -->
                    <div class="tab-pane fade<?= $activeTab === 'schedules' ? ' show active' : '' ?>" id="tab-rute-schedules" role="tabpanel" aria-labelledby="tab-rute-schedules-btn">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span>Jadwal Keberangkatan Rute (ENUM Jam)</span>
                                    <span id="ruteScheduleCountBadge" class="badge bg-primary-subtle text-primary border"><?= count($selectedSlots) ?> Jam Dipilih</span>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <button type="button" class="rnt-header-action btn-rute-preset" data-preset="07:00,08:00,09:00">
                                        <i class="bi bi-sunrise me-1"></i>Pagi 07–09
                                    </button>
                                    <button type="button" class="rnt-header-action text-danger btn-rute-preset" data-preset="">
                                        <i class="bi bi-trash3 me-1"></i>Kosongkan
                                    </button>
                                </div>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="form-text small mb-3">Opsional — pilih jam keberangkatan yang tersedia untuk rute ini (contoh: pagi jam 07:00, 08:00, 09:00). Tersimpan terpisah di tabel <code>rute_schedules</code>.</div>
                                <?php foreach (rute_schedule_periods() as $grp): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="small text-muted fw-semibold" style="min-width: 78px;"><?= e($grp['label']) ?></span>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($grp['slots'] as $slot):
                                                $sid = 'rslot_' . str_replace(':', '', $slot);
                                                $chk = in_array($slot, $selectedSlots, true) ? 'checked' : '';
                                            ?>
                                                <input type="checkbox" class="btn-check rute-slot-check" name="departure_times[]" id="<?= e($sid) ?>" value="<?= e($slot) ?>" autocomplete="off" <?= $chk ?>>
                                                <label class="btn btn-sm btn-outline-primary font-monospace px-2 py-1" for="<?= e($sid) ?>"><?= e($slot) ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ================= TAB 3: RADIUS JEMPUT & ANTAR ================= -->
                    <div class="tab-pane fade<?= $activeTab === 'radius' ? ' show active' : '' ?>" id="tab-rute-radius" role="tabpanel" aria-labelledby="tab-rute-radius-btn">
                        <div class="row g-2">
                            <?php foreach ($radiusAreas as $area): ?>
                                <div class="col-12 col-lg-6">
                                    <div class="rnt-panel h-100 mb-0 d-flex flex-column rute-radius-panel" data-area="<?= e($area['type']) ?>" data-prefix="<?= e($area['prefix']) ?>" data-city-input="<?= e($area['cityInput']) ?>">
                                        <div class="rnt-panel-header">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span><i class="bi <?= e($area['icon']) ?> me-1"></i><?= e($area['title']) ?></span>
                                                <span id="ruteRadiusCount_<?= e($area['type']) ?>" class="badge bg-info-subtle text-info-emphasis border"><?= count($area['rows']) ?> Kecamatan</span>
                                            </div>
                                            <div class="d-flex flex-wrap align-items-center gap-3">
                                                <button type="button" class="rnt-header-action btn-fill-city-districts">
                                                    <i class="bi bi-magic me-1"></i><?= e($area['fillLabel']) ?>
                                                </button>
                                                <button type="button" class="rnt-header-action text-danger btn-clear-radius-area">
                                                    <i class="bi bi-trash3 me-1"></i>Kosongkan
                                                </button>
                                                <button type="button" class="rnt-header-action btn-add-radius-row">
                                                    <i class="bi bi-plus-lg me-1"></i>Tambah Kecamatan
                                                </button>
                                            </div>
                                        </div>
                                        <div class="rnt-panel-body p-0 flex-grow-1 d-flex flex-column">
                                            <div id="ruteRadiusWarn_<?= e($area['type']) ?>" class="alert alert-warning small py-1 px-3 m-2 mb-0 d-none" role="alert" data-no-autodismiss="1"></div>
                                            <div class="table-responsive flex-grow-1">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light small">
                                                        <tr>
                                                            <th style="width: 32px;" class="text-center">#</th>
                                                            <th style="min-width: 175px;">Kecamatan (Kota) <span class="text-danger">*</span></th>
                                                            <th style="min-width: 120px;" class="text-end">Biaya Tambahan (Rp)</th>
                                                            <th style="min-width: 125px;">Keterangan</th>
                                                            <th style="width: 38px;" class="text-center">Aksi</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="ruteRadiusBody_<?= e($area['type']) ?>" class="rute-radius-body">
                                                        <?php if (!$area['rows']): ?>
                                                            <tr class="rute-radius-empty">
                                                                <td colspan="5" class="text-center text-muted small py-3">
                                                                    Belum ada daftar kecamatan. Klik <strong><?= e($area['fillLabel']) ?></strong> atau <strong>Tambah Kecamatan</strong> untuk mengatur area &amp; biaya tambahan.
                                                                </td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($area['rows'] as $rIdx => $rItem):
                                                                $did   = (int) ($rItem['district_id'] ?? 0);
                                                                $dLbl  = trim((string) ($rItem['label'] ?? ''));
                                                                $extra = (float) ($rItem['extra_price'] ?? 0);
                                                                $rNote = (string) ($rItem['note'] ?? '');
                                                            ?>
                                                                <tr class="rute-radius-row">
                                                                    <td class="text-center small text-muted radius-row-num"><?= $rIdx + 1 ?></td>
                                                                    <td>
                                                                        <select class="form-select form-select-sm radius-dist-select" name="<?= e($area['prefix']) ?>_district_id[]" data-radius-field="district_id" data-ts-url="pages/masters/districts/options.php" data-ts-with-city="1" data-ts-limit="50" data-placeholder="Cari nama kecamatan...">
                                                                            <option value="">-- Pilih Kecamatan --</option>
                                                                            <?php if ($did > 0): ?>
                                                                                <option value="<?= $did ?>" selected><?= e($dLbl !== '' ? $dLbl : ('Kecamatan #' . $did)) ?></option>
                                                                            <?php endif; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold tariff-rp-disp radius-extra-disp" value="<?= e(format_nominal($extra)) ?>" placeholder="0">
                                                                        <input type="hidden" name="<?= e($area['prefix']) ?>_extra_price[]" data-radius-field="extra_price" class="tariff-rp-val radius-extra-val" value="<?= e((string) round($extra, 2)) ?>">
                                                                    </td>
                                                                    <td>
                                                                        <input type="text" class="form-control form-control-sm" name="<?= e($area['prefix']) ?>_note[]" data-radius-field="note" maxlength="500" value="<?= e($rNote) ?>" placeholder="Catatan (opsional)">
                                                                    </td>
                                                                    <td class="text-center">
                                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-radius" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="px-3 py-2 bg-light border-top small text-muted mt-auto">
                                                <strong>Rp 0</strong> = dalam jangkauan dasar (tanpa biaya tambahan) · Isi misal <strong>30.000 – 100.000</strong> untuk kecamatan yang lebih jauh · Ketik nama kecamatan untuk mencari lintas kota sekitar.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ================= TAB 4: AUDIT TRAIL ================= -->
                    <div class="tab-pane fade<?= $activeTab === 'audit' ? ' show active' : '' ?>" id="tab-rute-audit" role="tabpanel" aria-labelledby="tab-rute-audit-btn">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header"><span>Audit Trail Dokumen</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e($row['created_user'] ?: '-') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($row['created_date']) ? e(date('d-M-Y H:i', strtotime((string) $row['created_date']))) : '-' ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e($row['modified_user'] ?: '-') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($row['modified_date']) ? e(date('d-M-Y H:i', strtotime((string) $row['modified_date']))) : '-' ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>

<template id="ruteTariffRowTpl">
    <tr class="rute-tariff-row">
        <td class="text-center text-muted small tariff-row-num">1</td>
        <td>
            <select class="form-select form-select-sm tariff-cat-select" name="tariff_category_vehicle_id[]" required data-ts-dropdown-parent="body" data-ts-placeholder="-- Pilih Kategori --">
                <option value="">-- Pilih Kategori --</option>
                <?= select_options($cats, '') ?>
            </select>
        </td>
        <td>
            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end tariff-rp-disp" data-target="min" value="0" placeholder="0">
            <input type="hidden" name="tariff_min_price[]" class="tariff-rp-val" value="0">
        </td>
        <td>
            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold tariff-rp-disp" data-target="price" required value="0" placeholder="0">
            <input type="hidden" name="tariff_price[]" class="tariff-rp-val" value="0">
        </td>
        <td>
            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end tariff-rp-disp" data-target="max" value="0" placeholder="0">
            <input type="hidden" name="tariff_max_price[]" class="tariff-rp-val" value="0">
        </td>
        <td>
            <input type="number" min="0" max="999" class="form-control form-control-sm text-end tariff-seat-input" name="tariff_seat_capacity[]" value="0" placeholder="0">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="tariff_note[]" maxlength="2000" value="" placeholder="Keterangan (opsional)">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-tariff" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
        </td>
    </tr>
</template>

<template id="ruteRadiusRowTpl">
    <tr class="rute-radius-row">
        <td class="text-center small text-muted radius-row-num">1</td>
        <td>
            <select class="form-select form-select-sm radius-dist-select" data-radius-field="district_id" data-ts-url="pages/masters/districts/options.php" data-ts-with-city="1" data-ts-limit="50" data-placeholder="Cari nama kecamatan...">
                <option value="">-- Pilih Kecamatan --</option>
            </select>
        </td>
        <td>
            <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold tariff-rp-disp radius-extra-disp" value="0" placeholder="0">
            <input type="hidden" data-radius-field="extra_price" class="tariff-rp-val radius-extra-val" value="0">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" data-radius-field="note" maxlength="500" value="" placeholder="Catatan (opsional)">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-radius" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const appUrl    = <?= json_encode(APP_URL) ?>;
    const formEl    = document.getElementById('formRute');
    const tabNav    = document.getElementById('ruteFormTabs');
    const activeInp = document.getElementById('ruteActiveTabInput');
    if (tabNav && activeInp) {
        tabNav.querySelectorAll('button[data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function () {
                activeInp.value = btn.getAttribute('data-tab-key') || 'detail';
            });
        });
    }

    // Cascade Provinsi -> Kota + auto-sync Provinsi saat Kota dipilih langsung
    if (window.BookingErp && BookingErp.initGeoCascade) {
        BookingErp.initGeoCascade({
            appUrl: appUrl,
            provinceEl: document.getElementById('rute_pickup_province'),
            cityEl: document.getElementById('rute_pickup_city')
        });
        BookingErp.initGeoCascade({
            appUrl: appUrl,
            provinceEl: document.getElementById('rute_delivery_province'),
            cityEl: document.getElementById('rute_delivery_city')
        });
    }

    // Validasi lintas tab termasuk kontrol TomSelect & elemen tersembunyi
    function focusFieldInPane(el) {
        if (!el) return;
        const pane = el.closest ? el.closest('.tab-pane') : null;
        if (pane && !pane.classList.contains('active') && window.bootstrap && bootstrap.Tab) {
            const trigger = document.querySelector('#ruteFormTabs button[data-bs-target="#' + pane.id + '"]');
            if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
        setTimeout(function () {
            if (el.tomselect) {
                try { el.tomselect.focus(); } catch (err) {}
            } else if (typeof el.focus === 'function') {
                try { el.focus(); } catch (err) {}
            }
        }, 60);
    }
    // Pengelola baris tarif per kategori kendaraan
    const tariffBody    = document.getElementById('ruteTariffBody');
    const tariffTpl     = document.getElementById('ruteTariffRowTpl');
    const btnAddTariff  = document.getElementById('btnAddRuteTariff');
    const btnFillAllCat = document.getElementById('btnFillAllTariffCats');
    const tariffBadge   = document.getElementById('ruteTariffCountBadge');
    const dupWarnEl     = document.getElementById('ruteTariffDupWarn');
    const dupMsgEl      = document.getElementById('ruteTariffDupMsg');
    const allCatsList   = <?= json_encode(array_map(static fn (array $c): array => ['id' => (string) (int) $c['id'], 'name' => (string) $c['name']], $cats), JSON_UNESCAPED_UNICODE) ?>;
    const catSeatMap    = <?= json_encode($catDefaultSeats, JSON_FORCE_OBJECT) ?>;
    const catLabelMap   = {};
    allCatsList.forEach(function (c) { catLabelMap[String(c.id)] = String(c.name); });
    let stickyDupMsg    = '';
    let dupInvalidSel   = null;

    if (formEl) {
        formEl.addEventListener('invalid', function (e) {
            focusFieldInPane(e.target);
        }, true);
        formEl.addEventListener('submit', function (e) {
            if (tariffBody) {
                const seenCats = {};
                const tRows = Array.from(tariffBody.querySelectorAll('tr.rute-tariff-row'));
                for (let i = 0; i < tRows.length; i++) {
                    const sel = tRows[i].querySelector('.tariff-cat-select');
                    const v = getCatValue(sel);
                    if (!v) continue;
                    if (seenCats[v]) {
                        e.preventDefault();
                        const catName = catLabelMap[v] || ('#' + v);
                        stickyDupMsg = 'Kategori "' + catName + '" sudah dipilih pada baris #' + seenCats[v] + '. Kategori kendaraan yang sama tidak boleh dipilih lebih dari 1 kali.';
                        dupInvalidSel = sel;
                        syncTariffMeta();
                        focusFieldInPane(sel);
                        return false;
                    }
                    seenCats[v] = i + 1;
                }
            }
            const reqEls = formEl.querySelectorAll('[required]');
            for (let i = 0; i < reqEls.length; i++) {
                const el = reqEls[i];
                if (el.disabled) continue;
                const val = el.tomselect ? String(el.tomselect.getValue() || '').trim() : String(el.value || '').trim();
                if (!val) {
                    e.preventDefault();
                    focusFieldInPane(el);
                    return false;
                }
            }
            return true;
        });
    }

    function getCatValue(sel) {
        if (!sel) return '';
        return String(sel.tomselect ? (sel.tomselect.getValue() || '') : (sel.value || '')).trim();
    }

    function setSelectInvalidState(sel, isInvalid) {
        if (!sel) return;
        sel.classList.toggle('is-invalid', isInvalid);
        if (sel.tomselect && sel.tomselect.wrapper) {
            sel.tomselect.wrapper.classList.toggle('is-invalid', isInvalid);
        }
    }

    function suggestSeatForRow(tr, catId) {
        if (!tr || !catId) return;
        const seatEl = tr.querySelector('.tariff-seat-input');
        const defSeat = parseInt(catSeatMap[String(catId)] || 0, 10) || 0;
        if (seatEl && defSeat > 0 && (parseInt(seatEl.value || '0', 10) || 0) <= 0) {
            seatEl.value = String(defSeat);
        }
    }

    function syncTariffOptionsAvailability(rows, counts) {
        rows.forEach(function (tr) {
            const catSel = tr.querySelector('.tariff-cat-select');
            if (!catSel) return;
            const myVal = getCatValue(catSel);
            Array.from(catSel.options || []).forEach(function (opt) {
                const val = String(opt.value || '').trim();
                if (!val) return;
                if (!opt.dataset.baseLabel) {
                    opt.dataset.baseLabel = String(opt.textContent || '').trim();
                }
                const baseLabel = opt.dataset.baseLabel;
                if (!catLabelMap[val] && baseLabel) catLabelMap[val] = baseLabel;
                const usedByOther = (counts[val] || 0) > (val === myVal ? 1 : 0);
                const shouldDisable = (val !== myVal) && usedByOther;
                const nextText = shouldDisable ? (baseLabel + ' (Sudah dipilih)') : baseLabel;
                opt.disabled = shouldDisable;
                opt.textContent = nextText;
                if (catSel.tomselect && catSel.tomselect.options[val]) {
                    catSel.tomselect.updateOption(val, Object.assign({}, catSel.tomselect.options[val], {
                        text: nextText,
                        name: nextText,
                        disabled: shouldDisable
                    }));
                }
            });
            if (catSel.tomselect) {
                catSel.tomselect.refreshOptions(false);
            }
        });
    }

    function syncTariffMeta() {
        if (!tariffBody) return;
        const rows = Array.from(tariffBody.querySelectorAll('tr.rute-tariff-row'));
        const counts = {};
        let hasDup = false;
        const dupNames = [];
        rows.forEach(function (tr, idx) {
            const numCell = tr.querySelector('.tariff-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            const sel = tr.querySelector('.tariff-cat-select');
            const v = getCatValue(sel);
            if (v) {
                counts[v] = (counts[v] || 0) + 1;
                if (counts[v] > 1) {
                    hasDup = true;
                    const nm = catLabelMap[v] || ('#' + v);
                    if (dupNames.indexOf(nm) === -1) dupNames.push(nm);
                }
            }
        });
        rows.forEach(function (tr) {
            const sel = tr.querySelector('.tariff-cat-select');
            const v = getCatValue(sel);
            const bad = (v !== '' && (counts[v] || 0) > 1) || (dupInvalidSel === sel);
            setSelectInvalidState(sel, bad);
        });
        syncTariffOptionsAvailability(rows, counts);
        if (tariffBadge) tariffBadge.textContent = rows.length + ' Kategori';
        if (dupWarnEl) {
            if (hasDup) {
                const msg = 'Kategori kendaraan tidak boleh ganda (' + dupNames.join(', ') + '). Pilih kategori berbeda di setiap baris.';
                if (dupMsgEl) dupMsgEl.textContent = msg;
                dupWarnEl.classList.remove('d-none');
            } else if (stickyDupMsg) {
                if (dupMsgEl) dupMsgEl.textContent = stickyDupMsg;
                dupWarnEl.classList.remove('d-none');
            } else {
                dupWarnEl.classList.add('d-none');
            }
        }
    }

    function bindRupiahInputs(container) {
        if (!container) return;
        container.querySelectorAll('.tariff-rp-disp').forEach(function (dispEl) {
            const hiddenEl = dispEl.parentElement ? dispEl.parentElement.querySelector('.tariff-rp-val') : null;
            if (hiddenEl && window.attachRupiahInput) {
                window.attachRupiahInput(dispEl, hiddenEl);
            }
        });
    }

    function bindTariffRow(tr) {
        if (!tr || tr.dataset.tariffBound === '1') return;
        tr.dataset.tariffBound = '1';

        const catSel = tr.querySelector('.tariff-cat-select');
        if (catSel) {
            if (typeof window.initTsSelect === 'function' && !catSel.tomselect) {
                window.initTsSelect(catSel);
            }
            catSel.addEventListener('change', function () {
                if (catSel.dataset.reverting === '1') return;
                const chosen = getCatValue(catSel);
                let dupRowIdx = -1;
                if (chosen && tariffBody) {
                    const rows = Array.from(tariffBody.querySelectorAll('tr.rute-tariff-row'));
                    for (let i = 0; i < rows.length; i++) {
                        const otherSel = rows[i].querySelector('.tariff-cat-select');
                        if (otherSel && otherSel !== catSel && getCatValue(otherSel) === chosen) {
                            dupRowIdx = i + 1;
                            break;
                        }
                    }
                }
                if (dupRowIdx > 0) {
                    const catName = catLabelMap[chosen] || ('#' + chosen);
                    stickyDupMsg = 'Kategori "' + catName + '" sudah dipilih pada baris #' + dupRowIdx + '. Kategori kendaraan yang sama tidak boleh dipilih lebih dari 1 kali.';
                    dupInvalidSel = catSel;
                    catSel.dataset.reverting = '1';
                    try {
                        if (catSel.tomselect) catSel.tomselect.setValue('', true);
                        else catSel.value = '';
                    } finally {
                        delete catSel.dataset.reverting;
                    }
                    syncTariffMeta();
                    if (catSel.tomselect) {
                        try { catSel.tomselect.focus(); } catch (err) {}
                    } else {
                        try { catSel.focus(); } catch (err) {}
                    }
                    return;
                }
                stickyDupMsg = '';
                dupInvalidSel = null;
                suggestSeatForRow(tr, chosen);
                syncTariffMeta();
            });
        }

        bindRupiahInputs(tr);

        const btnRem = tr.querySelector('.btn-remove-tariff');
        if (btnRem) {
            btnRem.addEventListener('click', function () {
                stickyDupMsg = '';
                if (dupInvalidSel === catSel) dupInvalidSel = null;
                const rows = tariffBody ? tariffBody.querySelectorAll('tr.rute-tariff-row') : [];
                if (rows.length <= 1) {
                    if (catSel && catSel.tomselect) catSel.tomselect.setValue('', true);
                    else if (catSel) catSel.value = '';
                    tr.querySelectorAll('.tariff-rp-disp').forEach(function (d) { d.value = '0'; });
                    tr.querySelectorAll('.tariff-rp-val').forEach(function (h) { h.value = '0'; });
                    tr.querySelectorAll('input[type="number"]').forEach(function (n) { n.value = '0'; });
                    const noteEl = tr.querySelector('input[name="tariff_note[]"]');
                    if (noteEl) noteEl.value = '';
                    syncTariffMeta();
                    return;
                }
                if (catSel && catSel.tomselect) {
                    try { catSel.tomselect.destroy(); } catch (err) {}
                }
                tr.remove();
                syncTariffMeta();
            });
        }
    }

    function appendTariffRow(defaultCatId) {
        if (!tariffBody || !tariffTpl) return null;
        const frag = tariffTpl.content.cloneNode(true);
        const tr = frag.querySelector('tr.rute-tariff-row');
        tariffBody.appendChild(tr);
        const catSel = tr.querySelector('.tariff-cat-select');
        if (defaultCatId && catSel) {
            catSel.value = String(defaultCatId);
        }
        bindTariffRow(tr);
        suggestSeatForRow(tr, defaultCatId);
        syncTariffMeta();
        return tr;
    }

    if (tariffBody) {
        tariffBody.querySelectorAll('tr.rute-tariff-row').forEach(bindTariffRow);
        syncTariffMeta();
    }
    if (btnAddTariff) {
        btnAddTariff.addEventListener('click', function () {
            stickyDupMsg = '';
            dupInvalidSel = null;
            appendTariffRow('');
        });
    }
    if (btnFillAllCat) {
        btnFillAllCat.addEventListener('click', function () {
            if (!tariffBody || !allCatsList.length) return;
            stickyDupMsg = '';
            dupInvalidSel = null;
            const existing = new Set();
            const blankRows = [];
            tariffBody.querySelectorAll('tr.rute-tariff-row').forEach(function (tr) {
                const sel = tr.querySelector('.tariff-cat-select');
                const val = getCatValue(sel);
                if (val) existing.add(val);
                else blankRows.push({ tr: tr, sel: sel });
            });
            allCatsList.forEach(function (cat) {
                if (existing.has(cat.id)) return;
                if (blankRows.length) {
                    const slot = blankRows.shift();
                    if (slot.sel && slot.sel.tomselect) slot.sel.tomselect.setValue(cat.id, true);
                    else if (slot.sel) slot.sel.value = cat.id;
                    suggestSeatForRow(slot.tr, cat.id);
                    existing.add(cat.id);
                } else {
                    appendTariffRow(cat.id);
                    existing.add(cat.id);
                }
            });
            syncTariffMeta();
        });
    }

    // Jadwal Keberangkatan ENUM
    const checks   = Array.from(document.querySelectorAll('.rute-slot-check'));
    const badge    = document.getElementById('ruteScheduleCountBadge');
    const tabBadge = document.getElementById('ruteScheduleTabBadge');
    function refreshCount() {
        const cnt = checks.filter(function (c) { return c.checked; }).length;
        if (badge) badge.textContent = cnt + ' Jam Dipilih';
        if (tabBadge) tabBadge.textContent = String(cnt);
    }
    checks.forEach(function (c) { c.addEventListener('change', refreshCount); });
    document.querySelectorAll('.btn-rute-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const raw = (btn.getAttribute('data-preset') || '').trim();
            const want = new Set(raw ? raw.split(',') : []);
            checks.forEach(function (c) { c.checked = want.has(c.value); });
            refreshCount();
        });
    });
    refreshCount();

    // Pengelola daftar kecamatan radius penjemputan & pengantaran (satu helper untuk kedua panel)
    const radiusTpl      = document.getElementById('ruteRadiusRowTpl');
    const radiusTabBadge = document.getElementById('ruteRadiusTabBadge');
    function refreshRadiusTotalBadge() {
        if (!radiusTabBadge) return;
        const total = document.querySelectorAll('.rute-radius-row').length;
        radiusTabBadge.textContent = String(total);
    }

    function initRadiusArea(panelEl) {
        if (!panelEl) return;
        const area          = panelEl.getAttribute('data-area') || 'pickup';
        const prefix        = panelEl.getAttribute('data-prefix') || ('radius_' + area);
        const cityInputId   = panelEl.getAttribute('data-city-input') || '';
        const tbody         = panelEl.querySelector('.rute-radius-body');
        const countBadge    = document.getElementById('ruteRadiusCount_' + area);
        const warnEl        = document.getElementById('ruteRadiusWarn_' + area);
        const btnAdd        = panelEl.querySelector('.btn-add-radius-row');
        const btnClear      = panelEl.querySelector('.btn-clear-radius-area');
        const btnFillCity   = panelEl.querySelector('.btn-fill-city-districts');

        function setWarn(msg) {
            if (!warnEl) return;
            if (!msg) {
                warnEl.textContent = '';
                warnEl.classList.add('d-none');
            } else {
                warnEl.textContent = msg;
                warnEl.classList.remove('d-none');
            }
        }

        function ensureEmptyRow() {
            if (!tbody) return;
            const hasRows = tbody.querySelector('tr.rute-radius-row') !== null;
            const emptyRow = tbody.querySelector('tr.rute-radius-empty');
            if (!hasRows && !emptyRow) {
                const tr = document.createElement('tr');
                tr.className = 'rute-radius-empty';
                tr.innerHTML = '<td colspan="5" class="text-center text-muted small py-3">Belum ada daftar kecamatan. Klik tombol <strong>Isi Kecamatan</strong> atau <strong>Tambah Kecamatan</strong> untuk menambahkan area.</td>';
                tbody.appendChild(tr);
            } else if (hasRows && emptyRow) {
                emptyRow.remove();
            }
        }

        function syncAreaMeta() {
            if (!tbody) return;
            ensureEmptyRow();
            const rows = Array.from(tbody.querySelectorAll('tr.rute-radius-row'));
            const counts = {};
            let hasDup = false;
            rows.forEach(function (tr, idx) {
                const numCell = tr.querySelector('.radius-row-num');
                if (numCell) numCell.textContent = String(idx + 1);
                const val = getCatValue(tr.querySelector('.radius-dist-select'));
                if (val) {
                    counts[val] = (counts[val] || 0) + 1;
                    if (counts[val] > 1) hasDup = true;
                }
            });
            if (countBadge) countBadge.textContent = rows.length + ' Kecamatan';
            setWarn(hasDup ? 'Terdapat kecamatan duplikat pada daftar radius ini — pastikan setiap kecamatan hanya muncul satu kali.' : '');
            refreshRadiusTotalBadge();
        }

        function bindRadiusRow(tr) {
            if (!tr || tr.dataset.radiusBound === '1') return;
            tr.dataset.radiusBound = '1';
            tr.querySelectorAll('[data-radius-field]').forEach(function (inp) {
                const f = inp.getAttribute('data-radius-field');
                if (f) inp.setAttribute('name', prefix + '_' + f + '[]');
            });
            const sel = tr.querySelector('.radius-dist-select');
            if (sel) {
                if (typeof window.initTsSelect === 'function' && !sel.tomselect) {
                    window.initTsSelect(sel);
                }
                sel.addEventListener('change', syncAreaMeta);
            }
            bindRupiahInputs(tr);
            const btnRem = tr.querySelector('.btn-remove-radius');
            if (btnRem) {
                btnRem.addEventListener('click', function () {
                    if (sel && sel.tomselect) {
                        try { sel.tomselect.destroy(); } catch (err) {}
                    }
                    tr.remove();
                    syncAreaMeta();
                });
            }
        }

        function appendRadiusRow(item, focusAfter) {
            if (!tbody || !radiusTpl) return null;
            const frag = radiusTpl.content.cloneNode(true);
            const tr = frag.querySelector('tr.rute-radius-row');
            const sel = tr.querySelector('.radius-dist-select');
            if (item && item.id && sel) {
                const opt = document.createElement('option');
                opt.value = String(item.id);
                opt.textContent = String(item.text || ('Kecamatan #' + item.id));
                opt.selected = true;
                sel.appendChild(opt);
            }
            if (item && item.extra != null) {
                const h = tr.querySelector('.radius-extra-val');
                const d = tr.querySelector('.radius-extra-disp');
                const num = Number(item.extra) || 0;
                if (h) h.value = String(num);
                if (d) d.value = num ? num.toLocaleString('id-ID') : '0';
            }
            tbody.appendChild(tr);
            bindRadiusRow(tr);
            syncAreaMeta();
            if (focusAfter && sel) {
                if (sel.tomselect) { try { sel.tomselect.focus(); } catch (err) {} }
                else { try { sel.focus(); } catch (err) {} }
            }
            return tr;
        }

        if (tbody) {
            tbody.querySelectorAll('tr.rute-radius-row').forEach(bindRadiusRow);
            syncAreaMeta();
        }
        if (btnAdd) {
            btnAdd.addEventListener('click', function () {
                appendRadiusRow(null, true);
            });
        }
        if (btnClear) {
            btnClear.addEventListener('click', function () {
                if (!tbody) return;
                tbody.querySelectorAll('tr.rute-radius-row').forEach(function (tr) {
                    const sel = tr.querySelector('.radius-dist-select');
                    if (sel && sel.tomselect) { try { sel.tomselect.destroy(); } catch (err) {} }
                    tr.remove();
                });
                syncAreaMeta();
            });
        }
        if (btnFillCity && cityInputId) {
            btnFillCity.addEventListener('click', function () {
                const cityEl = document.getElementById(cityInputId);
                const cityId = getCatValue(cityEl);
                if (!cityId) {
                    setWarn('Pilih Kota ' + (area === 'pickup' ? 'Asal' : 'Tujuan') + ' terlebih dahulu di Tab Detail.');
                    return;
                }
                setWarn('');
                btnFillCity.disabled = true;
                const url = appUrl.replace(/\/$/, '') + '/pages/masters/districts/options.php?city_id=' + encodeURIComponent(cityId) + '&with_city=1&limit=200';
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (items) {
                        if (!Array.isArray(items) || !items.length) {
                            setWarn('Tidak ada data kecamatan terdaftar untuk kota yang dipilih.');
                            return;
                        }
                        const existing = new Set();
                        tbody.querySelectorAll('tr.rute-radius-row').forEach(function (tr) {
                            const v = getCatValue(tr.querySelector('.radius-dist-select'));
                            if (v) existing.add(v);
                        });
                        items.forEach(function (it) {
                            const idStr = String(it.value || it.id || '');
                            if (!idStr || existing.has(idStr)) return;
                            existing.add(idStr);
                            appendRadiusRow({ id: idStr, text: it.text || it.name || ('Kecamatan #' + idStr), extra: 0 }, false);
                        });
                    })
                    .catch(function () {
                        setWarn('Gagal memuat daftar kecamatan kota.');
                    })
                    .finally(function () {
                        btnFillCity.disabled = false;
                    });
            });
        }
    }

    document.querySelectorAll('.rute-radius-panel').forEach(initRadiusArea);
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
