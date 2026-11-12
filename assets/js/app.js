// VSudoTrans — App JS (disamakan dengan Arya Green IPL, brand MTM)
// Tema key: vst_theme (sudah di-init inline di header.php sebelum render)

// ── Sidebar toggle (mobile) ────────────────────────────────────────────────
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const toggler  = document.getElementById('sidebarToggler');

function setSidebarOpen(open, restoreFocus = false) {
  if (!sidebar) return;
  sidebar.classList.toggle('show', open);
  overlay && overlay.classList.toggle('show', open);
  toggler && toggler.setAttribute('aria-expanded', String(open));
  toggler && toggler.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
  if (open) sidebar.querySelector('a.nav-link')?.focus();
  else if (restoreFocus && toggler && toggler.getClientRects().length) toggler.focus();
}
if (toggler && sidebar) toggler.addEventListener('click', () => {
  if (window.innerWidth >= 992) {
    const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('vst_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    toggler.setAttribute('aria-expanded', String(!collapsed));
    return;
  }
  const open = !sidebar.classList.contains('show');
  setSidebarOpen(open);
  if (open) sidebar.querySelector('a.nav-link')?.focus();
});
if (overlay) overlay.addEventListener('click', () => setSidebarOpen(false, true));
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && sidebar?.classList.contains('show')) setSidebarOpen(false, true);
});
if (sidebar) {
  sidebar.querySelectorAll('a.nav-link:not([data-bs-toggle])').forEach(a => {
    a.addEventListener('click', () => { if (window.innerWidth < 992) setSidebarOpen(false); });
  });
}
window.addEventListener('resize', () => { if (window.innerWidth >= 992) setSidebarOpen(false); });
document.addEventListener('keydown', e => {
  if (e.key !== 'Tab' || !sidebar?.classList.contains('show') || window.innerWidth >= 992) return;
  const items = [...sidebar.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)')]
    .filter(el => el.getClientRects().length);
  const first = items[0], last = items[items.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
});

// Keep collapsible navigation state available to assistive technology + Accordion behavior.
document.querySelectorAll('.sidebar [data-bs-toggle="collapse"]').forEach(toggle => {
  const target = document.querySelector(toggle.getAttribute('href'));
  if (!target) return;
  target.addEventListener('show.bs.collapse', () => {
    document.querySelectorAll('.sidebar .collapse.show').forEach(other => {
      if (other !== target && window.bootstrap?.Collapse) {
        bootstrap.Collapse.getOrCreateInstance(other, { toggle: false }).hide();
      }
    });
  });
  target.addEventListener('shown.bs.collapse', () => toggle.setAttribute('aria-expanded', 'true'));
  target.addEventListener('hidden.bs.collapse', () => toggle.setAttribute('aria-expanded', 'false'));
});

// ── Pertahankan posisi scroll sidebar saat navigasi halaman ────────────────
const navScroll = document.querySelector('.sidebar .nav-scroll');
if (navScroll) {
  const SCROLL_KEY = 'vst_sidebar_scroll';
  const savedPos = sessionStorage.getItem(SCROLL_KEY);
  if (savedPos !== null) {
    navScroll.scrollTop = parseInt(savedPos, 10);
  } else {
    // Jika posisi belum tersimpan, otomatis scroll ke menu aktif yang sedang terbuka
    const activeItem = navScroll.querySelector('.nav-link.active, .sidebar-sub.active');
    if (activeItem) {
      activeItem.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
  }

  // Simpan posisi scroll saat di-scroll
  navScroll.addEventListener('scroll', () => {
    sessionStorage.setItem(SCROLL_KEY, String(navScroll.scrollTop));
  }, { passive: true });

  // Simpan juga sebelum pengguna meninggalkan halaman / klik link
  window.addEventListener('beforeunload', () => {
    sessionStorage.setItem(SCROLL_KEY, String(navScroll.scrollTop));
  });
}

// Use a visible focus ring for keyboard users without changing pointer styling.
document.addEventListener('keydown', e => { if (e.key === 'Tab') document.body.classList.add('using-keyboard'); });
document.addEventListener('pointerdown', () => document.body.classList.remove('using-keyboard'));

// ── Auto-dismiss alerts ────────────────────────────────────────────────────
// Alert flash dari flash_html() sudah alert-dismissible; alert statis di
// halaman ditandai otomatis di sini supaya ikut hilang setelah 5 detik.
document.querySelectorAll('.alert.alert-success, .alert.alert-danger, .alert.alert-warning, .alert.alert-info').forEach(el => {
  if (!el.classList.contains('alert-dismissible')) {
    el.classList.add('alert-dismissible', 'fade', 'show');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-close';
    btn.setAttribute('data-bs-dismiss', 'alert');
    el.appendChild(btn);
  }
  setTimeout(() => {
    const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
    bsAlert && bsAlert.close();
  }, 5000);
});

// ── Confirm delete terpusat (ganti onclick="return confirm(...)") ──────────
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm || 'Yakin ingin menghapus data ini?')) {
      e.preventDefault();
    }
  });
});

