<?php
/**
 * Invoice & pembayaran service.
 *
 * Invoice dibuat otomatis saat booking (lihat booking_service::invoice_issue).
 * Modul ini menangani pembacaan, perubahan, dan pencatatan pembayaran.
 *
 * Pembayaran tidak punya tabel sendiri: setiap pembayaran dicatat sebagai baris
 * di `booking_cost_lines` berkode 'payment' (amount negatif tidak dipakai), lalu
 * invoices.total_payment & booking.total_payment dihitung ulang dari situ.
 * ponytail: cukup satu sumber kebenaran, tanpa tabel payments; pecah ke tabel
 * sendiri bila perlu multi-akun/history transfer detail.
 */

require_once __DIR__ . '/booking_service.php';
require_once __DIR__ . '/finance_sync.php';

/** Definisi status pembayaran dari total vs subtotal+tax. */
function invoice_pay_status(array $inv): string
{
    $total = (float) $inv['total'];
    $paid  = (float) ($inv['paid_total'] ?? 0);
    if ($inv['status'] === 'cancelled') {
        return 'cancelled';
    }
    if ($paid <= 0) {
        return 'unpaid';
    }
    return $paid + 0.001 >= $total ? 'paid' : 'partial';
}

function invoice_pay_badge(string $s): string
{
    $map = ['unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', 'cancelled' => 'secondary'];
    $label = ['unpaid' => 'Belum Bayar', 'partial' => 'Sebagian', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . e($label[$s] ?? $s) . '</span>';
}

/** Total pembayaran tercatat untuk satu invoice. */
function invoice_paid_total(int $invoice_id): float
{
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM booking_cost_lines
        WHERE cost_type = 'payment' AND description = ?");
    $desc = 'INV#' . $invoice_id;
    $st->bind_param('s', $desc);
    $st->execute();
    $v = (float) $st->get_result()->fetch_row()[0];
    $st->close();
    return $v;
}

/** Ambil invoice + info booking. Mengembalikan null bila tak ada. */
function invoice_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inv) {
        return null;
    }
    $inv['paid_total'] = invoice_paid_total($id);
    $inv['pay_status'] = invoice_pay_status($inv);
    return $inv;
}

/**
 * Catat pembayaran. Satu transaksi: baris biaya + sinkron total_payment
 * invoice & booking. $amount > 0. Menolak bila melebihi sisa tagihan
 * kecuali $allow_over.
 *
 * @return array{invoice_id:int, amount:float, paid_total:float, status:string}
 */
