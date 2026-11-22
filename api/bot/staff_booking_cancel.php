<?php
/**
 * POST/DELETE /api/bot/staff_booking_cancel.php
 * Pembatalan aman (`mode: cancel`) atau penghapusan permanen (`mode: delete`, wajib `confirm: true`
 * dan khusus pesanan `draft`/`cancelled` yang belum memiliki pembayaran tercatat).
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/bot_staff_booking.php';

$ctx     = bot_boot(['POST', 'DELETE']);
$db      = $ctx['db'];
$company = $ctx['company'];
$body    = $ctx['body'];
$cid     = (int) $company['id'];

$ref      = trim((string) ($body['document_number'] ?? ($body['invoice_no'] ?? ($body['booking_id'] ?? ($_GET['document_number'] ?? '')))));
$hintType = !empty($body['booking_type']) ? strtolower(trim((string) $body['booking_type'])) : null;
if ($ref === '') {
    bot_error(400, 'MISSING_REFERENCE', 'Sertakan document_number, booking_id, atau invoice_no pesanan.');
}

$bk = bot_staff_find_booking($db, $cid, $ref, $hintType);
if (!$bk) {
    bot_error(404, 'BOOKING_NOT_FOUND', "Pesanan '{$ref}' tidak ditemukan di perusahaan {$company['code']}.");
}

$bType     = (string) $bk['booking_type'];
$bid       = (int) $bk['id'];
$docNo     = (string) $bk['document_number'];
$staffCtx  = bot_staff_require($ctx, [$bType === 'rental' ? 'rental.manage' : 'travel.manage']);
$auditUser = $staffCtx['audit_user'];

$mode   = strtolower(trim((string) ($body['mode'] ?? 'cancel')));
$reason = trim((string) ($body['reason'] ?? 'Dibatalkan admin via Grup WA Hermes'));
if (!in_array($mode, ['cancel', 'delete'], true)) {
    bot_error(400, 'INVALID_MODE', "mode harus 'cancel' atau 'delete'.");
}

$paidAmt   = (float) ($bk['invoice']['paid_total'] ?? 0);
$payStatus = (string) ($bk['invoice']['pay_status'] ?? 'unpaid');
$bkStatus  = (string) ($bk['booking_status'] ?? 'draft');
$paxName   = (string) ($bk['passengers'][0]['name'] ?? '-');

if ($mode === 'delete') {
    if (empty($body['confirm'])) {
        bot_error(
            400,
            'CONFIRMATION_REQUIRED',
            "Konfirmasi penghapusan permanen diperlukan untuk resi #{$docNo} a.n. {$paxName}.",
            [
                'document_number' => $docNo,
                'passenger_name'  => $paxName,
                'booking_status'  => $bkStatus,
                'paid_amount'     => bot_money($paidAmt),
            ]
        );
    }
    if (!in_array($bkStatus, ['draft', 'cancelled'], true)) {
        bot_error(
            409,
            'CANNOT_DELETE_BOOKING',
            "Pesanan berstatus '" . bot_staff_status_label($bkStatus) . "' tidak bisa dihapus permanen. Gunakan mode 'cancel'.",
            ['booking_status' => $bkStatus]
        );
    }
    if ($paidAmt > 0.001) {
        bot_error(
            409,
            'CANNOT_DELETE_BOOKING',
            'Pesanan sudah memiliki pembayaran (' . rupiah($paidAmt) . ') sehingga tidak boleh dihapus permanen agar pembukuan kas tetap akurat. Gunakan mode \'cancel\'.',
            ['paid_amount' => bot_money($paidAmt)]
        );
    }

    $chkPg = $db->prepare("SELECT COUNT(*) FROM payment_gateway_orders WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND status = 'paid'");
    $chkPg->bind_param('sii', $bType, $bid, $cid);
    $chkPg->execute();
    $hasPaidQris = (int) $chkPg->get_result()->fetch_row()[0] > 0;
    $chkPg->close();
    if ($hasPaidQris) {
        bot_error(409, 'CANNOT_DELETE_BOOKING', 'Pesanan memiliki transaksi QRIS berstatus PAID sehingga tidak dapat dihapus permanen.');
    }

    $deletedDoc = booking_delete($bType, $bid, $cid);
    bot_staff_audit(
        $db,
        $cid,
        $staffCtx['staff'],
        'bot.staff_booking_delete',
        "Hapus permanen {$bType} #{$deletedDoc} a.n. {$paxName}" . ($reason !== '' ? " ({$reason})" : '')
    );

    bot_reply(200, [
        'action_taken'    => 'deleted',
        'booking_type'    => $bType,
        'document_number' => $deletedDoc,
        'passenger_name'  => $paxName,
        'wa_text'         => "*Pesanan Dihapus Permanen*\nResi: `#{$deletedDoc}`\nNama: {$paxName}\nAlasan: " . ($reason ?: '-'),
    ]);
}

// Mode: cancel
if ($payStatus === 'paid' && empty($body['force_cancel_paid'])) {
    bot_error(
        409,
        'INVOICE_ALREADY_PAID',
        "Pesanan #{$docNo} a.n. {$paxName} sudah berstatus LUNAS (" . rupiah($paidAmt) . '). Kirim konfirmasi eksplisit (force_cancel_paid: true) jika tetap ingin membatalkan.',
        ['document_number' => $docNo, 'paid_amount' => bot_money($paidAmt)]
    );
}
if ($payStatus === 'paid' && !$staffCtx['is_admin'] && !$staffCtx['is_super']) {
    bot_error(403, 'ADMIN_ONLY_PAID_CANCEL', 'Pembatalan pesanan yang sudah LUNAS hanya diizinkan untuk Admin Perusahaan / Super Admin.');
}

if ($bkStatus !== 'cancelled') {
    booking_change_status($bType, $bid, $cid, 'cancelled', $reason, $auditUser);
    bot_staff_audit(
        $db,
        $cid,
        $staffCtx['staff'],
        'bot.staff_booking_cancel',
        "Batalkan {$bType} #{$docNo} a.n. {$paxName} ({$reason})"
    );
}

$updated = bot_staff_find_booking($db, $cid, $docNo, $bType);
bot_reply(200, [
    'action_taken'    => 'cancelled',
    'booking_type'    => $bType,
    'document_number' => $docNo,
    'booking'         => $updated,
    'wa_text'         => bot_staff_format_booking_wa('Pesanan Dibatalkan', $updated, $company),
]);
