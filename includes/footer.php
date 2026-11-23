<?php
/** Layout: penutup wrapper + Bootstrap JS bundle + toggle sidebar/tema. */
?>
    </div><!-- /row -->
</div><!-- /content-wrapper -->
</div><!-- /wrapper -->
<?php require __DIR__ . '/ai_assistant_widget.php'; ?>
<!-- Global Quick Search Modal (Ctrl+K) -->
<div class="modal fade" id="globalSearchModal" tabindex="-1" aria-labelledby="globalSearchLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable gs-dialog">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
            <div class="gs-input-wrap d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                <i class="bi bi-search text-primary fs-5" aria-hidden="true"></i>
                <label for="globalSearchInput" id="globalSearchLabel" class="visually-hidden">Pencarian Cepat Global</label>
                <input type="text" id="globalSearchInput" class="form-control form-control-sm border-0 shadow-none bg-transparent gs-input"
                       placeholder="Ketik resi, nama penumpang, nopol armada, sopir, atau menu..." autocomplete="off">
                <span id="globalSearchSpinner" class="spinner-border spinner-border-sm text-primary d-none" role="status" aria-hidden="true"></span>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-2 gs-body" id="globalSearchResults">
                <div class="gs-empty small text-body-secondary text-center py-4">
                    Ketik minimal 2 karakter untuk mencari booking Travel, Rental, Penumpang, Armada, atau Karyawan.
                </div>
            </div>
            <div class="modal-footer justify-content-between py-2 px-3 border-top small text-body-secondary gs-footer">
                <span><kbd>&uarr;</kbd> <kbd>&darr;</kbd> Pilih &middot; <kbd>Enter</kbd> Buka &middot; <kbd>Esc</kbd> Tutup</span>
                <span id="globalSearchMeta">Shortcut: <kbd>Ctrl+K</kbd></span>
            </div>
        </div>
    </div>
</div>
<script>
window.__VST_APP_URL = <?= json_encode(APP_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
window.__VST_CAN_DISPATCH = <?= can('dispatch.view') ? 'true' : 'false' ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/app.js') ?>"></script>
</body>
</html>
