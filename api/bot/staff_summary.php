<?php
/**
 * GET /api/bot/staff_summary.php
 * Khusus nomor WhatsApp yang terdaftar sebagai Pengurus/Admin/Staff perusahaan aktif.
 * Menampilkan ringkasan booking hari ini, penerimaan pembayaran hari ini, invoice belum lunas,
 * penugasan driver aktif, dan saldo kas/bank perusahaan.
 */
require __DIR__ . '/_bootstrap.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$cid     = (int) $company['id'];

if (!$actor['is_staff']) {
    bot_error(403, 'STAFF_ONLY', 'Informasi rekapitulasi operasional hanya dapat diakses oleh Admin/Staff perusahaan.');
}

// 1. Booking Travel & Rental Hari Ini
$st = $db->prepare('SELECT COUNT(*), COALESCE(SUM(total_price),0) FROM travel_ticket_bookings WHERE company_id = ? AND date = CURDATE()');
$st->bind_param('i', $cid);
$st->execute();
[$trvCnt, $trvSum] = $st->get_result()->fetch_row();
$st->close();

$st = $db->prepare('SELECT COUNT(*), COALESCE(SUM(total_price),0) FROM rental_car_bookings WHERE company_id = ? AND date = CURDATE()');
$st->bind_param('i', $cid);
$st->execute();
[$rntCnt, $rntSum] = $st->get_result()->fetch_row();
$st->close();

// 2. Pembayaran Masuk Hari Ini (dari booking_cost_lines cost_type='payment')
$st = $db->prepare(
    "SELECT COUNT(*), COALESCE(SUM(amount),0)
       FROM booking_cost_lines
      WHERE company_id = ? AND cost_type = 'payment' AND DATE(created_date) = CURDATE()"
);
$st->bind_param('i', $cid);
$st->execute();
[$payCnt, $paySum] = $st->get_result()->fetch_row();
$st->close();

// 3. Invoice Belum Lunas
$st = $db->prepare(
    "SELECT i.id, i.total,
            COALESCE((SELECT SUM(bcl.amount) FROM booking_cost_lines bcl
                       WHERE bcl.cost_type = 'payment' AND bcl.description = CONCAT('INV#', i.id)), 0) AS paid
       FROM invoices i
      WHERE i.company_id = ? AND i.status = 'issued'"
);
$st->bind_param('i', $cid);
$st->execute();
$unpaidInvCnt = 0;
$unpaidInvSum = 0.0;
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $invRow) {
    $rem = max(0.0, (float) $invRow['total'] - (float) $invRow['paid']);
    if ($rem > 0.01) {
        $unpaidInvCnt++;
        $unpaidInvSum += $rem;
    }
}
$st->close();

// 4. Penugasan Driver Aktif
$st = $db->prepare('SELECT COUNT(*) FROM trip_assignments WHERE company_id = ? AND ended_at IS NULL');
$st->bind_param('i', $cid);
$st->execute();
$activeDispatches = (int) $st->get_result()->fetch_column();
$st->close();

// 5. Saldo Buku Kas & Bank
$st = $db->prepare('SELECT COALESCE(SUM(opening_balance),0) FROM cash_accounts WHERE company_id = ? AND is_active = 1');
$st->bind_param('i', $cid);
$st->execute();
$openingBal = (float) $st->get_result()->fetch_column();
$st->close();

$st = $db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)
       FROM cash_transactions WHERE company_id = ?"
);
$st->bind_param('i', $cid);
$st->execute();
$cashDelta = (float) $st->get_result()->fetch_column();
$st->close();

bot_reply(200, [
    'as_of'                     => date('Y-m-d H:i'),
    'company'                   => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'staff'                     => $actor['staff'],
    'today_travel_bookings'     => (int) $trvCnt,
    'today_rental_bookings'     => (int) $rntCnt,
    'today_bookings_amount'     => bot_money((float) $trvSum + (float) $rntSum),
    'today_payments_count'      => (int) $payCnt,
    'today_payments_amount'     => bot_money((float) $paySum),
    'unpaid_invoices_count'     => $unpaidInvCnt,
    'unpaid_invoices_amount'    => bot_money($unpaidInvSum),
    'active_driver_assignments' => $activeDispatches,
    'cash_book_balance'         => bot_money($openingBal + $cashDelta),
]);

