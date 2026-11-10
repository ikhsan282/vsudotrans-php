<?php
/**
 * Panel "Isi Cepat dengan AI" — include di dalam <form> booking.
 * Harus di-include SETELAH $csrf_token tersedia (header.php me-render csrf_field dengan form).
 * Aman tanpa AI: panel hanya tampil bila AI_API_KEY & AI_BASE_URL terisi.
 */
$ai_enabled = defined('AI_API_KEY') && AI_API_KEY !== '' && defined('AI_BASE_URL') && AI_BASE_URL !== ''
    && defined('AI_MODEL') && AI_MODEL !== '';
$ai_endpoint = $ai_endpoint ?? ''; // wajib diset sebelum include
if (!$ai_enabled || $ai_endpoint === '') {
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
                       placeholder="Contoh: besok jam 8 pagi ke Bandung, 2 penumpang, jemput Cip fondu" maxlength="1000">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-primary" id="ai-btn">Proses</button>
            </div>
        </div>
        <div id="ai-status" class="small text-muted mt-1"></div>
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
    var list = document.getElementById('ai-preview-list');
    var apply = document.getElementById('ai-apply');
    var cancel = document.getElementById('ai-cancel');
    var csrfT = document.querySelector('input[name="csrf_token"]');
    var labels = null; // id -> label dari server

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function tampil(fields) {
        list.innerHTML = '';
        var map = labels || {};
        var namaF = {
            branch_id: 'Cabang', passenger_id: 'Pelanggan', category_vehicle_id: 'Kategori',
            vehicle_id: 'Kendaraan', pickup_point_district_id: 'Kecamatan Asal',
            delivery_point_district_id: 'Kecamatan Tujuan', date: 'Tanggal', start_date: 'Mulai Sewa',
            end_date: 'Selesai Sewa', time: 'Jam', pickup_address: 'Alamat Jemput',
            delivery_address: 'Alamat Tujuan', total_price: 'Total Harga', trip: 'Trip (km)', bbm: 'BBM', note: 'Catatan'
        };
        var keys = Object.keys(fields).filter(function (k) { return fields[k] !== '' && fields[k] != null; });
        if (!keys.length) {
            st.textContent = 'Tidak ada field yang bisa dipastikan — isi manual atau tambahkan detail.';
            return;
        }
        keys.forEach(function (k) {
            var v = fields[k];
            if (map[k] && map[k][v] !== undefined) v = map[k][v];
            var li = document.createElement('li');
            li.className = 'list-group-item py-1';
            li.innerHTML = '<strong>' + esc(namaF[k] || k) + ':</strong> ' + esc(v);
            list.appendChild(li);
        });
        prev.classList.remove('d-none');
        apply.__fields = fields;
    }

    btn.addEventListener('click', function () {
        var teks = (inp.value || '').trim();
        if (!teks) { st.textContent = 'Tulis dulu permintaannya.'; return; }
        btn.disabled = true; prev.classList.add('d-none');
        st.textContent = 'Memproses…';
        var fd = new FormData();
        fd.append('text', teks);
        if (csrfT) fd.append('csrf_token', csrfT.value);
        fetch('<?= e($ai_endpoint) ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
            .then(function (o) {
                btn.disabled = false;
                if (!o.j.ok) { st.textContent = o.j.error || ('Gagal (HTTP ' + o.s + ')'); return; }
                labels = o.j.peta || {};
                st.textContent = '';
                tampil(o.j.candidate || {});
            })
            .catch(function () { btn.disabled = false; st.textContent = 'Gagal menghubungi server.'; });
    });

    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });

    apply.addEventListener('click', function () {
        var f = apply.__fields || {};
        Object.keys(f).forEach(function (k) {
            var el = document.querySelector('[name="' + k + '"]');
            if (el && f[k] !== '' && f[k] != null) {
                el.value = f[k];
                if (el._flatpickr) {
                    el._flatpickr.setDate(String(f[k]), true);
                } else if (el.tomselect) {
                    el.tomselect.setValue(String(f[k]));
                }
                if (k === 'total_price') {
                    var pDisp = document.getElementById('rental_price_display') || document.getElementById('totalPriceDisplay');
                    if (pDisp) pDisp.value = window.formatRupiah ? window.formatRupiah(f[k]) : f[k];
                }
                if (k === 'bbm') {
                    var bDisp = document.getElementById('crew_bbm_display');
                    if (bDisp) {
                        bDisp.value = window.formatRupiah ? window.formatRupiah(f[k]) : f[k];
                        bDisp.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        prev.classList.add('d-none');
        st.textContent = 'Form terisi — periksa lalu klik Simpan.';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    cancel.addEventListener('click', function () { prev.classList.add('d-none'); });
})();
</script>