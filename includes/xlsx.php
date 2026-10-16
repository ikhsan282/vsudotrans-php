<?php
/**
 * Penulis XLSX native — pure PHP, tanpa Composer, tanpa ZipArchive.
 *
 * Cukup untuk kebutuhan laporan: satu sheet, campuran teks (sharedStrings) dan angka.
 * ZIP memakai metode STORED (tanpa kompresi) sehingga cukup header lokal + data +
 * central directory + EOCD. Tidak ada dependensi eksternal.
 *
 * ponytail: satu sheet, tanpa styling/formula/gambar — tambah bila laporan butuh
 * header berwarna atau beberapa sheet.
 */

/** Escape untuk XML: escape entitas + buang karakter kontrol (Excel menolak sheet yang memuatnya). */
function xlsx_xml(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** CRC32 little-endian 4 byte dari data (yang diminta spesifikasi ZIP). */
function xlsx_crc32(string $data): string
{
    return pack('V', hexdec(hash('crc32b', $data)));
}

/** Satu entry ZIP metode STORED: local file header + data. */
function xlsx_local_header(string $name, string $data): string
{
    return "PK\x03\x04"
        . pack('v', 20)                 // version needed
        . pack('v', 0x0800)             // flag: UTF-8 nama file
        . pack('v', 0)                  // method 0 = stored
        . pack('v', 0)                  // mtime
        . pack('v', 0)                  // mdate
        . xlsx_crc32($data)
        . pack('V', strlen($data))      // compressed size
        . pack('V', strlen($data))      // uncompressed size
        . pack('v', strlen($name))
        . pack('v', 0)                  // extra length
        . $name
        . $data;
}

/** Central directory entry untuk satu file. */
function xlsx_central_entry(string $name, string $data, int $offset): string
{
    return "PK\x01\x02"
        . pack('v', 20)                 // version made by
        . pack('v', 20)                 // version needed
        . pack('v', 0x0800)             // flag UTF-8
        . pack('v', 0)                  // method stored
        . pack('v', 0) . pack('v', 0)   // mtime/mdate
        . xlsx_crc32($data)
        . pack('V', strlen($data))
        . pack('V', strlen($data))
        . pack('v', strlen($name))
        . pack('v', 0)                  // extra
        . pack('v', 0)                  // comment
        . pack('v', 0)                  // disk number
        . pack('v', 0)                  // internal attrs
        . pack('V', 0)                  // external attrs
        . pack('V', $offset)            // offset local header
        . $name;
}

/** Bangun arsip ZIP dari map nama → isi. */
function xlsx_zip(array $files): string
{
    $out = '';
    $central = '';
    foreach ($files as $name => $data) {
        $central .= xlsx_central_entry($name, $data, strlen($out));
        $out .= xlsx_local_header($name, $data);
    }
    $count = count($files);
    $eocd = "PK\x05\x06"
        . pack('v', 0) . pack('v', 0)   // disk numbers
        . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central))
        . pack('V', strlen($out))
        . pack('v', 0);
    return $out . $central . $eocd;
}

/** Kolom ke-0 → A, 26 → AA, dst. */
function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/**
 * Tulis workbook satu sheet dari baris-baris data (array baris; sel = string|int|float|null).
 * Mengembalikan isi file XLSX (string biner).
 */
function xlsx_build(array $rows, string $sheet_name = 'Laporan'): string
{
    // Pass 1: kumpulkan string unik → sharedStrings; angka langsung ke <v>.
    $strings = [];
    $cells = [];
    foreach ($rows as $r => $row) {
        foreach (array_values($row) as $c => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_int($v) || is_float($v)) {
                $cells[$r][$c] = ['n', $v];
                continue;
            }
            $v = (string) $v;
            if (!isset($strings[$v])) {
                $strings[$v] = count($strings);
            }
            $cells[$r][$c] = ['s', $strings[$v]];
        }
    }

    // sheet1.xml: baris & sel; teks dirujuk via t="s" ke indeks sharedStrings.
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($cells as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => [$type, $val]) {
            $ref = xlsx_col($c) . ($r + 1);
            $sheet .= $type === 's'
                ? '<c r="' . $ref . '" t="s"><v>' . (int) $val . '</v></c>'
                : '<c r="' . $ref . '"><v>' . xlsx_num($val) . '</v></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . count($cells ? array_merge(...array_map('array_values', $cells)) : [])
        . '" uniqueCount="' . count($strings) . '">';
    foreach (array_keys($strings) as $s) {
        $shared .= '<si><t xml:space="preserve">' . xlsx_xml((string) $s) . '</t></si>';
    }
    $shared .= '</sst>';

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_xml($sheet_name) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    return xlsx_zip([
        '[Content_Types].xml'      => $content_types,
        '_rels/.rels'              => $rels,
        'xl/workbook.xml'          => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbook_rels,
        'xl/worksheets/sheet1.xml' => $sheet,
        'xl/sharedStrings.xml'     => $shared,
    ]);
}

/** Format angka untuk <v>: titik desimal, tanpa notasi ilmiah. */
function xlsx_num($n): string
{
    if (is_int($n)) {
        return (string) $n;
    }
    $s = rtrim(rtrim(sprintf('%.10F', (float) $n), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}
