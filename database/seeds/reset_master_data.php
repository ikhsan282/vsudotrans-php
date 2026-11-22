<?php
/**
 * Reset & susun ulang master data Operasional, Armada, HR, Peraturan Komisi & Satuan
 * secara transaksional di seluruh perusahaan sambil menjaga 100% integritas FK data eksisting
 * (armada, rute, tarif rute, pesanan travel/rental, karyawan, wilayah BPS, bank).
 *
 * Penggunaan CLI:
 *   php database/seeds/reset_master_data.php           # Dry-run preview (tanpa mengubah DB)
 *   php database/seeds/reset_master_data.php --apply   # Eksekusi atomik di DB
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script ini hanya dapat dijalankan lewat CLI.');
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/regulation_service.php';

$apply  = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply || in_array('--dry-run', $argv ?? [], true);

// Pastikan skema tambahan tersedia sebelum memulai transaksi (DDL menyebabkan implicit commit)
category_service_ensure_schema();
rute_tariff_ensure_schema();
regulation_ensure_schema();

$db      = db();
$actor   = 'master_reseed';
$hasPremi = ($db->query("SHOW TABLES LIKE 'premi_configs'")->num_rows > 0);

// 1. Muat seluruh perusahaan aktif/terdaftar
$companies = $db->query('SELECT id, code, name FROM companies ORDER BY id ASC')->fetch_all(MYSQLI_ASSOC);
if (!$companies) {
    fwrite(STDERR, "Gagal: tabel companies kosong.\n");
    exit(1);
}

// 2. Snapshot peta ID lama -> (company_id, code) & ikatan FK saat ini sebelum penghapusan
$oldBrands = [];
foreach ($db->query('SELECT id, company_id, code FROM brand_vehicles')->fetch_all(MYSQLI_ASSOC) as $r) {
    $oldBrands[(int) $r['id']] = ['company_id' => (int) $r['company_id'], 'code' => strtoupper(trim((string) $r['code']))];
}
$oldModels = [];
foreach ($db->query('SELECT id, company_id, code FROM model_units')->fetch_all(MYSQLI_ASSOC) as $r) {
    $oldModels[(int) $r['id']] = ['company_id' => (int) $r['company_id'], 'code' => strtoupper(trim((string) $r['code']))];
}
$oldCats = [];
foreach ($db->query('SELECT id, company_id, code FROM category_vehicles')->fetch_all(MYSQLI_ASSOC) as $r) {
    $oldCats[(int) $r['id']] = ['company_id' => (int) $r['company_id'], 'code' => strtoupper(trim((string) $r['code']))];
}

$snapVehicles = $db->query('SELECT id, company_id, brand_id, model_unit_id, category_vehicle_id FROM vehicles')->fetch_all(MYSQLI_ASSOC);
$snapRutes    = $db->query('SELECT id, company_id, category_vehicle_id FROM rutes WHERE category_vehicle_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
$snapTariffs  = $db->query('SELECT id, company_id, category_vehicle_id FROM rute_tariffs')->fetch_all(MYSQLI_ASSOC);
$snapTrvBk    = $db->query('SELECT id, company_id, category_vehicle_id FROM travel_ticket_bookings WHERE category_vehicle_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
$snapRntBk    = $db->query('SELECT id, company_id, category_vehicle_id FROM rental_car_bookings WHERE category_vehicle_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
$snapPremi    = $hasPremi
    ? $db->query('SELECT id, company_id, category_vehicle_id FROM premi_configs WHERE category_vehicle_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC)
    : [];

// 3. Definisi katalog master data standar & seragam
$unitSeeds = [
    ['KRS',  'Kursi / Seat',  '0',    'Satuan penumpang / kursi tiket travel'],
    ['UNIT', 'Unit / Mobil',  '0',    'Satuan kendaraan untuk carter drop & sewa'],
    ['PKT',  'Paket / Koli',  '0',    'Satuan kiriman dokumen, barang, atau hewan'],
    ['HARI', 'Hari',          '0',    'Satuan durasi sewa harian rental'],
    ['TRIP', 'Trip / Rit',    '0',    'Satuan perjalanan sekali jalan atau pulang-pergi'],
    ['KM',   'Kilometer',     '0.00', 'Satuan jarak tempuh dan radius penjemputan/pengantaran'],
    ['LTR',  'Liter',         '0.00', 'Satuan bahan bakar (BBM)'],
    ['KG',   'Kilogram',      '0.00', 'Satuan berat bagasi atau paket'],
    ['PCS',  'Pcs / Buah',    '0',    'Satuan suku cadang dan perlengkapan armada'],
];

$brandSeeds = [
    ['TOYOTA',     'Toyota',        'MPV, Minibus & Hiace operasional'],
    ['ISUZU',      'Isuzu',         'Elf Short & Elf Long pariwisata dan travel'],
    ['MITSUBISHI', 'Mitsubishi',    'MPV & kendaraan niaga ringan'],
    ['HINO',       'Hino',          'Chassis Medium Bus & Big Bus'],
    ['MERC',       'Mercedes-Benz', 'Chassis bus premium antarkota dan pariwisata'],
    ['ADIPUTRO',   'Adiputro',      'Karoseri bodi bus (Jetbus / HD)'],
];

$modelSeeds = [
    ['TOYOTA',     'AVANZA',    'Avanza',              'MPV standar operasional 7 seat'],
    ['TOYOTA',     'INNOVA',    'Innova Reborn',       'MPV eksekutif 7 seat'],
    ['TOYOTA',     'HIACE-COM', 'Hiace Commuter',      'Minibus travel/carter 14 seat'],
    ['TOYOTA',     'HIACE-PRE', 'Hiace Premio',        'Minibus eksekutif 12-14 seat'],
    ['ISUZU',      'ELF-SHORT', 'Elf NMR 71 Short',    'Microbus 12-14 seat'],
    ['ISUZU',      'ELF-LONG',  'Elf NLR 55 Long',     'Microbus long chassis 19 seat'],
    ['MITSUBISHI', 'XPANDER',   'Xpander',             'MPV operasional 7 seat'],
    ['MITSUBISHI', 'L300',      'Colt L300',           'Kendaraan niaga & logistik paket'],
    ['HINO',       'RK8',       'Hino RK8 R260',       'Chassis Big Bus bermesin belakang'],
    ['HINO',       'RM280',     'Hino RM 280',         'Chassis Big Bus air suspension'],
    ['MERC',       'OF1723',    'OF 1723',             'Chassis bus antarkota front-engine'],
    ['MERC',       'OH1626',    'OH 1626',             'Chassis bus pariwisata air suspension'],
    ['MERC',       'OC500',     'OC 500 RF',           'Chassis premium tronton / long distance'],
    ['ADIPUTRO',   'JB3P',      'Jetbus 3+',           'Bodi karoseri Medium & Big Bus'],
    ['ADIPUTRO',   'HD5',       'HD 5 (High Deck)',    'Bodi karoseri High Deck'],
];

$categorySeeds = [
    ['REG', 'Travel Reguler',        'travel', 'per_seat',    'Layanan Travel Reguler Door-to-Door (tarif per kursi)'],
    ['PKT', 'Paket Dokumen/Barang',  'travel', 'per_package', 'Pengiriman paket dokumen atau barang via travel reguler (tarif per paket)'],
    ['HWN', 'Paket Hewan',           'travel', 'per_package', 'Pengiriman paket hewan peliharaan via travel reguler (tarif per paket)'],
    ['CMB', 'Carter Drop Mini Bus',  'travel', 'per_vehicle', 'Travel private / carter drop sekali jalan unit Avanza atau Innova (tarif per mobil)'],
    ['CHC', 'Carter Drop Hiace',     'travel', 'per_vehicle', 'Travel private / carter drop sekali jalan unit Hiace Commuter atau Premio (tarif per mobil)'],
    ['CEL', 'Carter Drop Elf Long',  'travel', 'per_vehicle', 'Travel private / carter drop sekali jalan unit Isuzu Elf Long (tarif per mobil)'],
    ['SMV', 'Sewa Mini Bus',         'rental', 'per_day',     'Sewa harian / wisata unit Avanza atau Innova termasuk driver (tarif per hari)'],
    ['SHC', 'Sewa Hiace',            'rental', 'per_day',     'Sewa harian / wisata unit Hiace Commuter atau Premio (tarif per hari)'],
    ['SEL', 'Sewa Elf Long',         'rental', 'per_day',     'Sewa harian / wisata unit Isuzu Elf Long 19 seat (tarif per hari)'],
    ['SMB', 'Sewa Medium Bus',       'rental', 'per_day',     'Sewa harian / wisata Medium Bus 31-35 seat (tarif per hari)'],
    ['SBB', 'Sewa Big Bus',          'rental', 'per_day',     'Sewa harian / wisata Big Bus 45-59 seat (tarif per hari)'],
];

$jobTitleSeeds = [
    ['JBT-DIR', 'Direktur',                 1, 12000000.0, 150000.0, 'Pimpinan tertinggi perusahaan'],
    ['JBT-MGR', 'Manajer',                  2,  8000000.0, 100000.0, 'Manajer divisi operasional / keuangan'],
    ['JBT-SPV', 'Supervisor / Koordinator', 3,  5500000.0,  75000.0, 'Koordinator lapangan dan armada'],
    ['JBT-STF', 'Staff / Admin',            4,  4000000.0,  50000.0, 'Staff administrasi, tiket, CS & kasir'],
    ['JBT-OPR', 'Kru Operasional',          5,  3000000.0,  50000.0, 'Driver, kernet, dan mekanik armada'],
];

$jobPositionSeeds = [
    ['POS-DIR',     'Direktur Utama',              'JBT-DIR', null,          1],
    ['POS-MGR-OPS', 'Manajer Operasional',         'JBT-MGR', 'POS-DIR',     2],
    ['POS-MGR-KEU', 'Manajer Keuangan & Admin',    'JBT-MGR', 'POS-DIR',     2],
    ['POS-KOORD',   'Koordinator Driver & Armada', 'JBT-SPV', 'POS-MGR-OPS', 3],
    ['POS-ADM-TRV', 'Admin Tiket & CS',            'JBT-STF', 'POS-MGR-OPS', 4],
    ['POS-ADM-KEU', 'Staff Keuangan & Kasir',      'JBT-STF', 'POS-MGR-KEU', 4],
    ['POS-DRV',     'Driver',                      'JBT-OPR', 'POS-KOORD',   5],
    ['POS-KRNT',    'Kernet',                      'JBT-OPR', 'POS-KOORD',   5],
    ['POS-MKNK',    'Mekanik Armada',              'JBT-OPR', 'POS-KOORD',   5],
];

$orgStructSeeds = [
    ['ORG-DIR', 'Direksi',                           null,      1],
    ['ORG-OPS', 'Divisi Operasional & Armada',       'ORG-DIR', 2],
    ['ORG-TRV', 'Unit Layanan Travel',               'ORG-OPS', 3],
    ['ORG-RNT', 'Unit Layanan Rental & Wisata',      'ORG-OPS', 3],
    ['ORG-MNT', 'Unit Bengkel & Pemeliharaan Armada','ORG-OPS', 3],
    ['ORG-KEU', 'Divisi Keuangan & Administrasi',    'ORG-DIR', 2],
    ['ORG-CS',  'Divisi Pemasaran & Layanan Pelanggan', 'ORG-DIR', 2],
];

$regSeeds = [
    'REG' => [
        ['Sopir', 'Persentase', 15.0],
        ['BBM',   'Persentase', 25.0],
        ['Agen',  'Tetap',      20000.0],
    ],
    'CMB' => [
        ['Sopir', 'Persentase', 15.0],
        ['BBM',   'Persentase', 25.0],
        ['Agen',  'Tetap',      50000.0],
    ],
    'CHC' => [
        ['Sopir',  'Persentase', 14.0],
        ['BBM',    'Persentase', 25.0],
        ['Montir', 'Tetap',      50000.0],
        ['Agen',   'Tetap',      75000.0],
    ],
    'CEL' => [
        ['Sopir',  'Persentase', 14.0],
        ['Kernet', 'Tetap',      100000.0],
        ['BBM',    'Persentase', 25.0],
        ['Montir', 'Tetap',      75000.0],
        ['Agen',   'Tetap',      75000.0],
    ],
    'SMV' => [
        ['Sopir', 'Tetap',      200000.0],
        ['BBM',   'Persentase', 20.0],
        ['Agen',  'Tetap',      50000.0],
    ],
    'SHC' => [
        ['Sopir',  'Persentase', 13.5],
        ['BBM',    'Persentase', 25.0],
        ['Montir', 'Tetap',      75000.0],
        ['Agen',   'Tetap',      50000.0],
    ],
    'SEL' => [
        ['Sopir',  'Persentase', 13.5],
        ['Kernet', 'Tetap',      125000.0],
        ['BBM',    'Persentase', 25.0],
        ['Montir', 'Tetap',      100000.0],
        ['Agen',   'Tetap',      50000.0],
    ],
    'SMB' => [
        ['Sopir',  'Persentase', 13.5],
        ['Kernet', 'Tetap',      150000.0],
        ['Montir', 'Tetap',      120000.0],
        ['BBM',    'Persentase', 26.5],
        ['Agen',   'Tetap',      50000.0],
    ],
    'SBB' => [
        ['Sopir',  'Persentase', 13.5],
        ['Kernet', 'Tetap',      150000.0],
        ['Montir', 'Tetap',      145000.0],
        ['BBM',    'Persentase', 26.5],
        ['Agen',   'Tetap',      50000.0],
    ],
];

// Validasi dry-run: pastikan seluruh FK lama punya padanan kode di katalog baru
$brandCodesAvail = array_column($brandSeeds, 0);
$modelCodesAvail = array_column($modelSeeds, 1);
$catCodesAvail   = array_column($categorySeeds, 0);
$missingMap = [];

foreach ($snapVehicles as $sv) {
    $ob = $oldBrands[(int) $sv['brand_id']] ?? null;
    if (!$ob || !in_array($ob['code'], $brandCodesAvail, true)) {
        $missingMap[] = "vehicles#{$sv['id']} brand_id={$sv['brand_id']}";
    }
    $om = $oldModels[(int) $sv['model_unit_id']] ?? null;
    if (!$om || !in_array($om['code'], $modelCodesAvail, true)) {
        $missingMap[] = "vehicles#{$sv['id']} model_unit_id={$sv['model_unit_id']}";
    }
    if (!empty($sv['category_vehicle_id'])) {
        $oc = $oldCats[(int) $sv['category_vehicle_id']] ?? null;
        if (!$oc || !in_array($oc['code'], $catCodesAvail, true)) {
            $missingMap[] = "vehicles#{$sv['id']} category_vehicle_id={$sv['category_vehicle_id']}";
        }
    }
}
foreach (['rutes' => $snapRutes, 'rute_tariffs' => $snapTariffs, 'travel_ticket_bookings' => $snapTrvBk, 'rental_car_bookings' => $snapRntBk, 'premi_configs' => $snapPremi] as $tbl => $rows) {
    foreach ($rows as $sr) {
        $oc = $oldCats[(int) $sr['category_vehicle_id']] ?? null;
        if (!$oc || !in_array($oc['code'], $catCodesAvail, true)) {
            $missingMap[] = "{$tbl}#{$sr['id']} category_vehicle_id={$sr['category_vehicle_id']}";
        }
    }
}

if ($missingMap) {
    fwrite(STDERR, "Gagal: terdapat FK lama yang belum tercakup katalog baru:\n - " . implode("\n - ", array_slice($missingMap, 0, 20)) . "\n");
    exit(1);
}

$compCount = count($companies);
if ($dryRun) {
    echo "=== DRY-RUN Reset & Re-Seed Master Data (--apply untuk eksekusi) ===\n";
    echo "Perusahaan target ({$compCount}): " . implode(', ', array_column($companies, 'code')) . "\n";
    echo "Akan menyusun ulang:\n";
    echo "  - unit_measures                 : " . count($unitSeeds) . " (global)\n";
    echo "  - brand_vehicles                : " . (count($brandSeeds) * $compCount) . " (" . count($brandSeeds) . " x {$compCount})\n";
    echo "  - model_units                   : " . (count($modelSeeds) * $compCount) . " (" . count($modelSeeds) . " x {$compCount})\n";
    echo "  - category_vehicles             : " . (count($categorySeeds) * $compCount) . " (" . count($categorySeeds) . " x {$compCount})\n";
    echo "  - job_titles                    : " . (count($jobTitleSeeds) * $compCount) . " (" . count($jobTitleSeeds) . " x {$compCount})\n";
    echo "  - job_positions                 : " . (count($jobPositionSeeds) * $compCount) . " (" . count($jobPositionSeeds) . " x {$compCount})\n";
    echo "  - organization_structures       : " . (count($orgStructSeeds) * $compCount) . " (" . count($orgStructSeeds) . " x {$compCount})\n";
    echo "  - rental_car_regulation_employees : " . (count($regSeeds) * $compCount) . " (" . count($regSeeds) . " x {$compCount})\n";
    echo "Remap FK siap: vehicles=" . count($snapVehicles)
        . ", rutes=" . count($snapRutes)
        . ", rute_tariffs=" . count($snapTariffs)
        . ", travel_bookings=" . count($snapTrvBk)
        . ", rental_bookings=" . count($snapRntBk) . "\n";
    exit(0);
}

$db->begin_transaction();
try {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');

    // A. Hapus seluruh data master target dalam satu transaksi (anak terlebih dahulu)
    foreach ([
        'rental_car_regulation_employee_details',
        'rental_car_regulation_employees',
        'model_units',
        'brand_vehicles',
        'category_vehicles',
        'job_positions',
        'job_titles',
        'organization_structures',
        'unit_measures',
    ] as $delTbl) {
        $db->query("DELETE FROM `{$delTbl}`");
    }

    // B. Re-seed unit_measures (global)
    $stUm = $db->prepare('INSERT INTO unit_measures (code, name, format, note, created_user) VALUES (?, ?, ?, ?, ?)');
    foreach ($unitSeeds as [$uCode, $uName, $uFmt, $uNote]) {
        $stUm->bind_param('sssss', $uCode, $uName, $uFmt, $uNote, $actor);
        $stUm->execute();
    }
    $stUm->close();

    $newBrands = [];
    $newModels = [];
    $newCats   = [];

    $stBr  = $db->prepare('INSERT INTO brand_vehicles (company_id, code, name, note, is_active, created_user) VALUES (?, ?, ?, ?, 1, ?)');
    $stMd  = $db->prepare('INSERT INTO model_units (company_id, brand_id, code, name, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, 1, ?)');
    $stCat = $db->prepare('INSERT INTO category_vehicles (company_id, code, name, service_type, pricing_unit, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    $stJt  = $db->prepare('INSERT INTO job_titles (company_id, code, name, level, basic_salary, daily_allowance, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $stJp  = $db->prepare('INSERT INTO job_positions (company_id, job_title_id, parent_id, code, name, level, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    $stOrg = $db->prepare('INSERT INTO organization_structures (company_id, parent_id, code, name, level, is_active, created_user) VALUES (?, ?, ?, ?, ?, 1, ?)');
    $stReg = $db->prepare("INSERT INTO rental_car_regulation_employees (company_id, category_vehicle_id, trip, start_date, end_date, type, amount, note, created_user) VALUES (?, ?, 1, '2026-01-01', '9999-12-31', 'Komisi', ?, ?, ?)");
    $stDet = $db->prepare('INSERT INTO rental_car_regulation_employee_details (regulation_id, line_no, employee_type, calc_type, amount, created_user) VALUES (?, ?, ?, ?, ?, ?)');

    // C. Re-seed seluruh master per perusahaan
    foreach ($companies as $comp) {
        $cid = (int) $comp['id'];

        foreach ($brandSeeds as [$bCode, $bName, $bNote]) {
            $stBr->bind_param('issss', $cid, $bCode, $bName, $bNote, $actor);
            $stBr->execute();
            $newBrands[$cid][$bCode] = (int) $db->insert_id;
        }

        foreach ($modelSeeds as [$bCode, $mCode, $mName, $mNote]) {
            $bId = $newBrands[$cid][$bCode] ?? 0;
            if ($bId <= 0) {
                throw new RuntimeException("Brand {$bCode} gagal ditemukan untuk perusahaan {$cid}.");
            }
            $stMd->bind_param('iissss', $cid, $bId, $mCode, $mName, $mNote, $actor);
            $stMd->execute();
            $newModels[$cid][$mCode] = (int) $db->insert_id;
        }

        foreach ($categorySeeds as [$cCode, $cName, $svcType, $pUnit, $cNote]) {
            $stCat->bind_param('issssss', $cid, $cCode, $cName, $svcType, $pUnit, $cNote, $actor);
            $stCat->execute();
            $newCats[$cid][$cCode] = (int) $db->insert_id;
        }

        $newTitles = [];
        foreach ($jobTitleSeeds as [$jtCode, $jtName, $jtLvl, $jtSal, $jtAllw, $jtNote]) {
            $stJt->bind_param('issiddss', $cid, $jtCode, $jtName, $jtLvl, $jtSal, $jtAllw, $jtNote, $actor);
            $stJt->execute();
            $newTitles[$jtCode] = (int) $db->insert_id;
        }

        $newPositions = [];
        foreach ($jobPositionSeeds as [$jpCode, $jpName, $jtCode, $parCode, $jpLvl]) {
            $jtId  = $newTitles[$jtCode] ?? 0;
            $parId = $parCode !== null ? ($newPositions[$parCode] ?? null) : null;
            $stJp->bind_param('iiissis', $cid, $jtId, $parId, $jpCode, $jpName, $jpLvl, $actor);
            $stJp->execute();
            $newPositions[$jpCode] = (int) $db->insert_id;
        }

        $newOrgs = [];
        foreach ($orgStructSeeds as [$oCode, $oName, $oParCode, $oLvl]) {
            $oParId = $oParCode !== null ? ($newOrgs[$oParCode] ?? null) : null;
            $stOrg->bind_param('iissis', $cid, $oParId, $oCode, $oName, $oLvl, $actor);
            $stOrg->execute();
            $newOrgs[$oCode] = (int) $db->insert_id;
        }

        foreach ($regSeeds as $cCode => $detList) {
            $catId = $newCats[$cid][$cCode] ?? 0;
            if ($catId <= 0) {
                throw new RuntimeException("Kategori {$cCode} tidak ditemukan saat seed regulasi perusahaan {$cid}.");
            }
            $detBuilt = [];
            $sumAmt   = 0.0;
            foreach ($detList as [$empType, $calcType, $amt]) {
                $detBuilt[] = ['employee_type' => $empType, 'calc_type' => $calcType, 'amount' => (float) $amt];
                $sumAmt    += (float) $amt;
            }
            $sumAmt  = round($sumAmt, 2);
            $regNote = regulation_build_summary($detBuilt);
            $stReg->bind_param('iidss', $cid, $catId, $sumAmt, $regNote, $actor);
            $stReg->execute();
            $regId = (int) $db->insert_id;

            $lineNo = 1;
            foreach ($detBuilt as $dl) {
                $stDet->bind_param('iissds', $regId, $lineNo, $dl['employee_type'], $dl['calc_type'], $dl['amount'], $actor);
                $stDet->execute();
                $lineNo++;
            }
        }
    }

    $stBr->close();
    $stMd->close();
    $stCat->close();
    $stJt->close();
    $stJp->close();
    $stOrg->close();
    $stReg->close();
    $stDet->close();

    // D. Remap seluruh FK eksisting berdasarkan snapshot primary key `id` (kebal tabrakan ID lama/baru)
    $stUpVeh = $db->prepare('UPDATE vehicles SET brand_id = ?, model_unit_id = ?, category_vehicle_id = ? WHERE id = ?');
    foreach ($snapVehicles as $sv) {
        $vcid  = (int) $sv['company_id'];
        $ob    = $oldBrands[(int) $sv['brand_id']] ?? null;
        $om    = $oldModels[(int) $sv['model_unit_id']] ?? null;
        $newB  = $ob ? ($newBrands[$vcid][$ob['code']] ?? 0) : 0;
        $newM  = $om ? ($newModels[$vcid][$om['code']] ?? 0) : 0;
        if ($newB <= 0 || $newM <= 0) {
            throw new RuntimeException("Gagal remap FK kendaraan #{$sv['id']} (company={$vcid}).");
        }
        $newC = null;
        if (!empty($sv['category_vehicle_id'])) {
            $oc   = $oldCats[(int) $sv['category_vehicle_id']] ?? null;
            $newC = $oc ? ($newCats[$vcid][$oc['code']] ?? 0) : 0;
            if ($newC <= 0) {
                throw new RuntimeException("Gagal remap category_vehicle_id kendaraan #{$sv['id']}.");
            }
        }
        $vid = (int) $sv['id'];
        $stUpVeh->bind_param('iiii', $newB, $newM, $newC, $vid);
        $stUpVeh->execute();
    }
    $stUpVeh->close();

    $remapCategoryRows = static function (mysqli $db, string $table, array $snapRows, array $oldCats, array $newCats): void {
        if (!$snapRows) {
            return;
        }
        $st = $db->prepare("UPDATE `{$table}` SET category_vehicle_id = ? WHERE id = ?");
        foreach ($snapRows as $sr) {
            $rcid  = (int) $sr['company_id'];
            $oc    = $oldCats[(int) $sr['category_vehicle_id']] ?? null;
            $newC  = $oc ? ($newCats[$rcid][$oc['code']] ?? 0) : 0;
            if ($newC <= 0) {
                throw new RuntimeException("Gagal remap category_vehicle_id pada {$table}#{$sr['id']} (company={$rcid}).");
            }
            $rid = (int) $sr['id'];
            $st->bind_param('ii', $newC, $rid);
            $st->execute();
        }
        $st->close();
    };

    $remapCategoryRows($db, 'rutes', $snapRutes, $oldCats, $newCats);
    $remapCategoryRows($db, 'rute_tariffs', $snapTariffs, $oldCats, $newCats);
    $remapCategoryRows($db, 'travel_ticket_bookings', $snapTrvBk, $oldCats, $newCats);
    $remapCategoryRows($db, 'rental_car_bookings', $snapRntBk, $oldCats, $newCats);
    if ($hasPremi) {
        $remapCategoryRows($db, 'premi_configs', $snapPremi, $oldCats, $newCats);
    }

    // E. Sinkronkan nilai VARCHAR HR karyawan agar konsisten dengan master HR yang baru disusun
    $empRows = $db->query('SELECT id, job_title, job_position FROM employees')->fetch_all(MYSQLI_ASSOC);
    $stUpEmp = $db->prepare('UPDATE employees SET job_title = ?, job_position = ?, organization_structure = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
    foreach ($empRows as $er) {
        $jtIn = trim((string) ($er['job_title'] ?? ''));
        $jpIn = trim((string) ($er['job_position'] ?? ''));
        if ($jpIn === 'Driver') {
            $newJt = 'Kru Operasional';
            $newJp = 'Driver';
            $newOrg = 'Divisi Operasional & Armada';
        } elseif ($jpIn === 'Kernet' || $jtIn === 'Kernet') {
            $newJt = 'Kru Operasional';
            $newJp = 'Kernet';
            $newOrg = 'Divisi Operasional & Armada';
        } elseif ($jtIn === 'Mekanik' || str_contains($jpIn, 'Mekanik')) {
            $newJt = 'Kru Operasional';
            $newJp = 'Mekanik Armada';
            $newOrg = 'Unit Bengkel & Pemeliharaan Armada';
        } elseif ($jtIn === 'Koordinator Sopir' || str_contains($jpIn, 'Koordinator')) {
            $newJt = 'Supervisor / Koordinator';
            $newJp = 'Koordinator Driver & Armada';
            $newOrg = 'Divisi Operasional & Armada';
        } elseif (str_contains($jtIn, 'Manajer') || $jpIn === 'Operasional' || str_contains($jpIn, 'Manajer')) {
            $newJt = 'Manajer';
            $newJp = 'Manajer Operasional';
            $newOrg = 'Divisi Operasional & Armada';
        } elseif (str_contains($jpIn, 'Admin') || str_contains($jtIn, 'Admin')) {
            $newJt = 'Staff / Admin';
            $newJp = 'Admin Tiket & CS';
            $newOrg = 'Divisi Operasional & Armada';
        } else {
            $newJt = 'Staff / Admin';
            $newJp = 'Admin Tiket & CS';
            $newOrg = 'Divisi Operasional & Armada';
        }
        $eid = (int) $er['id'];
        $stUpEmp->bind_param('ssssi', $newJt, $newJp, $newOrg, $actor, $eid);
        $stUpEmp->execute();
    }
    $stUpEmp->close();

    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    // F. Verifikasi integritas penuh sebelum commit (bila ada anomali -> rollback otomatis)
    $integrityChecks = [
        'orphan_vehicle_brand'     => "SELECT COUNT(*) FROM vehicles v LEFT JOIN brand_vehicles b ON b.id = v.brand_id AND b.company_id = v.company_id WHERE b.id IS NULL",
        'orphan_vehicle_model'     => "SELECT COUNT(*) FROM vehicles v LEFT JOIN model_units m ON m.id = v.model_unit_id AND m.company_id = v.company_id WHERE m.id IS NULL",
        'orphan_vehicle_cat'       => "SELECT COUNT(*) FROM vehicles v LEFT JOIN category_vehicles c ON c.id = v.category_vehicle_id AND c.company_id = v.company_id WHERE v.category_vehicle_id IS NOT NULL AND c.id IS NULL",
        'orphan_rute_cat'          => "SELECT COUNT(*) FROM rutes r LEFT JOIN category_vehicles c ON c.id = r.category_vehicle_id AND c.company_id = r.company_id WHERE r.category_vehicle_id IS NOT NULL AND c.id IS NULL",
        'orphan_rute_tariff_cat'   => "SELECT COUNT(*) FROM rute_tariffs rt LEFT JOIN category_vehicles c ON c.id = rt.category_vehicle_id AND c.company_id = rt.company_id WHERE c.id IS NULL",
        'orphan_travel_bk_cat'     => "SELECT COUNT(*) FROM travel_ticket_bookings tb LEFT JOIN category_vehicles c ON c.id = tb.category_vehicle_id AND c.company_id = tb.company_id WHERE tb.category_vehicle_id IS NOT NULL AND c.id IS NULL",
        'orphan_rental_bk_cat'     => "SELECT COUNT(*) FROM rental_car_bookings rb LEFT JOIN category_vehicles c ON c.id = rb.category_vehicle_id AND c.company_id = rb.company_id WHERE rb.category_vehicle_id IS NOT NULL AND c.id IS NULL",
        'cross_scope_rutes'        => "SELECT COUNT(*) FROM rutes r JOIN category_vehicles c ON c.id = r.category_vehicle_id WHERE c.service_type <> 'travel'",
        'cross_scope_rute_tariffs' => "SELECT COUNT(*) FROM rute_tariffs rt JOIN category_vehicles c ON c.id = rt.category_vehicle_id WHERE c.service_type <> 'travel'",
        'cross_scope_travel_bk'    => "SELECT COUNT(*) FROM travel_ticket_bookings tb JOIN category_vehicles c ON c.id = tb.category_vehicle_id WHERE c.service_type <> 'travel'",
        'cross_scope_rental_bk'    => "SELECT COUNT(*) FROM rental_car_bookings rb JOIN category_vehicles c ON c.id = rb.category_vehicle_id WHERE c.service_type <> 'rental'",
        'unmatched_emp_job_title'  => "SELECT COUNT(*) FROM employees e WHERE NOT EXISTS (SELECT 1 FROM job_titles jt WHERE jt.company_id = e.company_id AND jt.name = e.job_title)",
        'unmatched_emp_job_pos'    => "SELECT COUNT(*) FROM employees e WHERE NOT EXISTS (SELECT 1 FROM job_positions jp WHERE jp.company_id = e.company_id AND jp.name = e.job_position)",
        'unmatched_emp_org'        => "SELECT COUNT(*) FROM employees e WHERE NOT EXISTS (SELECT 1 FROM organization_structures os WHERE os.company_id = e.company_id AND os.name = e.organization_structure)",
    ];
    foreach ($integrityChecks as $label => $sqlChk) {
        $badCnt = (int) $db->query($sqlChk)->fetch_row()[0];
        if ($badCnt > 0) {
            throw new RuntimeException("Pemeriksaan integritas '{$label}' gagal ({$badCnt} baris bermasalah). Transaksi di-rollback.");
        }
    }

    $db->commit();
} catch (Throwable $ex) {
    try {
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignore) {
    }
    $db->rollback();
    fwrite(STDERR, "ERROR: " . $ex->getMessage() . "\n");
    exit(1);
}

echo "=== Reset & Re-Seed Master Data Selesai (COMMITTED) ===\n";
foreach ([
    'unit_measures',
    'brand_vehicles',
    'model_units',
    'category_vehicles',
    'job_titles',
    'job_positions',
    'organization_structures',
    'rental_car_regulation_employees',
    'rental_car_regulation_employee_details',
    'vehicles',
    'rutes',
    'rute_tariffs',
    'rute_radius_districts',
    'travel_ticket_bookings',
    'rental_car_bookings',
    'employees',
    'banks',
    'villages',
] as $tblSummary) {
    $cnt = (int) $db->query("SELECT COUNT(*) FROM `{$tblSummary}`")->fetch_row()[0];
    echo sprintf("  %-40s : %d\n", $tblSummary, $cnt);
}
