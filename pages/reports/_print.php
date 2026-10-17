<?php
/**
 * Presenter PDF/HTML untuk file export laporan.
 * Dipanggil SETELAH $table (array baris) & $base dibangun oleh tiap *_export.php,
 * sehingga CSV/XLSX/PDF memakai data yang sama persis (tidak ada query ulang).
 *
 * Baris pemisah bagian: ['', 'Judul Bagian'] (kolom ke-2 berisi judul, kolom lain kosong).
 * Baris total: baris yang kolom keduanya 'TOTAL'. Sisanya = baris data.
 *
 * $table  : array of array (baris siap-tulis)
 * $title  : judul dokumen (mis. 'Laporan Booking')
 * $base   : nama berkas tanpa ekstensi
 * $sub    : subjudul (mis. 'Periode 01/10/2026 s/d 31/10/2026')
 */
require_once __DIR__ . '/../../includes/pdf.php';

/** Apakah baris ini pemisah bagian? ['' , 'Judul'] → true. */
function rpt_is_section(array $row): bool
{
    $n = count($row);
    return $n >= 2 && trim((string) $row[0]) === '' && trim((string) $row[1]) !== ''
        && trim((string) $row[1]) !== 'TOTAL';
}

/** Apakah baris ini baris total? */
function rpt_is_total(array $row): bool
{
    foreach ($row as $c) {
        if (is_string($c) && trim($c) === 'TOTAL') {
            return true;
        }
    }
    return false;
}

/** Buat HTML laporan generik dari $table. */
function rpt_table_html(array $table, string $title, string $sub, ?array $company = null): string
{
    $css = pdf_base_css();
    $cols = 0;
    foreach ($table as $r) {
        $cols = max($cols, count($r));
    }

    $body = '';
    $in_table = false;
    foreach ($table as $row) {
        if (!$row) {
            continue;
        }
        if (rpt_is_section($row)) {
            if ($in_table) {
                $body .= '</tbody></table>';
                $in_table = false;
            }
            $body .= '<h3>' . htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8') . '</h3>';
            continue;
        }
        if (!$in_table) {
            $body .= '<table><tbody>';
            $in_table = true;
        }
        $is_total = rpt_is_total($row);
        $body .= '<tr' . ($is_total ? ' class="tot"' : '') . '>';
        for ($i = 0; $i < $cols; $i++) {
            $v = $row[$i] ?? '';
            if (is_bool($v)) {
                $v = $v ? 'Ya' : 'Tidak';
            }
            $is_num = is_int($v) || is_float($v);
            $txt = $is_num
                ? (is_float($v) && abs($v - round($v)) > 0.0001 ? number_format((float) $v, 2, ',', '.') : number_format((float) $v, 0, ',', '.'))
                : (string) $v;
            $body .= '<td' . ($is_num ? ' class="right"' : '') . '>' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $body .= '</tr>';
    }
    if ($in_table) {
        $body .= '</tbody></table>';
    }

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>' . $css . '
           body { padding: 12px; }
           tr.tot td { font-weight: bold; background: #f1f3f5; }
           .toolbar { margin-bottom: 12px; }
           .btn { font-family: "DejaVu Sans", sans-serif; font-size: 12px; padding: 7px 12px;
                  border: 1px solid #0d3b66; background: #0d3b66; color: #fff;
                  border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
           @media print { .toolbar { display: none; } body { padding: 0; } }
           </style></head><body>'
        . (isset($_GET['print']) ? '<div class="toolbar"><button class="btn" onclick="window.print()">Cetak / Simpan PDF</button></div>' : '')
        . pdf_doc_head($title, $company, $sub)
        . $body
        . pdf_footer($company)
        . '</body></html>';
}

/**
 * Titik masuk: ?format=pdf (default) → unduh PDF; ?format=html[&print] → HTML siap cetak.
 * $company = baris companies (opsional; default konstanta).
 */
function rpt_send(array $table, string $title, string $base, string $sub = '', ?array $company = null, string $orient = 'portrait', ?string $audit_action = null): void
{
    $fmt = (string) ($_GET['format'] ?? 'pdf');
    $html = rpt_table_html($table, $title, $sub, $company);

    if ($fmt === 'html') {
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    if ($audit_action) {
        audit($audit_action, $base . ' (PDF)');
    }
    pdf_send_or_html($html, $base, 'A4', $orient);
}
