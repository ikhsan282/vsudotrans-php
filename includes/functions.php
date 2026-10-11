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
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $p['total_pages']; $i++) {
        $active = $i === $p['current'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . e($query_base) . '&page=' . $i . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
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
