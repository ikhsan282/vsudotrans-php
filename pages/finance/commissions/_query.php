<?php
/**
 * Query & layanan pencairan Komisi Sopir / Kru dari `booking_cost_lines`.
 * Pencairan otomatis mencatat Kas Keluar di `cash_transactions` (source_type='crew_commission')
 * sehingga sinkron dengan Pengeluaran, Buku Kas, Dashboard, dan Laporan Keuangan.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../_cash_query.php';

/** Ekspresi SQL nominal komisi per baris booking_cost_lines. */
function comm_amount_expr(string $alias = 'bcl'): string
{
    return "COALESCE(CASE WHEN {$alias}.premi_amount > 0 THEN {$alias}.premi_amount ELSE {$alias}.amount END, 0)";
}

/** Ekspresi SQL tanggal efektif trip (tanggal keberangkatan travel/sewa, fallback created_date). */
function comm_date_expr(): string
{
    return 'COALESCE(tb.date, rb.start_date, rb.date, DATE(bcl.created_date))';
}

/** Klausa FROM + JOIN bersama untuk query komisi sopir/kru. */
function comm_joins_sql(): string
{
    return "FROM booking_cost_lines bcl
        LEFT JOIN companies c ON c.id = bcl.company_id
        LEFT JOIN travel_ticket_bookings tb
               ON bcl.booking_type = 'travel' AND tb.id = bcl.booking_id AND tb.company_id = bcl.company_id
        LEFT JOIN rental_car_bookings rb
               ON bcl.booking_type = 'rental' AND rb.id = bcl.booking_id AND rb.company_id = bcl.company_id
        LEFT JOIN employees e ON e.id = NULLIF(bcl.employee_id, 0)
        LEFT JOIN vehicles v ON v.id = NULLIF(COALESCE(tb.vehicle_id, rb.vehicle_id), 0)
        LEFT JOIN cash_transactions ct ON ct.id = NULLIF(bcl.paid_cash_tx_id, 0) AND ct.company_id = bcl.company_id";
}

/** Normalisasi filter halaman Komisi Sopir & Kru. */
function comm_filter(?array $in = null): array
{
    $in = $in ?? $_GET;
    $d = static fn (string $k, string $def): string =>
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) ? (string) $in[$k] : $def;
    $bt = (string) ($in['booking_type'] ?? 'all');
    if (!in_array($bt, ['all', 'travel', 'rental'], true)) {
        $bt = 'all';
    }
    $st = (string) ($in['status'] ?? 'all');
    if (!in_array($st, ['all', 'unpaid', 'paid'], true)) {
        $st = 'all';
    }
    return [
        'from'         => $d('from', date('Y-m-01')),
        'to'           => $d('to', date('Y-m-t')),
        'employee_id'  => max(0, (int) ($in['employee_id'] ?? 0)),
        'booking_type' => $bt,
        'status'       => $st,
        'company_id'   => isset($in['company_id']) && $in['company_id'] !== '' ? max(0, (int) $in['company_id']) : 0,
        'q'            => trim((string) ($in['q'] ?? '')),
    ];
}

/** Bentuk query string whitelist untuk redirect kembali ke halaman Komisi. */
function comm_build_qs(array $f): string
{
    $p = [
        'from'         => (string) ($f['from'] ?? date('Y-m-01')),
        'to'           => (string) ($f['to'] ?? date('Y-m-t')),
        'booking_type' => (string) ($f['booking_type'] ?? 'all'),
        'status'       => (string) ($f['status'] ?? 'all'),
    ];
    if (!empty($f['employee_id'])) {
        $p['employee_id'] = (int) $f['employee_id'];
    }
    if (!empty($f['q'])) {
        $p['q'] = (string) $f['q'];
    }
    if (is_super_admin() && isset($f['company_id'])) {
        $p['company_id'] = (int) $f['company_id'];
    }
    return http_build_query($p);
}

/**
 * Klausa WHERE komisi kru (mengecualikan baris 'payment' & 'bbm').
 * @return array{sql:string, types:string, params:array}
 */
