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
function invoice_add_payment(int $invoice_id, int $company_id, float $amount, ?int $method_id, ?string $note, ?string $user, bool $allow_over = false): array
{
    if ($amount <= 0) {
        throw new InvalidArgumentException('Nominal pembayaran harus lebih dari 0.');
    }
    $db = db();
    $db->begin_transaction();
    try {
        // Kunci invoice + cek tenant.
        $st = $db->prepare('SELECT id, booking_type, booking_id, company_id, total, status FROM invoices WHERE id = ? FOR UPDATE');
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
            (company_id, booking_type, booking_id, cost_type, description, amount, payment_method_id, note, created_user)
            VALUES (?, ?, ?, 'payment', ?, ?, ?, ?, ?)");
        $bt = $inv['booking_type'];
        $bid = (int) $inv['booking_id'];
        $mid = $method_id ?? 0;
        $st->bind_param('isisdiss', $company_id, $bt, $bid, $desc, $amount, $mid, $label, $user);
        $st->execute();
        $st->close();

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
    $st = db()->prepare("SELECT id, amount, description, note, created_user, created_date
        FROM booking_cost_lines WHERE cost_type = 'payment' AND description = ?
        ORDER BY id DESC");
    $st->bind_param('s', $desc);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}
