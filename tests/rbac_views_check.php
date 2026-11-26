<?php
/**
 * Self-check pemisahan izin .view per role (kebijakan RBAC seed).
 *   php -c /opt/data/cache/scratch/localdb/php.ini tests/rbac_views_check.php
 *
 * Staff (4) & Viewer (5) hanya boleh modul operasional. Direktori pengguna,
 * keuangan, dan audit harus tertutup.
 */
require_once __DIR__ . '/../config/config.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$has = function (int $role, string $perm) use ($c): bool {
    $st = $c->prepare('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
                       WHERE rp.role_id=? AND p.name=?');
    $st->bind_param('is', $role, $perm);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n > 0;
};

// Tertutup untuk Staff & Viewer
foreach ([4, 5] as $r) {
    foreach (['users.view', 'finance.view', 'audit.view'] as $p) {
        ok(!$has($r, $p), "role $r TIDAK punya $p");
    }
}
// Terbuka untuk Staff & Viewer (operasional)
foreach ([4, 5] as $r) {
    foreach (['travel.view', 'rental.view', 'vehicles.view', 'reports.view'] as $p) {
        ok($has($r, $p), "role $r punya $p");
    }
}
// HRD & Admin tetap punya hr.view
foreach ([2, 3] as $r) {
    ok($has($r, 'hr.view'), "role $r punya hr.view");
}
ok($has(2, 'finance.view'), 'Admin Perusahaan punya finance.view');
ok($has(1, 'finance.view') && $has(1, 'hr.view'), 'Super Admin punya finance & hr');

$payrollPerms = (int) $c->query("SELECT COUNT(*) FROM permissions WHERE module = 'payroll' OR name IN ('payroll.view', 'payroll.manage')")->fetch_row()[0];
ok($payrollPerms === 0, 'Permission payroll.view & payroll.manage sudah dihapus dari tabel permissions');

echo $fails === 0 ? "\nSEMUA LULUS\n" : "\n$fails GAGAL\n";
exit($fails === 0 ? 0 : 1);
