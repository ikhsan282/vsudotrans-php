<?php
/**
 * Helper PDF bersama (Dompdf, vendor di includes/vendor/dompdf — tanpa Composer).
 * Dipakai invoice, slip payroll, dan laporan (PDF server-side, bukan dialog cetak browser).
 *
 * Kontrak:
 *  - hanya teks/tabel; TIDAK ada akses jaringan (setIsRemoteEnabled(false)) — jadi
 *    gambar harus base64/data-URI, bukan URL (mencegah SSRF lewat isi dokumen);
 *  - font DejaVu Sans (ter-bundle) → aksen & karakter Indonesia aman;
 *  - chroot dibatasi ke root proyek (Dompdf tidak boleh membaca berkas sembarangan);
 *  - GD tidak dibutuhkan (tidak ada gambar raster); logo bisa dikirim sebagai data-URI.
 *
 * Ketahanan shared hosting: bila ekstensi `dom`/`mbstring` tidak tersedia (jarang di cPanel,
 * tapi mungkin), pdf_render melempar RuntimeException dan pdf_send_or_html() otomatis
 * menurunkan keluaran ke halaman HTML siap cetak — pengguna tetap bisa menyimpan PDF via
 * dialog cetak browser, alih-alih melihat error 500.
 */

/** Apakah lingkungan punya ekstensi yang dibutuhkan Dompdf? */
function pdf_supported(): bool
{
    return class_exists('DOMDocument') && function_exists('mb_convert_encoding');
}

/** Pastikan autoloader Dompdf dimuat sekali. */
function pdf_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once __DIR__ . '/vendor/dompdf/autoload.inc.php';
    $done = true;
}

/**
 * Render HTML menjadi string PDF. $paper 'A4', $orient 'portrait'|'landscape'.
 * Mengembalikan string PDF; melempar RuntimeException bila gagal.
 */
function pdf_render(string $html, string $paper = 'A4', string $orient = 'portrait', string $font = 'DejaVu Sans'): string
{
    pdf_boot();
    $opts = new \Dompdf\Options();
    $opts->setChroot(dirname(__DIR__));
    $opts->setIsRemoteEnabled(false);
    $opts->setIsHtml5ParserEnabled(true);
    $opts->setDefaultFont($font);
    $opts->setTempDir(sys_get_temp_dir());

    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orient);
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Render + kirim PDF; bila ekstensi tak tersedia/gagal, turunkan ke HTML siap cetak
 * (dengan tombol Cetak) alih-alih error 500. Selalu exit.
 */
function pdf_send_or_html(string $html, string $base, string $paper = 'A4', string $orient = 'portrait'): void
{
    if (pdf_supported()) {
        try {
            pdf_send(pdf_render($html, $paper, $orient), $base);
        } catch (\Throwable $e) {
            error_log('pdf_render gagal: ' . $e->getMessage());
        }
    }
    $inject = '<style>body{font-family:"DejaVu Sans",sans-serif}.pdf-fallback-btn{font-size:12px;padding:7px 12px;'
        . 'border:1px solid #0d3b66;background:#0d3b66;color:#fff;border-radius:4px;cursor:pointer;margin-bottom:12px}'
        . '@media print{.pdf-fallback-btn{display:none}}</style>'
        . '<div><button class="pdf-fallback-btn" onclick="window.print()">Cetak / Simpan PDF</button></div>';
    $pos = strripos($html, '<body>');
    $html = $pos !== false ? substr($html, 0, $pos + 6) . $inject . substr($html, $pos + 6) : $inject . $html;
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

/**
 * Kirim PDF ke browser sebagai unduhan. $base = nama berkas tanpa ekstensi.
 * Header dual-form (ASCII fallback + filename* UTF-8) — sama pola dengan export CSV/XLSX.
 */
function pdf_send(string $pdf, string $base): void
{
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
    $ascii = str_replace('"', '', (string) $ascii);
    $utf8  = rawurlencode($base . '.pdf');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $ascii . '.pdf"; filename*=UTF-8\'\'' . $utf8);
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}

