<?php
/**
 * Helper umum: CSRF, flash, escape, format, pagination, audit.
 */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalisasi nomor telepon Indonesia menjadi format kanonis diawali `62` (hanya digit, tanpa `+`/spasi/`-`).
 * Contoh: `0812-3456-789` -> `628123456789`, `+62 812...` -> `62812...`, `812...` -> `62812...`,
 * `021-0000000` -> `62210000000`. Mengembalikan `null` bila kosong, tidak valid, atau hanya `'62'`.
 */
function normalize_phone_62(?string $raw): ?string
{
    $s = preg_replace('/@.*$/', '', trim((string) $raw));
    $d = preg_replace('/\D+/', '', (string) $s);
    if ($d === '' || $d === null) {
        return null;
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (str_starts_with($d, '620') && strlen($d) > 3) {
        $d = '62' . ltrim(substr($d, 2), '0');
    } elseif (str_starts_with($d, '62')) {
        // sudah diawali 62
    } elseif (str_starts_with($d, '0')) {
        $rest = ltrim(substr($d, 1), '0');
        $d = $rest !== '' ? '62' . $rest : '';
    } else {
        $d = '62' . $d;
    }
    if ($d === '' || $d === '62') {
        return null;
    }
    return $d;
}

/**
 * Prefix pencarian nomor telepon untuk query index `LIKE '62...'`:
 * mengubah awalan `0812` / `+62812` / `812` menjadi `62812`. Mengembalikan null bila kosong.
 */
function phone_search_prefix(string $q): ?string
{
    $norm = normalize_phone_62($q);
    if ($norm !== null) {
        return $norm;
    }
    $d = preg_replace('/\D+/', '', $q) ?? '';
    return $d === '0' ? '62' : null;
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : APP_URL . '/' . ltrim($path, '/')));
    exit;
}

function rupiah(float $n): string
{
    return 'Rp ' . number_format(round($n, 2), 0, ',', '.');
}

/** Format angka nominal/rupiah tanpa prefix 'Rp ' dengan pemisah ribuan titik (contoh: 2.000.000). */
function format_nominal(float $n): string
{
    return number_format(round($n, 2), 0, ',', '.');
}

/**
 * Normalisasi dan parse input uang/rupiah dari pengguna Indonesia ke float murni.
 * Menangani aman format integer maupun ribuan berformat:
 * - 2000000 (angka murni)
 * - 2.000.000 / 150.000 / 50.000 (titik pemisah ribuan standar ID)
 * - 2,000,000 / 150,000 (koma pemisah ribuan)
 * - Rp 2.000.000 / Rp. 150.000,00
 */
function parse_rupiah_input(mixed $val): float
{
    if (is_int($val) || is_float($val)) {
        return is_finite((float) $val) ? (float) $val : 0.0;
    }
    $str = trim((string) $val);
    if ($str === '') {
        return 0.0;
    }

    $neg = str_starts_with($str, '-');
    if ($neg) {
        $str = ltrim(substr($str, 1));
    }
    if (preg_match('/^\d+(?:\.\d+)?e[+-]?\d+$/i', $str)) {
        $expVal = (float) $str;
        return is_finite($expVal) ? ($neg ? -$expVal : $expVal) : 0.0;
    }

    // Bersihkan prefix Rp, rp, spasi, dan karakter selain digit, titik, koma
    $str = preg_replace('/[^\d.,]/', '', $str) ?? '';
    if ($str === '') {
        return 0.0;
    }

    $parsed = 0.0;
    // Jika mengandung titik dan koma (misal: "2.000.000,00" atau "2,000,000.00")
    if (str_contains($str, '.') && str_contains($str, ',')) {
        if (strrpos($str, ',') > strrpos($str, '.')) {
            // Titik ribuan, koma desimal
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } else {
            // Koma ribuan, titik desimal
            $str = str_replace(',', '', $str);
        }
        $parsed = (float) $str;
    } elseif (str_contains($str, '.')) {
        // Desimal USD/DB (.0, .00, .5, .25) vs pemisah ribuan ID (2.000.000, 150.000)
        if (preg_match('/^\d+\.\d{1,2}$/', $str)) {
            $parsed = (float) $str;
        } else {
            $parsed = (float) str_replace('.', '', $str);
        }
    } elseif (str_contains($str, ',')) {
        // Jika hanya mengandung koma (misal: "2,000,000" atau "150,000" atau "150000,00")
        if (preg_match('/^\d+\,(0|00)$/', $str)) {
            $parsed = (float) preg_replace('/\,(0|00)$/', '', $str);
        } elseif (preg_match('/,\d{3}/', $str)) {
            $parsed = (float) str_replace(',', '', $str);
        } else {
            $parsed = (float) str_replace(',', '.', $str);
        }
    } else {
        $parsed = (float) (preg_replace('/[^\d]/', '', $str) ?: '0');
    }

    if (!is_finite($parsed)) {
        return 0.0;
    }
    return $neg ? -$parsed : $parsed;
}

