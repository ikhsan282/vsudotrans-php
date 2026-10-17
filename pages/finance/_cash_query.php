<?php
/**
 * Kas & Bank — semua query, validasi, dan angka ringkasan ada di sini.
 * Dipakai pages/finance/cash/{index,form,delete,export}.php dan tests/cash_check.php,
 * sehingga rumus saldo hanya hidup di satu tempat.
 *
 * Scope tenant: company_scope() untuk daftar, assert_company_access() untuk satu baris
 * (akun maupun transaksi). Akun sah = milik perusahaan aktif, dicek di SQL sebelum
 * dipakai menulis.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../travel/_helpers.php';

function cash_types(): array
{
    return ['kas' => 'Kas', 'bank' => 'Bank'];
}

function cash_directions(): array
{
    return ['in' => 'Masuk', 'out' => 'Keluar'];
}

function cash_direction_badge(string $d): string
{
    return $d === 'in'
        ? '<span class="badge bg-success">Masuk</span>'
        : '<span class="badge bg-danger">Keluar</span>';
}

/** Normalisasi filter buku kas. Arah/tanggal ngawur → default aman (bukan error). */
function cash_filter(?array $in = null): array
{
    $in = $in ?? $_GET;
    $dir = (string) ($in['direction'] ?? 'all');
    if (!isset(cash_directions()[$dir])) {
        $dir = 'all';
    }
    $d = static fn(string $k, string $def): string =>
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) ? (string) $in[$k] : $def;
    return [
        'from'      => $d('from', date('Y-m-01')),
        'to'        => $d('to', date('Y-m-t')),
        'account'   => (int) ($in['account'] ?? 0),
        'direction' => $dir,
    ];
}

/**
 * Klausa WHERE bersama untuk semua query transaksi buku kas.
 * @return array{sql:string, types:string, params:array}
 */
function cash_tx_where(array $f, bool $with_dates = true): array
{
    [$w, $types, $params] = company_scope('t.company_id');
    // company_scope() bisa mengembalikan klausa tanpa placeholder ('1=1' / '1=0') dengan types ''.
    // Jangan pernah menempelkan types milik klausa sendiri ke string kosong itu tanpa placeholder —
    // sebabkan ArgumentCountError di bind_param. Bangun types hanya dari klausa yang kita tambahkan.
    $sql = '';
    if ($with_dates) {
        $sql = 't.date >= ? AND t.date <= ?';
        $types .= 'ss';
        $params[] = $f['from'];
        $params[] = $f['to'];
    }
    if ($f['account'] > 0) {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.cash_account_id = ?';
        $types .= 'i';
        $params[] = $f['account'];
    }
    if ($f['direction'] !== 'all') {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.direction = ?';
        $types .= 's';
        $params[] = $f['direction'];
    }
    if ($sql === '') {
        $sql = '1=1';
    }
    return ['sql' => ($w !== '' ? $w . ' AND ' : '') . $sql, 'types' => $types, 'params' => $params];
}

/** Akun aktif perusahaan (untuk dropdown filter & form). */
function cash_account_list(bool $active_only = true, ?int $company_id = null): array
{
    $cid = $company_id ?? (int) active_company_id();
    $st = db()->prepare('SELECT id, code, name, type, opening_balance, is_active FROM cash_accounts'
        . ' WHERE company_id = ?' . ($active_only ? ' AND is_active = 1' : '') . ' ORDER BY code');
    $st->bind_param('i', $cid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Satu akun; tabel ber-company_id → 403 bila bukan milik perusahaan aktif. */
function cash_account_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }
    assert_company_access((int) $row['company_id']);
    return $row;
}

/** Satu transaksi + nama akun & metode bayar; 403 bila tenant lain. */
function cash_tx_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT t.*, a.name AS account_name, a.code AS account_code'
        . ' FROM cash_transactions t LEFT JOIN cash_accounts a ON a.id = t.cash_account_id WHERE t.id = ? LIMIT 1');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }
    assert_company_access((int) $row['company_id']);
    return $row;
}

/**
 * Validasi input transaksi. Akun wajib milik perusahaan aktif ($cid) — bukan sekadar
 * ada di cash_accounts (scoping antar perusahaan).
 */
