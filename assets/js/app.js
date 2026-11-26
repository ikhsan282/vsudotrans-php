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
  if (el.classList.contains('d-none') || el.hasAttribute('data-no-autodismiss')) return;
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
  const mode = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-bs-theme', mode);
  document.querySelectorAll('.dark-toggle').forEach(btn => {
    const label = mode === 'dark' ? 'Beralih ke mode terang' : 'Beralih ke mode gelap';
    btn.setAttribute('title', label);
    btn.setAttribute('aria-label', label);
    btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
    const icon = btn.querySelector('i');
    if (icon) {
      icon.classList.toggle('bi-moon-stars', mode === 'light');
      icon.classList.toggle('bi-sun', mode === 'dark');
    }
  });
  try {
    window.dispatchEvent(new CustomEvent('vst:themechange', { detail: { theme: mode } }));
  } catch (err) {}
}
applyTheme(document.documentElement.getAttribute('data-bs-theme') || 'light');
document.addEventListener('click', e => {
  const toggle = e.target.closest('.dark-toggle');
  if (!toggle) return;
  const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(THEME_KEY, next); } catch (err) {}
  applyTheme(next);
});

// ── Rupiah input formatter & helpers ─────────────────────────────────────────
window.RUPIAH_MAX_DIGITS = 15; // Maks Rp 999.999.999.999.999 (aman presisi JS & DECIMAL(18,2))

window.parseRupiah = function (val) {
  if (val === null || val === undefined || val === '') return 0;
  if (typeof val === 'number') {
    return Number.isFinite(val) ? Math.max(0, Math.round(val)) : 0;
  }
  var str = String(val).trim();
  if (!str || str.charAt(0) === '-') return 0;
  if (/^\d+(?:\.\d+)?e[+-]?\d+$/i.test(str)) {
    var expNum = Number(str);
    return Number.isFinite(expNum) ? Math.max(0, Math.round(expNum)) : 0;
  }
  if (/^\d+\.\d{1,2}$/.test(str)) {
    var decNum = parseFloat(str);
    return Number.isFinite(decNum) ? Math.max(0, Math.round(decNum)) : 0;
  }
  var digits = str.replace(/[^0-9]/g, '');
  if (!digits) return 0;
  var parsed = Number(digits);
  return Number.isFinite(parsed) ? Math.max(0, Math.round(parsed)) : 0;
};

