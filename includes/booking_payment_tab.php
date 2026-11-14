<?php
/**
 * Partial bersama Tab 3 ERP: Pembayaran Travel / Pembayaran Sewa.
 * Styling, header, kolom Lampiran/Tanggal/Nominal, dan footer disamakan persis dengan Rental.
 *
 * Variabel input dari pemanggil:
 * - string $formId         ID elemen <form> utama
 * - bool   $canManage      Hak edit pengguna saat ini
 * - array  $payments       Daftar baris pembayaran (termasuk info QRIS)
 * - float  $totalPrice     Total harga booking
 * - bool   $isDetailMode   true untuk halaman Detail, false untuk Form Create
 * - string $tabTitle       (opsional) Judul panel ('Pembayaran Travel' / 'Pembayaran Sewa')
 * - string $activeTab      (opsional) Tab aktif saat ini
 * - array  $qrisChannels   (opsional) Daftar channel QRIS aktif
 * - float  $invoiceTotal   (opsional, mode detail) Total tagihan invoice
 * - float  $paidTotal      (opsional, mode detail) Total terbayar di DB
 * - string $payBadgeHtml   (opsional, mode detail) Badge status lunas/dp/belum
 * - string $bookingStatus  (opsional, mode detail) Status booking saat ini
 * - bool   $hasPendingQris (opsional, mode detail) Ada transaksi QRIS menunggu pembayaran
 */

$formId         = (string) ($formId ?? 'formTravelBooking');
$canManage      = !empty($canManage);
$isDetailMode   = !empty($isDetailMode);
$tabTitle       = (string) ($tabTitle ?? 'Pembayaran Travel');
$activeTab      = (string) ($activeTab ?? 'detail');
$qrisChannels   = is_array($qrisChannels ?? null) ? $qrisChannels : [];
$totalPrice     = max(0.0, (float) ($totalPrice ?? 0));
$invoiceTotal   = isset($invoiceTotal) ? max(0.0, (float) $invoiceTotal) : $totalPrice;
$paidTotalVal   = isset($paidTotal) ? max(0.0, (float) $paidTotal) : 0.0;
$bookingStatus  = (string) ($bookingStatus ?? 'draft');
$hasPendingQris = !empty($hasPendingQris);
$payBadgeHtml   = (string) ($payBadgeHtml ?? '');

if ($isDetailMode) {
    $payRowsIn = is_array($payments ?? null) ? array_values($payments) : [];
} else {
    $payRowsIn = is_array($payments ?? null) && $payments ? array_values($payments) : [
        ['id' => 0, 'pay_type' => 'Down Payment (DP)', 'pay_method' => 'Transfer', 'pay_date' => date('Y-m-d'), 'amount' => 0, 'proof_blob_path' => '', 'is_auto_qris' => false],
    ];
}

$paySubtotal = 0.0;
foreach ($payRowsIn as $pr) {
    $paySubtotal += max(0.0, (float) ($pr['amount'] ?? 0));
}
$topPaidValue  = ($isDetailMode && isset($paidTotal)) ? $paidTotalVal : $paySubtotal;
$remainingVal  = max(0.0, $invoiceTotal - $paidTotalVal);

