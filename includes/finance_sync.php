<?php
/**
 * Sinkronisasi & master data terpusat modul Keuangan:
 * - Lazy schema ensure (`cash_transactions.source_type/source_id`, `payment_methods.is_cash`).
 * - Provisioning default Akun Kas/Bank per perusahaan & Metode Pembayaran standar global.
 * - Auto-routing Metode Pembayaran -> Akun Kas (`kas`) atau Bank (`bank`).
 * - Auto-sync idempoten pembayaran Booking/Invoice/QRIS ke `cash_transactions`.
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Pastikan kolom linkage sinkronisasi pada `cash_transactions` & `payment_methods` tersedia.
 * Aman dipanggil berulang; memakai guard static + session.
 */
function finance_ensure_schema(): void
{
    static $checked = false;
    $hasSess = session_status() === PHP_SESSION_ACTIVE;
    if ($checked || ($hasSess && !empty($_SESSION['_vst_schema_fin_v1']))) {
        $checked = true;
        return;
    }
    $checked = true;
    $db = db();

    $txCols = [];
    $res = $db->query('SHOW COLUMNS FROM `cash_transactions`');
    if ($res) {
        while ($c = $res->fetch_assoc()) {
            $txCols[(string) $c['Field']] = true;
        }
    }
    if (empty($txCols['source_type'])) {
        $db->query("ALTER TABLE `cash_transactions` ADD COLUMN `source_type` VARCHAR(30) DEFAULT NULL COMMENT 'booking_payment|payroll_period|NULL=manual' AFTER `payment_method_id`");
    }
    if (empty($txCols['source_id'])) {
        $db->query("ALTER TABLE `cash_transactions` ADD COLUMN `source_id` INT(11) DEFAULT NULL COMMENT 'id booking_cost_lines / payroll_periods' AFTER `source_type`");
    }
    $idxRes = $db->query("SHOW INDEX FROM `cash_transactions` WHERE Key_name = 'ix_cashtx_source'");
    if ($idxRes && $idxRes->num_rows === 0) {
        $db->query('ALTER TABLE `cash_transactions` ADD KEY `ix_cashtx_source` (`source_type`, `source_id`)');
    }

    $pmCols = [];
    $pmRes = $db->query('SHOW COLUMNS FROM `payment_methods`');
    if ($pmRes) {
        while ($c = $pmRes->fetch_assoc()) {
            $pmCols[(string) $c['Field']] = true;
        }
    }
    if (empty($pmCols['is_cash'])) {
        $db->query("ALTER TABLE `payment_methods` ADD COLUMN `is_cash` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=kas tunai, 0=bank/non-tunai' AFTER `auto_verify`");
        $db->query("UPDATE `payment_methods` SET is_cash = 1 WHERE UPPER(code) IN ('TUNAI','CASH') OR LOWER(name) LIKE '%tunai%'");
    }

    if ($hasSess) {
        $_SESSION['_vst_schema_fin_v1'] = 1;
    }
}

/**
 * Pastikan metode pembayaran standar global (`company_id IS NULL`) tersedia & idempoten.
 * Catatan: UNIQUE(company_id, code) pada MySQL mengizinkan duplicate NULL, sehingga dicek eksplisit.
 */
function finance_ensure_global_payment_methods(?string $user = 'system'): void
{
    finance_ensure_schema();
    $db = db();
    $defaults = [
        ['code' => 'TUNAI',    'name' => 'Tunai / Cash',             'is_cash' => 1, 'sort' => 10, 'instr' => 'Pembayaran tunai diterima langsung oleh kasir/sopir dan dicatat ke Kas Tunai.'],
        ['code' => 'TRANSFER', 'name' => 'Transfer Bank',            'is_cash' => 0, 'sort' => 20, 'instr' => 'Transfer ke rekening bank utama perusahaan.'],
        ['code' => 'QRIS',     'name' => 'QRIS Statis / Manual',     'is_cash' => 0, 'sort' => 30, 'instr' => 'Scan QRIS toko/perusahaan lalu lampirkan bukti pembayaran.'],
        ['code' => 'EDC',      'name' => 'EDC / Kartu Debit-Kredit', 'is_cash' => 0, 'sort' => 40, 'instr' => 'Pembayaran melalui mesin EDC / kartu debit atau kredit.'],
    ];

    $sel = $db->prepare('SELECT id FROM payment_methods WHERE company_id IS NULL AND code = ? LIMIT 1');
    $ins = $db->prepare('INSERT INTO payment_methods (company_id, code, name, instructions, sort_order, auto_verify, is_cash, is_active, created_user) VALUES (NULL, ?, ?, ?, ?, 0, ?, 1, ?)');
    foreach ($defaults as $m) {
        $sel->bind_param('s', $m['code']);
        $sel->execute();
        $found = $sel->get_result()->fetch_assoc();
        if (!$found) {
            $ins->bind_param('sssiis', $m['code'], $m['name'], $m['instr'], $m['sort'], $m['is_cash'], $user);
            $ins->execute();
        }
    }
    $sel->close();
    $ins->close();
}

