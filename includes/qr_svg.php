<?php
/**
 * Generator QR Code SVG Lokal (ISO/IEC 18004, Byte Mode, ECC Level L, Versi 1–20).
 * Menghasilkan SVG/Data URI instan (< 2 ms) tanpa ketergantungan library GD atau server eksternal
 * seperti api.qrserver.com / quickchart.io agar QRIS Dinamis langsung tampil di modal.
 */

/**
 * Spesifikasi blok ECC Level L per versi (1–20):
 * [ec_per_block, [[count_g1, data_cw_g1], [count_g2, data_cw_g2]?]]
 */
function qr_ecc_l_spec(int $version): array
{
    static $specs = [
        1  => [7,  [[1, 19]]],
        2  => [10, [[1, 34]]],
        3  => [15, [[1, 55]]],
        4  => [20, [[1, 80]]],
        5  => [26, [[1, 108]]],
        6  => [18, [[2, 68]]],
        7  => [20, [[2, 78]]],
        8  => [24, [[2, 97]]],
        9  => [30, [[2, 116]]],
        10 => [18, [[2, 68], [2, 69]]],
        11 => [20, [[4, 81]]],
        12 => [24, [[2, 92], [2, 93]]],
        13 => [26, [[4, 107]]],
        14 => [30, [[3, 115], [1, 116]]],
        15 => [22, [[5, 87], [1, 88]]],
        16 => [24, [[5, 98], [1, 99]]],
        17 => [28, [[1, 107], [5, 108]]],
        18 => [30, [[5, 120], [1, 121]]],
        19 => [28, [[3, 113], [4, 114]]],
        20 => [28, [[3, 107], [5, 108]]],
    ];
    if (!isset($specs[$version])) {
        throw new InvalidArgumentException("Versi QR {$version} tidak didukung.");
    }
    return $specs[$version];
}

/** Koordinat pusat Alignment Pattern per versi (ISO/IEC 18004 Tabel E.1). */
function qr_alignment_positions(int $version): array
{
    static $pos = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];
    return $pos[$version] ?? [];
}

/** Tabel eksponen & logaritma GF(256) dengan polinomial primitif 0x11d. */
function qr_gf_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $v = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $v;
        $exp[$i + 255] = $v;
        $log[$v] = $i;
        $v <<= 1;
        if ($v & 0x100) {
            $v ^= 0x11d;
        }
    }
    $exp[510] = $exp[0];
    $exp[511] = $exp[1];
    $tables = [$exp, $log];
    return $tables;
}

/** Polinomial generator Reed-Solomon derajat $degree di GF(256). */
function qr_rs_generator(int $degree): array
{
    static $cache = [];
    if (isset($cache[$degree])) {
        return $cache[$degree];
    }
    [$exp, $log] = qr_gf_tables();
    $poly = [1];
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        $rootExp = $i;
        foreach ($poly as $idx => $coef) {
            $next[$idx] ^= $coef;
            if ($coef !== 0) {
                $next[$idx + 1] ^= $exp[$log[$coef] + $rootExp];
            }
        }
        $poly = $next;
    }
    $cache[$degree] = $poly;
    return $poly;
}

/** Hitung $ecLen byte ECC Reed-Solomon untuk $data codewords. */
function qr_rs_remainder(array $data, int $ecLen): array
{
    [$exp, $log] = qr_gf_tables();
    $gen = qr_rs_generator($ecLen);
    $buf = array_merge($data, array_fill(0, $ecLen, 0));
    $n = count($data);
    for ($i = 0; $i < $n; $i++) {
        $factor = $buf[$i];
        if ($factor === 0) {
            continue;
        }
        $logFactor = $log[$factor];
        for ($j = 1; $j <= $ecLen; $j++) {
            if ($gen[$j] !== 0) {
                $buf[$i + $j] ^= $exp[$logFactor + $log[$gen[$j]]];
            }
        }
    }
    return array_slice($buf, $n);
}

/** Pilih versi QR terkecil (1–20) di ECC Level L yang memuat $byteLen byte. */
function qr_choose_version(int $byteLen): int
{
    for ($v = 1; $v <= 20; $v++) {
        [$_, $groups] = qr_ecc_l_spec($v);
        $dataCw = 0;
        foreach ($groups as [$cnt, $cw]) {
            $dataCw += $cnt * $cw;
        }
        $ccBits = $v <= 9 ? 8 : 16;
        $capBits = $dataCw * 8;
        if (4 + $ccBits + ($byteLen * 8) <= $capBits) {
            return $v;
        }
    }
    throw new InvalidArgumentException("Panjang data QR ({$byteLen} byte) melebihi kapasitas maksimum 858 byte.");
}