$payTypeOpts   = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)', 'Tambahan'];
$payMethodOpts = ['Transfer', 'Tunai', 'QRIS', 'EDC / Kartu'];
$isPayActive   = ($activeTab === 'payment');
?>
<div class="tab-pane fade<?= $isPayActive ? ' show active' : '' ?>" id="tab-payment" role="tabpanel" aria-labelledby="tab-payment-btn">
    <?php if ($canManage): ?>
        <input type="hidden" name="pay_sync_present" value="1" form="<?= e($formId) ?>">
    <?php endif; ?>

    <!-- Field Atas: Total Pembayaran (+ Info Tagihan & QRIS di mode Detail) -->
    <div class="rnt-panel">
        <div class="rnt-panel-body">
            <div class="rnt-row">
                <div class="rnt-label">Total Pembayaran</div>
                <div class="rnt-field d-flex flex-wrap align-items-center gap-3">
                    <input type="text" class="form-control form-control-sm fw-bold bg-light" id="payTotalDisplay"
                        value="<?= e(format_nominal($topPaidValue)) ?>" readonly<?= $isDetailMode ? ' style="max-width:240px;"' : '' ?>>
                    <?php if ($isDetailMode): ?>
                        <span class="small text-muted">
                            Sisa Tagihan:
                            <strong id="qrisRemainingDisplay" class="<?= $remainingVal > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $remainingVal > 0 ? ('Rp ' . e(format_nominal($remainingVal))) : 'Lunas (Rp 0)' ?>
                            </strong>
                        </span>
                        <?php if ($payBadgeHtml !== ''): ?>
                            <span><?= $payBadgeHtml ?></span>
                        <?php endif; ?>
                        <span id="qrisLiveSyncBadge" class="badge bg-info-subtle text-info-emphasis border <?= $hasPendingQris ? '' : 'd-none' ?>">
                            <i class="bi bi-arrow-repeat me-1"></i>Pantau QRIS Otomatis Aktif
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isDetailMode): ?>
        <div id="qrisAutoNewRowBanner" class="alert alert-success alert-dismissible fade show py-2 px-3 small d-none align-items-center justify-content-between mb-3" role="alert">
            <div><i class="bi bi-check-circle-fill me-1"></i><span id="qrisAutoNewRowMsg">Pembayaran QRIS berhasil diterima dan ditambahkan ke tabel.</span></div>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- Grid Pembayaran (Lampiran | Tipe Pembayaran | Metode Pembayaran | Tanggal | Nominal) -->
    <div class="rnt-panel mb-0">
        <div class="rnt-panel-header">
            <span><?= e($tabTitle) ?></span>
            <?php if ($canManage): ?>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <?php if ($isDetailMode): ?>
                        <?php if (!empty($qrisChannels)): ?>
                            <button type="button" class="rnt-header-action" id="btnPayQrisAuto" <?= ($bookingStatus === 'cancelled' || $remainingVal <= 0) ? 'disabled' : '' ?>>
                                <i class="bi bi-qr-code-scan me-1"></i>Bayar via QRIS Otomatis
                            </button>
                        <?php else: ?>
                            <button type="button" class="rnt-header-action text-muted" disabled title="Belum ada channel QRIS aktif di Payment Gateway Setup Perusahaan">
                                <i class="bi bi-qr-code me-1"></i>QRIS Belum Aktif
                            </button>
                        <?php endif; ?>
                    <?php elseif (!empty($qrisChannels)): ?>
                        <button type="submit" name="redirect_qris" value="1" form="<?= e($formId) ?>" class="rnt-header-action" title="Simpan pemesanan terlebih dahulu lalu buka pembayaran QRIS otomatis">
                            <i class="bi bi-qr-code-scan me-1"></i>Simpan &amp; Bayar QRIS
                        </button>
                    <?php endif; ?>
                    <button type="button" class="rnt-header-action" id="btnAddPayRow">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Pembayaran Manual
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div id="payOverpayAlert" class="alert alert-danger py-2 px-3 small d-none m-2 mb-0" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i><span id="payOverpayMsg"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle" id="payTable" style="font-size:.84rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px;" class="text-center">#</th>
                        <th style="width:220px;">Lampiran</th>
                        <th style="width:220px;">Tipe Pembayaran</th>
                        <th style="width:200px;">Metode Pembayaran</th>
                        <th style="width:170px;">Tanggal</th>
                        <th style="width:180px;" class="text-end">Nominal</th>
                        <?php if ($canManage): ?><th style="width:42px;" class="text-center">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="payTableBody">
                    <?php foreach ($payRowsIn as $idx => $pr):
                        $pId       = (int) ($pr['id'] ?? 0);
                        $pType     = (string) ($pr['pay_type'] ?? 'Down Payment (DP)');
                        $pMethod   = (string) ($pr['pay_method'] ?? 'Transfer');
                        $pDate     = (string) ($pr['pay_date'] ?? date('Y-m-d'));
                        $pAmt      = max(0.0, (float) ($pr['amount'] ?? 0));
                        $isQris    = !empty($pr['is_auto_qris']);
                        $proofBlob = trim((string) ($pr['proof_blob_path'] ?? ''));
                        $proofUrl  = $proofBlob !== '' ? resolve_asset_url($proofBlob) : '';

                        $rowTypeOpts   = in_array($pType, $payTypeOpts, true) ? $payTypeOpts : array_merge($payTypeOpts, [$pType]);
                        $rowMethodOpts = in_array($pMethod, $payMethodOpts, true) ? $payMethodOpts : array_merge($payMethodOpts, [$pMethod]);
                    ?>
                        <tr class="pay-row<?= $isQris ? ' table-success' : '' ?>"<?= $pId > 0 ? ' data-payment-id="' . $pId . '"' : '' ?>>
                            <td class="text-center text-muted pay-row-num"><?= $idx + 1 ?></td>
                            <td>
                                <input type="hidden" name="pay_id[]" form="<?= e($formId) ?>" value="<?= $pId ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($isQris): ?>
                                        <span class="badge bg-success-subtle text-success border"><i class="bi bi-qr-code me-1"></i>Verified QRIS</span>
                                        <?php if ($proofUrl !== ''): ?>
                                            <a href="<?= e($proofUrl) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-paperclip me-1"></i>Bukti</a>
                                        <?php endif; ?>
                                    <?php elseif ($proofUrl !== ''): ?>
                                        <a href="<?= e($proofUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 pay-file-label">
                                            <i class="bi bi-image me-1"></i>Lihat Bukti
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small pay-file-label">No image data</span>
                                    <?php endif; ?>
                                    <?php if ($canManage && !$isQris): ?>
                                        <label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Unggah/ganti bukti pembayaran">
                                            <i class="bi bi-paperclip"></i>
                                            <input type="file" name="pay_proof[]" form="<?= e($formId) ?>" class="d-none pay-file-input" accept="image/*,application/pdf">
                                        </label>
                                    <?php elseif ($canManage): ?>
                                        <input type="file" name="pay_proof[]" form="<?= e($formId) ?>" class="d-none" tabindex="-1">
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($isQris): ?>
                                    <select class="form-select form-select-sm pay-type-select" disabled>
                                        <?php foreach ($rowTypeOpts as $o): ?>
                                            <option value="<?= e($o) ?>"<?= $o === $pType ? ' selected' : '' ?>><?= e($o) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="pay_type[]" form="<?= e($formId) ?>" value="<?= e($pType) ?>">
                                <?php else: ?>
                                    <select class="form-select form-select-sm pay-type-select" name="pay_type[]" form="<?= e($formId) ?>" <?= $canManage ? '' : 'disabled' ?>>
                                        <?php foreach ($rowTypeOpts as $o): ?>
                                            <option value="<?= e($o) ?>"<?= $o === $pType ? ' selected' : '' ?>><?= e($o) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isQris): ?>
                                    <select class="form-select form-select-sm pay-method-select" disabled>
                                        <?php foreach ($rowMethodOpts as $m): ?>
                                            <option value="<?= e($m) ?>"<?= $m === $pMethod ? ' selected' : '' ?>><?= e($m) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="pay_method[]" form="<?= e($formId) ?>" value="<?= e($pMethod) ?>">
                                <?php else: ?>
                                    <select class="form-select form-select-sm pay-method-select" name="pay_method[]" form="<?= e($formId) ?>" <?= $canManage ? '' : 'disabled' ?>>
                                        <?php foreach ($rowMethodOpts as $m): ?>
                                            <option value="<?= e($m) ?>"<?= $m === $pMethod ? ' selected' : '' ?>><?= e($m) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isQris): ?>
                                    <input type="text" class="form-control form-control-sm bg-light" value="<?= e($pDate !== '' ? date('d-M-Y', strtotime($pDate)) : date('d-M-Y')) ?>" disabled>
                                    <input type="hidden" name="pay_date[]" form="<?= e($formId) ?>" value="<?= e($pDate) ?>">
                                <?php else: ?>
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date pay-date-input" name="pay_date[]" form="<?= e($formId) ?>" value="<?= e($pDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? '' : 'disabled' ?>>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="<?= e(format_nominal($pAmt)) ?>" placeholder="0" <?= ($canManage && !$isQris) ? '' : 'readonly' ?>>
                                <input type="hidden" name="pay_amount[]" class="pay-amt-value" form="<?= e($formId) ?>" value="<?= (int) round($pAmt) ?>">
                            </td>
                            <?php if ($canManage): ?>
                                <td class="text-center">
                                    <?php if ($isQris): ?>
                                        <i class="bi bi-lock-fill text-muted small" title="Pembayaran terverifikasi otomatis oleh QRIS"></i>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="5" class="py-2">
                            <div class="d-flex align-items-center gap-2 small text-muted">
                                <span id="payRecordCount">Record <?= count($payRowsIn) ? 1 : 0 ?> of <?= count($payRowsIn) ?></span>
                                <?php if ($canManage): ?>
                                    <div class="btn-group btn-group-sm ms-2">
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnPayFooterAdd" title="Tambah Baris">+</button>
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnPayFooterRemove" title="Hapus Baris Terakhir">&minus;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-2">
                            <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="paySubtotalDisplay" value="<?= e(format_nominal($paySubtotal)) ?>" readonly>
                        </td>
                        <?php if ($canManage): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
