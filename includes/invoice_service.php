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
        $st = $db->prepare('SELECT id, invoice_no, booking_type, booking_id, company_id, total, status FROM invoices WHERE id = ? FOR UPDATE');
        $st->bind_param('i', $invoice_id);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$inv) {
            throw new RuntimeException('Invoice tidak ditemukan.');
        }
        if ((int) $inv['company_id'] !== $company_id && !is_super_admin()) {
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
        $mid = $method_id ?? 0;
        $st->bind_param('isisdisss', $company_id, $bt, $bid, $desc, $amount, $mid, $label, $proof_blob_path, $user);
        $st->execute();
        $st->close();

        // Jika akun kas/bank dipilih, catat otomatis ke cash_transactions (transaksi masuk)
        if ($cash_account_id !== null && $cash_account_id > 0) {
            $c_st = $db->prepare('SELECT id FROM cash_accounts WHERE id = ? AND company_id = ? AND is_active = 1');
            $c_st->bind_param('ii', $cash_account_id, $company_id);
            $c_st->execute();
            $c_acc = $c_st->get_result()->fetch_assoc();
            $c_st->close();
            if ($c_acc) {
                $c_date = date('Y-m-d');
                $c_dir  = 'in';
                $c_cat  = 'Pendapatan Invoice';
                $c_desc = 'Pembayaran Invoice ' . ($inv['invoice_no'] ?? ('#' . $invoice_id)) . ($note !== null && $note !== '' ? ' - ' . $note : '');
                $c_meth = $method_id > 0 ? $method_id : null;
                $t_st = $db->prepare('INSERT INTO cash_transactions
                    (company_id, cash_account_id, date, direction, amount, category, description, payment_method_id, created_user, created_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $t_st->bind_param('iissdssis', $company_id, $cash_account_id, $c_date, $c_dir, $amount, $c_cat, $c_desc, $c_meth, $user);
                $t_st->execute();
                $t_st->close();
            }
        }

        $new_paid = round($paid + $amount, 2);
        $st = $db->prepare('UPDATE invoices SET modified_user = ?, modified_date = NOW() WHERE id = ?');
        $st->bind_param('si', $user, $invoice_id);
        $st->execute();
        $st->close();

        // Sinkron kolom total_payment di tabel booking (denormalisasi cepat).
        $tbl = booking_table($bt);
        $st = $db->prepare("UPDATE {$tbl} SET total_payment = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
        $st->bind_param('dsii', $new_paid, $user, $bid, $company_id);
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
 * @param array<int, array{id?:int, pay_type:string, pay_method:string, pay_date:string, amount:float, proof_blob_path?:?string}> $pay_rows
 */
function booking_rental_payments_sync(int $booking_id, int $invoice_id, int $company_id, array $pay_rows, ?string $user): float
{
    $db   = db();
    $desc = 'INV#' . $invoice_id;

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

    $stInv = $db->prepare('SELECT total, status FROM invoices WHERE id = ? AND company_id = ? LIMIT 1');
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

    // Hapus baris manual lama (pertahankan baris yang berasal dari webhook QRIS otomatis)
    $del = $db->prepare("DELETE FROM booking_cost_lines
        WHERE booking_type = 'rental' AND booking_id = ? AND company_id = ?
          AND cost_type = 'payment' AND description = ?
          AND (created_user IS NULL OR created_user NOT LIKE '%qris%')");
    $del->bind_param('iis', $booking_id, $company_id, $desc);
    $del->execute();
    $del->close();

    $ins = $db->prepare("INSERT INTO booking_cost_lines
        (company_id, booking_type, booking_id, cost_type, description, amount, note, proof_blob_path, created_user, created_date)
        VALUES (?, 'rental', ?, 'payment', ?, ?, ?, ?, ?, ?)");

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

        $ins->bind_param('iisdssss', $company_id, $booking_id, $desc, $amt, $noteStr, $proofPath, $user, $createdDate);
        $ins->execute();
    }
    $ins->close();

    $total_paid = invoice_paid_total($invoice_id);
    $upB = $db->prepare('UPDATE rental_car_bookings SET total_payment = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
    $upB->bind_param('dsii', $total_paid, $user, $booking_id, $company_id);
    $upB->execute();
    $upB->close();

    $upI = $db->prepare('UPDATE invoices SET modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
    $upI->bind_param('sii', $user, $invoice_id, $company_id);
    $upI->execute();
    $upI->close();

    return $total_paid;
}