// ── Preview uploaded image ─────────────────────────────────────────────────
const proofInput = document.getElementById('proof_file');
const proofPreview = document.getElementById('proofPreview');
if (proofInput && proofPreview) {
  proofInput.addEventListener('change', () => {
    const file = proofInput.files[0];
    if (!file) return;
    if (file.type.startsWith('image/')) {
      proofPreview.src = URL.createObjectURL(file);
      proofPreview.classList.remove('d-none');
    }
  });
}

// ── Dark mode toggle ──────────────────────────────────────────────────────
const THEME_KEY = 'vst_theme';
function applyTheme(theme) {
  document.documentElement.setAttribute('data-bs-theme', theme);
  document.querySelectorAll('.dark-toggle i').forEach(icon => {
    icon.classList.toggle('bi-moon-stars', theme === 'light');
    icon.classList.toggle('bi-sun', theme === 'dark');
  });
}
document.addEventListener('click', e => {
  const toggle = e.target.closest('.dark-toggle');
  if (!toggle) return;
  const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  localStorage.setItem(THEME_KEY, next);
  applyTheme(next);
});

// ── Rupiah input formatter & helpers ─────────────────────────────────────────
window.parseRupiah = function (val) {
  if (val === null || val === undefined || val === '') return 0;
  if (typeof val === 'number') return Math.round(val);
  var str = String(val).trim();
  if (/^\d+\.(0|00)$/.test(str)) {
    str = str.replace(/\.(0|00)$/, '');
  }
  var digits = str.replace(/[^0-9]/g, '');
  return digits ? parseInt(digits, 10) : 0;
};

window.formatRupiah = function (val) {
  if (val === null || val === undefined || val === '') return '';
  var num = window.parseRupiah(val);
  if (num === 0) {
    return String(val).replace(/[^0-9]/g, '') === '' ? '' : '0';
  }
  return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

window.attachRupiahInput = function (inputEl, hiddenEl, onChangeCallback) {
  if (!inputEl) return;
  inputEl.addEventListener('input', function () {
    var oldVal = this.value;
    var caretPos = this.selectionStart || 0;
    var digitsBeforeCaret = oldVal.slice(0, caretPos).replace(/[^0-9]/g, '').length;

    var clean = oldVal.replace(/[^0-9]/g, '');
    var num = parseInt(clean || '0', 10);
    if (hiddenEl) hiddenEl.value = String(num);

    var formatted = clean === '' ? '' : window.formatRupiah(num);
    this.value = formatted;

    if (this.setSelectionRange && formatted) {
      var newCaret = 0;
      var digitCount = 0;
      for (var i = 0; i < formatted.length; i++) {
        if (/[0-9]/.test(formatted[i])) {
          digitCount++;
        }
        if (digitCount === digitsBeforeCaret) {
          newCaret = i + 1;
          break;
        }
      }
      if (digitCount < digitsBeforeCaret) newCaret = formatted.length;
      try { this.setSelectionRange(newCaret, newCaret); } catch (e) {}
    }

    if (typeof onChangeCallback === 'function') onChangeCallback(num);
  });

  inputEl.addEventListener('blur', function () {
    if (this.value.trim() === '') {
      this.value = '0';
      if (hiddenEl) hiddenEl.value = '0';
      if (typeof onChangeCallback === 'function') onChangeCallback(0);
    }
  });
};

// ── Tom Select: aktifkan otomatis ke <select class="ts-select"> ────────────
if (window.TomSelect) {
  document.querySelectorAll('select.ts-select').forEach(el => {
    if (el.tomselect) return;
    new TomSelect(el, { allowEmptyOption: true, create: false,
      sortField: { field: 'text', direction: 'asc' } });
  });
}
