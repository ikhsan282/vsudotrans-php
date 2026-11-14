/**
 * Controller UI/UX ERP bersama untuk pemesanan Travel & Rental (VSudoTrans).
 * Menangani: Tab Navigation + Hash Sync, Time AM/PM Badge, Tab Kru & Komisi Otomatis,
 * Tab Pembayaran Multi-Baris, Polling & Modal QRIS Otomatis, Preview Kendaraan,
 * serta Validasi Form Lintas Tab.
 */
(function (window, document) {
  'use strict';

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function parseRp(val) {
    if (typeof window.parseRupiah === 'function') {
      return window.parseRupiah(val);
    }
    if (val === null || val === undefined || val === '') return 0;
    if (typeof val === 'number') return Math.round(val);
    const digits = String(val).replace(/\.(0|00)$/, '').replace(/[^0-9]/g, '');
    return digits ? parseInt(digits, 10) : 0;
  }

  function fmtRp(val) {
    if (typeof window.formatRupiah === 'function') {
      return window.formatRupiah(val);
    }
    const n = parseRp(val);
    if (n === 0) return String(val == null ? '' : val).replace(/[^0-9]/g, '') === '' ? '' : '0';
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function attachRp(inputEl, hiddenEl, cb) {
    if (!inputEl) return;
    if (typeof window.attachRupiahInput === 'function') {
      window.attachRupiahInput(inputEl, hiddenEl, cb);
      return;
    }
    inputEl.addEventListener('input', function () {
      const n = parseRp(this.value);
      if (hiddenEl) hiddenEl.value = String(n);
      this.value = n ? fmtRp(n) : '';
      if (typeof cb === 'function') cb(n);
    });
  }

  function getFieldVal(el) {
    if (!el) return '';
    if (el.tomselect) return el.tomselect.getValue();
    return el.value || '';
  }

  function initFlatpickrEl(el, onDateChange) {
    if (!el || el.disabled || el._flatpickr || typeof window.flatpickr === 'undefined') return null;
    return window.flatpickr(el, {
      altInput: true,
      altFormat: 'd-M-Y',
      dateFormat: 'Y-m-d',
      altInputClass: 'form-control form-control-sm bg-white',
      allowInput: false,
      onChange: function (selectedDates, dateStr) {
        if (typeof onDateChange === 'function') onDateChange(selectedDates, dateStr);
      }
    });
  }

  function getTabButtonForPane(paneEl) {
    if (!paneEl || !paneEl.id) return null;
    return document.querySelector(`[data-bs-target="#${paneEl.id}"]`);
  }

  function showTabById(tabId) {
    const clean = String(tabId || '').replace(/^#/, '');
    if (!clean) return;
    const btn = document.querySelector(`[data-bs-target="#${clean}"]`);
    if (btn && window.bootstrap && window.bootstrap.Tab) {
      window.bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }

  function initTabs(opts) {
    const cfg = opts || {};
    const navEl = cfg.tabNavId ? document.getElementById(cfg.tabNavId) : null;
    const activeInput = cfg.activeTabInputId ? document.getElementById(cfg.activeTabInputId) : null;

    if (window.location.hash && /^#tab-(detail|crew|payment|note|audit)$/.test(window.location.hash)) {
      showTabById(window.location.hash.slice(1));
    } else if (cfg.defaultTab && cfg.defaultTab !== 'detail') {
      showTabById('tab-' + cfg.defaultTab.replace(/^tab-/, ''));
    }

    const buttons = navEl
      ? navEl.querySelectorAll('[data-bs-toggle="tab"]')
      : document.querySelectorAll('.erp-sub-tabs [data-bs-toggle="tab"]');

    buttons.forEach(function (btn) {
      btn.addEventListener('shown.bs.tab', function (ev) {
        const targetSel = ev.target.getAttribute('data-bs-target') || '';
        const shortName = targetSel.replace(/^#tab-/, '') || 'detail';
        if (activeInput) activeInput.value = shortName;
        if (targetSel && window.history && window.history.replaceState) {
          try {
            window.history.replaceState(null, '', targetSel);
          } catch (e) {}
        }
        if (typeof cfg.onTabShown === 'function') {
          cfg.onTabShown(shortName, targetSel);
        }
      });
    });

    return { showTabById: showTabById };
  }

  function bindTimeBadge(timeInputEl, badgeEl) {
    if (!timeInputEl || !badgeEl) return;
    function syncBadge() {
      const val = String(timeInputEl.value || '').trim();
      const m = val.match(/^(\d{1,2}):\d{2}/);
      if (!m) {
        badgeEl.textContent = '--';
        return;
      }
      const hr = parseInt(m[1], 10);
      if (hr < 12) {
        badgeEl.textContent = 'AM (Pagi)';
        badgeEl.className = 'badge bg-info-subtle text-info-emphasis border';
      } else {
        badgeEl.textContent = 'PM (Siang/Malam)';
        badgeEl.className = 'badge bg-primary-subtle text-primary-emphasis border';
      }
    }
    timeInputEl.addEventListener('change', syncBadge);
    timeInputEl.addEventListener('input', syncBadge);
    syncBadge();
  }

  function initVehiclePreview(opts) {
    const cfg = opts || {};
    const vehSel = cfg.vehicleSelectEl;
    const preview = cfg.previewEl;
    if (!vehSel || !preview) return;

    function refresh() {
      const val = String(getFieldVal(vehSel) || '').trim();
      if (!val) {
        preview.style.display = 'none';
        return;
      }
      let plate = '';
      let brand = '';
      let model = '';
      let catName = '';
      let color = '';
      let seat = 0;

      if (vehSel.tomselect && vehSel.tomselect.options && vehSel.tomselect.options[val]) {
        const m = vehSel.tomselect.options[val];
        plate   = m.plate || '';
        brand   = m.brand || '';
        model   = m.model || '';
        catName = m.category_name || '';
        color   = m.color || '';
        seat    = parseInt(m.seat || 0, 10) || 0;
        if (!plate && m.text) {
          plate = String(m.text).split(' - ')[0] || m.text;
        }
      } else {
        const opt = vehSel.options[vehSel.selectedIndex];
        if (opt) {
          const d = opt.dataset || {};
          plate   = d.plate || (opt.textContent || '').split(' - ')[0] || '';
          brand   = d.brand || '';
          model   = d.model || '';
          catName = d.categoryName || '';
          color   = d.color || '';
          seat    = parseInt(d.seat || 0, 10) || 0;
        }
      }

      if (cfg.plateEl) cfg.plateEl.textContent = plate || '-';
      if (cfg.modelEl) cfg.modelEl.textContent = [brand, model].filter(Boolean).join(' ') || '-';
      if (cfg.catEl) cfg.catEl.textContent = catName || 'Armada';
      const specParts = [];
      if (color) specParts.push(color);
      if (seat > 0) specParts.push(seat + ' Kursi');
      if (cfg.specsEl) cfg.specsEl.textContent = specParts.join(' • ');
      preview.style.display = '';
    }

    vehSel.addEventListener('change', refresh);
    return { refresh: refresh };
  }

  function initCrewTab(opts) {
    const cfg = opts || {};
    const formId = cfg.formId || 'formTravelBooking';
    const tabCrewPane = document.getElementById('tab-crew');
    const showBbm = cfg.showBbm !== undefined
      ? Boolean(cfg.showBbm)
      : (!tabCrewPane || tabCrewPane.getAttribute('data-show-bbm') !== '0');
    const tbody = document.getElementById('crewTableBody');
    const bbmDisp = document.getElementById('bbmDisplay');
    const bbmVal = document.getElementById('bbmValue');
    const totalOpsEl = document.getElementById('crewTotalOpsDisplay');
    const subtotalEl = document.getElementById('crewSubtotalDisplay');
    const countEl = document.getElementById('crewRecordCount');
    const empTpl = document.getElementById('crewEmployeeOptionsTpl');
    const regSelect = document.getElementById('crewRegulationSelect');
    const btnApplyReg = document.getElementById('btnApplyRegulation');
    const regMsgEl = document.getElementById('crewRegStatusMsg');

    const crewRoles = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'];

    function recalcCrewTotals() {
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr.crew-row');
      let crewSum = 0;
      rows.forEach(function (tr, idx) {
        const numCell = tr.querySelector('.crew-row-num');
        if (numCell) numCell.textContent = String(idx + 1);
        const hv = tr.querySelector('.crew-amt-value');
        crewSum += hv ? parseRp(hv.value) : 0;
      });
      const bbmAmt = (showBbm && bbmVal) ? parseRp(bbmVal.value) : 0;
      if (subtotalEl) {
        const subFmt = fmtRp(crewSum) || '0';
        if (subtotalEl.tagName === 'INPUT') subtotalEl.value = subFmt;
        else subtotalEl.textContent = 'Rp ' + subFmt;
      }
      if (totalOpsEl) totalOpsEl.value = fmtRp(bbmAmt + crewSum) || '0';
      if (countEl) countEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
    }

    function syncEmpMeta(tr) {
      const empSel = tr.querySelector('.crew-emp-select');
      const orgInp = tr.querySelector('.crew-org-input');
      const posInp = tr.querySelector('.crew-pos-input');
      if (!empSel) return;
      const val = String(getFieldVal(empSel) || '').trim();
      let org = '';
      let pos = '';
      if (val) {
        if (empSel.tomselect && empSel.tomselect.options && empSel.tomselect.options[val]) {
          const meta = empSel.tomselect.options[val];
          org = meta.job_title || '';
          pos = meta.job_position || '';
        }
        if (!org && !pos && empSel.selectedIndex >= 0) {
          const opt = empSel.options[empSel.selectedIndex];
          if (opt) {
            org = opt.getAttribute('data-org') || '';
            pos = opt.getAttribute('data-pos') || '';
          }
        }
      }
      if (orgInp) orgInp.value = org;
      if (posInp) posInp.value = pos;
    }

    function bindCrewRow(tr) {
      if (!tr || tr.dataset.crewBound === '1') return;
      tr.dataset.crewBound = '1';

      const amtDisp = tr.querySelector('.crew-amt-display');
      const amtVal = tr.querySelector('.crew-amt-value');
      if (amtDisp && !amtDisp.readOnly) {
        attachRp(amtDisp, amtVal, recalcCrewTotals);
      }

      const empSel = tr.querySelector('.crew-emp-select');
      if (empSel) {
        if (typeof window.initTsSelect === 'function' && !empSel.tomselect && !empSel.disabled) {
          window.initTsSelect(empSel);
        }
        empSel.addEventListener('change', function () {
          syncEmpMeta(tr);
        });
        syncEmpMeta(tr);
      }

      const btnRem = tr.querySelector('.btn-remove-crew');
      if (btnRem) {
        btnRem.addEventListener('click', function () {
          if (!tbody) return;
          if (empSel && empSel.tomselect) {
            try { empSel.tomselect.destroy(); } catch (e) {}
          }
          tr.remove();
          recalcCrewTotals();
        });
      }
    }

    function addCrewRow(defaultType, defaultEmpId, defaultAmt) {
      if (!tbody || !cfg.canManage) return null;
      const roleVal = defaultType || 'Sopir';
      const amtNum = parseRp(defaultAmt || 0);
      const roleOptionsHtml = (crewRoles.includes(roleVal) ? crewRoles : crewRoles.concat([roleVal]))
        .map(function (r) {
          return '<option value="' + escHtml(r) + '"' + (r === roleVal ? ' selected' : '') + '>' + escHtml(r) + '</option>';
        }).join('');
      const empOptionsHtml = empTpl ? empTpl.innerHTML : '<option value="">-- Pilih Karyawan --</option>';

      const tr = document.createElement('tr');
      tr.className = 'crew-row';
      tr.innerHTML = '' +
        '<td class="text-center text-muted crew-row-num">1</td>' +
        '<td><select class="form-select form-select-sm crew-type-select" name="crew_type[]" form="' + escHtml(formId) + '">' + roleOptionsHtml + '</select></td>' +
        '<td><select class="form-select form-select-sm ts-select crew-emp-select" name="crew_employee_id[]" form="' + escHtml(formId) + '"' +
        ' data-ts-url="' + escHtml(cfg.appUrl) + '/pages/employees/options.php"' +
        ' data-ts-company="' + Number(cfg.companyId || 0) + '"' +
        ' data-ts-limit="30" data-ts-dropdown-parent="body" data-ts-placeholder="-- Pilih Karyawan --">' + empOptionsHtml + '</select></td>' +
        '<td><input type="text" class="form-control form-control-sm bg-light crew-org-input" value="" readonly></td>' +
        '<td><input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="" readonly></td>' +
        '<td>' +
        '<input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="' + escHtml(fmtRp(amtNum) || '0') + '" placeholder="0">' +
        '<input type="hidden" name="crew_amount[]" class="crew-amt-value" form="' + escHtml(formId) + '" value="' + amtNum + '">' +
        '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris"><i class="bi bi-x-lg"></i></button></td>';

      tbody.appendChild(tr);
      const empSel = tr.querySelector('.crew-emp-select');
      if (defaultEmpId && empSel) {
        empSel.value = String(defaultEmpId);
      }
      bindCrewRow(tr);
      recalcCrewTotals();
      return tr;
    }

    function setRegMsg(cls, text) {
      if (!regMsgEl) return;
      regMsgEl.className = 'small fw-normal ms-1 ' + (cls || 'text-muted');
      regMsgEl.textContent = text || '';
    }

    async function applyRegulationAuto() {
      const regId = regSelect ? (parseInt(regSelect.value || '0', 10) || 0) : 0;
      const catId = typeof cfg.getCategoryId === 'function' ? (parseInt(cfg.getCategoryId() || '0', 10) || 0) : 0;
      const totalPrice = typeof cfg.getTotalPrice === 'function' ? parseRp(cfg.getTotalPrice()) : 0;
      const startDate = typeof cfg.getStartDate === 'function' ? (cfg.getStartDate() || '') : '';
      const trip = typeof cfg.getTrip === 'function' ? (parseFloat(cfg.getTrip() || '1') || 1) : 1;

      if (!regId && !catId) {
        setRegMsg('text-warning fw-semibold', 'Pilih Kategori Kendaraan di tab Detail terlebih dahulu.');
        return;
      }

      if (btnApplyReg) btnApplyReg.disabled = true;
      setRegMsg('text-muted', showBbm ? 'Menghitung komisi dan BBM...' : 'Menghitung komisi...');

      try {
        const qs = new URLSearchParams({
          company_id: String(cfg.companyId || 0),
          regulation_id: String(regId),
          category_vehicle_id: String(catId),
          trip: String(trip),
          start_date: String(startDate),
          total_price: String(totalPrice)
        });
        const res = await fetch(cfg.appUrl + '/pages/transport/regulations/match_api.php?' + qs.toString(), {
          headers: { Accept: 'application/json' }
        });
        const data = await res.json();
        if (!res.ok || !data || !data.ok) {
          setRegMsg('text-danger fw-semibold', (data && data.error) || 'Peraturan komisi yang sesuai tidak ditemukan.');
          return;
        }

        const calc = data.calculated || {};
        if (showBbm) {
          const bbmNew = parseRp(calc.bbm || 0);
          if (bbmVal) bbmVal.value = String(bbmNew);
          if (bbmDisp) bbmDisp.value = fmtRp(bbmNew) || '0';
        }

        // Kumpulkan karyawan yang sudah dipilih per jabatan agar pilihan sopir/kernet user tetap terjaga
        const existingByRole = {};
        if (tbody) {
          tbody.querySelectorAll('tr.crew-row').forEach(function (tr) {
            const rSel = tr.querySelector('.crew-type-select');
            const eSel = tr.querySelector('.crew-emp-select');
            const role = rSel ? String(rSel.value || '') : '';
            const empId = eSel ? String(getFieldVal(eSel) || '') : '';
            if (role && empId) {
              if (!existingByRole[role]) existingByRole[role] = [];
              existingByRole[role].push(empId);
            }
          });
        }

        const regRows = Array.isArray(calc.crew_rows) ? calc.crew_rows : [];
        if (regRows.length > 0 && tbody) {
          const domRows = Array.from(tbody.querySelectorAll('tr.crew-row'));
          regRows.forEach(function (rr, i) {
            const roleName = rr.type || 'Sopir';
            const amtVal = parseRp(rr.amount || 0);
            const matchedEmp = existingByRole[roleName] && existingByRole[roleName].length
              ? existingByRole[roleName].shift()
              : '';
            if (i < domRows.length) {
              const rowEl = domRows[i];
              const tSel = rowEl.querySelector('.crew-type-select');
              const eSel = rowEl.querySelector('.crew-emp-select');
              const aDisp = rowEl.querySelector('.crew-amt-display');
              const aHid = rowEl.querySelector('.crew-amt-value');
              if (tSel) {
                if (!Array.from(tSel.options).some(o => o.value === roleName)) {
                  const opt = document.createElement('option');
                  opt.value = roleName;
                  opt.textContent = roleName;
                  tSel.appendChild(opt);
                }
                tSel.value = roleName;
              }
              if (aDisp) aDisp.value = fmtRp(amtVal) || '0';
              if (aHid) aHid.value = String(amtVal);
              if (matchedEmp && eSel) {
                if (eSel.tomselect) eSel.tomselect.setValue(matchedEmp, true);
                else eSel.value = matchedEmp;
                syncEmpMeta(rowEl);
              }
            } else {
              addCrewRow(roleName, matchedEmp, amtVal);
            }
          });
        }

        recalcCrewTotals();
        const regInfo = data.regulation || {};
        if (regSelect && regInfo.id && !regSelect.value) {
          regSelect.value = String(regInfo.id);
        }
        setRegMsg('text-success fw-semibold', 'Komisi berhasil dihitung: ' + (regInfo.note || regInfo.category_name || 'OK'));
      } catch (err) {
        setRegMsg('text-danger fw-semibold', 'Gagal menghubungi layanan peraturan komisi.');
      } finally {
        if (btnApplyReg) btnApplyReg.disabled = false;
      }
    }

    if (showBbm && bbmDisp && !bbmDisp.readOnly) {
      attachRp(bbmDisp, bbmVal, recalcCrewTotals);
    }
    if (showBbm && bbmVal) {
      bbmVal.addEventListener('change', function () {
        if (bbmDisp) bbmDisp.value = fmtRp(this.value) || '0';
        recalcCrewTotals();
      });
    }
    if (tbody) {
      tbody.querySelectorAll('tr.crew-row').forEach(bindCrewRow);
    }
    recalcCrewTotals();

    const btnAdd = document.getElementById('btnAddCrewRow');
    const btnFootAdd = document.getElementById('btnCrewFooterAdd');
    const btnFootRem = document.getElementById('btnCrewFooterRemove');
    if (btnAdd) btnAdd.addEventListener('click', function () { addCrewRow('Sopir', '', 0); });
    if (btnFootAdd) btnFootAdd.addEventListener('click', function () { addCrewRow('Sopir', '', 0); });
    if (btnFootRem) {
      btnFootRem.addEventListener('click', function () {
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr.crew-row');
        if (rows.length > 0) {
          const rmBtn = rows[rows.length - 1].querySelector('.btn-remove-crew');
          if (rmBtn) rmBtn.click();
        }
      });
    }
    if (btnApplyReg) {
      btnApplyReg.addEventListener('click', applyRegulationAuto);
    }

    return {
      recalcCrewTotals: recalcCrewTotals,
      addCrewRow: addCrewRow
    };
  }

  function initPaymentTab(opts) {
    const cfg = opts || {};
    const formId = cfg.formId || 'formTravelBooking';
    const isDetailMode = Boolean(cfg.isDetailMode);
    const tbody = document.getElementById('payTableBody');
    const totalDisp = document.getElementById('payTotalDisplay');
    const subtotalEl = document.getElementById('paySubtotalDisplay');
    const countEl = document.getElementById('payRecordCount');
    const overAlert = document.getElementById('payOverpayAlert');
    const overMsg = document.getElementById('payOverpayMsg');
    const remDispEl = cfg.remainingDisplayEl || document.getElementById('qrisRemainingDisplay');
    const todayIso = cfg.todayIso || new Date().toISOString().slice(0, 10);
    const taxPct = Number(cfg.taxPercent || 0);
    const origBookingPrice = Number(cfg.origBookingPrice || 0);
    const origInvoiceTotal = Number(cfg.invoiceTotal || 0);

    const payTypeOpts = ['Down Payment (DP)', 'Pelunasan', 'Pembayaran Penuh (Full)', 'Tambahan'];
    const payMethodOpts = ['Transfer', 'Tunai', 'QRIS', 'EDC / Kartu'];

    function getExpectedBillTotal() {
      const liveBill = typeof cfg.getBillTotal === 'function' ? parseRp(cfg.getBillTotal()) : 0;
      if (isDetailMode && origInvoiceTotal > 0 && taxPct !== 0 && Math.round(liveBill) === Math.round(origBookingPrice)) {
        return Math.round(origInvoiceTotal);
      }
      return Math.round(liveBill * (1 + (taxPct / 100)));
    }

    function getPaySum() {
      if (!tbody) return 0;
      let sum = 0;
      tbody.querySelectorAll('tr.pay-row .pay-amt-value').forEach(function (inp) {
        sum += parseRp(inp.value);
      });
      return sum;
    }

    function recalcPayTotals() {
      if (!tbody) return 0;
      const rows = tbody.querySelectorAll('tr.pay-row');
      let sum = 0;
      rows.forEach(function (tr, idx) {
        const num = tr.querySelector('.pay-row-num');
        if (num) num.textContent = String(idx + 1);
        const hv = tr.querySelector('.pay-amt-value');
        sum += hv ? parseRp(hv.value) : 0;
      });
      const sumFmt = fmtRp(sum) || '0';
      if (totalDisp) totalDisp.value = sumFmt;
      if (subtotalEl) {
        if (subtotalEl.tagName === 'INPUT') subtotalEl.value = sumFmt;
        else subtotalEl.textContent = 'Rp ' + sumFmt;
      }
      if (countEl) countEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;

      const billTotal = getExpectedBillTotal();
      const exceeded = billTotal > 0 ? (sum > billTotal + 0.001) : (sum > 0);
      const sisa = Math.round(billTotal - sum);

      if (remDispEl) {
        if (exceeded) {
          remDispEl.textContent = 'Melebihi Rp ' + (fmtRp(Math.abs(sisa)) || '0');
          remDispEl.className = 'text-danger fw-bold';
        } else {
          remDispEl.textContent = sisa > 0 ? ('Rp ' + (fmtRp(sisa) || '0')) : 'Lunas (Rp 0)';
          remDispEl.className = sisa > 0 ? 'text-danger fw-bold' : 'text-success fw-bold';
        }
      }

      if (overAlert && overMsg) {
        if (exceeded) {
          overMsg.textContent = 'Total pembayaran (Rp ' + sumFmt + ') tidak boleh melebihi ' +
            (isDetailMode ? 'total tagihan' : 'total harga') + ' (Rp ' + (fmtRp(billTotal) || '0') + ').';
          overAlert.classList.remove('d-none');
        } else {
          overAlert.classList.add('d-none');
          overMsg.textContent = '';
        }
      }
      [totalDisp, subtotalEl].forEach(function (el) {
        if (!el) return;
        el.classList.toggle('is-invalid', exceeded);
        el.classList.toggle('text-danger', exceeded);
      });
      if (typeof cfg.onChange === 'function') cfg.onChange(sum, billTotal);
      return sum;
    }

    function bindPayRow(tr) {
      if (!tr || tr.dataset.payBound === '1') return;
      tr.dataset.payBound = '1';

      const fileInp = tr.querySelector('.pay-file-input');
      const fileLbl = tr.querySelector('.pay-file-label');
      if (fileInp && fileLbl) {
        fileInp.addEventListener('change', function () {
          if (this.files && this.files.length > 0) {
            fileLbl.textContent = this.files[0].name;
            fileLbl.classList.remove('text-muted', 'btn', 'btn-sm', 'btn-outline-primary', 'py-0', 'px-2');
            fileLbl.classList.add('text-success', 'fw-semibold', 'small');
          }
        });
      }

      const dateInp = tr.querySelector('.flatpickr-date');
      if (dateInp && !dateInp.disabled) {
        initFlatpickrEl(dateInp);
      }

      const amtDisp = tr.querySelector('.pay-amt-display');
      const amtVal = tr.querySelector('.pay-amt-value');
      if (amtDisp && !amtDisp.readOnly) {
        attachRp(amtDisp, amtVal, recalcPayTotals);
      }

      const btnRem = tr.querySelector('.btn-remove-pay');
      if (btnRem) {
        btnRem.addEventListener('click', function () {
          if (!tbody) return;
          tr.remove();
          recalcPayTotals();
        });
      }
    }

    function addPayRow(defaultType, defaultMethod, defaultDate, defaultAmt) {
      if (!tbody || !cfg.canManage) return null;
      const pt = defaultType || 'Down Payment (DP)';
      const pm = defaultMethod || 'Transfer';
      const pd = defaultDate || todayIso;
      const amtNum = parseRp(defaultAmt || 0);

      const ptHtml = payTypeOpts.map(function (o) {
        return '<option value="' + escHtml(o) + '"' + (o === pt ? ' selected' : '') + '>' + escHtml(o) + '</option>';
      }).join('');
      const pmHtml = payMethodOpts.map(function (o) {
        return '<option value="' + escHtml(o) + '"' + (o === pm ? ' selected' : '') + '>' + escHtml(o) + '</option>';
      }).join('');

      const tr = document.createElement('tr');
      tr.className = 'pay-row';
      tr.setAttribute('data-payment-id', '0');
      tr.innerHTML = '' +
        '<td class="text-center text-muted pay-row-num">1</td>' +
        '<td>' +
        '<input type="hidden" name="pay_id[]" form="' + escHtml(formId) + '" value="0">' +
        '<div class="d-flex align-items-center gap-2">' +
        '<span class="text-muted small pay-file-label">No image data</span>' +
        '<label class="btn btn-sm btn-outline-secondary py-0 px-2 ms-auto mb-0" title="Pilih bukti pembayaran">' +
        '<i class="bi bi-paperclip"></i>' +
        '<input type="file" name="pay_proof[]" form="' + escHtml(formId) + '" class="d-none pay-file-input" accept="image/*,application/pdf">' +
        '</label>' +
        '</div>' +
        '</td>' +
        '<td><select class="form-select form-select-sm pay-type-select" name="pay_type[]" form="' + escHtml(formId) + '">' + ptHtml + '</select></td>' +
        '<td><select class="form-select form-select-sm pay-method-select" name="pay_method[]" form="' + escHtml(formId) + '">' + pmHtml + '</select></td>' +
        '<td><input type="text" class="form-control form-control-sm bg-white flatpickr-date pay-date-input" name="pay_date[]" form="' + escHtml(formId) + '" value="' + escHtml(pd) + '" placeholder="dd-MMM-yyyy"></td>' +
        '<td>' +
        '<input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="' + escHtml(fmtRp(amtNum) || '0') + '" placeholder="0">' +
        '<input type="hidden" name="pay_amount[]" class="pay-amt-value" form="' + escHtml(formId) + '" value="' + amtNum + '">' +
        '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-pay" title="Hapus baris"><i class="bi bi-x-lg"></i></button></td>';

      tbody.appendChild(tr);
      bindPayRow(tr);
      recalcPayTotals();
      return tr;
    }

    function appendAutoQrisRow(p) {
      if (!tbody || !p || !p.id) return false;
      if (tbody.querySelector('tr.pay-row[data-payment-id="' + Number(p.id) + '"]')) {
        return false;
      }
      const ptOpts = payTypeOpts.slice();
      if (p.pay_type && !ptOpts.includes(p.pay_type)) ptOpts.push(p.pay_type);
      const pmOpts = payMethodOpts.slice();
      if (p.pay_method && !pmOpts.includes(p.pay_method)) pmOpts.push(p.pay_method);

      const ptHtml = ptOpts.map(function (o) {
        return '<option value="' + escHtml(o) + '"' + (o === (p.pay_type || 'Pelunasan') ? ' selected' : '') + '>' + escHtml(o) + '</option>';
      }).join('');
      const pmHtml = pmOpts.map(function (o) {
        return '<option value="' + escHtml(o) + '"' + (o === (p.pay_method || 'QRIS') ? ' selected' : '') + '>' + escHtml(o) + '</option>';
      }).join('');

      const proofHtml = p.proof_url
        ? '<a href="' + escHtml(p.proof_url) + '" target="_blank" class="small text-decoration-none"><i class="bi bi-paperclip me-1"></i>Bukti</a>'
        : '';

      const tr = document.createElement('tr');
      tr.className = 'pay-row table-success';
      tr.setAttribute('data-payment-id', String(Number(p.id)));
      tr.innerHTML = '' +
        '<td class="text-center text-muted pay-row-num">1</td>' +
        '<td>' +
        '<input type="hidden" name="pay_id[]" form="' + escHtml(formId) + '" value="' + Number(p.id) + '">' +
        '<div class="d-flex flex-wrap align-items-center gap-2">' +
        '<span class="badge bg-success-subtle text-success border"><i class="bi bi-qr-code me-1"></i>Verified QRIS</span>' +
        proofHtml +
        (cfg.canManage ? '<input type="file" name="pay_proof[]" form="' + escHtml(formId) + '" class="d-none" tabindex="-1">' : '') +
        '</div>' +
        '</td>' +
        '<td><select class="form-select form-select-sm pay-type-select" disabled>' + ptHtml + '</select><input type="hidden" name="pay_type[]" form="' + escHtml(formId) + '" value="' + escHtml(p.pay_type || 'Pelunasan') + '"></td>' +
        '<td><select class="form-select form-select-sm pay-method-select" disabled>' + pmHtml + '</select><input type="hidden" name="pay_method[]" form="' + escHtml(formId) + '" value="' + escHtml(p.pay_method || 'QRIS') + '"></td>' +
        '<td><input type="text" class="form-control form-control-sm bg-light" value="' + escHtml(p.pay_date_fmt || p.pay_date || todayIso) + '" disabled><input type="hidden" name="pay_date[]" form="' + escHtml(formId) + '" value="' + escHtml(p.pay_date || todayIso) + '"></td>' +
        '<td>' +
        '<input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold pay-amt-display" value="' + escHtml(p.amount_fmt || fmtRp(p.amount || 0)) + '" readonly>' +
        '<input type="hidden" name="pay_amount[]" class="pay-amt-value" form="' + escHtml(formId) + '" value="' + Number(p.amount || 0) + '">' +
        '</td>' +
        (cfg.canManage ? '<td class="text-center"><i class="bi bi-lock-fill text-muted small" title="Pembayaran terverifikasi otomatis oleh QRIS"></i></td>' : '');

      tbody.appendChild(tr);
      recalcPayTotals();
      setTimeout(function () { tr.classList.remove('table-success'); }, 4000);
      return true;
    }

    if (tbody) {
      tbody.querySelectorAll('tr.pay-row').forEach(bindPayRow);
    }
    recalcPayTotals();

    const btnAdd = document.getElementById('btnAddPayRow');
    const btnFootAdd = document.getElementById('btnPayFooterAdd');
    const btnFootRem = document.getElementById('btnPayFooterRemove');
    if (btnAdd) btnAdd.addEventListener('click', function () { addPayRow('Down Payment (DP)', 'Transfer', todayIso, 0); });
    if (btnFootAdd) btnFootAdd.addEventListener('click', function () { addPayRow('Down Payment (DP)', 'Transfer', todayIso, 0); });
    if (btnFootRem) {
      btnFootRem.addEventListener('click', function () {
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr.pay-row');
        for (let i = rows.length - 1; i >= 0; i--) {
          const rmBtn = rows[i].querySelector('.btn-remove-pay');
          if (rmBtn) {
            rmBtn.click();
            break;
          }
        }
      });
    }

    return {
      recalcPayTotals: recalcPayTotals,
      addPayRow: addPayRow,
      appendAutoQrisRow: appendAutoQrisRow,
      getPaySum: getPaySum
    };
  }

  function initQrisController(opts) {
    const cfg = opts || {};
    const qrisModalEl = document.getElementById('qrisAutoModal');
    const btnPayQrisAuto = document.getElementById('btnPayQrisAuto');
    const qrisStepForm = document.getElementById('qrisStepForm');
    const qrisStepShow = document.getElementById('qrisStepShow');
    const qrisCreateErr = document.getElementById('qrisCreateErr');
    const qrisUnsavedWarn = document.getElementById('qrisUnsavedPriceWarn');
    const qrisFormSisa = document.getElementById('qrisFormSisaText');
    const qrisPayTypeSel = document.getElementById('qrisPayType');
    const qrisProvSel = document.getElementById('qrisChannelProvider');
    const qrisAmtDisp = document.getElementById('qrisAmountDisplay');
    const qrisAmtVal = document.getElementById('qrisAmountValue');
    const btnGenQris = document.getElementById('btnGenerateQris');
    const qrisRemDisp = document.getElementById('qrisRemainingDisplay');
    const qrisLiveBadge = document.getElementById('qrisLiveSyncBadge');
    const qrisNewBanner = document.getElementById('qrisAutoNewRowBanner');
    const qrisNewMsg = document.getElementById('qrisAutoNewRowMsg');

    let qrisInvTotal = Number(cfg.invoiceTotal || 0);
    let qrisServerPaid = Number(cfg.paidTotal || 0);
    let qrisBookStat = String(cfg.bookingStatus || 'draft');
    let qrisHasPending = Boolean(cfg.hasPending);
    let qrisCurrentOrderId = '';
    let qrisCurrentPayWebUrl = '';
    let qrisModalPollTimer = null;
    let qrisBgPollTimer = null;
    let qrisCountdownTimer = null;
    let qrisPollInFlight = false;

    function stopModalTimers() {
      if (qrisModalPollTimer) { clearInterval(qrisModalPollTimer); qrisModalPollTimer = null; }
      if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
    }

    function startCountdown(expiredTs) {
      if (qrisCountdownTimer) { clearInterval(qrisCountdownTimer); qrisCountdownTimer = null; }
      const cdEl = document.getElementById('qrisShowCountdown');
      if (!cdEl) return;
      if (!expiredTs) { cdEl.textContent = '±15 menit'; return; }
      function tick() {
        const left = Math.max(0, expiredTs - Math.floor(Date.now() / 1000));
        if (left <= 0) {
          cdEl.textContent = 'Kedaluwarsa';
          clearInterval(qrisCountdownTimer);
          qrisCountdownTimer = null;
          return;
        }
        const mm = String(Math.floor(left / 60)).padStart(2, '0');
        const ss = String(left % 60).padStart(2, '0');
        cdEl.textContent = mm + ':' + ss;
      }
      tick();
      qrisCountdownTimer = setInterval(tick, 1000);
    }

    function updateSummaryUi(data) {
      if (typeof data.invoice_total === 'number') qrisInvTotal = data.invoice_total;
      if (typeof data.paid_total === 'number') qrisServerPaid = data.paid_total;
      if (typeof data.has_pending === 'boolean') qrisHasPending = data.has_pending;
      if (data.booking_status) qrisBookStat = data.booking_status;

      const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
      if (qrisRemDisp) {
        qrisRemDisp.textContent = rem > 0 ? ('Rp ' + (fmtRp(rem) || '0')) : 'Lunas (Rp 0)';
        qrisRemDisp.className = rem > 0 ? 'fw-bold text-danger' : 'fw-bold text-success';
      }
      if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + (fmtRp(rem) || '0');
      if (btnPayQrisAuto) btnPayQrisAuto.disabled = (qrisBookStat === 'cancelled' || rem <= 0);
      if (data.booking_status_badge && typeof cfg.onStatusBadge === 'function') {
        cfg.onStatusBadge(data.booking_status_badge);
      }
      refreshBgPoller();
    }

    async function pollQris(orderIdOpt) {
      if (qrisPollInFlight || !cfg.bookingId) return null;
      qrisPollInFlight = true;
      try {
        const fd = new FormData();
        fd.append('csrf_token', cfg.csrfToken || '');
        fd.append('act', 'poll');
        fd.append('booking_type', cfg.bookingType || 'travel');
        fd.append('booking_id', String(cfg.bookingId));
        if (orderIdOpt) fd.append('order_id', orderIdOpt);

        const fetchOpts = { method: 'POST', body: fd };
        if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function') {
          fetchOpts.signal = AbortSignal.timeout(10000);
        }
        const res = await fetch(cfg.appUrl + '/pages/rental/bookings/qris_ajax.php', fetchOpts);
        const data = await res.json();
        if (!res.ok || !data || !data.ok) return null;

        let added = 0;
        if (Array.isArray(data.payments) && cfg.paymentCtrl) {
          data.payments.forEach(function (p) {
            if (p.is_auto_qris && cfg.paymentCtrl.appendAutoQrisRow(p)) {
              added++;
            }
          });
        }
        updateSummaryUi(data);

        if (added > 0 && qrisNewBanner) {
          if (qrisNewMsg) {
            qrisNewMsg.textContent = added + ' pembayaran QRIS baru otomatis terverifikasi dan ditambahkan ke tabel!';
          }
          qrisNewBanner.classList.remove('d-none');
        }

        const stBadge = document.getElementById('qrisShowStatusBadge');
        if (orderIdOpt && data.order_status === 'PAID') {
          stopModalTimers();
          if (stBadge) {
            stBadge.className = 'badge bg-success text-white';
            stBadge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Pembayaran Berhasil Diterima!';
          }
          setTimeout(function () {
            if (qrisModalEl && window.bootstrap && window.bootstrap.Modal) {
              const inst = window.bootstrap.Modal.getInstance(qrisModalEl);
              if (inst) inst.hide();
            }
          }, 1400);
        } else if (orderIdOpt && data.order_status === 'EXPIRED') {
          stopModalTimers();
          if (stBadge) {
            stBadge.className = 'badge bg-danger text-white';
            stBadge.innerHTML = '<i class="bi bi-x-circle me-1"></i>Kedaluwarsa';
          }
        }
        return data;
      } catch (e) {
        return null;
      } finally {
        qrisPollInFlight = false;
      }
    }

    function refreshBgPoller() {
      const payPane = document.getElementById('tab-payment');
      const tabActive = payPane && payPane.classList.contains('active');
      const shouldPoll = !document.hidden && !qrisCurrentOrderId && (qrisHasPending || tabActive);

      if (shouldPoll && !qrisBgPollTimer) {
        qrisBgPollTimer = setInterval(function () {
          if (!document.hidden) pollQris('');
        }, 6000);
      } else if (!shouldPoll && qrisBgPollTimer) {
        clearInterval(qrisBgPollTimer);
        qrisBgPollTimer = null;
      }

      if (qrisLiveBadge) {
        qrisLiveBadge.classList.toggle('d-none', !(qrisHasPending || tabActive));
      }
    }

    document.addEventListener('visibilitychange', refreshBgPoller);

    if (qrisAmtDisp && qrisAmtVal) {
      attachRp(qrisAmtDisp, qrisAmtVal);
    }

    document.querySelectorAll('.qris-preset-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const preset = btn.getAttribute('data-qris-preset');
        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        let val = rem;
        if (preset === 'dp50') {
          val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.5)));
          if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
        } else if (preset === 'dp30') {
          val = Math.min(rem, Math.max(1, Math.round(qrisInvTotal * 0.3)));
          if (qrisPayTypeSel) qrisPayTypeSel.value = 'Down Payment (DP)';
        } else if (qrisPayTypeSel) {
          qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
        }
        if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(val) || '0';
        if (qrisAmtVal) qrisAmtVal.value = String(val);
      });
    });

    if (btnPayQrisAuto && qrisModalEl) {
      btnPayQrisAuto.addEventListener('click', function () {
        stopModalTimers();
        qrisCurrentOrderId = '';
        qrisCurrentPayWebUrl = '';
        if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
        if (qrisUnsavedWarn && typeof cfg.getLivePrice === 'function') {
          const liveP = parseRp(cfg.getLivePrice());
          qrisUnsavedWarn.classList.toggle('d-none', Math.abs(liveP - Math.round(qrisInvTotal)) <= 1);
        }
        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));
        if (qrisFormSisa) qrisFormSisa.textContent = 'Rp ' + (fmtRp(rem) || '0');
        if (qrisPayTypeSel) qrisPayTypeSel.value = qrisServerPaid > 0 ? 'Pelunasan' : 'Pembayaran Penuh (Full)';
        if (qrisAmtDisp) qrisAmtDisp.value = fmtRp(rem) || '0';
        if (qrisAmtVal) qrisAmtVal.value = String(rem);
        if (qrisStepForm) qrisStepForm.classList.remove('d-none');
        if (qrisStepShow) qrisStepShow.classList.add('d-none');
        if (window.bootstrap && window.bootstrap.Modal) {
          window.bootstrap.Modal.getOrCreateInstance(qrisModalEl).show();
        }
      });

      qrisModalEl.addEventListener('hidden.bs.modal', function () {
        stopModalTimers();
        qrisCurrentOrderId = '';
        refreshBgPoller();
      });
    }

    if (btnGenQris) {
      btnGenQris.addEventListener('click', async function () {
        const amt = parseRp(qrisAmtVal ? qrisAmtVal.value : (qrisAmtDisp ? qrisAmtDisp.value : '0'));
        const pt = qrisPayTypeSel ? qrisPayTypeSel.value : 'Pelunasan';
        const prov = qrisProvSel ? qrisProvSel.value : '';
        const rem = Math.max(0, Math.round(qrisInvTotal - qrisServerPaid));

        if (qrisCreateErr) { qrisCreateErr.classList.add('d-none'); qrisCreateErr.textContent = ''; }
        if (rem <= 0 || amt <= 0 || amt > rem) {
          if (qrisCreateErr) {
            qrisCreateErr.textContent = rem <= 0
              ? 'Tagihan invoice ini sudah lunas.'
              : (amt <= 0 ? 'Masukkan nominal QRIS lebih dari Rp 0.' : 'Nominal QRIS (Rp ' + fmtRp(amt) + ') melebihi sisa tagihan (Rp ' + fmtRp(rem) + ').');
            qrisCreateErr.classList.remove('d-none');
          }
          return;
        }

        btnGenQris.disabled = true;
        const origHtml = btnGenQris.innerHTML;
        btnGenQris.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Membuat QRIS...';

        try {
          const fd = new FormData();
          fd.append('csrf_token', cfg.csrfToken || '');
          fd.append('act', 'create');
          fd.append('booking_type', cfg.bookingType || 'travel');
          fd.append('booking_id', String(cfg.bookingId));
          fd.append('pay_type', pt);
          fd.append('amount', String(amt));
          if (prov) fd.append('provider', prov);

          const fetchOpts = { method: 'POST', body: fd };
        if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function') {
          fetchOpts.signal = AbortSignal.timeout(10000);
        }
        const res = await fetch(cfg.appUrl + '/pages/rental/bookings/qris_ajax.php', fetchOpts);
          const data = await res.json();
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) || 'Gagal membuat transaksi QRIS.');
          }

          qrisCurrentOrderId = data.order_id || '';
          qrisCurrentPayWebUrl = data.pay_web_url || '';
          qrisHasPending = true;

          const imgEl = document.getElementById('qrisShowImg');
          if (imgEl) {
            const primarySrc = data.qris_data_uri || data.qris_image_url || data.fallback_qr_url || '';
            const fallbackSrc = data.qris_image_url || data.fallback_qr_url || '';
            imgEl.onerror = function () { if (fallbackSrc && this.src !== fallbackSrc) this.src = fallbackSrc; };
            imgEl.src = primarySrc;
          }

          const setTxt = function (id, txt) { const e = document.getElementById(id); if (e) e.textContent = txt; };
          setTxt('qrisShowChannelBadge', data.channel_name || 'QRIS');
          setTxt('qrisShowOrderId', qrisCurrentOrderId);
          setTxt('qrisShowPayType', data.pay_type || pt);
          setTxt('qrisShowBill', 'Rp ' + (fmtRp(Math.round(Number(data.bill_amount || amt))) || '0'));

          const feeRow = document.getElementById('qrisShowFeeRow');
          const feeNum = Math.round(Number(data.fee || 0));
          if (feeRow) {
            feeRow.classList.toggle('d-none', feeNum <= 0);
            setTxt('qrisShowFee', 'Rp ' + (fmtRp(feeNum) || '0'));
          }
          const uniqRow = document.getElementById('qrisShowUniqueRow');
          const uniqNum = Math.round(Number(data.unique_code || 0));
          if (uniqRow) {
            uniqRow.classList.toggle('d-none', uniqNum <= 0);
            setTxt('qrisShowUnique', '+Rp ' + (fmtRp(uniqNum) || '0'));
          }
          setTxt('qrisShowTotal', 'Rp ' + (fmtRp(Math.round(Number(data.total_amount || amt))) || '0'));

          const stBadge = document.getElementById('qrisShowStatusBadge');
          if (stBadge) {
            stBadge.className = 'badge bg-warning-subtle text-warning-emphasis border';
            stBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;" role="status"></span>Menunggu Pembayaran';
          }
          if (qrisStepForm) qrisStepForm.classList.add('d-none');
          if (qrisStepShow) qrisStepShow.classList.remove('d-none');
          startCountdown(data.expired_ts || null);
          qrisModalPollTimer = setInterval(function () { if (qrisCurrentOrderId) pollQris(qrisCurrentOrderId); }, 4000);
          refreshBgPoller();
        } catch (err) {
          if (qrisCreateErr) {
            qrisCreateErr.textContent = err.message || 'Terjadi kesalahan saat membuat QRIS.';
            qrisCreateErr.classList.remove('d-none');
          }
        } finally {
          btnGenQris.disabled = false;
          btnGenQris.innerHTML = origHtml;
        }
      });
    }

    const btnBack = document.getElementById('btnQrisBackToForm');
    if (btnBack) {
      btnBack.addEventListener('click', function () {
        stopModalTimers();
        qrisCurrentOrderId = '';
        if (qrisStepShow) qrisStepShow.classList.add('d-none');
        if (qrisStepForm) qrisStepForm.classList.remove('d-none');
      });
    }

    const btnCheckNow = document.getElementById('btnCheckQrisNow');
    if (btnCheckNow) {
      btnCheckNow.addEventListener('click', async function () {
        if (!qrisCurrentOrderId) return;
        btnCheckNow.disabled = true;
        await pollQris(qrisCurrentOrderId);
        btnCheckNow.disabled = false;
      });
    }

    const btnCopy = document.getElementById('btnCopyQrisLink');
    if (btnCopy) {
      btnCopy.addEventListener('click', async function () {
        if (!qrisCurrentPayWebUrl) return;
        const orig = btnCopy.innerHTML;
        try {
          await navigator.clipboard.writeText(qrisCurrentPayWebUrl);
          btnCopy.innerHTML = '<i class="bi bi-check2 me-1"></i>Tersalin';
          setTimeout(function () { btnCopy.innerHTML = orig; }, 1800);
        } catch (e) {
          window.prompt('Salin tautan pembayaran QRIS berikut:', qrisCurrentPayWebUrl);
        }
      });
    }

    if (qrisHasPending) refreshBgPoller();
    return { refreshBgPoller: refreshBgPoller, pollQris: pollQris };
  }

  function initFormTabValidation(opts) {
    const cfg = opts || {};
    const form = cfg.formId ? document.getElementById(cfg.formId) : null;
    if (!form) return;

    function focusInvalidElement(el) {
      if (!el) return;
      const pane = el.closest('.tab-pane');
      if (pane && !pane.classList.contains('active')) {
        const btn = getTabButtonForPane(pane);
        if (btn && window.bootstrap && window.bootstrap.Tab) {
          window.bootstrap.Tab.getOrCreateInstance(btn).show();
        }
      }
      setTimeout(function () {
        if (el.tomselect) {
          try { el.tomselect.focus(); } catch (e) {}
        } else if (typeof el.focus === 'function') {
          try { el.focus(); } catch (e) {}
        }
      }, 60);
    }

    form.addEventListener('invalid', function (e) {
      focusInvalidElement(e.target);
    }, true);

    form.addEventListener('submit', function (e) {
      const reqEls = form.querySelectorAll('[required]');
      for (let i = 0; i < reqEls.length; i++) {
        const el = reqEls[i];
        if (el.disabled) continue;
        const v = String(getFieldVal(el) || '').trim();
        if (!v) {
          e.preventDefault();
          focusInvalidElement(el);
          if (typeof cfg.onValidationError === 'function') {
            cfg.onValidationError('Lengkapi seluruh kolom wajib pada tab Detail terlebih dahulu.');
          }
          return false;
        }
      }

      if (cfg.paymentCtrl) {
        const pSum = cfg.paymentCtrl.getPaySum();
        const bill = typeof cfg.getBillTotal === 'function' ? parseRp(cfg.getBillTotal()) : 0;
        if (pSum > 0 && pSum > bill) {
          e.preventDefault();
          showTabById('tab-payment');
          return false;
        }
      }

      if (typeof cfg.beforeSubmit === 'function') {
        const res = cfg.beforeSubmit();
        if (res && res.ok === false) {
          e.preventDefault();
          if (res.tabId) showTabById(res.tabId);
          if (res.focusEl) focusInvalidElement(res.focusEl);
          return false;
        }
      }
      return true;
    });
  }

  function initCategoryVehicleFilter(opts) {
    const cfg = opts || {};
    const catEl = cfg.categorySelectEl;
    const vehEl = cfg.vehicleSelectEl;
    if (!catEl || !vehEl) return;

    catEl.addEventListener('change', async function () {
      const catVal = getFieldVal(catEl);
      if (typeof window.setTsOptions === 'function') {
        try {
          const qs = new URLSearchParams({
            company_id: String(cfg.companyId || 0),
            category_id: String(catVal || ''),
            limit: '50'
          });
          const res = await fetch((cfg.appUrl || '') + '/pages/vehicles/options.php?' + qs.toString());
          const data = await res.json();
          window.setTsOptions(vehEl, data, '', cfg.placeholder || '-- Pilih Kendaraan --');
        } catch (e) {}
      }
      if (typeof cfg.onChanged === 'function') cfg.onChanged();
    });
  }

  function initGeoCascade(opts) {
    const cfg = opts || {};
    const provEl = cfg.provinceEl;
    const cityEl = cfg.cityEl;
    const distEl = cfg.districtEl;

    function syncProvinceFromCity() {
      if (!provEl || !cityEl) return;
      const cityVal = String(getFieldVal(cityEl) || '');
      if (!cityVal) return;
      let pId = '';
      let pName = '';
      if (cityEl.tomselect && cityEl.tomselect.options && cityEl.tomselect.options[cityVal]) {
        const meta = cityEl.tomselect.options[cityVal];
        if (meta.province_id) pId = String(meta.province_id);
        if (meta.province_name) pName = String(meta.province_name);
      }
      if (!pId && cityEl.selectedIndex >= 0) {
        const opt = cityEl.options[cityEl.selectedIndex];
        if (opt && opt.dataset && opt.dataset.province) {
          pId = String(opt.dataset.province);
        }
      }
      if (!pId || String(getFieldVal(provEl) || '') === pId) return;
      if (provEl.tomselect && !provEl.tomselect.options[pId] && pName) {
        provEl.tomselect.addOption({ value: pId, id: pId, text: pName });
      }
      if (typeof window.setTsValueSilent === 'function') {
        window.setTsValueSilent(provEl, pId);
      } else {
        provEl.value = pId;
      }
    }

    async function reloadCities() {
      if (!cityEl || typeof window.setTsOptions !== 'function') return;
      const provVal = String(getFieldVal(provEl) || '');
      if (distEl) window.setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
      try {
        let qs = provVal ? ('limit=100&province_id=' + encodeURIComponent(provVal)) : 'limit=50&all=1';
        if (cityEl.dataset && cityEl.dataset.tsWithProvince === '1') {
          qs += '&with_province=1';
        }
        const res = await fetch((cfg.appUrl || '') + '/pages/masters/cities/options.php?' + qs);
        const data = await res.json();
        window.setTsOptions(cityEl, data, '', '-- Pilih Kota/Kab --');
      } catch (e) {}
    }

    async function reloadDistricts() {
      if (!distEl || typeof window.setTsOptions !== 'function') return;
      const cityVal = String(getFieldVal(cityEl) || '');
      if (!cityVal) {
        window.setTsOptions(distEl, [], '', '-- Pilih Kecamatan --');
        return;
      }
      try {
        const res = await fetch((cfg.appUrl || '') + '/pages/masters/districts/options.php?limit=100&city_id=' + encodeURIComponent(cityVal));
        const data = await res.json();
        window.setTsOptions(distEl, data, '', '-- Pilih Kecamatan --');
      } catch (e) {}
    }

    if (provEl) {
      provEl.addEventListener('change', function () {
        reloadCities();
        if (typeof cfg.onCityChange === 'function') cfg.onCityChange();
      });
    }
    if (cityEl) {
      cityEl.addEventListener('change', function () {
        syncProvinceFromCity();
        reloadDistricts();
        if (typeof cfg.onCityChange === 'function') cfg.onCityChange();
      });
    }
  }

  function initTravelRouteLookup(opts) {
    const cfg = opts || {};
    const pCityEl = cfg.pickupCityEl;
    const dCityEl = cfg.deliveryCityEl;
    const pDistEl = cfg.pickupDistrictEl || null;
    const dDistEl = cfg.deliveryDistrictEl || null;
    const catEl = cfg.categoryEl;
    const primEl = cfg.primaryPassengerEl;
    const extraEl = cfg.extraPassengersEl;
    const priceHint = cfg.hintEl;
    const btnApply = cfg.applyBtnEl;
    const priceDisp = cfg.priceDisplayEl;
    const priceVal = cfg.priceValueEl;
    const schedBox = cfg.scheduleContainerEl || null;
    const timeEl = cfg.timeInputEl || null;
    const routeCache = {};
    let routeReqSeq = 0;
    let lastMatchedRoute = null;
    let boundsWarnEl = null;

    if (priceHint && priceHint.parentNode) {
      boundsWarnEl = document.createElement('div');
      boundsWarnEl.className = 'text-danger small mt-1 d-none';
      priceHint.parentNode.insertBefore(boundsWarnEl, priceHint.nextSibling);
    }

    function syncScheduleHighlight() {
      if (!schedBox || !timeEl) return;
      const cur = String(timeEl.value || '').slice(0, 5);
      schedBox.querySelectorAll('.btn-route-slot').forEach(function (b) {
        const hit = b.getAttribute('data-slot') === cur;
        b.classList.toggle('btn-primary', hit);
        b.classList.toggle('btn-outline-primary', !hit);
      });
    }

    function renderSchedules(slots) {
      if (!schedBox) return;
      if (!Array.isArray(slots) || !slots.length) {
        schedBox.innerHTML = '';
        schedBox.classList.remove('d-flex');
        schedBox.classList.add('d-none');
        return;
      }
      const isRo = !timeEl || timeEl.readOnly || timeEl.disabled;
      let html = '<span class="small text-muted me-1">Jadwal Rute:</span>';
      slots.forEach(function (s) {
        html += '<button type="button" class="btn btn-sm btn-outline-primary font-monospace py-0 px-2 btn-route-slot" data-slot="' +
          escHtml(s) + '"' + (isRo ? ' disabled' : '') + '>' + escHtml(s) + '</button>';
      });
      schedBox.innerHTML = html;
      schedBox.classList.remove('d-none');
      schedBox.classList.add('d-flex');
      schedBox.querySelectorAll('.btn-route-slot').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!timeEl || timeEl.readOnly || timeEl.disabled) return;
          timeEl.value = b.getAttribute('data-slot') || '';
          timeEl.dispatchEvent(new Event('change', { bubbles: true }));
          syncScheduleHighlight();
        });
      });
      syncScheduleHighlight();
    }

    if (timeEl && schedBox) {
      timeEl.addEventListener('change', syncScheduleHighlight);
      timeEl.addEventListener('input', syncScheduleHighlight);
    }

    function getPassengerCount() {
      const primVal = String(getFieldVal(primEl) || '');
      const rawExtra = getFieldVal(extraEl);
      const extArr = Array.isArray(rawExtra) ? rawExtra : (rawExtra ? [rawExtra] : []);
      const extCnt = extArr.filter(function (x) { return x && String(x) !== primVal; }).length;
      return Math.max(1, (primVal ? 1 : 0) + extCnt);
    }

    function getRadiusExtra(routeObj) {
      if (!routeObj) return { pickup: 0, delivery: 0, total: 0 };
      const pExtra = Math.round(parseFloat(routeObj.pickup_district_extra) || 0);
      const dExtra = Math.round(parseFloat(routeObj.delivery_district_extra) || 0);
      return { pickup: pExtra, delivery: dExtra, total: pExtra + dExtra };
    }

    function validatePriceBounds() {
      const catVal = catEl ? (parseInt(getFieldVal(catEl) || '0', 10) || 0) : 0;
      const applicable = Boolean(lastMatchedRoute && (catVal === 0 || lastMatchedRoute.category_matched !== false));
      let msg = '';
      if (applicable && priceVal && (!priceDisp || !priceDisp.readOnly)) {
        const passCount = getPassengerCount();
        const curVal = parseRp(priceVal.value);
        const minUnit = parseFloat(lastMatchedRoute.min_price) || 0;
        const maxUnit = parseFloat(lastMatchedRoute.max_price) || 0;
        const radEx = getRadiusExtra(lastMatchedRoute);
        const minTotal = minUnit > 0 ? (Math.round(passCount * minUnit) + radEx.total) : 0;
        const maxTotal = maxUnit > 0 ? (Math.round(passCount * maxUnit) + radEx.total) : 0;
        const radNote = radEx.total > 0 ? (' + radius Rp ' + (fmtRp(radEx.total) || '0')) : '';
        if (minTotal > 0 && curVal < minTotal) {
          msg = 'Di bawah Harga Min rute (' + passCount + ' x Rp ' + (fmtRp(Math.round(minUnit)) || '0') + radNote + ' = Rp ' + (fmtRp(minTotal) || '0') + ').';
        } else if (maxTotal > 0 && curVal > maxTotal) {
          msg = 'Melebihi Harga Maks rute (' + passCount + ' x Rp ' + (fmtRp(Math.round(maxUnit)) || '0') + radNote + ' = Rp ' + (fmtRp(maxTotal) || '0') + ').';
        }
      }
      if (priceDisp) priceDisp.classList.toggle('is-invalid', msg !== '');
      if (boundsWarnEl) {
        if (msg !== '') {
          boundsWarnEl.innerHTML = '<i class="bi bi-exclamation-octagon me-1"></i>' + escHtml(msg);
          boundsWarnEl.classList.remove('d-none');
        } else {
          boundsWarnEl.textContent = '';
          boundsWarnEl.classList.add('d-none');
        }
      }
      return { ok: msg === '', message: msg, focusEl: priceDisp };
    }

    function renderEstimate(matched, forceApply) {
      lastMatchedRoute = matched || null;
      renderSchedules(matched ? matched.schedules : null);
      if (!priceHint) return;
      if (!matched) {
        priceHint.textContent = cfg.notFoundText || 'Tidak ditemukan tarif rute aktif untuk pasangan kota ini.';
        priceHint.className = 'form-text text-muted small mt-1';
        priceHint.style.display = 'block';
        if (btnApply) btnApply.style.display = 'none';
        validatePriceBounds();
        return;
      }
      const passCount = getPassengerCount();
      const unitPrice = parseFloat(matched.price) || 0;
      const minUnit = parseFloat(matched.min_price) || 0;
      const maxUnit = parseFloat(matched.max_price) || 0;
      const radEx = getRadiusExtra(matched);
      const estTotal = Math.round(passCount * unitPrice) + radEx.total;
      const minTotal = minUnit > 0 ? (Math.round(passCount * minUnit) + radEx.total) : 0;
      const maxTotal = maxUnit > 0 ? (Math.round(passCount * maxUnit) + radEx.total) : 0;

      const radParts = [];
      if (radEx.pickup > 0) radParts.push('Jemput +Rp ' + escHtml(fmtRp(radEx.pickup) || '0'));
      if (radEx.delivery > 0) radParts.push('Antar +Rp ' + escHtml(fmtRp(radEx.delivery) || '0'));
      const radiusExpr = radEx.total > 0 ? (' + radius Rp ' + escHtml(fmtRp(radEx.total) || '0')) : '';
      const radiusDetail = radParts.length ? (' <span class="text-muted">(' + radParts.join(', ') + ')</span>') : '';

      let boundsHtml = '';
      if (minTotal > 0 || maxTotal > 0) {
        const rangeParts = [];
        if (minTotal > 0) rangeParts.push('Min Rp ' + escHtml(fmtRp(minTotal) || '0'));
        if (maxTotal > 0) rangeParts.push('Maks Rp ' + escHtml(fmtRp(maxTotal) || '0'));
        boundsHtml = ' <span class="text-muted ms-1">&middot; Batas: ' + rangeParts.join(' &ndash; ') + '</span>';
      }
      const catVal = catEl ? (parseInt(getFieldVal(catEl) || '0', 10) || 0) : 0;
      const uncatNote = (catVal > 0 && matched.category_matched === false)
        ? ' <span class="text-muted">(tarif kategori terpilih belum diatur khusus di rute ini)</span>'
        : '';

      const outZones = [];
      if (pDistEl && parseInt(getFieldVal(pDistEl) || '0', 10) > 0 && matched.pickup_district_in_radius === false) {
        outZones.push('penjemputan');
      }
      if (dDistEl && parseInt(getFieldVal(dDistEl) || '0', 10) > 0 && matched.delivery_district_in_radius === false) {
        outZones.push('pengantaran');
      }
      const outZoneNote = outZones.length
        ? ' <span class="text-warning-emphasis ms-1"><i class="bi bi-info-circle me-1"></i>Kecamatan ' + escHtml(outZones.join(' & ')) + ' di luar daftar radius standar (biaya menyesuaikan).</span>'
        : '';

      priceHint.className = 'form-text small mt-1';
      priceHint.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-calculator me-1"></i>Estimasi (' +
        escHtml(matched.name) + '): ' + passCount + ' penumpang &times; Rp ' + escHtml(fmtRp(Math.round(unitPrice)) || '0') +
        radiusExpr + ' = Rp ' + escHtml(fmtRp(estTotal) || '0') + '</span>' + radiusDetail + boundsHtml + uncatNote + outZoneNote;
      priceHint.style.display = 'block';
      if (btnApply) btnApply.style.display = 'inline-block';

      const curVal = priceVal ? parseRp(priceVal.value) : 0;
      const shouldSet = forceApply || (cfg.autoFillZero && curVal <= 0);
      if (shouldSet && priceVal && priceDisp && !priceDisp.readOnly) {
        priceVal.value = String(estTotal);
        priceDisp.value = fmtRp(estTotal) || '0';
        if (typeof cfg.onPriceApplied === 'function') cfg.onPriceApplied(estTotal);
      }
      validatePriceBounds();
    }

    function lookup(forceApply) {
      if (!pCityEl || !dCityEl) return;
      const pcVal = parseInt(getFieldVal(pCityEl) || '0', 10) || 0;
      const dcVal = parseInt(getFieldVal(dCityEl) || '0', 10) || 0;
      const pdVal = pDistEl ? (parseInt(getFieldVal(pDistEl) || '0', 10) || 0) : 0;
      const ddVal = dDistEl ? (parseInt(getFieldVal(dDistEl) || '0', 10) || 0) : 0;
      const catVal = catEl ? (parseInt(getFieldVal(catEl) || '0', 10) || 0) : 0;
      if (!pcVal || !dcVal) {
        lastMatchedRoute = null;
        renderSchedules(null);
        if (priceHint) priceHint.style.display = 'none';
        if (btnApply) btnApply.style.display = 'none';
        validatePriceBounds();
        return;
      }
      const key = pcVal + '-' + dcVal + '-' + pdVal + '-' + ddVal + '-' + catVal;
      if (Object.prototype.hasOwnProperty.call(routeCache, key)) {
        renderEstimate(routeCache[key], forceApply);
        return;
      }
      const seq = ++routeReqSeq;
      const baseLookup = cfg.lookupUrl || ((cfg.appUrl || '') + '/pages/travel/rutes/lookup.php');
      const url = baseLookup + '?company_id=' + Number(cfg.companyId || 0) +
        '&pickup_point_city_id=' + pcVal + '&delivery_point_city_id=' + dcVal +
        (pdVal > 0 ? '&pickup_point_district_id=' + pdVal : '') +
        (ddVal > 0 ? '&delivery_point_district_id=' + ddVal : '') +
        (catVal > 0 ? '&category_vehicle_id=' + catVal : '');
      fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          const matched = (res && res.ok) ? (res.route || null) : null;
          routeCache[key] = matched;
          if (seq !== routeReqSeq) return;
          renderEstimate(matched, forceApply);
        })
        .catch(function () {});
    }

    if (pDistEl) pDistEl.addEventListener('change', function () { lookup(false); });
    if (dDistEl) dDistEl.addEventListener('change', function () { lookup(false); });

    if (priceDisp) priceDisp.addEventListener('input', validatePriceBounds);
    if (priceVal) priceVal.addEventListener('change', validatePriceBounds);

    if (btnApply) {
      btnApply.addEventListener('click', function (e) {
        e.preventDefault();
        lookup(true);
      });
    }

    return { lookup: lookup, validateBounds: validatePriceBounds };
  }

  function initQuickPassengerModal(opts) {
    const cfg = opts || {};
    const formEl = cfg.formId ? document.getElementById(cfg.formId) : null;
    if (!formEl) return;

    formEl.addEventListener('submit', async function (e) {
      e.preventDefault();
      const alertEl = cfg.alertId ? document.getElementById(cfg.alertId) : null;
      const btnSave = cfg.saveBtnId ? document.getElementById(cfg.saveBtnId) : null;
      const nameEl  = cfg.nameInputId ? document.getElementById(cfg.nameInputId) : null;
      const phoneEl = cfg.phoneInputId ? document.getElementById(cfg.phoneInputId) : null;
      const emailEl = cfg.emailInputId ? document.getElementById(cfg.emailInputId) : null;

      if (alertEl) alertEl.classList.add('d-none');
      if (btnSave) btnSave.disabled = true;

      try {
        const fd = new FormData();
        fd.append('csrf_token', cfg.csrfToken || '');
        fd.append('company_id', String(cfg.companyId || 0));
        fd.append('name', nameEl ? nameEl.value : '');
        fd.append('phone_number', phoneEl ? phoneEl.value : '');
        fd.append('email', emailEl ? emailEl.value : '');

        const res = await fetch((cfg.appUrl || '') + '/pages/transport/passengers/quick_create.php', {
          method: 'POST',
          body: fd
        });
        const json = await res.json();
        if (!res.ok || !json || !json.ok || !json.data || !json.data.id) {
          throw new Error((json && json.error) || 'Gagal menambah pelanggan.');
        }
        const pd = json.data;
        const optObj = { value: String(pd.id), id: pd.id, text: pd.label || pd.name };

        const primSel = cfg.primarySelectEl;
        const extSel  = cfg.extraSelectEl;
        [primSel, extSel].forEach(function (sel) {
          if (!sel) return;
          if (sel.tomselect) {
            sel.tomselect.addOption(optObj);
          } else {
            sel.add(new Option(optObj.text, optObj.value));
          }
        });

        if (primSel) {
          if (primSel.tomselect) primSel.tomselect.setValue(optObj.value);
          else primSel.value = optObj.value;
          primSel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        formEl.reset();
        const modalEl = cfg.modalId ? document.getElementById(cfg.modalId) : null;
        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
          window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }
      } catch (err) {
        if (alertEl) {
          alertEl.textContent = err.message || 'Gagal menambah pelanggan.';
          alertEl.classList.remove('d-none');
        }
      } finally {
        if (btnSave) btnSave.disabled = false;
      }
    });
  }

  window.BookingErp = {
    escHtml: escHtml,
    parseRp: parseRp,
    fmtRp: fmtRp,
    initFlatpickr: initFlatpickrEl,
    initTabs: initTabs,
    bindTimeBadge: bindTimeBadge,
    initVehiclePreview: initVehiclePreview,
    initCrewTab: initCrewTab,
    initPaymentTab: initPaymentTab,
    initQrisController: initQrisController,
    initFormTabValidation: initFormTabValidation,
    initCategoryVehicleFilter: initCategoryVehicleFilter,
    initGeoCascade: initGeoCascade,
    initTravelRouteLookup: initTravelRouteLookup,
    initQuickPassengerModal: initQuickPassengerModal
  };
})(window, document);
