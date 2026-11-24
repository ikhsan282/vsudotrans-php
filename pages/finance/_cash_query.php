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

function cash_expense_categories(): array
{
    return [
        'BBM'                      => 'BBM / Solar / Bensin',
        'Tol & Parkir'             => 'Tol, Parkir & Retribusi',
        'Servis & Perawatan Armada'=> 'Servis, Oli, Ban & Sparepart Armada',
        'Uang Jalan & Komisi Kru'  => 'Uang Jalan, Uang Makan & Komisi Sopir/Kru',
        'Gaji & Tunjangan'         => 'Gaji, Tunjangan & Bonus Karyawan',
        'Sewa & Utilitas Kantor'   => 'Sewa Kantor/Pool, Listrik, Air & Internet',
        'Operasional & ATK'        => 'ATK, Konsumsi & Operasional Kantor',
        'Pajak & Legalitas Armada' => 'Pajak Kendaraan, STNK, KIR & Asuransi',
        'Lain-lain'                => 'Pengeluaran Lain-lain',
    ];
}

function cash_income_categories(): array
{
    return [
        'Setoran Modal'      => 'Setoran Modal / Tambahan Kas',
        'Pendapatan Travel'  => 'Pemasukan Tiket / Charter Travel Manual',
        'Pendapatan Rental'  => 'Pemasukan Sewa Kendaraan Manual',
        'Pelunasan Piutang'  => 'Pelunasan Piutang / Titipan',
        'Komisi & Bagi Hasil'=> 'Komisi Agen, Mitra & Bagi Hasil',
        'Refund / Retur'     => 'Pengembalian Dana / Refund Supplier',
        'Pendapatan Lainnya' => 'Pemasukan Lain-lain',
    ];
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
        'method'     => max(0, (int) ($in['method'] ?? 0)),
        'vehicle_id' => max(0, (int) ($in['vehicle_id'] ?? 0)),
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
    if (!empty($f['method']) && (int) $f['method'] > 0) {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.payment_method_id = ?';
        $types .= 'i';
        $params[] = (int) $f['method'];
    }
    if (!empty($f['vehicle_id']) && (int) $f['vehicle_id'] > 0) {
        $sql .= ($sql === '' ? '' : ' AND ') . 't.vehicle_id = ?';
        $types .= 'i';
        $params[] = (int) $f['vehicle_id'];
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

/** Daftar unik kategori (preset + transaksi yang sudah ada) untuk filter/datalist. */
function cash_distinct_categories(array $f, string $direction = 'out'): array
{
    finance_ensure_schema();
    $presets = $direction === 'in' ? array_keys(cash_income_categories()) : array_keys(cash_expense_categories());
    $cf = [
        'from'       => '',
        'to'         => '',
        'method'     => 0,
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

/** Daftar armada aktif untuk dropdown/filter transaksi kas. */
function cash_vehicle_list(?int $company_id = null): array
{
    finance_ensure_schema();
    $cid = $company_id ?? (int) (active_company_id() ?? 0);
    if ($cid === 0 && is_super_admin()) {
        $sql = 'SELECT v.id, v.company_id, c.code AS company_code, v.vehicle_number, v.vehicle_category,
                       bv.name AS brand_name, mu.name AS model_name
                  FROM vehicles v
                  LEFT JOIN companies c ON c.id = v.company_id
                  LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
                  LEFT JOIN model_units mu ON mu.id = v.model_unit_id
                 WHERE v.is_active = 1
                 ORDER BY v.vehicle_number ASC';
        return db()->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
    if ($cid <= 0) {
        return [];
    }
    $st = db()->prepare(
        'SELECT v.id, v.company_id, c.code AS company_code, v.vehicle_number, v.vehicle_category,
                bv.name AS brand_name, mu.name AS model_name
           FROM vehicles v
           LEFT JOIN companies c ON c.id = v.company_id
           LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
           LEFT JOIN model_units mu ON mu.id = v.model_unit_id
          WHERE v.is_active = 1 AND v.company_id = ?
          ORDER BY v.vehicle_number ASC'
    );
    $st->bind_param('i', $cid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Cek apakah vehicle_id sah dipakai perusahaan $cid. */
function cash_vehicle_ok(int $vehicle_id, int $cid): bool
{
    $st = db()->prepare('SELECT 1 FROM vehicles WHERE id = ? AND company_id = ? LIMIT 1');
    $st->bind_param('ii', $vehicle_id, $cid);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/** Rincian total pengeluaran per armada/unit dalam rentang filter (hanya transaksi yang tertaut ke unit). */
function cash_expense_vehicle_breakdown(array $f): array
{
    finance_ensure_schema();
    $ef = $f;
    $ef['direction'] = 'out';
    $ef['vehicle_id'] = 0;
    $tw = cash_tx_where($ef);
    $sql = "SELECT v.id AS vehicle_id,
                   v.vehicle_number,
                   v.vehicle_category,
                   COUNT(*) AS tx_count,
                   COALESCE(SUM(t.amount), 0) AS total_amount
              FROM cash_transactions t
              JOIN vehicles v ON v.id = t.vehicle_id
             WHERE {$tw['sql']}
               AND t.vehicle_id IS NOT NULL AND t.vehicle_id > 0
             GROUP BY v.id, v.vehicle_number, v.vehicle_category
             ORDER BY total_amount DESC, v.vehicle_number ASC";
    $st = db()->prepare($sql);
    if ($tw['types'] !== '') {
        $st->bind_param($tw['types'], ...$tw['params']);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
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

/** Render baris form pilihan Armada/Unit opsional (searchable via Tom Select). */
function cash_render_vehicle_row(array $vehicles, string $selectedVehicleId = ''): string
{
    $sel = (int) $selectedVehicleId;
    $opts = '<option value="">— opsional (tidak tertaut armada) —</option>';
    foreach ($vehicles as $v) {
        $vid   = (int) ($v['id'] ?? 0);
        $plate = trim((string) ($v['vehicle_number'] ?? ''));
        $label = $plate !== '' ? $plate : ('Unit #' . $vid);
        $extra = trim(implode(' · ', array_filter([
            (string) ($v['vehicle_category'] ?? ''),
            trim((string) ($v['brand_name'] ?? '') . ' ' . (string) ($v['model_name'] ?? '')),
        ])));
        if ($extra !== '') {
            $label .= ' — ' . $extra;
        }
        $opts .= '<option value="' . $vid . '"'
            . ' data-plate="' . e($plate) . '"'
            . ($sel === $vid ? ' selected' : '') . '>'
            . e($label)
            . '</option>';
    }
    return '<div class="rnt-row">'
        . '<label class="rnt-label" for="vehicle_id">Armada / Unit</label>'
        . '<div class="rnt-field">'
        . '<select class="form-select form-select-sm ts-select" id="vehicle_id" name="vehicle_id" data-ts-placeholder="— opsional (tidak tertaut armada) —">'
        . $opts
        . '</select>'
        . '</div>'
        . '</div>';
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

/** Badge plat armada untuk kolom Keterangan pada tabel kas/pemasukan/pengeluaran. */
function cash_vehicle_badge_html(array $row): string
{
    $plate = trim((string) ($row['vehicle_number'] ?? ''));
    if ($plate === '') {
        return '';
    }
    return '<span class="badge bg-light text-dark border me-1 font-monospace"><i class="bi bi-truck me-1"></i>' . e($plate) . '</span>';
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

/** Satu transaksi + metode bayar + info armada; 403 bila tenant lain. */
function cash_tx_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    finance_ensure_schema();
    $st = db()->prepare(
        'SELECT t.*, pm.code AS method_code, pm.name AS method_name,
                v.vehicle_number, v.vehicle_category
           FROM cash_transactions t
           LEFT JOIN payment_methods pm ON pm.id = NULLIF(t.payment_method_id, 0)
           LEFT JOIN vehicles v ON v.id = NULLIF(t.vehicle_id, 0)
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
    $vehicle_id = (int) ($in['vehicle_id'] ?? 0);
    $date = (string) ($in['date'] ?? '');
    $direction = (string) ($in['direction'] ?? '');
    $amount = (float) ($in['amount'] ?? 0);

    if ($method_id > 0 && !cash_method_ok($method_id, $cid)) {
        $errors[] = 'Metode pembayaran harus dipilih dari daftar yang tersedia.';
    }
    if ($vehicle_id > 0 && !cash_vehicle_ok($vehicle_id, $cid)) {
        $errors[] = 'Armada/kendaraan harus dipilih dari daftar milik perusahaan.';
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
    $vehRaw = (int) ($in['vehicle_id'] ?? 0);
    $vehicle_id = $vehRaw > 0 ? $vehRaw : null;
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
            . ' (company_id, date, direction, amount, category, description, payment_method_id, vehicle_id, proof_blob_path, created_user, created_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('issdssiisss', $cid, $date, $direction, $amount, $category, $description, $method_id, $vehicle_id, $proof_path, $user, $now);
        $st->execute();
        $new_id = $st->insert_id;
        $st->close();
        return $new_id;
    }

    $sets = ['date = ?', 'direction = ?', 'amount = ?', 'category = ?', 'description = ?', 'payment_method_id = ?'];
    $types = 'ssdssi';
    $params = [$date, $direction, $amount, $category, $description, $method_id];
    if (array_key_exists('vehicle_id', $in)) {
        $sets[] = 'vehicle_id = ?';
        $types .= 'i';
        $params[] = $vehicle_id;
    }
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
    $sql = "SELECT t.id, t.company_id, c.code AS company_code, t.payment_method_id, t.vehicle_id, t.date, t.direction, t.amount, t.category, t.description, t.created_user,
            t.source_type, t.source_id, t.proof_blob_path,
            pm.code AS method_code, pm.name AS method_name,
            v.vehicle_number, v.vehicle_category,
            inv.id AS ref_invoice_id, inv.booking_type AS ref_booking_type, inv.booking_id AS ref_booking_id
        FROM cash_transactions t
        LEFT JOIN payment_methods pm ON pm.id = NULLIF(t.payment_method_id, 0)
        LEFT JOIN vehicles v ON v.id = NULLIF(t.vehicle_id, 0)
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
        $descTxt = trim((string) ($r['description'] ?? ''));
        $vehNum  = trim((string) ($r['vehicle_number'] ?? ''));
        if ($vehNum !== '') {
            $descTxt = '[' . $vehNum . ']' . ($descTxt !== '' ? ' ' . $descTxt : '');
        }
        $table[] = [
            (string) $r['date'],
            $methodLabel,
            cash_directions()[$r['direction']] ?? (string) $r['direction'],
            (string) $r['category'],
            $descTxt,
            $signed,
        ];
    }
    $table[] = [];
    $table[] = ['Total Masuk', '', '', '', '', $tot_in];
    $table[] = ['Total Keluar', '', '', '', '', -$tot_out];
    $table[] = ['Saldo Akhir', '', '', '', '', $s['balance']];
    return $table;
}
