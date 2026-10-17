<?php
/**
 * Self-check: modul Payroll (tanpa HTTP).
 * Butuh DB dev. Jalankan: php -d mysqli.default_socket=... tests/payroll_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/payroll_service.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$cid = (int) $c->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetch_row()[0];
$emp_total = (int) $c->query("SELECT COUNT(*) FROM employees WHERE company_id = $cid AND status = 'aktif'")->fetch_row()[0];
if ($emp_total === 0) { echo "skip: tidak ada karyawan aktif\n"; exit(0); }

// Periode uji: tahun jauh di depan supaya tidak bertabrakan dengan data nyata.
$YEAR = 2099;
$c->query("DELETE i FROM payroll_items i JOIN payroll_periods p ON p.id = i.payroll_period_id WHERE p.company_id = $cid AND p.period_year = $YEAR");
$c->query("DELETE FROM payroll_periods WHERE company_id = $cid AND period_year = $YEAR");

// 1. Generate periode sukses.
$g = payroll_generate($cid, $YEAR, 1, 'test');
$pid = (int) $g['period_id'];
ok($g['items'] === $emp_total, "generate: item = $emp_total karyawan aktif (dapat {$g['items']})");
$st = $c->prepare('SELECT COUNT(*) FROM payroll_items WHERE payroll_period_id = ?');
$st->bind_param('i', $pid);
$st->execute();
$n = (int) $st->get_result()->fetch_row()[0];
$st->close();
ok($n === $emp_total, "baris payroll_items = $n (harus $emp_total)");

$found = payroll_period_find($pid);
ok($found !== null && $found['status'] === 'draft', 'periode baru berstatus draft');
ok((int) $found['item_count'] === $emp_total, "period_find melaporkan item_count = {$found['item_count']}");

// 2. Generate periode yang sama 2x -> throw.
$thrown = false;
try { payroll_generate($cid, $YEAR, 1, 'test'); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'generate periode duplikat ditolak (throw)');
$st = $c->prepare('SELECT COUNT(*) FROM payroll_periods WHERE company_id = ? AND period_year = ? AND period_month = 1');
$st->bind_param('ii', $cid, $YEAR);
$st->execute();
$dup = (int) $st->get_result()->fetch_row()[0];
$st->close();
ok($dup === 1, 'tidak ada periode ganda setelah penolakan');

// 3. Hitung net_salary: basic + (daily * hari hadir) - potongan, dibulatkan 2 desimal.
$items = payroll_items($pid);
$it = $items[0];
$iid = (int) $it['id'];
$basic = (float) $it['basic_salary'];
$daily = (float) $it['daily_allowance'];
$r = payroll_item_update($pid, $cid, $iid, 22, 125000.55, 'uji hitung', 'test');
$expect = round($basic + ($daily * 22) - 125000.55, 2);
ok(abs($r['net_salary'] - $expect) < 0.001, "net_salary = {$r['net_salary']} (harus $expect dari pokok $basic + harian $daily x 22 - 125000.55)");
$st = $c->prepare('SELECT days_present, deductions, net_salary, note FROM payroll_items WHERE id = ?');
$st->bind_param('i', $iid);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
ok((int) $row['days_present'] === 22, 'hari hadir tersimpan');
ok(abs((float) $row['deductions'] - 125000.55) < 0.001, 'potongan tersimpan');
ok(abs((float) $row['net_salary'] - $expect) < 0.001, 'net_salary tersimpan persis di DB');
ok($row['note'] === 'uji hitung', 'catatan item tersimpan');

// Potongan tidak boleh negatif.
$thrown = false;
try { payroll_item_update($pid, $cid, $iid, 22, -1, null, 'test'); }
catch (InvalidArgumentException $e) { $thrown = true; }
ok($thrown, 'potongan negatif ditolak');

// 4. Transisi status: draft -> final -> paid OK, lalu paid -> final ditolak.
payroll_period_set_status($pid, $cid, 'final', 'test');
ok(payroll_period_find($pid)['status'] === 'final', 'draft -> final OK');
payroll_period_set_status($pid, $cid, 'paid', 'test');
ok(payroll_period_find($pid)['status'] === 'paid', 'final -> paid OK');

$thrown = false;
try { payroll_period_set_status($pid, $cid, 'final', 'test'); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'paid -> final ditolak (tanpa transisi mundur)');

$thrown = false;
try { payroll_period_set_status($pid, $cid, 'draft', 'test'); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'paid -> draft ditolak');

// 5. Edit item setelah periode 'paid' -> throw.
$thrown = false;
try { payroll_item_update($pid, $cid, $iid, 10, 0, null, 'test'); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, "edit item setelah status 'paid' ditolak (throw)");

// 6. Scope tenant: periode milik perusahaan lain ditolak.
$thrown = false;
try { payroll_item_update($pid, $cid + 999, $iid, 10, 0, null, 'test'); }
catch (RuntimeException $e) { $thrown = true; }
ok($thrown, 'periode perusahaan lain ditolak (scope company_id)');

// 7. RBAC: nama role di DB = display name, jadi filter pakai r.id.
$has = function (int $role_id, string $perm) use ($c): bool {
    $st = $c->prepare('SELECT 1 FROM role_permissions rp
        JOIN permissions p ON p.id = rp.permission_id
        JOIN roles r ON r.id = rp.role_id
        WHERE r.id = ? AND p.name = ?');
    $st->bind_param('is', $role_id, $perm);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
};
ok($has(1, 'payroll.view'), 'Super Admin (role 1) punya payroll.view');
ok($has(1, 'payroll.manage'), 'Super Admin (role 1) punya payroll.manage');
ok($has(3, 'payroll.view'), 'HRD (role 3) punya payroll.view');
ok($has(3, 'payroll.manage'), 'HRD (role 3) punya payroll.manage');
ok(!$has(4, 'payroll.manage'), 'Staff (role 4) TIDAK punya payroll.manage');
ok(!$has(5, 'payroll.manage'), 'Viewer (role 5) TIDAK punya payroll.manage');
// Gaji = data sensitif: Staff/Viewer tidak boleh melihat payroll sama sekali
// (detail periode memuat nominal gaji per karyawan).
ok(!$has(4, 'payroll.view') && !$has(5, 'payroll.view'), 'Staff & Viewer TIDAK punya payroll.view (nominal gaji tertutup)');

// 8. Bersihkan data uji (cascade menghapus item).
$c->query("DELETE FROM payroll_periods WHERE company_id = $cid AND period_year = $YEAR");
$st = $c->prepare('SELECT COUNT(*) FROM payroll_items WHERE payroll_period_id = ?');
$st->bind_param('i', $pid);
$st->execute();
$left = (int) $st->get_result()->fetch_row()[0];
$st->close();
ok($left === 0, 'hapus periode ikut menghapus itemnya (ON DELETE CASCADE)');

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
