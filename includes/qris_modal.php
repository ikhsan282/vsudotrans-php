<?php
/**
 * Shared Modal QRIS Dinamis Otomatis (dipakai oleh pages/rental/bookings/detail.php & pages/travel/bookings/detail.php).
 * Variabel diharapkan: $canManage (bool), $qrisChannels (array), $qrisRemaining (float).
 */
if (!empty($canManage) && !empty($qrisChannels)): ?>
<div class="modal fade" id="qrisAutoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-qr-code-scan text-success me-2"></i>Pembayaran QRIS Otomatis</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <!-- Step 1: Form Input Nominal & Tipe -->
            <div id="qrisStepForm">
                <div class="modal-body">
                    <div id="qrisCreateErr" class="alert alert-danger py-2 small d-none"></div>
                    <div id="qrisUnsavedPriceWarn" class="alert alert-warning py-2 small d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i>Perubahan harga di Tab Detail belum disimpan. Batas maksimal mengikuti total invoice tersimpan.
                    </div>

                    <div class="p-2 px-3 bg-light border rounded small mb-3 d-flex justify-content-between align-items-center">
                        <span class="text-muted">Sisa Tagihan Invoice:</span>
                        <strong class="text-danger fs-6" id="qrisFormSisaText">Rp <?= format_nominal($qrisRemaining) ?></strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Tipe Pembayaran <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="qrisPayType">
                            <option value="Down Payment (DP)">Down Payment (DP)</option>
                            <option value="Pelunasan">Pelunasan</option>
                            <option value="Pembayaran Penuh (Full)">Pembayaran Penuh (Full)</option>
                        </select>
                    </div>

                    <?php if (count($qrisChannels) > 1): ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Channel QRIS</label>
                        <select class="form-select form-select-sm" id="qrisChannelProvider">
                            <?php foreach ($qrisChannels as $ch): ?>
                                <option value="<?= e($ch['provider']) ?>"><?= e($ch['name']) ?> (<?= e(strtoupper((string) $ch['provider'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="qrisChannelProvider" value="<?= e((string) ($qrisChannels[0]['provider'] ?? '')) ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Nominal Tagihan QRIS (Rp) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Rp</span>
                            <input type="text" inputmode="numeric" class="form-control fw-bold text-end" id="qrisAmountDisplay" value="<?= format_nominal($qrisRemaining) ?>" autocomplete="off">
                        </div>
                        <input type="hidden" id="qrisAmountValue" value="<?= (int) round($qrisRemaining) ?>">
                    </div>

                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 qris-preset-btn" data-qris-preset="full">Sisa Tagihan</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 qris-preset-btn" data-qris-preset="dp50">DP 50%</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 qris-preset-btn" data-qris-preset="dp30">DP 30%</button>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success btn-sm px-3" id="btnGenerateQris">
                        <i class="bi bi-qr-code me-1"></i>Buat QRIS Dinamis
                    </button>
                </div>
            </div>

            <!-- Step 2: Tampil QR Code & Auto-Polling -->
            <div id="qrisStepShow" class="d-none">
                <div class="modal-body text-center">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-dark" id="qrisShowChannelBadge">QRIS</span>
                        <span class="badge bg-warning-subtle text-warning-emphasis border" id="qrisShowStatusBadge">
                            <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;" role="status"></span>Menunggu Pembayaran
                        </span>
                    </div>
                    <div class="text-muted small mb-2">Order ID: <code id="qrisShowOrderId">-</code></div>

                    <div class="p-2 bg-white border rounded-3 d-inline-block mb-3 shadow-sm">
                        <img id="qrisShowImg" src="" alt="QRIS Dinamis" width="230" height="230" class="img-fluid">
                    </div>

                    <div class="border rounded bg-light p-2 px-3 text-start small mb-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Tipe Pembayaran</span>
                            <strong id="qrisShowPayType">-</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Nominal Tagihan</span>
                            <span id="qrisShowBill">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 d-none" id="qrisShowFeeRow">
                            <span class="text-muted">Biaya Layanan</span>
                            <span id="qrisShowFee">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1 d-none" id="qrisShowUniqueRow">
                            <span class="text-muted">Kode Unik Verifikasi</span>
                            <span class="text-primary fw-semibold" id="qrisShowUnique">+Rp 0</span>
                        </div>
                        <hr class="my-1">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Total Scan / Transfer QRIS</span>
                            <strong class="fs-5 text-primary" id="qrisShowTotal">Rp 0</strong>
                        </div>
                    </div>

                    <div class="small text-muted mb-0">
                        Batas waktu: <strong id="qrisShowCountdown">-</strong> · Baris pembayaran akan otomatis bertambah begitu dana masuk.
                    </div>
                </div>
                <div class="modal-footer py-2 justify-content-between">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnQrisBackToForm">
                        <i class="bi bi-arrow-left me-1"></i>Ubah Nominal
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopyQrisLink" title="Salin link pembayaran untuk dikirim ke pelanggan">
                            <i class="bi bi-link-45deg me-1"></i>Salin Link
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="btnCheckQrisNow">
                            <i class="bi bi-arrow-clockwise me-1"></i>Cek Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