/** Encode string byte ke stream bit data + ECC ter-interleave dalam bentuk array bit (0|1). */
function qr_build_codeword_bits(string $text, int $version): array
{
    [$ecLen, $groups] = qr_ecc_l_spec($version);
    $totalDataCw = 0;
    foreach ($groups as [$cnt, $cw]) {
        $totalDataCw += $cnt * $cw;
    }

    $len = strlen($text);
    $ccBits = $version <= 9 ? 8 : 16;
    $bits = [0, 1, 0, 0]; // Mode Byte (0100)
    for ($i = $ccBits - 1; $i >= 0; $i--) {
        $bits[] = ($len >> $i) & 1;
    }
    for ($p = 0; $p < $len; $p++) {
        $b = ord($text[$p]);
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($b >> $i) & 1;
        }
    }

    $capBits = $totalDataCw * 8;
    $term = min(4, $capBits - count($bits));
    for ($i = 0; $i < $term; $i++) {
        $bits[] = 0;
    }
    while ((count($bits) % 8) !== 0) {
        $bits[] = 0;
    }

    $codewords = [];
    $totalBits = count($bits);
    for ($i = 0; $i < $totalBits; $i += 8) {
        $val = 0;
        for ($j = 0; $j < 8; $j++) {
            $val = ($val << 1) | $bits[$i + $j];
        }
        $codewords[] = $val;
    }

    $padBytes = [0xEC, 0x11];
    $padIdx = 0;
    while (count($codewords) < $totalDataCw) {
        $codewords[] = $padBytes[$padIdx];
        $padIdx ^= 1;
    }

    $dataBlocks = [];
    $ecBlocks = [];
    $offset = 0;
    $maxDataBlock = 0;
    foreach ($groups as [$cnt, $cw]) {
        $maxDataBlock = max($maxDataBlock, $cw);
        for ($b = 0; $b < $cnt; $b++) {
            $blk = array_slice($codewords, $offset, $cw);
            $offset += $cw;
            $dataBlocks[] = $blk;
            $ecBlocks[] = qr_rs_remainder($blk, $ecLen);
        }
    }

    $interleaved = [];
    for ($i = 0; $i < $maxDataBlock; $i++) {
        foreach ($dataBlocks as $blk) {
            if (isset($blk[$i])) {
                $interleaved[] = $blk[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecBlocks as $blk) {
            if (isset($blk[$i])) {
                $interleaved[] = $blk[$i];
            }
        }
    }

    $outBits = [];
    foreach ($interleaved as $cw) {
        for ($i = 7; $i >= 0; $i--) {
            $outBits[] = ($cw >> $i) & 1;
        }
    }
    return $outBits;
}

/** Siapkan matriks dasar QR berisi function patterns dan bitmap cell `$isFunc`. */
function qr_init_matrix(int $version): array
{
    $size = $version * 4 + 17;
    $mod = array_fill(0, $size, array_fill(0, $size, 0));
    $func = array_fill(0, $size, array_fill(0, $size, false));

    $setFunc = static function (int $r, int $c, int $v) use (&$mod, &$func, $size): void {
        if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
            $mod[$r][$c] = $v;
            $func[$r][$c] = true;
        }
    };

    // Finder patterns (7x7) + 1-module white separator
    $finders = [[0, 0], [0, $size - 7], [$size - 7, 0]];
    foreach ($finders as [$fr, $fc]) {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $fr + $dr;
                $c = $fc + $dc;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                    continue;
                }
                $isDark = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6) && (
                    $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
                    || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4)
                );
                $setFunc($r, $c, $isDark ? 1 : 0);
            }
        }
    }

    // Timing strips (row 6 & col 6)
    for ($i = 8; $i < $size - 8; $i++) {
        $bit = ($i % 2) === 0 ? 1 : 0;
        $setFunc(6, $i, $bit);
        $setFunc($i, 6, $bit);
    }

    // Alignment patterns (5x5): hanya lewati 3 sudut yang bertumpuk dengan finder
    $centers = qr_alignment_positions($version);
    $nAlign = count($centers);
    foreach ($centers as $ir => $cr) {
        foreach ($centers as $ic => $cc) {
            if (($ir === 0 && $ic === 0)
                || ($ir === 0 && $ic === $nAlign - 1)
                || ($ir === $nAlign - 1 && $ic === 0)
            ) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $dist = max(abs($dr), abs($dc));
                    $setFunc($cr + $dr, $cc + $dc, ($dist === 2 || $dist === 0) ? 1 : 0);
                }
            }
        }
    }

    // Dark module (selalu di [4*V + 9, 8])
    $setFunc($size - 8, 8, 1);

    // Reserve Format Information cells
    for ($i = 0; $i <= 8; $i++) {
        if (!$func[8][$i]) $setFunc(8, $i, 0);
        if (!$func[$i][8]) $setFunc($i, 8, 0);
    }
    for ($i = 0; $i < 8; $i++) {
        $setFunc(8, $size - 1 - $i, 0);
        if ($i < 7) {
            $setFunc($size - 1 - $i, 8, 0);
        }
    }

    // Version Information (untuk Versi >= 7): 18-bit BCH(18,6)
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $v = ($bits >> $i) & 1;
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $setFunc($a, $b, $v);
            $setFunc($b, $a, $v);
        }
    }

    return [$mod, $func, $size];
}

