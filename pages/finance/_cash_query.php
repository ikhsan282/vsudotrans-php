<?php
/**
 * Buku Kas — semua query, validasi, dan angka ringkasan ada di sini.
 * Dipakai pages/finance/cash/{index,form,delete,export}.php dan tests/cash_check.php,
 * sehingga rumus saldo hanya hidup di satu tempat.
 *
 * Scope tenant: company_scope() untuk daftar, assert_company_access() untuk satu baris.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_sync.php';
require_once __DIR__ . '/../travel/_helpers.php';

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

/** Normalisasi filter buku kas. Arah/tanggal ngawur -> default aman (bukan error). */
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
        'from'       => $d('from', date('Y-m-01')),
        'to'         => $d('to', date('Y-m-t')),
        'method'     => max(0, (int) ($in['method'] ?? 0)),
        'direction'  => $dir,
        'company_id' => isset($in['company_id']) && $in['company_id'] !== '' ? max(0, (int) $in['company_id']) : 0,
    ];
}

/** Klausa scope perusahaan untuk query Buku Kas (mendukung filter company_id eksplisit Super Admin). */
function cash_company_scope(string $column, array $f): array
{
    if (is_super_admin() && !empty($f['company_id'])) {
        return ["{$column} = ?", 'i', [(int) $f['company_id']]];
    }
    return company_scope($column);
}

/**
 * Klausa WHERE bersama untuk semua query transaksi buku kas.
 * @return array{sql:string, types:string, params:array}
 */
