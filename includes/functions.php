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
