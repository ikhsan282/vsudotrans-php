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
require_once __DIR__ . '/../../includes/azure_blob.php';
require_once __DIR__ . '/../travel/_helpers.php';

function cash_directions(): array
{
    return ['in' => 'Masuk', 'out' => 'Keluar'];
}

/**
 * Daftar baris Master Kategori Kas aktif/nonaktif dari tabel `cash_categories`
 * (global `company_id IS NULL` + milik perusahaan).
 */
function cash_master_categories(string $direction = 'all', ?int $company_id = null, bool $active_only = true): array
{
    finance_ensure_schema();
    finance_ensure_global_cash_categories();

    $where  = [];
    $types  = '';
    $params = [];

    if (in_array($direction, ['in', 'out'], true)) {
        $where[]  = 'direction = ?';
        $types   .= 's';
        $params[] = $direction;
    }
    if ($active_only) {
        $where[] = 'is_active = 1';
    }

    if ($company_id !== null && $company_id > 0) {
        $where[]  = '(company_id = ? OR company_id IS NULL)';
        $types   .= 'i';
        $params[] = $company_id;
    } elseif ($company_id === 0 && is_super_admin()) {
        // Super Admin filter "Semua Perusahaan": tampilkan semua kategori master
    } elseif (active_company_id() !== null) {
        $where[]  = '(company_id = ? OR company_id IS NULL)';
        $types   .= 'i';
        $params[] = (int) active_company_id();
    } elseif (!is_super_admin()) {
        $where[] = 'company_id IS NULL';
    }

    $whereSql = $where ? implode(' AND ', $where) : '1=1';
    $sql = "SELECT id, company_id, code, name, direction, sort_order, is_active, note
              FROM cash_categories
             WHERE {$whereSql}
             ORDER BY sort_order ASC, name ASC, id ASC";
    $st = db()->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function cash_expense_categories(?int $company_id = null): array
{
    $out = [];
    foreach (cash_master_categories('out', $company_id, true) as $row) {
        $name = (string) $row['name'];
        $note = trim((string) ($row['note'] ?? ''));
        $out[$name] = $note !== '' ? $note : (string) $row['code'];
    }
    return $out;
}

function cash_income_categories(?int $company_id = null): array
{
    $out = [];
    foreach (cash_master_categories('in', $company_id, true) as $row) {
        $name = (string) $row['name'];
        $note = trim((string) ($row['note'] ?? ''));
        $out[$name] = $note !== '' ? $note : (string) $row['code'];
    }
    return $out;
}

/** Urai nominal uang format Indonesia (`250.000`, `250.000,50`) maupun desimal titik (`250000.50`). */
function cash_parse_amount($raw): float
{
    $s = trim(str_ireplace(['Rp', ' '], '', (string) $raw));
    if ($s === '') {
        return 0.0;
    }
    if (preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
        return (float) $s;
    }
    $s = str_replace(['.', ','], ['', '.'], $s);
    if (!is_numeric($s)) {
        return 0.0;
    }
    return (float) $s;
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
        'direction'  => $dir,
        'company_id' => isset($in['company_id']) && $in['company_id'] !== '' ? max(0, (int) $in['company_id']) : 0,
        'category'   => trim((string) ($in['category'] ?? '')),
        'q'          => trim((string) ($in['q'] ?? '')),
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
    if ($f['direction'] !== 'all') {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.direction = ?';
        $types .= 's';
        $params[] = $f['direction'];
    }
    if (!empty($f['category'])) {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.category = ?';
        $types .= 's';
        $params[] = (string) $f['category'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $sql .= ($sql === '' ? '' : ' AND ') . '(t.category LIKE ? OR t.description LIKE ?)';
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
    if ($sql === '') {
        $sql = '1=1';
    }
    return ['sql' => ($w !== '' ? $w . ' AND ' : '') . $sql, 'types' => $types, 'params' => $params];
}

/** Rincian total transaksi per kategori untuk arah 'in' atau 'out' dalam rentang filter. */
function cash_category_breakdown(array $f, string $direction = 'out'): array
{
    finance_ensure_schema();
    $ef = $f;
    $ef['direction'] = $direction === 'in' ? 'in' : 'out';
    $tw = cash_tx_where($ef);
    $sql = "SELECT COALESCE(NULLIF(TRIM(t.category), ''), 'Tanpa Kategori') AS category,
                   COUNT(*) AS tx_count,
                   COALESCE(SUM(t.amount), 0) AS total_amount
              FROM cash_transactions t
             WHERE {$tw['sql']}
             GROUP BY category
             ORDER BY total_amount DESC, category ASC";
    $st = db()->prepare($sql);
    if ($tw['types'] !== '') {
        $st->bind_param($tw['types'], ...$tw['params']);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Rincian total pengeluaran per kategori dalam rentang filter. */
function cash_expense_category_breakdown(array $f): array
{
    return cash_category_breakdown($f, 'out');
}

/** Rincian total pemasukan per kategori dalam rentang filter. */
function cash_income_category_breakdown(array $f): array
{
    return cash_category_breakdown($f, 'in');
}

/** Daftar unik kategori (master + transaksi yang sudah ada) untuk filter. */
function cash_distinct_categories(array $f, string $direction = 'out'): array
{
    finance_ensure_schema();
    $cidArg  = isset($f['company_id']) ? (int) $f['company_id'] : null;
    $presets = $direction === 'in'
        ? array_keys(cash_income_categories($cidArg))
        : array_keys(cash_expense_categories($cidArg));
    $cf = [
        'from'       => '',
        'to'         => '',
        'direction'  => in_array($direction, ['in', 'out'], true) ? $direction : 'all',
        'company_id' => (int) ($f['company_id'] ?? 0),
        'category'   => '',
        'q'          => '',
    ];
    $tw = cash_tx_where($cf, false);
    $sql = "SELECT DISTINCT TRIM(t.category) AS category
              FROM cash_transactions t
             WHERE {$tw['sql']}
               AND t.category IS NOT NULL AND TRIM(t.category) <> ''
             ORDER BY category ASC";
    $st = db()->prepare($sql);
    if ($tw['types'] !== '') {
        $st->bind_param($tw['types'], ...$tw['params']);
    }
    $st->execute();
    $dbCats = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'category');
    $st->close();

    $custom = [];
    foreach ($dbCats as $dc) {
        $dc = trim((string) $dc);
        if ($dc !== '' && !in_array($dc, $presets, true)) {
            $custom[] = $dc;
        }
    }
    natcasesort($custom);
    return array_values(array_merge($presets, $custom));
}

/** Proses unggah/hapus bukti struk/nota/transfer ke Azure Blob Storage (opsional). */
function cash_handle_proof_upload(int $cid, string $subFolder, ?string $existingPath, bool $removeExisting = false): array
{
    $current = ($existingPath !== null && trim($existingPath) !== '') ? trim($existingPath) : null;
    $hasFile = !empty($_FILES['proof_file'])
        && isset($_FILES['proof_file']['error'])
        && (int) $_FILES['proof_file']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hasFile) {
        try {
            $newPath = azure_blob_handle_upload('proof_file', $cid, 'finance', $subFolder, 'bukti', true, 5);
            if ($newPath !== null && $newPath !== '') {
                if ($current !== null && $current !== $newPath) {
                    azure_blob_delete($current);
                }
                return ['path' => $newPath, 'error' => null];
            }
        } catch (InvalidArgumentException $e) {
            return ['path' => $current, 'error' => $e->getMessage()];
        }
    }

    if ($removeExisting && $current !== null) {
        azure_blob_delete($current);
        return ['path' => null, 'error' => null];
    }

    return ['path' => $current, 'error' => null];
}

/** Render baris form unggah / pratinjau Bukti Struk / Nota (Azure Blob Storage). */
function cash_render_proof_row(?string $proofPath = null): string
{
    $path = trim((string) $proofPath);
    $url  = $path !== '' ? resolve_asset_url($path) : '';
    $isPdf = $path !== '' && str_ends_with(strtolower($path), '.pdf');

    $existingHtml = '';
    if ($path !== '' && $url !== '') {
        $thumb = !$isPdf
            ? '<div class="mb-2"><a href="' . e($url) . '" target="_blank" rel="noopener"><img src="' . e($url) . '" alt="Bukti Transaksi" class="img-fluid rounded border bg-white" style="max-height:120px;"></a></div>'
            : '';
        $existingHtml = '<div class="mt-2 p-2 border rounded bg-light-subtle">'
            . $thumb
            . '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">'
            . '<a href="' . e($url) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2">'
            . '<i class="bi bi-paperclip me-1"></i>Lihat / Unduh Bukti</a>'
            . '<label class="form-check form-check-inline mb-0 small text-danger">'
            . '<input class="form-check-input" type="checkbox" name="remove_proof" value="1"> '
            . '<span>Hapus lampiran bukti</span>'
            . '</label>'
            . '</div>'
            . '</div>';
    }

    return '<div class="rnt-row rnt-row-top">'
        . '<label class="rnt-label pt-1" for="proof_file">Bukti Struk / Nota</label>'
        . '<div class="rnt-field">'
        . '<input class="form-control form-control-sm" type="file" id="proof_file" name="proof_file" accept="image/*,application/pdf">'
        . '<div class="form-text small text-muted">Opsional · JPG, PNG, WEBP, atau PDF (maks. 5 MB) ke Azure Blob Storage.</div>'
        . '<img id="proofPreview" src="" alt="Pratinjau Bukti" class="img-fluid rounded border mt-2 d-none" style="max-height:140px;">'
        . $existingHtml
        . '</div>'
        . '</div>';
}

/** Cek apakah URI berkas mengarah ke tombol Lihat Bukti pada tabel kas/pemasukan/pengeluaran. */
function cash_proof_action_html(array $row): string
{
    $p = trim((string) ($row['proof_blob_path'] ?? ''));
    if ($p === '') {
        return '';
    }
    $url = resolve_asset_url($p);
    if ($url === '') {
        return '';
    }
    return '<a class="btn btn-sm btn-outline-secondary" href="' . e($url) . '" target="_blank" rel="noopener" title="Lihat Bukti Struk/Nota" aria-label="Lihat Bukti"><i class="bi bi-paperclip"></i></a> ';
}

/** Satu transaksi kas; 403 bila tenant lain. */
function cash_tx_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    finance_ensure_schema();
    $st = db()->prepare('SELECT t.* FROM cash_transactions t WHERE t.id = ? LIMIT 1');
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
    $date = (string) ($in['date'] ?? '');
    $direction = (string) ($in['direction'] ?? '');
    $amount = (float) ($in['amount'] ?? 0);

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
    $proofTrim = trim((string) ($in['proof_blob_path'] ?? ''));
    $proof_path = $proofTrim !== '' ? mb_substr($proofTrim, 0, 255) : null;
    $date = (string) $in['date'];
    $direction = (string) $in['direction'];
    $amount = (float) $in['amount'];
    $category = trim((string) ($in['category'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $category = $category === '' ? null : mb_substr($category, 0, 50);
    $description = $description === '' ? null : mb_substr($description, 0, 1000);

    if ($id === 0) {
        $st = db()->prepare('INSERT INTO cash_transactions'
            . ' (company_id, date, direction, amount, category, description, proof_blob_path, created_user, created_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('issdsssss', $cid, $date, $direction, $amount, $category, $description, $proof_path, $user, $now);
        $st->execute();
        $new_id = $st->insert_id;
        $st->close();
        return $new_id;
    }

    $sets = ['date = ?', 'direction = ?', 'amount = ?', 'category = ?', 'description = ?'];
    $types = 'ssdss';
    $params = [$date, $direction, $amount, $category, $description];
    if (array_key_exists('proof_blob_path', $in)) {
        $sets[] = 'proof_blob_path = ?';
        $types .= 's';
        $params[] = $proof_path;
    }
    $sets[] = 'modified_user = ?';
    $sets[] = 'modified_date = ?';
    $types .= 'ssii';
    $params[] = $user;
    $params[] = $now;
    $params[] = $id;
    $params[] = $cid;

    $st = db()->prepare('UPDATE cash_transactions SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?');
    $st->bind_param($types, ...$params);
    $st->execute();
    $st->close();
    return $id;
}

/**
 * Ringkasan buku kas.
 *  - opening   : saldo akumulasi sebelum tanggal `from` (Σmasuk − Σkeluar sebelum `from`).
 *  - total_in  : Σmasuk pada rentang filter (`from`..`to`, ikut filter arah).
 *  - total_out : Σkeluar pada rentang filter (`from`..`to`, ikut filter arah).
 *  - balance   : saldo akumulasi sampai tanggal `to` (`t.date <= to`, tidak terpotong filter arah).
 */
function cash_summary(array $f): array
{
    finance_ensure_schema();
    $range = cash_tx_where($f);

    $baseFilter = [
        'from'       => '',
        'to'         => $f['to'],
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
    $sql = "SELECT t.id, t.company_id, c.code AS company_code, t.date, t.direction, t.amount, t.category, t.description, t.created_user,
            t.source_type, t.source_id, t.proof_blob_path,
            inv.id AS ref_invoice_id,
            CASE WHEN t.source_type = 'booking_bbm' THEN 'rental' ELSE COALESCE(inv.booking_type, bcl.booking_type) END AS ref_booking_type,
            CASE WHEN t.source_type = 'booking_bbm' THEN t.source_id ELSE COALESCE(inv.booking_id, bcl.booking_id) END AS ref_booking_id
        FROM cash_transactions t
        LEFT JOIN companies c ON c.id = t.company_id
        LEFT JOIN booking_cost_lines bcl ON t.source_type IN ('booking_payment', 'crew_commission') AND bcl.id = t.source_id
        LEFT JOIN invoices inv ON t.source_type = 'booking_payment' AND bcl.description = CONCAT('INV#', inv.id)
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
 * Baris siap-tulis export: tanggal, arah, kategori, keterangan, nominal, diakhiri TOTAL.
 * CSV & XLSX memakai daftar yang sama supaya isi kedua berkas identik.
 */
function cash_export_table(array $f): array
{
    $heads = ['Tanggal', 'Arah', 'Kategori', 'Keterangan', 'Nominal'];
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
            cash_directions()[$r['direction']] ?? (string) $r['direction'],
            (string) $r['category'],
            trim((string) ($r['description'] ?? '')),
            $signed,
        ];
    }
    $table[] = [];
    $table[] = ['Total Masuk', '', '', '', $tot_in];
    $table[] = ['Total Keluar', '', '', '', -$tot_out];
    $table[] = ['Saldo Akhir', '', '', '', $s['balance']];
    return $table;
}
