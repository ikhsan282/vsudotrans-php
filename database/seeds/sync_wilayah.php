<?php
declare(strict_types=1);

/**
 * Script Sinkronisasi Data Wilayah Administrasi Indonesia (Kemendagri No. 300.2.2-2138)
 * + Kodepos PT Pos Indonesia.
 *
 * Menghasilkan:
 * - 1 Negara (Indonesia)
 * - 38 Provinsi
 * - 514 Kota / Kabupaten
 * - 7.285 Kecamatan
 * - 83.762 Kelurahan / Desa + Kode Pos
 *
 * Serta melakukan remapping otomatis rute eksisting ke ID resmi Kota Tangerang & Kota Bandung.
 */

require_once __DIR__ . '/../../config/config.php';

$t0 = microtime(true);
$mysqli = db();
$mysqli->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

echo "=== SINKRONISASI DATA WILAYAH INDONESIA ===\n";
echo "Sumber: Kepmendagri No. 300.2.2-2138 + Kodepos PT Pos Indonesia\n\n";

$dir = __DIR__ . '/data';
$file_wilayah = $dir . '/wilayah.sql';
$file_kodepos = $dir . '/wilayah_kodepos.sql';

if (!file_exists($file_wilayah) || !file_exists($file_kodepos)) {
    echo "Mengunduh berkas sumber...\n";
    require_once __DIR__ . '/download.php';
}

echo "1. Memuat pemetaan kode pos ke memory...\n";
$kodepos_map = [];
$fkp = fopen($file_kodepos, 'r');
if (!$fkp) {
    die("Gagal membuka $file_kodepos\n");
}
$kp_pattern = "/\('([0-9.]+)',\s*'([0-9]+)'\)/";
while (($line = fgets($fkp)) !== false) {
    if (preg_match($kp_pattern, $line, $m)) {
        $kodepos_map[$m[1]] = $m[2];
    }
}
fclose($fkp);
echo "   -> Terbaca " . number_format(count($kodepos_map), 0, ',', '.') . " kode pos.\n";

echo "2. Membaca & mengklasifikasikan struktur wilayah...\n";
$provinces_raw = [];
$cities_raw = [];
$districts_raw = [];
$villages_count = 0;

$fw = fopen($file_wilayah, 'r');
if (!$fw) {
    die("Gagal membuka $file_wilayah\n");
}
$w_pattern = "/\('([0-9.]+)',\s*'((?:''|[^'])*)'\)/";

while (($line = fgets($fw)) !== false) {
    if (preg_match($w_pattern, $line, $m)) {
        $kode = $m[1];
        $nama = str_replace("''", "'", $m[2]);
        $len = strlen($kode);

        if ($len === 2) {
            $provinces_raw[] = ['code' => $kode, 'name' => $nama];
        } elseif ($len === 5) {
            $cities_raw[] = ['code' => $kode, 'name' => $nama, 'prov' => substr($kode, 0, 2)];
        } elseif ($len === 8) {
            $districts_raw[] = ['code' => $kode, 'name' => $nama, 'prov' => substr($kode, 0, 2), 'city' => substr($kode, 0, 5)];
        } elseif ($len === 13) {
            $villages_count++;
        }
    }
}
rewind($fw);

echo "   -> Terdata " . count($provinces_raw) . " Provinsi, " . count($cities_raw) . " Kota/Kab, " . count($districts_raw) . " Kecamatan, " . number_format($villages_count, 0, ',', '.') . " Desa.\n";

echo "3. Menyiapkan database & disable foreign keys sementara...\n";
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$mysqli->query("START TRANSACTION");

