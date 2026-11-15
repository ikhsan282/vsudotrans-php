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

/**
 * Singkatan 3–4 huruf UPPERCASE (Opsi 1) untuk Master Kota.
 * Kota utama = 3 huruf, Kabupaten kembar = K + 3 huruf kota, wilayah arah = 3–4 huruf.
 */
function wilayah_city_short_name(string $code, string $name): string
{
    static $curated = [
        // DKI Jakarta
        '31.01' => 'KPS',  '31.71' => 'JKP',  '31.72' => 'JKU',  '31.73' => 'JKB',  '31.74' => 'JKS',  '31.75' => 'JKT',
        // Banten
        '36.01' => 'PDGL', '36.02' => 'LBK',  '36.03' => 'KTNG', '36.04' => 'KSRG',
        '36.71' => 'TNG',  '36.72' => 'CLG',  '36.73' => 'SRNG', '36.74' => 'TGSL',
        // Jawa Barat
        '32.01' => 'KBGR', '32.02' => 'KSKB', '32.03' => 'CJR',  '32.04' => 'KBDG', '32.05' => 'GRT',
        '32.06' => 'KTSM', '32.07' => 'CMS',  '32.08' => 'KNG',  '32.09' => 'KCRB', '32.10' => 'MJL',
        '32.11' => 'SMD',  '32.12' => 'IDM',  '32.13' => 'SBG',  '32.14' => 'PWK',  '32.15' => 'KRW',
        '32.16' => 'KBKS', '32.17' => 'KBB',  '32.18' => 'PGD',
        '32.71' => 'BGR',  '32.72' => 'SKB',  '32.73' => 'BDG',  '32.74' => 'CRB',  '32.75' => 'BKS',
        '32.76' => 'DPK',  '32.77' => 'CMH',  '32.78' => 'TSM',  '32.79' => 'BNJR',
        // Jawa Tengah
        '33.01' => 'CLC',  '33.02' => 'BMS',  '33.03' => 'PBG',  '33.04' => 'BJN',  '33.05' => 'KBM',
        '33.06' => 'PWR',  '33.07' => 'WSB',  '33.08' => 'KMGL', '33.09' => 'BYL',  '33.10' => 'KLT',
        '33.11' => 'SKH',  '33.12' => 'WNG',  '33.13' => 'KRA',  '33.14' => 'SRG',  '33.15' => 'GRB',
        '33.16' => 'BLR',  '33.17' => 'RBG',  '33.18' => 'PTI',  '33.19' => 'KDS',  '33.20' => 'JPR',
        '33.21' => 'DMK',  '33.22' => 'KSMG', '33.23' => 'TMG',  '33.24' => 'KDL',  '33.25' => 'BTG',
        '33.26' => 'KPKL', '33.27' => 'PML',  '33.28' => 'KTGL', '33.29' => 'BBS',
        '33.71' => 'MGL',  '33.72' => 'SLO',  '33.73' => 'SLG',  '33.74' => 'SMG',  '33.75' => 'PKL',  '33.76' => 'TGL',
        // DIY
        '34.01' => 'KLP',  '34.02' => 'BTL',  '34.03' => 'GNK',  '34.04' => 'SLM',  '34.71' => 'YGY',
        // Jawa Timur
        '35.01' => 'PCT',  '35.02' => 'PNG',  '35.03' => 'TRG',  '35.04' => 'TLG',  '35.05' => 'KBLT',
        '35.06' => 'KKDR', '35.07' => 'KMLG', '35.08' => 'LMJ',  '35.09' => 'JBR',  '35.10' => 'BWI',
        '35.11' => 'BDW',  '35.12' => 'STB',  '35.13' => 'KPBL', '35.14' => 'KPSR', '35.15' => 'SDA',
        '35.16' => 'KMJK', '35.17' => 'JBG',  '35.18' => 'NGK',  '35.19' => 'KMDN', '35.20' => 'MGT',
        '35.21' => 'NGW',  '35.22' => 'BJG',  '35.23' => 'TBN',  '35.24' => 'LMG',  '35.25' => 'GRS',
        '35.26' => 'BKL',  '35.27' => 'SMP',  '35.28' => 'PMK',  '35.29' => 'SMPN',
        '35.71' => 'KDR',  '35.72' => 'BLT',  '35.73' => 'MLG',  '35.74' => 'PBL',  '35.75' => 'PSR',
        '35.76' => 'MJK',  '35.77' => 'MDN',  '35.78' => 'SBY',  '35.79' => 'BTU',
        // Pasangan kembar & kota utama luar Jawa
        '13.02' => 'KSLK', '13.72' => 'SLK',
        '52.06' => 'KBIM', '52.72' => 'BIM',
        '53.01' => 'KKPG', '53.71' => 'KPG',
        '63.03' => 'KBJR', '63.71' => 'BJM',  '63.72' => 'BJB',
        '75.01' => 'KGTO', '75.71' => 'GTO',
        '91.03' => 'KJYP', '91.71' => 'JYP',
        '96.01' => 'KSRM', '96.71' => 'SRM',
        '12.71' => 'MDN',  '13.71' => 'PDG',  '14.71' => 'PKU',  '16.71' => 'PLG',  '18.71' => 'BDL',
        '51.71' => 'DPS',  '64.71' => 'BPN',  '64.72' => 'SMR',  '71.71' => 'MDO',  '73.71' => 'MKS',
    ];
    if (isset($curated[$code])) {
        return $curated[$code];
    }

    $clean = trim((string) preg_replace('/^(?:Kota\s+Administrasi|Kabupaten\s+Administrasi|Kota|Kabupaten)\s+/i', '', $name));
    $clean = strtoupper((string) preg_replace('/[^A-Za-z\s]/', '', $clean));
    $words = array_values(array_filter(explode(' ', $clean)));
    if (!$words) {
        return 'KTA';
    }
    if (count($words) === 1) {
        $w = $words[0];
        $cons = (string) preg_replace('/[AEIOU]/', '', substr($w, 1));
        $cand = substr($w, 0, 1) . $cons;
        if (strlen($cand) < 3) {
            $cand = substr($w . 'XXX', 0, 3);
        }
        return substr($cand, 0, 3);
    }

    $dirMap = [
        'UTARA' => 'U', 'SELATAN' => 'S', 'BARAT' => 'B', 'TIMUR' => 'T',
        'TENGAH' => 'TG', 'TENGGARA' => 'TG', 'DAYA' => 'BD',
    ];
    $last = end($words);
    if (isset($dirMap[$last])) {
        $first = $words[0];
        $cons = (string) preg_replace('/[AEIOU]/', '', substr($first, 1));
        $base = substr($first . $cons, 0, 2);
        $res = $base . $dirMap[$last];
        if (strlen($res) < 3) {
            $res = substr($first, 0, 2) . $dirMap[$last];
        }
        return substr($res, 0, 4);
    }

    if (count($words) >= 3) {
        return substr($words[0][0] . $words[1][0] . $words[2][0] . ($words[3][0] ?? ''), 0, 4);
    }

    $w1 = $words[0];
    $w2 = $words[1];
    $c1 = substr($w1, 0, 1) . substr((string) preg_replace('/[AEIOU]/', '', substr($w1, 1)) . substr($w1, 1), 0, 1);
    $c2 = substr($w2, 0, 1) . substr((string) preg_replace('/[AEIOU]/', '', substr($w2, 1)) . substr($w2, 1), 0, 1);
    return substr($c1 . $c2, 0, 4);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

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
$stmt = $mysqli->prepare("INSERT INTO cities (country_id, province_id, code, name, short_name, created_user) VALUES (1, ?, ?, ?, ?, 'system')");
foreach ($cities_raw as $c) {
    $prov_id = $prov_map[$c['prov']] ?? null;
    if (!$prov_id) {
        throw new RuntimeException("Province parent not found for city {$c['code']}");
    }
    $short_name = wilayah_city_short_name($c['code'], $c['name']);
    $stmt->bind_param('isss', $prov_id, $c['code'], $c['name'], $short_name);
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