function cash_tx_where(array $f, bool $with_dates = true): array
{
    [$w, $types, $params] = cash_company_scope('t.company_id', $f);
    $sql = '';
    if ($with_dates) {
        $sql = 't.date >= ? AND t.date <= ?';
        $types .= 'ss';
        $params[] = $f['from'];
        $params[] = $f['to'];
    }
    if (!empty($f['method']) && (int) $f['method'] > 0) {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.payment_method_id = ?';
        $types .= 'i';
        $params[] = (int) $f['method'];
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

/** Daftar metode pembayaran aktif untuk dropdown/filter Buku Kas. */
function cash_method_list(?int $company_id = null): array
{
    finance_ensure_schema();
    $cid = $company_id ?? (int) (active_company_id() ?? 0);
    if ($cid === 0 && is_super_admin()) {
        return db()->query(
            'SELECT pm.id, pm.company_id, pm.code, pm.name, pm.is_cash, pm.is_active
               FROM payment_methods pm
              WHERE pm.is_active = 1
              ORDER BY pm.sort_order ASC, pm.name ASC'
        )->fetch_all(MYSQLI_ASSOC);
    }
    return finance_active_payment_methods($cid);
}

/** Cek apakah payment_method_id sah dipakai perusahaan $cid (aktif milik perusahaan atau global). */
function cash_method_ok(int $method_id, int $cid): bool
{
    $st = db()->prepare('SELECT 1 FROM payment_methods WHERE id = ? AND is_active = 1 AND (company_id = ? OR company_id IS NULL) LIMIT 1');
    $st->bind_param('ii', $method_id, $cid);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/** Satu transaksi + metode bayar; 403 bila tenant lain. */
function cash_tx_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    finance_ensure_schema();
    $st = db()->prepare(
        'SELECT t.*, pm.code AS method_code, pm.name AS method_name
           FROM cash_transactions t
           LEFT JOIN payment_methods pm ON pm.id = NULLIF(t.payment_method_id, 0)
          WHERE t.id = ? LIMIT 1'
    );
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
 * Validasi input transaksi buku kas.
 */
function cash_tx_validate(array $in, int $cid): array
{
    $errors = [];
    $method_id = (int) ($in['payment_method_id'] ?? 0);
    $date = (string) ($in['date'] ?? '');
    $direction = (string) ($in['direction'] ?? '');
    $amount = (float) ($in['amount'] ?? 0);

    if ($method_id > 0 && !cash_method_ok($method_id, $cid)) {
        $errors[] = 'Metode pembayaran harus dipilih dari daftar yang tersedia.';
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

/**
 * Simpan (insert/update) transaksi. Update: baris wajib milik perusahaan aktif —
 * baris tenant lain tidak ditemukan sehingga tidak mungkin diubah.
 */
function cash_tx_save(int $id, array $in, int $cid, ?string $user): int
{
    finance_ensure_schema();
    $user = (string) $user;
    $now = date('Y-m-d H:i:s');
    $pmRaw = (int) ($in['payment_method_id'] ?? 0);
    $method_id = $pmRaw > 0 ? $pmRaw : null;
    $date = (string) $in['date'];
    $direction = (string) $in['direction'];
    $amount = (float) $in['amount'];
    $category = trim((string) ($in['category'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $category = $category === '' ? null : mb_substr($category, 0, 50);
    $description = $description === '' ? null : mb_substr($description, 0, 1000);

    if ($id === 0) {
        $st = db()->prepare('INSERT INTO cash_transactions'
            . ' (company_id, date, direction, amount, category, description, payment_method_id, created_user, created_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('issdssiss', $cid, $date, $direction, $amount, $category, $description, $method_id, $user, $now);
        $st->execute();
        $new_id = $st->insert_id;
        $st->close();
        return $new_id;
    }

    $st = db()->prepare('UPDATE cash_transactions SET date = ?, direction = ?, amount = ?,'
        . ' category = ?, description = ?, payment_method_id = ?, modified_user = ?, modified_date = ? WHERE id = ? AND company_id = ?');
    $st->bind_param('ssdssissii', $date, $direction, $amount, $category, $description, $method_id, $user, $now, $id, $cid);
    $st->execute();
    $st->close();
    return $id;
}

/**
 * Ringkasan buku kas.
 *  - opening   : saldo akumulasi sebelum tanggal `from` (Σmasuk − Σkeluar sebelum `from`).
 *  - total_in  : Σmasuk pada rentang filter (`from`..`to`, ikut filter arah & metode).
 *  - total_out : Σkeluar pada rentang filter (`from`..`to`, ikut filter arah & metode).
 *  - balance   : saldo akumulasi sampai tanggal `to` (`t.date <= to`, tidak terpotong filter arah).
 */
function cash_summary(array $f): array
{
    finance_ensure_schema();
    $range = cash_tx_where($f);

    $baseFilter = [
        'from'       => '',
        'to'         => $f['to'],
        'method'     => (int) ($f['method'] ?? 0),
        'direction'  => 'all',
        'company_id' => (int) ($f['company_id'] ?? 0),
    ];

    $beforeFrom = cash_tx_where($baseFilter, false);
    $beforeFrom['sql'] .= ' AND t.date < ?';
    $beforeFrom['types'] .= 's';
    $beforeFrom['params'][] = $f['from'];

    $upto = cash_tx_where($baseFilter, false);
    $upto['sql'] .= ' AND t.date <= ?';
    $upto['types'] .= 's';
    $upto['params'][] = $f['to'];

    $netQuery = static function (array $w): float {
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

    $opening = $netQuery($beforeFrom);
    $balance = $netQuery($upto);

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
        'balance'       => $balance,
        'range_balance' => $balance,
    ];
}

/** Baris transaksi untuk tabel & export. $limit null = semua. */
function cash_tx_list(array $f, ?int $limit = null, int $offset = 0): array
{
    finance_ensure_schema();
    $tw = cash_tx_where($f);
    $sql = "SELECT t.id, t.company_id, c.code AS company_code, t.payment_method_id, t.date, t.direction, t.amount, t.category, t.description, t.created_user,
            t.source_type, t.source_id,
            pm.code AS method_code, pm.name AS method_name,
            inv.id AS ref_invoice_id, inv.booking_type AS ref_booking_type, inv.booking_id AS ref_booking_id
        FROM cash_transactions t
        LEFT JOIN payment_methods pm ON pm.id = NULLIF(t.payment_method_id, 0)
        LEFT JOIN companies c ON c.id = t.company_id
        LEFT JOIN booking_cost_lines bcl ON t.source_type = 'booking_payment' AND bcl.id = t.source_id
        LEFT JOIN invoices inv ON bcl.description = CONCAT('INV#', inv.id)
        WHERE " . $tw['sql'] . ' ORDER BY t.date ASC, t.id ASC';
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
    finance_ensure_schema();
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
 * Baris siap-tulis export: tanggal, metode, arah, kategori, keterangan, nominal, diakhiri TOTAL.
 * CSV & XLSX memakai daftar yang sama supaya isi kedua berkas identik.
 */
function cash_export_table(array $f): array
{
    $heads = ['Tanggal', 'Metode', 'Arah', 'Kategori', 'Keterangan', 'Nominal'];
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
        $methodLabel = trim((string) ($r['method_name'] ?? ''));
        if ($methodLabel === '') {
            $methodLabel = '-';
        }
        $table[] = [
            (string) $r['date'],
            $methodLabel,
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
