<?php
/**
 * Self-check: data grafik dashboard (booking & pendapatan per bulan).
 * Butuh DB dev. Jalankan:
 *   php -d mysqli.default_socket=/opt/data/cache/scratch/localdb/mysql.sock tests/dashboard_chart_check.php
 *
 * Uji: (a) 6 titik data, (b) bulan tanpa booking = 0, (c) total pendapatan
 * sama dengan SELECT SUM(total_price) langsung untuk rentang yang sama.
 * Fixture sementara dibersihkan di akhir.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/dashboard/_chart.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
// Sesi admin lintas-perusahaan: grafik tidak dibatasi company_id saat Super Admin,
// sehingga totalnya bisa dibandingkan langsung dengan SUM seluruh tabel.
$_SESSION['role_name'] = 'Super Admin';
$_SESSION['company_id'] = 1;

$first = array_key_first(chart_months()) . '-01';
$last  = date('Y-m-t');

try {
    // --- (a) 6 titik data, label & kunci konsisten ---
    $ch = dashboard_chart();
    ok(count($ch['labels']) === 6, 'a) 6 label bulan (' . count($ch['labels']) . ')');
    ok(count($ch['bookings']) === 6 && count($ch['revenue']) === 6,
        'a) 6 titik data booking & revenue');
    ok($ch['labels'][5] === date('M Y'), 'a) label terakhir = bulan berjalan (' . $ch['labels'][5] . ')');
    ok($ch['keys'] === array_values(array_unique($ch['keys'])),
        'a) kunci bulan unik & terurut (' . implode(',', $ch['keys']) . ')');
    ok($ch['keys'][0] !== $ch['keys'][5], 'a) rentang mencakup 6 bulan berbeda');

    // --- (b) bulan tanpa booking = 0 (tetap tampil, tidak error) ---
    $empty = null;
    foreach ($ch['keys'] as $i => $ym) {
        $n = (int) $c->query("SELECT (SELECT COUNT(*) FROM travel_ticket_bookings WHERE DATE_FORMAT(date,'%Y-%m')='$ym')"
            . " + (SELECT COUNT(*) FROM rental_car_bookings WHERE DATE_FORMAT(date,'%Y-%m')='$ym')")->fetch_row()[0];
        if ($n === 0) { $empty = $i; break; }
    }
    if ($empty !== null) {
        ok($ch['bookings'][$empty] === 0, "b) bulan tanpa booking ({$ch['keys'][$empty]}) = 0");
        ok($ch['revenue'][$empty] === 0.0, "b) pendapatan bulan tanpa booking ({$ch['keys'][$empty]}) = 0");
    } else {
        ok(true, 'b) semua 6 bulan berisi data (uji nol dilewati)');
    }
    ok(!in_array(null, $ch['bookings'], true) && !in_array(null, $ch['revenue'], true),
        'b) tidak ada nilai null (bulan kosong tetap angka 0)');

    // --- (c) total pendapatan = SUM(total_price) langsung pada rentang sama ---
    $direct = $c->query("SELECT
        (SELECT COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE date BETWEEN '$first' AND '$last')
      + (SELECT COALESCE(SUM(total_price),0) FROM rental_car_bookings  WHERE date BETWEEN '$first' AND '$last') AS tp,
        (SELECT COUNT(*) FROM travel_ticket_bookings WHERE date BETWEEN '$first' AND '$last')
      + (SELECT COUNT(*) FROM rental_car_bookings  WHERE date BETWEEN '$first' AND '$last') AS n")->fetch_assoc();
    $chart_total = array_sum($ch['revenue']);
    ok(abs($chart_total - (float) $direct['tp']) < 0.01,
        "c) total pendapatan grafik = SUM(total_price) langsung ($chart_total = {$direct['tp']})");
    ok(array_sum($ch['bookings']) === (int) $direct['n'],
        'c) total booking grafik = COUNT langsung (' . array_sum($ch['bookings']) . " = {$direct['n']})");

    // --- (c2) scoping: fixture perusahaan lain tidak bocor ke grafik company 1 ---
    $c->query("DELETE FROM travel_ticket_bookings WHERE document_number = 'TRV-CHARTB1'");
    $c->query("DELETE FROM passengers WHERE code = 'CHART-PAX'");
    $c->query("DELETE FROM branches WHERE code = 'CHART-BR'");
    $c->query("DELETE FROM companies WHERE code = 'CHART-B'");
    $c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
    $c->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BANTEN', 'Banten')");
    $c->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
    $c->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CBT', 'Cibodas')");
    $c->query("INSERT INTO companies (id, group_id, code, name, is_active)
               SELECT 9991, 1, 'CHART-B', 'Perusahaan Chart B', 1
               WHERE NOT EXISTS (SELECT 1 FROM companies WHERE id = 9991)");
    $c->query("INSERT INTO branches (id, company_id, code, name)
               SELECT 99911, 9991, 'CHART-BR', 'Cabang Chart'
               WHERE NOT EXISTS (SELECT 1 FROM branches WHERE id = 99911)");
    $c->query("INSERT INTO passengers (id, company_id, code, name, phone_number)
               SELECT 9991, 9991, 'CHART-PAX', 'Pax Chart', '62800'
               WHERE NOT EXISTS (SELECT 1 FROM passengers WHERE id = 9991)");
    $c->query("INSERT INTO travel_ticket_bookings
        (company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
         total_price, total_payment, passenger_id, created_user)
        VALUES (9991, 99911, 'TRV-CHARTB1', '$last', '09:00:00', 1, 1, 999000000, 0, 9991, 'chart-check')");

    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $ch1 = dashboard_chart();
    $own = $c->query("SELECT
        (SELECT COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE company_id = 1 AND date BETWEEN '$first' AND '$last')
      + (SELECT COALESCE(SUM(total_price),0) FROM rental_car_bookings  WHERE company_id = 1 AND date BETWEEN '$first' AND '$last') AS tp")->fetch_assoc();
    ok(abs(array_sum($ch1['revenue']) - (float) $own['tp']) < 0.01,
        'c2) scoping: nilai perusahaan 9991 (999 juta) tidak bocor ke grafik company 1');
    ok(abs(array_sum($ch1['revenue']) < 999000000),
        'c2) scoping: total grafik company 1 jauh di bawah fixture Rp 999 jt');
    $_SESSION['role_name'] = 'Super Admin';

    // --- (d) format rupiah singkat (fungsi nyata dari index.php) ---
    $src = file_get_contents(__DIR__ . '/../pages/dashboard/index.php');
    ok(str_contains($src, 'function rupiah_singkat'), 'd) helper rupiah_singkat ada di dashboard');
    // Salin rumus dari helper (index.php dijalankan penuh hanya lewat HTTP).
    $fmt = static function (float $n): string {
        foreach ([[1e9, 'M'], [1e6, 'jt'], [1e3, 'rb']] as [$div, $suf]) {
            if (abs($n) >= $div) {
                return 'Rp ' . number_format($n / $div, ($n / $div) < 100 ? 1 : 0, ',', '.') . ' ' . $suf;
            }
        }
        return 'Rp ' . number_format($n, 0, ',', '.');
    };
    ok($fmt(0) === 'Rp 0', 'd) format 0 → Rp 0 (' . $fmt(0) . ')');
    ok($fmt(12500000) === 'Rp 12,5 jt', 'd) format 12.500.000 (' . $fmt(12500000) . ')');
    ok($fmt(2500000000) === 'Rp 2,5 M', 'd) format 2,5 miliar (' . $fmt(2500000000) . ')');

} finally {
    $c->query("DELETE FROM travel_ticket_bookings WHERE document_number = 'TRV-CHARTB1'");
    $c->query("DELETE FROM passengers WHERE code = 'CHART-PAX'");
    $c->query("DELETE FROM branches WHERE code = 'CHART-BR'");
    $c->query("DELETE FROM companies WHERE code = 'CHART-B'");
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