/**
 * Provisioning default Akun Kas/Bank (KAS-TUNAI & BANK-UTAMA) untuk sebuah perusahaan
 * plus metode pembayaran standar global agar langsung siap pakai saat transaksi pertama.
 */
function finance_ensure_company_defaults(int $company_id, ?string $user = 'system'): void
{
    if ($company_id <= 0) {
        return;
    }
    finance_ensure_schema();
    finance_ensure_global_payment_methods($user);
    $db = db();

    $chkType = $db->prepare('SELECT COUNT(*) FROM cash_accounts WHERE company_id = ? AND type = ?');
    $insAcc  = $db->prepare('INSERT IGNORE INTO cash_accounts (company_id, code, name, type, opening_balance, is_active, note, created_user) VALUES (?, ?, ?, ?, 0, 1, ?, ?)');

    $defaults = [
        ['type' => 'kas',  'code' => 'KAS-TUNAI',  'name' => 'Kas Tunai Operasional', 'note' => 'Akun kas tunai default (otomatis untuk pembayaran Tunai)'],
        ['type' => 'bank', 'code' => 'BANK-UTAMA', 'name' => 'Bank Utama Perusahaan', 'note' => 'Akun bank default (otomatis untuk Transfer / QRIS / EDC)'],
    ];

    foreach ($defaults as $acc) {
        $chkType->bind_param('is', $company_id, $acc['type']);
        $chkType->execute();
        $cnt = (int) $chkType->get_result()->fetch_row()[0];
        if ($cnt === 0) {
            $insAcc->bind_param('isssss', $company_id, $acc['code'], $acc['name'], $acc['type'], $acc['note'], $user);
            $insAcc->execute();
        }
    }

    $chkType->close();
    $insAcc->close();
}

/** Daftar metode pembayaran aktif milik perusahaan + global, urut sort_order. */
function finance_active_payment_methods(int $company_id): array
{
    finance_ensure_schema();
    $st = db()->prepare(
        'SELECT id, company_id, code, name, account_name, account_number, auto_verify, is_cash, sort_order
           FROM payment_methods
          WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL)
          ORDER BY sort_order ASC, name ASC'
    );
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Apakah label/metode tergolong pembayaran tunai (kas). */
function finance_is_cash_method(?string $label, ?array $pmRow = null): bool
{
    if ($pmRow !== null) {
        if (!empty($pmRow['is_cash'])) {
            return true;
        }
        $pmCode = strtoupper((string) ($pmRow['code'] ?? ''));
        $pmName = (string) ($pmRow['name'] ?? '');
        if ($pmCode === 'TUNAI' || $pmCode === 'CASH' || preg_match('/\b(tunai|cash)\b/i', $pmName)) {
            return true;
        }
        if ($label === null || trim($label) === '') {
            return false;
        }
    }
    return $label !== null && (bool) preg_match('/\b(tunai|cash)\b/i', $label);
}

/**
 * Resolusi `payment_methods.id` berdasarkan nama/kode teks dari tab Booking atau catatan.
 */
