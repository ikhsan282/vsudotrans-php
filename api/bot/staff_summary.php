<?php
/**
 * GET /api/bot/staff_summary.php
 * Khusus nomor WhatsApp yang terdaftar sebagai Pengurus/Admin/Staff perusahaan aktif.
 * Menampilkan ringkasan operasional & keuangan hari ini selaras dengan modul Keuangan terbaru:
 * - Booking Travel & Rental aktif (mengecualikan status `cancelled`).
 * - Penerimaan pembayaran pelanggan hari ini + breakdown metode pembayaran (mengecualikan invoice `cancelled`).
 * - Invoice belum lunas (`issued` dan `partial`, mengecualikan `cancelled`).
 * - Biaya operasional hari ini (di luar `cost_type='payment'`) termasuk BBM Rental.
 * - Penugasan driver aktif & rincian saldo Buku Kas/Bank per akun (`KAS-TUNAI`, `BANK-UTAMA`, dst) + Arus Kas Nyata hari ini.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/finance_sync.php';

$ctx     = bot_boot(['GET']);
$db      = $ctx['db'];
$company = $ctx['company'];
$actor   = $ctx['actor'];
$cid     = (int) $company['id'];

if (!$actor['is_staff']) {
    bot_error(403, 'STAFF_ONLY', 'Informasi rekapitulasi operasional hanya dapat diakses oleh Admin/Staff perusahaan.');
}

finance_ensure_company_defaults($cid, 'hermes-bot');

// 1. Booking Travel & Rental Hari Ini (kecuali dibatalkan)
$bookingToday = static function (mysqli $db, int $cid, string $type, string $table): array {
    $sql = "SELECT COUNT(*), COALESCE(SUM(b.total_price), 0)
              FROM {$table} b
             WHERE b.company_id = ? AND b.date = CURDATE()
               AND COALESCE((SELECT h.to_status FROM booking_status_history h
                              WHERE h.booking_type = ? AND h.booking_id = b.id
                              ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'";
    $st = $db->prepare($sql);
    $st->bind_param('is', $cid, $type);
    $st->execute();
    [$cnt, $sum] = $st->get_result()->fetch_row();
    $st->close();
    return [(int) $cnt, (float) $sum];
};
[$trvCnt, $trvSum] = $bookingToday($db, $cid, 'travel', 'travel_ticket_bookings');
[$rntCnt, $rntSum] = $bookingToday($db, $cid, 'rental', 'rental_car_bookings');

// 2. Pembayaran Pelanggan Masuk Hari Ini (kecualikan invoice cancelled + rincian per metode bayar)
$st = $db->prepare(
    "SELECT COALESCE(pm.code, 'LAINNYA') AS pm_code,
            COALESCE(pm.name, 'Metode Lain / Belum Ditentukan') AS pm_name,
            COUNT(*) AS cnt,
            COALESCE(SUM(l.amount), 0) AS amt
       FROM booking_cost_lines l
       LEFT JOIN invoices i ON i.booking_type = l.booking_type AND i.booking_id = l.booking_id
       LEFT JOIN payment_methods pm ON pm.id = l.payment_method_id
      WHERE l.company_id = ?
        AND l.cost_type = 'payment'
        AND COALESCE(i.status, 'issued') <> 'cancelled'
        AND l.created_date >= CURDATE() AND l.created_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      GROUP BY pm_code, pm_name
      ORDER BY amt DESC, pm_code ASC"
);
$st->bind_param('i', $cid);
$st->execute();
$pmRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$payCnt = 0;
$paySum = 0.0;
$paymentsByMethod = [];
foreach ($pmRows as $pmr) {
    $c = (int) $pmr['cnt'];
    $a = (float) $pmr['amt'];
    $payCnt += $c;
    $paySum += $a;
    $paymentsByMethod[] = [
        'code'   => (string) $pmr['pm_code'],
        'name'   => (string) $pmr['pm_name'],
        'count'  => $c,
        'amount' => bot_money($a),
    ];
}

// 3. Invoice Belum Lunas (termasuk status 'issued' & 'partial', kecualikan 'cancelled')
$st = $db->prepare(
    "SELECT COUNT(*), COALESCE(SUM(GREATEST(i.total - COALESCE(bcl.paid, 0), 0)), 0)
       FROM invoices i
       LEFT JOIN (
           SELECT description, SUM(amount) AS paid
             FROM booking_cost_lines
            WHERE company_id = ? AND cost_type = 'payment'
            GROUP BY description
       ) bcl ON bcl.description = CONCAT('INV#', i.id)
      WHERE i.company_id = ? AND i.status <> 'cancelled'
        AND (i.total - COALESCE(bcl.paid, 0)) > 0.01"
);
$st->bind_param('ii', $cid, $cid);
$st->execute();
[$unpaidInvCnt, $unpaidInvSum] = $st->get_result()->fetch_row();
$st->close();
$unpaidInvCnt = (int) $unpaidInvCnt;
$unpaidInvSum = (float) $unpaidInvSum;

// 4. Biaya Operasional Hari Ini (non-payment + BBM Rental)
$st = $db->prepare(
    "SELECT COALESCE(SUM(amount), 0)
       FROM booking_cost_lines
      WHERE company_id = ? AND cost_type <> 'payment'
        AND created_date >= CURDATE() AND created_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
);
$st->bind_param('i', $cid);
$st->execute();
$costLinesToday = (float) $st->get_result()->fetch_column();
$st->close();

$st = $db->prepare(
    "SELECT COALESCE(SUM(r.bbm), 0)
       FROM rental_car_bookings r
      WHERE r.company_id = ? AND r.bbm > 0
        AND COALESCE(NULLIF(r.start_date, ''), r.date) = CURDATE()
        AND COALESCE((SELECT h.to_status FROM booking_status_history h
                       WHERE h.booking_type = 'rental' AND h.booking_id = r.id
                       ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'"
);
$st->bind_param('i', $cid);
$st->execute();
$rentalBbmToday = (float) $st->get_result()->fetch_column();
$st->close();
$opCostTotalToday = $costLinesToday + $rentalBbmToday;

// 5. Penugasan Driver Aktif
$st = $db->prepare('SELECT COUNT(*) FROM trip_assignments WHERE company_id = ? AND ended_at IS NULL');
$st->bind_param('i', $cid);
$st->execute();
$activeDispatches = (int) $st->get_result()->fetch_column();
$st->close();

// 6. Saldo Buku Kas & Bank per Akun Aktif (KAS-TUNAI, BANK-UTAMA, dll)
$st = $db->prepare(
    "SELECT a.id, a.code, a.name, a.type,
            (a.opening_balance + COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount WHEN t.direction = 'out' THEN -t.amount ELSE 0 END), 0)) AS balance
       FROM cash_accounts a
       LEFT JOIN cash_transactions t ON t.cash_account_id = a.id AND t.company_id = a.company_id
      WHERE a.company_id = ? AND a.is_active = 1
      GROUP BY a.id, a.code, a.name, a.type, a.opening_balance
      ORDER BY CASE a.type WHEN 'kas' THEN 0 ELSE 1 END, a.id ASC"
);
$st->bind_param('i', $cid);
$st->execute();
$accRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$cashAccounts = [];
$cashBookBalance = 0.0;
foreach ($accRows as $ar) {
    $bal = (float) $ar['balance'];
    $cashBookBalance += $bal;
    $cashAccounts[] = [
        'id'      => (int) $ar['id'],
        'code'    => (string) $ar['code'],
        'name'    => (string) $ar['name'],
        'type'    => (string) $ar['type'],
        'balance' => bot_money($bal),
    ];
}

// 7. Arus Kas Nyata Hari Ini dari `cash_transactions`
$st = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN direction = 'in' AND source_type = 'booking_payment' THEN amount ELSE 0 END), 0) AS in_booking,
        COALESCE(SUM(CASE WHEN direction = 'in' AND (source_type IS NULL OR source_type <> 'booking_payment') THEN amount ELSE 0 END), 0) AS in_other,
        COALESCE(SUM(CASE WHEN direction = 'out' AND source_type = 'payroll_period' THEN amount ELSE 0 END), 0) AS out_payroll,
        COALESCE(SUM(CASE WHEN direction = 'out' AND (source_type IS NULL OR source_type <> 'payroll_period') THEN amount ELSE 0 END), 0) AS out_other
       FROM cash_transactions
      WHERE company_id = ? AND date = CURDATE()"
);
$st->bind_param('i', $cid);
$st->execute();
$cfRow = $st->get_result()->fetch_assoc() ?: [];
$st->close();

$inB  = (float) ($cfRow['in_booking'] ?? 0);
$inO  = (float) ($cfRow['in_other'] ?? 0);
$outP = (float) ($cfRow['out_payroll'] ?? 0);
$outO = (float) ($cfRow['out_other'] ?? 0);
$inT  = $inB + $inO;
$outT = $outP + $outO;

bot_reply(200, [
    'as_of'                         => date('Y-m-d H:i'),
    'company'                       => ['id' => $cid, 'code' => $company['code'], 'name' => $company['name']],
    'staff'                         => $actor['staff'],
    'today_travel_bookings'         => $trvCnt,
    'today_rental_bookings'         => $rntCnt,
    'today_bookings_amount'         => bot_money($trvSum + $rntSum),
    'today_payments_count'          => $payCnt,
    'today_payments_amount'         => bot_money($paySum),
    'today_payments_by_method'      => $paymentsByMethod,
    'today_operational_cost_amount' => bot_money($opCostTotalToday),
    'today_rental_bbm_amount'       => bot_money($rentalBbmToday),
    'unpaid_invoices_count'         => $unpaidInvCnt,
    'unpaid_invoices_amount'        => bot_money($unpaidInvSum),
    'active_driver_assignments'     => $activeDispatches,
    'cash_book_balance'             => bot_money($cashBookBalance),
    'cash_accounts'                 => $cashAccounts,
    'today_cash_flow'               => [
        'in_booking'  => bot_money($inB),
        'in_other'    => bot_money($inO),
        'in_total'    => bot_money($inT),
        'out_payroll' => bot_money($outP),
        'out_other'   => bot_money($outO),
        'out_total'   => bot_money($outT),
        'net'         => bot_money($inT - $outT),
    ],
]);
