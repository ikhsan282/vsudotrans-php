<?php
/**
 * Seeder transaksi Travel Maura Trans Mandiri (company_id = 1) dari ekspor grup WhatsApp
 * (`chat.txt` & `chat.md`) bagian "Form Pemesanan Tiket Travel Door to door".
 *
 * Usage:
 *   php database/seeds/seed_travel_from_wa_forms.php --zip="path/to/archive.zip"                           (dry-run preview & validasi)
 *   php database/seeds/seed_travel_from_wa_forms.php --zip="path/to/archive.zip" --apply                   (ganti travel_ticket_bookings MTM)
 *   php database/seeds/seed_travel_from_wa_forms.php --zip="path/to/archive.zip" --apply --clean-all-transactions (hapus seluruh transaksi dummy lama + reset ID/invoice mulai 1, lalu seed WA)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/bot_auth.php';
require_once __DIR__ . '/../../includes/bot_staff_booking.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_once __DIR__ . '/../../includes/invoice_service.php';
require_once __DIR__ . '/../../includes/finance_sync.php';

$zipPath              = '';
$apply                = false;
$cleanAllTransactions = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--clean-all-transactions') {
        $cleanAllTransactions = true;
    } elseif (str_starts_with($arg, '--zip=')) {
        $zipPath = trim(substr($arg, 6), " \t\n\r\0\x0B\"'");
    }
}

if ($zipPath === '') {
    fwrite(STDERR, "Wajib menyertakan --zip=\"path/to/archive.zip\"\n");
    exit(1);
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "File ZIP tidak ditemukan: {$zipPath}\n");
    exit(1);
}

/** Ekstrak chat.txt & chat.md dari ZIP (pakai ZipArchive atau fallback PowerShell Windows). */
function wa_seed_read_zip_texts(string $zipPath): array
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Gagal membuka ZIP via ZipArchive: {$zipPath}");
        }
        $txt = (string) $zip->getFromName('chat.txt');
        $md  = (string) $zip->getFromName('chat.md');
        $zip->close();
        return [
            'chat.txt' => str_replace(["\r\n", "\r"], "\n", $txt),
            'chat.md'  => str_replace(["\r\n", "\r"], "\n", $md),
        ];
    }

    $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'vst_wa_seed_' . getmypid() . '_' . mt_rand(1000, 9999);
    mkdir($tmpDir, 0777, true);
    try {
        $psCmd = 'Add-Type -AssemblyName System.IO.Compression.FileSystem; '
            . '[System.IO.Compression.ZipFile]::ExtractToDirectory('
            . "'" . str_replace("'", "''", $zipPath) . "', "
            . "'" . str_replace("'", "''", $tmpDir) . "'"
            . ')';
        $cmd = 'powershell -NoProfile -Command ' . escapeshellarg($psCmd);
        $out = [];
        $rc  = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Gagal mengekstrak ZIP: ' . implode("\n", $out));
        }
        $txtFile = $tmpDir . DIRECTORY_SEPARATOR . 'chat.txt';
        $mdFile  = $tmpDir . DIRECTORY_SEPARATOR . 'chat.md';
        if (!is_file($txtFile) || !is_file($mdFile)) {
            throw new RuntimeException('ZIP harus berisi chat.txt dan chat.md.');
        }
        return [
            'chat.txt' => str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($txtFile)),
            'chat.md'  => str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($mdFile)),
        ];
    } finally {
        foreach (['chat.txt', 'chat.md'] as $f) {
            $p = $tmpDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($p)) {
                @unlink($p);
            }
        }
        if (is_dir($tmpDir)) {
            @rmdir($tmpDir);
        }
    }
}

/** Ambil nomor HP pertama yang valid (62...) dari teks yang mungkin berisi multi-nomor. */
function wa_seed_first_phone(string $raw): string
{
    if (preg_match('/(?:\+?62|0)8[0-9\-\s\.]{7,16}/', $raw, $m)) {
        return bot_normalize_phone($m[0]) ?? '';
    }
    return '';
}

/** Ambil seluruh nomor HP unik yang ada dalam string. */
function wa_seed_all_phones(string $raw): array
{
    $phones = [];
    if (preg_match_all('/(?:\+?62|0)8[0-9\-\s\.]{7,16}/', $raw, $ms)) {
        foreach ($ms[0] as $cand) {
            $norm = bot_normalize_phone($cand);
            if ($norm !== null && $norm !== '' && !in_array($norm, $phones, true)) {
                $phones[] = $norm;
            }
        }
    }
    return $phones;
}

