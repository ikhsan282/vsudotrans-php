<?php
/** Utilitas uji: pulihkan password user ke P@ssw0rd. Jalankan: php tests/_reset_admin_password.php */
require __DIR__ . '/../config/config.php';
$db = db();
$h = password_hash('P@ssw0rd', PASSWORD_DEFAULT);
$u = 'admin';
$st = $db->prepare('UPDATE users SET password = ? WHERE username = ?');
$st->bind_param('ss', $h, $u);
$st->execute();
$st->close();
$db->query("DELETE FROM login_attempts WHERE username = 'admin'");
$cur = (string) $db->query("SELECT password FROM users WHERE username = 'admin'")->fetch_row()[0];
echo 'pulih: P@ssw0rd => ', var_export(password_verify('P@ssw0rd', $cur), true), "\n";