function invoice_add_payment(int $invoice_id, int $company_id, float $amount, ?int $method_id, ?string $note, ?string $user, bool $allow_over = false, ?string $proof_blob_path = null, ?int $cash_account_id = null): array
{
    if ($amount <= 0) {
        throw new InvalidArgumentException('Nominal pembayaran harus lebih dari 0.');
    }
    $db = db();
    $db->begin_transaction();
    try {
        // Kunci invoice + cek tenant.
        $st = $db->prepare('SELECT id, invoice_no, document_number, booking_type, booking_id, company_id, total, status FROM invoices WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $invoice_id);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$inv) {
            throw new RuntimeException('Invoice tidak ditemukan.');
        }
        $real_cid = (int) $inv['company_id'];
        if ($real_cid !== $company_id && !is_super_admin()) {
            throw new RuntimeException('Invoice bukan milik perusahaan aktif.');
        }
        if ($inv['status'] === 'cancelled') {
            throw new RuntimeException('Invoice sudah dibatalkan — tidak bisa menerima pembayaran.');
        }

        $paid = invoice_paid_total($invoice_id);
        $remaining = round((float) $inv['total'] - $paid, 2);
        if (!$allow_over && $amount > $remaining + 0.001) {
            throw new RuntimeException('Nominal melebihi sisa tagihan (' . rupiah($remaining) . ').');
        }

        // Catat sebagai baris biaya (cost_type 'payment'), deskripsi = penanda invoice.
        $desc = 'INV#' . $invoice_id;
        $label = $note !== null && $note !== '' ? $note : 'Pembayaran';
        $st = $db->prepare("INSERT INTO booking_cost_lines
            (company_id, booking_type, booking_id, cost_type, description, amount, payment_method_id, note, proof_blob_path, created_user)
            VALUES (?, ?, ?, 'payment', ?, ?, ?, ?, ?, ?)");
        $bt = $inv['booking_type'];
        $bid = (int) $inv['booking_id'];
        $midParam = ($method_id !== null && $method_id > 0) ? $method_id : null;
        $st->bind_param('isisdisss', $real_cid, $bt, $bid, $desc, $amount, $midParam, $label, $proof_blob_path, $user);
        $st->execute();
        $newCostLineId = (int) $st->insert_id;
        $st->close();

        // Sinkronisasi otomatis ke Buku Kas/Bank (kecuali user eksplisit memilih tidak mencatat dengan $cash_account_id < 0).
        if ($cash_account_id === null || $cash_account_id >= 0) {
            finance_sync_invoice_payments_to_cash(
                $real_cid,
                $invoice_id,
                (string) ($inv['invoice_no'] ?? ''),
                $bt,
                (string) ($inv['document_number'] ?? ''),
                $cash_account_id !== null && $cash_account_id > 0 ? [$newCostLineId => $cash_account_id] : [],
                null
            );
        }

        $new_paid = round($paid + $amount, 2);
        $st = $db->prepare('UPDATE invoices SET modified_user = ?, modified_date = NOW() WHERE id = ?');
        $st->bind_param('si', $user, $invoice_id);
        $st->execute();
        $st->close();

        // Sinkron kolom total_payment di tabel booking (denormalisasi cepat).
        $tbl = booking_table($bt);
        $st = $db->prepare("UPDATE {$tbl} SET total_payment = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
        $st->bind_param('dsii', $new_paid, $user, $bid, $real_cid);
        $st->execute();
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    $inv['paid_total'] = $new_paid;
    return [
        'invoice_id' => $invoice_id,
        'amount' => $amount,
        'paid_total' => $new_paid,
        'status' => invoice_pay_status($inv),
    ];
}

/** Daftar pembayaran tercatat untuk satu invoice. */
function invoice_payments(int $invoice_id): array
{
    $desc = 'INV#' . $invoice_id;
    $st = db()->prepare("SELECT bcl.id, bcl.amount, bcl.description, bcl.payment_method_id,
            pm.name AS pm_name, pm.code AS pm_code, bcl.note, bcl.proof_blob_path,
            bcl.created_user, bcl.created_date
        FROM booking_cost_lines bcl
        LEFT JOIN payment_methods pm ON pm.id = bcl.payment_method_id
        WHERE bcl.cost_type = 'payment' AND bcl.description = ?
        ORDER BY bcl.id ASC");
    $st->bind_param('s', $desc);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as &$r) {
        $note = trim((string) ($r['note'] ?? ''));
        $isAutoQris = (($r['pm_code'] ?? '') === 'QRIS-AUTO' || stripos((string) ($r['created_user'] ?? ''), 'qris') !== false);
        $payType   = $isAutoQris ? 'Pelunasan' : 'Down Payment (DP)';
        $payMethod = $isAutoQris ? 'QRIS' : 'Transfer';
        if (strpos($note, '|') !== false) {
            [$pt, $pm] = array_map('trim', explode('|', $note, 2));
            if ($pt !== '') $payType = $pt;
            if (!$isAutoQris && $pm !== '') $payMethod = $pm;
        } elseif (stripos($note, 'QRIS') !== false || $isAutoQris) {
            $payType   = 'Pelunasan';
            $payMethod = 'QRIS';
        } elseif ($note !== '') {
            $payType = $note;
            if (!empty($r['pm_name'])) {
                $payMethod = (string) $r['pm_name'];
            }
        }
        $r['pay_type']     = $payType;
        $r['pay_method']   = $payMethod;
        $r['pay_date']     = substr((string) ($r['created_date'] ?? date('Y-m-d')), 0, 10);
        $r['is_auto_qris'] = $isAutoQris;
    }
    unset($r);
    return $rows;
}

/**
 * Sinkronisasi baris-baris pembayaran sewa pada Tab Pembayaran Sewa (Rental):
 * - Mempertahankan baris pembayaran otomatis QRIS (is_auto_qris)
 * - Menyimpan baris pembayaran manual (beserta upload bukti lampiran bila ada)
 * - Menghitung ulang total_payment pada rental_car_bookings & invoices
 *
 * @param string $type 'rental' atau 'travel'
 * @param array<int, array{id?:int, pay_type:string, pay_method:string, pay_date:string, amount:float, proof_blob_path?:?string}> $pay_rows
 */
function booking_payments_sync(string $type, int $booking_id, int $invoice_id, int $company_id, array $pay_rows, ?string $user): float
{
    $type   = ($type === 'travel') ? 'travel' : 'rental';
    $bTable = ($type === 'travel') ? 'travel_ticket_bookings' : 'rental_car_bookings';
    $db     = db();
    $desc   = 'INV#' . $invoice_id;

    // Ambil baris eksisting agar proof_blob_path lama & baris QRIS otomatis tidak hilang
    $existing = [];
    $qrisTotal = 0.0;
    foreach (invoice_payments($invoice_id) as $ex) {
        $existing[(int) $ex['id']] = $ex;
        if (!empty($ex['is_auto_qris'])) {
            $qrisTotal += max(0.0, (float) ($ex['amount'] ?? 0));
        }
    }

    // Validasi sebelum menghapus baris lama agar data tidak terhapus bila ditolak
    $manualTotal = 0.0;
    foreach ($pay_rows as $r) {
        $rowId = (int) ($r['id'] ?? 0);
        if ($rowId > 0 && !empty($existing[$rowId]['is_auto_qris'])) {
            continue;
        }
        $rawAmt = (float) ($r['amount'] ?? 0);
        if ($rawAmt < -0.001) {
            throw new InvalidArgumentException('Nominal pembayaran tidak boleh negatif.');
        }
        if ($rawAmt <= 0) {
            continue;
        }
        $manualTotal += $rawAmt;
    }

    $stInv = $db->prepare('SELECT invoice_no, document_number, total, status FROM invoices WHERE id = ? AND company_id = ? LIMIT 1');
    $stInv->bind_param('ii', $invoice_id, $company_id);
    $stInv->execute();
    $invRow = $stInv->get_result()->fetch_assoc();
    $stInv->close();
    if ($invRow) {
        if (($invRow['status'] ?? '') === 'cancelled' && $manualTotal > 0.001) {
            throw new RuntimeException('Invoice sudah dibatalkan — tidak bisa menerima pembayaran.');
        }
        $invTotal  = max(0.0, (float) $invRow['total']);
        $grandPaid = round($qrisTotal + $manualTotal, 2);
        if ($grandPaid > $invTotal + 0.001) {
            $remainingAllowed = max(0.0, round($invTotal - $qrisTotal, 2));
            throw new InvalidArgumentException(
                'Total pembayaran (' . rupiah($grandPaid) . ') tidak boleh melebihi total tagihan (' . rupiah($invTotal) . ').'
                . ($qrisTotal > 0 ? ' Maksimal pembayaran manual tersisa ' . rupiah($remainingAllowed) . '.' : '')
            );
        }
    }

    // Petakan akun kas lama per baris manual agar pilihan rekening manual dari Invoice detail tidak tertimpa saat booking disimpan ulang
    finance_ensure_schema();
    $manualOldIds = [];
    foreach ($existing as $exId => $exRow) {
        if (empty($exRow['is_auto_qris'])) {
            $manualOldIds[] = (int) $exId;
        }
    }
    $oldAccountMap = [];
    if ($manualOldIds) {
        $ph = implode(',', array_fill(0, count($manualOldIds), '?'));
        $tStr = 'is' . str_repeat('i', count($manualOldIds));
        $srcType = 'booking_payment';
        $stAccMap = $db->prepare("SELECT source_id, cash_account_id FROM cash_transactions WHERE company_id = ? AND source_type = ? AND source_id IN ({$ph})");
        $stAccMap->bind_param($tStr, $company_id, $srcType, ...$manualOldIds);
        $stAccMap->execute();
        foreach ($stAccMap->get_result()->fetch_all(MYSQLI_ASSOC) as $am) {
            $oldAccountMap[(int) $am['source_id']] = (int) $am['cash_account_id'];
        }
        $stAccMap->close();
        finance_unsync_cost_line_ids($company_id, $manualOldIds);
    }

    // Hapus baris manual lama (pertahankan baris yang berasal dari webhook QRIS otomatis)
    $del = $db->prepare("DELETE FROM booking_cost_lines
        WHERE booking_type = ? AND booking_id = ? AND company_id = ?
          AND cost_type = 'payment' AND description = ?
          AND (created_user IS NULL OR created_user NOT LIKE '%qris%')");
    $del->bind_param('siis', $type, $booking_id, $company_id, $desc);
    $del->execute();
    $del->close();

    $ins = $db->prepare("INSERT INTO booking_cost_lines
        (company_id, booking_type, booking_id, cost_type, description, amount, payment_method_id, note, proof_blob_path, created_user, created_date)
        VALUES (?, ?, ?, 'payment', ?, ?, ?, ?, ?, ?, ?)");

    $lineAccountOverrides = [];
    foreach ($pay_rows as $r) {
        $rowId = (int) ($r['id'] ?? 0);
        if ($rowId > 0 && !empty($existing[$rowId]['is_auto_qris'])) {
            // Baris QRIS otomatis sudah dipertahankan di DB
            continue;
        }
        $amt = max(0.0, (float) ($r['amount'] ?? 0));
        if ($amt <= 0) {
            continue;
        }
        $pType   = trim((string) ($r['pay_type'] ?? 'Down Payment (DP)')) ?: 'Down Payment (DP)';
        $pMethod = trim((string) ($r['pay_method'] ?? 'Transfer')) ?: 'Transfer';
        $pDate   = trim((string) ($r['pay_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pDate)) {
            $pDate = date('Y-m-d');
        }
        $createdDate = $pDate . ' ' . date('H:i:s');
        $noteStr     = $pType . ' | ' . $pMethod;
        $proofPath   = $r['proof_blob_path'] ?? ($rowId > 0 ? ($existing[$rowId]['proof_blob_path'] ?? null) : null);
        $pmIdParam   = finance_resolve_payment_method_id($company_id, $pMethod);

        $ins->bind_param('isisdissss', $company_id, $type, $booking_id, $desc, $amt, $pmIdParam, $noteStr, $proofPath, $user, $createdDate);
        $ins->execute();
        $newBclId = (int) $ins->insert_id;
        if ($rowId > 0 && isset($oldAccountMap[$rowId])) {
            $lineAccountOverrides[$newBclId] = $oldAccountMap[$rowId];
        }
    }
    $ins->close();

    finance_sync_invoice_payments_to_cash(
        $company_id,
        $invoice_id,
        (string) ($invRow['invoice_no'] ?? ''),
        $type,
        (string) ($invRow['document_number'] ?? ''),
        $lineAccountOverrides
    );

    $total_paid = invoice_paid_total($invoice_id);
    $upB = $db->prepare("UPDATE {$bTable} SET total_payment = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
    $upB->bind_param('dsii', $total_paid, $user, $booking_id, $company_id);
    $upB->execute();
    $upB->close();

    $upI = $db->prepare('UPDATE invoices SET modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
    $upI->bind_param('sii', $user, $invoice_id, $company_id);
    $upI->execute();
    $upI->close();

    return $total_paid;
}

/**
 * Wrapper backward-compatible untuk sinkronisasi pembayaran booking rental.
 *
 * @param array<int, array{id?:int, pay_type:string, pay_method:string, pay_date:string, amount:float, proof_blob_path?:?string}> $pay_rows
 */
function booking_rental_payments_sync(int $booking_id, int $invoice_id, int $company_id, array $pay_rows, ?string $user): float
{
    return booking_payments_sync('rental', $booking_id, $invoice_id, $company_id, $pay_rows, $user);
}
