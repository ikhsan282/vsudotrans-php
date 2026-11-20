<?php
/**
 * Shared Smart Booking Parser untuk form Pemesanan Travel & Rental.
 * - Pre-filter kandidat lokasi (kota/kecamatan) & pelanggan di PHP agar prompt ke 9router tetap kecil (< 3KB)
 * - Dilengkapi fallback parser deterministik berbasis DB & regex bila AI tidak merespons/belum dikonfigurasi
 * - Semua ID & field divalidasi ulang terhadap DB sebelum dikembalikan ke UI preview.
 */
require_once __DIR__ . '/transport_helpers.php';
require_once __DIR__ . '/ai.php';

/** Ambil token kata kunci bermakna dari teks bebas user (min 3 karakter, bebas stopword umum). */
function _booking_ai_extract_tokens(string $raw): array
{
    $stop = [
        'yang', 'dan', 'atau', 'untuk', 'dengan', 'dari', 'atau', 'pada', 'jam', 'pukul', 'pkl',
        'pagi', 'siang', 'sore', 'malam', 'dini', 'hari', 'ini', 'besok', 'lusa', 'tgl', 'tanggal',
        'sewa', 'travel', 'rental', 'mobil', 'unit', 'armada', 'jemput', 'antar', 'tujuan', 'asal',
        'pelanggan', 'penumpang', 'atas', 'nama', 'pak', 'bapak', 'ibu', 'mas', 'mbak', 'kak', 'bang',
        'harga', 'tarif', 'total', 'biaya', 'mulai', 'selesai', 'catatan', 'note', 'alamat', 'detail',
        'kota', 'kab', 'kabupaten', 'kec', 'kecamatan', 'provinsi', 'ribu', 'juta', 'trip', 'bbm',
    ];
    $clean = mb_strtolower($raw, 'UTF-8');
    preg_match_all('/[a-z0-9]{3,}/u', $clean, $m);
    $tokens = [];
    foreach ($m[0] as $tok) {
        if (ctype_digit($tok) || in_array($tok, $stop, true)) {
            continue;
        }
        $tokens[$tok] = true;
        if (count($tokens) >= 12) {
            break;
        }
    }
    return array_keys($tokens);
}

/** Parsing besaran rupiah berakhiran rb/ribu/jt/juta atau format ribuan standar. */
function _booking_ai_parse_amount_expr(string $numStr, string $unit = ''): float
{
    $u = strtolower(trim($unit));
    $clean = str_replace(' ', '', $numStr);
    if ($u === 'jt' || $u === 'juta') {
        $norm = preg_match('/^\d+(?:\.\d{3})+$/', $clean)
            ? str_replace('.', '', $clean)
            : str_replace(',', '.', $clean);
        return round((float) $norm * 1000000);
    }
    if ($u === 'rb' || $u === 'ribu' || $u === 'k') {
        $norm = preg_match('/^\d+(?:\.\d{3})+$/', $clean)
            ? str_replace('.', '', $clean)
            : str_replace(',', '.', $clean);
        return round((float) $norm * 1000);
    }
    return parse_rupiah_input($numStr);
}

/**
 * Ekstrak kandidat nama & nomor telepon pelanggan baru dari teks bebas (misal hasil copy-paste chat WA)
 * bila pelanggan tersebut belum ditemukan di daftar master passengers.
 *
 * @return array{name:string,phone:string}
 */