function finance_resolve_payment_method_id(int $company_id, ?string $methodLabel): ?int
{
    $raw = trim((string) $methodLabel);
    if ($raw === '') {
        return null;
    }
    // Jika formatnya 'DP | Transfer Bank', ambil bagian kanan setelah '|'.
    if (str_contains($raw, '|')) {
        $parts = explode('|', $raw, 2);
        $raw   = trim((string) ($parts[1] ?? $raw));
    }

    $methods = finance_active_payment_methods($company_id);
    if (!$methods) {
        return null;
    }

    $low = mb_strtolower($raw);
    // Urutkan prioritas perusahaan dulu atas global jika sama-sama cocok.
    usort($methods, static function (array $a, array $b): int {
        $ca = !empty($a['company_id']) ? 0 : 1;
        $cb = !empty($b['company_id']) ? 0 : 1;
        return $ca <=> $cb ?: ((int) $a['sort_order'] <=> (int) $b['sort_order']);
    });

    foreach ($methods as $m) {
        $mName = mb_strtolower(trim((string) $m['name']));
        $mCode = mb_strtolower(trim((string) $m['code']));
        if ($low === $mName || $low === $mCode) {
            return (int) $m['id'];
        }
    }

    $wantCode = null;
    if (str_contains($low, 'qris') && (str_contains($low, 'otomatis') || str_contains($low, 'dinamis') || str_contains($low, '#'))) {
        $wantCode = 'QRIS-AUTO';
    } elseif (str_contains($low, 'qris')) {
        $wantCode = 'QRIS';
    } elseif (preg_match('/\b(tunai|cash)\b/i', $low)) {
        $wantCode = 'TUNAI';
    } elseif (str_contains($low, 'edc') || str_contains($low, 'kartu') || str_contains($low, 'debit') || str_contains($low, 'kredit')) {
        $wantCode = 'EDC';
    } elseif (str_contains($low, 'transfer') || str_contains($low, 'va') || str_contains($low, 'bank')) {
        $wantCode = 'TRANSFER';
    }

    if ($wantCode !== null) {
        foreach ($methods as $m) {
            if (strtoupper((string) $m['code']) === $wantCode) {
                return (int) $m['id'];
            }
        }
    }

    foreach ($methods as $m) {
        $mName = mb_strtolower((string) $m['name']);
        if (str_contains($mName, $low) || str_contains($low, $mName)) {
            return (int) $m['id'];
        }
    }
    return null;
}

/**
 * Pilih Akun Kas/Bank aktif milik `$company_id`:
 * - `$explicitAccountId < 0` => null (sengaja tidak dicatat ke Kas/Bank)
 * - `$explicitAccountId > 0` => verifikasi akun milik company & aktif
 * - `$explicitAccountId === null` => auto-routing ke 'kas' (Tunai) atau 'bank' (Transfer/QRIS/EDC).
 */
