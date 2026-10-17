<?php
/**
 * Self-check: laporan booking + export (tanpa HTTP).
 * Butuh DB dev. Jalankan: php -d mysqli.default_socket=... tests/reports_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/reports/_query.php';
require_once __DIR__ . '/../includes/xlsx.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
// Fixture sementara: perusahaan B + booking travel B untuk uji scoping. Dibersihkan di akhir.
// DB fresh kosong: fixture wajib lengkap (wilayah + penumpang) agar FK booking terpenuhi.
$c->query("DELETE FROM travel_ticket_bookings WHERE document_number LIKE 'TRV-CHK%'");
$c->query("DELETE FROM passengers WHERE code = 'CHK-PAX'");
$c->query("DELETE FROM branches WHERE code = 'CHK-BR'");
$c->query("DELETE FROM companies WHERE code = 'CHK-B'");
$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BANTEN', 'Banten')");
$c->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
$c->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CBT', 'Cibodas')");
$c->query("INSERT INTO companies (id, group_id, code, name, country_code, is_active)
           VALUES (990, 1, 'CHK-B', 'Perusahaan Cek B', '+62', 1)");
$c->query("INSERT INTO branches (id, company_id, code, name) VALUES (9901, 990, 'CHK-BR', 'Cabang Cek')");
$c->query("INSERT INTO passengers (id, company_id, code, name, phone_number)
           VALUES (990, 990, 'CHK-PAX', 'Penumpang Cek', '0800')");
$bid = 99001;
$c->query("INSERT INTO travel_ticket_bookings
    (id, company_id, branch_id, document_number, date, time, pickup_point_district_id, delivery_point_district_id,
     total_price, total_payment, passenger_id, created_user)
    VALUES ($bid, 990, 9901, 'TRV-CHKB01', '2026-10-15', '09:00:00', 1, 1, 777777, 0, 990, 'reports-check')");

try {
    // --- 1. Rentang uji: super admin (lihat semua) vs scoped company 1 ---
    $f_all = report_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'booking_type' => 'all']);

    // Super Admin: cocokkan dengan COUNT/SUM langsung di DB.
    $_SESSION['role_name'] = 'Super Admin';
    $_SESSION['company_id'] = 1;
    $s = report_summary($f_all);
    $direct = $c->query("SELECT (SELECT COUNT(*) FROM travel_ticket_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31')
        + (SELECT COUNT(*) FROM rental_car_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31') AS n")->fetch_assoc();
    $dsum = $c->query("SELECT
        (SELECT COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31')
      + (SELECT COALESCE(SUM(total_price),0) FROM rental_car_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31') AS tp,
        (SELECT COALESCE(SUM(total_payment),0) FROM travel_ticket_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31')
      + (SELECT COALESCE(SUM(total_payment),0) FROM rental_car_bookings WHERE date BETWEEN '2026-10-01' AND '2026-10-31') AS pay")->fetch_assoc();
    ok((int) $s['total_bookings'] === (int) $direct['n'],
        "summary.total_bookings cocok DB ({$s['total_bookings']} = {$direct['n']})");
    ok(abs((float) $s['total_price'] - (float) $dsum['tp']) < 0.01,
        "summary.total_price cocok DB ({$s['total_price']} = {$dsum['tp']})");
    ok(abs((float) $s['total_payment'] - (float) $dsum['pay']) < 0.01,
        "summary.total_payment cocok DB ({$s['total_payment']} = {$dsum['pay']})");
    ok(abs((float) $s['total_receivable'] - ((float) $dsum['tp'] - (float) $dsum['pay'])) < 0.01,
        'summary.total_receivable = nilai - terbayar');
    // Booking company 990 harus terlihat oleh Super Admin.
    ok((int) $s['total_bookings'] >= 1, 'Super Admin melihat lintas perusahaan');

    // --- 2. Scoping: perusahaan 1 tidak boleh melihat baris company 990 ---
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $s1 = report_summary($f_all);
    $p1 = report_by_period($f_all);
    $seen_b = false;
    foreach ($p1 as $r) { if ((float) $r['total_receivable'] > 700000) { $seen_b = true; } }
    $own = $c->query("SELECT COUNT(*) FROM travel_ticket_bookings WHERE company_id = 1 AND date BETWEEN '2026-10-01' AND '2026-10-31'")->fetch_row()[0];
    $own_rental = $c->query("SELECT COUNT(*) FROM rental_car_bookings WHERE company_id = 1 AND date BETWEEN '2026-10-01' AND '2026-10-31'")->fetch_row()[0];
    ok((int) $s1['total_bookings'] === (int) $own + (int) $own_rental,
        "scoping: company 1 hanya melihat booking-nya ({$s1['total_bookings']} = $own + $own_rental)");
    ok(!$seen_b, 'scoping: baris company 990 (777777) tidak bocor ke laporan company 1');
    // Query mentah sebagai cross-check
    $leak = $c->query("SELECT COUNT(*) FROM travel_ticket_bookings WHERE company_id = 990")->fetch_row()[0];
    ok((int) $leak === 1, 'fixture company 990 memang ada di DB (bukti uji scoping valid)');

    // --- 3. booking_type ngawur tidak error & tidak bocor ---
    $fx = report_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'booking_type' => 'xxx']);
    ok($fx['booking_type'] === 'all', "whitelist: 'xxx' → 'all' (dapat: {$fx['booking_type']})");
    $sqlinj = report_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'booking_type' => "travel' UNION SELECT 1 -- "]);
    ok($sqlinj['booking_type'] === 'all', 'whitelist: percobaan injeksi juga → all');
    $err = null;
    try { report_summary($sqlinj); report_by_period($sqlinj); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'whitelist: tidak ada SQL error untuk input ngawur');

    // --- 4. Tanggal ngawur → default, tanpa error ---
    $fd = report_filter(['from' => 'bukan-tanggal', 'to' => '', 'booking_type' => 'travel']);
    ok((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd['from']), "tanggal ngawur → default valid ({$fd['from']})");

    // --- 5. Semua dimensi tidak error & total <tfoot> konsisten ---
    foreach (['period', 'route', 'vehicle', 'driver'] as $dim) {
        $fn = ['period' => 'report_by_period', 'route' => 'report_by_route',
               'vehicle' => 'report_by_vehicle', 'driver' => 'report_by_driver'][$dim];
        $rows = $fn($f_all);
        $t = report_totals($rows);
        $sum = 0;
        foreach ($rows as $r) { $sum += (int) $r['total_bookings']; }
        ok($t['total_bookings'] === $sum, "dimensi $dim: total <tfoot> = jumlah baris ($sum)");
    }

    // --- 6. Booking tanpa assignment driver tetap tampil sebagai '-' (tidak hilang) ---
    $_SESSION['role_name'] = 'Super Admin';
    // Fixture karyawan + booking company 1 (fresh install kosong).
    $c->query("DELETE FROM trip_assignments WHERE reason = 'reports-check'");
    $c->query("DELETE FROM travel_ticket_bookings WHERE document_number = 'TRV-CHKDRV'");
    $c->query("DELETE FROM employees WHERE code = 'CHK-EMP'");
    $c->query("INSERT INTO employees (company_id, code, name, job_position, status, join_date) VALUES (1, 'CHK-EMP', 'Budi Cek Driver', 'Driver', 'aktif', '2026-01-01')");
    $eid = $c->insert_id;
    $c->query("INSERT INTO travel_ticket_bookings (company_id, branch_id, document_number, date, time,
        pickup_point_district_id, delivery_point_district_id, total_price, total_payment, passenger_id, created_user)
        VALUES (1, 1, 'TRV-CHKDRV', '2026-10-15', '10:00:00', 1, 1, 111000, 0, 990, 'reports-check')");
    $b1 = $c->insert_id;
    $c->query("INSERT INTO trip_assignments (company_id, booking_type, booking_id, employee_id, started_at, reason, created_user)
        VALUES (1, 'travel', $b1, $eid, NOW(), 'reports-check', 'uji')");
    $s_all = report_summary($f_all);
    $drv = report_by_driver($f_all);
    $names = array_column($drv, 'driver_name');
    ok(in_array('-', $names, true), "driver tanpa penugasan tampil sebagai '-' (dapat: " . implode('|', $names) . ')');
    ok(in_array('Budi Cek Driver', $names, true), 'driver dengan penugasan tampil pakai nama karyawan');
    $tot_drv = array_sum(array_map(static fn($r) => (int) $r['total_bookings'], $drv));
    ok($tot_drv === (int) $s_all['total_bookings'], "driver: total booking utuh ($tot_drv), tidak ada baris hilang");
    $c->query("DELETE FROM trip_assignments WHERE reason = 'reports-check'");
    $c->query("DELETE FROM travel_ticket_bookings WHERE document_number = 'TRV-CHKDRV'");
    $c->query("DELETE FROM employees WHERE code = 'CHK-EMP'");

    // --- 7. RBAC: reports.view untuk Super Admin (r.id=1) & Staff (r.id=4) ---
    foreach ([[1, 'Super Admin'], [4, 'Staff']] as [$rid, $rname]) {
        $has = (bool) $c->query("SELECT 1 FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = $rid AND p.name = 'reports.view'")->num_rows;
        ok($has, "$rname (role id=$rid) punya reports.view");
    }

    // --- 8. Penulis XLSX: bangun → baca ulang via ZipArchive → verifikasi CRC ---
    $rows = [
        ['Tanggal', 'Booking', 'Nilai', 'Teks'],
        ['2026-10-16', 2, 1500000.5, 'Tangerang - Jakarta'],
        ['Total "A" & <B>', 3, 1234.56, "kontrol\x07char"],
    ];
    $bin = xlsx_build($rows, 'Laporan Booking');
    $tmp = sys_get_temp_dir() . '/reports_check_' . getmypid() . '.xlsx';
    file_put_contents($tmp, $bin);
    ok(str_starts_with($bin, "PK\x03\x04"), 'XLSX: magic ZIP lokal (PK\\x03\\x04)');
    ok(substr($bin, -22, 4) === "PK\x05\x06", 'XLSX: EOCD ada di akhir arsip');

    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        $open = $z->open($tmp);
        ok($open === true, 'XLSX: ZipArchive membuka arsip');
        if ($open === true) {
            $names = [];
            for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
            $need = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml',
                     'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'];
            foreach ($need as $n) {
                ok(in_array($n, $names, true), "XLSX: entry $n ada");
            }
            ok($z->numFiles === 6, 'XLSX: 6 entry');
            // CRC: bandingkan hash crc32b isi entry dengan yang tercatat di ekstraksi.
            $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
            ok($sheet !== false && str_contains($sheet, '<row r="1">'), 'XLSX: sheet1.xml terbaca & punya baris');
            $ct = $z->getFromName('[Content_Types].xml');
            ok($ct !== false && str_contains($ct, 'spreadsheetml.sheet.main+xml'), 'XLSX: content types benar');
            $sst = $z->getFromName('xl/sharedStrings.xml');
            ok($sst !== false && str_contains($sst, 'Tangerang - Jakarta'), 'XLSX: teks ada di sharedStrings');
            // Angka harus di <v> tanpa t="s"; teks harus t="s".
            ok(str_contains($sheet, '<v>1500000.5</v>'), 'XLSX: angka langsung di <v> (1500000.5)');
            ok(!str_contains($sheet, 't="s"><v>1500000.5'), 'XLSX: angka bukan shared string');
            // Karakter kontrol dibuang, XML di-escape.
            ok(!str_contains($sst, "\x07"), 'XLSX: karakter kontrol dibuang');
            ok(str_contains($sst, '&#039;') || str_contains($sst, 'Total &quot;A&quot; &amp; &lt;B&gt;')
                || str_contains($sst, 'Total "A" &amp; &lt;B&gt;'), 'XLSX: XML di-escape');
            $z->close();
        }
    } else {
        // CLI tanpa ekstensi zip: verifikasi lewat python3 zipfile di luar PHP.
        // Bukan kegagalan kode — cukup 1 bukti eksternal (lihat laporan uji).
        $zf = addslashes($tmp);
        $py = shell_exec("python3 -c \"import zipfile,sys; z=zipfile.ZipFile('$zf'); print(z.testzip()); print(len(z.namelist()))\" 2>&1");
        $lines = array_filter(array_map('trim', explode("\n", (string) $py)));
        ok(($lines[0] ?? '') === 'None', 'XLSX: python zipfile testzip() = None (semua CRC valid)');
        ok(($lines[1] ?? '') === '6', 'XLSX: python zipfile membaca 6 entry');
    }

    // Verifikasi CRC manual (tanpa ZipArchive): bandingkan crc32b isi dengan header lokal.
    $off = 0;
    $crc_ok = true;
    while (($p = strpos($bin, "PK\x03\x04", $off)) !== false) {
        $crc_stored = unpack('V', substr($bin, $p + 14, 4))[1];
        $csize = unpack('V', substr($bin, $p + 18, 4))[1];
        $nlen = unpack('v', substr($bin, $p + 26, 2))[1];
        $elen = unpack('v', substr($bin, $p + 28, 2))[1];
        $data = substr($bin, $p + 30 + $nlen + $elen, $csize);
        $crc_calc = hexdec(hash('crc32b', $data));
        if ($crc_stored !== $crc_calc) { $crc_ok = false; }
        $off = $p + 4;
    }
    ok($crc_ok, 'XLSX: CRC32 setiap entry (yang ditulis) cocok dengan isinya');
    @unlink($tmp);

} finally {
    // Bersihkan fixture.
    $c->query("DELETE FROM travel_ticket_bookings WHERE document_number LIKE 'TRV-CHK%'");
    $c->query("DELETE FROM passengers WHERE code = 'CHK-PAX'");
    $c->query("DELETE FROM branches WHERE code = 'CHK-BR'");
    $c->query("DELETE FROM companies WHERE code = 'CHK-B'");
    $c->query("DELETE FROM trip_assignments WHERE reason = 'reports-check'");
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