/** Ubah timestamp log WA "[m/d/yy, h:mm:ss AM/PM]" menjadi "YYYY-MM-DD HH:MM:SS". */
function wa_seed_parse_msg_datetime(string $header): string
{
    if (preg_match('/^\[(\d{1,2})\/(\d{1,2})\/(\d{2,4}),\s+(\d{1,2}:\d{2}:\d{2}\s+[AP]M)\]/', $header, $m)) {
        $mo = (int) $m[1];
        $dy = (int) $m[2];
        $yr = (int) $m[3];
        if ($yr < 100) {
            $yr += 2000;
        }
        $ts = strtotime(sprintf('%04d-%02d-%02d %s', $yr, $mo, $dy, $m[4]));
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return date('Y-m-d H:i:s');
}

/** Indeks seluruh balasan kutipan pada `chat.md` untuk Cancel, Pembayaran TF, dan Reschedule. */
function wa_seed_parse_md_replies(string $md): array
{
    $curDate = '';
    $blocks  = [];
    $cur     = null;
    foreach (explode("\n", $md) as $line) {
        if (preg_match('/^##\s+(.+)$/', $line, $dm)) {
            $ts = strtotime(trim($dm[1]));
            $curDate = $ts !== false ? date('Y-m-d', $ts) : '';
            continue;
        }
        if (preg_match('/^\[(\d{1,2}:\d{2}\s+[AP]M)\]\s+\*\*([^:]+):\*\*\s*(.*)$/', $line, $mh)) {
            if ($cur !== null) {
                $blocks[] = $cur;
            }
            $cur = [
                'date'   => $curDate,
                'time'   => $mh[1],
                'sender' => trim($mh[2]),
                'lines'  => [$mh[3]],
            ];
        } elseif ($cur !== null) {
            $cur['lines'][] = $line;
        }
    }
    if ($cur !== null) {
        $blocks[] = $cur;
    }

    $byPhoneDate = [];
    $byNameDate  = [];

    foreach ($blocks as $b) {
        $lines    = $b['lines'];
        $startIdx = -1;
        if (isset($lines[0]) && preg_match('/^>\s*_/', $lines[0])) {
            $startIdx = 0;
        } elseif (isset($lines[0], $lines[1]) && trim($lines[0]) === '' && preg_match('/^>\s*_/', $lines[1])) {
            $startIdx = 1;
        }
        if ($startIdx < 0) {
            continue;
        }

        $endIdx = -1;
        $cnt    = count($lines);
        for ($k = $startIdx; $k < $cnt; $k++) {
            $ln = rtrim($lines[$k]);
            if (preg_match('/[^_]_$/', $ln) && !preg_match('/^_+$/', $ln)) {
                $endIdx = $k;
            }
            if ($endIdx >= 0 && $k > $endIdx) {
                break;
            }
        }
        if ($endIdx < $startIdx) {
            continue;
        }

        $qText = implode("\n", array_slice($lines, $startIdx, $endIdx - $startIdx + 1));
        $aText = trim(implode("\n", array_slice($lines, $endIdx + 1)));
        if ($aText === '' || !preg_match('/form\s+pemesanan/i', $qText)) {
            continue;
        }

        $qPhone = preg_match('/no\.?\s*hp\s*[:\-]?\s*([^\n]+)/i', $qText, $pm) ? wa_seed_first_phone($pm[1]) : '';
        $qName  = preg_match('/nama\s*[:\-]?\s*([^\n]+)/iu', $qText, $nm) ? trim($nm[1]) : '';
        $qDate  = '';
        if (preg_match('/(?:hari\s*[&\/\-]?\s*tanggal|tanggal)\s*[:\-]?\s*([^\n]+)/iu', $qText, $tm)) {
            $qDate = bot_staff_parse_indonesian_date(trim($tm[1]), $b['date'] ?: null) ?? '';
        }

        $entry = [
            'reply_date' => $b['date'],
            'reply_time' => $b['time'],
            'sender'     => $b['sender'],
            'text'       => preg_replace('/\s+/', ' ', $aText),
        ];

        if ($qPhone !== '' && $qDate !== '') {
            $byPhoneDate[$qPhone . '|' . $qDate][] = $entry;
        }
        if ($qName !== '' && $qDate !== '') {
            $byNameDate[mb_strtolower($qName, 'UTF-8') . '|' . $qDate][] = $entry;
        }
    }

    return ['by_phone_date' => $byPhoneDate, 'by_name_date' => $byNameDate];
}

/**
 * Ekstrak data penumpang & alamat multi-titik ("Titik 1", "Titik 2", ...) maupun multi-pengirim.
 *
 * @return array{
 *   has_titik:bool,
 *   titiks:array<int,array{name:string,phone:string,pickup:string,delivery:string}>,
 *   shared_pickup:string,
 *   shared_delivery:string
 * }
 */
function wa_seed_extract_titik_blocks(string $cleaned): array
{
    $lines         = explode("\n", $cleaned);
    $sharedPickup  = '';
    $sharedDeliv   = '';
    $sharedMode    = '';
    $titiks        = [];
    $cur           = null;
    $lastField     = '';

    foreach ($lines as $rawLine) {
        $line     = trim($rawLine);
        $stripped = trim((string) preg_replace('/^[\p{So}\p{Cs}\p{Cf}\p{Sk}\p{M}\x{200D}\x{FE0E}\x{FE0F}\*\-\•~\s]+/u', '', $line));
        if ($stripped === '') {
            continue;
        }

        if (preg_match('/^titik\s+(\d+)\b/i', $stripped)) {
            if ($cur !== null) {
                $titiks[] = $cur;
            }
            $cur        = ['name' => '', 'phone' => '', 'pickup' => '', 'delivery' => ''];
            $lastField  = 'expect_name';
            $sharedMode = '';
            continue;
        }

        if (preg_match('/^(?:jumlah\s+(?:tiket|kursi)|hari\s*[&\/\-]?\s*tanggal|jam\s+berangkat|barang\s+bawaan|tarif)\b/i', $stripped)) {
            if ($cur !== null) {
                $titiks[] = $cur;
                $cur = null;
            }
            $sharedMode = '';
            $lastField  = '';
            continue;
        }

        if (preg_match('/^alamat+\s+penjemputan(?:\s+bersama)?\s*:?\s*(.*)$/i', $stripped, $m)) {
            $val = trim($m[1]);
            if ($cur === null || preg_match('/bersama/i', $stripped)) {
                if ($cur !== null && preg_match('/bersama/i', $stripped)) {
                    $titiks[] = $cur;
                    $cur = null;
                }
                $sharedPickup = $val;
                $sharedMode   = 'pickup';
            } else {
                $cur['pickup'] = $val;
                $lastField     = 'pickup';
            }
            continue;
        }

        if (preg_match('/^alamat+\s+tujuan(?:\s+bersama)?\s*:?\s*(.*)$/i', $stripped, $m)) {
            $val = trim($m[1]);
            if ($cur === null || preg_match('/bersama/i', $stripped)) {
                if ($cur !== null && preg_match('/bersama/i', $stripped)) {
                    $titiks[] = $cur;
                    $cur = null;
                }
                $sharedDeliv = $val;
                $sharedMode  = 'delivery';
            } else {
                $cur['delivery'] = $val;
                $lastField       = 'delivery';
            }
            continue;
        }

        if ($cur !== null) {
            if (preg_match('/^nama(?:\s+lengkap)?\s*:?\s*(.*)$/iu', $stripped, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    $cur['name'] = $val;
                    $lastField   = '';
                } else {
                    $lastField = 'name_next';
                }
                continue;
            }
            if (preg_match('/^no\.?\s*hp\s*:?\s*(.*)$/iu', $stripped, $m)) {
                $val = trim($m[1]);
                $ph  = wa_seed_first_phone($val);
                if ($ph !== '') {
                    $cur['phone'] = $ph;
                    $lastField    = '';
                } else {
                    $lastField = 'phone_next';
                }
                continue;
            }
            if ($lastField === 'expect_name' || $lastField === 'name_next') {
                $cur['name'] = $stripped;
                $lastField   = '';
                continue;
            }
            if ($lastField === 'phone_next') {
                $ph = wa_seed_first_phone($stripped);
                if ($ph !== '') {
                    $cur['phone'] = $ph;
                }
                $lastField = '';
                continue;
            }
            if ($lastField === 'pickup') {
                $cur['pickup'] = trim($cur['pickup'] . ' ' . $stripped);
                continue;
            }
            if ($lastField === 'delivery') {
                $cur['delivery'] = trim($cur['delivery'] . ' ' . $stripped);
                continue;
            }
        } elseif ($sharedMode === 'pickup') {
            $sharedPickup = trim($sharedPickup . ' ' . $stripped);
        } elseif ($sharedMode === 'delivery') {
            $sharedDeliv = trim($sharedDeliv . ' ' . $stripped);
        }
    }
    if ($cur !== null) {
        $titiks[] = $cur;
    }

    foreach ($titiks as &$t) {
        $t['pickup']   = rtrim($t['pickup'], " *");
        $t['delivery'] = rtrim($t['delivery'], " *");
    }
    unset($t);

    return [
        'has_titik'       => count($titiks) > 0,
        'titiks'          => $titiks,
        'shared_pickup'   => rtrim($sharedPickup, " *"),
        'shared_delivery' => rtrim($sharedDeliv, " *"),
    ];
}

/** Upsert penumpang MTM dari data WhatsApp tanpa menimpa nama yang sudah lengkap. */
function wa_seed_upsert_passenger(mysqli $db, int $cid, ?string $phone, string $name): int
{
    $cleanName  = mb_substr(trim($name), 0, 255) ?: 'Penumpang WA';
    $cleanPhone = ($phone !== null && $phone !== '') ? bot_normalize_phone($phone) : null;

    if ($cleanPhone !== null) {
        $pax = bot_ensure_passenger($db, $cid, $cleanPhone, $cleanName);
        if (strcasecmp((string) $pax['name'], $cleanName) !== 0 && str_starts_with((string) $pax['name'], 'Pelanggan WA')) {
            $st = $db->prepare('UPDATE passengers SET name = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
            $u  = 'seed_wa_forms';
            $pid = (int) $pax['id'];
            $st->bind_param('ssii', $cleanName, $u, $pid, $cid);
            $st->execute();
            $st->close();
        }
        return (int) $pax['id'];
    }

    $pax = bot_staff_ensure_passenger_flexible($db, $cid, null, $cleanName, 'seed_wa_forms');
    return (int) $pax['id'];
}

$db      = db();
$cid     = 1; // Maura Trans Mandiri
$zips    = wa_seed_read_zip_texts($zipPath);
$mdIndex = wa_seed_parse_md_replies($zips['chat.md']);

$msgs = preg_split('/(?m)(?=^\[\d{1,2}\/\d{1,2}\/\d{2,4},\s+\d{1,2}:\d{2}:\d{2}\s+[AP]M\])/', $zips['chat.txt']) ?: [];
$msgCount = count($msgs);

// Muat master kategori, cabang, armada kecil Travel, dan driver MTM
$branchId = (int) $db->query("SELECT id FROM branches WHERE company_id = {$cid} AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch_column();
if ($branchId <= 0) {
    fwrite(STDERR, "Cabang aktif MTM tidak ditemukan.\n");
    exit(1);
}

$catMap = [];
foreach ($db->query("SELECT id, code FROM category_vehicles WHERE company_id = {$cid} AND service_type = 'travel' AND is_active = 1") as $row) {
    $catMap[strtoupper((string) $row['code'])] = (int) $row['id'];
}
foreach (['REG', 'PKT', 'CMB', 'CHC'] as $reqCat) {
    if (empty($catMap[$reqCat])) {
        fwrite(STDERR, "Kategori Travel '{$reqCat}' tidak ditemukan pada MTM.\n");
        exit(1);
    }
}

$fleetSmall = []; // seat <= 7 (Avanza, Innova)
$fleetHiace = []; // seat 12..19 (Hiace Commuter, Hiace Premio, Elf)
foreach ($db->query("SELECT id, vehicle_number, seat FROM vehicles WHERE company_id = {$cid} AND is_active = 1 AND seat <= 20 ORDER BY seat ASC, id ASC") as $v) {
    $vid  = (int) $v['id'];
    $seat = (int) $v['seat'];
    if ($seat <= 8) {
        $fleetSmall[] = $vid;
    } else {
        $fleetHiace[] = $vid;
    }
}
$fleetAll = array_merge($fleetSmall, $fleetHiace);
if (!$fleetAll) {
    fwrite(STDERR, "Armada Travel (seat <= 20) MTM tidak ditemukan.\n");
    exit(1);
}

$drivers = [];
foreach ($db->query("SELECT id, name FROM employees WHERE company_id = {$cid} AND LOWER(COALESCE(job_position,'')) LIKE '%driver%' ORDER BY id ASC") as $e) {
    $drivers[] = ['id' => (int) $e['id'], 'name' => (string) $e['name']];
}
if (!$drivers) {
    fwrite(STDERR, "Karyawan Driver MTM tidak ditemukan.\n");
    exit(1);
}

$records     = [];
$seenKeys    = [];
$dupSkipped  = 0;
$formSeq     = 0;
$valErrors   = [];

for ($mi = 0; $mi < $msgCount; $mi++) {
    $rawMsg = trim($msgs[$mi]);
    if ($rawMsg === '' || !preg_match('/form\s+pemesanan/i', $rawMsg)) {
        continue;
    }
    $formSeq++;
    $msgDt   = wa_seed_parse_msg_datetime($rawMsg);
    $msgDate = substr($msgDt, 0, 10);

    $body = (string) preg_replace('/^\[[^\]]+\]\s*[^:]+:\s*/', '', $rawMsg);
    $body = (string) preg_replace('/^\[Diteruskan\]\s*/i', '', $body);
    $cleaned = bot_staff_strip_boilerplate($body);

    $p = bot_staff_parse_form_text($db, $cid, $body, 'travel', false, $msgDate);

    // Kumpulkan semua nama/telepon/alamat, utamakan blok Titik 1/2/3 bila ada
    $extraPassengers = [];
    $titikInfo       = wa_seed_extract_titik_blocks($cleaned);
    if ($titikInfo['has_titik']) {
        $t1 = $titikInfo['titiks'][0];
        if ($t1['name'] !== '') {
            $p['passenger_name'] = $t1['name'];
        }
        if ($t1['phone'] !== '') {
            $p['passenger_phone'] = $t1['phone'];
        }
        $pkParts = [];
        $dlParts = [];
        foreach ($titikInfo['titiks'] as $ti => $tRow) {
            if ($ti > 0 && ($tRow['name'] !== '' || $tRow['phone'] !== '')) {
                $extraPassengers[] = ['name' => $tRow['name'] ?: ('Penumpang Titik ' . ($ti + 1)), 'phone' => $tRow['phone'] ?: null];
            }
            $lbl = 'Titik ' . ($ti + 1) . ($tRow['name'] !== '' ? ' (' . $tRow['name'] . ')' : '');
            if ($tRow['pickup'] !== '') {
                $pkParts[] = $lbl . ': ' . $tRow['pickup'];
            }
            if ($tRow['delivery'] !== '') {
                $dlParts[] = $lbl . ': ' . $tRow['delivery'];
            }
        }
        if ($pkParts) {
            $p['pickup_address'] = implode(' | ', $pkParts);
            $p['pickup_geo']     = bot_staff_resolve_address_geo($db, $titikInfo['titiks'][0]['pickup'] ?: $p['pickup_address'], $cid);
        } elseif ($titikInfo['shared_pickup'] !== '') {
            $p['pickup_address'] = $titikInfo['shared_pickup'];
            $p['pickup_geo']     = bot_staff_resolve_address_geo($db, $titikInfo['shared_pickup'], $cid);
        }
        if ($dlParts) {
            $p['delivery_address'] = implode(' | ', $dlParts);
            $p['delivery_geo']     = bot_staff_resolve_address_geo($db, $titikInfo['titiks'][0]['delivery'] ?: $p['delivery_address'], $cid);
        } elseif ($titikInfo['shared_delivery'] !== '') {
            $p['delivery_address'] = $titikInfo['shared_delivery'];
            $p['delivery_geo']     = bot_staff_resolve_address_geo($db, $titikInfo['shared_delivery'], $cid);
        }
    } else {
        // Tangani form non-Titik dengan banyak nomor HP (mis. Pengirim / Penerima / #110 2 Lokasi)
        $hpRaw = '';
        if (preg_match('/no\.?\s*hp\s*[:\-]?\s*([^\n]+(?:\n(?![\p{So}\s]*(?:alamat|jumlah|hari|tgl|tanggal|jam|barang|tarif))[^\n]+)*)/iu', $cleaned, $hpm)) {
            $hpRaw = $hpm[1];
        }
        $phones = wa_seed_all_phones($hpRaw !== '' ? $hpRaw : $p['passenger_phone']);
        if ($phones) {
            $p['passenger_phone'] = $phones[0];
            if (count($phones) > 1 && preg_match('/nama\s*:\s*([^\n]+)\n([^\n:]+)\n/iu', $cleaned, $nmMulti)) {
                $secondName = trim((string) preg_replace('/^[\p{So}\s]+/u', '', $nmMulti[2]), " ,");
                if ($secondName !== '') {
                    $extraPassengers[] = ['name' => $secondName, 'phone' => $phones[1]];
                }
            }
        }
    }
    $p['passenger_name'] = trim(rtrim($p['passenger_name'], " ,"));

    // Form #21 tanpa nama -> pakai label berdasarkan 4 digit akhir HP
    if ($p['passenger_name'] === '') {
        $tail = $p['passenger_phone'] !== '' ? substr($p['passenger_phone'], -4) : (string) $formSeq;
        $p['passenger_name'] = 'Penumpang WA ' . $tail;
    }

    // Khusus Form #129 Warteg Langgeng Jaya: konteks pesan tepat sebelumnya menyebut Pamulang, Tangerang Selatan
    if ((int) ($p['pickup_geo']['district_id'] ?? 0) <= 0 && stripos($p['pickup_address'], 'Warteg Langgeng Jaya') !== false) {
        $prevContext = '';
        for ($bk = max(0, $mi - 3); $bk < $mi; $bk++) {
            $prevContext .= ' ' . $msgs[$bk];
        }
        if (preg_match('/pamulang|tangerang\s+selatan/i', $prevContext)) {
            $p['pickup_address'] = trim($p['pickup_address'] . ' (Pamulang, Kota Tangerang Selatan)');
            $p['pickup_geo']     = bot_staff_resolve_address_geo($db, 'Pamulang, Kota Tangerang Selatan', $cid);
        }
    }

    // Bersihkan URL Maps panjang dari alamat di tampilan ringkas, tapi simpan referensi di catatan bila ada
    $noteParts = [];
    if (preg_match_all('/https?:\/\/\S+/i', $p['pickup_address'] . ' ' . $p['delivery_address'], $urlM)) {
        $noteParts[] = 'Maps: ' . implode(', ', array_unique($urlM[0]));
    }
    $cleanPkAddr = trim((string) preg_replace('/\s*\(?https?:\/\/\S+\)?/i', '', $p['pickup_address']));
    $cleanDlAddr = trim((string) preg_replace('/\s*\(?https?:\/\/\S+\)?/i', '', $p['delivery_address']));
    $cleanPkAddr = trim((string) preg_replace('/\s{2,}/', ' ', $cleanPkAddr));
    $cleanDlAddr = trim((string) preg_replace('/\s{2,}/', ' ', $cleanDlAddr));

    // Koreksi tanggal salah ketik bulan (mis. Salma Minggu 25 September 2026 dikirim Minggu 2026-10-25)
    $resolvedDate = $p['date'];
    if ($resolvedDate !== '' && abs(strtotime($resolvedDate) - strtotime($msgDate)) > 20 * 86400) {
        if (abs((int) date('d', strtotime($resolvedDate)) - (int) date('d', strtotime($msgDate))) <= 1) {
            $noteParts[]  = "Tgl form '{$resolvedDate}' dikoreksi sesuai tgl chat {$msgDate}";
            $resolvedDate = $msgDate;
        }
    }
    if ($resolvedDate === '') {
        $resolvedDate = $msgDate;
    }

    $resolvedTime = $p['time'] !== '' ? ($p['time'] . ':00') : '09:00:00';

    // Pencocokan balasan dari chat.md (keyed by phone|date & name|date) & pesan instan sesudahnya (#4 Endah, #21)
    $replies = [];
    if ($p['passenger_phone'] !== '') {
        $replies = array_merge($replies, $mdIndex['by_phone_date'][$p['passenger_phone'] . '|' . $resolvedDate] ?? []);
        if ($p['date'] !== '' && $p['date'] !== $resolvedDate) {
            $replies = array_merge($replies, $mdIndex['by_phone_date'][$p['passenger_phone'] . '|' . $p['date']] ?? []);
        }
    }
    $nmKey = mb_strtolower($p['passenger_name'], 'UTF-8') . '|' . $resolvedDate;
    foreach (($mdIndex['by_name_date'][$nmKey] ?? []) as $nr) {
        $replies[] = $nr;
    }
    // Cek 1-2 pesan tepat sesudah form pada hari yang sama yang menyatakan Cancel/Cansel singkat (#4 Endah, #21)
    for ($nx = 1; $nx <= 2 && ($mi + $nx) < $msgCount; $nx++) {
        $nxtRaw = trim($msgs[$mi + $nx]);
        if ($nxtRaw === '' || preg_match('/form\s+pemesanan/i', $nxtRaw)) {
            break;
        }
        $nxtDate = substr(wa_seed_parse_msg_datetime($nxtRaw), 0, 10);
        if ($nxtDate !== $msgDate) {
            break;
        }
        $nxtTxt = trim((string) preg_replace('/^\[[^\]]+\]\s*[^:]+:\s*/', '', $nxtRaw));
        if (preg_match('/^(?:can[sc]el|batal)\b/i', $nxtTxt)) {
            $replies[] = ['reply_date' => $nxtDate, 'reply_time' => '', 'sender' => 'WA', 'text' => $nxtTxt];
        }
    }

    $isCancelled  = false;
    $cancelReason = null;
    $hasTfProof   = false;
    $tfToDriver   = false;
    foreach ($replies as $rep) {
        $rt = (string) $rep['text'];
        if (preg_match('/\b(?:ga|gak|ngga|nggak|tidak|tdk|bukan)\s+j(?:a)?di\s+(?:can[sc]el|batal)\b/i', $rt)) {
            $isCancelled  = false;
            $cancelReason = null;
        } elseif (preg_match('/\b(can[sc]el|batal|ga jadi|gak jadi|tidak jadi|tdk jadi|cancelan)\b/i', $rt)) {
            $isCancelled  = true;
            $cancelReason = mb_substr('WA: ' . $rt, 0, 250);
        }
        if (preg_match('/\b(sudah\s*tf|udh\s*tf|sdh\s*tf|sudahh\s*tf|bayar\s*tf|sudah\s+bayar)\b/i', $rt)) {
            $hasTfProof = true;
            if (preg_match('/\b(?:ke\s+drv|ke\s+driver)\b/i', $rt)) {
                $tfToDriver = true;
            }
        }
        if (preg_match('/\b(reskejul|reschedule)\b/i', $rt)) {
            if (preg_match('/\bbesok\b/i', $rt)) {
                $origD        = $resolvedDate;
                $resolvedDate = date('Y-m-d', strtotime($resolvedDate . ' +1 day'));
                // #5 Yudha lanjut jam 10 pagi di pesan chat besoknya
                if (stripos($p['passenger_name'], 'Yudha') !== false && $origD === '2026-07-14') {
                    $resolvedTime = '10:00:00';
                }
                $noteParts[] = "Reschedule via WA dari {$origD} ke {$resolvedDate}";
            } else {
                $noteParts[] = 'Reschedule via WA: ' . $rt;
            }
        }
    }

    // Kategori Layanan: PKT / CMB / CHC / REG
    $catCode = $p['service_category_hint'] ?: 'REG';
    // Form #143 (Dadan Sutisna + Aria kepulangan carter Bandara -> Bandung 1,2 jt) juga Carter
    if ($catCode === 'REG' && $titikInfo['has_titik'] && (float) $p['total_price'] >= 900000) {
        $catCode = 'CMB';
    }
    if ($catCode === 'CMB' && ((int) $p['seats'] >= 4 || stripos($p['baggage'], '5 Koper') !== false)) {
        $catCode = 'CHC';
    }
    $catId = $catMap[$catCode];

    if ($p['seats'] > 1) {
        $noteParts[] = "{$p['seats']} kursi/orang";
    }
    if ($p['baggage'] !== '') {
        $noteParts[] = 'Bagasi: ' . $p['baggage'];
    }
    if (preg_match('/^\d+\s*\([^)]+\)/', trim((string) (preg_match('/jumlah\s+tiket\/kursi\s*[:\-]?\s*([^\n]+)/i', $cleaned, $km) ? $km[1] : '')))) {
        $noteParts[] = 'Seat: ' . trim($km[1]);
    }

    $dedupKey = ($p['passenger_phone'] !== '' ? $p['passenger_phone'] : mb_strtolower($p['passenger_name'], 'UTF-8')) . '|' . $resolvedDate;
    if (isset($seenKeys[$dedupKey])) {
        $dupSkipped++;
        continue;
    }
    $seenKeys[$dedupKey] = true;

    $pkGeo = $p['pickup_geo'];
    $dlGeo = $p['delivery_geo'];
    $err   = [];
    if ($p['passenger_name'] === '') {
        $err[] = 'nama kosong';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedDate)) {
        $err[] = 'tanggal invalid (' . $resolvedDate . ')';
    }
    if ((float) $p['total_price'] < 100000) {
        $err[] = 'tarif < 100rb (' . $p['total_price'] . ')';
    }
    if ((int) ($pkGeo['district_id'] ?? 0) <= 0) {
        $err[] = 'pickup geo kosong (' . mb_substr($cleanPkAddr, 0, 50) . ')';
    }
    if ((int) ($dlGeo['district_id'] ?? 0) <= 0) {
        $err[] = 'delivery geo kosong (' . mb_substr($cleanDlAddr, 0, 50) . ')';
    }
    if ($err) {
        $valErrors[] = "Form #{$formSeq} ({$p['passenger_name']}): " . implode('; ', $err);
        continue;
    }

    $records[] = [
        'form_seq'        => $formSeq,
        'msg_dt'          => $msgDt,
        'msg_date'        => $msgDate,
        'date'            => $resolvedDate,
        'time'            => $resolvedTime,
        'passenger_name'  => mb_substr(trim($p['passenger_name']), 0, 255),
        'passenger_phone' => $p['passenger_phone'],
        'extra_pax'       => $extraPassengers,
        'pickup_address'  => mb_substr($cleanPkAddr ?: ($pkGeo['district_name'] . ', ' . $pkGeo['city_name']), 0, 1900),
        'pickup_prov_id'  => (int) $pkGeo['province_id'] ?: null,
        'pickup_city_id'  => (int) $pkGeo['city_id'],
        'pickup_dist_id'  => (int) $pkGeo['district_id'],
        'delivery_address'=> mb_substr($cleanDlAddr ?: ($dlGeo['district_name'] . ', ' . $dlGeo['city_name']), 0, 1900),
        'delivery_prov_id'=> (int) $dlGeo['province_id'] ?: null,
        'delivery_city_id'=> (int) $dlGeo['city_id'],
        'delivery_dist_id'=> (int) $dlGeo['district_id'],
        'seats'           => (int) $p['seats'],
        'total_price'     => round((float) $p['total_price'], 2),
        'cat_code'        => $catCode,
        'category_id'     => $catId,
        'note'            => mb_substr(implode(' | ', $noteParts), 0, 1900),
        'is_cancelled'    => $isCancelled,
        'cancel_reason'   => $cancelReason,
        'has_tf_proof'    => $hasTfProof,
        'tf_to_driver'    => $tfToDriver,
    ];
}

echo "=== Analisa Form Pemesanan WA (MTM) ===\n";
echo "Total Form Pemesanan ditemukan : {$formSeq}\n";
echo "Duplikat form dilewati         : {$dupSkipped}\n";
echo "Valid siap di-seed             : " . count($records) . "\n";
echo "Validasi error                 : " . count($valErrors) . "\n";

if ($valErrors) {
    echo "\nDetail error validasi:\n  - " . implode("\n  - ", $valErrors) . "\n";
    exit(1);
}

$catCounts = [];
$canCnt    = 0;
$tfCnt     = 0;
foreach ($records as $r) {
    $catCounts[$r['cat_code']] = ($catCounts[$r['cat_code']] ?? 0) + 1;
    if ($r['is_cancelled']) {
        $canCnt++;
    }
    if ($r['has_tf_proof']) {
        $tfCnt++;
    }
}
echo 'Kategori: ' . json_encode($catCounts) . " | Cancelled: {$canCnt} | Bukti TF: {$tfCnt}\n";

if (!$apply) {
    echo "\n--- Preview Sampel & Pengecekan Khusus ---\n";
    foreach ($records as $r) {
        if ($r['is_cancelled']) {
            echo "  [CANCEL] #{$r['form_seq']} {$r['date']} {$r['passenger_name']} ({$r['cat_code']}) Rp " . number_format($r['total_price'], 0, ',', '.') . " | {$r['cancel_reason']}\n";
        }
    }
    foreach ($records as $r) {
        if ($r['cat_code'] !== 'REG' || $r['form_seq'] <= 10 || $r['form_seq'] >= 135) {
            echo "  [{$r['cat_code']}] #{$r['form_seq']} {$r['date']} {$r['time']} | {$r['passenger_name']} | kursi={$r['seats']} | extra=" . count($r['extra_pax']) . " | pkCity={$r['pickup_city_id']}/{$r['pickup_dist_id']} -> dlCity={$r['delivery_city_id']}/{$r['delivery_dist_id']} | Rp " . number_format($r['total_price'], 0, ',', '.') . ($r['has_tf_proof'] ? ' [TF]' : '') . "\n";
        }
    }
    echo "\n[DRY-RUN] Tidak ada perubahan database. Jalankan dengan --apply untuk menerapkan.\n";
    exit(0);
}

// Urutkan kronologis (date ASC, time ASC) agar nomor resi 1001YYYYMMDD.. rapi dan berurutan
usort($records, static fn(array $a, array $b): int => [$a['date'], $a['time'], $a['form_seq']] <=> [$b['date'], $b['time'], $b['form_seq']]);

if ($cleanAllTransactions) {
    echo "\n[APPLY] Menghapus seluruh data transaksi dummy lama (master data tetap utuh)...\n";
    $db->begin_transaction();
    try {
        $txTables = [
            'cash_transactions',
            'payment_gateway_orders',
            'invoice_revisions',
            'invoices',
            'booking_cost_lines',
            'booking_passengers',
            'booking_status_history',
            'trip_assignments',
            'trip_schedules',
            'wa_outbox',
            'bot_request_logs',
            'travel_ticket_bookings',
            'rental_car_bookings',
            'passengers',
            'audit_trails',
        ];
        foreach ($txTables as $tbl) {
            $chk = $db->query("SHOW TABLES LIKE '{$tbl}'");
            if ($chk && $chk->num_rows > 0) {
                $db->query("DELETE FROM `{$tbl}`");
            }
        }
        $db->query('UPDATE document_sequences SET last_no = 0, modified_date = NOW()');
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        fwrite(STDERR, 'Gagal membersihkan seluruh transaksi dummy: ' . $ex->getMessage() . "\n");
        exit(1);
    }

    $resetAiTables = [
        'travel_ticket_bookings',
        'rental_car_bookings',
        'invoices',
        'passengers',
        'booking_cost_lines',
        'booking_passengers',
        'booking_status_history',
        'trip_assignments',
        'cash_transactions',
        'payment_gateway_orders',
        'audit_trails',
    ];
    foreach ($resetAiTables as $tbl) {
        $chk = $db->query("SHOW TABLES LIKE '{$tbl}'");
        if ($chk && $chk->num_rows > 0) {
            $db->query("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
        }
    }
} else {
    echo "\n[APPLY] Menghapus transaksi travel lama MTM (rental & master tetap utuh)...\n";
    $db->begin_transaction();
    try {
        $db->query("DELETE FROM cash_transactions WHERE company_id = {$cid} AND source_type = 'booking_payment' AND source_id IN (SELECT id FROM booking_cost_lines WHERE company_id = {$cid} AND booking_type = 'travel')");
        $db->query("DELETE FROM payment_gateway_orders WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM invoices WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM booking_cost_lines WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM booking_passengers WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM booking_status_history WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM trip_assignments WHERE company_id = {$cid} AND booking_type = 'travel'");
        $db->query("DELETE FROM travel_ticket_bookings WHERE company_id = {$cid}");
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        fwrite(STDERR, 'Gagal membersihkan travel_ticket_bookings MTM: ' . $ex->getMessage() . "\n");
        exit(1);
    }
}

$inserted      = 0;
$statusCounts  = [];
$invoiceCounts = [];

foreach ($records as $idx => $rec) {
    $primaryPaxId = wa_seed_upsert_passenger($db, $cid, $rec['passenger_phone'], $rec['passenger_name']);
    $allPaxIds    = [$primaryPaxId];
    foreach ($rec['extra_pax'] as $ep) {
        $epId = wa_seed_upsert_passenger($db, $cid, $ep['phone'], $ep['name']);
        if (!in_array($epId, $allPaxIds, true)) {
            $allPaxIds[] = $epId;
        }
    }

    // Pilih armada kecil/Hiace sesuai kategori & jumlah kursi (null bila cancelled)
    $vehId = null;
    if (!$rec['is_cancelled']) {
        if ($rec['cat_code'] === 'CHC' || $rec['seats'] >= 4) {
            $vehPool = $fleetHiace ?: $fleetAll;
        } elseif ($rec['cat_code'] === 'CMB') {
            $vehPool = $fleetAll;
        } else {
            $vehPool = $fleetSmall ?: $fleetAll;
        }
        $vehId = $vehPool[$idx % count($vehPool)];
    }

    $created = booking_create('travel', [
        'company_id'                 => $cid,
        'branch_id'                  => $branchId,
        'date'                       => $rec['date'],
        'time'                       => $rec['time'],
        'pickup_address'             => $rec['pickup_address'],
        'pickup_point_province_id'   => $rec['pickup_prov_id'],
        'pickup_point_city_id'       => $rec['pickup_city_id'],
        'pickup_point_district_id'   => $rec['pickup_dist_id'],
        'delivery_address'           => $rec['delivery_address'],
        'delivery_point_province_id' => $rec['delivery_prov_id'],
        'delivery_point_city_id'     => $rec['delivery_city_id'],
        'delivery_point_district_id' => $rec['delivery_dist_id'],
        'total_price'                => $rec['total_price'],
        'category_vehicle_id'        => $rec['category_id'],
        'vehicle_id'                 => $vehId,
        'passenger_id'               => $primaryPaxId,
        'passenger_ids'              => $allPaxIds,
        'note'                       => $rec['note'],
        'created_user'               => 'seed_wa_forms',
    ]);

    $bkId  = (int) $created['id'];
    $invId = (int) $created['invoice_id'];

    // Update provinsi pada travel_ticket_bookings (karena booking_create('travel') hanya simpan city & district) + timestamp chat
    $stUpProv = $db->prepare('UPDATE travel_ticket_bookings SET pickup_point_province_id = ?, delivery_point_province_id = ?, created_date = ? WHERE id = ?');
    $stUpProv->bind_param('iisi', $rec['pickup_prov_id'], $rec['delivery_prov_id'], $rec['msg_dt'], $bkId);
    $stUpProv->execute();
    $stUpProv->close();

    $stUpInvDt = $db->prepare('UPDATE invoices SET issued_at = ?, created_date = ? WHERE id = ?');
    $stUpInvDt->bind_param('ssi', $rec['msg_dt'], $rec['msg_dt'], $invId);
    $stUpInvDt->execute();
    $stUpInvDt->close();

    if ($rec['is_cancelled']) {
        booking_change_status('travel', $bkId, $cid, 'cancelled', $rec['cancel_reason'] ?? 'Dibatalkan via grup WA', 'seed_wa_forms');
        $statusCounts['cancelled']  = ($statusCounts['cancelled'] ?? 0) + 1;
        $invoiceCounts['cancelled'] = ($invoiceCounts['cancelled'] ?? 0) + 1;
    } else {
        $drv    = $drivers[$idx % count($drivers)];
        $drvFee = match ($rec['cat_code']) {
            'PKT'        => 100000.0,
            'CMB', 'CHC' => 250000.0,
            default      => ($rec['total_price'] >= 450000 ? 150000.0 : 120000.0),
        };
        booking_crew_sync('travel', $bkId, $cid, 0.0, [
            ['type' => 'Sopir', 'employee_id' => $drv['id'], 'amount' => $drvFee],
        ], $vehId, 'seed_wa_forms');

        // Tentukan progres status berdasarkan tanggal keberangkatan (acuan chat s/d 2026-11-22)
        if ($rec['date'] <= '2026-11-16' || ($rec['date'] === '2026-11-17' && stripos($rec['passenger_name'], 'Indra') !== false)) {
            $targetStatus = 'done';
            booking_change_status('travel', $bkId, $cid, 'scheduled', 'Armada & driver ditugaskan', 'seed_wa_forms');
            booking_change_status('travel', $bkId, $cid, 'on_trip', 'Dalam perjalanan', 'seed_wa_forms');
            booking_change_status('travel', $bkId, $cid, 'done', 'Perjalanan selesai', 'seed_wa_forms');
        } elseif ($rec['date'] === '2026-11-17') {
            $targetStatus = 'on_trip';
            booking_change_status('travel', $bkId, $cid, 'scheduled', 'Armada & driver ditugaskan', 'seed_wa_forms');
            booking_change_status('travel', $bkId, $cid, 'on_trip', 'Dalam perjalanan', 'seed_wa_forms');
        } else {
            $targetStatus = 'scheduled';
            booking_change_status('travel', $bkId, $cid, 'scheduled', 'Armada & driver ditugaskan', 'seed_wa_forms');
        }
        $statusCounts[$targetStatus] = ($statusCounts[$targetStatus] ?? 0) + 1;

        // Catat pembayaran ke Invoice & Buku Kas
        $payRows = [];
        $payDate = $rec['date'];
        if ($rec['has_tf_proof']) {
            $payRows[] = [
                'pay_type'   => 'Pembayaran Penuh (Full)',
                'pay_method' => 'Transfer',
                'pay_date'   => $payDate,
                'amount'     => $rec['total_price'],
            ];
            $invoiceCounts['paid'] = ($invoiceCounts['paid'] ?? 0) + 1;
        } elseif ($targetStatus === 'done') {
            $payMethod = ($idx % 10 < 5) ? 'Transfer' : 'Tunai';
            $payRows[] = [
                'pay_type'   => 'Pembayaran Penuh (Full)',
                'pay_method' => $payMethod,
                'pay_date'   => $payDate,
                'amount'     => $rec['total_price'],
            ];
            $invoiceCounts['paid'] = ($invoiceCounts['paid'] ?? 0) + 1;
        } elseif ($targetStatus === 'on_trip') {
            // Rizky Febriano (17 Nov): DP 50% (partial) agar KPI Piutang/Sebagian terwakili
            $dp = round($rec['total_price'] * 0.5, -3);
            if ($dp > 0 && $dp < $rec['total_price']) {
                $payRows[] = [
                    'pay_type'   => 'Down Payment (DP)',
                    'pay_method' => 'Transfer',
                    'pay_date'   => $payDate,
                    'amount'     => $dp,
                ];
                $invoiceCounts['partial'] = ($invoiceCounts['partial'] ?? 0) + 1;
            } else {
                $invoiceCounts['issued'] = ($invoiceCounts['issued'] ?? 0) + 1;
            }
        } else {
            // Bryan M. W (20 Nov, non-TF): tetap Belum Bayar (issued)
            $invoiceCounts['issued'] = ($invoiceCounts['issued'] ?? 0) + 1;
        }

        if ($payRows) {
            booking_payments_sync('travel', $bkId, $invId, $cid, $payRows, 'seed_wa_forms');
        }

        // Rapikan timestamp historis trip_assignments & booking_status_history
        $tripStart = $rec['date'] . ' ' . $rec['time'];
        $tripEnd   = $targetStatus === 'done'
            ? date('Y-m-d H:i:s', strtotime($tripStart . ' +4 hours'))
            : null;
        $stUpTa = $db->prepare("UPDATE trip_assignments SET started_at = ?, ended_at = ?, created_date = ? WHERE booking_type = 'travel' AND booking_id = ?");
        $stUpTa->bind_param('sssi', $tripStart, $tripEnd, $tripStart, $bkId);
        $stUpTa->execute();
        $stUpTa->close();
    }

    $histTime = $rec['date'] . ' ' . $rec['time'];
    $stUpHist = $db->prepare("UPDATE booking_status_history SET changed_at = CASE WHEN to_status = 'draft' THEN ? ELSE ? END WHERE booking_type = 'travel' AND booking_id = ?");
    $stUpHist->bind_param('ssi', $rec['msg_dt'], $histTime, $bkId);
    $stUpHist->execute();
    $stUpHist->close();

    $inserted++;
}

echo "[DONE] Berhasil memasukkan {$inserted} data booking Travel dari Form Pemesanan WA!\n";
echo "Status Booking : " . json_encode($statusCounts) . "\n";
echo "Status Invoice : " . json_encode($invoiceCounts) . "\n";
