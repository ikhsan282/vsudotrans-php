# Panduan Integrasi WordPress Landing Page — Cek & Cetak E-Tiket VSudoTrans

Setiap klien **VSudoTrans Multi-Tenant** memiliki website landing page WordPress di domain masing-masing:
- **Maura Trans Mandiri** (`mauratransmandiri.com`) → Kode: `MTM`
- **Nugi Trans Mandiri** (`nugitransmandiri.com`) → Kode: `NTM`
- **Gibran Trans Mandiri** (`gibrantransmandiri.com`) → Kode: `GTM`
- **Trisula Trans Mandiri** (`trisulatransmandiri.com`) → Kode: `TTM`
- **LIZ Wisata Alam Indah** (`lizwisataalamindah.com`) → Kode: `LIZ`

Halaman Cek Tiket pada masing-masing WordPress cukup memanggil endpoint publik:
`GET /api/ticket.php?no=<NOMOR_TIKET>&company=<KODE_PERUSAHAAN>`

---

## 1. Spesifikasi Endpoint `/api/ticket.php`

- **URL**: `https://panel.domain-vsudotrans.com/api/ticket.php?no=TRV-8K4M9P2X&company=MTM`
- **Method**: `GET` (mendukung CORS `Access-Control-Allow-Origin: *`)
- **Isolasi Multi-Tenant**: Jika parameter `company=MTM` dikirim, maka nomor tiket milik perusahaan lain (mis. `LIZ` atau `GTM`) akan otomatis ditolak (`404 TICKET_NOT_FOUND`), sehingga pelanggan di web Maura Trans Mandiri hanya bisa mengecek tiket Maura Trans Mandiri.

### Contoh Respons JSON Sukses (`200 OK`)
```json
{
  "ok": true,
  "data": {
    "valid": true,
    "document_number": "TRV-8K4M9P2X",
    "invoice_no": "INV/2026/000012",
    "booking_type": "travel",
    "booking_status": "confirmed",
    "payment_status": "paid",
    "company": {
      "id": 1,
      "code": "MTM",
      "name": "Maura Trans Mandiri",
      "phone": "021-0000000",
      "email": "info@mauratransmandiri.com"
    },
    "route": {
      "origin_city": "Tangerang",
      "destination_city": "Bandung",
      "label": "Tangerang - Bandung",
      "pickup_address": "Jl. Sudirman No. 10",
      "delivery_address": "Jl. Dago No. 25"
    },
    "schedule": {
      "date": "2026-11-15",
      "date_formatted": "15 November 2026",
      "time": "08:00"
    },
    "pricing": {
      "total": { "raw": 350000, "formatted": "Rp 350.000" },
      "paid": { "raw": 350000, "formatted": "Rp 350.000" },
      "remaining": { "raw": 0, "formatted": "Rp 0" }
    },
    "passengers_count": 2,
    "passengers": [
      { "no": 1, "name": "Budi Santoso", "is_primary": true, "phone_masked": "0812*****890" }
    ],
    "links": {
      "tiket_url": "https://panel.domain-vsudotrans.com/tiket.php?no=TRV-8K4M9P2X&company=MTM",
      "print_pdf_url": "https://panel.domain-vsudotrans.com/tiket.php?no=TRV-8K4M9P2X&company=MTM&print=1",
      "verifikasi_url": "https://panel.domain-vsudotrans.com/verifikasi.php?no=TRV-8K4M9P2X&company=MTM",
      "pay_qris_url": null
    }
  }
}
```

---

## 2. Snippet Shortcode WordPress (`[vsudo_cek_tiket]`)

Tempelkan kode PHP berikut ke plugin **WPCode / Code Snippets** atau file `functions.php` tema WordPress masing-masing klien, lalu pasang shortcode di halaman **Cek Tiket**:

- Di `mauratransmandiri.com`: `[vsudo_cek_tiket company="MTM" api_url="https://panel.domain-vsudotrans.com"]`
- Di `gibrantransmandiri.com`: `[vsudo_cek_tiket company="GTM" api_url="https://panel.domain-vsudotrans.com"]`
- Di `lizwisataalamindah.com`: `[vsudo_cek_tiket company="LIZ" api_url="https://panel.domain-vsudotrans.com"]`