/** Tanggal Indonesia: 2026-10-31 -> "31 Oktober 2026". */
function tanggal_indo(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $d = strtotime($date);
    return $d ? date('j', $d) . ' ' . $bulan[(int) date('n', $d)] . ' ' . date('Y', $d) : $date;
}

/** Validasi tanggal ketat format Y-m-d; kembalikan null bila kosong atau tidak valid. */
function parse_valid_ymd(?string $raw): ?string
{
    $v = trim((string) $raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    $errors = DateTimeImmutable::getLastErrors();
    if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $dt->format('Y-m-d');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('CSRF token tidak valid.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_html(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $cls = $map[$f['type']] ?? 'info';
    return '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
        . e($f['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

function render_flash(): string
{
    return flash_html();
}

/** Resolve jumlah baris per halaman yang diizinkan (10, 20, 50, 100). */
function resolve_per_page(int $default = PER_PAGE, array $allowed = [10, 20, 50, 100]): int
{
    $req = (int) ($_GET['per_page'] ?? $default);
    return in_array($req, $allowed, true) ? $req : $default;
}

/** Pagination sederhana & terstandarisasi: kembalikan offset, rentang from/to, & info halaman. */
function paginate(int $total, int $page = 1, ?int $per_page = null): array
{
    $total = max(0, $total);
    $per_page = max(1, min(100, $per_page ?? resolve_per_page()));
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    $offset = ($page - 1) * $per_page;
    return [
        'total' => $total,
        'per_page' => $per_page,
        'current' => $page,
        'total_pages' => $total_pages,
        'offset' => $offset,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => $total === 0 ? 0 : min($total, $offset + $per_page),
    ];
}

function pagination_html(array $p, string $query_base = '', array $opts = []): string
{
    $total_rows = (int) ($p['total'] ?? 0);
    $current = (int) ($p['current'] ?? 1);
    $total = (int) ($p['total_pages'] ?? 1);
    $cur_pp = (int) ($p['per_page'] ?? PER_PAGE);
    $show_per_page = (bool) ($opts['show_per_page'] ?? true);
    $allowed_sizes = $opts['per_page_options'] ?? [10, 20, 50, 100];

    $need_per_page = $show_per_page && ($total_rows > 10 || $cur_pp !== PER_PAGE);
    if (!$need_per_page && $total <= 1) {
        return '';
    }

    $q_params = [];
    if ($query_base !== '') {
        parse_str(ltrim($query_base, '?&'), $q_params);
    }
    unset($q_params['page']);
    if (!isset($q_params['per_page']) && $cur_pp !== PER_PAGE) {
        $q_params['per_page'] = (string) $cur_pp;
    }

    $emit_hidden = static function (array $params): string {
        $qs = http_build_query($params);
        if ($qs === '') {
            return '';
        }
        $out = '';
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $k = urldecode($parts[0]);
            $v = isset($parts[1]) ? urldecode($parts[1]) : '';
            $out .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        return $out;
    };

    $html = '<div class="d-flex flex-wrap align-items-center justify-content-end gap-2 vst-pagination-controls no-print">';

    if ($need_per_page) {
        $pp_params = $q_params;
        unset($pp_params['per_page']);
        $html .= '<form method="get" class="d-inline-flex align-items-center gap-1 m-0">';
        $html .= $emit_hidden($pp_params);
        $html .= '<input type="hidden" name="page" value="1">';
        $html .= '<select name="per_page" class="form-select form-select-sm w-auto" aria-label="Jumlah baris per halaman" title="Baris per halaman" onchange="this.form.submit()">';
        foreach ($allowed_sizes as $sz) {
            $sz = (int) $sz;
            $sel = $cur_pp === $sz ? ' selected' : '';
            $html .= '<option value="' . $sz . '"' . $sel . '>' . $sz . ' / hal</option>';
        }
        $html .= '</select></form>';
    }

    if ($total > 1) {
        $link = static function (int $page, string $text, bool $active = false, bool $disabled = false) use ($q_params): string {
            $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
            $params = $q_params;
            $params['page'] = $page;
            $href = $disabled ? '#' : '?' . e(http_build_query($params));
            return '<li class="' . $cls . '"><a class="page-link" href="' . $href . '">' . $text . '</a></li>';
        };

        $html .= '<nav aria-label="Navigasi Halaman"><ul class="pagination pagination-sm mb-0">';
        $html .= $link($current - 1, '&laquo;', false, $current <= 1);

        if ($total <= 9) {
            for ($i = 1; $i <= $total; $i++) {
                $html .= $link($i, (string) $i, $i === $current);
            }
        } else {
            $html .= $link(1, '1', 1 === $current);
            if ($current > 4) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }

            $start = max(2, $current - 2);
            $end = min($total - 1, $current + 2);
            for ($i = $start; $i <= $end; $i++) {
                $html .= $link($i, (string) $i, $i === $current);
            }

            if ($current < $total - 3) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            $html .= $link($total, (string) $total, $total === $current);
        }

        $html .= $link($current + 1, '&raquo;', false, $current >= $total);
        $html .= '</ul></nav>';

        if ($total > 5) {
            $html .= '<form method="get" class="d-none d-md-inline-flex align-items-center gap-1 m-0">';
            $html .= $emit_hidden($q_params);
            $html .= '<input type="number" name="page" min="1" max="' . $total . '" value="' . $current . '" class="form-control form-control-sm" style="width:64px" title="Lompat ke halaman (1–' . $total . ')" aria-label="Lompat halaman">';
            $html .= '<button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2" style="height:31px" title="Buka halaman">Go</button>';
            $html .= '</form>';
        }
    }

    $html .= '</div>';
    return $html;
}

/** Footer grid terstandarisasi: ringkasan rentang data + per-page + paginasi + jump halaman. */
function grid_footer_html(array $p, string $query_base = '', string $entity_label = 'data', array $opts = []): string
{
    $total = (int) ($p['total'] ?? 0);
    $from = (int) ($p['from'] ?? ($total > 0 ? ((int) ($p['offset'] ?? 0) + 1) : 0));
    $to = (int) ($p['to'] ?? ($total > 0 ? min($total, (int) ($p['offset'] ?? 0) + (int) ($p['per_page'] ?? PER_PAGE)) : 0));
    $cur = (int) ($p['current'] ?? 1);
    $total_pages = (int) ($p['total_pages'] ?? 1);

    $summary = $total > 0
        ? 'Menampilkan <strong>' . number_format($from, 0, ',', '.') . '&ndash;' . number_format($to, 0, ',', '.') . '</strong> dari <strong>' . number_format($total, 0, ',', '.') . '</strong> ' . e($entity_label)
        : 'Total <strong>0</strong> ' . e($entity_label);
    $page_meta = $total > 0
        ? '<span class="d-none d-sm-inline text-muted">&bull; Hal. ' . number_format($cur, 0, ',', '.') . ' / ' . number_format($total_pages, 0, ',', '.') . '</span>'
        : '';

    return '<div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">'
        . '<div class="small text-muted d-flex flex-wrap align-items-center gap-2">'
        . '<span>' . $summary . '</span>'
        . $page_meta
        . '</div>'
        . pagination_html($p, $query_base, $opts)
        . '</div>';
}

/**
 * Kelola partisi bulanan tabel audit_trails secara otomatis (PARTITION BY RANGE COLUMNS(created_date)):
 *  1. Membuat partisi bulan berjalan & bulan depan (REORGANIZE PARTITION p_max) jika belum ada.
 *  2. Menghapus instan (DROP PARTITION) partisi bulanan yang umurnya > $retention_months bulan.
 *
 * @return array{partitions: array<int, string>, added: array<int, string>, dropped: array<int, string>}
 */
function audit_maintain_partitions(int $retention_months = 12): array
{
    $result = ['partitions' => [], 'added' => [], 'dropped' => []];
    try {
        $db = db();
        $res = $db->query("SELECT PARTITION_NAME
                           FROM information_schema.PARTITIONS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'audit_trails'
                             AND PARTITION_NAME IS NOT NULL
                           ORDER BY PARTITION_ORDINAL_POSITION");
        if (!$res) {
            return $result;
        }

        $monthly = [];
        $has_pmax = false;
        while ($row = $res->fetch_assoc()) {
            $pname = (string) $row['PARTITION_NAME'];
            if ($pname === 'p_max') {
                $has_pmax = true;
            } elseif (preg_match('/^p_(\d{6})$/', $pname, $m)) {
                $monthly[] = $m[1];
            }
        }
        $res->free();

        if (!$has_pmax && !$monthly) {
            return $result;
        }

        $now = new DateTimeImmutable('first day of this month 00:00:00');
        $target = $now->modify('+1 month');

        // 1. Pastikan partisi sampai bulan depan sudah tersedia (pecah dari p_max)
        if ($has_pmax) {
            $start = $now;
            if ($monthly) {
                $max_ym = max($monthly);
                $max_dt = DateTimeImmutable::createFromFormat('!Ym', $max_ym);
                if ($max_dt instanceof DateTimeImmutable) {
                    $start = $max_dt->modify('+1 month');
                }
            }

            $defs = [];
            $cursor = $start;
            while ($cursor <= $target) {
                $ym = $cursor->format('Ym');
                if (!in_array($ym, $monthly, true)) {
                    $less_than = $cursor->modify('+1 month')->format('Y-m-01 00:00:00');
                    $defs[] = "PARTITION `p_{$ym}` VALUES LESS THAN ('{$less_than}')";
                    $monthly[] = $ym;
                    $result['added'][] = 'p_' . $ym;
                }
                $cursor = $cursor->modify('+1 month');
            }

            if ($defs) {
                $defs[] = 'PARTITION `p_max` VALUES LESS THAN (MAXVALUE)';
                $db->query('ALTER TABLE `audit_trails` REORGANIZE PARTITION `p_max` INTO (' . implode(', ', $defs) . ')');
            }
        }

        // 2. DROP PARTITION untuk partisi yang usianya > $retention_months bulan
        if ($retention_months > 0 && count($monthly) > 1) {
            $cutoff_ym = $now->modify("-{$retention_months} months")->format('Ym');
            $to_drop = [];
            foreach ($monthly as $ym) {
                if ($ym < $cutoff_ym) {
                    $to_drop[] = $ym;
                }
            }
            // Sisakan minimal 1 partisi bulanan selain p_max
            if ($to_drop && count($to_drop) < count($monthly)) {
                $drop_sql = array_map(static fn(string $ym): string => "`p_{$ym}`", $to_drop);
                $db->query('ALTER TABLE `audit_trails` DROP PARTITION ' . implode(', ', $drop_sql));
                foreach ($to_drop as $ym) {
                    $result['dropped'][] = 'p_' . $ym;
                }
                $monthly = array_values(array_diff($monthly, $to_drop));
            }
        }

        sort($monthly);
        $result['partitions'] = array_map(static fn(string $ym): string => 'p_' . $ym, $monthly);
    } catch (Throwable $e) {
        // Jangan gagalkan transaksi utama bila sedang ada DDL konkuren
    }

    return $result;
}

/** Catat aktivitas ke audit_trails (otomatis terpartisi per bulan & per company). */
function audit(string $action, string $description = '', ?int $company_id = null): void
{
    $cur_ym = date('Ym');
    if (($_SESSION['_audit_part_checked'] ?? '') !== $cur_ym) {
        audit_maintain_partitions(12);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_audit_part_checked'] = $cur_ym;
        }
    }

    $uid = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? null;
    $cid = $company_id ?? active_company_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = db()->prepare('INSERT INTO audit_trails (user_id, username, company_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isisss', $uid, $username, $cid, $action, $description, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Render standar breadcrumb navigasi ringkas.
 * $items = ['Label' => 'url', 'Label Aktif' => null]
 */
function ui_breadcrumb(array $items): string
{
    if (empty($items)) {
        return '';
    }
    $html = '<nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb mb-0" style="font-size: 0.8rem;">';
    $keys = array_keys($items);
    $last = end($keys);
    foreach ($items as $label => $url) {
        if ($label === $last || empty($url)) {
            $html .= '<li class="breadcrumb-item active text-body-emphasis" aria-current="page">' . e($label) . '</li>';
        } else {
            $href = str_starts_with($url, 'http') ? $url : APP_URL . '/' . ltrim($url, '/');
            $html .= '<li class="breadcrumb-item"><a href="' . e($href) . '" class="text-decoration-none text-muted">' . e($label) . '</a></li>';
        }
    }
    $html .= '</ol></nav>';
    return $html;
}

/**
 * Render standar baris judul & aksi ringkas (Page Header).
 * $badges: list of ['text' => '...', 'class' => '...'] or plain strings
 * $actions: list of ['label' => '...', 'href' => '...', 'icon' => '...', 'class' => '...']
 */
function ui_page_header(string $title, array $breadcrumb = [], array $badges = [], array $actions = [], string $meta = ''): string
{
    $html = ui_breadcrumb($breadcrumb);
    $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
    $html .= '<div class="d-flex align-items-center gap-2 flex-wrap">';
    $html .= '<h4 class="fw-bold mb-0 text-body-emphasis">' . e($title) . '</h4>';

    foreach ($badges as $b) {
        if (is_string($b) && $b !== '') {
            $html .= '<span class="badge bg-body-secondary text-body-secondary border px-2 py-1">' . e($b) . '</span>';
        } elseif (is_array($b) && !empty($b['text'])) {
            $cls = $b['class'] ?? 'bg-body-secondary text-body-secondary border';
            $html .= '<span class="badge ' . e($cls) . ' px-2 py-1">' . e($b['text']) . '</span>';
        }
    }
    if ($meta !== '') {
        $html .= '<span class="text-muted small ms-1">&bull; ' . $meta . '</span>';
    }
    $html .= '</div>';

    if (!empty($actions)) {
        $html .= '<div class="d-flex flex-wrap align-items-center gap-2">';
        foreach ($actions as $act) {
            if (!empty($act['items']) && is_array($act['items'])) {
                $cls = $act['class'] ?? 'btn btn-sm btn-outline-secondary dropdown-toggle';
                $icon = !empty($act['icon']) ? '<i class="bi bi-' . e($act['icon']) . ' me-1"></i>' : '';
                $html .= '<div class="dropdown">';
                $html .= '<button class="' . e($cls) . '" type="button" data-bs-toggle="dropdown" aria-expanded="false">' . $icon . e($act['label']) . '</button>';
                $html .= '<ul class="dropdown-menu dropdown-menu-end shadow-sm">';
                foreach ($act['items'] as $item) {
                    $ihref = (str_starts_with($item['href'], 'http') || str_starts_with($item['href'], '?'))
                        ? $item['href']
                        : APP_URL . '/' . ltrim($item['href'], '/');
                    $iicon = !empty($item['icon']) ? '<i class="bi bi-' . e($item['icon']) . ' me-2 ' . e($item['icon_class'] ?? '') . '"></i>' : '';
                    $itarget = !empty($item['target']) ? ' target="' . e($item['target']) . '"' . ($item['target'] === '_blank' ? ' rel="noopener"' : '') : '';
                    $html .= '<li><a class="dropdown-item small d-flex align-items-center py-1" href="' . e($ihref) . '"' . $itarget . '>' . $iicon . e($item['label']) . '</a></li>';
                }
                $html .= '</ul></div>';
                continue;
            }
            $cls = $act['class'] ?? 'btn btn-sm btn-outline-secondary';
            $icon = !empty($act['icon']) ? '<i class="bi bi-' . e($act['icon']) . ' me-1"></i>' : '';
            if (!empty($act['type']) && $act['type'] === 'submit') {
                $form_attr = !empty($act['form']) ? ' form="' . e($act['form']) . '"' : '';
                $html .= '<button type="submit"' . $form_attr . ' class="' . e($cls) . '">' . $icon . e($act['label']) . '</button>';
            } else {
                $href = (str_starts_with($act['href'] ?? '#', 'http') || str_starts_with($act['href'] ?? '#', '?'))
                    ? ($act['href'] ?? '#')
                    : APP_URL . '/' . ltrim($act['href'] ?? '#', '/');
                $target = !empty($act['target']) ? ' target="' . e($act['target']) . '"' . ($act['target'] === '_blank' ? ' rel="noopener"' : '') : '';
                $html .= '<a class="' . e($cls) . '" href="' . e($href) . '"' . $target . '>' . $icon . e($act['label']) . '</a>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Render Top Action Toolbar standar ERP detail/form (mengikuti Travel Detail).
 * $badges: list of strings, ['html' => '...'], or ['text' => '...', 'class' => '...']
 * $actions: list of ['label', 'icon', 'href', 'class', 'target', 'type' => 'submit', 'form', 'html']
 */
function ui_erp_toolbar(string $moduleLabel, string $moduleIcon, string $titleText = '', array $badges = [], array $actions = [], string $meta = ''): string
{
    $html = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">';
    $html .= '<div class="d-flex flex-wrap align-items-center gap-2">';
    $iconHtml = $moduleIcon !== '' ? '<i class="bi bi-' . e($moduleIcon) . ' me-1"></i>' : '';
    $html .= '<span class="badge bg-primary text-white">' . $iconHtml . e($moduleLabel) . '</span>';
    if ($titleText !== '') {
        $html .= '<strong class="fs-6">' . e($titleText) . '</strong>';
    }
    foreach ($badges as $b) {
        if (is_array($b) && isset($b['html'])) {
            $html .= (string) $b['html'];
        } elseif (is_string($b) && $b !== '') {
            $html .= '<span class="badge bg-body-secondary text-body-secondary border">' . e($b) . '</span>';
        } elseif (is_array($b) && !empty($b['text'])) {
            $cls = $b['class'] ?? 'bg-body-secondary text-body-secondary border';
            $idAttr = !empty($b['id']) ? ' id="' . e((string) $b['id']) . '"' : '';
            $html .= '<span class="badge ' . e($cls) . '"' . $idAttr . '>' . e($b['text']) . '</span>';
        }
    }
    if ($meta !== '') {
        $html .= '<span class="small text-muted d-none d-md-inline">' . $meta . '</span>';
    }
    $html .= '</div>';

    if (!empty($actions)) {
        $html .= '<div class="d-flex flex-wrap align-items-center gap-2 ms-auto">';
        foreach ($actions as $act) {
            if (!empty($act['html'])) {
                $html .= (string) $act['html'];
                continue;
            }
            $cls = $act['class'] ?? 'btn btn-outline-secondary btn-sm';
            $icon = !empty($act['icon']) ? '<i class="bi bi-' . e($act['icon']) . ' me-1"></i>' : '';
            if (($act['type'] ?? '') === 'submit') {
                $formAttr = !empty($act['form']) ? ' form="' . e((string) $act['form']) . '"' : '';
                $html .= '<button type="submit"' . $formAttr . ' class="' . e($cls) . '">' . $icon . e((string) ($act['label'] ?? 'Simpan')) . '</button>';
            } else {
                $rawHref = (string) ($act['href'] ?? '#');
                $href = (str_starts_with($rawHref, 'http') || str_starts_with($rawHref, '?') || str_starts_with($rawHref, '#'))
                    ? $rawHref
                    : APP_URL . '/' . ltrim($rawHref, '/');
                $targetAttr = !empty($act['target']) ? ' target="' . e((string) $act['target']) . '"' : '';
                $html .= '<a class="' . e($cls) . '" href="' . e($href) . '"' . $targetAttr . '>' . $icon . e((string) ($act['label'] ?? '')) . '</a>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Render satu baris read-only (.rnt-row) dengan input/textarea .form-control-sm.bg-light.
 */
function ui_erp_ro_row(string $label, string|int|float|null $value, bool $multiline = false, bool $mono = false, string $empty = '-'): string
{
    $str = ($value === null || (string) $value === '') ? $empty : (string) $value;
    $monoCls = $mono ? ' font-monospace' : '';
    if ($multiline) {
        return '<div class="rnt-row align-items-start">'
            . '<label class="rnt-label pt-1">' . e($label) . '</label>'
            . '<div class="rnt-field">'
            . '<textarea class="form-control form-control-sm bg-body-secondary rnt-ta-compact' . $monoCls . '" rows="2" readonly>' . e($str) . '</textarea>'
            . '</div></div>';
    }
    return '<div class="rnt-row">'
        . '<label class="rnt-label">' . e($label) . '</label>'
        . '<div class="rnt-field">'
        . '<input type="text" class="form-control form-control-sm bg-body-secondary' . $monoCls . '" value="' . e($str) . '" readonly>'
        . '</div></div>';
}

/**
 * Render satu baris read-only (.rnt-row) berisi HTML (badge, link, gambar/tombol berkas).
 */
function ui_erp_ro_html_row(string $label, string $innerHtml, bool $alignTop = false, bool $asBox = true): string
{
    $rowCls = $alignTop ? 'rnt-row align-items-start' : 'rnt-row';
    $lblCls = $alignTop ? 'rnt-label pt-1' : 'rnt-label';
    $content = $asBox ? '<div class="rnt-ro-value">' . $innerHtml . '</div>' : $innerHtml;
    return '<div class="' . $rowCls . '"><label class="' . $lblCls . '">' . e($label) . '</label><div class="rnt-field">' . $content . '</div></div>';
}

/**
 * Render panel Audit Trail Dokumen standar ERP (.rnt-panel).
 */
function ui_erp_audit_panel(?array $row, string $panelClass = 'mb-0', string $extraRowsHtml = ''): string
{
    $fmtDate = static function ($raw): string {
        if ($raw === null || (string) $raw === '') {
            return '-';
        }
        $ts = strtotime((string) $raw);
        return $ts !== false ? date('d-M-Y H:i', $ts) : (string) $raw;
    };
    $createdUser  = ($row !== null && !empty($row['created_user'])) ? (string) $row['created_user'] : '-';
    $createdDate  = $fmtDate($row['created_date'] ?? null);
    $modifiedUser = ($row !== null && !empty($row['modified_user'])) ? (string) $row['modified_user'] : '-';
    $modifiedDate = $fmtDate($row['modified_date'] ?? null);
    $cls = trim('rnt-panel ' . $panelClass);

    return '<div class="' . e($cls) . '">'
        . '<div class="rnt-panel-header"><span>Audit Trail Dokumen</span></div>'
        . '<div class="rnt-panel-body">'
        . ui_erp_ro_row('Dibuat Oleh', $createdUser)
        . ui_erp_ro_row('Dibuat Tanggal', $createdDate)
        . ui_erp_ro_row('Diubah Oleh', $modifiedUser)
        . ui_erp_ro_row('Diubah Tanggal', $modifiedDate)
        . $extraRowsHtml
        . '</div></div>';
}

/**
 * Daftar bank aktif dari tabel master banks: [['code' => '...', 'name' => '...'], ...]
 */
function master_bank_options(): array
{
    static $banks = null;
    if ($banks !== null) {
        return $banks;
    }
    if (!function_exists('db')) {
        return [];
    }
    try {
        $res = db()->query("SELECT code, name FROM banks ORDER BY name ASC");
        $banks = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        $banks = [];
    }
    return $banks;
}

/**
 * Render uniform Filter (primary + funnel icon) and conditional Reset (outline-danger icon-only) actions.
 */
function ui_filter_actions(
    string $reset_href = '',
    bool $has_active_filter = false,
    string $wrapper_class = 'col-auto d-flex gap-1 ms-auto erp-filter-actions'
): string {
    $html = '<div class="' . e($wrapper_class) . '">';
    $html .= '<button type="submit" class="btn btn-sm btn-primary text-nowrap" title="Terapkan Filter">';
    $html .= '<i class="bi bi-funnel-fill me-1"></i>Filter</button>';
    if ($has_active_filter && $reset_href !== '') {
        $html .= '<a href="' . e($reset_href) . '" class="btn btn-sm btn-outline-danger text-nowrap" title="Reset filter" aria-label="Reset filter">';
        $html .= '<i class="bi bi-x-lg"></i></a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Gabungkan merek dan model unit kendaraan tanpa menduplikasi merek jika nama model sudah diawali merek.
 */
function format_vehicle_brand_model(?string $brand, ?string $model, string $sep = ' '): string
{
    $b = trim((string) $brand);
    $m = trim((string) $model);
    if ($b !== '' && $m !== '' && stripos($m, $b) === 0) {
        return $m;
    }
    if ($b !== '' && $m !== '') {
        return $b . $sep . $m;
    }
    return $b !== '' ? $b : $m;
}

/** Secret HMAC aplikasi (override via env VSUDO_APP_SECRET di production). */
function vsudo_app_secret(string $purpose = 'default'): string
{
    $base = (string) (getenv('VSUDO_APP_SECRET') ?: (DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS . '|vsudotrans-v1'));
    return hash('sha256', $base . '|' . $purpose);
}

/** Token tanda tangan singkat (16 hex) untuk membuka data PII tiket publik. */
function ticket_pii_signature(string $docNo, string $companyCode): string
{
    $payload = strtoupper(trim($docNo)) . '|' . strtoupper(trim($companyCode));
    return substr(hash_hmac('sha256', $payload, vsudo_app_secret('ticket-pii')), 0, 16);
}

/** Masking nama penumpang per kata untuk respons API tiket publik tanpa verifikasi. */
function ticket_mask_name(?string $name): string
{
    $clean = trim(preg_replace('/\s+/', ' ', (string) $name));
    if ($clean === '') {
        return '—';
    }
    $words = explode(' ', $clean);
    $masked = [];
    foreach ($words as $w) {
        $len = mb_strlen($w, 'UTF-8');
        if ($len <= 1) {
            $masked[] = $w;
        } elseif ($len <= 2) {
            $masked[] = mb_substr($w, 0, 1, 'UTF-8') . '*';
        } else {
            $masked[] = mb_substr($w, 0, 2, 'UTF-8') . str_repeat('*', $len - 2);
        }
    }
    return implode(' ', $masked);
}

/** Token HMAC untuk otorisasi polling status QRIS publik (`gateway_status.php`). */
function gateway_poll_token(string $orderId, int $companyId, string $csrfToken): string
{
    $payload = trim($orderId) . '|' . $companyId . '|' . $csrfToken;
    return hash_hmac('sha256', $payload, vsudo_app_secret('gw-poll'));
}

require_once __DIR__ . '/grid_export.php';


