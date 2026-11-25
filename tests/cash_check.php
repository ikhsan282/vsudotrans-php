<?php
/**
 * Self-check modul Buku Kas (tanpa HTTP, tanpa Akun Kas/Bank). Butuh DB dev:
 *   php tests/cash_check.php
 *
 * Fixture terisolasi (perusahaan 990): masuk 400.000 (30 Sep → saldo awal Okt),
 * masuk 500.000 (5 Okt) & keluar 200.000 (10 Okt), keluar 50.000 (20 Nov, luar rentang Okt).
 * Perusahaan 991 punya 8.000.000 (6 Okt) untuk uji scoping lintas perusahaan.
 * Lulus dari DB kosong maupun DB ber-seed; seluruh fixture dibersihkan di blok finally.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/finance/_cash_query.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
finance_ensure_schema();

$CID_A  = 990;
$CID_B  = 991;
$TX_PRE = 9900;  // 30 Sep (sebelum rentang Okt)
$TX_IN  = 9901;  // 5 Okt
$TX_OUT = 9902;  // 10 Okt
$TX_B   = 9903;  // 6 Okt milik company 991
$TX_OOR = 9904;  // 20 Nov (di luar rentang Okt)

$cleanup = static function () use ($c): void {
    $c->query("DELETE FROM cash_transactions WHERE created_user IN ('cash-check', 'cash-probe') OR id BETWEEN 9900 AND 9910 OR company_id IN (990, 991)");
    $c->query("DELETE FROM companies WHERE id IN (990, 991) OR code IN ('CHK-KAS', 'CHK-KASB')");
};
$cleanup();

$c->query("INSERT IGNORE INTO countries (id, code, name) VALUES (1, 'ID', 'Indonesia')");
$c->query("INSERT INTO companies (id, group_id, code, name, is_active) VALUES
    ($CID_A, 1, 'CHK-KAS',  'Perusahaan Cek Kas A', 1),
    ($CID_B, 1, 'CHK-KASB', 'Perusahaan Cek Kas B', 1)");
$ins = static function (int $id, int $cid, string $date, string $dir, float $amt, string $cat, string $desc) use ($c): void {
    $st = $c->prepare("INSERT INTO cash_transactions (id, company_id, date, direction, amount, category, description, created_user)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'cash-check')");
    $st->bind_param('iissdss', $id, $cid, $date, $dir, $amt, $cat, $desc);
    $st->execute();
    $st->close();
};
$ins($TX_PRE, $CID_A, '2026-09-30', 'in', 400000, 'setoran', 'Saldo sebelum rentang');
$ins($TX_IN,  $CID_A, '2026-10-05', 'in', 500000, 'setoran', 'Masuk Cek');
$ins($TX_OUT, $CID_A, '2026-10-10', 'out', 200000, 'bbm', 'Keluar Cek');
$ins($TX_B,   $CID_B, '2026-10-06', 'in', 8000000, 'setoran', 'Masuk Cek B');
$ins($TX_OOR, $CID_A, '2026-11-20', 'out', 50000, 'lain', 'Luar rentang');

// Sesi: Super Admin memfilter company 990 -> lalu diturunkan jadi user perusahaan 990 untuk uji scoping.
$_SESSION['username'] = 'cash-check';
$_SESSION['role_name'] = 'Super Admin';
$_SESSION['company_id'] = $CID_A;

$f = cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'direction' => 'all', 'company_id' => (string) $CID_A]);

try {
    // --- (a) Saldo: awal = akumulasi sebelum `from`; akhir = akumulasi sampai `to` ---
    $s = cash_summary($f);
    ok(abs($s['opening'] - 400000) < 0.01, 'saldo awal (sebelum 1 Okt) = 400.000 (dapat ' . $s['opening'] . ')');
    ok(abs($s['total_in'] - 500000) < 0.01, 'total masuk rentang = 500.000');
    ok(abs($s['total_out'] - 200000) < 0.01, 'total keluar rentang = 200.000');
    ok(abs($s['balance'] - 700000) < 0.01, 'saldo akhir = 400k + 500k - 200k = 700.000 (dapat ' . $s['balance'] . ')');
    ok(abs($s['opening'] + $s['total_in'] - $s['total_out'] - $s['balance']) < 0.01, 'rumus: awal + masuk - keluar = akhir');

    $f_nov = cash_filter(['from' => '2026-11-01', 'to' => '2026-11-30', 'company_id' => (string) $CID_A]);
    $s_nov = cash_summary($f_nov);
    ok(abs($s_nov['opening'] - 700000) < 0.01, 'saldo awal November = saldo akhir Oktober (700.000)');
    ok(abs($s_nov['total_out'] - 50000) < 0.01, 'total keluar November = 50.000');
    ok(abs($s_nov['balance'] - 650000) < 0.01, 'saldo s/d 30 Nov = 650.000');

    // --- (b) Filter rentang & arah ---
    $rows_oct = cash_tx_list($f);
    ok(count($rows_oct) === 2, 'filter rentang: 2 transaksi Oktober (dapat ' . count($rows_oct) . ')');
    ok(cash_tx_count($f) === 2, 'cash_tx_count cocok dengan daftar');
    $dates = array_column($rows_oct, 'date');
    ok($dates === ['2026-10-05', '2026-10-10'], 'urut naik & tanggal dalam rentang: ' . implode(', ', $dates));
    $f_in = cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'direction' => 'in', 'company_id' => (string) $CID_A]);
    $rows_in = cash_tx_list($f_in);
    ok(count($rows_in) === 1 && $rows_in[0]['direction'] === 'in', 'filter arah=in: hanya transaksi masuk');
    ok(abs(cash_summary($f_in)['total_out']) < 0.01, 'filter arah=in: total keluar 0');
    ok(abs(cash_summary($f_in)['balance'] - 700000) < 0.01, 'filter arah=in: saldo tetap 700.000 (bukan saldo terfilter)');
    ok(cash_tx_list(cash_filter(['from' => '2026-12-01', 'to' => '2026-12-31', 'company_id' => (string) $CID_A])) === [], 'rentang kosong: tidak ada baris');

    $fx = cash_filter(['from' => 'bukan-tanggal', 'to' => '', 'direction' => 'xxx', 'company_id' => (string) $CID_A]);
    ok($fx['direction'] === 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fx['from']),
        'filter ngawur (tanggal/arah) → default aman');
    $err = null;
    try { cash_tx_list($fx); cash_summary($fx); } catch (Throwable $e) { $err = $e->getMessage(); }
    ok($err === null, 'filter ngawur: tidak ada SQL error');

    // --- (c) Validasi ---
    $base = ['date' => '2026-10-15', 'direction' => 'in', 'amount' => '1000'];
    ok(cash_tx_validate($base, $CID_A) === [], 'input valid: tidak ada error');
    ok(count(cash_tx_validate(['date' => '2026-10-15', 'direction' => 'in', 'amount' => '0'], $CID_A)) === 1, 'jumlah 0 ditolak');
    ok(count(cash_tx_validate(['date' => '2026-10-15', 'direction' => 'out', 'amount' => '-5'], $CID_A)) === 1, 'jumlah negatif ditolak');
    ok(count(cash_tx_validate(['date' => '2026-10-15', 'direction' => 'out', 'amount' => 'abc'], $CID_A)) === 1, 'jumlah bukan angka ditolak');
    ok(count(cash_tx_validate(['date' => '', 'direction' => 'in', 'amount' => '10'], $CID_A)) === 1, 'tanggal kosong ditolak');
    ok(count(cash_tx_validate(['date' => '2026-02-30', 'direction' => 'in', 'amount' => '10'], $CID_A)) === 1, 'tanggal tidak valid (30 Feb) ditolak');
    ok(count(cash_tx_validate(['date' => '2026-10-15', 'direction' => 'xx', 'amount' => '10'], $CID_A)) === 1, 'arah selain in/out ditolak');
    ok(count(cash_tx_validate($base + ['category' => str_repeat('x', 51)], $CID_A)) === 1, 'kategori > 50 karakter ditolak');
    ok(count(cash_tx_validate($base + ['description' => str_repeat('x', 1001)], $CID_A)) === 1, 'keterangan > 1000 karakter ditolak');

    $new_id = cash_tx_save(0, $base + ['category' => 'cek'], $CID_A, 'cash-check');
    ok($new_id > 0, "cash_tx_save insert id baru ($new_id)");
    ok(abs(cash_summary($f)['balance'] - 701000) < 0.01, 'setelah insert 1.000: saldo 701.000');
    $updated = cash_tx_save($new_id, ['date' => '2026-10-15', 'direction' => 'out', 'amount' => '4000'], $CID_A, 'cash-check');
    ok($updated === $new_id && abs(cash_summary($f)['balance'] - 696000) < 0.01, 'update arah in→out 4.000: saldo 696.000');

    // --- (d) Scoping antar perusahaan (admin company 1 tidak boleh akses company 990/991) ---
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = 1;
    $all_rows = cash_tx_list(cash_filter(['from' => '2026-10-01', 'to' => '2026-11-30']));
    $leaked = array_filter($all_rows, static fn($r) => in_array((int) $r['company_id'], [$CID_A, $CID_B], true));
    ok($leaked === [], 'scoping: transaksi perusahaan 990/991 tidak muncul untuk company 1');

    // Sekarang uji Admin Perusahaan 990 -> tidak melihat 8.000.000 milik 991.
    $_SESSION['company_id'] = $CID_A;
    $scA = cash_summary(cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31']));
    ok(abs($scA['total_in'] - 500000) < 0.01, 'scoping: total masuk company 990 = 500.000 (8.000.000 milik 991 tidak ikut)');

    // Update lintas perusahaan (dari company 1 mencoba update baris 991)
    $_SESSION['company_id'] = 1;
    $before = (float) $c->query("SELECT amount FROM cash_transactions WHERE id = $TX_B")->fetch_row()[0];
    cash_tx_save($TX_B, ['date' => '2026-10-06', 'direction' => 'out', 'amount' => '1'], 1, 'cash-check');
    $row_b = $c->query("SELECT amount, direction, company_id FROM cash_transactions WHERE id = $TX_B")->fetch_assoc();
    ok((float) $row_b['amount'] === $before && $row_b['direction'] === 'in', 'scoping: update lintas perusahaan tidak mengubah transaksi 991');

    $probe = __DIR__ . '/_cash_scope_probe.php';
    $php   = PHP_BINARY;
    $ini   = (string) get_cfg_var('cfg_file_path');
    $out1 = (static function () use ($php, $probe, $ini, $TX_B): string {
        $cmd = escapeshellarg($php) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '')
            . ' ' . escapeshellarg($probe) . ' tx ' . escapeshellarg((string) $TX_B) . ' 2>&1';
        exec($cmd, $out);
        return implode("\n", $out);
    })();
    ok(str_contains($out1, 'Akses Ditolak') && !str_contains($out1, 'BOLEH AKSES'), 'scoping: cash_tx_find(transaksi 991) → 403 Akses Ditolak');
    ok((int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE company_id = $CID_B AND id = $TX_B")->fetch_row()[0] === 1,
        'scoping: data company 991 utuh setelah percobaan akses');

    // --- Export ---
    $_SESSION['role_name'] = 'Super Admin';
    $tbl = cash_export_table(cash_filter(['from' => '2026-10-01', 'to' => '2026-10-31', 'company_id' => (string) $CID_A]));
    $flat = array_merge(...array_map(static fn($r) => array_map('strval', $r), $tbl));
    ok(in_array('2026-10-05', $flat, true) && in_array('Masuk Cek', $flat, true), 'export: baris transaksi ada');
    ok(!in_array('2026-11-20', $flat, true), 'export: baris di luar rentang tidak ikut');
    ok(array_values(array_filter($tbl, static fn($r) => ($r[4] ?? null) === -200000.0)) !== [], 'export: nominal keluar bertanda minus (-200000)');
    $last = $tbl[count($tbl) - 1];
    ok($last[0] === 'Saldo Akhir' && abs((float) $last[4] - 696000) < 0.01, 'export: baris Saldo Akhir = 696.000');
    ok($tbl[2] === ['Tanggal', 'Arah', 'Kategori', 'Keterangan', 'Nominal'], 'export: header 5 kolom ringkas tanpa Metode');

    // --- (e) Hapus bersih ---
    $_SESSION['role_name'] = 'Admin Perusahaan';
    $_SESSION['company_id'] = $CID_A;
    $st = $c->prepare('DELETE FROM cash_transactions WHERE id = ? AND company_id = ?');
    $st->bind_param('ii', $new_id, $CID_A);
    $st->execute();
    ok($st->affected_rows === 1, 'hapus: 1 baris terhapus');
    $st->close();
    ok(abs(cash_summary($f)['balance'] - 700000) < 0.01, 'setelah hapus: saldo kembali 700.000');
    ok((int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE id = $TX_B")->fetch_row()[0] === 1, 'hapus di company 990 tidak menyentuh transaksi company 991');

    // --- RBAC ---
    foreach ([[1, 'Super Admin'], [2, 'Admin Perusahaan']] as [$rid, $rname]) {
        $n = (int) $c->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = $rid AND p.name IN ('finance.view','finance.manage')")->fetch_row()[0];
        ok($n === 2, "$rname punya finance.view + finance.manage");
    }
    $n5 = (int) $c->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id IN (4,5) AND p.name = 'finance.manage'")->fetch_row()[0];
    ok($n5 === 0, 'Staff/Viewer tidak punya finance.manage (gate tulis tertutup)');

    // --- Struktur: tanpa Akun Kas/Bank, tanpa FK, indeks sesuai ---
    $hasAcc = (int) $c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cash_accounts'")->fetch_row()[0];
    ok($hasAcc === 0, 'tabel cash_accounts sudah tidak ada');
    $hasCol = (int) $c->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cash_transactions' AND column_name = 'cash_account_id'")->fetch_row()[0];
    ok($hasCol === 0, 'kolom cash_transactions.cash_account_id sudah tidak ada');
    $hasPmVeh = (int) $c->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cash_transactions' AND column_name IN ('payment_method_id', 'vehicle_id')")->fetch_row()[0];
    ok($hasPmVeh === 0, 'kolom cash_transactions.payment_method_id & vehicle_id sudah dihapus');
    $fk = (int) $c->query("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND table_name = 'cash_transactions'")->fetch_row()[0];
    ok($fk === 0, 'tanpa FOREIGN KEY (mengikuti pola booking_cost_lines)');
    $key = $c->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'cash_transactions' AND index_name = 'ix_cashtx_company_date'")->fetch_assoc();
    ok($key['cols'] === 'company_id,date', 'KEY ix_cashtx_company_date (company_id, date)');
} finally {
    $cleanup();
    $left_tx = (int) $c->query("SELECT COUNT(*) FROM cash_transactions WHERE created_user = 'cash-check'")->fetch_row()[0];
    ok($left_tx === 0, 'fixture terbersihkan (transaksi=' . $left_tx . ')');
}

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
