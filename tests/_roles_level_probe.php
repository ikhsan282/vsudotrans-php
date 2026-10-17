<?php
/**
 * Probe untuk roles_rbac_check.php. Buat fixture aktor sendiri (idempoten),
 * lalu panggil assert_role_manageable() untuk level target.
 * Cetak "ALLOWED" bila lolos; 403 (tanpa ALLOWED) bila ditolak.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/roles/_helpers.php';

$target_level = (int) ($argv[1] ?? 0);
$c = db();
$ROLE = 9903; $USER = 9903;

// Fixture aktor level 3 (idempoten).
$c->query("DELETE FROM users WHERE id = $USER");
$c->query("DELETE FROM roles WHERE id = $ROLE");
$c->query("INSERT INTO roles (id, name, description, level) VALUES ($ROLE, 'CEK-RBAC', 'uji', 3)");
$c->query("INSERT INTO users (id, company_id, role_id, username, password, full_name, email, is_active)
           VALUES ($USER, 1, $ROLE, 'rbac_actor_x', 'x', 'x', 'x@x.test', 1)");

$_SESSION['user_id']   = $USER;
$_SESSION['role_name'] = 'CEK-RBAC';

assert_role_manageable(['name' => 'probe', 'level' => $target_level]);
echo "ALLOWED\n";
