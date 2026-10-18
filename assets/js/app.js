// VSudoTrans — App JS (disamakan dengan Arya Green IPL, brand MTM)
// Tema key: vst_theme (sudah di-init inline di header.php sebelum render)

// ── Sidebar toggle (mobile) ────────────────────────────────────────────────
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const toggler  = document.getElementById('sidebarToggler');

if (toggler && sidebar) {
  toggler.addEventListener('click', () => {
    sidebar.classList.toggle('show');
    overlay && overlay.classList.toggle('show');
  });
}
if (overlay) {
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('show');
    overlay && overlay.classList.remove('show');
  });
}
if (sidebar) {
  sidebar.querySelectorAll('a.nav-link:not([data-bs-toggle])').forEach(a => {
    a.addEventListener('click', () => {
      if (window.innerWidth < 992) { sidebar.classList.remove('show'); overlay && overlay.classList.remove('show'); }
    });
  });
}

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

// ── Rupiah input formatter ─────────────────────────────────────────────────
// SENGAJA TIDAK DIPAKAI: input harga di VSudoTrans bertipe type="number" dan
// server membaca nilainya dengan (float) langsung. Format "1.000.000" akan
// dibaca PHP sebagai 1. Kalau nanti ingin formatter, ubah dulu input ke
// type="text" + inputmode="numeric" DAN parse server dengan
// str_replace(['.',','],['','.'], $v) seperti pola Arya Green IPL.
// ponytail: dilewati sampai kedua syarat itu dipenuhi.

// ── Tom Select: aktifkan otomatis ke <select class="ts-select"> ────────────
if (window.TomSelect) {
  document.querySelectorAll('select.ts-select').forEach(el => {
    if (el.tomselect) return;
    new TomSelect(el, { allowEmptyOption: true, create: false,
      sortField: { field: 'text', direction: 'asc' } });
  });
}