function finance_resolve_cash_account_id(int $company_id, ?string $methodLabel = null, ?int $methodId = null, ?int $explicitAccountId = null): ?int
{
    if ($company_id <= 0) {
        return null;
    }
    if ($explicitAccountId !== null) {
        if ($explicitAccountId < 0) {
            return null;
        }
        if ($explicitAccountId > 0) {
            $st = db()->prepare('SELECT id FROM cash_accounts WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
            $st->bind_param('ii', $explicitAccountId, $company_id);
            $st->execute();
            $acc = $st->get_result()->fetch_assoc();
            $st->close();
            if ($acc) {
                return (int) $acc['id'];
            }
        }
    }

    $db = db();
    // Pastikan minimal ada akun aktif; jika belum ada sama sekali, provision default.
    $chk = $db->prepare('SELECT COUNT(*) FROM cash_accounts WHERE company_id = ? AND is_active = 1');
    $chk->bind_param('i', $company_id);
    $chk->execute();
    $cnt = (int) $chk->get_result()->fetch_row()[0];
    $chk->close();
    if ($cnt === 0) {
        finance_ensure_company_defaults($company_id, 'system');
    }

    $pmRow = null;
    if ($methodId !== null && $methodId > 0) {
        $stPm = $db->prepare('SELECT id, code, name, is_cash FROM payment_methods WHERE id = ? LIMIT 1');
        $stPm->bind_param('i', $methodId);
        $stPm->execute();
        $pmRow = $stPm->get_result()->fetch_assoc();
        $stPm->close();
    }

    $wantType = finance_is_cash_method($methodLabel, $pmRow) ? 'kas' : 'bank';

    $stAcc = $db->prepare(
        "SELECT id, type FROM cash_accounts
          WHERE company_id = ? AND is_active = 1
          ORDER BY CASE WHEN type = ? THEN 0 ELSE 1 END ASC,
                   CASE WHEN code IN ('KAS-TUNAI', 'BANK-UTAMA') THEN 0 ELSE 1 END ASC,
                   id ASC
          LIMIT 1"
    );
    $stAcc->bind_param('is', $company_id, $wantType);
    $stAcc->execute();
    $pick = $stAcc->get_result()->fetch_assoc();
    $stAcc->close();

    return $pick ? (int) $pick['id'] : null;
}

/** Ekstrak label metode pembayaran yang bersih dari string `note` `Tipe | Metode`. */
function finance_extract_method_label(?string $note): string
{
    $raw = trim((string) $note);
    if ($raw === '') {
        return '';
    }
    if (str_contains($raw, '|')) {
        $parts = explode('|', $raw, 2);
        return trim((string) ($parts[1] ?? ''));
    }
    return $raw;
}

/** Hapus entri `cash_transactions` hasil auto-sync untuk daftar ID `booking_cost_lines`. */
function finance_unsync_cost_line_ids(int $company_id, array $cost_line_ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $cost_line_ids), static fn (int $v): bool => $v > 0)));
    if (!$ids) {
        return;
    }
    finance_ensure_schema();
    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $types  = 'is' . str_repeat('i', count($ids));
    $srcType = 'booking_payment';
    $params = array_merge([$company_id, $srcType], $ids);
    $st = db()->prepare("DELETE FROM cash_transactions WHERE company_id = ? AND source_type = ? AND source_id IN ({$ph})");
    $st->bind_param($types, ...$params);
    $st->execute();
    $st->close();
}

/** Hapus semua transaksi kas yang tersinkron dari suatu booking saat booking dihapus. */
function finance_unsync_booking(string $booking_type, int $booking_id, int $company_id): void
{
    if ($booking_id <= 0 || $company_id <= 0) {
        return;
    }
    finance_ensure_schema();
    $db = db();
    $st = $db->prepare(
        "DELETE ct FROM cash_transactions ct
           JOIN booking_cost_lines bcl ON ct.source_type = 'booking_payment' AND ct.source_id = bcl.id
          WHERE bcl.booking_type = ? AND bcl.booking_id = ? AND bcl.company_id = ? AND ct.company_id = ?"
    );
    $st->bind_param('siii', $booking_type, $booking_id, $company_id, $company_id);
    $st->execute();
    $st->close();
}

/**
 * Sinkronkan baris `booking_cost_lines` (`cost_type='payment'`) milik sebuah invoice ke `cash_transactions`
 * secara idempoten (berdasarkan `source_type='booking_payment'`, `source_id=bcl.id`).
 *
 * @param array<int, int> $accountOverrides Peta `cost_line_id => cash_account_id` (atau -1 untuk lewati).
 */
