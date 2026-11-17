<?php
/**
 * Panel bersama Detail & Ketentuan Invoice di dalam Tab Pembayaran Detail Booking
 * (dipakai Travel Detail lewat booking_payment_tab.php & Rental Detail).
 *
 * Variabel opsional dari pemanggil:
 * - array|null $invCard
 * - string     $invCardFormId
 * - bool       $invCardCanManage
 */
$invCardData = is_array($invCard ?? null) ? $invCard : null;
if (!$invCardData || empty($invCardData['id'])) {
    return;
}

$invCardId      = (int) $invCardData['id'];
$invCardNo      = trim((string) ($invCardData['invoice_no'] ?? ('#' . $invCardId)));
$invCardStatus  = (string) ($invCardData['pay_status'] ?? invoice_pay_status($invCardData));
$invCardBadge   = invoice_pay_badge($invCardStatus);
$invCardIssued  = (string) ($invCardData['issued_at'] ?? '');
$invCardSub     = (float) ($invCardData['subtotal'] ?? 0);
$invCardTaxPct  = (float) ($invCardData['tax_percent'] ?? 0);
$invCardTaxAmt  = (float) ($invCardData['tax_amount'] ?? 0);
$invCardTotal   = (float) ($invCardData['total'] ?? 0);
$invCardPaid    = (float) ($invCardData['paid_total'] ?? 0);
$invCardRem     = max(0.0, round($invCardTotal - $invCardPaid, 2));
$invCardNote    = (string) ($invCardData['note'] ?? '');
$invCardFormId  = (string) ($invCardFormId ?? $formId ?? 'travelDetailForm');
$invCardManage  = !empty($invCardCanManage);
?>
<div class="rnt-panel">
    <div class="rnt-panel-header">
        <span><i class="bi bi-receipt me-1 text-primary"></i>Detail &amp; Ketentuan Invoice</span>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <a class="rnt-header-action" target="_blank" href="<?= e(APP_URL) ?>/pages/finance/invoices/print.php?id=<?= $invCardId ?>&amp;print=1">
                <i class="bi bi-printer me-1"></i>Cetak Invoice
            </a>
            <a class="rnt-header-action text-danger" target="_blank" href="<?= e(APP_URL) ?>/pages/finance/invoices/print.php?id=<?= $invCardId ?>&amp;format=pdf">
                <i class="bi bi-filetype-pdf me-1"></i>PDF Invoice
            </a>
        </div>
    </div>
    <div class="rnt-panel-body">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="rnt-row">
                    <div class="rnt-label">No. Invoice</div>
                    <div class="rnt-field d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-bold font-monospace"><?= e($invCardNo) ?></span>
                        <?= $invCardBadge ?>
                    </div>
                </div>
                <div class="rnt-row">
                    <div class="rnt-label">Tanggal Terbit</div>
                    <div class="rnt-field">
                        <?= $invCardIssued !== '' ? e(tanggal_indo(substr($invCardIssued, 0, 10)) . (strlen($invCardIssued) >= 16 ? ' ' . substr($invCardIssued, 11, 5) : '')) : '—' ?>
                    </div>
                </div>
                <div class="rnt-row">
                    <div class="rnt-label">Rincian Tagihan</div>
                    <div class="rnt-field small">
                        Subtotal <strong><?= e(rupiah($invCardSub)) ?></strong>
                        <?php if ($invCardTaxPct > 0 || abs($invCardTaxAmt) > 0.001): ?>
                            · Pajak (<?= e((string) $invCardTaxPct) ?>%) <strong><?= e(rupiah($invCardTaxAmt)) ?></strong>
                        <?php endif; ?>
                        · Total <strong><?= e(rupiah($invCardTotal)) ?></strong>
                        <div class="text-muted mt-1">
                            Dibayar <?= e(rupiah($invCardPaid)) ?> ·
                            Sisa <strong class="<?= $invCardRem > 0 ? 'text-danger' : 'text-success' ?>"><?= e(rupiah($invCardRem)) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="rnt-row rnt-row-top">
                    <label class="rnt-label pt-1" for="invoiceNoteInput">Ketentuan / Catatan Invoice</label>
                    <div class="rnt-field">
                        <textarea class="form-control form-control-sm" id="invoiceNoteInput" name="invoice_note" form="<?= e($invCardFormId) ?>" rows="2" maxlength="2000" <?= $invCardManage ? '' : 'readonly' ?> placeholder="mis. Pelunasan H-1 sebelum keberangkatan; pembatalan &lt; 24 jam dikenakan potongan DP 50%."><?= e($invCardNote) ?></textarea>
                        <div class="form-text small text-muted mb-0">
                            Ketentuan standar: Tagihan diterbitkan otomatis per pemesanan. Pembayaran DP/Pelunasan otomatis tercatat ke Buku Kas dan ikut tercetak di PDF/Cetak Invoice.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