function comm_where(array $f, bool $with_status = true): array
{
    finance_ensure_schema();
    [$w, $types, $params] = cash_company_scope('bcl.company_id', $f);

    $dateExpr = comm_date_expr();
    $amtExpr  = comm_amount_expr('bcl');

    $conds = [];
    if ($w !== '') {
        $conds[] = $w;
    }
    $conds[] = "bcl.cost_type <> 'payment'";
    $conds[] = "LOWER(TRIM(bcl.cost_type)) <> 'bbm'";
    $conds[] = "(bcl.employee_id IS NOT NULL OR bcl.premi_amount > 0 OR LOWER(TRIM(bcl.cost_type)) IN ('sopir','driver','kernet','kru','montir','agen','komisi'))";
    $conds[] = "{$amtExpr} > 0";
    $conds[] = "{$dateExpr} >= ? AND {$dateExpr} <= ?";
    $types  .= 'ss';
    $params[] = $f['from'];
    $params[] = $f['to'];

    if (!empty($f['employee_id']) && (int) $f['employee_id'] > 0) {
        $conds[] = 'bcl.employee_id = ?';
        $types  .= 'i';
        $params[] = (int) $f['employee_id'];
    }
    if (($f['booking_type'] ?? 'all') !== 'all') {
        $conds[] = 'bcl.booking_type = ?';
        $types  .= 's';
        $params[] = (string) $f['booking_type'];
    }
    if ($with_status) {
        if (($f['status'] ?? 'all') === 'unpaid') {
            $conds[] = 'bcl.paid_cash_tx_id IS NULL';
        } elseif (($f['status'] ?? 'all') === 'paid') {
            $conds[] = 'bcl.paid_cash_tx_id IS NOT NULL';
        }
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $conds[] = "(COALESCE(e.name, '') LIKE ? OR COALESCE(e.code, '') LIKE ? OR COALESCE(tb.document_number, rb.document_number, '') LIKE ? OR COALESCE(bcl.cost_type, '') LIKE ? OR COALESCE(v.vehicle_number, '') LIKE ?)";
        $types  .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    return ['sql' => implode(' AND ', $conds), 'types' => $types, 'params' => $params];
}

/** Ringkasan KPI komisi pada rentang & filter. */
function comm_summary(array $f): array
{
    $cw      = comm_where($f, true);
    $joins   = comm_joins_sql();
    $amtExpr = comm_amount_expr('bcl');

    $sql = "SELECT
                COALESCE(SUM({$amtExpr}), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN bcl.paid_cash_tx_id IS NULL THEN {$amtExpr} ELSE 0 END), 0) AS unpaid_amount,
                COALESCE(SUM(CASE WHEN bcl.paid_cash_tx_id IS NOT NULL THEN {$amtExpr} ELSE 0 END), 0) AS paid_amount,
                COUNT(*) AS total_lines,
                SUM(CASE WHEN bcl.paid_cash_tx_id IS NULL THEN 1 ELSE 0 END) AS unpaid_lines,
                SUM(CASE WHEN bcl.paid_cash_tx_id IS NOT NULL THEN 1 ELSE 0 END) AS paid_lines,
                COUNT(DISTINCT NULLIF(bcl.employee_id, 0)) AS employee_count
            {$joins}
            WHERE {$cw['sql']}";
    $st = db()->prepare($sql);
    if ($cw['types'] !== '') {
        $st->bind_param($cw['types'], ...$cw['params']);
    }
    $st->execute();
    $r = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    return [
        'total_amount'   => (float) ($r['total_amount'] ?? 0),
        'unpaid_amount'  => (float) ($r['unpaid_amount'] ?? 0),
        'paid_amount'    => (float) ($r['paid_amount'] ?? 0),
        'total_lines'    => (int) ($r['total_lines'] ?? 0),
        'unpaid_lines'   => (int) ($r['unpaid_lines'] ?? 0),
        'paid_lines'     => (int) ($r['paid_lines'] ?? 0),
        'employee_count' => (int) ($r['employee_count'] ?? 0),
    ];
}

