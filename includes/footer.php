<?php
/** Layout: penutup wrapper + Bootstrap JS bundle + toggle sidebar/tema. */
?>
    </div><!-- /row -->
</div><!-- /content-wrapper -->
</div><!-- /wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/app.js') ?>"></script>
</body>
</html>