// Pastikan negara Indonesia (ID=1)
$mysqli->query("INSERT INTO countries (id, code, name, note, created_user)
                VALUES (1, 'ID', 'Indonesia', 'Republik Indonesia', 'system')
                ON DUPLICATE KEY UPDATE name = 'Indonesia'");

// Kosongkan tabel wilayah untuk fresh sync
$mysqli->query("TRUNCATE TABLE villages");
$mysqli->query("TRUNCATE TABLE districts");
$mysqli->query("TRUNCATE TABLE cities");
$mysqli->query("TRUNCATE TABLE provinces");

echo "4. Mengimpor 38 Provinsi...\n";
$stmt = $mysqli->prepare("INSERT INTO provinces (country_id, code, name, created_user) VALUES (1, ?, ?, 'system')");
$prov_map = []; // code -> id
foreach ($provinces_raw as $p) {
    $stmt->bind_param('ss', $p['code'], $p['name']);
    $stmt->execute();
    $prov_map[$p['code']] = (int) $mysqli->insert_id;
}
$stmt->close();
echo "   -> " . count($prov_map) . " provinsi berhasil disimpan.\n";

echo "5. Mengimpor 514 Kota / Kabupaten...\n";
$city_map = []; // code -> id
$stmt = $mysqli->prepare("INSERT INTO cities (country_id, province_id, code, name, created_user) VALUES (1, ?, ?, ?, 'system')");
foreach ($cities_raw as $c) {
    $prov_id = $prov_map[$c['prov']] ?? null;
    if (!$prov_id) {
        throw new RuntimeException("Province parent not found for city {$c['code']}");
    }
    $stmt->bind_param('iss', $prov_id, $c['code'], $c['name']);
    $stmt->execute();
    $city_map[$c['code']] = (int) $mysqli->insert_id;
}
$stmt->close();
echo "   -> " . count($city_map) . " kota/kabupaten berhasil disimpan.\n";

echo "6. Mengimpor " . count($districts_raw) . " Kecamatan...\n";
$dist_map = []; // code -> id
$batch = [];
$stmt = $mysqli->prepare("INSERT INTO districts (country_id, province_id, city_id, code, name, created_user) VALUES (1, ?, ?, ?, ?, 'system')");
foreach ($districts_raw as $d) {
    $prov_id = $prov_map[$d['prov']] ?? null;
    $city_id = $city_map[$d['city']] ?? null;
    if (!$prov_id || !$city_id) {
        throw new RuntimeException("Parent not found for district {$d['code']}");
    }
    $stmt->bind_param('iiss', $prov_id, $city_id, $d['code'], $d['name']);
    $stmt->execute();
    $dist_map[$d['code']] = (int) $mysqli->insert_id;
}
$stmt->close();
echo "   -> " . count($dist_map) . " kecamatan berhasil disimpan.\n";

echo "7. Mengimpor " . number_format($villages_count, 0, ',', '.') . " Kelurahan / Desa + Kode Pos (Batch 1.000)...\n";
$batch_rows = [];
$inserted_villages = 0;

$insert_batch = function(array &$rows) use ($mysqli, &$inserted_villages) {
    if (empty($rows)) return;
    $sql = "INSERT INTO villages (country_id, province_id, city_id, district_id, code, name, postal_code, created_user) VALUES " . implode(',', $rows);
    $mysqli->query($sql);
    $inserted_villages += count($rows);
    $rows = [];
};

while (($line = fgets($fw)) !== false) {
    if (preg_match($w_pattern, $line, $m)) {
        $kode = $m[1];
        if (strlen($kode) === 13) {
            $nama = str_replace("''", "'", $m[2]);
            $prov_code = substr($kode, 0, 2);
            $city_code = substr($kode, 0, 5);
            $dist_code = substr($kode, 0, 8);

            $prov_id = $prov_map[$prov_code] ?? null;
            $city_id = $city_map[$city_code] ?? null;
            $dist_id = $dist_map[$dist_code] ?? null;

            if ($prov_id && $city_id && $dist_id) {
                $pos = $kodepos_map[$kode] ?? null;
                $pos_sql = $pos !== null ? "'" . $mysqli->real_escape_string($pos) . "'" : "NULL";
                $nama_esc = $mysqli->real_escape_string($nama);
                $kode_esc = $mysqli->real_escape_string($kode);

                $batch_rows[] = "(1, {$prov_id}, {$city_id}, {$dist_id}, '{$kode_esc}', '{$nama_esc}', {$pos_sql}, 'system')";
                if (count($batch_rows) >= 1000) {
                    $insert_batch($batch_rows);
                }
            }
        }
    }
}
fclose($fw);
$insert_batch($batch_rows);
echo "   -> " . number_format($inserted_villages, 0, ',', '.') . " kelurahan/desa berhasil disimpan lengkap dengan kode pos.\n";

echo "8. Remapping rute eksisting ke ID resmi Kemendagri...\n";
$id_tangerang = $city_map['36.71'] ?? null; // Kota Tangerang
$id_bandung   = $city_map['32.73'] ?? null; // Kota Bandung

if ($id_tangerang && $id_bandung) {
    // Update rute Tangerang - Bandung
    $st = $mysqli->prepare("UPDATE rutes SET pickup_point_city_id = ?, delivery_point_city_id = ? WHERE code = 'TNG-BDG'");
    $st->bind_param('ii', $id_tangerang, $id_bandung);
    $st->execute();
    $st->close();
    echo "   -> Rute 'TNG-BDG' sukses dimapping ke Kota Tangerang (#{$id_tangerang}) & Kota Bandung (#{$id_bandung}).\n";

    // Update jika ada di travel_ticket_bookings atau trip_schedules
    $st = $mysqli->prepare("UPDATE travel_ticket_bookings SET pickup_point_city_id = ?, delivery_point_city_id = ? WHERE pickup_point_city_id IS NOT NULL");
    $st->bind_param('ii', $id_tangerang, $id_bandung);
    $st->execute();
    $st->close();

    $st = $mysqli->prepare("UPDATE trip_schedules SET pickup_point_city_id = ?, delivery_point_city_id = ? WHERE pickup_point_city_id IS NOT NULL");
    $st->bind_param('ii', $id_tangerang, $id_bandung);
    $st->execute();
    $st->close();
}

$mysqli->query("COMMIT");
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

$dur = round(microtime(true) - $t0, 2);
echo "\n=== SINKRONISASI SELESAI SUKSES DALAM {$dur} DETIK! ===\n";
