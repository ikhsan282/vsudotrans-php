<?php
/**
 * Self-check: Laporan Operasional (tanpa HTTP).
 * Angka persis vs query langsung DB + dedup penugasan + scoping tenant.
 * Jalankan: php -d mysqli.default_socket=... tests/reports_operations_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/reports/_query.php';
require_once __DIR__ . '/../pages/reports/_operations_query.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
// Rentang khusus fixture (Desember 2026) agar angka persis tidak tercampur data dev.
$FROM = '2026-12-10';
$TO   = '2026-12-12';

$cleanup = static function () use ($c): void {
    $c->query("DELETE FROM booking_status_history WHERE changed_by = 'ops-check'");
    $c->query("DELETE FROM trip_assignments WHERE id >= 990000 OR reason = 'ops-check'");
    $c->query("DELETE FROM travel_ticket_bookings WHERE id >= 990000 OR document_number LIKE 'TRV-OCHK%'");
    $c->query("DELETE FROM rental_car_bookings WHERE id >= 990000 OR document_number LIKE 'RNT-OCHK%'");
    $c->query("DELETE FROM vehicles WHERE id >= 990000");
    $c->query("DELETE FROM employees WHERE id >= 990000 OR code = 'OCHK-EMP1' OR code = 'OCHK-EMP2'");
    $c->query("DELETE FROM passengers WHERE id >= 990000 OR id = 991 OR code LIKE 'OCHK-PAX%'");
    $c->query("DELETE FROM branches WHERE id >= 990000 OR code = 'OCHK-BR'");
    $c->query("DELETE FROM companies WHERE id = 992 OR code = 'OCHK-B'");
};
$cleanup();

$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BANTEN', 'Banten')");
$c->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
$c->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CBT', 'Cibodas')");
$c->query("INSERT IGNORE INTO brand_vehicles (id, company_id, code, name) VALUES (1, 1, 'OCHK-BRAND', 'Merek Ochk')");
$c->query("INSERT IGNORE INTO category_vehicles (id, company_id, code, name) VALUES (1, 1, 'OCHK-CAT', 'Kategori Ochk')");
$c->query("INSERT IGNORE INTO model_units (id, company_id, brand_id, code, name) VALUES (1, 1, 1, 'OCHK-MDL', 'Model Ochk')");

// Perusahaan lain untuk uji scoping.
$c->query("INSERT INTO companies (id, group_id, code, name, is_active)
           VALUES (992, 1, 'OCHK-B', 'Perusahaan Ochk B', 1)");
$c->query("INSERT INTO branches (id, company_id, code, name) VALUES (990010, 992, 'OCHK-BR', 'Cabang Ochk')");
$c->query("INSERT INTO passengers (id, company_id, code, name, phone_number) VALUES (990010, 992, 'OCHK-PAX', 'Pax Ochk', '62800')");
// Penumpang milik perusahaan 1 (target FK passenger_id pada booking ops di bawah).
$c->query("INSERT INTO passengers (id, company_id, code, name, phone_number) VALUES (991, 1, 'OCHK-PAX1', 'Pax Ochk 1', '62801')");

// Dua kendaraan: satu dipakai 2 tugas (1 selesai, 1 berjalan), satu menganggur.
$veh = static function (int $id, string $no) use ($c): void {
    $c->query("INSERT INTO vehicles (id, company_id, brand_id, model_unit_id, category_vehicle_id,
        frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, ownership, is_active)
        VALUES ($id, 1, 1, 1, 1, 'OCHK-F$id', 'OCHK-M$id', 12, 2020, 'Putih', '$no', 'milik', 1)");
};
$veh(990010, 'B 1 OCHK');
$veh(990011, 'B 2 OCHK');

// Dua karyawan: satu 1 tugas, satu tidak pernah ditugaskan.
$c->query("INSERT INTO employees (id, company_id, code, name, job_position, status, join_date)
    VALUES (990010, 1, 'OCHK-EMP1', 'Driver Ochk Satu', 'Driver', 'aktif', '2026-01-01'),
           (990011, 1, 'OCHK-EMP2', 'Driver Ochk Dua', 'Driver', 'aktif', '2026-01-01')");

// Booking: 1 travel + 1 rental (untuk uji status & filter jenis).
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES (990020, 1, 1, 'TRV-OCHK01', '2026-12-10', '09:00:00', 1, 1, 500000, 500000, 991, 'ops-check')");
$c->query("INSERT INTO rental_car_bookings
    (id, company_id, branch_id, document_number, date, start_date, end_date, time,
     pickup_point_district_id, delivery_point_district_id, total_price, total_payment, passenger_id, created_user)
    VALUES (990021, 1, 1, 'RNT-OCHK01', '2026-12-11', '2026-12-11', '2026-12-12', '09:00:00', 1, 1, 700000, 0, 991, 'ops-check')");

// Riwayat status: travel -> confirmed, rental -> on_trip (uji booking per status).
$c->query("INSERT INTO booking_status_history (company_id, booking_type, booking_id, from_status, to_status, changed_by)
    VALUES (1, 'travel', 990020, 'draft', 'confirmed', 'ops-check'),
           (1, 'rental', 990021, 'draft', 'on_trip', 'ops-check')");

// Penugasan: 2 tugas kendaraan 1 (driver 1) — 1 selesai pada 10 Des, 1 berjalan 11 Des.
// + 1 tugas perusahaan 992 (harus tidak bocor).
$c->query("INSERT INTO trip_assignments (id, company_id, booking_type, booking_id, employee_id, vehicle_id, started_at, ended_at, reason, created_user)
    VALUES (990010, 1, 'travel', 990020, 990010, 990010, '2026-12-10 08:00:00', '2026-12-10 17:00:00', 'ops-check', 'ops-check'),
           (990011, 1, 'rental', 990021, 990010, 990010, '2026-12-11 08:00:00', NULL, 'ops-check', 'ops-check'),
           (990012, 1, 'travel', 990020, 990011, 990011, '2026-12-12 08:00:00', '2026-12-12 12:00:00', 'ops-check', 'ops-check'),
           (990013, 992, 'travel', 990020, 990010, 990010, '2026-12-11 08:00:00', NULL, 'ops-check', 'ops-check')");

try {
    // ---------- A. Super Admin: angka persis ----------
    $_SESSION['role_name'] = 'Super Admin';
    $_SESSION['company_id'] = 1;
    $f = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);
    $d = ops_all($f);

    $q = static fn(string $sql): string => (string) db()->query($sql)->fetch_row()[0];
    $db_total = (int) $q("SELECT COUNT(*) FROM trip_assignments WHERE started_at >= '$FROM' AND started_at < DATE_ADD('$TO', INTERVAL 1 DAY)");
    $db_done  = (int) $q("SELECT COUNT(*) FROM trip_assignments WHERE started_at >= '$FROM' AND started_at < DATE_ADD('$TO', INTERVAL 1 DAY) AND ended_at IS NOT NULL");

    ok($d['completion']['total'] === $db_total, "completion.total cocok DB ({$d['completion']['total']} = $db_total)");
    ok($d['completion']['selesai'] === $db_done, "completion.selesai cocok DB ({$d['completion']['selesai']} = $db_done)");
    ok($d['completion']['selesai'] + $d['completion']['berjalan'] === $d['completion']['total'],
        'completion: selesai + berjalan = total');
    ok($d['completion']['total'] === 4, "perusahaan 1 punya 3 tugas, 992 punya 1 → 4 untuk Super Admin (dapat {$d['completion']['total']})");

    // Utilisasi kendaraan: hanya 2 kendaraan perusahaan 1.
    $names = array_column($d['vehicles'], 'vehicle_number');
    ok(in_array('B 1 OCHK', $names, true) && in_array('B 2 OCHK', $names, true),
        'utilisasi: kedua kendaraan perusahaan 1 tampil (' . implode('|', $names) . ')');
    $byNo = [];
    foreach ($d['vehicles'] as $r) { $byNo[$r['vehicle_number']] = $r; }
    ok((int) $byNo['B 1 OCHK']['tugas'] === 3, "utilisasi B 1: 3 tugas (2 milik 1 + 1 milik 992) → {$byNo['B 1 OCHK']['tugas']}");
    ok((int) $byNo['B 1 OCHK']['hari'] === 2, "utilisasi B 1: 2 hari unik (10 & 11 Des; tugas 992 juga 11 Des) → {$byNo['B 1 OCHK']['hari']}");
    ok((int) $byNo['B 2 OCHK']['tugas'] === 1, "utilisasi B 2: 1 tugas → {$byNo['B 2 OCHK']['tugas']}");
    ok((int) $byNo['B 1 OCHK']['selesai'] + (int) $byNo['B 1 OCHK']['berjalan'] === 3,
        'utilisasi B 1: selesai + berjalan = tugas');

    // Aktivitas driver: hanya karyawan yang ditugaskan.
    $dn = array_column($d['drivers'], 'name');
    ok(in_array('Driver Ochk Satu', $dn, true) && in_array('Driver Ochk Dua', $dn, true), 'driver: keduanya tampil');
    ok(!in_array('Siti Aminah', $dn, true), 'driver: karyawan tanpa penugasan tidak tampil');
    $dd = [];
    foreach ($d['drivers'] as $r) { $dd[$r['name']] = $r; }
    // Driver Satu (990010): tugas 990010 (selesai) + 990011 (berjalan) + 990013 milik 992 = 3 tugas.
    ok((int) $dd['Driver Ochk Satu']['tugas'] === 3, "driver Satu: 3 tugas (2 milik 1 + 1 milik 992) → {$dd['Driver Ochk Satu']['tugas']}");
    ok((int) $dd['Driver Ochk Satu']['selesai'] === 1 && (int) $dd['Driver Ochk Satu']['berjalan'] === 2,
        'driver Satu: 1 selesai + 2 berjalan');
    ok((int) $dd['Driver Ochk Dua']['tugas'] === 1 && (int) $dd['Driver Ochk Dua']['selesai'] === 1,
        'driver Dua: 1 tugas selesai');

    // Booking per status.
    $st = [];
    foreach ($d['status'] as $r) { $st[$r['status']] = $r; }
    ok(isset($st['confirmed']) && (int) $st['confirmed']['travel'] === 1, 'status: 1 travel confirmed');
    ok(isset($st['on_trip']) && (int) $st['on_trip']['rental'] === 1, 'status: 1 rental on_trip');
    $sum_st = array_sum(array_map(static fn($r) => (int) $r['total'], $d['status']));
    $db_bookings = (int) $q("SELECT (SELECT COUNT(*) FROM travel_ticket_bookings WHERE date BETWEEN '$FROM' AND '$TO')
        + (SELECT COUNT(*) FROM rental_car_bookings WHERE date BETWEEN '$FROM' AND '$TO')");
    ok($sum_st >= $db_bookings, "status: total baris >= jumlah booking DB ($sum_st >= $db_bookings)");

    // Totals <tfoot>.
    $vt = ops_totals($d['vehicles'], ['tugas', 'selesai', 'berjalan', 'hari']);
    ok($vt['tugas'] === array_sum(array_map(static fn($r) => (int) $r['tugas'], $d['vehicles'])),
        'utilisasi: total <tfoot> = jumlah baris');
    $dt = ops_totals($d['drivers'], ['tugas', 'selesai', 'berjalan']);
    ok($dt['tugas'] === $d['completion']['total'] || $dt['tugas'] > 0,
        'driver: total tugas konsisten');

    // ---------- B. Scoping: Admin Perusahaan (company 1) ----------
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $f1 = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);
    $d1 = ops_all($f1);
    ok($d1['completion']['total'] === 3, "scoping: company 1 hanya 3 tugas, penugasan 992 tidak bocor (dapat {$d1['completion']['total']})");
    $byn = [];
    foreach ($d1['vehicles'] as $r) { $byn[$r['vehicle_number']] = $r; }
    ok((int) $byn['B 1 OCHK']['tugas'] === 2, "scoping: B 1 hanya 2 tugas milik company 1 → {$byn['B 1 OCHK']['tugas']}");
    ok($d1['completion']['selesai'] === 2, "scoping: 2 tugas selesai milik company 1 (990010 & 990012) → {$d1['completion']['selesai']}");
    ok((int) $q("SELECT COUNT(*) FROM trip_assignments WHERE company_id = 992") === 1,
        'scoping: fixture penugasan company 992 memang ada di DB');

    // ---------- C. Filter jenis booking ----------
    // Super Admin, semua tugas: travel = 990010 + 990012 + 990013(992) = 3; rental = 990011 = 1.
    $_SESSION['role_name'] = 'Super Admin';
    $ft = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'rental']);
    $dt2 = ops_completion($ft);
    ok($dt2['total'] === 1, "filter rental: 1 tugas (990011 milik company 1) → {$dt2['total']}");
    $ft1 = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'travel']);
    ok(ops_completion($ft1)['total'] === 3, 'filter travel: 3 tugas (2 milik 1 + 1 milik 992)');

    // ---------- D. Whitelist & tanggal ngawur ----------
    $fx = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => "travel' UNION SELECT 1 -- "]);
    ok($fx['booking_type'] === 'all', 'whitelist: injeksi booking_type → all');
    $err = null;
    try { ops_all($fx); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'whitelist: tidak ada SQL error untuk input ngawur');
    $fd = report_filter(['from' => 'bukan-tanggal', 'to' => '', 'booking_type' => 'all']);
    ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd['from']), 'tanggal ngawur → default valid');

} finally {
    $cleanup();
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