function finance_sync_invoice_payments_to_cash(
    int $company_id,
    int $invoice_id,
    string $invoice_no,
    string $booking_type,
    string $document_number = '',
    array $accountOverrides = [],
    ?int $defaultAccountOverride = null
): void {
    if ($company_id <= 0 || $invoice_id <= 0) {
        return;
    }
    finance_ensure_schema();
    $db = db();

    $descKey = 'INV#' . $invoice_id;
    $stLines = $db->prepare(
        "SELECT bcl.id, bcl.amount, bcl.payment_method_id, bcl.note, bcl.created_user, bcl.created_date,
                pm.name AS pm_name, pm.code AS pm_code, pm.is_cash AS pm_is_cash
           FROM booking_cost_lines bcl
           LEFT JOIN payment_methods pm ON pm.id = NULLIF(bcl.payment_method_id, 0)
          WHERE bcl.company_id = ? AND bcl.cost_type = 'payment' AND bcl.description = ?
          ORDER BY bcl.id ASC"
    );
    $stLines->bind_param('is', $company_id, $descKey);
    $stLines->execute();
    $lines = $stLines->get_result()->fetch_all(MYSQLI_ASSOC);
    $stLines->close();

    if (!$lines) {
        return;
    }

    $ids   = array_map(static fn (array $r): int => (int) $r['id'], $lines);
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $types = 's' . str_repeat('i', count($ids));
    $src   = 'booking_payment';
    $stEx  = $db->prepare("SELECT id, source_id FROM cash_transactions WHERE source_type = ? AND source_id IN ({$ph})");
    $stEx->bind_param($types, $src, ...$ids);
    $stEx->execute();
    $existingMap = [];
    foreach ($stEx->get_result()->fetch_all(MYSQLI_ASSOC) as $ex) {
        $existingMap[(int) $ex['source_id']] = (int) $ex['id'];
    }
    $stEx->close();

    $category = 'Pendapatan ' . ($booking_type === 'travel' ? 'Travel' : 'Rental');
    $invLabel = $invoice_no !== '' ? $invoice_no : $descKey;
    $docPart  = $document_number !== '' ? ' (' . $document_number . ')' : '';

    $insTx = $db->prepare(
        "INSERT INTO cash_transactions
            (company_id, cash_account_id, date, direction, amount, category, description, payment_method_id, source_type, source_id, created_user, created_date)
         VALUES (?, ?, ?, 'in', ?, ?, ?, ?, 'booking_payment', ?, ?, ?)"
    );
    $updTx = $db->prepare(
        'UPDATE cash_transactions
            SET cash_account_id = ?, date = ?, amount = ?, category = ?, description = ?, payment_method_id = ?, modified_user = ?, modified_date = NOW()
          WHERE id = ? AND company_id = ?'
    );
    $updBclPm = $db->prepare('UPDATE booking_cost_lines SET payment_method_id = ? WHERE id = ? AND company_id = ?');

    foreach ($lines as $ln) {
        $bclId  = (int) $ln['id'];
        $amount = round((float) $ln['amount'], 2);
        if ($amount <= 0) {
            continue;
        }
        $override = $accountOverrides[$bclId] ?? $defaultAccountOverride;
        if ($override !== null && $override < 0) {
            continue; // User memilih tidak mencatat transaksi kas
        }

        $methodLabel = finance_extract_method_label((string) ($ln['note'] ?? ''));
        if ($methodLabel === '' && !empty($ln['pm_name'])) {
            $methodLabel = (string) $ln['pm_name'];
        }
        $pmId = (int) ($ln['payment_method_id'] ?? 0);
        if ($pmId <= 0 && $methodLabel !== '') {
            $resolvedPm = finance_resolve_payment_method_id($company_id, $methodLabel);
            if ($resolvedPm !== null && $resolvedPm > 0) {
                $pmId = $resolvedPm;
                $updBclPm->bind_param('iii', $pmId, $bclId, $company_id);
                $updBclPm->execute();
            }
        }
        $pmIdParam = $pmId > 0 ? $pmId : null;

        $accId = finance_resolve_cash_account_id($company_id, $methodLabel, $pmIdParam, $override);
        if ($accId === null || $accId <= 0) {
            continue;
        }

        $rawDate   = (string) ($ln['created_date'] ?? '');
        $txDate    = preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDate) ? substr($rawDate, 0, 10) : date('Y-m-d');
        $txCreated = $rawDate !== '' ? $rawDate : date('Y-m-d H:i:s');
        $noteTxt   = trim((string) ($ln['note'] ?? ''));
        $txDesc    = mb_substr('Pembayaran ' . $invLabel . $docPart . ($noteTxt !== '' ? ' · ' . $noteTxt : ''), 0, 1000);
        $txUser    = (string) ($ln['created_user'] ?? 'system');

        if (!empty($existingMap[$bclId])) {
            $txId = $existingMap[$bclId];
            $updTx->bind_param('isdssisii', $accId, $txDate, $amount, $category, $txDesc, $pmIdParam, $txUser, $txId, $company_id);
            $updTx->execute();
        } else {
            $insTx->bind_param('iisdssiiss', $company_id, $accId, $txDate, $amount, $category, $txDesc, $pmIdParam, $bclId, $txUser, $txCreated);
            $insTx->execute();
        }
    }

    $insTx->close();
    $updTx->close();
    $updBclPm->close();
}
