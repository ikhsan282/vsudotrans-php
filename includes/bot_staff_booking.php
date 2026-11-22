<?php
/**
 * Helper layanan Admin Grup WhatsApp Hermes (`api/bot/staff_*.php`):
 * - Validasi aktor & permission RBAC tanpa bergantung $_SESSION
 * - Audit Trail ke `audit_trails` dengan jejak `wa:<username>`
 * - Parser form pesanan WhatsApp yang fleksibel (styled text, singkatan tarif/jam/tanggal,
 *   strip template NOTE, hybrid fallback LLM saat field penting belum lengkap)
 * - Resolusi kota/kecamatan/rute akurat + deteksi duplikat + format rekap manifest WA
 */

require_once __DIR__ . '/booking_service.php';
require_once __DIR__ . '/invoice_service.php';
require_once __DIR__ . '/transport_helpers.php';
require_once __DIR__ . '/finance_sync.php';
require_once __DIR__ . '/ai.php';

/**
 * Pastikan pengirim adalah Admin/Staff perusahaan aktif (`is_staff`) serta memiliki
 * minimal salah satu permission pada `$requiredAnyPerms` (jika diisi).
 *
 * @param array<string,mixed> $ctx Hasil `bot_boot()`
 * @param string[] $requiredAnyPerms
 * @return array{staff:array<string,mixed>, perms:string[], is_admin:bool, audit_user:string}
 */
function bot_staff_require(array $ctx, array $requiredAnyPerms = []): array
{
    $actor = $ctx['actor'] ?? null;
    if (empty($actor['is_staff']) || empty($actor['staff'])) {
        bot_error(403, 'STAFF_ONLY', 'Akses khusus Admin/Staff perusahaan yang nomor WA-nya terdaftar.');
    }

    $staff   = $actor['staff'];
    $roleId  = (int) ($staff['role_id'] ?? 0);
    $isSuper = ($roleId === 1 || strcasecmp((string) ($staff['role_label'] ?? ''), 'Super Admin') === 0);
    $perms   = load_permissions((int) $staff['user_id'], (string) ($staff['role_label'] ?? ''));

    if ($requiredAnyPerms && !$isSuper) {
        $has = false;
        foreach ($requiredAnyPerms as $perm) {
            if (in_array($perm, $perms, true)) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            bot_error(403, 'FORBIDDEN_PERMISSION', 'Akun Anda tidak memiliki izin untuk aksi ini.');
        }
    }

    $uname = trim((string) ($staff['username'] ?: $staff['name']));
    return [
        'staff'      => $staff,
        'perms'      => $perms,
        'is_super'   => $isSuper,
        'is_admin'   => ($roleId > 0 && $roleId <= 2),
        'audit_user' => mb_substr('wa:' . ($uname !== '' ? $uname : 'staff'), 0, 100),
    ];
}

/** Catat aktivitas WhatsApp Admin langsung ke `audit_trails` tanpa membutuhkan `$_SESSION`. */
function bot_staff_audit(mysqli $db, int $cid, array $staff, string $action, string $description): void
{
    try {
        $uid  = (int) ($staff['user_id'] ?? 0) ?: null;
        $user = mb_substr('wa:' . trim((string) ($staff['username'] ?: ($staff['name'] ?? 'staff'))), 0, 100);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
        $desc = mb_substr($description, 0, 1000);
        $st   = $db->prepare('INSERT INTO audit_trails (user_id, username, company_id, action, description, ip_address) VALUES (?,?,?,?,?,?)');
        $st->bind_param('isssss', $uid, $user, $cid, $action, $desc, $ip);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        error_log('bot_staff_audit: ' . $e->getMessage());
    }
}

/** Cek apakah user memiliki permission tertentu dari daftar permission ter-load (atau Super Admin). */
function bot_staff_has_perm(array $staffCtx, string $perm): bool
{
    if (!empty($staffCtx['is_super'])) {
        return true;
    }
    return in_array($perm, $staffCtx['perms'] ?? [], true);
}

/** Konversi Unicode Mathematical Alphanumeric Symbols (mis. 𝙽𝙾𝚃𝙴) ke ASCII normal. */
function bot_staff_normalize_styled_text(string $text): string
{
    $out = preg_replace_callback('/[\x{1D400}-\x{1D7FF}]/u', static function (array $m): string {
        $cp = mb_ord($m[0], 'UTF-8');
        if ($cp === false) {
            return $m[0];
        }
        $alphaRanges = [
            0x1D400, 0x1D434, 0x1D468, 0x1D49C, 0x1D4D0, 0x1D504, 0x1D538,
            0x1D56C, 0x1D5A0, 0x1D5D4, 0x1D608, 0x1D63C, 0x1D670,
        ];
        foreach ($alphaRanges as $start) {
            if ($cp >= $start && $cp < $start + 26) {
                return chr(ord('A') + ($cp - $start));
            }
            if ($cp >= $start + 26 && $cp < $start + 52) {
                return chr(ord('a') + ($cp - $start - 26));
            }
        }
        $digitRanges = [0x1D7CE, 0x1D7D8, 0x1D7E2, 0x1D7EC, 0x1D7F6];
        foreach ($digitRanges as $start) {
            if ($cp >= $start && $cp < $start + 10) {
                return chr(ord('0') + ($cp - $start));
            }
        }
        return $m[0];
    }, $text);

    return (string) ($out ?? $text);
}

/** Hapus boilerplate template form (header website, garis pembatas, blok NOTE, ucapan terima kasih). */
function bot_staff_strip_boilerplate(string $text): string
{
    $norm  = str_replace(["\r\n", "\r"], "\n", bot_staff_normalize_styled_text($text));
    $lines = explode("\n", $norm);
    $kept  = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (preg_match('/^[_=\-\*~─═]{3,}$/u', $trimmed)) {
            continue;
        }
        if (preg_match('/^https?:\/\/\S+$/i', $trimmed)) {
            continue;
        }
        if (preg_match('/^(?:form\s+pemesanan\b|note\b\s*[:\-]|catatan\s+umum\s*[:\-]|terima\s*kasih\b)/i', $trimmed)) {
            if (preg_match('/^(?:note\b|catatan\s+umum)\s*[:\-]/i', $trimmed)) {
                break; // Blok NOTE template & seterusnya di bagian bawah form
            }
            continue;
        }
        if (preg_match('/^\d+\.\s*jika\s+(?:barang\s+bawaan|ada\s+keperluan)/i', $trimmed)) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}

