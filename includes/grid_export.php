<?php
/**
 * Helper Export Grid (Excel .xlsx & PDF .pdf) standar untuk seluruh daftar/grid.
 */

require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/pdf.php';

/**
 * Buat daftar action button Export Excel & PDF untuk dipasang di ui_page_header().
 * Otomatis mempertahankan query string filter/pencarian yang sedang aktif.
 */
function ui_export_actions(?array $extra_query = null): array
{
    $q = $_GET;
    unset($q['page'], $q['export']);
    if ($extra_query) {
        $q = array_merge($q, $extra_query);
    }
    $xlsx_url = '?' . http_build_query(array_merge($q, ['export' => 'xlsx']));
    $pdf_url  = '?' . http_build_query(array_merge($q, ['export' => 'pdf']));

    return [
        [
            'label' => 'Export',
            'icon'  => 'download',
            'class' => 'btn btn-outline-secondary btn-sm dropdown-toggle',
            'items' => [
                [
                    'label'      => 'Excel (.xlsx)',
                    'icon'       => 'file-earmark-excel',
                    'icon_class' => 'text-success',
                    'href'       => $xlsx_url,
                ],
                [
                    'label'      => 'PDF (.pdf)',
                    'icon'       => 'file-earmark-pdf',
                    'icon_class' => 'text-danger',
                    'href'       => $pdf_url,
                ],
            ],
        ],
    ];
}

/**
 * Kirim unduhan hasil export grid dalam format XLSX atau PDF, lalu exit.
 *
 * @param string $title    Judul laporan/daftar (mis. "Daftar Perusahaan")
 * @param array  $headers  Daftar nama kolom header (tanpa kolom No, karena No ditambahkan otomatis)
 * @param array  $rows     Array 2D baris data (urutan elemen sesuai $headers)
 * @param string $format   'xlsx' atau 'pdf'
 * @param string $orient   'portrait' atau 'landscape' (untuk PDF)
 * @param string $subtitle Subjudul opsional (mis. filter aktif)
 */
function grid_export_send(
    string $title,
    array $headers,
    array $rows,
    string $format = 'xlsx',
    string $orient = 'portrait',
    string $subtitle = ''
): void {
    $format = strtolower(trim($format));
    $date_str = date('Y-m-d');
    $base_name = preg_replace('/[^\w\s\-]/u', '', $title) . ' - ' . $date_str;

    // Ambil informasi perusahaan aktif untuk kop PDF bila ada
    $company = null;
    $cid = active_company_id();
    if ($cid !== null) {
        $st = db()->prepare('SELECT name, address, city, phone_number FROM companies WHERE id = ?');
        $st->bind_param('i', $cid);
        $st->execute();
        $company = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }

    if ($format === 'pdf') {
        $sub = $subtitle !== '' ? $subtitle . ' · ' : '';
        $sub .= 'Total: ' . count($rows) . ' data · Dicetak ' . date('d/m/Y H:i');

        $css = pdf_base_css('#0d3b66') . '
            tr:nth-child(even) td { background: #f8f9fa; }
            td.no-col, th.no-col { width: 32px; text-align: center; }
        ';

        $thead = '<thead><tr><th class="no-col">No</th>';
        foreach ($headers as $h) {
            $thead .= '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $thead .= '</tr></thead>';

        $tbody = '<tbody>';
        if (!$rows) {
            $colspan = count($headers) + 1;
            $tbody .= '<tr><td colspan="' . $colspan . '" class="center muted">Tidak ada data.</td></tr>';
        } else {
            foreach ($rows as $idx => $r) {
                $tbody .= '<tr><td class="no-col">' . ($idx + 1) . '</td>';
                foreach (array_values($r) as $cell) {
                    $is_num = is_int($cell) || is_float($cell);
                    $val = $cell === null ? '' : (string) $cell;
                    $tbody .= '<td' . ($is_num ? ' class="right"' : '') . '>'
                        . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $tbody .= '</tr>';
            }
        }
        $tbody .= '</tbody>';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>' . $css . '</style></head><body>'
            . pdf_doc_head($title, $company, $sub)
            . '<table>' . $thead . $tbody . '</table>'
            . pdf_footer($company)
            . '</body></html>';

        audit('grid.export.pdf', $title . ' (' . count($rows) . ' baris)');
        pdf_send_or_html($html, $base_name, 'A4', $orient);
    }

    // Default: XLSX
    $sheet_rows = [];
    $sheet_rows[] = array_merge(['No'], array_values($headers));
    foreach ($rows as $idx => $r) {
        $sheet_rows[] = array_merge([$idx + 1], array_values($r));
    }

    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base_name);
    $ascii = str_replace('"', '', (string) $ascii);
    $utf8  = rawurlencode($base_name . '.xlsx');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ascii . '.xlsx"; filename*=UTF-8\'\'' . $utf8);
    header('Cache-Control: no-store');

    audit('grid.export.xlsx', $title . ' (' . count($rows) . ' baris)');
    $sheet_title = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '', $title), 0, 31) ?: 'Data';
    echo xlsx_build($sheet_rows, $sheet_title);
    exit;
}