/** Rekap komisi dikelompokkan per sopir/kru. */
function comm_by_employee(array $f): array
{
    $cf = $f;
    $cf['employee_id'] = 0;
    $cw      = comm_where($cf, true);
    $joins   = comm_joins_sql();
    $amtExpr = comm_amount_expr('bcl');

    $sql = "SELECT
                COALESCE(bcl.employee_id, 0) AS employee_id,
                COALESCE(NULLIF(TRIM(e.name), ''), '(Kru Belum Ditautkan)') AS employee_name,
                COALESCE(e.code, '') AS employee_code,
                COALESCE(e.job_position, '') AS job_position,
                COALESCE(e.bank_name, '') AS bank_name,
                COALESCE(e.account_number, '') AS account_number,
                COUNT(*) AS total_lines,
                SUM(CASE WHEN bcl.paid_cash_tx_id IS NULL THEN 1 ELSE 0 END) AS unpaid_lines,
                COALESCE(SUM({$amtExpr}), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN bcl.paid_cash_tx_id IS NULL THEN {$amtExpr} ELSE 0 END), 0) AS unpaid_amount,
                COALESCE(SUM(CASE WHEN bcl.paid_cash_tx_id IS NOT NULL THEN {$amtExpr} ELSE 0 END), 0) AS paid_amount
            {$joins}
            WHERE {$cw['sql']}
            GROUP BY employee_id, employee_name, employee_code, job_position, bank_name, account_number
            ORDER BY unpaid_amount DESC, total_amount DESC, employee_name ASC";
    $st = db()->prepare($sql);
    if ($cw['types'] !== '') {
        $st->bind_param($cw['types'], ...$cw['params']);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Daftar baris komisi untuk tabel rincian & pencairan. */
function comm_list(array $f, ?int $limit = null, int $offset = 0): array
{
    $cw       = comm_where($f, true);
    $joins    = comm_joins_sql();
    $amtExpr  = comm_amount_expr('bcl');
    $dateExpr = comm_date_expr();

    $sql = "SELECT
                bcl.id,
                bcl.company_id,
                c.code AS company_code,
                bcl.booking_type,
                bcl.booking_id,
                bcl.cost_type,
                bcl.description,
                bcl.employee_id,
                bcl.premi_type,
                bcl.premi_value,
                bcl.amount AS line_amount,
                bcl.premi_amount,
                {$amtExpr} AS commission_amount,
                bcl.note,
                bcl.paid_cash_tx_id,
                bcl.paid_at,
                {$dateExpr} AS trip_date,
                COALESCE(tb.document_number, rb.document_number, CONCAT('#', bcl.booking_id)) AS document_number,
                CASE WHEN tb.id IS NOT NULL OR rb.id IS NOT NULL THEN 1 ELSE 0 END AS booking_exists,
                e.name AS employee_name,
                e.code AS employee_code,
                e.job_position,
                v.vehicle_number,
                ct.date AS cash_paid_date,
                ct.proof_blob_path AS cash_proof_blob_path
            {$joins}
            WHERE {$cw['sql']}
            ORDER BY (bcl.paid_cash_tx_id IS NOT NULL) ASC, trip_date DESC, bcl.id DESC";
    $types  = $cw['types'];
    $params = $cw['params'];
    if ($limit !== null) {
        $sql     .= ' LIMIT ? OFFSET ?';
        $types   .= 'ii';
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

function comm_count(array $f): int
{
    $cw    = comm_where($f, true);
    $joins = comm_joins_sql();
    $st = db()->prepare("SELECT COUNT(*) {$joins} WHERE {$cw['sql']}");
    if ($cw['types'] !== '') {
        $st->bind_param($cw['types'], ...$cw['params']);
    }
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/** Opsi karyawan/sopir untuk filter dropdown. */
function comm_employee_options(?int $company_id = null): array
{
    finance_ensure_schema();
    $cid = $company_id ?? (int) (active_company_id() ?? 0);
    if ($cid === 0 && is_super_admin()) {
        return db()->query(
            "SELECT e.id, e.company_id, c.code AS company_code, e.code, e.name, e.job_position
               FROM employees e
               LEFT JOIN companies c ON c.id = e.company_id
              WHERE e.status = 'aktif'
                 OR EXISTS (SELECT 1 FROM booking_cost_lines bcl WHERE bcl.employee_id = e.id AND bcl.cost_type <> 'payment')
              ORDER BY e.name ASC"
        )->fetch_all(MYSQLI_ASSOC);
    }
    if ($cid <= 0) {
        return [];
    }
    $st = db()->prepare(
        "SELECT e.id, e.company_id, c.code AS company_code, e.code, e.name, e.job_position
           FROM employees e
           LEFT JOIN companies c ON c.id = e.company_id
          WHERE e.company_id = ?
            AND (e.status = 'aktif'
                 OR EXISTS (SELECT 1 FROM booking_cost_lines bcl WHERE bcl.employee_id = e.id AND bcl.cost_type <> 'payment'))
          ORDER BY e.name ASC"
    );
    $st->bind_param('i', $cid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Cairkan daftar baris komisi (belum dibayar) dalam satu transaksi atomik:
 * membuat 1 `cash_transactions` (direction='out', category='Uang Jalan & Komisi Kru', source_type='crew_commission')
 * lalu menandai semua `booking_cost_lines` terpilih sebagai lunas.
 */
function comm_pay_lines(int $company_id, array $lineIds, array $opts, ?string $user): array
{
    finance_ensure_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $v): bool => $v > 0)));
    if ($company_id <= 0 || !$ids) {
        throw new InvalidArgumentException('Pilih minimal 1 baris komisi yang belum dibayar.');
    }

    $payDate = trim((string) ($opts['pay_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate) || !checkdate((int) substr($payDate, 5, 2), (int) substr($payDate, 8, 2), (int) substr($payDate, 0, 4))) {
        throw new InvalidArgumentException('Tanggal pembayaran tidak valid.');
    }

    $proofPath = isset($opts['proof_blob_path']) && trim((string) $opts['proof_blob_path']) !== ''
        ? mb_substr(trim((string) $opts['proof_blob_path']), 0, 255)
        : null;
    $noteTxt = trim((string) ($opts['note'] ?? ''));
    $userStr = (string) ($user ?? 'system');

    $db = db();
    $db->begin_transaction();
    try {
        $ph     = implode(',', array_fill(0, count($ids), '?'));
        $types  = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$company_id], $ids);
        $amtExpr = comm_amount_expr('bcl');

        $stLock = $db->prepare(
            "SELECT bcl.id, bcl.booking_type, bcl.booking_id, bcl.cost_type,
                    {$amtExpr} AS commission_amount,
                    COALESCE(NULLIF(TRIM(e.name), ''), bcl.cost_type) AS crew_label
               FROM booking_cost_lines bcl
               LEFT JOIN employees e ON e.id = NULLIF(bcl.employee_id, 0)
              WHERE bcl.company_id = ?
                AND bcl.id IN ({$ph})
                AND bcl.cost_type <> 'payment'
                AND LOWER(TRIM(bcl.cost_type)) <> 'bbm'
                AND bcl.paid_cash_tx_id IS NULL
                FOR UPDATE"
        );
        $stLock->bind_param($types, ...$params);
        $stLock->execute();
        $lockedRows = $stLock->get_result()->fetch_all(MYSQLI_ASSOC);
        $stLock->close();

        $validIds    = [];
        $totalAmount = 0.0;
        $crewNames   = [];
        foreach ($lockedRows as $lr) {
            $amt = (float) ($lr['commission_amount'] ?? 0);
            if ($amt <= 0) {
                continue;
            }
            $validIds[]   = (int) $lr['id'];
            $totalAmount += $amt;
            $lbl = trim((string) ($lr['crew_label'] ?? ''));
            if ($lbl !== '' && !in_array($lbl, $crewNames, true)) {
                $crewNames[] = $lbl;
            }
        }

        if (!$validIds || $totalAmount <= 0) {
            throw new InvalidArgumentException('Baris komisi yang dipilih sudah dibayar atau nominalnya nol.');
        }

        $firstLineId = $validIds[0];
        $crewPreview = implode(', ', array_slice($crewNames, 0, 3));
        if (count($crewNames) > 3) {
            $crewPreview .= ' (+' . (count($crewNames) - 3) . ' lainnya)';
        }
        $txDesc = 'Pencairan Komisi Sopir/Kru (' . count($validIds) . ' trip' . ($crewPreview !== '' ? ': ' . $crewPreview : '') . ')';
        if ($noteTxt !== '') {
            $txDesc .= ' · ' . $noteTxt;
        }
        $txDesc   = mb_substr($txDesc, 0, 1000);
        $category = 'Uang Jalan & Komisi Kru';
        $now      = date('Y-m-d H:i:s');

        $stIns = $db->prepare(
            "INSERT INTO cash_transactions
                (company_id, date, direction, amount, category, description, source_type, source_id, proof_blob_path, created_user, created_date)
             VALUES (?, ?, 'out', ?, ?, ?, 'crew_commission', ?, ?, ?, ?)"
        );
        $stIns->bind_param(
            'isdssisss',
            $company_id,
            $payDate,
            $totalAmount,
            $category,
            $txDesc,
            $firstLineId,
            $proofPath,
            $userStr,
            $now
        );
        $stIns->execute();
        $cashTxId = (int) $stIns->insert_id;
        $stIns->close();

        $updPh = implode(',', array_fill(0, count($validIds), '?'));
        $stUpd = $db->prepare(
            "UPDATE booking_cost_lines
                SET paid_cash_tx_id = ?, paid_at = ?, modified_user = ?, modified_date = NOW()
              WHERE company_id = ? AND id IN ({$updPh}) AND paid_cash_tx_id IS NULL"
        );
        $stUpd->bind_param('issi' . str_repeat('i', count($validIds)), $cashTxId, $now, $userStr, $company_id, ...$validIds);
        $stUpd->execute();
        $paidCount = $stUpd->affected_rows;
        $stUpd->close();

        $db->commit();
        return [
            'cash_tx_id'   => $cashTxId,
            'paid_count'   => $paidCount,
            'total_amount' => $totalAmount,
            'crew_preview' => $crewPreview,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Batalkan pencairan komisi kru: reset `paid_cash_tx_id/paid_at` pada `booking_cost_lines`
 * dan hapus baris `cash_transactions` terkait.
 */
function comm_cancel_payment(int $company_id, int $cashTxId, ?string $user): array
{
    finance_ensure_schema();
    if ($company_id <= 0 || $cashTxId <= 0) {
        throw new InvalidArgumentException('ID transaksi pencairan tidak valid.');
    }
    $userStr = (string) ($user ?? 'system');
    $db = db();
    $db->begin_transaction();
    try {
        $stSel = $db->prepare(
            "SELECT id, amount, proof_blob_path
               FROM cash_transactions
              WHERE id = ? AND company_id = ? AND source_type = 'crew_commission'
              LIMIT 1 FOR UPDATE"
        );
        $stSel->bind_param('ii', $cashTxId, $company_id);
        $stSel->execute();
        $tx = $stSel->get_result()->fetch_assoc();
        $stSel->close();

        if (!$tx) {
            throw new InvalidArgumentException('Transaksi pencairan komisi tidak ditemukan.');
        }

        $stReset = $db->prepare(
            'UPDATE booking_cost_lines
                SET paid_cash_tx_id = NULL, paid_at = NULL, modified_user = ?, modified_date = NOW()
              WHERE company_id = ? AND paid_cash_tx_id = ?'
        );
        $stReset->bind_param('sii', $userStr, $company_id, $cashTxId);
        $stReset->execute();
        $resetLines = $stReset->affected_rows;
        $stReset->close();

        $stDel = $db->prepare("DELETE FROM cash_transactions WHERE id = ? AND company_id = ? AND source_type = 'crew_commission'");
        $stDel->bind_param('ii', $cashTxId, $company_id);
        $stDel->execute();
        $stDel->close();

        $db->commit();

        if (!empty($tx['proof_blob_path'])) {
            azure_blob_delete((string) $tx['proof_blob_path']);
        }

        return [
            'cash_tx_id'  => $cashTxId,
            'amount'      => (float) ($tx['amount'] ?? 0),
            'reset_lines' => $resetLines,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
