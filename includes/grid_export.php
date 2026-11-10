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

define('GRID_PDF_MAX_ROWS', 2000);

/**
 * Generator penarik data SQL secara bertahap (chunked batching) agar MySQLi
 * tidak pernah memuat ratusan ribu baris sekaligus ke dalam RAM.
 *
 * @param string   $base_sql   Query SELECT ... WHERE ... ORDER BY ... (tanpa LIMIT)
 * @param string   $types      Tipe bind_param (mis. 'ssi')
 * @param array    $params     Parameter bind_param
 * @param callable $row_mapper Fungsi (array $assoc_row): array yang mengubah 1 baris DB menjadi array kolom export
 * @param ?int     $max_rows   Batas maksimal baris yang diambil (null = semua baris)
 * @param int      $batch_size Ukuran chunk per query (default 2000 baris)
 */
function grid_query_stream(
    string $base_sql,
    string $types,
    array $params,
    callable $row_mapper,
    ?int $max_rows = null,
    int $batch_size = 2000
): Generator {
    $offset = 0;
    $yielded = 0;
    $db = db();

    while (true) {
        $take = $batch_size;
        if ($max_rows !== null) {
            $remaining = $max_rows - $yielded;
            if ($remaining <= 0) {
                break;
            }
            $take = min($batch_size, $remaining);
        }

        $sql = $base_sql . ' LIMIT ? OFFSET ?';
        $st = $db->prepare($sql);
        $b_types = $types . 'ii';
        $b_params = [...$params, $take, $offset];
        $st->bind_param($b_types, ...$b_params);
        $st->execute();
        $res = $st->get_result();

        $count = 0;
        while ($r = $res->fetch_assoc()) {
            $count++;
            $yielded++;
            yield $row_mapper($r);
        }
        $st->close();

        if ($count < $take) {
            break;
        }
        $offset += $take;
    }
}

/**
 * Kirim unduhan hasil export grid dalam format XLSX atau PDF, lalu exit.
 * Mendukung $rows berupa array biasa maupun Generator/Traversable (streaming O(1) RAM).
 *
 * @param string   $title      Judul laporan/daftar (mis. "Daftar Perusahaan")
 * @param array    $headers    Daftar nama kolom header (tanpa kolom No, karena No ditambahkan otomatis)
 * @param iterable $rows       Array 2D atau Generator baris data (urutan elemen sesuai $headers)
 * @param string   $format     'xlsx' atau 'pdf'
 * @param string   $orient     'portrait' atau 'landscape' (untuk PDF)
 * @param string   $subtitle   Subjudul opsional (mis. filter aktif)
 * @param ?int     $total_rows Total baris keseluruhan di DB (opsional, untuk info apabila PDF dibatasi)
 */
function grid_export_send(
    string $title,
    array $headers,
    iterable $rows,
    string $format = 'xlsx',
    string $orient = 'portrait',
    string $subtitle = '',
    ?int $total_rows = null
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
        $pdf_rows = [];
        $truncated = false;
        foreach ($rows as $r) {
            if (count($pdf_rows) >= GRID_PDF_MAX_ROWS) {
                $truncated = true;
                break;
            }
            $pdf_rows[] = $r;
        }
        if ($total_rows !== null && $total_rows > count($pdf_rows)) {
            $truncated = true;
        }

        $sub_parts = [];
        if ($subtitle !== '') {
            $sub_parts[] = $subtitle;
        }
        if ($truncated) {
            $total_label = $total_rows !== null ? number_format($total_rows, 0, ',', '.') : '>' . GRID_PDF_MAX_ROWS;
            $sub_parts[] = 'Menampilkan ' . number_format(count($pdf_rows), 0, ',', '.') . ' dari ' . $total_label . ' data (gunakan filter atau Export Excel untuk data penuh)';
        } else {
            $sub_parts[] = 'Total: ' . number_format(count($pdf_rows), 0, ',', '.') . ' data';
        }
        $sub_parts[] = 'Dicetak ' . date('d/m/Y H:i');
        $sub = implode(' · ', $sub_parts);

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
        if (!$pdf_rows) {
            $colspan = count($headers) + 1;
            $tbody .= '<tr><td colspan="' . $colspan . '" class="center muted">Tidak ada data.</td></tr>';
        } else {
            foreach ($pdf_rows as $idx => $r) {
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

        audit('grid.export.pdf', $title . ' (' . count($pdf_rows) . ' baris)');
        pdf_send_or_html($html, $base_name, 'A4', $orient);
    }

    // Default: XLSX (Streaming O(1) RAM via Generator + php://temp)
    @set_time_limit(300);
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base_name);
    $ascii = str_replace('"', '', (string) $ascii);
    $utf8  = rawurlencode($base_name . '.xlsx');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ascii . '.xlsx"; filename*=UTF-8\'\'' . $utf8);
    header('Cache-Control: no-store');

    $sheet_title = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '', $title), 0, 31) ?: 'Data';
    $written_data_rows = 0;

    $row_generator = (static function () use ($headers, $rows, &$written_data_rows): Generator {
        yield array_merge(['No'], array_values($headers));
        $no = 0;
        foreach ($rows as $r) {
            $no++;
            $written_data_rows++;
            yield array_merge([$no], array_values($r));
        }
    })();

    xlsx_stream($row_generator, $sheet_title);
    audit('grid.export.xlsx', $title . ' (' . $written_data_rows . ' baris)');
    exit;
}