function _booking_ai_extract_new_passenger(string $raw): array
{
    $phone = '';
    if (preg_match('/(?:(?:\+62|62|0)8[0-9]{1,3}[\s.-]?[0-9]{3,4}[\s.-]?[0-9]{3,5})\b/', $raw, $pm)) {
        $norm = normalize_phone_62($pm[0]);
        if ($norm !== null && strlen($norm) >= 10 && strlen($norm) <= 20) {
            $phone = $norm;
        }
    }

    $name = '';
    $stopBoundary = '(?=\s*(?:[,;|\n\/()]|\b(?:no|nomor|hp|wa|whatsapp|telp|telepon|08\d|\+62|628|jemput|dari|asal|ke|tujuan|antar|jam|pukul|pkl|tgl|tanggal|besok|lusa|hari|mulai|selesai|harga|tarif|total|biaya|sewa|travel|rental|catatan|note|rp|\d{2,})\b|$))';
    if (preg_match('/\b(?:pelanggan|penumpang|a\.?\s*n\.?|atas\s+nama|nama(?:\s+pelanggan|\s+penumpang|\s+penyewa)?|penyewa|pemesan)\s*(?:baru)?\s*:?\s*(?:pak|bapak|ibu|bu|mas|mbak|kak|bang|sdr\.?|tn\.?|ny\.?)?\s*([a-zA-Z][a-zA-Z0-9 .\'-]{1,42}?)' . $stopBoundary . '/iu', $raw, $nm)) {
        $name = trim($nm[1]);
    } elseif ($phone !== '' && preg_match('/\b(?:pak|bapak|ibu|bu|mas|mbak|kak|bang)\s+([a-zA-Z][a-zA-Z0-9 .\'-]{1,35}?)' . $stopBoundary . '/iu', $raw, $nm2)) {
        $name = trim($nm2[1]);
    } elseif ($phone !== '' && preg_match('/([A-Z][a-zA-Z0-9]{1,}(?:\s+[A-Z][a-zA-Z0-9]{1,}){0,3})\s*[-–(,]?\s*(?:\+62|62|0)8\d/u', $raw, $nm3)) {
        $name = trim($nm3[1]);
    }

    if ($name !== '') {
        $badWords = ['besok', 'lusa', 'hari', 'pagi', 'siang', 'sore', 'malam', 'jemput', 'antar', 'tujuan', 'asal', 'sewa', 'travel', 'rental', 'mobil', 'harga', 'tarif', 'total', 'baru'];
        $words = preg_split('/\s+/', $name) ?: [];
        $cleanWords = [];
        foreach ($words as $w) {
            $wClean = trim($w, " \t\n\r\0\x0B.,;:-_()");
            if ($wClean === '' || ctype_digit($wClean) || in_array(mb_strtolower($wClean, 'UTF-8'), $badWords, true)) {
                if (empty($cleanWords)) {
                    continue;
                }
                break;
            }
            $cleanWords[] = $wClean;
            if (count($cleanWords) >= 4) {
                break;
            }
        }
        $name = implode(' ', $cleanWords);
        if (mb_strlen($name, 'UTF-8') < 2) {
            $name = '';
        } else {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }
    }

    return [
        'name'  => $name,
        'phone' => $phone,
    ];
}

/**
 * Fallback parser lokal deterministik berbasis regex + pencocokan DB perusahaan.
 * Dipakai otomatis bila LLM offline/timeout atau belum menghasilkan kandidat valid.
 */
