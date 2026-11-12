<?php
/**
 * Helper umum: CSRF, flash, escape, format, pagination, audit.
 */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
        return (float) $val;
    }
    $str = trim((string) $val);
    if ($str === '') {
        return 0.0;
    }

    // Bersihkan prefix Rp, rp, spasi, dan karakter selain digit, titik, koma
    $str = preg_replace('/[^\d.,]/', '', $str) ?? '';
    if ($str === '') {
        return 0.0;
    }

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
        return (float) $str;
    }

    // Jika hanya mengandung titik (misal: "2.000.000", "150.000", "50.000", "5.000")
    if (str_contains($str, '.')) {
        // Cek jika diakhiri desimal .00 atau .0
        if (preg_match('/^\d+\.(0|00)$/', $str)) {
            return (float) preg_replace('/\.(0|00)$/', '', $str);
        }
        // Dalam konteks rupiah ID, titik adalah pemisah ribuan
        return (float) str_replace('.', '', $str);
    }

    // Jika hanya mengandung koma (misal: "2,000,000" atau "150,000" atau "150000,00")
    if (str_contains($str, ',')) {
        if (preg_match('/^\d+\,(0|00)$/', $str)) {
            return (float) preg_replace('/\,(0|00)$/', '', $str);
        }
        if (preg_match('/,\d{3}/', $str)) {
            return (float) str_replace(',', '', $str);
        }
        return (float) str_replace(',', '.', $str);
    }

    return (float) (preg_replace('/[^\d]/', '', $str) ?: '0');
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

/** Pagination sederhana: kembalikan offset & info halaman. */
function paginate(int $total, int $page = 1, int $per_page = PER_PAGE): array
{
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    return [
        'total' => $total,
        'per_page' => $per_page,
        'current' => $page,
        'total_pages' => $total_pages,
        'offset' => ($page - 1) * $per_page,
    ];
}

function pagination_html(array $p, string $query_base): string
{
    if ($p['total_pages'] <= 1) {
        return '';
    }
    $prefix = $query_base !== '' ? rtrim($query_base, '&') . '&' : '';
    $current = $p['current'];
    $total = $p['total_pages'];

    $link = static function (int $page, string $text, bool $active = false, bool $disabled = false) use ($prefix): string {
        $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
        $href = $disabled ? '#' : '?' . e($prefix) . 'page=' . $page;
        return '<li class="' . $cls . '"><a class="page-link" href="' . $href . '">' . $text . '</a></li>';
    };

    $html = '<nav aria-label="Navigasi Halaman"><ul class="pagination pagination-sm mb-0">';
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
    return $html;
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
            $html .= '<li class="breadcrumb-item active text-dark" aria-current="page">' . e($label) . '</li>';
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
    $html .= '<h4 class="fw-bold mb-0 text-dark">' . e($title) . '</h4>';

    foreach ($badges as $b) {
        if (is_string($b) && $b !== '') {
            $html .= '<span class="badge bg-light text-secondary border px-2 py-1">' . e($b) . '</span>';
        } elseif (is_array($b) && !empty($b['text'])) {
            $cls = $b['class'] ?? 'bg-light text-secondary border';
            $html .= '<span class="badge ' . e($cls) . ' px-2 py-1">' . e($b['text']) . '</span>';
        }
    }
    if ($meta !== '') {
        $html .= '<span class="text-muted small ms-1">&bull; ' . $meta . '</span>';
    }
    $html .= '</div>';

    if (!empty($actions)) {
        $html .= '<div class="d-flex align-items-center gap-2">';
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
                    $html .= '<li><a class="dropdown-item small d-flex align-items-center py-1" href="' . e($ihref) . '">' . $iicon . e($item['label']) . '</a></li>';
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
                $html .= '<a class="' . e($cls) . '" href="' . e($href) . '">' . $icon . e($act['label']) . '</a>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
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

require_once __DIR__ . '/grid_export.php';


