<?php
/**
 * Self-check: Laporan Keuangan (tanpa HTTP).
 * Angka persis vs query langsung DB + scoping tenant + fixture bersih.
 * Jalankan: php -d mysqli.default_socket=... tests/reports_finance_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/reports/_query.php';
require_once __DIR__ . '/../pages/reports/_finance_query.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
// Rentang uji khusus fixture (Desember 2026) — DB dev berisi data QA di Oktober,
// sehingga angka persis hanya bisa dijamin pada rentang yang hanya berisi fixture ini.
$FROM = '2026-12-01';
$TO   = '2026-12-31';

// --- Fixture: perusahaan B (991) + booking + biaya, agar scoping & angka teruji sendiri.
// Id fixture memakai rentang >= 990 (di luar data seed) supaya tahan dijalankan berulang.
$cleanup = static function () use ($c): void {
    $c->query("DELETE FROM booking_cost_lines WHERE id >= 990000 OR created_user = 'fin-check'");
    $c->query("DELETE FROM trip_assignments WHERE id >= 990000 OR reason = 'fin-check'");
    $c->query("DELETE FROM travel_ticket_bookings WHERE id >= 990000 OR document_number LIKE 'TRV-FCHK%'");
    $c->query("DELETE FROM rental_car_bookings WHERE id >= 990000 OR document_number LIKE 'RNT-FCHK%'");
    $c->query("DELETE FROM vehicles WHERE id >= 990000");
    $c->query("DELETE FROM employees WHERE id >= 990000");
    $c->query("DELETE FROM passengers WHERE id >= 990000 OR code = 'FCHK-PAX'");
    $c->query("DELETE FROM branches WHERE id >= 990000 OR code = 'FCHK-BR'");
    $c->query("DELETE FROM companies WHERE id = 991 OR code = 'FCHK-B'");
};
$cleanup();

$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BANTEN', 'Banten')");
$c->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
$c->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CBT', 'Cibodas')");
// Master kendaraan wajib untuk FK vehicles (fresh install DB dev kosong).
$c->query("INSERT IGNORE INTO brand_vehicles (id, company_id, code, name) VALUES (1, 1, 'FCHK-BRAND', 'Merek Fchk')");
$c->query("INSERT IGNORE INTO category_vehicles (id, company_id, code, name) VALUES (1, 1, 'FCHK-CAT', 'Kategori Fchk')");
$c->query("INSERT IGNORE INTO model_units (id, company_id, brand_id, code, name) VALUES (1, 1, 1, 'FCHK-MDL', 'Model Fchk')");
$c->query("INSERT IGNORE INTO type_engines (id, company_id, code, name) VALUES (1, 1, 'FCHK-ENG', 'Mesin Fchk')");

// Perusahaan sendiri (1) + perusahaan lain (991) — keduanya punya booking & biaya di rentang uji.
$c->query("INSERT INTO companies (id, group_id, code, name, country_code, is_active)
           VALUES (991, 1, 'FCHK-B', 'Perusahaan Fchk B', '+62', 1)");
$c->query("INSERT INTO branches (id, company_id, code, name) VALUES (990001, 991, 'FCHK-BR', 'Cabang Fchk')");
$c->query("INSERT INTO passengers (id, company_id, code, name, phone_number) VALUES (990001, 991, 'FCHK-PAX', 'Pax Fchk', '0800')");

// Booking MILIK perusahaan 1 (dibuat lewat id tinggi agar ikut bersih-bersih).
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES (990010, 1, 1, 'TRV-FCHK01', '$FROM', '09:00:00', 1, 1, 1000000, 400000, 991, 'fin-check')");
$c->query("INSERT INTO rental_car_bookings
    (id, company_id, branch_id, document_number, date, start_date, end_date, time,
     pickup_point_district_id, delivery_point_district_id, total_price, total_payment, passenger_id, created_user)
    VALUES (990011, 1, 1, 'RNT-FCHK01', '$FROM', '$FROM', '$TO', '09:00:00', 1, 1, 2500000, 2500000, 991, 'fin-check')");

// Booking MILIK perusahaan lain (991) — total 7.777.777, harus TIDAK bocor.
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES (990020, 991, 990001, 'TRV-FCHK-B1', '$FROM', '09:00:00', 1, 1, 7777777, 0, 990001, 'fin-check')");

// Biaya: milik perusahaan 1 (3 baris) + milik 991 (1 baris), semua di rentang uji.
$c->query("INSERT INTO booking_cost_lines (id, company_id, booking_type, booking_id, cost_type, description, amount, created_user, created_date)
    VALUES (990030, 1, 'travel', 990010, 'bbm', 'BBM fchk', 300000, 'fin-check', '$FROM 10:00:00'),
           (990031, 1, 'travel', 990010, 'a',   'Premi fchk', 150000, 'fin-check', '$FROM 10:01:00'),
           (990032, 1, 'rental', 990011, 'bbm', 'BBM rental fchk', 250000, 'fin-check', '$FROM 10:02:00'),
           (990033, 991, 'travel', 990020, 'bbm', 'BBM fchk B', 999999, 'fin-check', '$FROM 10:03:00')");
// Biaya DI LUAR rentang (harus diabaikan).
$c->query("INSERT INTO booking_cost_lines (id, company_id, booking_type, booking_id, cost_type, description, amount, created_user, created_date)
    VALUES (990034, 1, 'travel', 990010, 'bbm', 'BBM luar rentang', 5000000, 'fin-check', '2026-11-30 23:59:59')");

// Assignment + kendaraan + karyawan milik perusahaan 1 untuk uji operasional.
$c->query("INSERT INTO vehicles (id, company_id, brand_id, model_unit_id, type_engine_id, category_vehicle_id,
    frame_number, machine_number, seat, production_year, vehicle_color, vehicle_number, ownership, is_active)
    VALUES (990010, 1, 1, 1, 1, 1, 'FCHK-FRAME', 'FCHK-MACH', 12, 2020, 'Putih', 'F 9999 FCHK', 'milik', 1)");
$c->query("INSERT INTO employees (id, company_id, code, name, job_position, status, join_date)
    VALUES (990010, 1, 'FCHK-EMP', 'Driver Fchk', 'Driver', 'aktif', '2026-01-01')");
$c->query("INSERT INTO trip_assignments (id, company_id, booking_type, booking_id, employee_id, vehicle_id, started_at, ended_at, reason, created_user)
    VALUES (990010, 1, 'travel', 990010, 990010, 990010, '$FROM 08:00:00', '$FROM 17:00:00', 'fin-check', 'fin-check'),
           (990011, 1, 'rental', 990011, 990010, NULL, '$FROM 08:00:00', NULL, 'fin-check', 'fin-check')");

try {
    // ---------- A. Super Admin: angka persis vs query langsung DB ----------
    $_SESSION['role_name'] = 'Super Admin';
    $_SESSION['company_id'] = 1;
    $f = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);

    $q = static fn(string $sql): string => (string) db()->query($sql)->fetch_row()[0];
    $db_price = (float) $q("SELECT
        (SELECT COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE date BETWEEN '$FROM' AND '$TO')
      + (SELECT COALESCE(SUM(total_price),0) FROM rental_car_bookings WHERE date BETWEEN '$FROM' AND '$TO')");
    $db_paid = (float) $q("SELECT
        (SELECT COALESCE(SUM(total_payment),0) FROM travel_ticket_bookings WHERE date BETWEEN '$FROM' AND '$TO')
      + (SELECT COALESCE(SUM(total_payment),0) FROM rental_car_bookings WHERE date BETWEEN '$FROM' AND '$TO')");
    $db_cost = (float) $q("SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines
        WHERE created_date >= '$FROM' AND created_date < DATE_ADD('$TO', INTERVAL 1 DAY)");

    // (a) total pendapatan = SUM(total_price) langsung
    $rev = fin_revenue($f);
    ok(abs($rev['total'] - $db_price) < 0.01,
        '(a) pendapatan = SUM(total_price) langsung (' . $rev['total'] . ' = ' . $db_price . ')');
    ok(abs($rev['total'] - ($rev['travel'] + $rev['rental'])) < 0.01,
        '(a) travel + rental = total (' . $rev['travel'] . ' + ' . $rev['rental'] . ' = ' . $rev['total'] . ')');
    // Super Admin melihat lintas perusahaan: fixture travel perusahaan 1 (1.000.000)
    // + fixture travel perusahaan 991 (7.777.777) = 8.777.777.
    ok(abs($rev['travel'] - 8777777) < 0.01, '(a) travel (Super Admin) = 1.000.000 + 7.777.777 = ' . $rev['travel']);
    ok(abs($rev['rental'] - 2500000) < 0.01, '(a) pendapatan rental = 2.500.000 fixture');
    ok(abs($rev['paid'] - $db_paid) < 0.01,
        '(a) terbayar = SUM(total_payment) langsung (' . $rev['paid'] . ' = ' . $db_paid . ')');
    ok(abs($rev['receivable'] - ($db_price - $db_paid)) < 0.01,
        '(a) piutang = pendapatan - terbayar (' . $rev['receivable'] . ')');

    // (b) total biaya = SUM(booking_cost_lines.amount) langsung
    $cost = fin_cost_total($f);
    ok(abs($cost - $db_cost) < 0.01, '(b) biaya = SUM(amount) langsung (' . $cost . ' = ' . $db_cost . ')');
    ok(abs($cost - 1699999) < 0.01, '(b) biaya fixture = 700.000 milik company 1 + 999.999 milik 991 (Super Admin); biaya luar rentang diabaikan');

    // (c) laba kotor = pendapatan - biaya
    $all = fin_all($f);
    ok(abs($all['gross_profit'] - ($rev['total'] - $cost)) < 0.01,
        '(c) laba kotor = pendapatan - biaya (' . $all['gross_profit'] . ')');
    ok($all['gross_profit'] === $all['revenue']['total'] - $all['cost_total'], '(c) laba kotor konsisten dengan kartu');

    // biaya per jenis
    $bytype = [];
    foreach ($all['cost_types'] as $r) { $bytype[$r['cost_type']] = (float) $r['amount']; }
    ksort($bytype);
    ok(abs(array_sum($bytype) - $db_cost) < 0.01, 'biaya per jenis: jumlah = total biaya');
    ok(isset($bytype['bbm']) && abs($bytype['bbm'] - 1549999) < 0.01, 'biaya per jenis: bbm = 1.549.999 (300k+250k milik 1, 999.999 milik 991)');
    ok(isset($bytype['a']) && abs($bytype['a'] - 150000) < 0.01, 'biaya per jenis: a = 150.000');

    // (d) daftar belum lunas hanya sisa > 0
    $unpaid = fin_unpaid($f, null);
    $bad = array_filter($unpaid, static fn($r) => (float) $r['outstanding'] <= 0);
    ok($bad === [], '(d) daftar belum lunas: tidak ada baris sisa <= 0 (' . count($unpaid) . ' baris)');
    $found = false;
    foreach ($unpaid as $r) { if ($r['document_number'] === 'TRV-FCHK01') { $found = true; } }
    ok($found, '(d) TRV-FCHK01 (sisa 600.000) ada di daftar belum lunas');
    // urut sisa terbesar: cek monoton menurun
    $prev = PHP_FLOAT_MAX; $sorted = true;
    foreach ($unpaid as $r) { if ((float) $r['outstanding'] > $prev) { $sorted = false; } $prev = (float) $r['outstanding']; }
    ok($sorted, '(d) daftar belum lunas urut sisa terbesar → terkecil');
    $ut = fin_unpaid_totals($unpaid);
    ok(abs($ut['outstanding'] - (float) $q("SELECT COALESCE(SUM(total_price - total_payment),0) FROM (
            SELECT total_price, total_payment FROM travel_ticket_bookings WHERE date BETWEEN '$FROM' AND '$TO'
            UNION ALL SELECT total_price, total_payment FROM rental_car_bookings WHERE date BETWEEN '$FROM' AND '$TO') u
        WHERE total_price - total_payment > 0")) < 0.01, '(d) total sisa = SUM(sisa>0) langsung di DB');

    // (e) scoping: data perusahaan lain tidak bocor — Admin Perusahaan (bukan Super Admin)
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $f1 = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'all']);
    $r1 = fin_revenue($f1);
    $c1 = fin_cost_total($f1);
    $own = (float) $q("SELECT
        (SELECT COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE company_id = 1 AND date BETWEEN '$FROM' AND '$TO')
      + (SELECT COALESCE(SUM(total_price),0) FROM rental_car_bookings WHERE company_id = 1 AND date BETWEEN '$FROM' AND '$TO')");
    $owncost = (float) $q("SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines
        WHERE company_id = 1 AND created_date >= '$FROM' AND created_date < DATE_ADD('$TO', INTERVAL 1 DAY)");
    ok(abs($r1['total'] - $own) < 0.01, '(e) scoping pendapatan = milik company 1 saja (' . $r1['total'] . ' = ' . $own . ')');
    ok(abs($c1 - $owncost) < 0.01, '(e) scoping biaya = milik company 1 saja (' . $c1 . ' = ' . $owncost . ')');
    ok(abs($c1 - 700000) < 0.01, '(e) biaya 999.999 milik company 991 tidak bocor ke company 1');
    foreach (fin_unpaid($f1, null) as $r) {
        ok($r['document_number'] !== 'TRV-FCHK-B1', '(e) booking company 991 tidak muncul di piutang company 1');
    }
    // Fixture company 991 memang ada → bukti uji scoping valid.
    ok((int) $q("SELECT COUNT(*) FROM travel_ticket_bookings WHERE company_id = 991") === 1,
        '(e) fixture company 991 memang ada di DB');

    // ---------- B. Filter jenis + whitelist ----------
    $ft = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => 'travel']);
    ok(abs(fin_revenue($ft)['rental']) < 0.01, 'filter travel: pendapatan rental = 0');
    ok(abs(fin_revenue($ft)['travel'] - 1000000) < 0.01, 'filter travel: pendapatan travel = 1.000.000');
    $ct = [];
    foreach (fin_cost_by_type($ft) as $r) { $ct[$r['cost_type']] = (float) $r['amount']; }
    ok(!isset($ct['bbm']) || abs($ct['bbm'] - 300000) < 0.01, 'filter travel: bbm hanya 300.000 (rental tidak ikut)');
    $fx = report_filter(['from' => $FROM, 'to' => $TO, 'booking_type' => "travel' UNION SELECT 1 -- "]);
    ok($fx['booking_type'] === 'all', 'whitelist: injeksi booking_type → all');
    $err = null;
    try { fin_all($fx); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'whitelist: tidak ada SQL error untuk input ngawur');
    $fd = report_filter(['from' => 'bukan-tanggal', 'to' => '', 'booking_type' => 'all']);
    ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd['from']), 'tanggal ngawur → default valid');

    // ---------- C. Limit tabel HTML tidak mengubah angka total ----------
    $_SESSION['role_name'] = 'Super Admin';
    $lim = fin_unpaid($f, 1);
    ok(count($lim) === 1, 'LIMIT 1: daftar piutang dibatasi 1 baris');
    ok(count(fin_unpaid($f, null)) >= 1, 'tanpa limit: semua baris piutang terbaca');

} finally {
    $cleanup();
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