/** Parse tanggal teks bebas Indonesia/Inggris (ISO, DD/MM/YYYY, `20 November 2026`, `besok`, `lusa`, `tgl 20`). */
function bot_staff_parse_indonesian_date(string $raw, ?string $refDate = null): ?string
{
    $s = trim(bot_staff_normalize_styled_text($raw));
    if ($s === '') {
        return null;
    }
    $lower   = mb_strtolower($s, 'UTF-8');
    $baseTs  = ($refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate))
        ? (strtotime($refDate) ?: strtotime(date('Y-m-d')))
        : strtotime(date('Y-m-d'));
    $baseYmd = date('Y-m-d', $baseTs);
    $baseYm  = date('Y-m', $baseTs);
    $baseYr  = (int) date('Y', $baseTs);

    if (preg_match('/\b(hari\s+ini|today)\b/u', $lower)) {
        return $baseYmd;
    }
    if (preg_match('/\b(besok|tomorrow)\b/u', $lower)) {
        return date('Y-m-d', strtotime('+1 day', $baseTs));
    }
    if (preg_match('/\blusa\b/u', $lower)) {
        return date('Y-m-d', strtotime('+2 days', $baseTs));
    }

    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $s, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[0];
        }
    }
    if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    $months = [
        'januari' => 1, 'jan' => 1, 'january' => 1,
        'februari' => 2, 'feb' => 2, 'pebruari' => 2, 'february' => 2,
        'maret' => 3, 'mar' => 3, 'march' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'aug' => 8, 'august' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'oct' => 10, 'october' => 10,
        'november' => 11, 'nov' => 11, 'nopember' => 11, 'nop' => 11,
        'desember' => 12, 'des' => 12, 'dec' => 12, 'december' => 12,
    ];
    $monthPat = implode('|', array_keys($months));
    if (preg_match('/\b(\d{1,2})\s*(' . $monthPat . ')(?:\s*,?\s*(\d{2,4}))?\b/iu', $lower, $mm)) {
        $d  = (int) $mm[1];
        $mo = $months[strtolower($mm[2])] ?? 0;
        if (!empty($mm[3])) {
            $y = (int) $mm[3];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            $y = $baseYr;
            if ($mo > 0 && $d > 0 && checkdate($mo, $d, $y)) {
                $cand = strtotime(sprintf('%04d-%02d-%02d', $y, $mo, $d));
                if ($cand !== false && $cand < strtotime('-60 days', $baseTs)) {
                    $y++;
                }
            }
        }
        if ($mo > 0 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    if (preg_match('/\b(?:tgl|tanggal)\s+(\d{1,2})\b/iu', $lower, $tm)) {
        $dom = (int) $tm[1];
        if ($dom >= 1 && $dom <= 31) {
            $cand = sprintf('%s-%02d', $baseYm, $dom);
            if ($cand < $baseYmd) {
                $cand = date('Y-m', strtotime('first day of next month', $baseTs)) . sprintf('-%02d', $dom);
            }
            $parts = explode('-', $cand);
            if (count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                return $cand;
            }
        }
    }

    return null;
}

/** Parse jam keberangkatan (mis. `19.00`, `19:00`, `jam 7 malam`, `jam 8 pagi`). Mengembalikan `HH:MM`. */
function bot_staff_parse_time(string $raw): ?string
{
    $lower = mb_strtolower(trim($raw), 'UTF-8');
    if ($lower === '') {
        return null;
    }

    if (preg_match('/\b([01]?\d|2[0-3])[:.]([0-5]\d)(?::[0-5]\d)?\s*(pagi|siang|sore|malam|wib|wita|wit)?\b/iu', $lower, $m)) {
        $h   = (int) $m[1];
        $min = (int) $m[2];
        $mer = strtolower($m[3] ?? '');
        if (($mer === 'sore' || $mer === 'malam') && $h > 0 && $h < 12) {
            $h += 12;
        } elseif ($mer === 'siang' && $h >= 1 && $h <= 5) {
            $h += 12;
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    if (preg_match('/\b(?:jam|pukul|pkl)?\s*([01]?\d|2[0-3])\s*(pagi|siang|sore|malam|wib|wita|wit)\b/iu', $lower, $m2)
        || preg_match('/\b(?:jam|pukul|pkl)\s*([01]?\d|2[0-3])\b/iu', $lower, $m2)
    ) {
        $h   = (int) $m2[1];
        $mer = strtolower($m2[2] ?? '');
        if (($mer === 'sore' || $mer === 'malam') && $h > 0 && $h < 12) {
            $h += 12;
        } elseif ($mer === 'siang' && $h >= 1 && $h <= 5) {
            $h += 12;
        }
        return sprintf('%02d:00', $h);
    }

    return null;
}

/** Parse nominal Rupiah termasuk akhiran `rb`/`ribu`/`k`/`jt`/`juta`, ekspresi `=`, dan penjumlahan `+`. */
function bot_staff_parse_rupiah(mixed $raw, bool $allowBareThousands = false): float
{
    if (is_int($raw) || is_float($raw)) {
        return max(0.0, (float) $raw);
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return 0.0;
    }

    // Jika ada '=', ambil hasil akhir setelah '=' terakhir (mis. "250rb x 2 = 500")
    if (str_contains($s, '=')) {
        $eqParts = array_values(array_filter(array_map('trim', explode('=', $s)), static fn(string $p): bool => $p !== ''));
        if ($eqParts) {
            return bot_staff_parse_rupiah((string) end($eqParts), true);
        }
    }

    // Hapus keterangan dalam kurung bila sudah ada angka sebelum '(' (mis. "500rb (200rb Bandara + 300rb Bekasi)")
    $work = $s;
    if (preg_match('/\d[^(]*\(/', $s)) {
        $stripped = trim((string) preg_replace('/\([^)]*\)/', ' ', $s));
        if (preg_match('/\d/', $stripped)) {
            $work = $stripped;
        }
    }

    // Jumlahkan suku dipisah '+' (mis. "250rb + 50" atau "250rb + 30rb")
    if (str_contains($work, '+')) {
        $hasUnit = (bool) preg_match('/\b(?:jt|juta|rb|ribu|k)\b/i', $work);
        $sum = 0.0;
        foreach (explode('+', $work) as $term) {
            $sum += bot_staff_parse_rupiah($term, $allowBareThousands || $hasUnit);
        }
        return round($sum, 2);
    }

    if (preg_match('/([0-9]+(?:[.,][0-9]+)*)\s*(jt|juta|rb|ribu|k)\b/i', $work, $m)) {
        $unit = strtolower($m[2]);
        $num  = str_replace(' ', '', $m[1]);
        $norm = preg_match('/^\d+(?:\.\d{3})+$/', $num)
            ? str_replace('.', '', $num)
            : str_replace(',', '.', $num);
        $mult = ($unit === 'jt' || $unit === 'juta') ? 1000000 : 1000;
        return max(0.0, round((float) $norm * $mult, 2));
    }

    if ($allowBareThousands && preg_match('/^\D*(\d{2,4})\D*$/', $work, $bm)) {
        $v = (int) $bm[1];
        if ($v >= 10 && $v <= 5000) {
            return round($v * 1000.0, 2);
        }
    }

    return max(0.0, parse_rupiah_input($work));
}

/** Muat indeks kota Indonesia (di-cache per request untuk pencocokan cepat tanpa ribuan query). */
function _bot_staff_cities_catalog(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = $db->query("SELECT id, name, short_name, province_id FROM cities ORDER BY LENGTH(name) DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['id']          = (int) $r['id'];
        $r['province_id'] = (int) $r['province_id'];
        $nameLower        = mb_strtolower((string) $r['name'], 'UTF-8');
        $short            = trim((string) preg_replace('/^(?:kota\s+administrasi|kota|kabupaten|kab\.?)\s+/u', '', $nameLower));
        $r['_lower']      = $nameLower;
        $r['_short']      = $short;
        $r['_short_code'] = strtoupper(trim((string) ($r['short_name'] ?? '')));
    }
    unset($r);
    $cache = $rows;
    return $cache;
}

/**
 * Resolusi kota & kecamatan dari alamat bebas (contoh: `..., Gambir, Jakarta Pusat`
 * atau `Panyileukan Regency Blok C no. 2, Cipadung Kidul, Panyileukan, Kota Bandung`).
 *
 * @return array{province_id:int,city_id:int,district_id:int,city_name:string,district_name:string}
 */
function bot_staff_resolve_address_geo(mysqli $db, string $address, int $companyId = 0): array
{
    $out = ['province_id' => 0, 'city_id' => 0, 'district_id' => 0, 'city_name' => '', 'district_name' => ''];
    $cleanAddr = trim($address);
    if ($cleanAddr === '') {
        return $out;
    }

    // Normalisasi salinan kerja (hapus URL, perbaiki typo umum, ekspansi singkatan kota & landmark populer)
    $normAddr = (string) preg_replace('/https?:\/\/\S+/iu', ' ', $cleanAddr);
    $normAddr = (string) preg_replace([
        '/\bkab\.\s*(?=[a-z])/iu',
        '/\bkab\s+(?=[a-z])/iu',
        '/\btanggerang\b/iu',
        '/\btasikamalaya\b/iu',
        '/\bjakarata\b/iu',
        '/\bujung\s+berung\b/iu',
        '/\bKota\.?\s*Tangerang\b/iu',
        '/\btangsel\b/iu',
        '/\b(?:jaksel|jkt\s+selatan)\b/iu',
        '/\b(?:jakbar|jkt\s+barat)\b/iu',
        '/\b(?:jaktim|jkt\s+timur)\b/iu',
        '/\b(?:jakpus|jkt\s+pusat)\b/iu',
        '/\b(?:jakut|jkt\s+utara)\b/iu',
        '/\b(?:kota\s+)?bks\b/iu',
    ], [
        'Kabupaten ',
        'Kabupaten ',
        'Tangerang',
        'Tasikmalaya',
        'Jakarta',
        'Ujungberung',
        'Kota Tangerang',
        ', Kota Tangerang Selatan',
        ', Kota Administrasi Jakarta Selatan',
        ', Kota Administrasi Jakarta Barat',
        ', Kota Administrasi Jakarta Timur',
        ', Kota Administrasi Jakarta Pusat',
        ', Kota Administrasi Jakarta Utara',
        ', Kota Bekasi',
    ], $normAddr);

    $landmarkHints = [];
    if (preg_match('/\b(?:bandara\s+soet{1,2}a|soekarno[\s\-\x{2013}\x{2014}]+hatta|\bcgk\b|swiss[\-\s]*belhotel\s+airport)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Benda, Kota Tangerang';
    }
    if (preg_match('/\b(?:tangcity|victoria\s+park)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Tangerang, Kota Tangerang';
    }
    if (preg_match('/\bgading\s+serpong\b/iu', $normAddr)) {
        $landmarkHints[] = 'Kelapa Dua, Kabupaten Tangerang';
    }
    if (preg_match('/\bkarawaci\b/iu', $normAddr)) {
        $landmarkHints[] = 'Karawaci, Kota Tangerang';
    }
    if (preg_match('/\b(?:cipadu)\b/iu', $normAddr) || (preg_match('/\blarangan\b/iu', $normAddr) && preg_match('/tangerang|cipadu|ciledug/i', $normAddr))) {
        $landmarkHints[] = 'Larangan, Kota Tangerang';
    }
    if (preg_match('/\bpinang\b/iu', $normAddr) && preg_match('/tangerang|banten|ciledug|cipondoh|gardenia/i', $normAddr)) {
        $landmarkHints[] = 'Pinang, Kota Tangerang';
    }
    if (preg_match('/\b(?:serpong\s+utara|jelupang)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Serpong Utara, Kota Tangerang Selatan';
    } elseif (preg_match('/\b(?:eka\s+hospital|nako\s+bsd|bsd)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Serpong, Kota Tangerang Selatan';
    }
    if (preg_match('/\b(?:pamulang|pondok\s+cabe)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Pamulang, Kota Tangerang Selatan';
    }
    if (preg_match('/\bciputat\b/iu', $normAddr)) {
        $landmarkHints[] = 'Ciputat, Kota Tangerang Selatan';
    }
    if (preg_match('/\b(?:pondok\s+aren|jurang\s+mangu)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Pondok Aren, Kota Tangerang Selatan';
    }
    if (preg_match('/\bgunung\s+sindur\b/iu', $normAddr)) {
        $landmarkHints[] = 'Gunung Sindur, Kabupaten Bogor';
    }
    if (preg_match('/\bbalaraja\b/iu', $normAddr)) {
        $landmarkHints[] = 'Balaraja, Kabupaten Tangerang';
    }
    if (preg_match('/\bcikupa\b/iu', $normAddr)) {
        $landmarkHints[] = 'Cikupa, Kabupaten Tangerang';
    }
    if (preg_match('/\bcengkareng\b/iu', $normAddr)) {
        $landmarkHints[] = 'Cengkareng, Kota Administrasi Jakarta Barat';
    }
    if (preg_match('/\b(?:grogol|harapan\s+kita)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Grogol Petamburan, Kota Administrasi Jakarta Barat';
    }
    if (preg_match('/\bbinus\b/iu', $normAddr)) {
        $landmarkHints[] = 'Kebon Jeruk, Kota Administrasi Jakarta Barat';
    }
    if (preg_match('/\bmabes\b/iu', $normAddr) && preg_match('/jakarta\s+barat|jakbar|taman\s+sari/i', $normAddr)) {
        $landmarkHints[] = 'Taman Sari, Kota Administrasi Jakarta Barat';
    }
    if (preg_match('/\b(?:muara\s+angke|kali\s+adem)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Penjaringan, Kota Administrasi Jakarta Utara';
    }
    if (preg_match('/\b(?:ancol|mangga\s+2|mangga\s+dua)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Pademangan, Kota Administrasi Jakarta Utara';
    }
    if (preg_match('/\b(?:salemba|rivoli)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Senen, Kota Administrasi Jakarta Pusat';
    }
    if (preg_match('/\bmenteng\b/iu', $normAddr)) {
        $landmarkHints[] = 'Menteng, Kota Administrasi Jakarta Pusat';
    }
    if (preg_match('/\b(?:gambir|hayam\s+[wm]uruk)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Gambir, Kota Administrasi Jakarta Pusat';
    }
    if (preg_match('/\bcilandak\b/iu', $normAddr)) {
        $landmarkHints[] = 'Cilandak, Kota Administrasi Jakarta Selatan';
    }
    if (preg_match('/\b(?:jagakarsa|srengseng\s+sawah)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Jagakarsa, Kota Administrasi Jakarta Selatan';
    }
    if (preg_match('/\b(?:cangkuang|soreang)\b/iu', $normAddr)) {
        $landmarkHints[] = 'Cangkuang, Kabupaten Bandung';
    }
    if (preg_match('/\bpagerageung\b/iu', $normAddr)) {
        $landmarkHints[] = 'Pagerageung, Kabupaten Tasikmalaya';
    }
    if ($landmarkHints) {
        $normAddr .= ', ' . implode(', ', array_unique($landmarkHints));
    }

    $segments = array_values(array_filter(
        array_map('trim', preg_split('/[,;\n]+/', $normAddr) ?: []),
        static fn(string $s): bool => $s !== ''
    ));
    $cleanSeg = static function (string $seg): string {
        $s = mb_strtolower(trim($seg), 'UTF-8');
        $s = (string) preg_replace('/^(?:kecamatan|kec\.?|kelurahan|kel\.?|desa|kabupaten|kab\.?)\s+/u', '', $s);
        return trim($s);
    };
    $revSegs = array_reverse($segments);

    // 1. Cari kota terlebih dahulu (scan segmen kanan -> kiri; prioritaskan kecocokan penuh sebelum bentuk pendek)
    $cities = _bot_staff_cities_catalog($db);
    $matchedCity = null;
    $bestScore   = 0;

    foreach ($revSegs as $segIdx => $seg) {
        $segLow = mb_strtolower($seg, 'UTF-8');
        foreach ($cities as $c) {
            if ($c['_lower'] === $segLow) {
                $score = 1000 + strlen($c['_lower']) - $segIdx;
                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $matchedCity = $c;
                }
            } elseif ($c['_short'] !== '' && $c['_short'] === $segLow) {
                // Utamakan 'Kota' jika nama kota kembar (Bandung -> Kota Bandung > Kab Bandung kecuali disebut Kabupaten)
                $kotaBonus = str_starts_with($c['_lower'], 'kota ') ? 20 : 0;
                $score     = 800 + strlen($c['_short']) + $kotaBonus - $segIdx;
                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $matchedCity = $c;
                }
            } elseif (strlen($segLow) >= 4 && preg_match('/\b' . preg_quote($c['_short'], '/') . '\b/u', $segLow)) {
                $fullMatchBonus = preg_match('/\b' . preg_quote($c['_lower'], '/') . '\b/u', $segLow) ? 200 : 0;
                $kotaBonus      = str_starts_with($c['_lower'], 'kota ') ? 15 : 0;
                $score          = 500 + $fullMatchBonus + strlen($c['_short']) + $kotaBonus - $segIdx;
                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $matchedCity = $c;
                }
            } elseif ($c['_short_code'] !== '' && strtoupper(trim($seg)) === $c['_short_code']) {
                $score = 400 - $segIdx;
                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $matchedCity = $c;
                }
            }
        }
        if ($matchedCity !== null && $bestScore >= 800) {
            break;
        }
    }

    if ($matchedCity !== null) {
        $out['city_id']     = $matchedCity['id'];
        $out['province_id'] = $matchedCity['province_id'];
        $out['city_name']   = (string) $matchedCity['name'];
    }

    // 2. Cari kecamatan di dalam kota yang sudah ditemukan
    if ($out['city_id'] > 0) {
        $stD = $db->prepare('SELECT id, name, province_id FROM districts WHERE city_id = ? ORDER BY LENGTH(name) DESC, id ASC');
        $stD->bind_param('i', $out['city_id']);
        $stD->execute();
        $distRows = $stD->get_result()->fetch_all(MYSQLI_ASSOC);
        $stD->close();

        foreach ($revSegs as $seg) {
            $sClean = $cleanSeg($seg);
            foreach ($distRows as $d) {
                $dn = mb_strtolower((string) $d['name'], 'UTF-8');
                if ($sClean === $dn) {
                    $out['district_id']   = (int) $d['id'];
                    $out['district_name'] = (string) $d['name'];
                    $out['province_id']   = (int) $d['province_id'] ?: $out['province_id'];
                    return $out;
                }
            }
        }
        foreach ($revSegs as $seg) {
            $sClean = $cleanSeg($seg);
            foreach ($distRows as $d) {
                $dn = mb_strtolower((string) $d['name'], 'UTF-8');
                if (strlen($dn) >= 4 && preg_match('/\b' . preg_quote($dn, '/') . '\b/u', $sClean)) {
                    $out['district_id']   = (int) $d['id'];
                    $out['district_name'] = (string) $d['name'];
                    $out['province_id']   = (int) $d['province_id'] ?: $out['province_id'];
                    return $out;
                }
            }
        }
        // Fallback ke kecamatan pertama di kota tersebut jika user hanya menuliskan nama kota
        if (!empty($distRows)) {
            $last = end($distRows);
            $stFirst = $db->prepare('SELECT id, name, province_id FROM districts WHERE city_id = ? ORDER BY id ASC LIMIT 1');
            $stFirst->bind_param('i', $out['city_id']);
            $stFirst->execute();
            $firstD = $stFirst->get_result()->fetch_assoc() ?: $last;
            $stFirst->close();
            $out['district_id']   = (int) $firstD['id'];
            $out['district_name'] = (string) $firstD['name'];
            $out['province_id']   = (int) $firstD['province_id'] ?: $out['province_id'];
        }
        return $out;
    }

    // 3. Jika nama kota tidak tercantum, cari nama kecamatan eksak di tabel districts (prioritas kota pada rute aktif)
    foreach ($revSegs as $seg) {
        $sClean = $cleanSeg($seg);
        if (mb_strlen($sClean, 'UTF-8') < 4) {
            continue;
        }
        if ($companyId > 0) {
            $stD2 = $db->prepare(
                "SELECT d.id, d.name, d.city_id, d.province_id, c.name AS city_name,
                        EXISTS(SELECT 1 FROM rutes r WHERE r.company_id = ? AND r.is_active = 1
                               AND (r.pickup_point_city_id = d.city_id OR r.delivery_point_city_id = d.city_id)) AS in_rute
                   FROM districts d
                   JOIN cities c ON c.id = d.city_id
                  WHERE LOWER(d.name) = ?
                  ORDER BY in_rute DESC, d.id ASC LIMIT 1"
            );
            $stD2->bind_param('is', $companyId, $sClean);
        } else {
            $stD2 = $db->prepare(
                "SELECT d.id, d.name, d.city_id, d.province_id, c.name AS city_name
                   FROM districts d
                   JOIN cities c ON c.id = d.city_id
                  WHERE LOWER(d.name) = ?
                  ORDER BY d.id ASC LIMIT 1"
            );
            $stD2->bind_param('s', $sClean);
        }
        $stD2->execute();
        $hitD = $stD2->get_result()->fetch_assoc();
        $stD2->close();
        if ($hitD) {
            return [
                'province_id'   => (int) $hitD['province_id'],
                'city_id'       => (int) $hitD['city_id'],
                'district_id'   => (int) $hitD['id'],
                'city_name'     => (string) $hitD['city_name'],
                'district_name' => (string) $hitD['name'],
            ];
        }
    }

    return $out;
}

/** Cari rute aktif milik perusahaan yang paling cocok dengan pasangan kota/kecamatan atau petunjuk kode rute. */
function bot_staff_match_route(mysqli $db, int $cid, int $pickupCityId, int $deliveryCityId, ?int $explicitRuteId = null, ?string $ruteCode = null): ?array
{
    if ($explicitRuteId !== null && $explicitRuteId > 0) {
        $st = $db->prepare('SELECT * FROM rutes WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->bind_param('ii', $explicitRuteId, $cid);
        $st->execute();
        $r = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        if ($r) {
            return $r;
        }
    }
    if ($ruteCode !== null && trim($ruteCode) !== '') {
        $rc = strtoupper(trim($ruteCode));
        $st = $db->prepare('SELECT * FROM rutes WHERE company_id = ? AND code = ? AND is_active = 1 LIMIT 1');
        $st->bind_param('is', $cid, $rc);
        $st->execute();
        $r = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        if ($r) {
            return $r;
        }
    }
    if ($pickupCityId > 0 && $deliveryCityId > 0) {
        $st = $db->prepare('SELECT * FROM rutes WHERE company_id = ? AND pickup_point_city_id = ? AND delivery_point_city_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
        $st->bind_param('iii', $cid, $pickupCityId, $deliveryCityId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        if ($r) {
            return $r;
        }
        // Fallback arah destinasi kota (mis. area Jabodetabek menuju Bandung)
        $st2 = $db->prepare('SELECT * FROM rutes WHERE company_id = ? AND delivery_point_city_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
        $st2->bind_param('ii', $cid, $deliveryCityId);
        $st2->execute();
        $r2 = $st2->get_result()->fetch_assoc() ?: null;
        $st2->close();
        if ($r2) {
            return $r2;
        }
    }
    return null;
}

/**
 * Parsing teks form pemesanan WhatsApp (rapi maupun acak) + fallback AI JSON jika belum lengkap.
 *
 * @return array<string,mixed>
 */
function bot_staff_parse_form_text(mysqli $db, int $cid, string $rawText, string $bookingType = 'travel', bool $useAi = true, ?string $refDate = null): array
{
    $cleaned = bot_staff_strip_boilerplate($rawText);
    $lines   = explode("\n", $cleaned);

    $parsed = [
        'passenger_name'        => '',
        'passenger_phone'       => '',
        'pickup_address'        => '',
        'delivery_address'      => '',
        'seats'                 => 1,
        'date'                  => '',
        'time'                  => '',
        'baggage'               => '',
        'total_price'           => 0.0,
        'price_is_per_seat'     => false,
        'service_category_hint' => 'REG',
        'note'                  => '',
        'vehicle_query'         => '',
        'driver_query'          => '',
        'payment_hint'          => '',
    ];

    // 1. Parser berbasis label baris (`Label : Nilai`) — tahan emoji/simbol di depan label
    $lastKey = null;
    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }
        // Bersihkan karakter non-ASCII / simbol pembuka seperti emoji (👱♂️, 📱, 🏠, 🚘, •, *)
        $stripped = trim((string) preg_replace('/^[\p{So}\p{Cs}\p{Cf}\p{Sk}\p{M}\x{200D}\x{FE0E}\x{FE0F}\*\-\•\s]+/u', '', $line));
        if ($stripped === '') {
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9\s\/&().\-]{2,45}?)\s*:\s*(.*)$/u', $stripped, $kv)) {
            $lbl = mb_strtolower(trim($kv[1]), 'UTF-8');
            $lbl = (string) preg_replace('/\s+/', ' ', $lbl);
            $val = trim($kv[2]);
            $lastKey = null;

            if (preg_match('/^(?:nama(?:\s+lengkap|\s+penumpang|\s+pelanggan|\s+pemesan)?|atas\s+nama|a\.?\s*n\.?|pemesan|penumpang)$/i', $lbl)) {
                $parsed['passenger_name'] = $val;
                $lastKey = 'passenger_name';
            } elseif (preg_match('/^(?:no\.?\s*(?:hp|wa|whatsapp|telp|telepon|kontak)|hp|wa|whatsapp|telp|telepon|kontak)$/i', $lbl)) {
                $parsed['passenger_phone'] = bot_normalize_phone($val) ?? $val;
            } elseif (preg_match('/^(?:alamat+\s+(?:penjemputan|jemput)(?:\s+bersama)?|(?:titik\s+)?penjemputan|jemput|asal|dari)$/i', $lbl)) {
                $parsed['pickup_address'] = $val;
                $lastKey = 'pickup_address';
            } elseif (preg_match('/^(?:alamat+\s+(?:tujuan|antar|pengantaran)(?:\s+bersama)?|(?:titik\s+)?(?:tujuan|pengantaran|antar)|ke)$/i', $lbl)) {
                $parsed['delivery_address'] = $val;
                $lastKey = 'delivery_address';
            } elseif (preg_match('/^(?:jumlah\s+(?:tiket\s*[\/&]\s*kursi|tiket|kursi|penumpang|seat|orang|paket)|tiket|kursi|seat|pax|jumlah|yg\s*\/\s*kursi)$/i', $lbl)) {
                if (preg_match('/\bpaket\b/iu', $val)) {
                    $parsed['service_category_hint'] = 'PKT';
                }
                if (preg_match('/\d+/', $val, $sm)) {
                    $parsed['seats'] = max(1, min(20, (int) $sm[0]));
                }
            } elseif (preg_match('/^(?:hari\s*[&\/\-]?\s*tanggal|tanggal(?:\s+berangkat)?|tgl(?:\s+berangkat)?|hari)$/i', $lbl)) {
                $parsed['date'] = bot_staff_parse_indonesian_date($val, $refDate) ?? '';
            } elseif (preg_match('/^(?:jam(?:\s+berangkat|\s+keberangkatan)?|pukul|pkl|waktu)$/i', $lbl)) {
                $parsed['time'] = bot_staff_parse_time($val) ?? '';
            } elseif (preg_match('/^(?:barang\s+bawaan|bagasi|bawaan|barang)$/i', $lbl)) {
                $parsed['baggage'] = $val;
                $lastKey = 'baggage';
            } elseif (preg_match('/^(?:tarif|harga(?:\s+tiket|\s+sewa)?|ongkos|biaya|total(?:\s+harga|\s+tarif|\s+biaya)?)$/i', $lbl)) {
                $amt = bot_staff_parse_rupiah($val, true);
                if ($amt > 0) {
                    $parsed['total_price'] = $amt;
                }
                if (preg_match('/(?:\/|per)\s*(?:kursi|tiket|org|orang|pax)/i', $val)) {
                    $parsed['price_is_per_seat'] = true;
                }
            } elseif (preg_match('/^(?:catatan|ket|keterangan|note)$/i', $lbl)) {
                $parsed['note'] = $val;
                $lastKey = 'note';
            } elseif (preg_match('/^(?:mobil|unit|armada|kendaraan|nopol|plat)$/i', $lbl)) {
                $parsed['vehicle_query'] = $val;
            } elseif (preg_match('/^(?:sopir|supir|driver|kru)$/i', $lbl)) {
                $parsed['driver_query'] = $val;
            } elseif (preg_match('/^(?:pembayaran|bayar|status\s+bayar|dp)$/i', $lbl)) {
                $parsed['payment_hint'] = $val;
            }
        } elseif (
            $lastKey !== null
            && in_array($lastKey, ['pickup_address', 'delivery_address', 'note', 'baggage'], true)
            && !preg_match('/^(?:no\.?\s*(?:hp|wa|telp)|hp|wa|whatsapp|telp|nama|dari|asal|jemput|ke|tujuan|antar|besok|lusa|hari|tgl|tanggal|jam|pukul|pkl|tarif|harga|ongkos|biaya|total|jumlah|kursi|tiket|seat|barang|bagasi|catatan|note|ket|mobil|unit|sopir|driver|bayar|dp)\b/iu', $stripped)
        ) {
            $parsed[$lastKey] = trim($parsed[$lastKey] . ' ' . $stripped);
        } else {
            $lastKey = null;
        }
    }

    // 2. Fallback untuk kalimat bebas tanpa label `:` (mis. "Bryan 085889611974 besok jam 7 malam 2 kursi dari Gambir ke Bandung 250rb")
    if ($parsed['passenger_phone'] === '' && preg_match('/(?:(?:\+62|62|0)8[0-9\s.\-]{7,16})/', $cleaned, $pm)) {
        $parsed['passenger_phone'] = bot_normalize_phone($pm[0]) ?? '';
    }
    if ($parsed['date'] === '') {
        $parsed['date'] = bot_staff_parse_indonesian_date($cleaned, $refDate) ?? '';
    }
    if ($parsed['time'] === '') {
        if (preg_match('/\b(?:jam|pukul|pkl)\s*[^\n,;]{1,18}/iu', $cleaned, $tm)) {
            $parsed['time'] = bot_staff_parse_time($tm[0]) ?? '';
        }
    }
    if ($parsed['seats'] <= 1 && preg_match('/\b(\d{1,2})\s*(?:kursi|tiket|seat|org|orang|pax)\b/iu', $cleaned, $sm)) {
        $parsed['seats'] = max(1, min(20, (int) $sm[1]));
    }
    if ($parsed['total_price'] <= 0) {
        if (preg_match('/\b(?:tarif|harga|ongkos|rp\.?)\s*:?\s*(?:rp\.?\s*)?([0-9]+(?:[.,][0-9]+)*\s*(?:jt|juta|rb|ribu|k)?)/iu', $cleaned, $prm)) {
            $parsed['total_price'] = bot_staff_parse_rupiah($prm[1]);
        } elseif (preg_match('/\b([0-9]+(?:[.,][0-9]+)*\s*(?:jt|juta|rb|ribu|k))\b/iu', $cleaned, $prm2)) {
            $parsed['total_price'] = bot_staff_parse_rupiah($prm2[1]);
        }
    }
    if ($parsed['pickup_address'] === '' && preg_match('/(?:^|\b)(?:jemput(?:\s+di)?|dari|asal)\s+([^\n]{3,160}?)(?=\s+\b(?:antar(?:\s+ke)?|ke|tujuan|jam|pukul|pkl|besok|lusa|tgl|tanggal|tarif|harga|ongkos|rp\.?|\d{1,4}\s*(?:rb|ribu|jt|juta|k))\b|\r?\n|$)/imu', $cleaned, $pka)) {
        $parsed['pickup_address'] = trim($pka[1]);
    }
    if ($parsed['delivery_address'] === '' && preg_match('/(?:^|\b)(?:antar(?:\s+ke)?|tujuan|ke)\s+([^\n]{3,160}?)(?=\s+\b(?:jam|pukul|pkl|besok|lusa|hari|tgl|tanggal|tarif|harga|ongkos|rp\.?|\d{1,4}\s*(?:rb|ribu|jt|juta|k)|\d+\s*(?:kursi|tiket|seat|pax))\b|\r?\n|$)/imu', $cleaned, $dla)) {
        $parsed['delivery_address'] = trim($dla[1]);
    }

    // Kalikan harga per kursi bila eksplisit ditulis "250rb/kursi"
    if ($parsed['price_is_per_seat'] && $parsed['total_price'] > 0 && $parsed['seats'] > 1) {
        $parsed['total_price'] = round($parsed['total_price'] * $parsed['seats'], 2);
    }

    if ($parsed['service_category_hint'] !== 'PKT' && preg_match('/\b(?:carter|charter)\b/iu', $cleaned)) {
        $parsed['service_category_hint'] = 'CMB';
    }

    // 3. Fallback Hybrid LLM bila ada field krusial yang masih kosong & provider AI aktif
    $needsAi = (
        $parsed['passenger_name'] === ''
        || $parsed['date'] === ''
        || $parsed['pickup_address'] === ''
        || $parsed['delivery_address'] === ''
        || $parsed['total_price'] <= 0
    );
    if ($useAi && $needsAi && ai_has_configured_provider('json')) {
        $today = date('Y-m-d');
        $sys = "Kamu ekstraktor form pemesanan {$bookingType} WhatsApp Admin. Hari ini {$today}, besok "
            . date('Y-m-d', strtotime('+1 day')) . ", lusa " . date('Y-m-d', strtotime('+2 days')) . ". "
            . 'Balas HANYA JSON valid: {"passenger_name":string,"passenger_phone":string,"pickup_address":string,'
            . '"delivery_address":string,"seats":int,"date":"YYYY-MM-DD","time":"HH:MM","baggage":string,"total_price":number,"note":string}. '
            . 'Abaikan NOTE template umum. Isi string kosong atau 0 bila field tidak tercantum.';
        $aiRes = ai_chat_json([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => mb_substr($cleaned, 0, 2000)],
        ], ['stream' => false], 10, 4);

        if (!empty($aiRes['ok']) && !empty($aiRes['data']) && is_array($aiRes['data'])) {
            $aiData = $aiRes['data'];
            if ($parsed['passenger_name'] === '' && !empty($aiData['passenger_name'])) {
                $parsed['passenger_name'] = trim((string) $aiData['passenger_name']);
            }
            if ($parsed['passenger_phone'] === '' && !empty($aiData['passenger_phone'])) {
                $parsed['passenger_phone'] = bot_normalize_phone((string) $aiData['passenger_phone']) ?? '';
            }
            if ($parsed['pickup_address'] === '' && !empty($aiData['pickup_address'])) {
                $parsed['pickup_address'] = trim((string) $aiData['pickup_address']);
            }
            if ($parsed['delivery_address'] === '' && !empty($aiData['delivery_address'])) {
                $parsed['delivery_address'] = trim((string) $aiData['delivery_address']);
            }
            if ($parsed['seats'] <= 1 && !empty($aiData['seats']) && is_numeric($aiData['seats'])) {
                $parsed['seats'] = max(1, min(20, (int) $aiData['seats']));
            }
            if ($parsed['date'] === '' && !empty($aiData['date'])) {
                $parsed['date'] = bot_staff_parse_indonesian_date((string) $aiData['date']) ?? '';
            }
            if ($parsed['time'] === '' && !empty($aiData['time'])) {
                $parsed['time'] = bot_staff_parse_time((string) $aiData['time']) ?? '';
            }
            if ($parsed['baggage'] === '' && !empty($aiData['baggage'])) {
                $parsed['baggage'] = trim((string) $aiData['baggage']);
            }
            if ($parsed['total_price'] <= 0 && !empty($aiData['total_price'])) {
                $parsed['total_price'] = bot_staff_parse_rupiah($aiData['total_price']);
            }
            if ($parsed['note'] === '' && !empty($aiData['note'])) {
                $parsed['note'] = trim((string) $aiData['note']);
            }
        }
    }

    // 4. Resolusi lokasi & rute perusahaan
    $pickupGeo   = bot_staff_resolve_address_geo($db, $parsed['pickup_address'], $cid);
    $deliveryGeo = bot_staff_resolve_address_geo($db, $parsed['delivery_address'], $cid);
    $matchedRute = bot_staff_match_route($db, $cid, $pickupGeo['city_id'], $deliveryGeo['city_id']);

    $parsed['pickup_geo']   = $pickupGeo;
    $parsed['delivery_geo'] = $deliveryGeo;
    $parsed['rute']         = $matchedRute ? ['id' => (int) $matchedRute['id'], 'code' => (string) $matchedRute['code'], 'name' => (string) $matchedRute['name']] : null;
    if ($parsed['total_price'] <= 0 && $matchedRute && $bookingType === 'travel') {
        $catId = !empty($matchedRute['category_vehicle_id']) ? (int) $matchedRute['category_vehicle_id'] : null;
        $quote = rute_calculate_travel_quote(
            $cid,
            $matchedRute,
            $catId,
            $parsed['seats'],
            $pickupGeo['district_id'],
            $deliveryGeo['district_id']
        );
        $parsed['total_price'] = (float) ($quote['total'] ?? 0);
    }

    return $parsed;
}

/**
 * Cari atau daftarkan profil `passengers` berdasarkan nomor HP kanonis (utamakan) atau nama.
 * Tidak pernah memakai nomor WA admin pengirim secara diam-diam.
 */
function bot_staff_ensure_passenger_flexible(mysqli $db, int $cid, ?string $phone, string $name, string $auditUser): array
{
    $cleanName  = mb_substr(trim($name), 0, 255);
    $cleanPhone = ($phone !== null && trim($phone) !== '') ? bot_normalize_phone($phone) : null;

    if ($cleanPhone !== null) {
        $pax = bot_ensure_passenger($db, $cid, $cleanPhone, $cleanName);
        if ($cleanName !== '' && strcasecmp($cleanName, (string) $pax['name']) !== 0 && !str_starts_with((string) $pax['name'], 'Pelanggan WA')) {
            // Biarkan nama master atau perbarui bila nama form lebih jelas dan tidak kosong?
            // Tetap kembalikan nama di form untuk tampilan jika berbeda hanya ejaan kecil, tanpa merusak master lama kecuali kosong.
        }
        return $pax;
    }

    if ($cleanName === '') {
        throw new InvalidArgumentException('Nama atau No. HP penumpang wajib diisi.');
    }

    $st = $db->prepare('SELECT id, code, name, phone_number, email FROM passengers WHERE company_id = ? AND LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 1');
    $st->bind_param('is', $cid, $cleanName);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if ($found) {
        return $found;
    }

    for ($i = 0; $i < 10; $i++) {
        $code = 'WA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        $chk  = $db->prepare('SELECT 1 FROM passengers WHERE company_id = ? AND code = ? LIMIT 1');
        $chk->bind_param('is', $cid, $code);
        $chk->execute();
        $dup = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$dup) {
            $ins = $db->prepare(
                "INSERT INTO passengers (company_id, code, name, phone_number, note, created_user)
                 VALUES (?, ?, ?, NULL, 'Auto-registered via Grup WA Admin Hermes', ?)"
            );
            $ins->bind_param('isss', $cid, $code, $cleanName, $auditUser);
            $ins->execute();
            $id = (int) $ins->insert_id;
            $ins->close();
            return ['id' => $id, 'code' => $code, 'name' => $cleanName, 'phone_number' => null, 'email' => null];
        }
    }
    throw new RuntimeException('Gagal membuat kode penumpang baru.');
}

/** Cek potensi duplikasi booking aktif di tanggal & jam yang sama untuk penumpang tersebut. */
function bot_staff_check_duplicate(mysqli $db, int $cid, string $type, int $passengerId, string $date, string $time): ?array
{
    $table   = booking_table($type);
    $hhmm    = substr($time, 0, 5);
    $st = $db->prepare(
        "SELECT b.id, b.document_number, b.date, b.time, b.total_price, b.pickup_address, b.delivery_address,
                COALESCE((SELECT h.to_status FROM booking_status_history h
                           WHERE h.booking_type = ? AND h.booking_id = b.id
                           ORDER BY h.id DESC LIMIT 1), 'draft') AS booking_status
           FROM {$table} b
          WHERE b.company_id = ?
            AND b.passenger_id = ?
            AND b.date = ?
            AND LEFT(b.time, 5) = ?
          ORDER BY b.id DESC LIMIT 3"
    );
    $st->bind_param('siiss', $type, $cid, $passengerId, $date, $hhmm);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as $r) {
        if (($r['booking_status'] ?? 'draft') !== 'cancelled') {
            return $r;
        }
    }
    return null;
}

/** Cari kendaraan aktif milik perusahaan berdasarkan ID atau nopol/model/kategori. */
function bot_staff_resolve_vehicle(mysqli $db, int $cid, mixed $idOrQuery): ?array
{
    if ($idOrQuery === null || trim((string) $idOrQuery) === '') {
        return null;
    }
    if (is_numeric($idOrQuery) && (int) $idOrQuery > 0) {
        $vid = (int) $idOrQuery;
        $st  = $db->prepare(
            "SELECT v.id, v.vehicle_number AS code, v.vehicle_number AS plate_number,
                    COALESCE(mu.name, v.vehicle_category, '') AS name
               FROM vehicles v
               LEFT JOIN model_units mu ON mu.id = v.model_unit_id
              WHERE v.id = ? AND v.company_id = ? AND v.is_active = 1 LIMIT 1"
        );
        $st->bind_param('ii', $vid, $cid);
        $st->execute();
        $v = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        return $v;
    }
    $q       = trim((string) $idOrQuery);
    $compact = strtoupper(str_replace(' ', '', $q));
    $like    = '%' . addcslashes($q, '%_\\') . '%';
    $st      = $db->prepare(
        "SELECT v.id, v.vehicle_number AS code, v.vehicle_number AS plate_number,
                COALESCE(mu.name, v.vehicle_category, '') AS name
           FROM vehicles v
           LEFT JOIN model_units mu ON mu.id = v.model_unit_id
          WHERE v.company_id = ? AND v.is_active = 1
            AND (REPLACE(UPPER(v.vehicle_number), ' ', '') = ? OR v.vehicle_number LIKE ? OR COALESCE(mu.name, '') LIKE ?)
          ORDER BY v.id ASC LIMIT 1"
    );
    $st->bind_param('isss', $cid, $compact, $like, $like);
    $st->execute();
    $v = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $v;
}

/** Cari karyawan/sopir aktif milik perusahaan berdasarkan ID atau kode/nama. */
function bot_staff_resolve_driver(mysqli $db, int $cid, mixed $idOrQuery): ?array
{
    if ($idOrQuery === null || trim((string) $idOrQuery) === '') {
        return null;
    }
    if (is_numeric($idOrQuery) && (int) $idOrQuery > 0) {
        $eid = (int) $idOrQuery;
        $st  = $db->prepare("SELECT id, code, name, phone_number, job_position FROM employees WHERE id = ? AND company_id = ? AND status = 'aktif' LIMIT 1");
        $st->bind_param('ii', $eid, $cid);
        $st->execute();
        $e = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        return $e;
    }
    $q    = trim((string) $idOrQuery);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $st   = $db->prepare(
        "SELECT id, code, name, phone_number, job_position FROM employees
          WHERE company_id = ? AND status = 'aktif'
            AND (UPPER(code) = UPPER(?) OR name LIKE ?)
          ORDER BY (LOWER(COALESCE(job_position,'')) IN ('driver','sopir')) DESC, id ASC LIMIT 1"
    );
    $st->bind_param('iss', $cid, $q, $like);
    $st->execute();
    $e = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    return $e;
}

/**
 * Ambil 1 booking terperinci strictly scoped ke `$cid` berdasarkan document_number (#...), invoice_no, atau ID.
 * Aman dipakai tanpa `$_SESSION`.
 */
function bot_staff_find_booking(mysqli $db, int $cid, string $ref, ?string $hintType = null): ?array
{
    $clean = ltrim(trim($ref), '#');
    if ($clean === '') {
        return null;
    }

    $bType = null;
    $row   = null;
    $types = ($hintType && in_array($hintType, ['travel', 'rental'], true))
        ? [$hintType]
        : ['travel', 'rental'];

    // 1. Cek berdasar nomor dokumen (atau ID numerik bila hanya digit < 10)
    foreach ($types as $t) {
        $tbl = booking_table($t);
        if (ctype_digit($clean) && strlen($clean) < 10) {
            $idInt = (int) $clean;
            $st    = $db->prepare("SELECT * FROM {$tbl} WHERE company_id = ? AND (id = ? OR document_number = ?) LIMIT 1");
            $st->bind_param('iis', $cid, $idInt, $clean);
        } else {
            $st = $db->prepare("SELECT * FROM {$tbl} WHERE company_id = ? AND document_number = ? LIMIT 1");
            $st->bind_param('is', $cid, $clean);
        }
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) {
            $bType = $t;
            $row   = $r;
            break;
        }
    }

    // 2. Fallback cek berdasar nomor invoice (`invoices.invoice_no`)
    if (!$row) {
        $stI = $db->prepare('SELECT booking_type, booking_id FROM invoices WHERE company_id = ? AND invoice_no = ? LIMIT 1');
        $stI->bind_param('is', $cid, $clean);
        $stI->execute();
        $invRef = $stI->get_result()->fetch_assoc();
        $stI->close();
        if ($invRef && in_array($invRef['booking_type'], $types, true)) {
            $bType = (string) $invRef['booking_type'];
            $tbl   = booking_table($bType);
            $bid   = (int) $invRef['booking_id'];
            $st    = $db->prepare("SELECT * FROM {$tbl} WHERE company_id = ? AND id = ? LIMIT 1");
            $st->bind_param('ii', $cid, $bid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
        }
    }

    if (!$row || !$bType) {
        return null;
    }

    $bid = (int) $row['id'];
    $row['booking_type']   = $bType;
    $row['booking_status'] = booking_current_status($bType, $bid);

    // Invoice
    $stInv = $db->prepare('SELECT id FROM invoices WHERE company_id = ? AND booking_type = ? AND booking_id = ? LIMIT 1');
    $stInv->bind_param('isi', $cid, $bType, $bid);
    $stInv->execute();
    $invId = (int) ($stInv->get_result()->fetch_column() ?: 0);
    $stInv->close();
    $row['invoice'] = $invId > 0 ? invoice_find($invId) : null;

    // Passengers
    $paxList = booking_passengers_list($bType, $bid, (int) ($row['passenger_id'] ?? 0));
    $row['passengers'] = $paxList;
    $row['seats']      = bot_staff_extract_seats($row, count($paxList));

    // Geo names
    $geoSt = $db->prepare(
        "SELECT COALESCE(pc.name, '') AS pickup_city_name,
                COALESCE(pd.name, '') AS pickup_district_name,
                COALESCE(dc.name, '') AS delivery_city_name,
                COALESCE(dd.name, '') AS delivery_district_name
           FROM (SELECT 1) x
           LEFT JOIN cities pc ON pc.id = ?
           LEFT JOIN districts pd ON pd.id = ?
           LEFT JOIN cities dc ON dc.id = ?
           LEFT JOIN districts dd ON dd.id = ?"
    );
    $pCid = (int) ($row['pickup_point_city_id'] ?? 0);
    $pDid = (int) ($row['pickup_point_district_id'] ?? 0);
    $dCid = (int) ($row['delivery_point_city_id'] ?? 0);
    $dDid = (int) ($row['delivery_point_district_id'] ?? 0);
    $geoSt->bind_param('iiii', $pCid, $pDid, $dCid, $dDid);
    $geoSt->execute();
    $geoNames = $geoSt->get_result()->fetch_assoc() ?: [];
    $geoSt->close();
    $row = array_merge($row, $geoNames);

    // Kendaraan
    $row['vehicle'] = null;
    if (!empty($row['vehicle_id'])) {
        $vid  = (int) $row['vehicle_id'];
        $stV  = $db->prepare(
            "SELECT v.id, v.vehicle_number AS code, v.vehicle_number AS plate_number,
                    COALESCE(mu.name, v.vehicle_category, '') AS name
               FROM vehicles v
               LEFT JOIN model_units mu ON mu.id = v.model_unit_id
              WHERE v.id = ? AND v.company_id = ? LIMIT 1"
        );
        $stV->bind_param('ii', $vid, $cid);
        $stV->execute();
        $row['vehicle'] = $stV->get_result()->fetch_assoc() ?: null;
        $stV->close();
    }

    // Driver aktif
    $stDrv = $db->prepare(
        "SELECT e.id, e.code, e.name, e.phone_number
           FROM trip_assignments ta
           JOIN employees e ON e.id = ta.employee_id
          WHERE ta.booking_type = ? AND ta.booking_id = ? AND ta.company_id = ? AND ta.ended_at IS NULL
          ORDER BY ta.id DESC LIMIT 1"
    );
    $stDrv->bind_param('sii', $bType, $bid, $cid);
    $stDrv->execute();
    $row['driver'] = $stDrv->get_result()->fetch_assoc() ?: null;
    $stDrv->close();

    return $row;
}

/** Hitung jumlah kursi efektif dari baris penumpang atau penanda `[N kursi]` pada catatan. */
function bot_staff_extract_seats(array $bk, int $paxCount): int
{
    $seats = max(1, $paxCount);
    if ($seats <= 1 && preg_match('/\[(\d{1,2})\s*kursi\]|\b(\d{1,2})\s*kursi\b/i', (string) ($bk['note'] ?? ''), $m)) {
        $n = (int) ($m[1] ?: ($m[2] ?? 1));
        if ($n > 1 && $n <= 20) {
            $seats = $n;
        }
    }
    return $seats;
}

/** Label status operasional berbahasa Indonesia. */
function bot_staff_status_label(string $status): string
{
    return BOOKING_STATUSES[$status] ?? ($status === 'rescheduled' ? 'Dijadwalkan Ulang' : ucfirst($status));
}

/** Label status pembayaran berbahasa Indonesia. */
function bot_staff_pay_status_label(string $payStatus): string
{
    return match ($payStatus) {
        'paid'      => 'Lunas',
        'partial'   => 'Sebagian/DP',
        'cancelled' => 'Dibatalkan',
        default     => 'Belum Bayar',
    };
}

/** Bangun ringkasan pesan WhatsApp siap kirim untuk satu pesanan (konfirmasi tambah/edit). */
function bot_staff_format_booking_wa(string $headline, array $bk, array $company, array $changes = []): string
{
    $docNo   = (string) ($bk['document_number'] ?? '-');
    $bType   = (string) ($bk['booking_type'] ?? 'travel');
    $primary = $bk['passengers'][0] ?? ['name' => '-', 'phone_number' => ''];
    $paxName = (string) ($primary['name'] ?? '-');
    $paxHp   = trim((string) ($primary['phone_number'] ?? ''));

    $routeFrom = trim((string) ($bk['pickup_district_name'] ?? '') . (!empty($bk['pickup_city_name']) ? ', ' . $bk['pickup_city_name'] : ''));
    $routeTo   = trim((string) ($bk['delivery_district_name'] ?? '') . (!empty($bk['delivery_city_name']) ? ', ' . $bk['delivery_city_name'] : ''));
    $totalAmt  = (float) ($bk['invoice']['total'] ?? ($bk['total_price'] ?? 0));
    $paidAmt   = (float) ($bk['invoice']['paid_total'] ?? ($bk['total_payment'] ?? 0));
    $paySt     = (string) ($bk['invoice']['pay_status'] ?? ($paidAmt >= $totalAmt && $totalAmt > 0 ? 'paid' : ($paidAmt > 0 ? 'partial' : 'unpaid')));
    $bkSt      = (string) ($bk['booking_status'] ?? 'draft');

    $lines = [];
    $lines[] = '*' . $headline . '*';
    $lines[] = 'Resi: `#' . $docNo . '` (' . ($bType === 'rental' ? 'Rental' : 'Travel') . ')';
    $lines[] = 'Nama: *' . $paxName . '*' . ($paxHp !== '' ? ' (' . $paxHp . ')' : '');
    $lines[] = 'Jadwal: ' . (string) ($bk['date'] ?? '-') . ' pukul ' . substr((string) ($bk['time'] ?? '00:00'), 0, 5) . ' | ' . (int) ($bk['seats'] ?? 1) . ' kursi';
    if ($routeFrom !== '' || $routeTo !== '') {
        $lines[] = 'Rute: ' . ($routeFrom ?: '-') . ' → ' . ($routeTo ?: '-');
    }
    if (!empty($bk['pickup_address'])) {
        $lines[] = 'Jemput: ' . (string) $bk['pickup_address'];
    }
    if (!empty($bk['delivery_address'])) {
        $lines[] = 'Tujuan: ' . (string) $bk['delivery_address'];
    }
    $payLine = 'Tarif: *' . rupiah($totalAmt) . '* | Bayar: ' . bot_staff_pay_status_label($paySt);
    if ($paySt === 'partial') {
        $payLine .= ' (' . rupiah($paidAmt) . ', sisa ' . rupiah(max(0.0, $totalAmt - $paidAmt)) . ')';
    }
    $lines[] = $payLine . ' | Status: ' . bot_staff_status_label($bkSt);

    if (!empty($bk['vehicle']) || !empty($bk['driver'])) {
        $unitTxt   = !empty($bk['vehicle']) ? trim(($bk['vehicle']['plate_number'] ?: $bk['vehicle']['code']) . ' ' . ($bk['vehicle']['name'] ?? '')) : '-';
        $driverTxt = !empty($bk['driver']) ? (string) $bk['driver']['name'] : '-';
        $lines[]   = 'Armada/Sopir: ' . $unitTxt . ' / ' . $driverTxt;
    }
    if (!empty($bk['note'])) {
        $lines[] = 'Catatan: ' . (string) $bk['note'];
    }
    if ($changes) {
        $lines[] = 'Perubahan: ' . implode(', ', $changes);
    }
    $base = bot_base_url();
    $lines[] = 'E-Tiket: ' . $base . '/tiket.php?no=' . rawurlencode($docNo) . '&company=' . rawurlencode((string) ($company['code'] ?? ''));

    return implode("\n", $lines);
}

/** Format manifest rekap siap copy-paste di Grup WhatsApp Admin atau Grup Sopir. */
function bot_staff_format_rekap_wa(array $items, array $meta, array $company, string $format = 'full'): string
{
    $dateLabel = $meta['date_from'] === $meta['date_to']
        ? $meta['date_from']
        : ($meta['date_from'] . ' s/d ' . $meta['date_to']);
    $title = ($format === 'driver')
        ? "*MANIFEST KEBERANGKATAN — {$company['name']}*"
        : "*REKAP PESANAN — {$company['name']}*";

    $lines = [$title, "Tanggal: *{$dateLabel}*" . (!empty($meta['time']) ? " · Jam {$meta['time']}" : '')];
    if (!$items) {
        $lines[] = "\n_Belum ada data pesanan yang sesuai filter._";
        return implode("\n", $lines);
    }

    // Kelompokkan per tanggal + jam berangkat
    $groups = [];
    foreach ($items as $it) {
        $gKey = ($it['date'] ?? '-') . ' ' . ($it['time'] ?? '00:00');
        $groups[$gKey][] = $it;
    }
    ksort($groups);

    $totalSeats = 0;
    $totalOmzet = 0.0;
    $totalPaid  = 0.0;

    foreach ($groups as $slot => $slotItems) {
        $slotSeats = 0;
        foreach ($slotItems as $si) {
            $slotSeats += (int) ($si['seats'] ?? 1);
        }
        $lines[] = "\n*Kloter {$slot}* ({$slotSeats} kursi)";
        foreach ($slotItems as $idx => $it) {
            $no     = $idx + 1;
            $seats  = (int) ($it['seats'] ?? 1);
            $totalSeats += $seats;
            $route  = trim(($it['pickup_city_name'] ?: '-') . ' → ' . ($it['delivery_city_name'] ?: '-'));
            $hp     = !empty($it['passenger_phone']) ? ' (' . $it['passenger_phone'] . ')' : '';
            $unit   = !empty($it['vehicle_plate']) ? $it['vehicle_plate'] : '';
            $driver = !empty($it['driver_name']) ? $it['driver_name'] : '';

            $lines[] = "{$no}. *{$it['passenger_name']}*{$hp} · {$seats} kursi · `#{$it['document_number']}`";
            $lines[] = "   Rute: {$route}";
            if (!empty($it['pickup_address'])) {
                $lines[] = "   Jemput: " . $it['pickup_address'];
            }
            if (!empty($it['delivery_address'])) {
                $lines[] = "   Tujuan: " . $it['delivery_address'];
            }
            if ($unit !== '' || $driver !== '') {
                $lines[] = "   Armada/Sopir: " . trim(($unit ?: '-') . ' / ' . ($driver ?: '-'));
            }
            if (!empty($it['note'])) {
                $lines[] = "   Catatan: " . $it['note'];
            }

            if ($format !== 'driver') {
                $tp = (float) ($it['total_price']['raw'] ?? 0);
                $pd = (float) ($it['paid_amount']['raw'] ?? 0);
                if (($it['booking_status'] ?? '') !== 'cancelled') {
                    $totalOmzet += $tp;
                    $totalPaid  += $pd;
                }
                $payLabel = bot_staff_pay_status_label((string) ($it['payment_status'] ?? 'unpaid'));
                $bkLabel  = bot_staff_status_label((string) ($it['booking_status'] ?? 'draft'));
                $lines[]  = "   {$it['total_price']['formatted']} · {$payLabel} · {$bkLabel}";
            }
        }
    }

    $lines[] = "\n*Ringkasan:* " . count($items) . " pesanan · {$totalSeats} kursi";
    if ($format !== 'driver') {
        $rem = max(0.0, $totalOmzet - $totalPaid);
        $lines[] = 'Omzet: *' . rupiah($totalOmzet) . '* · Masuk: ' . rupiah($totalPaid) . ' · Sisa: ' . rupiah($rem);
    }
    return implode("\n", $lines);
}