/** Gaya dasar dokumen PDF (kop, tabel, footer). $accent = warna aksen hex. */
function pdf_base_css(string $accent = '#0d3b66'): string
{
    return '
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #212529; }
        h1,h2,h3,h4 { color: ' . $accent . '; margin: 0 0 6px; }
        h1 { font-size: 17px; } h2 { font-size: 14px; } h3 { font-size: 12px; }
        .muted { color: #6c757d; }
        .small { font-size: 9px; }
        .right { text-align: right; }
        .center { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #adb5bd; padding: 5px 6px; vertical-align: top; }
        th { background: ' . $accent . '; color: #fff; text-align: left; font-size: 9.5px; }
        tfoot td { font-weight: bold; background: #f1f3f5; }
        .doc-head { border-bottom: 2px solid ' . $accent . '; padding-bottom: 8px; margin-bottom: 12px; }
        .doc-title { text-align: right; }
        .doc-title .t { font-size: 20px; font-weight: bold; color: ' . $accent . '; letter-spacing: 1px; }
        .kv { width: 100%; } .kv td { border: 0; padding: 1px 0; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center;
                  font-size: 8px; color: #adb5bd; border-top: 1px solid #dee2e6; padding-top: 3px; }
        .signbox { margin-top: 26px; width: 100%; } .signbox td { border: 0; text-align: center; }
    ';
}

/** Blok kop dokumen: identitas perusahaan (kiri) + judul (kanan). */
function pdf_doc_head(string $title, ?array $company, string $subtitle = ''): string
{
    $name = htmlspecialchars((string) ($company['name'] ?? COMPANY_NAME), ENT_QUOTES, 'UTF-8');
    $addr = htmlspecialchars(trim(implode(', ', array_filter([
        (string) ($company['address'] ?? COMPANY_ADDRESS),
        (string) ($company['city'] ?? ''),
    ]))), ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars((string) ($company['phone_number'] ?? COMPANY_PHONE), ENT_QUOTES, 'UTF-8');
    $h = '<div class="doc-head"><table class="kv"><tr>'
       . '<td><div style="font-size:13px;font-weight:bold;color:#0d3b66">' . $name . '</div>'
       . '<div class="small muted">' . $addr . '</div>'
       . '<div class="small muted">' . $phone . '</div></td>'
       . '<td class="doc-title"><div class="t">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
       . ($subtitle !== '' ? '<div class="small muted">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</div>' : '')
       . '</td></tr></table></div>';
    return $h;
}

/** Footer dokumen dengan nama perusahaan + waktu cetak. */
function pdf_footer(?array $company = null, ?string $when = null): string
{
    $name = htmlspecialchars((string) ($company['name'] ?? COMPANY_NAME), ENT_QUOTES, 'UTF-8');
    $when = $when ?? date('d/m/Y H:i');
    return '<div class="footer">' . $name . ' &middot; dicetak ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8')
         . ' &middot; VSudoTrans</div>';
}

/**
 * Override CSS untuk mencetak dokumen HTML layar (invoice, slip) menjadi PDF:
 *  - font diseragamkan ke DejaVu Sans (satu-satunya keluarga yang ter-bundle di Dompdf);
 *  - latar abu & bayangan kotak dibuang, padding halaman dipindah ke @page;
 *  - elemen ber-class .no-print / .iv-no-print disembunyikan.
 * Dipakai dengan fungsi pdf_html_to_pdf() di bawah.
 */
function pdf_screen_overrides(string $extra_hide = '.no-print, .iv-no-print'): string
{
    return '<style>'
        . 'body { font-family: "DejaVu Sans", sans-serif !important; background: #fff !important; margin: 0; }'
        . 'h1,h2,h3,h4,strong,.fw-bold { font-weight: bold; }'
        . $extra_hide . ' { display: none !important; }'
        . '</style>';
}

/**
 * Render dokumen HTML "layar" (yang biasanya dicetak lewat window.print()) menjadi PDF.
 * $hide = selektor elemen alat (tombol) yang disembunyikan.
 */
function pdf_from_screen_html(string $html, string $hide = '.no-print, .iv-no-print'): string
{
    return pdf_render(pdf_prepare_screen_html($html, $hide), 'A4', 'portrait');
}

/** Sisipkan override gaya-cetak ke dokumen HTML layar (tanpa render). */
function pdf_prepare_screen_html(string $html, string $hide = '.no-print, .iv-no-print'): string
{
    $inject = pdf_screen_overrides($hide);
    $pos = strripos($html, '</head>');
    if ($pos !== false) {
        return substr($html, 0, $pos) . $inject . substr($html, $pos);
    }
    return $inject . $html;
}
