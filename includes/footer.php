<?php
/** Layout: penutup wrapper + Bootstrap JS bundle + toggle sidebar/tema. */
?>
    </div><!-- /row -->
    <footer class="erp-statusbar">
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-globe2 text-primary"></i>
            <span>Url : <?= e($_SERVER['HTTP_HOST'] ?? 'vsudotrans.azurewebsites.net') ?></span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-person-fill text-warning"></i>
            <span>Akses Perusahaan : <?= e(function_exists('active_company_name') ? active_company_name() : '') ?></span>
        </div>
        <div class="d-flex align-items-center gap-1 ms-auto ms-md-0">
            <i class="bi bi-calendar-event text-danger"></i>
            <span>Tanggal : <span id="erpLiveClock"><?= date('d-M-Y H:i:s') ?></span></span>
        </div>
        <div class="d-flex align-items-center gap-1 ms-auto">
            <i class="bi bi-gear-fill text-primary"></i>
            <span>Versi : 1.0.1.19</span>
        </div>
    </footer>
</div><!-- /content-wrapper -->
</div><!-- /wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
<script>
  (function(){
    var el = document.getElementById('erpLiveClock');
    if (!el) return;
    function updateClock() {
      var d = new Date();
      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var dd = String(d.getDate()).padStart(2, '0');
      var mmm = months[d.getMonth()];
      var yyyy = d.getFullYear();
      var hh = String(d.getHours()).padStart(2, '0');
      var ii = String(d.getMinutes()).padStart(2, '0');
      var ss = String(d.getSeconds()).padStart(2, '0');
      el.textContent = dd + '-' + mmm + '-' + yyyy + ' ' + hh + ':' + ii + ':' + ss;
    }
    setInterval(updateClock, 1000);
  })();
</script>
</body>
</html>
