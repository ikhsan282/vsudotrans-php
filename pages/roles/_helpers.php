<?php
/** Helper modul Role & Hak Akses. */

// is_super_admin() di includes/auth.php membandingkan nama role ini, jadi nama tidak boleh diubah.
const ROLE_SUPER_ADMIN = 'Super Admin';

function role_find(int $id): ?array
{
    $st = db()->prepare('SELECT id, name, description, level FROM roles WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function role_is_super(array $role): bool
{
    return $role['name'] === ROLE_SUPER_ADMIN;
}

/** Level role aktor saat ini (angka kecil = lebih tinggi). PHP_INT_MAX = tolak bila tak diketahui. */
function actor_role_level(): int
{
    static $lvl = null;
    if ($lvl === null) {
        $st = db()->prepare('SELECT r.level FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $st->bind_param('i', $_SESSION['user_id']);
        $st->execute();
        $lvl = (int) ($st->get_result()->fetch_row()[0] ?? PHP_INT_MAX);
        $st->close();
    }
    return $lvl;
}

/**
 * Non-Super hanya boleh mengelola role yang levelnya LEBIH RENDAH dari dirinya
 * (angka level lebih besar). Super Admin melewati. Dipanggil sebelum mengubah
 * level/hak akses sebuah role — cegah eskalasi: naikkan level role sendiri lalu
 * tetapkan role tinggi ke diri sendiri (users/form.php membandingkan ke actor level).
 */
function assert_role_manageable(array $role): void
{
    if (!is_super_admin() && (int) $role['level'] <= actor_role_level()) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
}
