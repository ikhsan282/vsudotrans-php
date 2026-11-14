<?php
/**
 * Self-check: Laporan Penjadwalan Driver (tanpa HTTP).
 * Fixture: 1 driver + penugasan company 1 + penugasan company 990 (tenant lain)
 * → assert scoping (tidak bocor 990) + angka ringkasan/tabel persis.
 * Jalankan: php -d mysqli.default_socket=... tests/dispatch_report_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/reports/_query.php';
require_once __DIR__ . '/../pages/reports/_dispatch_query.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
// Rentang khusus fixture (Desember 2026) agar angka persis tidak tercampur data dev.
$FROM = '2026-12-10';
$TO   = '2026-12-12';

$cleanup = static function () use ($c): void {
    $c->query("DELETE FROM trip_assignments WHERE id >= 990000 OR reason = 'drp-check'");
    $c->query("DELETE FROM travel_ticket_bookings WHERE id >= 990000 OR document_number LIKE 'TRV-DRCHK%'");
    $c->query("DELETE FROM rental_car_bookings WHERE id >= 990000 OR document_number LIKE 'RNT-DRCHK%'");
    $c->query("DELETE FROM vehicles WHERE id >= 990000");
    $c->query("DELETE FROM employees WHERE id >= 990000 OR code LIKE 'DRCHK-EMP%'");
    $c->query("DELETE FROM passengers WHERE id >= 990000");
    $c->query("DELETE FROM branches WHERE id >= 990000 OR code = 'DRCHK-BR'");
    // Master kendaraan harus turun sebelum brand (FK model_units.brand_id → brand_vehicles.id).
    $c->query("DELETE FROM model_units WHERE id = 2 AND code = 'DRCHK-MDL'");
    $c->query("DELETE FROM brand_vehicles WHERE id = 2 AND code = 'DRCHK-BRAND'");
    $c->query("DELETE FROM category_vehicles WHERE id = 2 AND code = 'DRCHK-CAT'");
    $c->query("DELETE FROM companies WHERE id = 990 OR code = 'DRCHK-A'");
};
$cleanup();

$c->query("INSERT IGNORE INTO brand_vehicles (id, company_id, code, name) VALUES (2, 1, 'DRCHK-BRAND', 'Merek Drchk')");
$c->query("INSERT IGNORE INTO category_vehicles (id, company_id, code, name) VALUES (2, 1, 'DRCHK-CAT', 'Kategori Drchk')");
$c->query("INSERT IGNORE INTO model_units (id, company_id, brand_id, code, name) VALUES (2, 1, 2, 'DRCHK-MDL', 'Model Drchk')");

// Perusahaan lain (990) untuk uji scoping.
$c->query("INSERT INTO companies (id, group_id, code, name, is_active)
           VALUES (990, 1, 'DRCHK-A', 'Perusahaan Drchk A', 1)");
$c->query("INSERT INTO branches (id, company_id, code, name) VALUES (990000, 990, 'DRCHK-BR', 'Cabang Drchk')");
$c->query("INSERT INTO passengers (id, company_id, code, name, phone_number) VALUES (990000, 990, 'DRCHK-PAX', 'Pax Drchk', '62800')");

// Kendaraan company 1 + company 990.
$veh = static function (int $id, int $cid, string $no) use ($c): void {
    $c->query("INSERT INTO vehicles (id, company_id, brand_id, model_unit_id, category_vehicle_id,
        frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, ownership, is_active)
        VALUES ($id, $cid, 2, 2, 2, 'DRCHK-F$id', 'DRCHK-M$id', 12, 2021, 'Putih', '$no', 'milik', 1)");
};
$veh(990000, 1, 'D 1 DRCHK');
$veh(990001, 990, 'D 2 DRCHK');

// Dua driver di company 1: satu 2 tugas (1 selesai + 1 berjalan, 2 hari), satu 1 tugas.
$c->query("INSERT INTO employees (id, company_id, code, name, job_position, status, join_date)
    VALUES (990000, 1, 'DRCHK-EMP1', 'Driver Drchk Satu', 'Driver', 'aktif', '2026-01-01'),
           (990001, 1, 'DRCHK-EMP0', 'Driver Drchk Dua', 'Driver', 'aktif', '2026-01-01')");

// Booking company 1 (travel + rental) untuk nomor dokumen & per tanggal.
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES (990000, 1, 1, 'TRV-DRCHK01', '2026-12-10', '09:00:00', 1, 1, 300000, 300000, 990000, 'drp-check')");
$c->query("INSERT INTO rental_car_bookings
    (id, company_id, branch_id, document_number, date, start_date, end_date, time,
     pickup_point_district_id, delivery_point_district_id, total_price, total_payment, passenger_id, created_user)
    VALUES (990001, 1, 1, 'RNT-DRCHK01', '2026-12-12', '2026-12-12', '2026-12-13', '09:00:00', 1, 1, 600000, 0, 990000, 'drp-check')");

// Booking company 990 (tenant lain) untuk penugasan tenant lain.
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES (990002, 990, 990000, 'TRV-DRCHK99', '2026-12-11', '09:00:00', 1, 1, 100000, 100000, 990000, 'drp-check')");

// Penugasan: dua tugas company 1 (travel selesai 10 Des + rental berjalan 12 Des)
// + 1 tugas company 990 (harus tidak bocor ke laporan company 1).
$c->query("INSERT INTO trip_assignments (id, company_id, booking_type, booking_id, employee_id, vehicle_id, started_at, ended_at, reason, created_user)
    VALUES (990000, 1, 'travel', 990000, 990000, 990000, '2026-12-10 08:00:00', '2026-12-10 17:00:00', 'drp-check', 'drp-check'),
           (990001, 1, 'rental', 990001, 990000, 990000, '2026-12-12 08:00:00', NULL, 'drp-check', 'drp-check'),
           (990002, 1, 'travel', 990000, 990001, 990000, '2026-12-10 08:30:00', '2026-12-10 16:00:00', 'drp-check', 'drp-check'),
           (990003, 990, 'travel', 990002, 990000, 990001, '2026-12-11 08:00:00', NULL, 'drp-check', 'drp-check')");

try {
    // ---------- A. Angka persis (Super Admin: lintas tenant) ----------
    $_SESSION['role_name'] = 'Super Admin';
    $_SESSION['company_id'] = 1;
    $f = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);
    $d = dispatch_all($f);

    $q = static fn(string $sql): string => (string) $c->query($sql)->fetch_row()[0];
    $db_total = (int) $q("SELECT COUNT(*) FROM trip_assignments WHERE started_at >= '$FROM' AND started_at < DATE_ADD('$TO', INTERVAL 1 DAY)");
    $db_done  = (int) $q("SELECT COUNT(*) FROM trip_assignments WHERE started_at >= '$FROM' AND started_at < DATE_ADD('$TO', INTERVAL 1 DAY) AND ended_at IS NOT NULL");

    ok($d['summary']['total'] === $db_total, "summary.total cocok DB ({$d['summary']['total']} = $db_total)");
    ok($d['summary']['selesai'] === $db_done, "summary.selesai cocok DB ({$d['summary']['selesai']} = $db_done)");
    ok($d['summary']['selesai'] + $d['summary']['berjalan'] === $d['summary']['total'],
        'summary: selesai + berjalan = total');

    // ---------- B. Scoping: Admin Perusahaan (company 1) ----------
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $f1 = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);
    $d1 = dispatch_all($f1);

    ok($d1['summary']['total'] === 3, "scoping: company 1 punya 3 tugas, tugas 990 tidak bocor (dapat {$d1['summary']['total']})");
    ok($d1['summary']['selesai'] === 2 && $d1['summary']['berjalan'] === 1, 'scoping: 2 selesai + 1 berjalan');
    ok($d1['summary']['driver'] === 2, "scoping: 2 driver aktif (dapat {$d1['summary']['driver']})");

    // Beban kerja driver.
    $drv = [];
    foreach ($d1['drivers'] as $r) { $drv[$r['name']] = $r; }
    ok(count($d1['drivers']) === 2, 'beban kerja: tepat 2 driver company 1');
    ok((int) $drv['Driver Drchk Satu']['tugas'] === 2
        && (int) $drv['Driver Drchk Satu']['selesai'] === 1
        && (int) $drv['Driver Drchk Satu']['berjalan'] === 1,
        'beban kerja Satu: 2 tugas (1 selesai + 1 berjalan)');
    ok((int) $drv['Driver Drchk Satu']['hari'] === 2,
        'beban kerja Satu: 2 hari bertugas (10 & 12 Des) → ' . $drv['Driver Drchk Satu']['hari']);
    ok((int) $drv['Driver Drchk Dua']['tugas'] === 1 && (int) $drv['Driver Drchk Dua']['selesai'] === 1,
        'beban kerja Dua: 1 tugas selesai');
    $dtot = dispatch_totals($d1['drivers'], ['tugas', 'selesai', 'berjalan', 'hari']);
    ok($dtot['tugas'] === $d1['summary']['total'], 'total <tfoot> = summary.total');

    // Detail penugasan.
    $det = $d1['details'];
    ok(count($det) === 3, "detail: 3 baris penugasan company 1 (dapat " . count($det) . ")");
    $docs = array_column($det, 'document_number');
    ok(in_array('TRV-DRCHK01', $docs, true) && in_array('RNT-DRCHK01', $docs, true),
        'detail: nomor dokumen dari kedua jenis booking tampil');
    ok(!in_array('TRV-DRCHK99', $docs, true), 'detail: dokumen tenant 990 tidak bocor');
    $byDoc = [];
    foreach ($det as $r) { $byId[(int) $r['id']] = $r; }
    ok(($byId[990000]['ended_at'] ?? '') === '2026-12-10 17:00:00',
        'detail: kolom ended_at benar (dapat ' . var_export($byId[990000]['ended_at'] ?? null, true) . ')');
    ok(dispatch_status_label($byId[990001]['ended_at']) === 'Berjalan', 'detail: berjalan → label Berjalan');
    ok(dispatch_duration_label(dispatch_duration('2026-12-10 08:00:00', '2026-12-10 17:00:00')) === '9j 0m',
        'durasi: 08:00→17:00 = 9j 0m');
    $dry = dispatch_duration_label(dispatch_duration('2026-12-10 08:00:00', null));
    ok($dry !== '-' && strpos($dry, 'j') !== false, "durasi berjalan: dihitung sampai sekarang (dapat $dry)");

    // LEFT JOIN kendaraan: kendaraan 990 tidak pernah ikut baris company 1.
    $vnums = array_column($det, 'vehicle_number');
    ok(!in_array('D 2 DRCHK', $vnums, true), 'scoping detail: kendaraan tenant 990 tidak pernah ter-JOIN');

    // ---------- C. Filter jenis ----------
    $_SESSION['role_name'] = 'Super Admin';
    $ft = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'travel']);
    ok(dispatch_summary($ft)['total'] === 3, "filter travel: 3 tugas (2 milik 1 + 1 milik 990) → " . dispatch_summary($ft)['total']);
    $fr = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'rental']);
    ok(dispatch_summary($fr)['total'] === 1, 'filter rental: 1 tugas');

    // ---------- D. Input ngawur ----------
    $fx = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => "travel' UNION SELECT 1 -- "]);
    ok($fx['booking_type'] === 'all', 'whitelist: injeksi booking_type → all');
    $err = null;
    try { dispatch_all($fx); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'whitelist: tidak ada SQL error untuk input ngawur');
    $fd = report_filter(['from' => 'bukan-tanggal', 'to' => '', 'booking_type' => 'all']);
    ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd['from']), 'tanggal ngawur → default valid');

} finally {
    $cleanup();
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