/** Tulis 15-bit Format Information BCH(15,5) untuk ECC Level L (01) + $mask (0..7). */
function qr_apply_format_bits(array &$mod, int $size, int $mask): void
{
    $data = (0b01 << 3) | ($mask & 7); // ECC Level L = 01
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $fmt = (($data << 10) | $rem) ^ 0x5412;

    // Salinan 1 (kiri-atas: bit 14 di (8,0) hingga bit 0 di (0,8))
    for ($i = 0; $i <= 5; $i++) $mod[8][$i] = ($fmt >> (14 - $i)) & 1;
    $mod[8][7] = ($fmt >> 8) & 1;
    $mod[8][8] = ($fmt >> 7) & 1;
    $mod[7][8] = ($fmt >> 6) & 1;
    for ($i = 0; $i <= 5; $i++) $mod[$i][8] = ($fmt >> $i) & 1;

    // Salinan 2 (bawah-kiri: bit 14..8; kanan-atas: bit 7..0)
    for ($i = 0; $i < 7; $i++) $mod[$size - 1 - $i][8] = ($fmt >> (14 - $i)) & 1;
    for ($i = 0; $i < 8; $i++) $mod[8][$size - 8 + $i] = ($fmt >> (7 - $i)) & 1;
}

/** Skor penalti ISO/IEC 18004 (N1..N4) untuk menentukan mask optimal. */
function qr_penalty_score(array $mod, int $size): int
{
    $score = 0;
    // N1: 5+ modul berurutan berwarna sama pada baris & kolom
    for ($r = 0; $r < $size; $r++) {
        $rowRun = 0;
        $colRun = 0;
        $rowLast = -1;
        $colLast = -1;
        for ($c = 0; $c < $size; $c++) {
            $rv = $mod[$r][$c];
            if ($rv === $rowLast) {
                $rowRun++;
                if ($rowRun === 5) $score += 3;
                elseif ($rowRun > 5) $score += 1;
            } else {
                $rowLast = $rv;
                $rowRun = 1;
            }
            $cv = $mod[$c][$r];
            if ($cv === $colLast) {
                $colRun++;
                if ($colRun === 5) $score += 3;
                elseif ($colRun > 5) $score += 1;
            } else {
                $colLast = $cv;
                $colRun = 1;
            }
        }
    }
    // N2: blok 2x2 berwarna sama
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $mod[$r][$c];
            if ($v === $mod[$r][$c + 1] && $v === $mod[$r + 1][$c] && $v === $mod[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }
    // N3: pola rasio 1:1:3:1:1 mirip finder
    $darkCount = 0;
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($mod[$r][$c]) $darkCount++;
            if ($c + 10 < $size) {
                if (
                    $mod[$r][$c] === 1 && $mod[$r][$c + 1] === 0 && $mod[$r][$c + 2] === 1 &&
                    $mod[$r][$c + 3] === 1 && $mod[$r][$c + 4] === 1 && $mod[$r][$c + 5] === 0 &&
                    $mod[$r][$c + 6] === 1 && $mod[$r][$c + 7] === 0 && $mod[$r][$c + 8] === 0 &&
                    $mod[$r][$c + 9] === 0 && $mod[$r][$c + 10] === 0
                ) $score += 40;
                if (
                    $mod[$r][$c] === 0 && $mod[$r][$c + 1] === 0 && $mod[$r][$c + 2] === 0 &&
                    $mod[$r][$c + 3] === 0 && $mod[$r][$c + 4] === 1 && $mod[$r][$c + 5] === 0 &&
                    $mod[$r][$c + 6] === 1 && $mod[$r][$c + 7] === 1 && $mod[$r][$c + 8] === 1 &&
                    $mod[$r][$c + 9] === 0 && $mod[$r][$c + 10] === 1
                ) $score += 40;
            }
            if ($r + 10 < $size) {
                if (
                    $mod[$r][$c] === 1 && $mod[$r + 1][$c] === 0 && $mod[$r + 2][$c] === 1 &&
                    $mod[$r + 3][$c] === 1 && $mod[$r + 4][$c] === 1 && $mod[$r + 5][$c] === 0 &&
                    $mod[$r + 6][$c] === 1 && $mod[$r + 7][$c] === 0 && $mod[$r + 8][$c] === 0 &&
                    $mod[$r + 9][$c] === 0 && $mod[$r + 10][$c] === 0
                ) $score += 40;
                if (
                    $mod[$r][$c] === 0 && $mod[$r + 1][$c] === 0 && $mod[$r + 2][$c] === 0 &&
                    $mod[$r + 3][$c] === 0 && $mod[$r + 4][$c] === 1 && $mod[$r + 5][$c] === 0 &&
                    $mod[$r + 6][$c] === 1 && $mod[$r + 7][$c] === 1 && $mod[$r + 8][$c] === 1 &&
                    $mod[$r + 9][$c] === 0 && $mod[$r + 10][$c] === 1
                ) $score += 40;
            }
        }
    }
    // N4: keseimbangan proporsi gelap vs terang terhadap 50%
    $pct = ($darkCount * 100) / ($size * $size);
    $score += (int) (abs($pct - 50) / 5) * 10;
    return $score;
}