function cash_tx_validate(array $in, int $cid): array
{
    $errors = [];
    $account_id = (int) ($in['cash_account_id'] ?? 0);
    $date = (string) ($in['date'] ?? '');
    $direction = (string) ($in['direction'] ?? '');
    $amount = (float) ($in['amount'] ?? 0);

    if ($account_id <= 0) {
        $errors[] = 'Akun kas/bank wajib dipilih.';
    } elseif (!cash_relation_ok($account_id, $cid)) {
        $errors[] = 'Akun kas/bank harus dipilih dari daftar yang tersedia.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        $errors[] = 'Tanggal wajib diisi dengan format tanggal yang benar.';
    }
    if (!isset(cash_directions()[$direction])) {
        $errors[] = 'Arah transaksi harus masuk atau keluar.';
    }
    if ($amount <= 0) {
        $errors[] = 'Jumlah harus lebih dari 0.';
    }
    if (mb_strlen(trim((string) ($in['category'] ?? ''))) > 50) {
        $errors[] = 'Kategori maksimal 50 karakter.';
    }
    if (mb_strlen(trim((string) ($in['description'] ?? ''))) > 1000) {
        $errors[] = 'Keterangan maksimal 1000 karakter.';
    }
    return $errors;
}

/** Akun ada & milik perusahaan aktif (menahan id akun perusahaan lain). */
function cash_relation_ok(int $account_id, int $cid): bool
{
    $st = db()->prepare('SELECT 1 FROM cash_accounts WHERE id = ? AND company_id = ?');
    $st->bind_param('ii', $account_id, $cid);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/**
 * Simpan (insert/update) transaksi. Update: baris wajib milik perusahaan aktif —
 * baris tenant lain tidak ditemukan sehingga tidak mungkin diubah.
 */
function cash_tx_save(int $id, array $in, int $cid, ?string $user): int
{
    $user = (string) $user;
    $now = date('Y-m-d H:i:s');
    $account_id = (int) $in['cash_account_id'];
    $date = (string) $in['date'];
    $direction = (string) $in['direction'];
    $amount = (float) $in['amount'];
    $category = trim((string) ($in['category'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $category = $category === '' ? null : mb_substr($category, 0, 50);
    $description = $description === '' ? null : mb_substr($description, 0, 1000);

    if ($id === 0) {
        $st = db()->prepare('INSERT INTO cash_transactions'
            . ' (company_id, cash_account_id, date, direction, amount, category, description, created_user, created_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('iissdssss', $cid, $account_id, $date, $direction, $amount, $category, $description, $user, $now);
        $st->execute();
        $new_id = $st->insert_id;
        $st->close();
        return $new_id;
    }

    $st = db()->prepare('UPDATE cash_transactions SET cash_account_id = ?, date = ?, direction = ?, amount = ?,'
        . ' category = ?, description = ?, modified_user = ?, modified_date = ? WHERE id = ? AND company_id = ?');
    $st->bind_param('issdssssii', $account_id, $date, $direction, $amount, $category, $description, $user, $now, $id, $cid);
    $st->execute();
    $st->close();
    return $id;
}

/**
 * Ringkasan buku kas.
 *  - total_in / total_out : hanya dalam rentang (dan filter arah) — inilah angka yang ditampilkan.
 *  - balance              : saldo akhir = saldo awal akun + Σmasuk − Σkeluar sampai tanggal `to`.
 *
 * Saldo TIDAK dipotong filter arah: saldo adalah saldo akun, bukan total kolom terfilter.
 * Filter arah == 'in' membuat total_out 0; saldo tetap dihitung dari semua transaksi.
 * Tanpa akun terpilih (Semua Akun) saldo = jumlah saldo awal akun + seluruh transaksi.
 * Di-scope perusahaan: transaksi perusahaan lain tidak ikut (uang mereka bukan uang kita).
 */
function cash_summary(array $f): array
{
    // Saldo akhir = saldo awal akun + Σ masuk − Σ keluar S/D TANGGAL `to`.
    // Rentang tanggal `from` TIDAK memotong saldo (saldo itu posisi akun, bukan total kolom),
    // tapi batas atas `to` WAJIB dihormati: memilih periode lampau tak boleh menampilkan
    // mutasi setelahnya. Filter arah ikut apa adanya (sudut pandang kolom, bukan saldo akun).
    // Semua angka memakai satu WHERE yang sama → kartu ringkasan tak mungkin berselisih dengan tabel.
    $range = cash_tx_where($f);
    // Saldo s/d `to`: klausa tanggal bawah sengaja dibuang (with_dates=false) karena saldo itu
    // posisi akun, bukan total kolom rentang. Filter ARAH juga dibuang: saldo adalah posisi akun,
    // mem-filter kolom "masuk" tak boleh mengubah saldo (uji: arah=in → saldo tetap 1.300.000).
    // Batas atas ditambahkan eksplisit sebagai parameter sendiri supaya types & params tetap sejajar.
    $upto = cash_tx_where(['from' => '', 'to' => $f['to'], 'account' => $f['account'], 'direction' => 'all'], false);
    $upto['sql'] .= ' AND t.date <= ?';
    $upto['types'] .= 's';
    $upto['params'][] = $f['to'];
    $bal = static function (array $w): float {
        $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END), 0)
            FROM cash_transactions t WHERE " . $w['sql']);
        if ($w['types'] !== '') {
            $st->bind_param($w['types'], ...$w['params']);
        }
        $st->execute();
        $v = (float) $st->get_result()->fetch_row()[0];
        $st->close();
        return $v;
    };

    [$w, $types, $params] = company_scope('a.company_id');
    $acc_sql = ($w !== '' ? $w . ' AND ' : '') . '1=1' . ($f['account'] > 0 ? ' AND a.id = ?' : '');
    if ($f['account'] > 0) {
        $types .= 'i';
        $params[] = $f['account'];
    }
    $st = db()->prepare('SELECT COALESCE(SUM(a.opening_balance), 0), COUNT(*) FROM cash_accounts a WHERE ' . $acc_sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $acc_row = $st->get_result()->fetch_row();
    $st->close();
    $opening = (float) $acc_row[0];

    $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN t.direction = 'out' THEN t.amount ELSE 0 END), 0) AS total_out
        FROM cash_transactions t WHERE " . $range['sql']);
    if ($range['types'] !== '') {
        $st->bind_param($range['types'], ...$range['params']);
    }
    $st->execute();
    $sum = $st->get_result()->fetch_assoc();
    $st->close();

    return [
        'opening'       => $opening,
        'total_in'      => (float) $sum['total_in'],
        'total_out'     => (float) $sum['total_out'],
        'balance'       => $opening + $bal($upto),
        'range_balance' => $opening + $bal($upto),
    ];
}

/** Baris transaksi untuk tabel & export. $limit null = semua. */
function cash_tx_list(array $f, ?int $limit = null, int $offset = 0): array
{
    $tw = cash_tx_where($f);
    $sql = 'SELECT t.id, t.cash_account_id, t.date, t.direction, t.amount, t.category, t.description, t.created_user,
            a.code AS account_code, a.name AS account_name
        FROM cash_transactions t LEFT JOIN cash_accounts a ON a.id = t.cash_account_id
        WHERE ' . $tw['sql'] . ' ORDER BY t.date ASC, t.id ASC';
    $types = $tw['types'];
    $params = $tw['params'];
    if ($limit !== null) {
        $sql .= ' LIMIT ? OFFSET ?';
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;
    }
    $st = db()->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function cash_tx_count(array $f): int
{
    $tw = cash_tx_where($f);
    $st = db()->prepare('SELECT COUNT(*) FROM cash_transactions t WHERE ' . $tw['sql']);
    if ($tw['types'] !== '') {
        $st->bind_param($tw['types'], ...$tw['params']);
    }
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/**
 * Baris siap-tulis export: tanggal, akun, arah, kategori, keterangan, nominal, diakhiri TOTAL.
 * CSV & XLSX memakai daftar yang sama supaya isi kedua berkas identik.
 */
function cash_export_table(array $f): array
{
    $heads = ['Tanggal', 'Akun', 'Arah', 'Kategori', 'Keterangan', 'Nominal'];
    $table = [['Buku Kas', $f['from'] . ' s/d ' . $f['to']], [], $heads];
    $s = cash_summary($f);
    $tot_in = 0.0;
    $tot_out = 0.0;
    foreach (cash_tx_list($f) as $r) {
        $amount = (float) $r['amount'];
        // Nominal bertanda: keluar minus — supaya SUM() di Excel langsung benar.
        $signed = $r['direction'] === 'in' ? $amount : -$amount;
        if ($r['direction'] === 'in') {
            $tot_in += $amount;
        } else {
            $tot_out += $amount;
        }
        $table[] = [
            (string) $r['date'],
            trim($r['account_code'] . ' - ' . $r['account_name'], ' -'),
            cash_directions()[$r['direction']] ?? (string) $r['direction'],
            (string) $r['category'],
            (string) $r['description'],
            $signed,
        ];
    }
    $table[] = [];
    $table[] = ['Total Masuk', '', '', '', '', $tot_in];
    $table[] = ['Total Keluar', '', '', '', '', -$tot_out];
    $table[] = ['Saldo Akhir', '', '', '', '', $s['balance']];
    return $table;
}