function booking_ai_local_parse(string $mode, string $raw, array $masters): array
{
    $out = [];
    $qLower = mb_strtolower($raw, 'UTF-8');

    // 1. Tanggal
    $today = date('Y-m-d');
    $dates = [];
    if (preg_match('/\blusa\b/i', $qLower)) {
        $dates[] = date('Y-m-d', strtotime('+2 days'));
    } elseif (preg_match('/\bbesok\b/i', $qLower)) {
        $dates[] = date('Y-m-d', strtotime('+1 day'));
    } elseif (preg_match('/\bhari\s+ini\b/i', $qLower)) {
        $dates[] = $today;
    }
    if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $raw, $ym, PREG_SET_ORDER)) {
        foreach ($ym as $m) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $dates[] = $m[0];
            }
        }
    }
    if (preg_match_all('/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/', $raw, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $m) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                $dates[] = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
    }
    if (!$dates && preg_match('/\b(?:tgl|tanggal)\s+(\d{1,2})\b/i', $qLower, $tm)) {
        $dom = (int) $tm[1];
        if ($dom >= 1 && $dom <= 31) {
            $cand = sprintf('%s-%02d', date('Y-m'), $dom);
            if ($cand < $today) {
                $cand = date('Y-m', strtotime('first day of next month')) . sprintf('-%02d', $dom);
            }
            $parts = explode('-', $cand);
            if (count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                $dates[] = $cand;
            }
        }
    }

    if ($mode === 'rental') {
        $start = $dates[0] ?? '';
        $end   = $dates[1] ?? '';
        if ($start === '' && preg_match('/\b(\d+)\s*hari\b/i', $qLower)) {
            $start = $today;
        }
        if ($start !== '') {
            $out['start_date'] = $start;
            if ($end !== '' && $end >= $start) {
                $out['end_date'] = $end;
            } elseif (preg_match('/\b(\d{1,2})\s*hari\b/i', $qLower, $hd)) {
                $days = max(1, min(60, (int) $hd[1]));
                $out['end_date'] = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
            } else {
                $out['end_date'] = $start;
            }
        }
    } elseif (!empty($dates[0])) {
        $out['date'] = $dates[0];
    }

    // 2. Jam (HH:MM + dukungan pagi/siang/sore/malam)
    if (preg_match('/\b(?:jam|pukul|pkl)?\s*([01]?\d|2[0-3])[:.]([0-5]\d)\s*(pagi|siang|sore|malam)?\b/i', $qLower, $t1)) {
        $h = (int) $t1[1];
        $min = (int) $t1[2];
        $mer = $t1[3] ?? '';
        if (($mer === 'sore' || $mer === 'malam') && $h < 12) {
            $h += 12;
        } elseif ($mer === 'siang' && $h >= 1 && $h <= 5) {
            $h += 12;
        }
        $out['time'] = sprintf('%02d:%02d', $h, $min);
    } elseif (preg_match('/\b(?:jam|pukul|pkl)\s*([01]?\d|2[0-3])\s*(pagi|siang|sore|malam)?\b/i', $qLower, $t2)) {
        $h = (int) $t2[1];
        $mer = $t2[2] ?? '';
        if (($mer === 'sore' || $mer === 'malam') && $h < 12) {
            $h += 12;
        } elseif ($mer === 'siang' && $h >= 1 && $h <= 5) {
            $h += 12;
        }
        $out['time'] = sprintf('%02d:00', $h);
    }

    // 3. Harga, BBM, Trip
    $priceNum = '([0-9]+(?:[.,][0-9]+)*)\s*(jt|juta|rb|ribu|k)?(?!\s*(?:hari|jam|menit|minggu|bulan|tahun|unit|mobil|orang|penumpang|pax|km|trip)\b)';
    if (preg_match('/\b(?:harga(?:\s+sewa)?|tarif|total(?:\s+(?:harga|biaya))?|senilai|biaya(?:\s+sewa)?)\s*:?\s*(?:rp\.?\s*)?' . $priceNum . '\b/i', $qLower, $pm)) {
        $val = _booking_ai_parse_amount_expr($pm[1], $pm[2] ?? '');
        if ($val > 0) {
            $out['total_price'] = $val;
        }
    } elseif (preg_match('/\brp\.?\s*' . $priceNum . '\b/i', $qLower, $pm2)) {
        $val = _booking_ai_parse_amount_expr($pm2[1], $pm2[2] ?? '');
        if ($val > 0) {
            $out['total_price'] = $val;
        }
    } elseif (preg_match('/\b([0-9]+(?:[.,][0-9]+)?)\s*(jt|juta|rb|ribu)\b/i', $qLower, $pm3)) {
        $val = _booking_ai_parse_amount_expr($pm3[1], $pm3[2]);
        if ($val > 0) {
            $out['total_price'] = $val;
        }
    }

    if ($mode === 'rental') {
        if (preg_match('/\bbbm\s*:?\s*(?:rp\.?\s*)?([0-9]+(?:[.,][0-9]+)*)\s*(jt|juta|rb|ribu|k)?\b/i', $qLower, $bm)) {
            $bval = _booking_ai_parse_amount_expr($bm[1], $bm[2] ?? '');
            if ($bval > 0) {
                $out['bbm'] = $bval;
            }
        }
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*trip\b/i', $qLower, $trm)) {
            $out['trip'] = (float) str_replace(',', '.', $trm[1]);
        }
    }

    // 4. Cabang, Pelanggan, Kategori, Armada
    foreach ($masters['branches'] ?? [] as $b) {
        $nm = trim(preg_replace('/^[A-Z0-9-]+\s*-\s*/', '', (string) $b['name']));
        $shortNm = trim(preg_replace('/^(?:head office|kantor cabang|cabang|kantor)\s+/i', '', $nm));
        if (($nm !== '' && stripos($qLower, mb_strtolower($nm, 'UTF-8')) !== false)
            || (strlen($shortNm) >= 4 && stripos($qLower, mb_strtolower($shortNm, 'UTF-8')) !== false)
        ) {
            $out['branch_id'] = (int) $b['id'];
            break;
        }
    }

    $bestPaxId = 0;
    $bestPaxLen = 0;
    foreach ($masters['passengers'] ?? [] as $p) {
        $pRawName = trim((string) explode(' - ', (string) $p['name'])[0]);
        $pPhone   = preg_replace('/\D+/', '', (string) ($p['phone_number'] ?? ''));
        if ($pPhone !== '' && str_contains(preg_replace('/\D+/', '', $raw), $pPhone)) {
            $bestPaxId = (int) $p['id'];
            break;
        }
        if (strlen($pRawName) >= 3 && stripos($qLower, mb_strtolower($pRawName, 'UTF-8')) !== false && strlen($pRawName) > $bestPaxLen) {
            $bestPaxId = (int) $p['id'];
            $bestPaxLen = strlen($pRawName);
        }
    }
    if ($bestPaxId > 0) {
        $out['passenger_id'] = $bestPaxId;
    }

    $compactRaw = strtoupper(str_replace(' ', '', $raw));
    foreach ($masters['vehicles'] ?? [] as $v) {
        $vLabel = (string) $v['name'];
        if (preg_match('/\b([A-Z]{1,2}\s?\d{1,4}\s?[A-Z]{1,3})\b/i', $vLabel, $vp)) {
            $plate = strtoupper(str_replace(' ', '', $vp[1]));
            if (strlen($plate) >= 4 && str_contains($compactRaw, $plate)) {
                $out['vehicle_id'] = (int) $v['id'];
                break;
            }
        }
    }

    $bestCatId = 0;
    $bestCatLen = 0;
    foreach ($masters['cats'] ?? [] as $c) {
        $cName = trim(preg_replace('/^[A-Z0-9-]+\s*-\s*/', '', (string) $c['name']));
        if (strlen($cName) >= 3 && stripos($qLower, mb_strtolower($cName, 'UTF-8')) !== false && strlen($cName) > $bestCatLen) {
            $bestCatId = (int) $c['id'];
            $bestCatLen = strlen($cName);
        }
    }
    if ($bestCatId > 0) {
        $out['category_vehicle_id'] = $bestCatId;
    }

    // 5. Pencocokan Kecamatan / Kota Asal (pickup) vs Tujuan (delivery)
    $matchGeoInText = static function (string $segment) use ($masters): array {
        $seg = mb_strtolower(trim($segment), 'UTF-8');
        if ($seg === '') {
            return [];
        }
        $bestDist = 0;
        $bestDistLen = 0;
        foreach ($masters['districts_raw'] ?? [] as $d) {
            $dn = mb_strtolower((string) $d['name'], 'UTF-8');
            if (strlen($dn) >= 3 && stripos($seg, $dn) !== false && strlen($dn) > $bestDistLen) {
                $bestDist = (int) $d['id'];
                $bestDistLen = strlen($dn);
            }
        }
        if ($bestDist > 0) {
            return ['district_id' => $bestDist];
        }
        $bestCity = 0;
        $bestCityLen = 0;
        foreach ($masters['cities_raw'] ?? [] as $c) {
            $cn = mb_strtolower((string) $c['name'], 'UTF-8');
            $short = trim((string) preg_replace('/^(?:kota administrasi|kabupaten|kota)\s+/u', '', $cn));
            foreach ([$cn, $short] as $cand) {
                if (strlen($cand) >= 3 && stripos($seg, $cand) !== false && strlen($cand) > $bestCityLen) {
                    $bestCity = (int) $c['id'];
                    $bestCityLen = strlen($cand);
                }
            }
        }
        return $bestCity > 0 ? ['city_id' => $bestCity] : [];
    };

    if (preg_match('/\b(?:jemput|dari|asal)\s+([^,;.\n]+?)(?=\s+(?:ke|tujuan|antar|jam|pukul|tgl|tanggal|besok|lusa|pelanggan|harga|tarif|rp|\d{2,})|$)/i', $raw, $pkM)) {
        $hit = $matchGeoInText($pkM[1]);
        if (!empty($hit['district_id'])) {
            $out['pickup_point_district_id'] = $hit['district_id'];
        } elseif (!empty($hit['city_id'])) {
            $out['pickup_point_city_id'] = $hit['city_id'];
        }
    }
    if (preg_match('/\b(?:ke|tujuan|antar)\s+([^,;.\n]+?)(?=\s+(?:dari|jemput|jam|pukul|tgl|tanggal|besok|lusa|pelanggan|harga|tarif|rp|\d{2,})|$)/i', $raw, $dlM)) {
        $hit = $matchGeoInText($dlM[1]);
        if (!empty($hit['district_id'])) {
            $out['delivery_point_district_id'] = $hit['district_id'];
        } elseif (!empty($hit['city_id'])) {
            $out['delivery_point_city_id'] = $hit['city_id'];
        }
    }

    // Jika salah satu sisi belum ketemu, cari kemunculan lokasi berurutan di kalimat penuh
    if (empty($out['pickup_point_district_id']) && empty($out['pickup_point_city_id'])
        || (empty($out['delivery_point_district_id']) && empty($out['delivery_point_city_id']))
    ) {
        $hits = [];
        foreach ($masters['districts_raw'] ?? [] as $d) {
            $dn = mb_strtolower((string) $d['name'], 'UTF-8');
            $pos = strlen($dn) >= 4 ? mb_stripos($raw, $dn, 0, 'UTF-8') : false;
            if ($pos !== false) {
                $hits[] = ['pos' => $pos, 'len' => strlen($dn), 'kind' => 'district', 'id' => (int) $d['id']];
            }
        }
        foreach ($masters['cities_raw'] ?? [] as $c) {
            $cn = mb_strtolower((string) $c['name'], 'UTF-8');
            $short = trim((string) preg_replace('/^(?:kota administrasi|kabupaten|kota)\s+/u', '', $cn));
            $pos = strlen($short) >= 4 ? mb_stripos($raw, $short, 0, 'UTF-8') : false;
            if ($pos !== false) {
                $hits[] = ['pos' => $pos, 'len' => strlen($short), 'kind' => 'city', 'id' => (int) $c['id']];
            }
        }
        usort($hits, static fn(array $a, array $b): int => ($a['pos'] <=> $b['pos']) ?: ($b['len'] <=> $a['len']));
        foreach ($hits as $h) {
            if (empty($out['pickup_point_district_id']) && empty($out['pickup_point_city_id'])) {
                $key = $h['kind'] === 'district' ? 'pickup_point_district_id' : 'pickup_point_city_id';
                $out[$key] = $h['id'];
            } elseif (empty($out['delivery_point_district_id']) && empty($out['delivery_point_city_id'])) {
                $sameDist = $h['kind'] === 'district' && (int) ($out['pickup_point_district_id'] ?? 0) === $h['id'];
                $sameCity = $h['kind'] === 'city' && (int) ($out['pickup_point_city_id'] ?? 0) === $h['id'];
                if (!$sameDist && !$sameCity) {
                    $key = $h['kind'] === 'district' ? 'delivery_point_district_id' : 'delivery_point_city_id';
                    $out[$key] = $h['id'];
                    break;
                }
            }
        }
    }

    return $out;
}

