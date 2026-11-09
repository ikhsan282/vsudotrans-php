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
function paginate(int $total, int $page, int $per_page = PER_PAGE): array
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

/** Catat aktivitas ke audit_trails. */
function audit(string $action, string $description = ''): void
{
    $uid = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? null;
    $company_id = active_company_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = db()->prepare('INSERT INTO audit_trails (user_id, username, company_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isisss', $uid, $username, $company_id, $action, $description, $ip);
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
            $cls = $act['class'] ?? 'btn btn-sm btn-outline-secondary';
            $href = str_starts_with($act['href'], 'http') ? $act['href'] : APP_URL . '/' . ltrim($act['href'], '/');
            $icon = !empty($act['icon']) ? '<i class="bi bi-' . e($act['icon']) . ' me-1"></i>' : '';
            $html .= '<a class="' . e($cls) . '" href="' . e($href) . '">' . $icon . e($act['label']) . '</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