/** Cek predikat mask QR ke-$mask (0..7) pada koordinat ($r, $c). */
function qr_mask_bit(int $mask, int $r, int $c): bool
{
    return match ($mask) {
        0 => (($r + $c) % 2) === 0,
        1 => ($r % 2) === 0,
        2 => ($c % 3) === 0,
        3 => (($r + $c) % 3) === 0,
        4 => ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0,
        5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
        6 => ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0,
        7 => ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0,
        default => false,
    };
}

/**
 * Hasilkan matriks biner QR Code (array NxN bernilai 0/1) untuk string $text.
 */
function qr_encode_matrix(string $text): array
{
    $version = qr_choose_version(strlen($text));
    $bits = qr_build_codeword_bits($text, $version);
    [$base, $func, $size] = qr_init_matrix($version);

    // Tempatkan bitstream secara zig-zag dari kanan-bawah ke atas/bawah
    $bitIdx = 0;
    $bitCount = count($bits);
    $up = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5; // lewati kolom timing vertikal 6
        }
        for ($step = 0; $step < $size; $step++) {
            $r = $up ? ($size - 1 - $step) : $step;
            for ($dx = 0; $dx < 2; $dx++) {
                $c = $right - $dx;
                if ($func[$r][$c]) {
                    continue;
                }
                $base[$r][$c] = ($bitIdx < $bitCount) ? $bits[$bitIdx++] : 0;
            }
        }
        $up = !$up;
    }

    // Evaluasi seluruh 8 mask dan pilih mask dengan skor penalti terendah
    $bestMod = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $cand = $base;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$func[$r][$c] && qr_mask_bit($mask, $r, $c)) {
                    $cand[$r][$c] ^= 1;
                }
            }
        }
        qr_apply_format_bits($cand, $size, $mask);
        $sc = qr_penalty_score($cand, $size);
        if ($sc < $bestScore) {
            $bestScore = $sc;
            $bestMod = $cand;
        }
    }

    return $bestMod;
}

/** Render string SVG QR Code dengan quiet zone dan 1 elemen <path> gabungan (super ringkas). */
function qr_render_svg(string $text, int $quietZone = 4, int $px = 300): string
{
    $mat = qr_encode_matrix($text);
    $n = count($mat);
    $qz = max(1, $quietZone);
    $dim = $n + ($qz * 2);

    // RLE horizontal per baris supaya path SVG sangat padat & cepat dirender
    $d = '';
    for ($r = 0; $r < $n; $r++) {
        $y = $r + $qz;
        $c = 0;
        while ($c < $n) {
            if (!$mat[$r][$c]) {
                $c++;
                continue;
            }
            $start = $c;
            while ($c < $n && $mat[$r][$c]) {
                $c++;
            }
            $len = $c - $start;
            $x = $start + $qz;
            $d .= "M{$x} {$y}h{$len}v1h-{$len}z";
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
        . '<path d="' . $d . '" fill="#000000"/>'
        . '</svg>';
}

/** Render QR Code menjadi SVG Data URI (`data:image/svg+xml;base64,...`) agar langsung tampil di <img> tanpa request keluar. */
function qr_svg_data_uri(string $text, int $quietZone = 4, int $px = 300): string
{
    if ($text === '') {
        return '';
    }
    try {
        return 'data:image/svg+xml;base64,' . base64_encode(qr_render_svg($text, $quietZone, $px));
    } catch (Throwable) {
        return '';
    }
}
