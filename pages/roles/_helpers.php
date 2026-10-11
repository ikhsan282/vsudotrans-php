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
