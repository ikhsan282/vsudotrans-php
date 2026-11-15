<?php
/**
 * Self-check: invoice & pembayaran (tanpa HTTP).
 * Butuh DB dev. Jalankan: php -d mysqli.default_socket=... tests/invoice_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/invoice_service.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$c = db();
$inv = $c->query("SELECT id, total, company_id, invoice_no, booking_type, booking_id, document_number FROM invoices WHERE total > 0 ORDER BY id LIMIT 1")->fetch_assoc();
if (!$inv) { echo "skip: tidak ada invoice; buat booking dulu\n"; exit(0); }
$inv_id = (int) $inv['id'];
$cid = (int) $inv['company_id'];
$btOrig = (string) $inv['booking_type'];
$bidOrig = (int) $inv['booking_id'];
$tblOrig = $btOrig === 'travel' ? 'travel_ticket_bookings' : 'rental_car_bookings';

// Snapshot state asli INV# pertama agar DB dev tidak tercemar oleh run test
$origPays = $c->query("SELECT * FROM booking_cost_lines WHERE cost_type='payment' AND description='INV#$inv_id' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$origBkTp = (float) ($c->query("SELECT total_payment FROM $tblOrig WHERE id=$bidOrig")->fetch_row()[0] ?? 0);

try {
    // bersihkan pembayaran uji sebelumnya
    finance_unsync_cost_line_ids($cid, array_map(static fn($r) => (int) $r['id'], $origPays));
    $c->query("DELETE FROM booking_cost_lines WHERE cost_type='payment' AND description='INV#$inv_id'");

    // 1. bayar sebagian (default auto-sync ke Buku Kas/Bank)
    $r1 = invoice_add_payment($inv_id, $cid, 500000.0, null, 'uji sebagian', 'admin');
    ok((int) $r1['paid_total'] === 500000, 'bayar sebagian diterima (paid_total=500000)');
    $inv1 = invoice_find($inv_id);
    $st1 = invoice_pay_status($inv1);
    ok($st1 === 'partial', "status jadi partial (dapat: $st1)");
    ok(abs(invoice_paid_total($inv_id) - 500000.0) < 0.01, 'paid=500000');

    // Verifikasi transaksi kas masuk otomatis tersinkron
    $synced1 = (int) $c->query("SELECT COUNT(*) FROM cash_transactions ct JOIN booking_cost_lines bcl ON ct.source_type='booking_payment' AND ct.source_id=bcl.id WHERE bcl.description='INV#$inv_id' AND ct.direction='in'")->fetch_row()[0];
    ok($synced1 === 1, "pembayaran otomatis tersinkron ke cash_transactions ($synced1 baris)");

    // 2. tolak overpay (> sisa) — service melempar exception
    $thrown = false;
    try { invoice_add_payment($inv_id, $cid, (float) $inv['total'] + 1, null, 'overpay', 'admin'); }
    catch (RuntimeException $e) { $thrown = true; }
    ok($thrown, 'overpay ditolak');

    // 3. tolak <= 0
    $thrown2 = false;
    try { invoice_add_payment($inv_id, $cid, 0, null, '', 'admin'); }
    catch (InvalidArgumentException $e) { $thrown2 = true; }
    ok($thrown2, 'bayar 0 ditolak');

    // 4. lunasi sisa
    $sisa = (float) $inv['total'] - 500000.0;
    $r4 = invoice_add_payment($inv_id, $cid, $sisa, null, 'pelunasan', 'admin');
    ok((int) $r4['status'] === 0 || $r4['status'] === 'paid', 'pelunasan diterima');
    $inv2 = invoice_find($inv_id);
    $st2 = invoice_pay_status($inv2);
    ok($st2 === 'paid', "status jadi paid (dapat: $st2)");

    // 5. total_payment booking sinkron
    $bt = $inv2['booking_type'];
    $bid = (int) $inv2['booking_id'];
    $tbl = $bt === 'travel' ? 'travel_ticket_bookings' : 'rental_car_bookings';
    $tp = (float) $c->query("SELECT total_payment FROM $tbl WHERE id=$bid")->fetch_row()[0];
    ok(abs($tp - (float) $inv2['total']) < 0.01, "booking.total_payment sinkron ($tp)");
} finally {
    $testIds = array_column($c->query("SELECT id FROM booking_cost_lines WHERE cost_type='payment' AND description='INV#$inv_id'")->fetch_all(MYSQLI_ASSOC), 'id');
    finance_unsync_cost_line_ids($cid, $testIds);
    $c->query("DELETE FROM booking_cost_lines WHERE cost_type='payment' AND description='INV#$inv_id'");
    if ($origPays) {
        $insR = $c->prepare("INSERT INTO booking_cost_lines (id, company_id, booking_type, booking_id, cost_type, description, amount, payment_method_id, note, proof_blob_path, created_user, created_date) VALUES (?, ?, ?, ?, 'payment', ?, ?, ?, ?, ?, ?, ?)");
        foreach ($origPays as $op) {
            $opId  = (int) $op['id'];
            $opCid = (int) $op['company_id'];
            $opBt  = (string) $op['booking_type'];
            $opBid = (int) $op['booking_id'];
            $opD   = (string) $op['description'];
            $opAmt = (float) $op['amount'];
            $opPm  = $op['payment_method_id'] !== null && (int) $op['payment_method_id'] > 0 ? (int) $op['payment_method_id'] : null;
            $opNt  = $op['note'];
            $opPr  = $op['proof_blob_path'];
            $opUsr = $op['created_user'];
            $opDt  = $op['created_date'];
            $insR->bind_param('iisisdissss', $opId, $opCid, $opBt, $opBid, $opD, $opAmt, $opPm, $opNt, $opPr, $opUsr, $opDt);
            $insR->execute();
        }
        $insR->close();
        finance_sync_invoice_payments_to_cash($cid, $inv_id, (string) ($inv['invoice_no'] ?? ''), $btOrig, (string) ($inv['document_number'] ?? ''));
    }
    $c->query("UPDATE $tblOrig SET total_payment = $origBkTp WHERE id = $bidOrig");
}

// 6. RBAC invoices.view
$has_admin = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.id=1 AND p.name='invoices.view'")->num_rows;
ok($has_admin, 'Super Admin punya invoices.view');
$has_viewer = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.id=5 AND p.name='invoices.view'")->num_rows;
ok($has_viewer, 'Viewer punya invoices.view (read-only)');
$staff_pay = (bool) $c->query("SELECT 1 FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.id=4 AND p.name='invoices.manage'")->num_rows;
ok(!$staff_pay, 'Staff TIDAK punya invoices.manage');

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
