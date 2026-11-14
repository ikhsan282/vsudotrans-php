<?php
/**
 * Self-check modul Kas & Bank (tanpa HTTP). Butuh DB dev:
 *   php -c /opt/data/cache/scratch/localdb/php.ini tests/cash_check.php
 *
 * Fixture: 1 akun perusahaan 1 (saldo awal 1.000.000) + masuk 500.000 & keluar 200.000,
 * plus 1 akun perusahaan 990 dengan transaksinya sendiri untuk uji scoping.
 * Lulus dari DB fresh kosong; fixture dibersihkan di blok finally.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/finance/_cash_query.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();

// ---- Fixture (id tetap supaya aman diulang; dibersihkan dulu) ----
$ACC   = 5001;   // akun milik perusahaan 1
$ACC_B = 9902;   // akun milik perusahaan 990 (tenant lain)
$TX_IN = 9901;
$TX_OUT = 9902;
$TX_B  = 9903;

$c->query('DELETE FROM cash_transactions WHERE id IN (' . $TX_IN . ',' . $TX_OUT . ',' . $TX_B . ')');
$c->query('DELETE FROM cash_accounts WHERE id IN (' . $ACC . ',' . $ACC_B . ')');
$c->query("DELETE FROM cash_accounts WHERE company_id = 990 AND code = 'CHK-KB'");
// Sisa run yang terputus (mis. proses mati karena 403 di tengah uji) dibersihkan di depan,
// supaya self-check tetap lulus dari DB fresh maupun DB yang kotor.
$c->query("DELETE FROM cash_transactions WHERE company_id IN (1, 990) AND created_user IN ('cash-check', 'cash-probe')");
$c->query("DELETE FROM cash_accounts WHERE created_user IN ('cash-check', 'cash-probe')");
$c->query("DELETE FROM companies WHERE id = 990 OR code = 'CHK-KAS'");
$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT INTO companies (id, group_id, code, name, is_active)
           VALUES (990, 1, 'CHK-KAS', 'Perusahaan Cek Kas', 1)");
$c->query("INSERT INTO cash_accounts (id, company_id, code, name, type, opening_balance, is_active, created_user)
           VALUES ($ACC, 1, 'CHK-KAS', 'Kas Cek', 'kas', 1000000, 1, 'cash-check')");
$c->query("INSERT INTO cash_accounts (id, company_id, code, name, type, opening_balance, is_active, created_user)
           VALUES ($ACC_B, 990, 'CHK-KB', 'Kas Cek B', 'kas', 9000000, 1, 'cash-check')");
$c->query("INSERT INTO cash_transactions (id, company_id, cash_account_id, date, direction, amount, category, description, created_user)
           VALUES ($TX_IN, 1, $ACC, '2026-10-05', 'in', 500000, 'setoran', 'Masuk Cek', 'cash-check')");
$c->query("INSERT INTO cash_transactions (id, company_id, cash_account_id, date, direction, amount, category, description, created_user)
           VALUES ($TX_OUT, 1, $ACC, '2026-10-10', 'out', 200000, 'bbm', 'Keluar Cek', 'cash-check')");
$c->query("INSERT INTO cash_transactions (id, company_id, cash_account_id, date, direction, amount, category, description, created_user)
           VALUES ($TX_B, 990, $ACC_B, '2026-10-06', 'in', 8000000, 'setoran', 'Masuk Cek B', 'cash-check')");
// Transaksi perusahaan 1 di luar rentang uji: harus diabaikan filter, tapi ikut saldo sampai `to`.
$TX_OOR = 9904;
$c->query('DELETE FROM cash_transactions WHERE id = ' . $TX_OOR);
$c->query("INSERT INTO cash_transactions (id, company_id, cash_account_id, date, direction, amount, category, created_user)
           VALUES ($TX_OOR, 1, $ACC, '2026-11-20', 'out', 50000, 'lain', 'cash-check')");

// Sesi: Super Admin (lihat semua) → lalu diturunkan jadi user perusahaan 1 untuk uji scoping.
$_SESSION['username'] = 'cash-check';
$_SESSION['role_name'] = 'Super Admin';
$_SESSION['company_id'] = 1;

$f = cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'account' => (string) $ACC, 'direction' => 'all']);

try {
    // --- (a) Saldo akhir = saldo awal + Σin − Σout sampai `to` (hitung tangan) ---
    $s = cash_summary($f);
    $expect_balance = 1000000 + 500000 - 200000;      // 1.300.000 (transaksi 11-20 belum masuk)
    ok(abs($s['balance'] - $expect_balance) < 0.01,
        'saldo akhir = 1.300.000 (dapat ' . number_format($s['balance'], 2, ',', '.') . ')');
    ok(abs($s['opening'] - 1000000) < 0.01, 'saldo awal akun = 1.000.000');
    ok(abs($s['total_in'] - 500000) < 0.01, 'total masuk rentang = 500.000');
    ok(abs($s['total_out'] - 200000) < 0.01, 'total keluar rentang = 200.000');

    // Saldo sampai `to` di bulan berikutnya ikut menghitung transaksi di luar rentang Oktober.
    $f_nov = cash_filter(['from' => '2026-11-01', 'to' => '2026-11-30', 'account' => (string) $ACC]);
    $s_nov = cash_summary($f_nov);
    ok(abs($s_nov['balance'] - (1000000 + 500000 - 200000 - 50000)) < 0.01,
        'saldo s/d 30 Nov = 1.250.000 (transaksi 20 Nov ikut)');
    ok(abs($s_nov['total_out'] - 50000) < 0.01, 'total keluar November = 50.000');
    ok(abs($s_nov['total_in'] - 0) < 0.01, 'total masuk November = 0');

    // --- (b) Filter rentang & arah hanya memuat baris yang cocok ---
    $rows_oct = cash_tx_list($f);
    ok(count($rows_oct) === 2, 'filter rentang: 2 transaksi Oktober (dapat ' . count($rows_oct) . ')');
    ok(cash_tx_count($f) === 2, 'cash_tx_count cocok dengan daftar');
    $dates = array_column($rows_oct, 'date');
    ok($dates === ['2026-10-05', '2026-10-10'], 'urut naik & tanggal dalam rentang: ' . implode(', ', $dates));
    $f_in = cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'account' => (string) $ACC, 'direction' => 'in']);
    $rows_in = cash_tx_list($f_in);
    ok(count($rows_in) === 1 && $rows_in[0]['direction'] === 'in', 'filter arah=in: hanya transaksi masuk');
    ok(abs(cash_summary($f_in)['total_out']) < 0.01, 'filter arah=in: total keluar 0');
    ok(abs(cash_summary($f_in)['balance'] - 1300000) < 0.01, 'filter arah=in: saldo tetap 1.300.000 (bukan saldo terfilter)');
    ok(cash_tx_list(cash_filter(['from' => '2026-12-01', 'to' => '2026-12-31', 'account' => (string) $ACC])) === [],
        'rentang kosong: tidak ada baris');

    // Input filter ngawur → default aman, tanpa error SQL.
    $fx = cash_filter(['from' => 'bukan-tanggal', 'to' => '', 'account' => 'abc', 'direction' => 'xxx']);
    ok($fx['direction'] === 'all' && $fx['account'] === 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fx['from']),
        'filter ngawur (tanggal/akun/arah) → default aman');
    $err = null;
    try { cash_tx_list($fx); cash_summary($fx); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'filter ngawur: tidak ada SQL error');

    // --- (c) Validasi: jumlah ≤ 0, akun/tanggal kosong, arah ngawur ditolak ---
    $base = ['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '1000'];
    ok(cash_tx_validate($base, 1) === [], 'input valid: tidak ada error');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '0'], 1)) === 1,
        'jumlah 0 ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'out', 'amount' => '-5'], 1)) === 1,
        'jumlah negatif ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'out', 'amount' => 'abc'], 1)) === 1,
        'jumlah bukan angka ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => 0, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '10'], 1)) === 1,
        'akun kosong ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '', 'direction' => 'in', 'amount' => '10'], 1)) === 1,
        'tanggal kosong ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-02-30', 'direction' => 'in', 'amount' => '10'], 1)) === 1,
        'tanggal tidak valid (30 Feb) ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'xx', 'amount' => '10'], 1)) === 1,
        'arah selain in/out ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC_B, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '10'], 1)) === 1,
        'akun perusahaan lain (990) ditolak untuk company 1');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '10',
        'category' => str_repeat('x', 51)], 1)) === 1, 'kategori > 50 karakter ditolak');
    ok(count(cash_tx_validate(['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'in', 'amount' => '10',
        'description' => str_repeat('x', 1001)], 1)) === 1, 'keterangan > 1000 karakter ditolak');

    // Insert baru lewat jalur simpan (validasi + save) → saldo bertambah.
    $new_id = cash_tx_save(0, $base + ['category' => 'cek'], 1, 'cash-check');
    ok($new_id > 0, "cash_tx_save insert id baru ($new_id)");
    $s2 = cash_summary($f);
    ok(abs($s2['balance'] - ($expect_balance + 1000)) < 0.01,
        'setelah insert 1.000: saldo 1.301.000 (dapat ' . number_format($s2['balance'], 2, ',', '.') . ')');
    $updated = cash_tx_save($new_id, ['cash_account_id' => $ACC, 'date' => '2026-10-15', 'direction' => 'out',
        'amount' => '4000'], 1, 'cash-check');
    ok($updated === $new_id && abs(cash_summary($f)['balance'] - ($expect_balance - 4000)) < 0.01,
        'update arah in→out 4.000: saldo 996.000 (dapat ' . number_format(cash_summary($f)['balance'], 2, ',', '.') . ')');

    // --- (d) Scoping antar perusahaan ---
    $_SESSION['role_name'] = 'Admin Perusahaan'; // bukan Super Admin → company_scope aktif
    $_SESSION['company_id'] = 1;
    $all_rows = cash_tx_list(cash_filter(['from' => '2026-10-01', 'to' => '2026-11-30', 'account' => '0']));
    $leaked = array_filter($all_rows, static fn($r) => (int) $r['cash_account_id'] === $ACC_B);
    ok($leaked === [], 'scoping: transaksi akun perusahaan 990 tidak muncul untuk company 1');
    $accts = cash_account_list(false, 1);
    ok(!in_array($ACC_B, array_map('intval', array_column($accts, 'id')), true),
        'scoping: akun perusahaan 990 tidak ada di daftar akun company 1');
    ok(!cash_relation_ok($ACC_B, 1), 'scoping: akun 990 tidak lolos validasi relasi untuk company 1');
    ok(cash_relation_ok($ACC_B, 990), 'scoping: akun 990 sah untuk company 990 (bukti uji valid)');
    ok(abs(cash_summary(cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'account' => '0']))['balance']
        - (1000000 + 500000 - 200000 - 4000)) < 0.01,
        'scoping: saldo company 1 tidak terpengaruh uang perusahaan 990 (8.000.000)');

    // Update lintas perusahaan: WHERE company_id = 1 → 0 baris terpengaruh, data 990 utuh.
    $before = (float) $c->query("SELECT amount FROM cash_transactions WHERE id = $TX_B")->fetch_row()[0];
    cash_tx_save($TX_B, ['cash_account_id' => $ACC, 'date' => '2026-10-06', 'direction' => 'out', 'amount' => '1'], 1, 'cash-check');
    $row_b = $c->query("SELECT amount, cash_account_id, direction, company_id FROM cash_transactions WHERE id = $TX_B")->fetch_assoc();
    ok((float) $row_b['amount'] === $before && (int) $row_b['cash_account_id'] === $ACC_B,
        'scoping: update lintas perusahaan tidak mengubah transaksi 990');

    // Akses baris lintas perusahaan → 403 (assert_company_access memanggil exit).
    // Karena exit menghentikan proses, guard diuji di subproses lewat tests/_cash_scope_probe.php.
    // PHP keluar dengan kode 0 pada exit; penanda kegagalan/keberhasilan ada di teks output.
    $probe = __DIR__ . '/_cash_scope_probe.php';
    $php   = PHP_BINARY;
    $ini   = (string) get_cfg_var('cfg_file_path');
    $run   = static function (string $what, string $id) use ($php, $probe, $ini): string {
        $cmd = escapeshellarg($php) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '')
            . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($what) . ' ' . escapeshellarg($id) . ' 2>&1';
        exec($cmd, $out);
        return implode("\n", $out);
    };
    $out1 = $run('tx', (string) $TX_B);
    ok(str_contains($out1, 'Akses Ditolak') && !str_contains($out1, 'BOLEH AKSES'),
        'scoping: cash_tx_find(transaksi 990) → 403 Akses Ditolak');
    $out2 = $run('account', (string) $ACC_B);
    ok(str_contains($out2, 'Akses Ditolak') && !str_contains($out2, 'BOLEH AKSES'),
        'scoping: cash_account_find(akun 990) → 403 Akses Ditolak');
    // Kontrol: akun milik perusahaan sendiri harus lolos (probe tidak selalu menolak).
    $out3 = $run('account', (string) $ACC);
    ok(str_contains($out3, 'BOLEH AKSES'), 'kontrol: cash_account_find(akun company 1) lolos untuk company 1');
    // Data 990 tetap utuh setelah semua percobaan itu.
    ok((int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE company_id = 990 AND id = $TX_B")->fetch_row()[0] === 1,
        'scoping: data company 990 utuh setelah percobaan akses');

    // --- Export: isi tabel sama untuk CSV & XLSX ---
    $_SESSION['role_name'] = 'Super Admin';
    $tbl = cash_export_table(cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'account' => (string) $ACC]));
    $flat = array_merge(...array_map(static fn($r) => array_map('strval', $r), $tbl));
    ok(in_array('2026-10-05', $flat, true) && in_array('Masuk Cek', $flat, true), 'export: baris transaksi ada');
    ok(!in_array('2026-11-20', $flat, true), 'export: baris di luar rentang tidak ikut');
    $nominal = array_values(array_filter($tbl, static fn($r) => ($r[5] ?? null) === -200000.0));
    ok($nominal !== [], 'export: nominal keluar bertanda minus (-200000)');
    $last = $tbl[count($tbl) - 1];
    ok($last[0] === 'Saldo Akhir' && abs((float) $last[5] - (1000000 + 500000 - 200000 - 4000)) < 0.01,
        'export: baris TOTAL saldo akhir = 1.296.000');
    $masuk = array_values(array_filter($tbl, static fn($r) => ($r[0] ?? '') === 'Total Masuk'))[0];
    ok(abs((float) $masuk[5] - 500000) < 0.01, 'export: Total Masuk = 500.000 (hanya baris dalam rentang)');

    // --- (e) Hapus bersih ---
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $st = $c->prepare('DELETE FROM cash_transactions WHERE id = ? AND company_id = ?');
    $cid1 = 1;
    $st->bind_param('ii', $new_id, $cid1);
    $st->execute();
    ok($st->affected_rows === 1, 'hapus: 1 baris terhapus');
    $st->close();
    ok(abs(cash_summary($f)['balance'] - $expect_balance) < 0.01, 'setelah hapus: saldo kembali 1.300.000');
    // Hapus akun yang masih dipakai harus ditolak (dicek manual, tanpa FK).
    // Hitung baris lewat created_user, bukan hanya akun fixture: sisa run yang terputus
    // (proses mati di tengah uji) bisa meninggalkan baris tambahan di akun yang sama.
    $used = (int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE cash_account_id = $ACC")->fetch_row()[0];
    ok($used >= 2, "akun masih dipakai $used transaksi (dasar penolakan hapus akun)");
    ok((int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE id = $TX_B")->fetch_row()[0] === 1,
        'hapus di company 1 tidak menyentuh transaksi company 990');

    // --- RBAC: finance.view/manage hanya role yang berhak ---
    foreach ([[1, 'Super Admin'], [2, 'Admin Perusahaan']] as [$rid, $rname]) {
        $n = (int) $c->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = $rid AND p.name IN ('finance.view','finance.manage')")->fetch_row()[0];
        ok($n === 2, "$rname punya finance.view + finance.manage");
    }
    // Seed menganugerahkan hanya "%.view" ke Staff/Viewer, DAN hanya untuk modul yang
    // didaftar di blok grant (finance TIDAK termasuk). Yang benar-benar penting & stabil:
    // role terbatas TIDAK boleh punya finance.manage (gate tulis). Ini yang diuji.
    // (DB dev lama bisa saja masih memegang finance.view dari sebelum aturan ini — itu drift DB,
    //  bukan bug kode; fresh install = schema.sql, dan di sana finance.view tidak diberikan.)
    $n5 = (int) $c->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id IN (4,5) AND p.name = 'finance.manage'")->fetch_row()[0];
    ok($n5 === 0, 'Staff/Viewer tidak punya finance.manage (gate tulis tertutup)');

    // --- Struktur tabel: tanpa FOREIGN KEY, indeks sesuai spesifikasi ---
    $fk = (int) $c->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'
          AND table_name IN ('cash_accounts','cash_transactions')")->fetch_row()[0];
    ok($fk === 0, 'tanpa FOREIGN KEY (mengikuti pola booking_cost_lines)');
    $idx = $c->query("SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
        FROM information_schema.statistics WHERE table_schema = DATABASE()
          AND table_name IN ('cash_accounts','cash_transactions') AND non_unique = 0 GROUP BY index_name")->fetch_all(MYSQLI_ASSOC);
    $names = array_column($idx, 'index_name');
    ok(in_array('uq_cashacc_company_code', $names, true), 'UNIQUE uq_cashacc_company_code ada');
    $key = $c->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'cash_transactions'
          AND index_name = 'ix_cashtx_company_date'")->fetch_assoc();
    ok($key['cols'] === 'company_id,date', 'KEY ix_cashtx_company_date (company_id, date)');

} finally {
    // Bersihkan seluruh fixture.
    $c->query('DELETE FROM cash_transactions WHERE created_user = \'cash-check\' OR id IN (' . $TX_IN . ',' . $TX_OUT . ',' . $TX_B . ',' . $TX_OOR . ')');
    $c->query('DELETE FROM cash_accounts WHERE created_user = \'cash-check\' OR id IN (' . $ACC . ',' . $ACC_B . ')');
    $c->query("DELETE FROM cash_accounts WHERE company_id = 990 AND code = 'CHK-KB'");
    $c->query("DELETE FROM companies WHERE code = 'CHK-KAS'");
    $left = (int) $c->query("SELECT COUNT(*) FROM cash_accounts WHERE company_id IN (1, 990) AND code LIKE 'CHK%'")->fetch_row()[0];
    $left_tx = (int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE created_user = 'cash-check'")->fetch_row()[0];
    ok($left === 0 && $left_tx === 0, 'fixture terbersihkan (akun=' . $left . ', transaksi=' . $left_tx . ')');
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