/**
 * Handler endpoint AJAX parsing teks bebas -> kandidat field booking JSON.
 *
 * @param 'travel'|'rental' $mode
 */
function booking_ai_parse_handle(string $mode = 'travel'): void
{
    $mode = ($mode === 'rental') ? 'rental' : 'travel';
    require_permission($mode === 'rental' ? 'rental.manage' : 'travel.manage');

    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metode harus POST.']);
        exit;
    }
    verify_csrf();

    $raw = trim((string) ($_POST['text'] ?? ''));
    if ($raw === '' || mb_strlen($raw) > 1000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Teks 1–1000 karakter.']);
        exit;
    }

    $cid = require_active_company();
    $db  = db();

    $mapIdName = static function (array $rows): array {
        $m = [];
        foreach ($rows as $r) {
            $m[(int) $r['id']] = (string) $r['name'];
        }
        return $m;
    };

    $tokens     = _booking_ai_extract_tokens($raw);
    $branches   = opts_branches($cid);
    $cats       = opts_categories($cid);
    $vehicles   = opts_vehicles($cid, [], 100);
    $passengers = opts_passengers($cid, [], 40);

    // Tambah pencarian pelanggan berdasarkan kata kunci di prompt jika total > 40
    if ($tokens) {
        $likes = [];
        $types = 'i';
        $params = [$cid];
        foreach (array_slice($tokens, 0, 5) as $tk) {
            $likes[] = 'name LIKE ?';
            $types .= 's';
            $params[] = '%' . addcslashes($tk, '%_\\') . '%';
        }
        $sqlPx = "SELECT id, code, phone_number, CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS name
                    FROM passengers
                   WHERE company_id = ? AND (" . implode(' OR ', $likes) . ")
                   ORDER BY name ASC LIMIT 25";
        $stPx = $db->prepare($sqlPx);
        $stPx->bind_param($types, ...$params);
        $stPx->execute();
        $extraPx = $stPx->get_result()->fetch_all(MYSQLI_ASSOC);
        $stPx->close();
        $pxById = [];
        foreach (array_merge($extraPx, $passengers) as $px) {
            $pxById[(int) $px['id']] = $px;
        }
        $passengers = array_values($pxById);
    }

    // Pre-filter kandidat kota dari rute perusahaan + kecocokan kata kunci
    $citiesMap = [];
    $stRtC = $db->prepare(
        "SELECT DISTINCT c.id, c.name, c.province_id
           FROM rutes r
           JOIN cities c ON (c.id = r.pickup_point_city_id OR c.id = r.delivery_point_city_id)
          WHERE r.company_id = ? AND r.is_active = 1
          ORDER BY c.name ASC LIMIT 30"
    );
    $stRtC->bind_param('i', $cid);
    $stRtC->execute();
    foreach ($stRtC->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
        $citiesMap[(int) $cr['id']] = $cr;
    }
    $stRtC->close();

    $districtsMap = [];
    if ($tokens) {
        $cLikes = [];
        $cTypes = '';
        $cParams = [];
        $dLikes = [];
        $dTypes = '';
        $dParams = [];
        foreach (array_slice($tokens, 0, 6) as $tk) {
            $pat = '%' . addcslashes($tk, '%_\\') . '%';
            $cLikes[] = '(c.name LIKE ? OR c.short_name LIKE ?)';
            $cTypes .= 'ss';
            $cParams[] = $pat;
            $cParams[] = $pat;

            $dLikes[] = 'd.name LIKE ?';
            $dTypes .= 's';
            $dParams[] = $pat;
        }

        $stC = $db->prepare("SELECT c.id, c.name, c.province_id FROM cities c WHERE " . implode(' OR ', $cLikes) . " ORDER BY c.name ASC LIMIT 30");
        $stC->bind_param($cTypes, ...$cParams);
        $stC->execute();
        foreach ($stC->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
            $citiesMap[(int) $cr['id']] = $cr;
        }
        $stC->close();

        $stD = $db->prepare(
            "SELECT d.id, d.name, d.city_id, d.province_id, COALESCE(c.name, '') AS city_name
               FROM districts d
               LEFT JOIN cities c ON c.id = d.city_id
              WHERE " . implode(' OR ', $dLikes) . "
              ORDER BY d.name ASC LIMIT 40"
        );
        $stD->bind_param($dTypes, ...$dParams);
        $stD->execute();
        foreach ($stD->get_result()->fetch_all(MYSQLI_ASSOC) as $dr) {
            $districtsMap[(int) $dr['id']] = $dr;
        }
        $stD->close();
    }

    $citiesRaw    = array_values($citiesMap);
    $districtsRaw = array_values($districtsMap);

    $peta = [
        'branch_id'                  => $mapIdName($branches),
        'passenger_id'               => $mapIdName($passengers),
        'category_vehicle_id'        => $mapIdName($cats),
        'vehicle_id'                 => $mapIdName($vehicles),
        'pickup_point_city_id'       => $mapIdName($citiesRaw),
        'delivery_point_city_id'     => $mapIdName($citiesRaw),
        'pickup_point_district_id'   => $mapIdName($districtsRaw),
        'delivery_point_district_id' => $mapIdName($districtsRaw),
    ];

    // Daftar kecamatan berlabel kota khusus untuk prompt LLM agar tidak tertukar kecamatan kembar
    $districtPromptMap = [];
    foreach ($districtsRaw as $dr) {
        $districtPromptMap[(int) $dr['id']] = (string) $dr['name'] . ($dr['city_name'] !== '' ? ' (' . $dr['city_name'] . ')' : '');
    }

    $fields = $mode === 'rental'
        ? [
            'date' => 10, 'start_date' => 10, 'end_date' => 10, 'time' => 5,
            'trip' => 12, 'bbm' => 12,
            'pickup_address' => 500, 'delivery_address' => 500, 'total_price' => 15, 'note' => 500,
        ]
        : [
            'date' => 10, 'time' => 5,
            'pickup_address' => 500, 'delivery_address' => 500, 'total_price' => 15, 'note' => 500,
        ];

    $aiConfigured = defined('AI_API_KEY') && AI_API_KEY !== '' && defined('AI_BASE_URL') && AI_BASE_URL !== '' && defined('AI_MODEL') && AI_MODEL !== '';
    $rawCandidate = [];
    $usedAi = false;

    if ($aiConfigured) {
        $hariIni = date('Y-m-d');
        $promptData = [
            'branch_id'        => $peta['branch_id'],
            'passenger_id'     => $peta['passenger_id'],
            'category_vehicle_id' => $peta['category_vehicle_id'],
            'vehicle_id'       => $peta['vehicle_id'],
            'city_candidates'  => $peta['pickup_point_city_id'],
            'district_candidates' => $districtPromptMap,
        ];
        $pilihTxt = '';
        foreach ($promptData as $k => $opts) {
            if ($opts) {
                $pilihTxt .= $k . ': ' . json_encode($opts, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }

        if ($mode === 'rental') {
            $system = "Kamu parser input booking RENTAL mobil. "
                . "Hari ini {$hariIni}, besok " . date('Y-m-d', strtotime('+1 day')) . ", lusa " . date('Y-m-d', strtotime('+2 days')) . ". "
                . "Tanggal harus antara {$hariIni} s/d " . date('Y-m-d', strtotime('+60 days')) . ". "
                . 'Balas HANYA JSON valid: {"branch_id":int,"passenger_id":int,"category_vehicle_id":int,"vehicle_id":int,'
                . '"pickup_point_city_id":int,"pickup_point_district_id":int,"delivery_point_city_id":int,"delivery_point_district_id":int,'
                . '"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD","time":"HH:MM","trip":number,"bbm":number,'
                . '"pickup_address":string,"delivery_address":string,"total_price":number,"note":string}. '
                . "Semua *_id WAJIB dipilih dari daftar pilihan (city dari city_candidates, district dari district_candidates). Field kosong jangan dikarang. \"sewa 2 hari\" berarti end_date = start_date + 1 hari.";
        } else {
            $system = "Kamu parser input booking TRAVEL penumpang. "
                . "Hari ini {$hariIni}, besok " . date('Y-m-d', strtotime('+1 day')) . ", lusa " . date('Y-m-d', strtotime('+2 days')) . ". "
                . "Tanggal harus antara {$hariIni} s/d " . date('Y-m-d', strtotime('+60 days')) . ". "
                . 'Balas HANYA JSON valid: {"branch_id":int,"passenger_id":int,"category_vehicle_id":int,"vehicle_id":int,'
                . '"pickup_point_city_id":int,"pickup_point_district_id":int,"delivery_point_city_id":int,"delivery_point_district_id":int,'
                . '"date":"YYYY-MM-DD","time":"HH:MM","pickup_address":string,"delivery_address":string,"total_price":number,"note":string}. '
                . "Semua *_id WAJIB dipilih dari daftar pilihan (city dari city_candidates, district dari district_candidates). Field kosong jangan dikarang.";
        }

        $res = ai_chat_json([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Daftar pilihan:\n{$pilihTxt}\nInput user:\n{$raw}"],
        ], ['stream' => false], 12, 5);
        if (!empty($res['ok']) && !empty($res['data']) && is_array($res['data'])) {
            $rawCandidate = $res['data'];
            $usedAi = true;
        }
    }

    // Fallback deterministik lokal bila 9router tidak merespons/timeout/belum diisi
    $localParsed = booking_ai_local_parse($mode, $raw, [
        'branches'      => $branches,
        'passengers'    => $passengers,
        'cats'          => $cats,
        'vehicles'      => $vehicles,
        'cities_raw'    => $citiesRaw,
        'districts_raw' => $districtsRaw,
    ]);
    if (!$usedAi || !$rawCandidate) {
        $rawCandidate = $localParsed;
    } else {
        // Lengkapi field yang mungkin terlewat oleh model dari parser lokal (misal tanggal/jam/lokasi)
        foreach ($localParsed as $lk => $lv) {
            if (!isset($rawCandidate[$lk]) || $rawCandidate[$lk] === '' || $rawCandidate[$lk] === 0 || $rawCandidate[$lk] === '0') {
                $rawCandidate[$lk] = $lv;
            }
        }
    }

    $candidate = ai_sanitize_candidate($rawCandidate, $peta, $fields);

    $tanggalOk = static function (string $s): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        $t = strtotime($s);
        if ($t === false) {
            return null;
        }
        $min = strtotime(date('Y-m-d'));
        $max = strtotime('+60 days');
        return ($t >= $min && $t <= $max) ? date('Y-m-d', $t) : null;
    };

    if (isset($candidate['date'])) {
        $candidate['date'] = $tanggalOk((string) $candidate['date']) ?? '';
    }
    if ($mode === 'rental') {
        if (isset($candidate['start_date'])) {
            $candidate['start_date'] = $tanggalOk((string) $candidate['start_date']) ?? '';
        }
        if (isset($candidate['end_date'])) {
            $candidate['end_date'] = $tanggalOk((string) $candidate['end_date']) ?? '';
            if (!empty($candidate['start_date']) && !empty($candidate['end_date']) && $candidate['end_date'] < $candidate['start_date']) {
                $candidate['end_date'] = '';
            }
        }
        if (!empty($candidate['start_date']) && empty($candidate['end_date'])) {
            $candidate['end_date'] = $candidate['start_date'];
        }
        if (!empty($candidate['start_date']) && !empty($candidate['end_date'])) {
            $days = (int) round((strtotime($candidate['end_date']) - strtotime($candidate['start_date'])) / 86400) + 1;
            if ($days >= 1) {
                $candidate['duration_days'] = $days;
            }
        }
    }

    if (isset($candidate['time'])) {
        $candidate['time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $candidate['time']) ? $candidate['time'] : '';
    }
    foreach (['total_price', 'bbm'] as $numField) {
        if (isset($candidate[$numField])) {
            $parsedNum = parse_rupiah_input($candidate[$numField]);
            $candidate[$numField] = $parsedNum > 0 ? $parsedNum : '';
        }
    }
    if ($mode === 'rental' && isset($candidate['trip'])) {
        $candidate['trip'] = is_numeric((string) $candidate['trip']) && (float) $candidate['trip'] >= 0
            ? (float) $candidate['trip'] : '';
    }

    // Lengkapi hierarki Provinsi + Kota secara otomatis agar dropdown bertingkat langsung sinkron
    $peta['pickup_point_province_id']   = [];
    $peta['delivery_point_province_id'] = [];
    $resolveGeoSide = static function (string $prefix) use (&$candidate, &$peta, $db): void {
        $distKey = $prefix . '_point_district_id';
        $cityKey = $prefix . '_point_city_id';
        $provKey = $prefix . '_point_province_id';

        $distId = (int) ($candidate[$distKey] ?? 0);
        $cityId = (int) ($candidate[$cityKey] ?? 0);
        $provId = 0;

        if ($distId > 0 && ($info = resolve_district_info($distId))) {
            $cityId = $info['city_id'];
            $provId = $info['province_id'];
            if ($cityId > 0) {
                $candidate[$cityKey] = $cityId;
            }
        } elseif ($cityId > 0) {
            $provId = resolve_city_province_id($cityId);
        }

        if ($cityId > 0 && !isset($peta[$cityKey][$cityId])) {
            $stC = $db->prepare('SELECT name FROM cities WHERE id = ? LIMIT 1');
            $stC->bind_param('i', $cityId);
            $stC->execute();
            $cn = (string) ($stC->get_result()->fetch_column() ?: '');
            $stC->close();
            if ($cn !== '') {
                $peta[$cityKey][$cityId] = $cn;
            }
        }
        if ($provId > 0) {
            $candidate[$provKey] = $provId;
            $stP = $db->prepare("SELECT CONCAT(code, ' - ', name) FROM provinces WHERE id = ? LIMIT 1");
            $stP->bind_param('i', $provId);
            $stP->execute();
            $pn = (string) ($stP->get_result()->fetch_column() ?: '');
            $stP->close();
            if ($pn !== '') {
                $peta[$provKey][$provId] = $pn;
            }
        }
    };
    $resolveGeoSide('pickup');
    $resolveGeoSide('delivery');

    // Deteksi pelanggan baru jika passenger_id belum ditemukan di daftar master
    $newPassenger = null;
    if (empty($candidate['passenger_id'])) {
        $extractedPax = _booking_ai_extract_new_passenger($raw);
        $candName     = $extractedPax['name'];
        $candPhone    = $extractedPax['phone'];

        // Cek sekali lagi di DB apakah nomor HP atau nama persis sudah ada di perusahaan ini
        if ($candPhone !== '' || $candName !== '') {
            if ($candPhone !== '') {
                $stFind = $db->prepare(
                    "SELECT id, CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS label
                       FROM passengers
                      WHERE company_id = ? AND (phone_number = ? OR LOWER(name) = LOWER(?))
                      ORDER BY (phone_number = ?) DESC, id DESC LIMIT 1"
                );
                $stFind->bind_param('isss', $cid, $candPhone, $candName, $candPhone);
            } else {
                $stFind = $db->prepare(
                    "SELECT id, CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS label
                       FROM passengers
                      WHERE company_id = ? AND LOWER(name) = LOWER(?)
                      ORDER BY id DESC LIMIT 1"
                );
                $stFind->bind_param('is', $cid, $candName);
            }
            $stFind->execute();
            $foundPax = $stFind->get_result()->fetch_assoc();
            $stFind->close();

            if ($foundPax) {
                $foundPid = (int) $foundPax['id'];
                $candidate['passenger_id'] = $foundPid;
                $peta['passenger_id'][$foundPid] = (string) $foundPax['label'];
            } elseif ($candName !== '') {
                $newPassenger = [
                    'name'  => $candName,
                    'phone' => $candPhone,
                ];
            }
        }
    }

    echo json_encode([
        'ok'            => true,
        'candidate'     => $candidate,
        'peta'          => $peta,
        'new_passenger' => $newPassenger,
        'fallback'      => !$usedAi,
    ], JSON_UNESCAPED_UNICODE);
}
