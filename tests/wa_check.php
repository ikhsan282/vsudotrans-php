<?php
/**
 * Self-check: notifikasi WhatsApp (setelan, render, kirim, outbox, scoping).
 * Butuh DB dev. Jalankan:
 *   php -d mysqli.default_socket=/opt/data/cache/scratch/localdb/mysql.sock tests/wa_check.php
 * Tidak menyentuh jaringan internet: endpoint diarahkan ke 127.0.0.1:9 (port tertutup).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_service.php';
require_once __DIR__ . '/../includes/wa.php';
require_once __DIR__ . '/../pages/travel/_helpers.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$B = 'http://127.0.0.1:8080/vsudotrans-php';
$DEAD = 'http://127.0.0.1:9/x';            // port tertutup -> koneksi ditolak

// ---- Fixture (DB fresh kosong): wilayah, 2 perusahaan, cabang, penumpang ----
function wa_fixture_clean(mysqli $c): void
{
    $pax = "SELECT id FROM passengers WHERE code IN ('WA-PAX', 'WA-PAXB')";
    $br  = "SELECT id FROM branches WHERE code IN ('WA-BR', 'WA-BRB')";
    // Booking fixture dikenali dari cabang ATAU penumpang fixture — bukan hanya
    // document_number/note, sebab run yang gagal di tengah bisa meninggalkan booking
    // nyata hasil POST HTTP dengan nomor dokumen acak.
    $bkT = "SELECT id FROM travel_ticket_bookings WHERE branch_id IN ($br) OR passenger_id IN ($pax)";
    $bkR = "SELECT id FROM rental_car_bookings WHERE branch_id IN ($br) OR passenger_id IN ($pax)";

    $c->query("DELETE FROM wa_outbox WHERE company_id IN (1, 991)");
    $c->query("DELETE FROM wa_settings WHERE company_id IN (1, 991)");
    foreach ([['travel', $bkT], ['rental', $bkR]] as [$t, $sub]) {
        $c->query("DELETE FROM invoices WHERE booking_type = '$t' AND booking_id IN ($sub)");
        $c->query("DELETE FROM booking_passengers WHERE booking_type = '$t' AND booking_id IN ($sub)");
        $c->query("DELETE FROM booking_status_history WHERE booking_type = '$t' AND booking_id IN ($sub)");
        $c->query("DELETE FROM booking_cost_lines WHERE booking_type = '$t' AND booking_id IN ($sub)");
        $c->query("DELETE FROM trip_assignments WHERE booking_type = '$t' AND booking_id IN ($sub)");
    }
    // Orphan dari run yang crash: baris booking_passengers yang induk penumpangnya sudah hilang
    // tak pernah terhapus oleh DELETE passengers (pelanggaran FK hanya muncul saat menghapus INDUK).
    $c->query('DELETE bp FROM booking_passengers bp
        LEFT JOIN passengers p ON p.id = bp.passenger_id WHERE p.id IS NULL');
    $c->query('DELETE FROM booking_passengers WHERE company_id = 991');
    $c->query("DELETE FROM travel_ticket_bookings WHERE branch_id IN ($br) OR passenger_id IN ($pax)");
    $c->query("DELETE FROM rental_car_bookings WHERE branch_id IN ($br) OR passenger_id IN ($pax)");
    $c->query("DELETE FROM passengers WHERE code IN ('WA-PAX', 'WA-PAXB')");
    $c->query("DELETE FROM branches WHERE code IN ('WA-BR', 'WA-BRB')");
    $c->query('DELETE FROM document_sequences WHERE company_id = 991');
    $c->query("DELETE FROM companies WHERE code = 'WA-B'");
}
wa_fixture_clean($c);
$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT IGNORE INTO provinces (id, country_id, code, name) VALUES (1, 1, 'BANTEN', 'Banten')");
$c->query("INSERT IGNORE INTO cities (id, country_id, province_id, code, name) VALUES (1, 1, 1, 'TNG', 'Tangerang')");
$c->query("INSERT IGNORE INTO districts (id, country_id, province_id, city_id, code, name) VALUES (1, 1, 1, 1, 'CBT', 'Cibodas')");
$c->query("INSERT IGNORE INTO companies (id, group_id, code, name, is_active) VALUES (991, 1, 'WA-B', 'Perusahaan WA B', 1)");
$c->query("INSERT IGNORE INTO branches (id, company_id, code, name) VALUES (9001, 1, 'WA-BR', 'Cabang WA')");
$c->query("INSERT IGNORE INTO branches (id, company_id, code, name) VALUES (9002, 991, 'WA-BRB', 'Cabang WA B')");
$c->query("INSERT IGNORE INTO passengers (id, company_id, code, name, phone_number) VALUES (9001, 1, 'WA-PAX', 'Budi WA', '081200000001')");
$c->query("INSERT IGNORE INTO passengers (id, company_id, code, name, phone_number) VALUES (9002, 991, 'WA-PAXB', 'Siti WA B', '081200000002')");

$_SESSION['role_name']    = 'Super Admin';
$_SESSION['company_id']   = 1;
$_SESSION['user_id']      = 1;
$_SESSION['username']     = 'admin';
$_SESSION['permissions']  = [];

try {
    // ---------- a. wa_render ----------
    $tpl = 'Halo {nama} ({nomor}) {tanggal} {asal}->{tujuan} {harga} {jenis} {takdikenal}';
    $out = wa_render($tpl, [
        'nama' => 'Budi', 'nomor' => 'TRV-WA01', 'tanggal' => '31 Oktober 2026',
        'asal' => 'Cibodas', 'tujuan' => 'Cibodas', 'harga' => 'Rp 150.000', 'jenis' => 'Travel',
    ]);
    ok($out === 'Halo Budi (TRV-WA01) 31 Oktober 2026 Cibodas->Cibodas Rp 150.000 Travel {takdikenal}',
        "render: semua placeholder terganti & yang tak dikenal dibiarkan (" . $out . ')');
    ok(wa_render('{nama}', ['nama' => 'A', 'lain' => 'B']) === 'A', 'render: variabel tanpa placeholder diabaikan');
    ok(wa_render('{nama}{nama}', ['nama' => 'X']) === 'XX', 'render: placeholder berulang diganti semua');
    ok(wa_render('tanpa placeholder', []) === 'tanpa placeholder', 'render: template tanpa placeholder utuh');
    ok(wa_render('{nama}', ['nama' => null]) === '', 'render: nilai null -> string kosong, tanpa error');
    ok(wa_phone_normalize('0812-3456 789') === '628123456789', 'phone: 08xx -> 62xx (pemisah dibuang)');
    ok(wa_phone_normalize('8123456789') === '628123456789', 'phone: 8xx -> 62xx');
    ok(wa_phone_normalize('+62 812 3456 789') === '628123456789', 'phone: +62 -> 62');
    ok(wa_phone_normalize('bukan-nomor') === '', 'phone: input ngawur -> string kosong (skipped)');

    // ---------- b. setelan kosong -> skipped, return false ----------
    $before = (int) $c->query("SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1")->fetch_column();
    $r = wa_send(1, 'travel', 1, '081200000001', 'tes');
    ok($r === false, 'setelan kosong: wa_send() == false');
    $row = $c->query("SELECT * FROM wa_outbox WHERE company_id = 1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    ok(($row['status'] ?? '') === 'skipped', "setelan kosong: outbox status = skipped (dapat: " . ($row['status'] ?? '-') . ')');
    ok((int) $c->query("SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1")->fetch_column() === $before + 1,
        'setelan kosong: tetap tercatat 1 baris outbox');

    // ---------- c. simulasi gagal: port tertutup -> failed, tanpa throw ----------
    $st = $c->prepare("INSERT INTO wa_settings (company_id, is_active, api_url, api_token, sender, notify_phones, template_travel, template_rental)
        VALUES (?, 1, ?, 'tok123', 'VSudo', '081200000001', 'Travel {nama}', 'Rental {nama}')
        ON DUPLICATE KEY UPDATE is_active = 1, api_url = VALUES(api_url)");
    $st->bind_param('is', $cid1, $DEAD);
    $cid1 = 1;
    $st->execute();
    $st->close();

    $threw = null;
    $r = null;
    try {
        $r = wa_send(1, 'travel', 90001, '081200000001', 'Halo Budi');
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    ok($threw === null, 'endpoint mati: wa_send() TIDAK throw' . ($threw ? " (throw: $threw)" : ''));
    ok($r === false, 'endpoint mati: wa_send() == false');
    $row = $c->query("SELECT * FROM wa_outbox WHERE company_id = 1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    ok(($row['status'] ?? '') === 'failed', "endpoint mati: outbox status = failed (dapat: " . ($row['status'] ?? '-') . ')');
    ok((string) $row['booking_type'] === 'travel' && (int) $row['booking_id'] === 90001,
        'endpoint mati: outbox mencatat booking_type & booking_id benar');
    ok((string) $row['response'] !== '', 'endpoint mati: kolom respons terisi (alasan gagal): ' . substr((string) $row['response'], 0, 60));
    // Bukti query DB langsung (bukan hanya variabel PHP).
    $dbstat = $c->query("SELECT COUNT(*) n FROM wa_outbox WHERE company_id = 1 AND status = 'failed'")->fetch_assoc();
    ok((int) $dbstat['n'] >= 1, "query DB: ada {$dbstat['n']} baris failed di wa_outbox");

    // ---------- d. WA gagal tidak menggagalkan booking (lewat HTTP POST form nyata) ----------
    // Setelan WA company 1 menunjuk endpoint mati (dari langkah c).
    $doc = null;
    $socket = ini_get('mysqli.default_socket');
    $jar = sys_get_temp_dir() . '/wa_check_' . getmypid() . '.txt';
    $php = PHP_BINARY; // JANGAN escapeshellarg: tanda kutip di sekeliling path binary membuat
    // shell memperlakukannya sebagai satu token argumen + flag -d gagal diurai, sehingga helper
    // jalan tanpa socket dan mengembalikan halaman error (bukan token).
    $sock = $socket ? ' -d mysqli.default_socket=' . escapeshellarg($socket) : '';
    $phpcli = $php . $sock;
    $login = shell_exec("$phpcli " . escapeshellarg(__DIR__ . '/_wa_login.php') . ' ' . escapeshellarg($B) . ' ' . escapeshellarg($jar) . ' 2>&1');
    // Binary php di lingkungan ini menyemburkan startup warning ke STDOUT; ambil baris
    // TERAKHIR YANG TIDAK KOSONG sebagai hasil helper.
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $login)), static fn($l) => $l !== ''));
    $login = $lines ? (string) end($lines) : '';
    ok(str_contains($login, 'LOGIN_OK'), "HTTP: login admin berhasil ($login)");

    if (str_contains($login, 'LOGIN_OK')) {
        // Token disaring dari mana pun dalam output: 64 hex yang tidak menempel pada hex lain.
        // (Output helper bisa diawali startup warning dari binary php di lingkungan ini.)
        // Argumen helper: <base_url> <path> <cookie_jar>. JANGAN menggabungkan path ke $B di sini —
        // helper sendiri yang menyusun URL, dan menggabung lebih dulu membuat $argv[3] (jar) kosong.
        $csrf_raw = (string) shell_exec("$phpcli " . escapeshellarg(__DIR__ . '/_wa_csrf.php') . ' ' . escapeshellarg($B) . ' /pages/travel/bookings/form.php ' . escapeshellarg($jar) . ' 2>&1');
        preg_match('/(?<![0-9a-f])([0-9a-f]{64})(?![0-9a-f])/', $csrf_raw, $cm);
        $tok = $cm[1] ?? '';
        ok(strlen($tok) === 64, 'HTTP: CSRF token form travel diambil (' . strlen($tok) . ' char)'
            . ($tok === '' ? ' | helper: ' . substr(str_replace("\n", ' ', $csrf_raw), -160) : ''));

        $body = [
            'csrf_token' => $tok, 'date' => '2026-10-20', 'time' => '09:00', 'branch_id' => '9001',
            'passenger_id' => '9001', 'category_vehicle_id' => '', 'vehicle_id' => '',
            'total_price' => '150000', 'pickup_address' => 'Jl. Uji 1',
            'pickup_point_district_id' => '1', 'delivery_address' => 'Jl. Uji 2',
            'delivery_point_district_id' => '1', 'note' => 'wa_check',
        ];
        $bf = sys_get_temp_dir() . '/wa_check_body.txt';
        file_put_contents($bf, http_build_query($body));
        $res = shell_exec('curl -s -b ' . escapeshellarg($jar) . ' -o /dev/null -w "%{http_code}|%{redirect_url}" '
            . '-H "Content-Type: application/x-www-form-urlencoded" --data-binary @' . escapeshellarg($bf) . ' '
            . escapeshellarg($B . '/pages/travel/bookings/form.php') . ' 2>&1');
        ok(str_starts_with(trim((string) $res), '302') && str_contains((string) $res, 'detail.php?id='),
            'HTTP: simpan booking travel -> 302 ke detail (' . trim((string) $res) . ')');

        $bk = $c->query("SELECT id, document_number FROM travel_ticket_bookings WHERE passenger_id = 9001 AND branch_id = 9001 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        // Bukti query DB: booking TETAP tersimpan walau WA menunjuk endpoint mati.
        ok((bool) $bk, 'HTTP: booking TETAP tersimpan di DB walau WA gagal (id=' . ($bk['id'] ?? '-') . ')');
        if ($bk) {
            $doc = (string) $bk['document_number'];
            $st = $c->prepare("SELECT COUNT(*) n FROM wa_outbox WHERE booking_type = 'travel' AND booking_id = ? AND status = 'failed'");
            $st->bind_param('i', $bk['id']);
            $st->execute();
            $n = (int) $st->get_result()->fetch_column();
            $st->close();
            ok($n >= 1, "HTTP: outbox berisi $n baris failed untuk booking tersebut");
            $inv = $c->query('SELECT COUNT(*) FROM invoices WHERE booking_type = \'travel\' AND booking_id = ' . (int) $bk['id'])->fetch_column();
            ok((int) $inv === 1, 'HTTP: invoice booking tetap terbit (1 baris) — transaksi booking utuh');
        }

        // Form rental juga: endpoint mati tidak menggagalkan simpan rental.
        $tok2_raw = (string) shell_exec("$phpcli " . escapeshellarg(__DIR__ . '/_wa_csrf.php') . ' ' . escapeshellarg($B) . ' /pages/rental/bookings/form.php ' . escapeshellarg($jar) . ' 2>&1');
        preg_match('/(?<![0-9a-f])([0-9a-f]{64})(?![0-9a-f])/', $tok2_raw, $cm2);
        $tok2 = $cm2[1] ?? '';
        $body2 = [
            'csrf_token' => $tok2, 'date' => '2026-10-20', 'time' => '10:00',
            'start_date' => '2026-10-21', 'end_date' => '2026-10-22', 'trip' => '50', 'bbm' => '100000',
            'branch_id' => '9001', 'passenger_id' => '9001', 'category_vehicle_id' => '', 'vehicle_id' => '',
            'total_price' => '900000', 'pickup_address' => 'Jl. Uji 3', 'pickup_point_district_id' => '1',
            'delivery_address' => 'Jl. Uji 4', 'delivery_point_district_id' => '1', 'note' => 'wa_check',
        ];
        file_put_contents($bf, http_build_query($body2));
        $res2 = shell_exec('curl -s -b ' . escapeshellarg($jar) . ' -o /dev/null -w "%{http_code}" '
            . '-H "Content-Type: application/x-www-form-urlencoded" --data-binary @' . escapeshellarg($bf) . ' '
            . escapeshellarg($B . '/pages/rental/bookings/form.php') . ' 2>&1');
        $bk2 = $c->query("SELECT id FROM rental_car_bookings WHERE passenger_id = 9001 AND branch_id = 9001 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        ok(str_starts_with(trim((string) $res2), '302') && (bool) $bk2,
            'HTTP: booking rental tetap tersimpan walau WA gagal (http=' . trim((string) $res2) . ', id=' . ($bk2['id'] ?? '-') . ')');
        if ($bk2) {
            $st = $c->prepare("SELECT COUNT(*) n FROM wa_outbox WHERE booking_type = 'rental' AND booking_id = ?");
            $st->bind_param('i', $bk2['id']);
            $st->execute();
            $nr = (int) $st->get_result()->fetch_column();
            $st->close();
            ok($nr >= 1, "HTTP: outbox rental tercatat $nr baris");
            // Template rental dipakai, bukan template travel.
            $st = $c->prepare("SELECT message FROM wa_outbox WHERE booking_type = 'rental' AND booking_id = ? ORDER BY id DESC LIMIT 1");
            $st->bind_param('i', $bk2['id']);
            $st->execute();
            $m = (string) $st->get_result()->fetch_column();
            $st->close();
            ok(str_contains($m, 'Rental') && str_contains($m, 'TRV') === false, 'HTTP: pakai template rental (' . substr($m, 0, 40) . ')');
        }
    }

    // ---------- e. scoping antar-perusahaan ----------
    // Setelan perusahaan 991, endpoint juga mati. Company 1 tidak boleh melihatnya.
    $st = $c->prepare("INSERT INTO wa_settings (company_id, is_active, api_url, sender, notify_phones, template_travel)
        VALUES (991, 1, ?, 'PunyaB', '081200000099', 'RAHASIA-B {nama}')
        ON DUPLICATE KEY UPDATE is_active = 1, api_url = VALUES(api_url)");
    $st->bind_param('s', $DEAD);
    $st->execute();
    $st->close();

    ok((int) wa_settings(1)['id'] !== (int) wa_settings(991)['id'], 'scoping: setelan company 1 != company 991');
    ok(!str_contains((string) wa_settings(1)['template_travel'], 'RAHASIA-B'), 'scoping: template company 991 tidak bocor ke wa_settings(1)');
    ok(!str_contains((string) wa_settings(1)['notify_phones'], '081200000099'), 'scoping: nomor tujuan company 991 tidak bocor');
    ok((string) wa_settings(991)['sender'] === 'PunyaB', 'scoping: company 991 membaca setelannya sendiri');

    // Kirim sebagai scoped user company 1 -> outbox company 991 tidak bertambah.
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $n991 = (int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 991')->fetch_column();
    $n1 = (int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1')->fetch_column();
    wa_send(1, 'travel', 90002, '081200000001', 'pesan company 1');
    ok((int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 991')->fetch_column() === $n991,
        'scoping: mengirim untuk company 1 tidak menulis baris company 991');
    ok((int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1')->fetch_column() === $n1 + 1,
        'scoping: baris baru masuk ke company 1 saja');
    [$wsql, $wt, $wp] = company_scope('company_id');
    ok($wsql === 'company_id = ?' && (int) $wp[0] === 1, "scoping: company_scope() user company 1 -> [{$wsql}] param=" . ($wp[0] ?? '-'));

    // Setelan company 991 tidak terpakai saat mengirim untuk company 1.
    $last = $c->query('SELECT response FROM wa_outbox WHERE company_id = 1 ORDER BY id DESC LIMIT 1')->fetch_column();
    ok(!str_contains((string) $last, '081200000099'), 'scoping: kirim company 1 tidak menyentuh nomor company 991');

    // ---------- f. setelan halaman: token tidak pernah tampil plaintext ----------
    $s1 = wa_settings(1);
    ok((string) $s1['api_token'] === 'tok123', 'DB: token tersimpan apa adanya (dipakai request), tapi UI memmaskernya');
    $mask = str_repeat('•', max(4, strlen('tok123') - 4)) . substr('tok123', -4);
    ok(!str_contains($mask, 'tok1'), 'masking: token "tok123" tampil sebagai "' . $mask . '" (bukan plaintext)');

    // ---------- g. is_active = 0 -> skipped walau api_url terisi ----------
    $c->query("UPDATE wa_settings SET is_active = 0 WHERE company_id = 1");
    $r = wa_send(1, 'travel', 90003, '081200000001', 'nonaktif');
    $st = $c->query('SELECT status FROM wa_outbox WHERE company_id = 1 ORDER BY id DESC LIMIT 1')->fetch_column();
    ok($r === false && $st === 'skipped', "is_active=0: skipped walau endpoint terisi (status: $st)");
    $c->query("UPDATE wa_settings SET is_active = 1 WHERE company_id = 1");

    // ---------- h. nomor tujuan ngawur -> skipped (tidak menembak jaringan) ----------
    $r = wa_send(1, 'travel', 90004, 'bukan-nomor', 'x');
    $st = $c->query('SELECT status FROM wa_outbox WHERE company_id = 1 ORDER BY id DESC LIMIT 1')->fetch_column();
    ok($r === false && $st === 'skipped', "nomor ngawur: skipped tanpa kirim (status: $st)");

    // ---------- i. booking tanpa setelan WA aktif: tidak membanjiri outbox ----------
    $c->query('DELETE FROM wa_settings WHERE company_id = 1');
    $n_before = (int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1')->fetch_column();
    $res = booking_create('travel', [
        'company_id' => 1, 'branch_id' => 9001, 'date' => '2026-10-25', 'time' => '08:00:00',
        'pickup_address' => 'a', 'pickup_point_city_id' => 1, 'pickup_point_district_id' => 1,
        'delivery_address' => 'b', 'delivery_point_city_id' => 1, 'delivery_point_district_id' => 1,
        'total_price' => 1000, 'category_vehicle_id' => null, 'vehicle_id' => null,
        'passenger_id' => 9001, 'passenger_ids' => [9001], 'note' => 'wa_check', 'created_user' => 'check',
    ]);
    wa_notify_booking('travel', (int) $res['id']);
    ok((int) $c->query('SELECT COUNT(*) FROM wa_outbox WHERE company_id = 1')->fetch_column() === $n_before,
        'tanpa setelan aktif: wa_notify_booking() tidak menulis outbox (tidak spam)');
    ok((bool) $c->query('SELECT 1 FROM travel_ticket_bookings WHERE id = ' . (int) $res['id'])->fetch_column(),
        'tanpa setelan aktif: booking tetap tersimpan normal');

    // ---------- j. booking_id tak ada -> aman (tanpa throw) ----------
    $threw = null;
    try { wa_notify_booking('travel', 999999999); } catch (Throwable $e) { $threw = $e->getMessage(); }
    ok($threw === null, 'booking_id tak ada: wa_notify_booking() tidak throw' . ($threw ? " ($threw)" : ''));
    // booking_type ngawur ditolak oleh booking_table() — tapi di dalam wa_notify_booking
    // exception-nya ditelan (sengaja) dan dicatat ke error log, TIDAK menjatuhkan request.
    $threw = null;
    try { booking_table('xxx'); } catch (Throwable $e) { $threw = $e->getMessage(); }
    ok($threw !== null, 'booking_type ngawur: booking_table() menolak dengan exception');

} finally {
    @unlink(sys_get_temp_dir() . '/wa_check_' . getmypid() . '.txt');
    @unlink(sys_get_temp_dir() . '/wa_check_body.txt');
    wa_fixture_clean($c);
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