window.formatRupiah = function (val) {
  if (val === null || val === undefined || val === '') return '';
  var num = window.parseRupiah(val);
  if (num === 0) {
    return String(val).replace(/[^0-9]/g, '') === '' ? '' : '0';
  }
  if (typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function') {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(num);
  }
  return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

window.attachRupiahInput = function (inputEl, hiddenEl, onChangeCallback) {
  if (!inputEl) return;
  var maxDigits = window.RUPIAH_MAX_DIGITS || 15;
  inputEl.addEventListener('input', function () {
    var oldVal = this.value;
    var caretPos = this.selectionStart || 0;
    var digitsBeforeCaret = oldVal.slice(0, caretPos).replace(/[^0-9]/g, '').length;

    var clean = oldVal.replace(/[^0-9]/g, '');
    if (clean.length > maxDigits) {
      clean = clean.slice(0, maxDigits);
      if (digitsBeforeCaret > maxDigits) digitsBeforeCaret = maxDigits;
    }
    var num = clean === '' ? 0 : (Number(clean) || 0);
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

// ── Tom Select v2.3.1: Inisialisasi Standar & Remote Search (Skala Jutaan Data) ──
window._collectSelectMeta = function (el) {
  const metaMap = {};
  if (!el) return metaMap;
  Array.from(el.options || []).forEach(opt => {
    const val = String(opt.value || '');
    if (!val) return;
    const d = opt.dataset || {};
    metaMap[val] = {
      value: val,
      id: val,
      text: opt.textContent || '',
      name: opt.textContent || '',
      category: d.category || '',
      country_id: d.country || d.countryId || '',
      country_name: d.countryName || '',
      province_id: d.province || d.provinceId || '',
      province_name: d.provinceName || '',
      city_id: d.city || d.cityId || '',
      city_name: d.cityName || '',
      plate: d.plate || '',
      brand: d.brand || '',
      model: d.model || '',
      color: d.color || '',
      seat: d.seat ? parseInt(d.seat, 10) || 0 : 0,
      category_name: d.categoryName || '',
      code: d.code || '',
      phone: d.phone || '',
      job_title: d.org || d.jobTitle || '',
      job_position: d.pos || d.jobPosition || ''
    };
  });
  return metaMap;
};

window.initTsSelect = function (el, overrides) {
  if (!window.TomSelect || !el) return null;
  if (el.tomselect) return el.tomselect;

  const initialMeta = window._collectSelectMeta(el);
  const remoteUrl = el.dataset.tsUrl || '';
  const opts = {
    allowEmptyOption: true,
    create: false,
    maxOptions: 200,
    plugins: el.multiple ? ['remove_button'] : [],
    searchField: ['text', 'code', 'phone', 'plate', 'brand', 'model', 'color'],
    sortField: remoteUrl
      ? [{ field: '$score' }, { field: 'text', direction: 'asc' }]
      : { field: 'text', direction: 'asc' },
    valueField: 'value',
    labelField: 'text'
  };
  if (el.dataset.tsDropdownParent) {
    opts.dropdownParent = el.dataset.tsDropdownParent;
  } else if (
    el.closest &&
    el.closest('.table-responsive, .erp-window-card, .erp-filter-bar, .rutes-filter-bar') &&
    !el.closest('.modal')
  ) {
    opts.dropdownParent = 'body';
  }

  if (remoteUrl) {
    opts.loadThrottle = 250;
    opts.shouldLoad = function (query) {
      return query.trim().length >= 1;
    };
    opts.render = {
      loading: function (_data, _escape) {
        return '<div class="vst-ts-loading-skel" role="status" aria-label="Memuat pilihan…">'
          + '<div class="vst-ts-loading-row"><span class="vst-skel vst-skel-line" style="width:62%"></span><span class="vst-skel vst-skel-pill"></span></div>'
          + '<div class="vst-ts-loading-row"><span class="vst-skel vst-skel-line" style="width:76%"></span><span class="vst-skel vst-skel-pill" style="width:52px"></span></div>'
          + '<div class="vst-ts-loading-row"><span class="vst-skel vst-skel-line" style="width:54%"></span><span class="vst-skel vst-skel-pill" style="width:38px"></span></div>'
          + '</div>';
      }
    };
    opts.load = function (query, callback) {
      const parentSel = el.dataset.tsParent ? document.querySelector(el.dataset.tsParent) : null;
      const paramName = el.dataset.tsParam || '';
      const requireParent = el.dataset.tsRequireParent === '1';
      let parentVal = '';
      if (parentSel) {
        parentVal = parentSel.tomselect ? String(parentSel.tomselect.getValue() || '') : String(parentSel.value || '');
      }
      if (requireParent && !parentVal) {
        callback([]);
        return;
      }

      try {
        const u = new URL(remoteUrl, window.location.origin);
        u.searchParams.set('q', query.trim());
        u.searchParams.set('limit', el.dataset.tsLimit || '30');
        if (el.dataset.tsCompany) u.searchParams.set('company_id', el.dataset.tsCompany);
        if (el.dataset.tsWithCode) u.searchParams.set('with_code', el.dataset.tsWithCode);
        if (el.dataset.tsWithProvince) u.searchParams.set('with_province', el.dataset.tsWithProvince);
        if (el.dataset.tsWithCity) u.searchParams.set('with_city', el.dataset.tsWithCity);
        if (paramName && parentVal) u.searchParams.set(paramName, parentVal);

        fetch(u.toString())
          .then(r => (r.ok ? r.json() : []))
          .then(data => {
            const list = Array.isArray(data) ? data.map(it => ({
              ...it,
              value: String(it.value != null ? it.value : (it.id != null ? it.id : '')),
              text: String(it.text != null ? it.text : (it.name != null ? it.name : ''))
            })) : [];
            callback(list);
          })
          .catch(() => callback([]));
      } catch (e) {
        callback([]);
      }
    };
  }

  const mergedOpts = Object.assign({}, opts, overrides || {});
  if (opts.render && overrides && overrides.render) {
    mergedOpts.render = Object.assign({}, opts.render, overrides.render);
  }
  const ts = new TomSelect(el, mergedOpts);
  Object.keys(initialMeta).forEach(k => {
    if (ts.options[k]) {
      Object.assign(ts.options[k], initialMeta[k]);
    }
  });

  if (el.multiple && ts.control) {
    // Gunakan delegasi capture-phase di ts.control agar tombol hapus (.remove) tetap bekerja
    // meski child DOM di dalam .item diganti ulang (mis. oleh ekstensi 3CX yang membungkus nomor telepon).
    ts.control.addEventListener('mousedown', function (e) {
      const rm = e.target && e.target.closest ? e.target.closest('.remove') : null;
      if (!rm || !ts.control.contains(rm)) return;
      e.preventDefault();
      e.stopPropagation();
    }, true);

    ts.control.addEventListener('click', function (e) {
      const rm = e.target && e.target.closest ? e.target.closest('.remove') : null;
      if (!rm || !ts.control.contains(rm)) return;
      e.preventDefault();
      e.stopPropagation();
      if (ts.isLocked || ts.isDisabled) return;
      const itemEl = rm.closest('[data-ts-item], .item');
      if (itemEl && ts.shouldDelete([itemEl], e)) {
        ts.removeItem(itemEl);
        ts.refreshOptions(false);
        ts.inputState();
      }
    }, true);
  }

  const initVal = ts.getValue();
  el._tsLastVal = Array.isArray(initVal) ? initVal.join(',') : String(initVal == null ? '' : initVal);
  return ts;
};

window.setTsValueSilent = function (el, val) {
  if (!el) return;
  const v = Array.isArray(val) ? val : String(val == null ? '' : val);
  el._tsLastVal = Array.isArray(v) ? v.join(',') : v;
  if (el.tomselect) {
    el.tomselect.setValue(v, true);
  } else if (!Array.isArray(v)) {
    el.value = v;
  }
};

window.setTsOptions = function (el, items, selectedVal, placeholder) {
  if (!el) return;
  const ph = placeholder || (el.dataset.tsPlaceholder || '-- Pilih --');
  const selStr = String(selectedVal == null ? '' : selectedVal);
  const norm = (items || []).map(it => ({
    ...it,
    value: String(it.value != null ? it.value : (it.id != null ? it.id : '')),
    text: String(it.text != null ? it.text : (it.name != null ? it.name : ''))
  })).filter(it => it.value !== '');

  if (el.tomselect) {
    const ts = el.tomselect;
    ts.loadedSearches = {};
    ts.clear(true);
    ts.clearOptions();
    if (!el.multiple) {
      ts.addOption({ value: '', text: ph });
    }
    norm.forEach(it => ts.addOption(it));
    ts.refreshOptions(false);
    if (selStr && norm.some(it => it.value === selStr)) {
      el._tsLastVal = selStr;
      ts.setValue(selStr, true);
    } else {
      el._tsLastVal = '';
    }
  } else {
    el.innerHTML = '';
    if (!el.multiple) {
      const optPh = document.createElement('option');
      optPh.value = '';
      optPh.textContent = ph;
      el.appendChild(optPh);
    }
    norm.forEach(it => {
      const opt = document.createElement('option');
      opt.value = it.value;
      opt.textContent = it.text;
      if (it.value === selStr) opt.selected = true;
      el.appendChild(opt);
    });
    el._tsLastVal = selStr;
  }
};

window.bindTsChange = function (el, callback) {
  if (!el || typeof callback !== 'function') return;
  if (!el._tsChangeCallbacks) {
    const readVal = function () {
      if (el.tomselect) return el.tomselect.getValue();
      if (el.multiple) return Array.from(el.selectedOptions || []).map(o => o.value);
      return el.value;
    };
    const toStr = val => (Array.isArray(val) ? val.join(',') : String(val == null ? '' : val));
    el._tsChangeCallbacks = [];
    el._tsLastVal = toStr(readVal());
    const emit = function (val) {
      const cur = toStr(val);
      if (cur === el._tsLastVal) return;
      el._tsLastVal = cur;
      el._tsChangeCallbacks.forEach(fn => {
        try { fn(cur, val); } catch (e) { console.error(e); }
      });
    };
    el.addEventListener('change', function () {
      emit(readVal());
    });
    if (el.tomselect) {
      el.tomselect.on('change', emit);
    } else {
      let tries = 0;
      const timer = setInterval(() => {
        tries++;
        if (el.tomselect) {
          clearInterval(timer);
          el.tomselect.on('change', emit);
        } else if (tries > 30) {
          clearInterval(timer);
        }
      }, 100);
    }
  }
  el._tsChangeCallbacks.push(callback);
};

window.syncTsLastVal = function (el) {
  if (!el) return;
  const v = el.tomselect
    ? el.tomselect.getValue()
    : (el.multiple ? Array.from(el.selectedOptions || []).map(o => o.value) : el.value);
  el._tsLastVal = Array.isArray(v) ? v.join(',') : String(v == null ? '' : v);
};

/**
 * Jaga agar pelanggan utama & penumpang tambahan saling eksklusif dua arah,
 * sekaligus menjaga el._tsLastVal sinkron setelah removeItem().
 */
window.bindExclusivePrimaryExtra = function (primaryEl, extraEl, onAfterChange) {
  if (!primaryEl || !extraEl) return;
  let syncing = false;

  const getPrimaryVal = () => {
    const v = primaryEl.tomselect ? primaryEl.tomselect.getValue() : primaryEl.value;
    return String(Array.isArray(v) ? (v[0] || '') : (v || ''));
  };

  const getExtraArr = () => {
    const v = extraEl.tomselect
      ? extraEl.tomselect.getValue()
      : Array.from(extraEl.selectedOptions || []).map(o => o.value);
    return (Array.isArray(v) ? v : (v ? [v] : [])).map(String).filter(Boolean);
  };

  const stripPrimaryFromExtra = () => {
    const pVal = getPrimaryVal();
    if (!pVal) return false;
    const arr = getExtraArr();
    if (arr.indexOf(pVal) === -1) return false;
    if (extraEl.tomselect) {
      extraEl.tomselect.removeItem(pVal, true);
      window.syncTsLastVal(extraEl);
    } else {
      Array.from(extraEl.options || []).forEach(o => {
        if (String(o.value) === pVal) o.selected = false;
      });
    }
    return true;
  };

  stripPrimaryFromExtra();

  const handle = (source) => {
    if (syncing) return;
    syncing = true;
    try {
      stripPrimaryFromExtra();
    } finally {
      syncing = false;
    }
    if (typeof onAfterChange === 'function') {
      onAfterChange(source);
    }
  };

  if (typeof window.bindTsChange === 'function') {
    window.bindTsChange(primaryEl, () => handle('primary'));
    window.bindTsChange(extraEl, () => handle('extra'));
  } else {
    primaryEl.addEventListener('change', () => handle('primary'));
    extraEl.addEventListener('change', () => handle('extra'));
  }
};

/**
 * Tampilkan peringatan non-blocking bila jumlah total penumpang melebihi kursi kendaraan.
 */
window.updateSeatCapacityWarning = function (vehEl, primPaxEl, extraPaxEl, warnEl) {
  if (!warnEl) return;
  if (!vehEl) {
    warnEl.classList.add('d-none');
    warnEl.textContent = '';
    return;
  }

  const vId = vehEl.tomselect ? String(vehEl.tomselect.getValue() || '') : String(vehEl.value || '');
  let seat = 0;
  if (vId) {
    if (vehEl.tomselect && vehEl.tomselect.options && vehEl.tomselect.options[vId]) {
      seat = parseInt(vehEl.tomselect.options[vId].seat || 0, 10) || 0;
    }
    if (!seat) {
      const opt = Array.from(vehEl.options || []).find(o => String(o.value) === vId);
      if (opt && opt.dataset && opt.dataset.seat) {
        seat = parseInt(opt.dataset.seat, 10) || 0;
      }
    }
  }

  if (!vId || seat <= 0) {
    warnEl.classList.add('d-none');
    warnEl.textContent = '';
    return;
  }

  const primVal = primPaxEl
    ? String(primPaxEl.tomselect ? (primPaxEl.tomselect.getValue() || '') : (primPaxEl.value || ''))
    : '';
  const extraRaw = extraPaxEl
    ? (extraPaxEl.tomselect ? extraPaxEl.tomselect.getValue() : Array.from(extraPaxEl.selectedOptions || []).map(o => o.value))
    : [];
  const extraSet = new Set((Array.isArray(extraRaw) ? extraRaw : (extraRaw ? [extraRaw] : [])).map(String).filter(x => x && x !== primVal));
  const totalPax = (primVal ? 1 : 0) + extraSet.size;

  if (totalPax > seat) {
    warnEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Perhatian Kapasitas:</strong> Total <strong>' + totalPax + ' penumpang</strong> melebihi kapasitas kursi kendaraan terpilih (<strong>' + seat + ' kursi</strong>).';
    warnEl.classList.remove('d-none');
  } else {
    warnEl.classList.add('d-none');
    warnEl.textContent = '';
  }
};

if (window.TomSelect) {
  document.querySelectorAll('select.ts-select').forEach(el => window.initTsSelect(el));
}

/** Normalisasi nomor telepon Indonesia -> format kanonis `62...` (string kosong bila kosong / hanya `62`). */
window.normalizePhone62 = function (raw) {
  const s = String(raw || '').replace(/@.*$/, '').trim();
  let d = s.replace(/\D+/g, '');
  if (!d) return '';
  if (d.startsWith('00')) d = d.slice(2);
  if (d.startsWith('620') && d.length > 3) {
    d = '62' + d.slice(2).replace(/^0+/, '');
  } else if (d.startsWith('62')) {
    // sudah diawali 62
  } else if (d.startsWith('0')) {
    const rest = d.slice(1).replace(/^0+/, '');
    d = rest ? '62' + rest : '';
  } else {
    d = '62' + d;
  }
  return d && d !== '62' ? d : '';
};

/** Inisialisasi Flatpickr standar ERP (tampilan dd-MMM-yyyy, nilai submit Y-m-d). */
window.initFlatpickrDate = function (target, opts) {
  if (typeof window.flatpickr !== 'function') return [];
  const list = typeof target === 'string'
    ? document.querySelectorAll(target)
    : (target && typeof target.forEach === 'function' ? target : (target ? [target] : []));
  const instances = [];
  list.forEach(function (el) {
    if (!el || el.disabled || el._flatpickr) return;
    const baseCls = el.className.replace(/\bflatpickr-date\b/g, '').trim() || 'form-control form-control-sm bg-white';
    const cfg = Object.assign({
      altInput: true,
      altFormat: 'd-M-Y',
      dateFormat: 'Y-m-d',
      altInputClass: baseCls,
      allowInput: true
    }, opts || {});
    const fp = window.flatpickr(el, cfg);
    if (fp && fp.altInput) {
      fp.altInput.addEventListener('blur', function () {
        if (!el.required && String(fp.altInput.value || '').trim() === '' && fp.selectedDates.length > 0) {
          fp.clear();
        }
      });
    }
    instances.push(fp);
  });
  return instances;
};

document.addEventListener('DOMContentLoaded', function () {
  window.initFlatpickrDate('.flatpickr-date[data-flatpickr-auto]');
  const sel = 'input[name="phone_number"], input[name="bot_phone"], #qpPhone, #qp_phone, input[data-phone-62]';
  const applyNorm = function (el) {
    if (!el || el.readOnly || el.disabled) return;
    const norm = window.normalizePhone62(el.value);
    if (el.value !== norm) {
      el.value = norm;
    }
  };
  document.addEventListener('blur', function (e) {
    if (e.target && e.target.matches && e.target.matches(sel)) {
      applyNorm(e.target);
    }
  }, true);
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!f || !f.querySelectorAll) return;
    f.querySelectorAll(sel).forEach(applyNorm);
  }, true);
});

// ── Global Interactive Loading System (Progress Bar + Component Shimmer Workspace + Button Spinner) ──
(function initInteractiveLoader() {
  if (window.vstLoader) return;

  const DOWNLOAD_RE = /export\.php|_export\.php|print\.php|manifest\.php|[?&](format|export)=(pdf|csv|xlsx)|[?&](download|template)=1/i;
  const NAV_STORAGE_KEY = 'vst_nav_pending';
  const VALID_PRESETS = ['dashboard', 'table', 'form', 'detail', 'calendar', 'report'];

  const progressEl = document.getElementById('vstNavProgress');
  const barEl      = progressEl ? progressEl.querySelector('.vst-nav-progress-bar') : null;
  const overlayEl  = document.getElementById('vstPageLoader');
  const labelEl    = document.getElementById('vstPageLoaderLabel');
  const bodyEl     = document.getElementById('vstPageLoaderBody');

  let barVal        = 0;
  let barTimer      = null;
  let finishTimer   = null;
  let pulseTimer    = null;
  let overlayTimer  = null;
  let safetyTimer   = null;
  let fetchInFlight = 0;
  let currentPreset = '';
  const skelCache   = Object.create(null);
  const busyButtons = new Set();

  function detectPreset(urlPath) {
    const p = String(urlPath || '');
    if (/\/pages\/dashboard\//i.test(p)) return 'dashboard';
    if (/\/pages\/dispatch\/calendar\.php/i.test(p)) return 'calendar';
    if (/\/pages\/reports\//i.test(p)) return 'report';
    if (/\/detail\.php|\/pages\/profile\//i.test(p)) return 'detail';
    if (/\/(form|permissions|backup|pay|import)\.php/i.test(p)) return 'form';
    return 'table';
  }

  function skRow(widths) {
    return '<div class="vst-sk-table-row">'
      + widths.map(function (w) {
        return '<span class="vst-skel vst-skel-line mb-0" style="width:' + w + '"></span>';
      }).join('')
      + '<span class="vst-sk-actions"><span class="vst-skel vst-skel-pill"></span><span class="vst-skel vst-skel-pill"></span></span>'
      + '</div>';
  }

  function buildCalCellsHtml() {
    const pills = {
      2: '<span class="vst-skel vst-skel-pill" style="width:92%"></span><span class="vst-skel vst-skel-pill" style="width:70%"></span>',
      4: '<span class="vst-skel vst-skel-pill" style="width:84%"></span>',
      9: '<span class="vst-skel vst-skel-pill" style="width:96%"></span>',
      11: '<span class="vst-skel vst-skel-pill" style="width:76%"></span><span class="vst-skel vst-skel-pill" style="width:88%"></span>',
      16: '<span class="vst-skel vst-skel-pill" style="width:90%"></span>',
      18: '<span class="vst-skel vst-skel-pill" style="width:74%"></span>',
      22: '<span class="vst-skel vst-skel-pill" style="width:95%"></span><span class="vst-skel vst-skel-pill" style="width:68%"></span>',
      26: '<span class="vst-skel vst-skel-pill" style="width:94%"></span>'
    };
    let cells = '';
    for (let i = 0; i < 35; i++) {
      cells += '<div class="vst-sk-cal-cell">'
        + '<span class="vst-skel vst-skel-line vst-sk-cal-num"></span>'
        + (pills[i] || '')
        + '</div>';
    }
    return cells;
  }

  function buildCalListHtml() {
    let items = '';
    for (let i = 0; i < 5; i++) {
      items += '<div class="vst-sk-cal-list-row">'
        + '<div class="vst-sk-cal-list-top">'
        + '<span class="vst-skel vst-skel-pill" style="width:58px"></span>'
        + '<span class="vst-skel vst-skel-pill" style="width:72px"></span>'
        + '<span class="vst-skel vst-skel-line mb-0" style="width:42%"></span>'
        + '</div>'
        + '<span class="vst-skel vst-skel-line mb-0" style="width:76%;height:10px"></span>'
        + '</div>';
    }
    return items;
  }

  function buildSkeletonHtml(preset) {
    const key = VALID_PRESETS.indexOf(preset) >= 0 ? preset : 'table';
    if (skelCache[key]) return skelCache[key];

    let html = '';
    if (key === 'dashboard') {
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:180px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:260px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:128px"></span><span class="vst-skel vst-skel-btn" style="width:112px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-kpis">'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:52%;height:11px"></span><span class="vst-skel vst-skel-line" style="width:68%;height:24px"></span><span class="vst-skel vst-skel-line mb-0" style="width:40%;height:10px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:48%;height:11px"></span><span class="vst-skel vst-skel-line" style="width:74%;height:24px"></span><span class="vst-skel vst-skel-line mb-0" style="width:44%;height:10px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:56%;height:11px"></span><span class="vst-skel vst-skel-line" style="width:62%;height:24px"></span><span class="vst-skel vst-skel-line mb-0" style="width:38%;height:10px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:50%;height:11px"></span><span class="vst-skel vst-skel-line" style="width:70%;height:24px"></span><span class="vst-skel vst-skel-line mb-0" style="width:46%;height:10px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-grid-dash">'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:38%;height:14px;margin-bottom:12px"></span><span class="vst-skel vst-skel-block" style="height:190px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:48%;height:14px;margin-bottom:12px"></span>'
        +     '<span class="vst-skel vst-skel-line" style="width:94%;height:26px"></span>'
        +     '<span class="vst-skel vst-skel-line" style="width:88%;height:26px"></span>'
        +     '<span class="vst-skel vst-skel-line" style="width:92%;height:26px"></span>'
        +     '<span class="vst-skel vst-skel-line mb-0" style="width:82%;height:26px"></span>'
        +   '</div>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-table">'
        +   '<div class="vst-sk-table-head"><span class="vst-skel vst-skel-line mb-0" style="width:14%"></span><span class="vst-skel vst-skel-line mb-0" style="width:24%"></span><span class="vst-skel vst-skel-line mb-0" style="width:20%"></span><span class="vst-skel vst-skel-line mb-0" style="width:18%"></span><span class="vst-skel vst-skel-line mb-0" style="width:10%"></span></div>'
        +   skRow(['14%', '26%', '18%', '18%'])
        +   skRow(['12%', '24%', '22%', '16%'])
        +   skRow(['15%', '22%', '20%', '18%'])
        + '</div>';
    } else if (key === 'detail') {
      const infoRows = ''
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:72%"></span><span class="vst-skel vst-skel-line mb-0" style="width:65%"></span></div>'
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:66%"></span><span class="vst-skel vst-skel-line mb-0" style="width:78%"></span></div>'
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:78%"></span><span class="vst-skel vst-skel-line mb-0" style="width:54%"></span></div>'
        + '<div class="vst-sk-form-row mb-0"><span class="vst-skel vst-skel-line mb-0" style="width:62%"></span><span class="vst-skel vst-skel-line mb-0" style="width:70%"></span></div>';
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:230px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:170px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:94px"></span><span class="vst-skel vst-skel-btn" style="width:108px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-detail-grid">'
        +   '<div class="vst-sk-card">'
        +     '<div class="vst-sk-tabs">'
        +       '<span class="vst-skel vst-skel-btn" style="width:88px"></span>'
        +       '<span class="vst-skel vst-skel-btn" style="width:104px"></span>'
        +       '<span class="vst-skel vst-skel-btn" style="width:96px"></span>'
        +       '<span class="vst-skel vst-skel-btn" style="width:84px"></span>'
        +     '</div>'
        +     '<div class="vst-sk-card vst-sk-subpanel mb-3">' + infoRows + '</div>'
        +     '<div class="vst-sk-card vst-sk-table">'
        +       '<div class="vst-sk-table-head"><span class="vst-skel vst-skel-line mb-0" style="width:20%"></span><span class="vst-skel vst-skel-line mb-0" style="width:28%"></span><span class="vst-skel vst-skel-line mb-0" style="width:22%"></span><span class="vst-skel vst-skel-line mb-0" style="width:14%"></span></div>'
        +       skRow(['18%', '28%', '22%', '14%'])
        +       skRow(['22%', '24%', '20%', '16%'])
        +     '</div>'
        +   '</div>'
        +   '<div class="vst-sk-card">'
        +     '<div class="d-flex justify-content-between align-items-center mb-3"><span class="vst-skel vst-skel-line mb-0" style="width:48%;height:15px"></span><span class="vst-skel vst-skel-pill" style="width:68px"></span></div>'
        +     '<div class="vst-sk-kv-row"><span class="vst-skel vst-skel-line mb-0" style="width:42%"></span><span class="vst-skel vst-skel-line mb-0" style="width:36%"></span></div>'
        +     '<div class="vst-sk-kv-row"><span class="vst-skel vst-skel-line mb-0" style="width:38%"></span><span class="vst-skel vst-skel-line mb-0" style="width:32%"></span></div>'
        +     '<div class="vst-sk-kv-row"><span class="vst-skel vst-skel-line mb-0" style="width:46%"></span><span class="vst-skel vst-skel-line mb-0" style="width:34%"></span></div>'
        +     '<div class="vst-sk-kv-row"><span class="vst-skel vst-skel-line mb-0" style="width:36%;height:16px"></span><span class="vst-skel vst-skel-line mb-0" style="width:44%;height:18px"></span></div>'
        +   '</div>'
        + '</div>';
    } else if (key === 'form') {
      const formRows = ''
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:76%"></span><span class="vst-skel vst-skel-input"></span></div>'
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:68%"></span><span class="vst-skel vst-skel-input"></span></div>'
        + '<div class="vst-sk-form-row"><span class="vst-skel vst-skel-line mb-0" style="width:82%"></span><span class="vst-skel vst-skel-input"></span></div>'
        + '<div class="vst-sk-form-row mb-0"><span class="vst-skel vst-skel-line mb-0" style="width:64%"></span><span class="vst-skel vst-skel-input"></span></div>';
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:220px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:150px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:88px"></span><span class="vst-skel vst-skel-btn" style="width:96px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-card">'
        +   '<div class="vst-sk-tabs">'
        +     '<span class="vst-skel vst-skel-btn" style="width:92px"></span>'
        +     '<span class="vst-skel vst-skel-btn" style="width:108px"></span>'
        +     '<span class="vst-skel vst-skel-btn" style="width:96px"></span>'
        +   '</div>'
        +   '<div class="vst-sk-grid-2">'
        +     '<div class="vst-sk-card vst-sk-subpanel"><span class="vst-skel vst-skel-line" style="width:48%;height:14px;margin-bottom:14px"></span>' + formRows + '</div>'
        +     '<div class="vst-sk-card vst-sk-subpanel"><span class="vst-skel vst-skel-line" style="width:52%;height:14px;margin-bottom:14px"></span>' + formRows + '</div>'
        +   '</div>'
        + '</div>';
    } else if (key === 'calendar') {
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:210px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:290px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:144px"></span><span class="vst-skel vst-skel-btn" style="width:144px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-filter">'
        +   '<span class="vst-skel vst-skel-input" style="flex:2 1 200px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 130px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 130px"></span>'
        +   '<span class="vst-skel vst-skel-btn" style="width:84px"></span>'
        + '</div>'
        + '<div class="vst-sk-card">'
        +   '<div class="vst-sk-cal-toolbar">'
        +     '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:76px"></span><span class="vst-skel vst-skel-btn" style="width:74px"></span></div>'
        +     '<span class="vst-skel vst-skel-line mb-0" style="width:160px;height:18px"></span>'
        +     '<div class="vst-sk-header-right d-none d-md-flex"><span class="vst-skel vst-skel-btn" style="width:62px"></span><span class="vst-skel vst-skel-btn" style="width:62px"></span><span class="vst-skel vst-skel-btn" style="width:62px"></span></div>'
        +   '</div>'
        +   '<div class="d-none d-md-block">'
        +     '<div class="vst-sk-cal-weekdays">'
        +       '<span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span><span class="vst-skel vst-skel-line mb-0"></span>'
        +     '</div>'
        +     '<div class="vst-sk-cal-grid">' + buildCalCellsHtml() + '</div>'
        +   '</div>'
        +   '<div class="d-md-none vst-sk-cal-list">' + buildCalListHtml() + '</div>'
        + '</div>';
    } else if (key === 'report') {
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:200px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:240px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:78px"></span><span class="vst-skel vst-skel-btn" style="width:78px"></span><span class="vst-skel vst-skel-btn" style="width:78px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-filter">'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 160px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 140px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 140px"></span>'
        +   '<span class="vst-skel vst-skel-btn" style="width:94px"></span>'
        + '</div>'
        + '<div class="vst-sk-kpis">'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:54%;height:11px"></span><span class="vst-skel vst-skel-line mb-0" style="width:72%;height:22px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:48%;height:11px"></span><span class="vst-skel vst-skel-line mb-0" style="width:66%;height:22px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:58%;height:11px"></span><span class="vst-skel vst-skel-line mb-0" style="width:70%;height:22px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:50%;height:11px"></span><span class="vst-skel vst-skel-line mb-0" style="width:64%;height:22px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-grid-2">'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:42%;height:14px;margin-bottom:10px"></span><span class="vst-skel vst-skel-block" style="height:170px"></span></div>'
        +   '<div class="vst-sk-card"><span class="vst-skel vst-skel-line" style="width:46%;height:14px;margin-bottom:10px"></span><span class="vst-skel vst-skel-block" style="height:170px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-table">'
        +   '<div class="vst-sk-table-head"><span class="vst-skel vst-skel-line mb-0" style="width:20%"></span><span class="vst-skel vst-skel-line mb-0" style="width:16%"></span><span class="vst-skel vst-skel-line mb-0" style="width:18%"></span><span class="vst-skel vst-skel-line mb-0" style="width:18%"></span><span class="vst-skel vst-skel-line mb-0" style="width:12%"></span></div>'
        +   skRow(['22%', '16%', '18%', '18%'])
        +   skRow(['18%', '18%', '16%', '20%'])
        +   skRow(['24%', '14%', '18%', '16%'])
        + '</div>';
    } else {
      html = ''
        + '<div class="vst-sk-header">'
        +   '<div class="vst-sk-header-left"><span class="vst-skel vst-skel-line" style="width:190px;height:20px"></span><span class="vst-skel vst-skel-line mb-0" style="width:220px;height:11px"></span></div>'
        +   '<div class="vst-sk-header-right"><span class="vst-skel vst-skel-btn" style="width:92px"></span><span class="vst-skel vst-skel-btn" style="width:104px"></span></div>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-filter">'
        +   '<span class="vst-skel vst-skel-input" style="flex:2 1 200px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 130px"></span>'
        +   '<span class="vst-skel vst-skel-input" style="flex:1 1 130px"></span>'
        +   '<span class="vst-skel vst-skel-btn" style="width:86px"></span>'
        + '</div>'
        + '<div class="vst-sk-card vst-sk-table">'
        +   '<div class="vst-sk-table-head"><span class="vst-skel vst-skel-line mb-0" style="width:12%"></span><span class="vst-skel vst-skel-line mb-0" style="width:24%"></span><span class="vst-skel vst-skel-line mb-0" style="width:20%"></span><span class="vst-skel vst-skel-line mb-0" style="width:16%"></span><span class="vst-skel vst-skel-line mb-0" style="width:10%"></span></div>'
        +   skRow(['12%', '24%', '18%', '14%'])
        +   skRow(['10%', '28%', '16%', '16%'])
        +   skRow(['14%', '22%', '20%', '14%'])
        +   skRow(['12%', '26%', '18%', '12%'])
        +   skRow(['11%', '20%', '22%', '15%'])
        +   skRow(['13%', '25%', '17%', '14%'])
        + '</div>';
    }

    skelCache[key] = html;
    return html;
  }

  function setBarScale(v) {
    barVal = Math.max(0, Math.min(1, v));
    if (barEl) barEl.style.transform = 'scaleX(' + barVal + ')';
  }

  function startBar() {
    if (!progressEl || !barEl) return;
    window.clearTimeout(finishTimer);
    progressEl.classList.add('is-active');
    if (barVal < 0.22) setBarScale(0.22);
    if (barTimer) return;
    barTimer = window.setInterval(function () {
      if (barVal < 0.52) setBarScale(0.52);
      else if (barVal < 0.78) setBarScale(0.78);
      else if (barVal < 0.88) setBarScale(0.88);
      else if (barVal < 0.94) setBarScale(barVal + 0.015);
    }, 260);
  }

  function finishBar() {
    if (!progressEl || !barEl) return;
    window.clearInterval(barTimer);
    barTimer = null;
    window.clearTimeout(finishTimer);
    setBarScale(1);
    finishTimer = window.setTimeout(function () {
      progressEl.classList.remove('is-active');
      window.setTimeout(function () {
        if (!progressEl.classList.contains('is-active')) setBarScale(0);
      }, 240);
    }, 180);
  }

  function pulseBar(ms) {
    window.clearTimeout(pulseTimer);
    startBar();
    pulseTimer = window.setTimeout(finishBar, Math.max(400, Number(ms) || 1400));
  }

  function hideOverlay() {
    window.clearTimeout(overlayTimer);
    overlayTimer = null;
    window.clearTimeout(safetyTimer);
    safetyTimer = null;
    if (!overlayEl) return;
    overlayEl.classList.remove('is-active');
    overlayEl.setAttribute('aria-hidden', 'true');
  }

  function showOverlay(label, preset, immediate) {
    if (!overlayEl) return;
    if (labelEl) labelEl.textContent = label || 'Memuat halaman…';
    const nextPreset = VALID_PRESETS.indexOf(preset) >= 0 ? preset : detectPreset(window.location.pathname);
    if (bodyEl && currentPreset !== nextPreset) {
      bodyEl.innerHTML = buildSkeletonHtml(nextPreset);
      currentPreset = nextPreset;
    }
    window.clearTimeout(overlayTimer);
    window.clearTimeout(safetyTimer);
    const activate = function () {
      overlayEl.classList.add('is-active');
      overlayEl.setAttribute('aria-hidden', 'false');
    };
    if (immediate) {
      activate();
    } else {
      overlayTimer = window.setTimeout(activate, 80);
    }
    safetyTimer = window.setTimeout(resetAll, 12000);
  }

  function setButtonLoading(btn, label) {
    if (!btn) return;
    if (btn.dataset.vstOrigHtml === undefined) {
      btn.dataset.vstOrigHtml = btn.innerHTML;
      btn.dataset.vstOrigMinWidth = btn.style.minWidth || '';
      const rect = btn.getBoundingClientRect();
      if (rect && rect.width > 0) {
        btn.style.minWidth = Math.ceil(rect.width) + 'px';
      }
    }
    const rectW = btn.getBoundingClientRect().width;
    const rawText = (label || btn.getAttribute('data-loading-text') || btn.textContent || '').replace(/\s+/g, ' ').trim();
    const isIconOnly = btn.textContent.replace(/\s+/g, '').trim() === '' && rectW > 0 && rectW < 48;

    const frag = document.createDocumentFragment();
    const dot = document.createElement('span');
    dot.className = isIconOnly ? 'vst-btn-dot' : 'vst-btn-dot me-2';
    dot.setAttribute('role', 'status');
    dot.setAttribute('aria-hidden', 'true');
    frag.appendChild(dot);
    if (!isIconOnly) {
      const txt = document.createElement('span');
      txt.textContent = rawText || 'Memproses…';
      frag.appendChild(txt);
    }

    btn.replaceChildren(frag);
    btn.classList.add('vst-btn-loading');
    btn.setAttribute('aria-busy', 'true');
    busyButtons.add(btn);
  }

  function setFieldLoading(el, on) {
    if (!el) return;
    const targets = [];
    if (el.tomselect && el.tomselect.control) {
      targets.push(el.tomselect.control);
    }
    targets.push(el);
    targets.forEach(function (t) {
      if (!t || !t.classList) return;
      if (on) {
        t.classList.add('vst-field-shimmer');
        t.setAttribute('aria-busy', 'true');
      } else {
        t.classList.remove('vst-field-shimmer');
        t.removeAttribute('aria-busy');
      }
    });
  }

  function buildListSkeleton(rows) {
    const count = Math.max(1, Math.min(8, parseInt(rows, 10) || 4));
    const widths = [['92px', '220px'], ['108px', '180px'], ['84px', '205px'], ['96px', '165px']];
    let html = '<div class="vst-ai-panel-skel" aria-hidden="true">';
    for (let i = 0; i < count; i++) {
      const pair = widths[i % widths.length];
      html += '<div class="vst-ai-panel-skel-row">'
        + '<span class="vst-skel vst-skel-line" style="width:' + pair[0] + '"></span>'
        + '<span class="vst-skel vst-skel-line" style="flex:1;max-width:' + pair[1] + '"></span>'
        + '</div>';
    }
    html += '</div>';
    return html;
  }

  function clearButtonLoading(btn) {
    if (!btn || btn.dataset.vstOrigHtml === undefined) return;
    btn.innerHTML = btn.dataset.vstOrigHtml;
    delete btn.dataset.vstOrigHtml;
    btn.style.minWidth = btn.dataset.vstOrigMinWidth || '';
    delete btn.dataset.vstOrigMinWidth;
    btn.classList.remove('vst-btn-loading');
    btn.removeAttribute('aria-busy');
    busyButtons.delete(btn);
  }

  function resetAll() {
    window.clearTimeout(pulseTimer);
    fetchInFlight = 0;
    hideOverlay();
    window.clearInterval(barTimer);
    barTimer = null;
    window.clearTimeout(finishTimer);
    if (progressEl) progressEl.classList.remove('is-active');
    setBarScale(0);
    document.querySelectorAll('form[data-vst-submitting="1"]').forEach(function (f) {
      delete f.dataset.vstSubmitting;
    });
    Array.from(busyButtons).forEach(clearButtonLoading);
  }

  function markPending() {
    try {
      sessionStorage.setItem(NAV_STORAGE_KEY, JSON.stringify({ ts: Date.now() }));
    } catch (err) {}
  }

  // 1. Interceptor klik tautan internal (bubble phase agar menghormati preventDefault / confirm)
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (a.hasAttribute('download') || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-no-loader')) return;
    const tgt = (a.getAttribute('target') || '').trim().toLowerCase();
    if (tgt && tgt !== '_self') return;

    const rawHref = (a.getAttribute('href') || '').trim();
    if (!rawHref || rawHref.startsWith('#') || /^(javascript|mailto|tel|blob|data):/i.test(rawHref)) return;

    let url;
    try {
      url = new URL(a.href, window.location.href);
    } catch (err) {
      return;
    }
    if (url.origin !== window.location.origin) return;
    if (url.href === window.location.href || (url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '')) {
      return;
    }

    if (DOWNLOAD_RE.test(url.pathname + url.search)) {
      pulseBar(1500);
      return;
    }

    const isSidebar = !!a.closest('#sidebar');
    const linkTxt = (a.textContent || '').replace(/\s+/g, ' ').trim();
    let title = 'Memuat halaman…';

    if (/\/logout\.php$/i.test(url.pathname)) {
      title = 'Mengakhiri sesi…';
    } else if (isSidebar && linkTxt) {
      title = 'Memuat ' + linkTxt + '…';
    } else if (a.closest('.topbar-dropdown, .topbar-pill')) {
      title = linkTxt ? ('Membuka ' + linkTxt + '…') : 'Memuat halaman…';
    } else if (linkTxt && linkTxt.length <= 42) {
      title = 'Membuka ' + linkTxt + '…';
    }

    markPending();
    startBar();
    showOverlay(title, detectPreset(url.pathname), isSidebar);
  }, false);

  // 2. Interceptor submit form standar (bubble phase, tanpa disable tombol agar name/value tetap terkirim)
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    const form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.hasAttribute('data-no-loader') || form.hasAttribute('data-ajax')) return;
    const tgt = (form.getAttribute('target') || '').trim().toLowerCase();
    if (tgt && tgt !== '_self') return;

    const actionStr = form.getAttribute('action') || window.location.href;
    if (DOWNLOAD_RE.test(actionStr)) {
      pulseBar(1500);
      return;
    }

    if (form.dataset.vstSubmitting === '1') {
      e.preventDefault();
      return;
    }

    let btn = e.submitter || null;
    if (!btn && form.id && window.CSS && CSS.escape) {
      btn = document.querySelector('button[type="submit"][form="' + CSS.escape(form.id) + '"]');
    }
    if (!btn) {
      btn = form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
    }

    form.dataset.vstSubmitting = '1';
    const method = String(form.getAttribute('method') || 'get').toLowerCase();
    let title = 'Memproses permintaan…';
    let btnText = (btn && btn.getAttribute('data-loading-text')) || 'Memproses…';

    if (/\/companies\/switch\.php/i.test(actionStr)) {
      const compLabel = btn ? (btn.textContent || '').replace(/\s+/g, ' ').trim() : '';
      title   = compLabel ? ('Beralih ke ' + compLabel + '…') : 'Beralih perusahaan…';
      btnText = 'Mengalihkan…';
    } else if (/delete\.php/i.test(actionStr)) {
      title   = 'Menghapus data…';
      btnText = 'Menghapus…';
    } else if (method === 'get') {
      title   = 'Menerapkan filter…';
      btnText = (btn && btn.getAttribute('data-loading-text')) || 'Memfilter…';
    } else {
      title   = 'Menyimpan perubahan…';
      btnText = (btn && btn.getAttribute('data-loading-text')) || 'Menyimpan…';
    }

    let actionPath = window.location.pathname;
    try {
      actionPath = new URL(actionStr, window.location.href).pathname;
    } catch (err) {}

    markPending();
    if (btn && btn.tagName === 'BUTTON') {
      setButtonLoading(btn, btnText);
    }
    startBar();
    showOverlay(title, detectPreset(actionPath), method === 'post');

    window.setTimeout(function () {
      if (form.dataset.vstSubmitting === '1') {
        delete form.dataset.vstSubmitting;
        if (btn) clearButtonLoading(btn);
        hideOverlay();
      }
    }, 15000);
  }, false);

  // 3. Tracking fetch() same-origin untuk progress bar atas
  if (typeof window.fetch === 'function' && !window.__vstFetchWrapped) {
    window.__vstFetchWrapped = true;
    const origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      let sameOrigin = false;
      try {
        const rawUrl = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        if (rawUrl) {
          sameOrigin = new URL(rawUrl, window.location.href).origin === window.location.origin;
        }
      } catch (err) {}

      if (!sameOrigin) {
        return origFetch(input, init);
      }

      fetchInFlight++;
      startBar();
      return origFetch(input, init).finally(function () {
        fetchInFlight = Math.max(0, fetchInFlight - 1);
        if (fetchInFlight === 0) finishBar();
      });
    };
  }

  // 4. Pemulihan otomatis saat bfcache Back/Forward atau tombol Escape
  window.addEventListener('pageshow', resetAll);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlayEl && overlayEl.classList.contains('is-active')) {
      resetAll();
    }
  });

  window.vstLoader = {
    start: function (title, sub, withOverlay, preset) {
      startBar();
      if (withOverlay) showOverlay(title, preset || detectPreset(window.location.pathname), true);
    },
    done: resetAll,
    pulse: pulseBar,
    setButtonLoading: setButtonLoading,
    clearButtonLoading: clearButtonLoading,
    setFieldLoading: setFieldLoading,
    buildListSkeleton: buildListSkeleton
  };
})();
