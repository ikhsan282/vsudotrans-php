<?php
/**
 * Panel "Isi Cepat dengan AI" — include di dalam atau tepat di atas <form> booking.
 * Tersedia otomatis dengan fallback parser lokal bila LLM server belum dikonfigurasi/offline.
 */
$ai_endpoint    = $ai_endpoint ?? ''; // wajib diset sebelum include
$ai_placeholder = $ai_placeholder ?? 'Contoh: besok jam 08:00 jemput Cipondoh ke Bandung, pelanggan Budi, harga 250rb';
if ($ai_endpoint === '') {
    return;
}
?>
<div class="card mb-4 border-primary-subtle">
    <div class="card-body py-3">
        <div class="d-flex align-items-center mb-2">
            <i class="bi bi-stars text-primary me-2"></i><strong>Isi Cepat dengan AI</strong>
            <span class="text-muted small ms-2">ketik bebas — hasilnya muncul di Preview lalu klik Isi Form</span>
        </div>
        <div class="row g-2">
            <div class="col">
                <input type="text" class="form-control" id="ai-input"
                       placeholder="<?= e($ai_placeholder) ?>" maxlength="1000">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-primary" id="ai-btn">Proses</button>
            </div>
        </div>
        <div id="ai-status" class="small text-muted mt-1"></div>
        <div id="ai-loading-skel" class="vst-ai-panel-skel mt-2 d-none" aria-hidden="true">
            <div class="vst-ai-panel-skel-row"><span class="vst-skel vst-skel-line" style="width:92px"></span><span class="vst-skel vst-skel-line" style="flex:1;max-width:240px"></span></div>
            <div class="vst-ai-panel-skel-row"><span class="vst-skel vst-skel-line" style="width:108px"></span><span class="vst-skel vst-skel-line" style="flex:1;max-width:190px"></span></div>
            <div class="vst-ai-panel-skel-row"><span class="vst-skel vst-skel-line" style="width:84px"></span><span class="vst-skel vst-skel-line" style="flex:1;max-width:210px"></span></div>
            <div class="vst-ai-panel-skel-row"><span class="vst-skel vst-skel-line" style="width:96px"></span><span class="vst-skel vst-skel-line" style="flex:1;max-width:165px"></span></div>
        </div>
        <div id="ai-preview" class="mt-2 d-none">
            <ul id="ai-preview-list" class="list-group list-group-flush small"></ul>
            <button type="button" class="btn btn-sm btn-primary mt-2" id="ai-apply">Isi Form</button>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="ai-cancel">Tutup</button>
        </div>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('ai-btn');
    var inp = document.getElementById('ai-input');
    var st = document.getElementById('ai-status');
    var prev = document.getElementById('ai-preview');
    var skel = document.getElementById('ai-loading-skel');
    var list = document.getElementById('ai-preview-list');
    var apply = document.getElementById('ai-apply');
    var cancel = document.getElementById('ai-cancel');
    var labels = null; // id -> label dari server
    var detectedNewPax = null; // { name, phone } jika pelanggan belum terdaftar
    var geoFields = {
        pickup_point_province_id: true,
        pickup_point_city_id: true,
        pickup_point_district_id: true,
        delivery_point_province_id: true,
        delivery_point_city_id: true,
        delivery_point_district_id: true
    };

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function tampil(fields) {
        list.innerHTML = '';
        var map = labels || {};
        var namaF = {
            branch_id: 'Cabang', passenger_id: 'Pelanggan', category_vehicle_id: 'Kategori',
            vehicle_id: 'Kendaraan',
            pickup_point_province_id: 'Provinsi Asal', pickup_point_city_id: 'Kota/Kab Asal', pickup_point_district_id: 'Kecamatan Asal',
            delivery_point_province_id: 'Provinsi Tujuan', delivery_point_city_id: 'Kota/Kab Tujuan', delivery_point_district_id: 'Kecamatan Tujuan',
            date: 'Tanggal', start_date: 'Mulai Sewa', end_date: 'Selesai Sewa', duration_days: 'Durasi (Hari)',
            time: 'Jam', pickup_address: 'Alamat Jemput', delivery_address: 'Alamat Tujuan',
            total_price: 'Total Harga', trip: 'Trip (km)', bbm: 'BBM', note: 'Catatan'
        };
        var keys = Object.keys(fields).filter(function (k) { return fields[k] !== '' && fields[k] != null; });
        var hasNewPax = Boolean(detectedNewPax && detectedNewPax.name && !fields.passenger_id);
        if (!keys.length && !hasNewPax) {
            st.textContent = 'Tidak ada field yang bisa dipastikan — isi manual atau tambahkan detail.';
            return;
        }
        if (hasNewPax) {
            var np = detectedNewPax;
            var liPax = document.createElement('li');
            liPax.className = 'list-group-item py-1 d-flex flex-wrap align-items-center justify-content-between gap-2 bg-warning-subtle';
            liPax.id = 'ai-new-pax-item';
            var paxInfo = '<div><strong>Pelanggan Baru Terdeteksi:</strong> ' + esc(np.name) +
                (np.phone ? ' <span class="text-muted">(' + esc(np.phone) + ')</span>' : '') + '</div>';
            var paxBtn = '<button type="button" class="btn btn-sm btn-success py-0 px-2" id="ai-create-pax-btn">' +
                '<i class="bi bi-person-plus-fill me-1"></i>+ Buat &amp; Pilih Pelanggan Baru</button>';
            liPax.innerHTML = paxInfo + paxBtn;
            list.appendChild(liPax);

            var createBtn = liPax.querySelector('#ai-create-pax-btn');
            if (createBtn) {
                createBtn.addEventListener('click', function () {
                    createAndSelectNewPassenger(np, liPax, createBtn, fields);
                });
            }
        }
        keys.forEach(function (k) {
            var v = fields[k];
            if (map[k] && map[k][v] !== undefined) v = map[k][v];
            if ((k === 'total_price' || k === 'bbm') && window.formatRupiah) {
                v = 'Rp ' + (window.formatRupiah(v) || v);
            }
            var li = document.createElement('li');
            li.className = 'list-group-item py-1';
            li.innerHTML = '<strong>' + esc(namaF[k] || k) + ':</strong> ' + esc(v);
            list.appendChild(li);
        });
        prev.classList.remove('d-none');
        apply.__fields = fields;
    }

    function createAndSelectNewPassenger(np, liEl, btnEl, fieldsObj) {
        if (!np || !np.name) return Promise.resolve(null);
        if (btnEl) {
            btnEl.disabled = true;
            if (window.vstLoader && window.vstLoader.setButtonLoading) {
                window.vstLoader.setButtonLoading(btnEl, 'Menyimpan…');
            } else {
                btnEl.innerHTML = '<span class="vst-btn-dot me-2" aria-hidden="true"></span>Menyimpan…';
            }
        }
        var csrfT = document.querySelector('input[name="csrf_token"]');
        var compEl = document.querySelector('[data-ts-company]') || document.querySelector('input[name="company_id"]');
        var compId = compEl ? (compEl.getAttribute('data-ts-company') || compEl.value || '') : '';
        var fd = new FormData();
        fd.append('name', np.name);
        fd.append('phone_number', np.phone || '');
        if (csrfT) fd.append('csrf_token', csrfT.value);
        if (compId) fd.append('company_id', compId);

        return fetch('<?= e(APP_URL) ?>/pages/transport/passengers/quick_create.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok || !res.data || !res.data.id) {
                    throw new Error((res && res.error) || 'Gagal membuat pelanggan baru.');
                }
                var pd = res.data;
                var sId = String(pd.id);
                var lbl = pd.label || (pd.name + (pd.phone_number ? ' - ' + pd.phone_number : ''));
                [document.querySelector('[name="passenger_id"]'), document.querySelector('[name="passenger_ids[]"]')].forEach(function (sel) {
                    if (!sel) return;
                    if (sel.tomselect) {
                        sel.tomselect.addOption({ value: sId, id: pd.id, text: lbl });
                    } else if (sel.tagName === 'SELECT') {
                        sel.add(new Option(lbl, sId));
                    }
                });
                var prim = document.querySelector('[name="passenger_id"]');
                if (prim) {
                    if (prim.tomselect) prim.tomselect.setValue(sId);
                    else prim.value = sId;
                    prim.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (fieldsObj) fieldsObj.passenger_id = pd.id;
                if (!labels) labels = {};
                if (!labels.passenger_id) labels.passenger_id = {};
                labels.passenger_id[pd.id] = lbl;
                detectedNewPax = null;
                if (liEl) {
                    liEl.className = 'list-group-item py-1 d-flex flex-wrap align-items-center justify-content-between gap-2 bg-success-subtle';
                    liEl.innerHTML = '<div><strong>Pelanggan:</strong> ' + esc(lbl) + '</div>' +
                        '<span class="badge bg-success text-white"><i class="bi bi-check2-circle me-1"></i>Pelanggan Baru Tersimpan &amp; Terpilih</span>';
                }
                return pd;
            })
            .catch(function (err) {
                if (btnEl) {
                    if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                        window.vstLoader.clearButtonLoading(btnEl);
                    } else {
                        btnEl.innerHTML = '<i class="bi bi-person-plus-fill me-1"></i>+ Buat &amp; Pilih Pelanggan Baru';
                    }
                    btnEl.disabled = false;
                }
                st.textContent = err.message || 'Gagal menambah pelanggan baru.';
                return null;
            });
    }

    btn.addEventListener('click', function () {
        var teks = (inp.value || '').trim();
        if (!teks) { st.textContent = 'Tulis dulu permintaannya.'; return; }
        btn.disabled = true; prev.classList.add('d-none');
        if (skel) skel.classList.remove('d-none');
        st.textContent = '';
        if (window.vstLoader && window.vstLoader.setButtonLoading) {
            window.vstLoader.setButtonLoading(btn, 'Memproses…');
        }
        var csrfT = document.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('text', teks);
        if (csrfT) fd.append('csrf_token', csrfT.value);
        fetch('<?= e($ai_endpoint) ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
            .then(function (o) {
                if (skel) skel.classList.add('d-none');
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(btn);
                }
                btn.disabled = false;
                if (!o.j.ok) { st.textContent = o.j.error || ('Gagal (HTTP ' + o.s + ')'); return; }
                labels = o.j.peta || {};
                detectedNewPax = o.j.new_passenger || null;
                st.textContent = '';
                tampil(o.j.candidate || {});
            })
            .catch(function () {
                if (skel) skel.classList.add('d-none');
                if (window.vstLoader && window.vstLoader.clearButtonLoading) {
                    window.vstLoader.clearButtonLoading(btn);
                }
                btn.disabled = false;
                st.textContent = 'Gagal menghubungi server.';
            });
    });

    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });

    apply.addEventListener('click', async function () {
        var f = apply.__fields || {};
        if (detectedNewPax && detectedNewPax.name && !f.passenger_id) {
            var liPax = document.getElementById('ai-new-pax-item');
            var btnPax = document.getElementById('ai-create-pax-btn');
            await createAndSelectNewPassenger(detectedNewPax, liPax, btnPax, f);
        }
        var map = labels || {};
        var order = [
            'branch_id', 'passenger_id', 'category_vehicle_id', 'vehicle_id',
            'pickup_point_province_id', 'pickup_point_city_id', 'pickup_point_district_id',
            'delivery_point_province_id', 'delivery_point_city_id', 'delivery_point_district_id',
            'date', 'start_date', 'end_date', 'duration_days', 'time',
            'trip', 'bbm', 'total_price', 'pickup_address', 'delivery_address', 'note'
        ];
        Object.keys(f).forEach(function (k) {
            if (order.indexOf(k) === -1) order.push(k);
        });

        order.forEach(function (k) {
            if (!(k in f) || f[k] === '' || f[k] == null) return;
            var el = document.querySelector('[name="' + k + '"]');
            if (!el) return;
            var sVal = String(f[k]);
            var label = (map[k] && map[k][f[k]] !== undefined) ? String(map[k][f[k]]) : '';

            if (el.tomselect) {
                if (!el.tomselect.options[sVal]) {
                    el.tomselect.addOption({ value: sVal, id: sVal, text: label || sVal });
                }
                if (geoFields[k] && typeof window.setTsValueSilent === 'function') {
                    window.setTsValueSilent(el, sVal);
                } else {
                    el.tomselect.setValue(sVal);
                }
            } else {
                if (el.tagName === 'SELECT' && !Array.from(el.options || []).some(function (o) { return String(o.value) === sVal; })) {
                    el.add(new Option(label || sVal, sVal));
                }
                el.value = sVal;
                if (el._flatpickr) {
                    el._flatpickr.setDate(sVal, true);
                }
            }

            if (k === 'total_price') {
                var pDisp = document.getElementById('rental_price_display') || document.getElementById('totalPriceDisplay');
                if (pDisp) {
                    pDisp.value = window.formatRupiah ? window.formatRupiah(f[k]) : sVal;
                    pDisp.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            if (k === 'bbm') {
                var bDisp = document.getElementById('crew_bbm_display');
                if (bDisp) {
                    bDisp.value = window.formatRupiah ? window.formatRupiah(f[k]) : sVal;
                    bDisp.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            if (!geoFields[k]) {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        ['pickup_point_district_id', 'delivery_point_district_id'].forEach(function (dk) {
            var de = document.querySelector('[name="' + dk + '"]');
            if (de && f[dk]) {
                de.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        prev.classList.add('d-none');
        st.textContent = 'Form terisi — periksa lalu klik Simpan.';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    cancel.addEventListener('click', function () { prev.classList.add('d-none'); });
})();
</script>