<?php
/**
 * Seeder contoh booking Rental Multi-Hari realistis pada perusahaan MTM (company_id = 1)
 * agar Kalender Operasional, Manifest Armada, Filter Pembayaran, dan Dashboard tampil lengkap.
 * Tidak menyentuh data `travel_ticket_bookings`.
 *
 * Penggunaan CLI:
 *   php database/seeds/seed_rental_sample.php               # Dry-run preview
 *   php database/seeds/seed_rental_sample.php --apply       # Bersihkan sample lama lalu seed 7 booking rental
 *   php database/seeds/seed_rental_sample.php --clean-sample # Hapus hanya data contoh rental_sample_seed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_once __DIR__ . '/../../includes/invoice_service.php';

$args = array_slice($argv ?? [], 1);
$apply = in_array('--apply', $args, true);
$cleanOnly = in_array('--clean-sample', $args, true);

category_service_ensure_schema();
$db = db();
$cid = 1;
$actor = 'rental_sample_seed';

$company = $db->query("SELECT id, code, name FROM companies WHERE id = {$cid} LIMIT 1")->fetch_assoc();
if (!$company) {
    fwrite(STDERR, "Gagal: company_id=1 tidak ditemukan.\n");
    exit(1);
}

$branchRow = $db->query("SELECT id, code, name FROM branches WHERE company_id = {$cid} AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc()
    ?: $db->query("SELECT id, code, name FROM branches WHERE company_id = {$cid} ORDER BY id ASC LIMIT 1")->fetch_assoc();
if (!$branchRow) {
    fwrite(STDERR, "Gagal: cabang MTM tidak ditemukan.\n");
    exit(1);
}
$branchId = (int) $branchRow['id'];

$cats = [];
foreach ($db->query("SELECT id, code, name FROM category_vehicles WHERE company_id = {$cid} AND service_type = 'rental'")->fetch_all(MYSQLI_ASSOC) as $c) {
    $cats[strtoupper(trim((string) $c['code']))] = $c;
}

$vehiclesByType = [
    'SMV' => [],
    'SHC' => [],
    'SEL' => [],
    'SMB' => [],
    'SBB' => [],
];
foreach ($db->query("SELECT id, vehicle_number, vehicle_category, seat FROM vehicles WHERE company_id = {$cid} AND is_active = 1 ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC) as $v) {
    $vc = strtolower((string) ($v['vehicle_category'] ?? ''));
    if (str_contains($vc, 'mini bus') || str_contains($vc, 'minibus')) {
        $vehiclesByType['SMV'][] = $v;
    } elseif (str_contains($vc, 'hiace')) {
        $vehiclesByType['SHC'][] = $v;
    } elseif (str_contains($vc, 'elf long')) {
        $vehiclesByType['SEL'][] = $v;
    } elseif (str_contains($vc, 'medium bus')) {
        $vehiclesByType['SMB'][] = $v;
    } elseif (str_contains($vc, 'big bus')) {
        $vehiclesByType['SBB'][] = $v;
    }
}

$drivers = $db->query("SELECT id, code, name FROM employees WHERE company_id = {$cid} AND status = 'aktif' AND LOWER(COALESCE(job_position, '')) IN ('driver', 'sopir') ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$payMethodRow = $db->query("SELECT id, name FROM payment_methods WHERE (company_id = {$cid} OR company_id IS NULL) AND is_active = 1 AND name LIKE '%Transfer%' ORDER BY id ASC LIMIT 1")->fetch_assoc()
    ?: $db->query("SELECT id, name FROM payment_methods WHERE (company_id = {$cid} OR company_id IS NULL) AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc();
$payMethodId = $payMethodRow ? (int) $payMethodRow['id'] : null;

$findDistrict = static function (string $distPattern, string $cityPattern = '') use ($db): array {
    if ($cityPattern !== '') {
        $st = $db->prepare("SELECT d.id, d.name AS d_name, c.id AS city_id, c.name AS city_name, p.id AS province_id
                              FROM districts d
                              JOIN cities c ON c.id = d.city_id
                              JOIN provinces p ON p.id = c.province_id
                             WHERE d.name LIKE ? AND c.name LIKE ?
                             ORDER BY LENGTH(d.name) ASC, d.id ASC LIMIT 1");
        $dp = $distPattern . '%';
        $cp = '%' . $cityPattern . '%';
        $st->bind_param('ss', $dp, $cp);
    } else {
        $st = $db->prepare("SELECT d.id, d.name AS d_name, c.id AS city_id, c.name AS city_name, p.id AS province_id
                              FROM districts d
                              JOIN cities c ON c.id = d.city_id
                              JOIN provinces p ON p.id = c.province_id
                             WHERE d.name LIKE ?
                             ORDER BY LENGTH(d.name) ASC, d.id ASC LIMIT 1");
        $dp = $distPattern . '%';
        $st->bind_param('s', $dp);
    }
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row && $cityPattern !== '') {
        $st2 = $db->prepare("SELECT d.id, d.name AS d_name, c.id AS city_id, c.name AS city_name, p.id AS province_id
                               FROM districts d
                               JOIN cities c ON c.id = d.city_id
                               JOIN provinces p ON p.id = c.province_id
                              WHERE c.name LIKE ?
                              ORDER BY d.id ASC LIMIT 1");
        $cp = '%' . $cityPattern . '%';
        $st2->bind_param('s', $cp);
        $st2->execute();
        $row = $st2->get_result()->fetch_assoc();
        $st2->close();
    }
    if (!$row) {
        throw new RuntimeException("Kecamatan tidak ditemukan: {$distPattern} / {$cityPattern}");
    }
    return [
        'district_id' => (int) $row['id'],
        'city_id'     => (int) $row['city_id'],
        'province_id' => (int) $row['province_id'],
        'label'       => $row['d_name'] . ', ' . $row['city_name'],
    ];
};

$loc = [
    'cipondoh'       => $findDistrict('Cipondoh', 'Kota Tangerang'),
    'karawaci'       => $findDistrict('Karawaci', 'Kota Tangerang'),
    'tangerang'      => $findDistrict('Tangerang', 'Kota Tangerang'),
    'kebayoran'      => $findDistrict('Kebayoran Baru', 'Jakarta Selatan'),
    'pasar_minggu'   => $findDistrict('Pasar Minggu', 'Jakarta Selatan'),
    'cisarua'        => $findDistrict('Cisarua', 'Bogor'),
    'megamendung'    => $findDistrict('Megamendung', 'Bogor'),
    'lembang'        => $findDistrict('Lembang', 'Bandung Barat'),
    'ciwidey'        => $findDistrict('Ciwidey', 'Bandung'),
    'anyer'          => $findDistrict('Any', 'Serang'),
    'garut'          => $findDistrict('Garut', 'Garut'),
];

$pickVeh = static function (string $catCode, int $idx) use ($vehiclesByType): array {
    $list = $vehiclesByType[$catCode] ?? [];
    if (!$list) {
        foreach ($vehiclesByType as $fallback) {
            if ($fallback) {
                $list = $fallback;
                break;
            }
        }
    }
    if (!$list) {
        return ['id' => 0, 'vehicle_number' => '-'];
    }
    return $list[$idx % count($list)];
};

$pickDrv = static function (int $idx) use ($drivers): array {
    if (!$drivers) {
        return ['id' => 0, 'name' => 'Sopir'];
    }
    return $drivers[$idx % count($drivers)];
};

$scenarios = [
    [
        'pax_code'   => 'P-RNT01',
        'pax_name'   => 'PT Karya Mandiri Sejahtera (Bp. Hendra)',
        'pax_phone'  => '6281288101001',
        'cat_code'   => 'SHC',
        'start_date' => '2026-11-18',
        'end_date'   => '2026-11-20',
        'time'       => '06:00:00',
        'pickup'     => $loc['cipondoh'],
        'pickup_addr'=> 'Jl. KH Hasyim Ashari No. 45, Cipondoh, Tangerang',
        'deliv'      => $loc['cisarua'],
        'deliv_addr' => 'Villa Lembah Pinus Blok B3, Cisarua, Puncak',
        'vehicle'    => $pickVeh('SHC', 0),
        'driver'     => $pickDrv(0),
        'price'      => 4200000.0,
        'pay_ratio'  => 1.0,
        'status'     => 'done',
        'note'       => 'Sewa Hiace Commuter gathering kantor 3 hari Cisarua (termasuk BBM & radius puncak)',
    ],
    [
        'pax_code'   => 'P-RNT02',
        'pax_name'   => 'Keluarga Besar Ibu Rahmawati',
        'pax_phone'  => '6281399202002',
        'cat_code'   => 'SHC',
        'start_date' => '2026-11-22',
        'end_date'   => '2026-11-25',
        'time'       => '05:30:00',
        'pickup'     => $loc['kebayoran'],
        'pickup_addr'=> 'Jl. Senopati No. 18, Kebayoran Baru, Jakarta Selatan',
        'deliv'      => $loc['lembang'],
        'deliv_addr' => 'Lembang Park & Floating Market Area, Bandung Barat',
        'vehicle'    => $pickVeh('SHC', 1),
        'driver'     => $pickDrv(0),
        'price'      => 5800000.0,
        'pay_ratio'  => 0.5,
        'status'     => 'on_trip',
        'note'       => 'Tour keluarga 4 hari Jakarta - Lembang - Tangkuban Perahu (DP 50%, sisa pelunasan hari terakhir)',
    ],
    [
        'pax_code'   => 'P-RNT03',
        'pax_name'   => 'Bpk. Rizky Pratama',
        'pax_phone'  => '6285711303003',
        'cat_code'   => 'SMV',
        'start_date' => '2026-11-23',
        'end_date'   => '2026-11-24',
        'time'       => '07:00:00',
        'pickup'     => $loc['karawaci'],
        'pickup_addr'=> 'Perum Lippo Karawaci Taman Diponegoro No. 12',
        'deliv'      => $loc['megamendung'],
        'deliv_addr' => 'Resort Cimory Highland, Megamendung, Bogor',
        'vehicle'    => $pickVeh('SMV', 0),
        'driver'     => $pickDrv(1),
        'price'      => 1750000.0,
        'pay_ratio'  => 1.0,
        'status'     => 'on_trip',
        'note'       => 'Sewa Avanza all-in 2 hari Tangerang - Megamendung (Lunas via Transfer)',
    ],
    [
        'pax_code'   => 'P-RNT04',
        'pax_name'   => 'Komunitas Alumni SMAN 2 Tangerang',
        'pax_phone'  => '6281177404004',
        'cat_code'   => 'SEL',
        'start_date' => '2026-11-26',
        'end_date'   => '2026-11-29',
        'time'       => '06:00:00',
        'pickup'     => $loc['tangerang'],
        'pickup_addr'=> 'Area Pusat Pemerintahan Kota Tangerang, Jl. Satria Sudirman',
        'deliv'      => $loc['anyer'],
        'deliv_addr' => 'Marbella Beach Resort, Anyer, Serang',
        'vehicle'    => $pickVeh('SEL', 0),
        'driver'     => $pickDrv(0),
        'price'      => 6400000.0,
        'pay_ratio'  => 0.4,
        'status'     => 'scheduled',
        'note'       => 'Sewa Elf Long 19 seat wisata Anyer 4 hari 3 malam (DP 40%)',
    ],
    [
        'pax_code'   => 'P-RNT05',
        'pax_name'   => 'Ibu Dewi Anggraini',
        'pax_phone'  => '6281988505005',
        'cat_code'   => 'SMV',
        'start_date' => '2026-11-28',
        'end_date'   => '2026-11-30',
        'time'       => '08:00:00',
        'pickup'     => $loc['cipondoh'],
        'pickup_addr'=> 'Cluster Banjar Wijaya Blok A7 No. 9, Cipondoh',
        'deliv'      => $loc['cisarua'],
        'deliv_addr' => 'Taman Safari & Villa Tjokro, Cisarua, Puncak',
        'vehicle'    => ['id' => 0, 'vehicle_number' => '(Belum ditentukan)'],
        'driver'     => ['id' => 0, 'name' => '(Belum ditugaskan)'],
        'price'      => 2650000.0,
        'pay_ratio'  => 0.0,
        'status'     => 'draft',
        'note'       => 'Menunggu konfirmasi unit Innova Reborn & penugasan sopir',
    ],
    [
        'pax_code'   => 'P-RNT06',
        'pax_name'   => 'Yayasan Pendidikan Nusantara',
        'pax_phone'  => '6281211606006',
        'cat_code'   => 'SMB',
        'start_date' => '2026-12-03',
        'end_date'   => '2026-12-06',
        'time'       => '05:00:00',
        'pickup'     => $loc['pasar_minggu'],
        'pickup_addr'=> 'Jl. Raya Pasar Minggu KM 18, Jakarta Selatan',
        'deliv'      => $loc['garut'],
        'deliv_addr' => 'Kawasan Wisata Cipanas, Garut Kota',
        'vehicle'    => $pickVeh('SMB', 0),
        'driver'     => $pickDrv(1),
        'price'      => 11200000.0,
        'pay_ratio'  => 0.0,
        'status'     => 'scheduled',
        'note'       => 'Medium Bus 31 seat study tour 4 hari Jakarta - Garut (belum ada pembayaran)',
    ],
    [
        'pax_code'   => 'P-RNT07',
        'pax_name'   => 'CV Sinar Abadi (Ibu Nadia)',
        'pax_phone'  => '6281566707007',
        'cat_code'   => 'SHC',
        'start_date' => '2026-12-10',
        'end_date'   => '2026-12-12',
        'time'       => '06:00:00',
        'pickup'     => $loc['karawaci'],
        'pickup_addr'=> 'Ruko Pinangsia Blok M No. 15, Karawaci, Tangerang',
        'deliv'      => $loc['ciwidey'],
        'deliv_addr' => 'Kawah Putih & Glamping Lakeside Rancabali, Ciwidey',
        'vehicle'    => $pickVeh('SHC', 2),
        'driver'     => $pickDrv(0),
        'price'      => 4650000.0,
        'pay_ratio'  => 0.5,
        'status'     => 'scheduled',
        'note'       => 'Outing kantor 3 hari Hiace Premio tujuan Ciwidey (DP 50%)',
    ],
];

$existingSampleIds = [];
$resEx = $db->query("SELECT id, document_number FROM rental_car_bookings WHERE company_id = {$cid} AND created_user = '" . $db->real_escape_string($actor) . "' ORDER BY id ASC");
if ($resEx) {
    $existingSampleIds = $resEx->fetch_all(MYSQLI_ASSOC);
    $resEx->close();
}

if (!$apply && !$cleanOnly) {
    echo "=== DRY-RUN Seeder Rental Multi-Hari (MTM company_id={$cid}) ===\n";
    echo "Cabang       : {$branchRow['code']} - {$branchRow['name']}\n";
    echo "Data sample  : " . count($existingSampleIds) . " booking sudah ada (actor={$actor})\n";
    echo "Skenario     :\n";
    foreach ($scenarios as $i => $s) {
        $days = rental_duration_days($s['start_date'], $s['end_date']);
        $catId = (int) ($cats[$s['cat_code']]['id'] ?? 0);
        echo sprintf(
            "  #%d %s s/d %s (%d hr) | %s(cat#%d) | %s -> %s | Unit=%s Driver=%s | %s | Rp %s\n",
            $i + 1,
            $s['start_date'],
            $s['end_date'],
            $days,
            $s['cat_code'],
            $catId,
            $s['pickup']['label'],
            $s['deliv']['label'],
            $s['vehicle']['vehicle_number'],
            $s['driver']['name'],
            $s['status'],
            number_format($s['price'], 0, ',', '.')
        );
    }
    echo "\nJalankan dengan --apply untuk menanam data ke DB.\n";
    exit(0);
}

$deletedCount = 0;
foreach ($existingSampleIds as $er) {
    booking_delete('rental', (int) $er['id'], $cid);
    $deletedCount++;
}

if ($cleanOnly) {
    $db->query("DELETE p FROM passengers p LEFT JOIN travel_ticket_bookings tb ON tb.passenger_id=p.id LEFT JOIN rental_car_bookings rb ON rb.passenger_id=p.id WHERE p.company_id={$cid} AND p.created_user='" . $db->real_escape_string($actor) . "' AND tb.id IS NULL AND rb.id IS NULL");
    echo "Cleanup selesai: {$deletedCount} booking sample dihapus.\n";
    exit(0);
}

$ensurePassenger = static function (string $code, string $name, string $phone) use ($db, $cid, $actor): int {
    $st = $db->prepare('SELECT id FROM passengers WHERE company_id = ? AND (code = ? OR phone_number = ?) LIMIT 1');
    $st->bind_param('iss', $cid, $code, $phone);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        return (int) $row['id'];
    }
    $note = 'Pelanggan contoh Rental Multi-Hari';
    $ins = $db->prepare('INSERT INTO passengers (company_id, code, name, phone_number, note, created_user) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->bind_param('isssss', $cid, $code, $name, $phone, $note, $actor);
    $ins->execute();
    $pid = (int) $ins->insert_id;
    $ins->close();
    return $pid;
};

$created = [];
foreach ($scenarios as $s) {
    $catId = (int) ($cats[$s['cat_code']]['id'] ?? 0);
    if ($catId <= 0) {
        throw new RuntimeException("Kategori rental {$s['cat_code']} tidak ditemukan pada MTM.");
    }
    $paxId = $ensurePassenger($s['pax_code'], $s['pax_name'], $s['pax_phone']);
    $days = rental_duration_days($s['start_date'], $s['end_date']);
    $vehId = (int) ($s['vehicle']['id'] ?? 0);
    $drvId = (int) ($s['driver']['id'] ?? 0);

    $res = booking_create('rental', [
        'company_id'                 => $cid,
        'branch_id'                  => $branchId,
        'date'                       => $s['start_date'],
        'time'                       => $s['time'],
        'start_date'                 => $s['start_date'],
        'end_date'                   => $s['end_date'],
        'trip'                       => $days,
        'pickup_address'             => $s['pickup_addr'],
        'pickup_point_province_id'   => $s['pickup']['province_id'],
        'pickup_point_city_id'       => $s['pickup']['city_id'],
        'pickup_point_district_id'   => $s['pickup']['district_id'],
        'delivery_address'           => $s['deliv_addr'],
        'delivery_point_province_id' => $s['deliv']['province_id'],
        'delivery_point_city_id'     => $s['deliv']['city_id'],
        'delivery_point_district_id' => $s['deliv']['district_id'],
        'bbm'                        => 0,
        'total_price'                => $s['price'],
        'category_vehicle_id'        => $catId,
        'vehicle_id'                 => $vehId > 0 ? $vehId : null,
        'passenger_id'               => $paxId,
        'note'                       => $s['note'],
        'created_user'               => $actor,
    ]);
    $bid = (int) $res['id'];
    $invId = (int) $res['invoice_id'];

    if ($drvId > 0) {
        booking_assign_driver('rental', $bid, $cid, $drvId, $vehId > 0 ? $vehId : null, $actor);
    }

    if ($s['pay_ratio'] > 0 && $invId > 0) {
        $amt = round($s['price'] * $s['pay_ratio'], 2);
        $payLabel = $s['pay_ratio'] >= 0.999 ? 'Pelunasan | Transfer Bank' : 'Down Payment (DP) | Transfer Bank';
        invoice_add_payment($invId, $cid, $amt, $payMethodId, $payLabel, $actor, false, null, true);
    }

    if ($s['status'] !== 'draft') {
        booking_change_status('rental', $bid, $cid, $s['status'], 'Status awal contoh rental multi-hari', $actor);
    }

    $created[] = $res['document_number'] . " ({$s['start_date']}..{$s['end_date']}, {$s['cat_code']}, {$s['status']})";
}

echo "Seeder Rental Multi-Hari selesai: {$deletedCount} lama dihapus, " . count($created) . " dibuat:\n";
foreach ($created as $line) {
    echo "  - {$line}\n";
}
