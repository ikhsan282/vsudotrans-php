<?php
/**
 * Autentikasi, RBAC, dan tenant scoping (multi-company).
 *
 * Model:
 *  - users.company_id  = perusahaan aktif user (NULL = lintas perusahaan, hanya Super Admin)
 *  - user_companies    = daftar perusahaan yang boleh diakses user
 *  - role_permissions  = izin dasar dari role; user_permissions = tambahan per user
 */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function auth_check(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('pages/auth/login.php');
    }
}

function require_login(): void
{
    auth_check();
}

function is_super_admin(): bool
{
    return ($_SESSION['role_name'] ?? '') === 'Super Admin';
}

/** Perusahaan aktif user saat ini; NULL = lintas perusahaan (Super Admin). */
function active_company_id(): ?int
{
    $id = $_SESSION['company_id'] ?? null;
    return $id === null ? null : (int) $id;
}

/** Izin user: dari role + override per user. Super Admin selalu lolos. */
function can(string $permission): bool
{
    if (is_super_admin()) {
        return true;
    }
    return in_array($permission, $_SESSION['permissions'] ?? [], true);
}

function require_permission(string $permission): void
{
    auth_check();
    if (!can($permission)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/**
 * Pastikan record milik perusahaan aktif. Dipanggil sebelum membaca/mengubah
 * satu baris (detail, edit, delete). Super Admin melewati pengecekan.
 */
function assert_company_access(?int $record_company_id): void
{
    if (is_super_admin()) {
        return;
    }
    if ($record_company_id === null || $record_company_id !== active_company_id()) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/** Klausa WHERE untuk scoping list. Mengembalikan [sql, types, params]. */
function company_scope(string $column = 'company_id'): array
{
    if (is_super_admin()) {
        return ['1=1', '', []];
    }
    $cid = active_company_id();
    if ($cid === null) {
        return ['1=0', '', []]; // user tanpa perusahaan: tidak melihat apa pun
    }
    return ["{$column} = ?", 'i', [$cid]];
}

/**
 * Login: cek throttle, verifikasi password, muat izin & perusahaan ke session.
 * Mengembalikan null jika berhasil, atau pesan error.
 */
function login_user(string $username, string $password): ?string
{
    $username = strtolower(trim($username));
    $username = substr($username, 0, 100);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (login_is_locked($ip, $username)) {
        return 'Terlalu banyak percobaan gagal. Coba lagi beberapa menit lagi.';
    }

    $db = db();
    $stmt = $db->prepare('SELECT u.id, u.username, u.password, u.full_name, u.is_active, u.company_id, r.name AS role_name
                          FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password']) || !$user['is_active']) {
        record_login_attempt($ip, $username, false);
        return 'Username atau password salah.';
    }

    record_login_attempt($ip, $username, true);
    clear_login_attempts($ip, $username);

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['user']      = ['id' => (int) $user['id'], 'name' => $user['full_name']];
    $_SESSION['role_name'] = $user['role_name'];
    $_SESSION['company_id'] = $user['company_id'] !== null ? (int) $user['company_id'] : null;
    $_SESSION['permissions'] = load_permissions((int) $user['id'], $user['role_name']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db()->query('UPDATE users SET last_login = NOW() WHERE id = ' . (int) $user['id']);
    audit('login', 'Login berhasil');
    return null;
}

function logout_user(): void
{
    audit('logout', 'Logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Izin = izin role + override user. */
function load_permissions(int $user_id, string $role_name): array
{
    $db = db();
    $stmt = $db->prepare('SELECT p.name FROM permissions p
        JOIN role_permissions rp ON rp.permission_id = p.id
        JOIN roles r ON r.id = rp.role_id
        WHERE r.name = ?
        UNION
        SELECT p.name FROM permissions p
        JOIN user_permissions up ON up.permission_id = p.id
        WHERE up.user_id = ?');
    $stmt->bind_param('si', $role_name, $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_column($rows, 'name');
}

/** Ganti perusahaan aktif; hanya boleh ke perusahaan yang ada di user_companies. */
function switch_company(int $company_id): bool
{
    if (is_super_admin()) {
        $_SESSION['company_id'] = $company_id;
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ?');
    $uid = (int) $_SESSION['user_id'];
    $stmt->bind_param('ii', $uid, $company_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($ok) {
        $_SESSION['company_id'] = $company_id;
    }
    return $ok;
}

/** Perusahaan yang boleh dipilih user (untuk dropdown switcher). */
function accessible_companies(): array
{
    if (is_super_admin()) {
        return db()->query('SELECT id, code, name FROM companies WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    }
    $stmt = db()->prepare('SELECT c.id, c.code, c.name FROM companies c
        JOIN user_companies uc ON uc.company_id = c.id WHERE uc.user_id = ? AND c.is_active = 1 ORDER BY c.name');
    $uid = (int) $_SESSION['user_id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ---------------------------------------------------------------------
// Login throttling (per akun + per IP), berbasis waktu MySQL
// ---------------------------------------------------------------------

function login_is_locked(string $ip, string $username): bool
{
    $stmt = db()->prepare('SELECT
        (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND username = ? AND is_success = 0
            AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)) AS per_account,
        (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND is_success = 0
            AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)) AS per_ip');
    $window = LOGIN_LOCKOUT_TIME;
    $stmt->bind_param('ssisi', $ip, $username, $window, $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['per_account'] >= LOGIN_MAX_ATTEMPTS || $row['per_ip'] >= LOGIN_MAX_IP_ATTEMPTS;
}

function record_login_attempt(string $ip, string $username, bool $success): void
{
    $flag = $success ? 1 : 0;
    $stmt = db()->prepare('INSERT INTO login_attempts (ip_address, username, is_success) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $ip, $username, $flag);
    $stmt->execute();
    $stmt->close();
    // Pembersihan probabilistik: tidak perlu query cleanup di setiap request.
    if (random_int(1, 100) === 1) {
        db()->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function clear_login_attempts(string $ip, string $username): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND username = ?');
    $stmt->bind_param('ss', $ip, $username);
    $stmt->execute();
    $stmt->close();
}
