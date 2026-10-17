<?php
/** Layout: penutup wrapper + Bootstrap JS bundle + toggle sidebar/tema. */
?>
    </div><!-- /row -->
</div><!-- /content-wrapper -->
</div><!-- /wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle sidebar di layar kecil
(function () {
  var sb = document.getElementById('sidebar'),
      ov = document.getElementById('sidebarOverlay'),
      bt = document.getElementById('sidebarToggler');
  function close() { if (sb) sb.classList.remove('show'); if (ov) ov.classList.remove('show'); }
  if (bt) bt.addEventListener('click', function () {
    if (sb) sb.classList.toggle('show');
    if (ov) ov.classList.toggle('show');
  });
  if (ov) ov.addEventListener('click', close);
  // Tutup menu saat pindah halaman
  if (sb) sb.querySelectorAll('a.nav-link:not([data-bs-toggle])').forEach(function (a) {
    a.addEventListener('click', function () { if (window.innerWidth < 992) close(); });
  });
})();

// Toggle tema terang/gelap
(function () {
  var key = 'vst_theme';
  var btn = document.querySelector('.sidebar-theme-toggle');
  if (!btn) return;
  function icon() {
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var i = btn.querySelector('i');
    if (i) i.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
  }
  icon();
  btn.addEventListener('click', function () {
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var next = dark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem(key, next); } catch (e) {}
    icon();
  });
})();
</script>
</body>
</html>
