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
  } else if (el.closest && el.closest('.table-responsive')) {
    opts.dropdownParent = 'body';
  }

  if (remoteUrl) {
    opts.loadThrottle = 250;
    opts.shouldLoad = function (query) {
      return query.trim().length >= 1;
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

  const ts = new TomSelect(el, Object.assign(opts, overrides || {}));
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