```php
<?php
/**
 * Shortcode WordPress: [vsudo_cek_tiket company="MTM" api_url="https://panel.domain-vsudotrans.com"]
 */
add_shortcode('vsudo_cek_tiket', function ($atts) {
    $atts = shortcode_atts([
        'company' => 'MTM',
        'api_url' => 'https://panel.domain-vsudotrans.com',
    ], $atts, 'vsudo_cek_tiket');

    $apiBase = rtrim($atts['api_url'], '/');
    $company = strtoupper(trim($atts['company']));
    ob_start();
    ?>
    <div class="vsudo-ticket-widget" style="max-width:640px;margin:24px auto;font-family:system-ui,-apple-system,sans-serif;">
        <form id="vsudoTicketForm" style="display:flex;gap:8px;margin-bottom:16px;">
            <input type="text" id="vsudoTicketNo" placeholder="Masukkan Nomor Tiket (contoh: TRV-8K4M9P2X)" required
                   style="flex:1;padding:12px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;text-transform:uppercase;">
            <button type="submit" style="padding:12px 20px;background:#0f172a;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;">
                Cek Tiket
            </button>
        </form>
        <div id="vsudoTicketResult"></div>
    </div>
    <script>
    (function(){
        const form = document.getElementById('vsudoTicketForm');
        const input = document.getElementById('vsudoTicketNo');
        const box = document.getElementById('vsudoTicketResult');
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const company = <?php echo json_encode($company); ?>;

        form.addEventListener('submit', async function(e){
            e.preventDefault();
            const no = input.value.trim().toUpperCase();
            if (!no) return;
            box.innerHTML = '<div style="padding:14px;background:#f8fafc;border-radius:8px;">Memeriksa tiket <strong>' + no + '</strong>...</div>';
            try {
                const url = apiBase + '/api/ticket.php?no=' + encodeURIComponent(no) + '&company=' + encodeURIComponent(company);
                const r = await fetch(url);
                const j = await r.json();
                if (!j.ok) {
                    box.innerHTML = '<div style="padding:14px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;">'
                        + (j.error && j.error.message ? j.error.message : 'Tiket tidak ditemukan.') + '</div>';
                    return;
                }
                const d = j.data;
                const badgeBg = d.payment_status === 'paid' ? '#dcfce7' : (d.payment_status === 'cancelled' ? '#f1f5f9' : '#fef9c3');
                const badgeColor = d.payment_status === 'paid' ? '#166534' : (d.payment_status === 'cancelled' ? '#475569' : '#854d0e');
                const payLabel = d.payment_status === 'paid' ? 'LUNAS' : (d.payment_status === 'cancelled' ? 'DIBATALKAN' : 'BELUM LUNAS');

                let html = '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.04);">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
                html += '<div><div style="font-size:12px;color:#64748b;">NOMOR TIKET</div><div style="font-size:18px;font-weight:700;">' + d.document_number + '</div></div>';
                html += '<span style="padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;background:' + badgeBg + ';color:' + badgeColor + ';">' + payLabel + '</span>';
                html += '</div><hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0;">';
                html += '<p style="margin:4px 0;"><strong>Perusahaan:</strong> ' + d.company.name + '</p>';
                html += '<p style="margin:4px 0;"><strong>Rute:</strong> ' + d.route.label + '</p>';
                html += '<p style="margin:4px 0;"><strong>Jadwal:</strong> ' + d.schedule.date_formatted + ' · ' + d.schedule.time + ' WIB</p>';
                html += '<p style="margin:4px 0;"><strong>Penumpang:</strong> ' + d.passengers_count + ' orang (' + d.passengers.map(p => p.name).join(', ') + ')</p>';
                html += '<p style="margin:4px 0;"><strong>Total Harga:</strong> ' + d.pricing.total.formatted + '</p>';
                html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">';
                html += '<a href="' + d.links.print_pdf_url + '" target="_blank" rel="noopener" style="padding:10px 16px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">Cetak / Download E-Tiket PDF</a>';
                if (d.links.pay_qris_url) {
                    html += '<a href="' + d.links.pay_qris_url + '" target="_blank" rel="noopener" style="padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">Bayar via QRIS Otomatis</a>';
                }
                html += '</div></div>';
                box.innerHTML = html;
            } catch (err) {
                box.innerHTML = '<div style="padding:14px;background:#fef2f2;color:#991b1b;border-radius:8px;">Gagal menghubungi server tiket. Silakan coba lagi.</div>';
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});
```

