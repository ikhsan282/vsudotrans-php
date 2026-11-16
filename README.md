# VSudoTrans

Platform manajemen transportasi: **satu aplikasi untuk banyak Agen Travel & PO Bus** (multi-tenant).
Mencakup travel door-to-door, sewa kendaraan (rental), transportasi & komisi, kalender operasional & penugasan driver, keuangan & QRIS dinamis, WhatsApp Bot API, hingga payroll.
PHP Native + MySQLi + Bootstrap 5. Konversi dari VSudoTrans.API (ASP.NET Core).

- PHP 8.x Native, tanpa framework
- MySQLi prepared statements di semua query
- Bootstrap 5 (CDN), vanilla JavaScript + Tom Select v2.3.1 (remote search & bounded queries)
- Multi-tenant: semua data operasional di-scope `company_id` (isolasi data antar agen)
- Ekspor laporan: CSV, Excel (XLSX), PDF — semuanya tanpa Composer

## Instalasi

### 1. Import Database

```bash
mysql -u root -p < database/schema.sql
```

> Schema lengkap di 1 file: **59 tabel**, **5 role** (`Super Admin`, `Admin Perusahaan`, `HRD`, `Staff`, `Viewer`), dan **5 user default** dengan password `P@ssw0rd`.
>
> **Hak akses berbasis RBAC (`role_permissions`)**:
> - `Super Admin` & `Admin Perusahaan`: akses penuh modul operasional, keuangan, SDM/payroll, master data, pengguna & audit (`roles.manage` khusus `Super Admin`).
> - `HRD`: akses kelola SDM, absensi, payroll, organisasi/cabang, serta akses lihat (`*.view`) ke travel, rental & kendaraan — tanpa akses ke `invoices.view`, `finance.view`, `reports.view`, maupun `dispatch.view`.
> - `Staff` & `Viewer`: akses lihat (`*.view`) modul operasional (travel, rental, kalender operasional, kendaraan, karyawan, absensi, invoice, referensi, grup/perusahaan/cabang, laporan operasional/booking/driver); tidak memiliki akses ke Buku Kas/Akun Kas/Metode Pembayaran (`finance.view`), Payroll (`payroll.view`), Pengguna (`users.view`), maupun Audit (`audit.view`).
>
> **Penting:** file ini sudah memuat `CREATE DATABASE IF NOT EXISTS \`vsudotrans\`` dan `USE \`vsudotrans\``. Jadi:
> - Tidak perlu membuat database dulu; cukup import.
> - Untuk mengganti nama database, edit baris `CREATE DATABASE`/`USE` di paling atas file **atau** ganti nama di cPanel sebelum import.
> - Import ulang akan **menghapus dan mengganti** seluruh tabel (fresh install, bukan migrasi).

### 2. Konfigurasi

`config/config.php` dan `.env` tidak ikut Git (berisi kredensial). Salin dari contoh:

```bash
cp config/config.php.sample config/config.php
cp .env.example .env
```

Lalu sesuaikan `.env` untuk Azure Blob Storage:
```ini
BLOB_STORAGE_CONNECTION_STRING="DefaultEndpointsProtocol=https;AccountName=vsudotransstorage;AccountKey=YOUR_KEY;EndpointSuffix=core.windows.net"
BLOB_STORAGE_CONTAINER="vsudotrans"
```

Dan `config/config.php` untuk database & URL:
```php
define('APP_URL', 'http://localhost:8080/vsudotrans-php'); // tanpa trailing slash
define('DB_HOST', 'localhost');
define('DB_NAME', 'vsudotrans');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Jalankan

```bash
php -S 127.0.0.1:8080 -t /path/ke/parent-folder   # docroot = folder induk proyek
```

Buka `http://localhost:8080/vsudotrans-php/pages/auth/login.php`.

### 4. Login Default

| Username | Role |
|---|---|
| `superadmin` | Super Admin (lintas perusahaan) |
| `admin` | Admin Perusahaan |
| `hrd` | HRD |
| `staff` | Staff (view operasional) |
| `viewer` | Viewer (hanya lihat) |

Password semua user: `P@ssw0rd`. **Wajib diganti sebelum production.**

### 5. cPanel

- Upload semua file ke `public_html/vsudotrans-php/` (**sertakan `.htaccess`** — lihat catatan keamanan di bawah)
- Import `database/schema.sql` via phpMyAdmin
- Sesuaikan `APP_URL` dan kredensial DB di `config/config.php`
- Permission: folder `755`, file `644`
- **Azure Blob Storage**: Berkas unggahan (logo, stempel, QRIS, foto armada, STNK, KIR, foto karyawan, KTP, SIM, dan bukti bayar) tersimpan langsung di Azure Blob Storage private container dengan Service SAS Token URL. Pastikan kredensial Azure diisi di file `.env`.
- **Ekstensi PHP**: pastikan `mysqli`, `curl`, `dom`, `mbstring`, dan `zip` aktif (cPanel → *Select PHP Version* → *Extensions*). `curl` untuk Azure Storage REST API & integrasi QRIS/Midtrans/WA; `dom`+`mbstring` untuk PDF; bila tidak aktif, tombol ekspor otomatis beralih ke HTML siap-cetak (tidak error).

`.htaccess` memblokir akses web ke `config/`, `includes/`, `tests/`, `database/` dan file `.sql`/`.md`, plus menambah security headers. Tanpa file ini, `database/schema.sql` dan source dapat diunduh siapa saja.

## Status Modul

| Modul | Skema | Halaman PHP | Catatan |
|-------|-------|-------------|---------|
| Auth, RBAC, tenant scoping | ✅ | ✅ | CSRF + rate limit login |
| **Dashboard** | ✅ | ✅ | Query grafik & ringkasan hari/minggu ini dalam 1x `UNION ALL`, bar 5 status operasional, driver aktif + link booking, piutang jatuh tempo (`issued` + `partial`), tabel pemesanan aktif/terjadwal + kolom Sopir, peringatan dokumen STNK/KIR/Pajak/SIM H-30, ringkasan master aktif, & proteksi RBAC per seksi |
| Perusahaan, Grup, Cabang | ✅ | ✅ | Pengaturan WhatsApp per perusahaan (`pages/companies/wa.php`), logo & stempel Azure SAS |
| Karyawan | ✅ | ✅ | Berkas KTP/SIM & monitoring jatuh tempo SIM |
| Pengguna, Role & Hak Akses | ✅ | ✅ | Batas level role (`H1`/`H2`/`M1`) |
| Audit Trail | ✅ | ✅ | Read-only |
| Referensi umum (bank, wilayah, satuan) | ✅ | ✅ | Wilayah berjenjang (`cities.short_name` singkatan kota 3–4 huruf) + remote Tom Select (`opts_*`) |
| Kendaraan (`brands`, `categories`, `models`, unit) | ✅ | ✅ | Master merek/kategori/model dipisah ke halaman terdedikasi; foto, STNK, KIR terintegrasi Azure Blob |
| Master HR (grade, jabatan, posisi, struktur, penghargaan) | ✅ | ✅ | |
| **Travel** (rute & pemesanan tiket) | ✅ | ✅ | Rute bertarif bertingkat (Daftar Harga terurut tarif termurah) + radius kecamatan; pemesanan tiket + resi + badge penumpang tambahan (`+N`) + kolom **Sopir** (no. WA di atas, nama diperkecil) |
| **Rental** (sewa kendaraan) | ✅ | ✅ | Booking rental multi-hari + badge penumpang tambahan (`+N`) + komisi kru/sopir + kolom **Sopir** + inline payment & QRIS dinamis |
| **Transportasi** (penumpang & peraturan komisi) | ✅ | ✅ | Master bersama Penumpang & Peraturan Komisi untuk Travel & Rental |
| **Booking service** (5 status operasional) | ✅ | ✅ | Alur 5 status: `draft` → `scheduled` → `on_trip` → `done` / `cancelled` (status `confirmed` dihapus; pembayaran tidak mengunci status operasional); reschedule, assign driver, cancel otomatis sinkron ke invoice |
| Tiket publik `tiket.php?no=...` | ✅ | ✅ | Tanpa login, logo & stempel dari Azure SAS; blok `Detail Pemesanan` seragam Travel & Rental (`Resi`, `Jam Berangkat`, `Layanan`, `Kendaraan`, `Total Harga`) + Syarat & Ketentuan Travel |
| Metode Pembayaran & **Saluran QRIS/Gopay** | ✅ | ✅ | Master `payment_methods` + konfigurasi gateway QRIS/Midtrans dinamis (`payment_channels`) |
| **Invoice & Pembayaran** | ✅ | ✅ | Otomatis saat booking; status `issued` / `partial` / `paid` / `cancelled`; tolak overpay; cetak + **unduh PDF** |
| **Kalender Operasional & Driver** | ✅ | ✅ | Kalender operasional (`pages/dispatch/calendar.php` Bulan/Minggu/Hari/Daftar: ringkasan 2 baris dengan singkatan kota, kategori · merk · plat armada, tanggal/jam Travel & Rental, klik Daftar buka modal detail), papan/penugasan aktif, detail & cetak surat jalan (`manifest.php`) dari Detail Booking |
| **WhatsApp Bot API** (`api/bot/*`) | ✅ | ✅ | Endpoint internal bot untuk booking, rute, metode pembayaran, QRIS dinamis, & ringkasan staf |
| **Laporan** (Booking, Keuangan, Operasional, Driver) | ✅ | ✅ | Rekap lintas dimensi + export CSV, XLSX, PDF, & Cetak |
| **Payroll & Absensi** | ✅ | ✅ | Absensi harian/rekap → sumber hari hadir generate payroll (`draft` → `final` → `dibayar`), cetak & PDF |
| **Buku Kas & Bank** | ✅ | ✅ | Akun kas/bank + buku besar transaksi; export CSV, XLSX, PDF, cetak |
| **Backup & Restore** | ✅ | ✅ | Dump/unduh `.sql` + restore dari file; khusus Super Admin |
| **Import CSV master data** | ✅ | ✅ | Template CSV, all-or-nothing, mode upsert |

✅ selesai · ⏳ belum

## Menu & Fitur (Struktur Sidebar ERP)

Menu sidebar (`includes/sidebar.php`) dikelompokkan dalam 7 kategori dan difilter dengan `can()` serta flag `admin_only`:

| Kategori | Menu | Fitur |
|----------|------|-------|
| **1. UTAMA** | **Dashboard** | **Operasional:** booking hari ini & minggu ini (1x query gabungan grafik + KPI), ringkasan **5 status operasional** (`Draft`, `Terjadwal`, `Sedang Jalan`, `Selesai`, `Dibatalkan`), **Driver Sedang Bertugas** (no. WA/kode + link detail booking + status + shortcut ke Kalender), **Piutang Jatuh Tempo** (`issued` & `partial`, link detail invoice, total/jumlah dihitung dari seluruh piutang bukan hanya 8 baris teratas), **Pemesanan Aktif & Terjadwal** lengkap dengan kolom **Sopir**, **Peringatan Dokumen & Legalitas H-30** (STNK, KIR, Pajak, SIM). **Grafik & Statistik:** booking & pendapatan 6 bulan terakhir (disembunyikan bagi role tanpa izin keuangan/laporan) serta ringkasan **Karyawan Aktif**, **Armada Aktif**, **Cabang**, dan **Perusahaan** |
| **2. OPERASIONAL & ARMADA** | **Travel** ▾ | **Pemesanan Tiket Perjalanan**: CRUD booking, resi acak, invoice otomatis, 5 status operasional, penumpang utama & badge tambahan ringkas (`+N`), kolom **Sopir** (no. WA di atas + nama di bawah), nominal ringkas tanpa prefix `Rp`, ekspor PDF/XLSX · **Rute**: rute + Daftar Harga terurut tarif termurah + radius kecamatan |
| | **Rental** ▾ | **Pemesanan Sewa Kendaraan**: periode `start_date`–`end_date`, lama trip, BBM, premi/komisi kru, badge penumpang tambahan ringkas (`+N`), kolom **Sopir** (WA di atas + nama di bawah), nominal ringkas tanpa `Rp`, invoice otomatis & pembayaran QRIS dinamis |
| | **Transportasi** ▾ | **Penumpang** (master pelanggan/penumpang bersama Travel & Rental) · **Peraturan Komisi** (aturan biaya/komisi kru & sopir) |
| | **Kalender Operasional** | Agenda bulanan/mingguan/harian/daftar armada & driver (`pages/dispatch/calendar.php`) dengan tampilan 2 baris (rute singkat, kategori · merk · plat, sopir, tanggal/periode + jam, klik Daftar buka modal detail), papan/penugasan driver aktif, dan cetak surat jalan (`manifest.php`) dari halaman detail booking |
| | **Kendaraan** ▾ | **Daftar Kendaraan**, **Merek Kendaraan**, **Kategori Kendaraan**, **Model Unit**; lengkap foto unit, STNK, KIR (Azure Blob) |
| **3. KEUANGAN & AKUNTANSI** | **Keuangan** ▾ | **Invoice** (`issued`/`partial`/`paid`/`cancelled`, bukti bayar Azure Blob, cetak + **unduh PDF**) · **Buku Kas & Bank** · **Akun Kas/Bank** · **Metode Pembayaran** · **Saluran QRIS/Gopay** (`pages/finance/payment_channels`, izin `companies.manage`) |
| **4. SDM & PAYROLL** | **Karyawan** | CRUD karyawan + detail, rekening payroll, foto profil, KTP & SIM (Azure Blob) |
| | **Absensi** ▾ | **Absensi Harian** · **Rekap Absensi** (sumber hari hadir Payroll, export CSV/XLSX/PDF/Cetak) |
| | **Payroll** | Periode bulanan (`draft` → `final` → `dibayar`), generate per karyawan aktif, hitung ulang potongan & hari hadir, cetak + export CSV/XLSX/PDF |
| | **Master HR** ▾ | Grade Jabatan, Jabatan, Posisi, Struktur Org., Penghargaan + Import CSV |
| **5. LAPORAN** | **Laporan** ▾ | **Laporan Booking** · **Laporan Keuangan** · **Laporan Operasional** · **Laporan Driver** (semua export CSV, XLSX, PDF, dan Cetak) |
| **6. MASTER DATA & ORGANISASI** | **Organisasi** ▾ | **Grup Perusahaan** · **Perusahaan** (termasuk pengaturan WA & logo/stempel Azure Blob) · **Cabang** |
| | **Referensi Umum** ▾ | **Bank**, **Wilayah** (`Negara` → `Provinsi` → `Kota/Kab.` + `short_name` 3–4 huruf → `Kecamatan` → `Desa`), **Satuan** — Streaming Grid Export & Import CSV |
| **7. ADMINISTRASI SISTEM** | **Pengguna & Akses** ▾ | **Pengguna** · **Role & Hak Akses** · **Audit Trail** |
| | **Sistem** ▾ | **Backup & Restore** (khusus Super Admin) |

**Tanpa halaman (logika saja):** rate limit login, tenant scoping, CSRF, level rule role.

**Belum ada / tidak dipakai:** jurnal/neraca double-entry, portal pelanggan/driver, lembur otomatis, antrean/retry otomatis WhatsApp tanpa cron. **Pajak invoice sengaja tidak dipakai** — tarif tetap 0 (`booking_tax_percent()`).

## Pengujian

Self-check tanpa framework (butuh DB lokal sesuai `config/config.php`). Tersedia **36 file `tests/*_check.php`** dan **1 skrip browser E2E CDP** (`tests/browser_ui_e2e.mjs`):

```bash
php tests/dashboard_chart_check.php   # grafik 6 bulan dashboard, scoping tenant & format rupiah_singkat
php tests/booking_check.php           # premi/komisi, transisi 5 status, reschedule, cancel
php tests/calendar_check.php          # kalender operasional: RBAC, scope, filter, tampilan Bulan & Daftar
php tests/ticket_api_check.php        # WordPress Ticket Check API & multi-brand e-tiket
php tests/payment_gateway_check.php   # QRIS dinamis, webhook/polling, saluran pembayaran
php tests/tomselect_scale_check.php   # remote Tom Select, index DB, ekspor, Sopir & UI parity
php tests/masters_engine_check.php    # engine master: save/scope referensi & HR
php tests/master_import_check.php     # import CSV master (all-or-nothing, upsert, RBAC, CSRF)
php tests/masters_reorder_check.php   # ubah urutan item master (naik/turun)
php tests/vehicles_check.php
php tests/level_rule_check.php        # batas level role
php tests/payment_methods_check.php   # metode pembayaran + blob QR
php tests/invoice_check.php           # invoice + pembayaran (partial/lunas/overpay)
php tests/dispatch_check.php          # penjadwalan driver: assign/end, validasi tenant, RBAC
php tests/dispatch_report_check.php   # laporan driver & surat jalan
php tests/payroll_check.php           # payroll: generate, hitung gaji bersih, transisi status, RBAC
php tests/reports_check.php           # laporan booking
php tests/reports_finance_check.php   # laporan keuangan
php tests/reports_operations_check.php # laporan operasional
php tests/pdf_reports_check.php       # PDF/Cetak laporan + invoice + slip payroll, RBAC, audit
php tests/backup_check.php            # dump/restore: parsing SQL, tolak DDL & USE berbahaya
```

> Beberapa test menguji lewat HTTP (`http://127.0.0.1:8080/vsudotrans-php` atau `PDF_BASE`/`IV_BASE`), jadi Apache/PHP dev server dan MariaDB lokal harus aktif.
>
> `tests/backup_restore_http_check.php` bersifat **destruktif** dan otomatis SKIP kecuali env `VSUDO_ALLOW_DESTRUCTIVE=1` diset — jangan dijalankan di database produksi.

Export XLSX ditulis native tanpa Composer (`includes/xlsx.php`) — ZIP metode STORED + CRC32 manual, satu sheet, tanpa dependensi eksternal.

PDF server-side memakai Dompdf 3.1.6 yang di-vendor ke `includes/vendor/dompdf` (PHP murni, tanpa Composer). Butuh ekstensi PHP `dom` + `mbstring`; bila tidak tersedia, halaman otomatis turun ke mode HTML siap-cetak, bukan error.

## Struktur

```
vsudotrans-php/
├── api/
│   └── bot/           # endpoint WhatsApp Bot API (bookings, qris, dispatch, routes, summary)
├── config/            # APP_URL + DB + session
├── database/schema.sql# 59 tabel + seed role/user, satu file
├── includes/          # auth.php (RBAC, company scope), booking.php, booking_service.php,
│                      # invoice_service.php, payroll_service.php, dispatch_service.php,
│                      # payment_gateway.php, transport_helpers.php, backup.php, wa.php, ai.php,
│                      # xlsx.php (XLSX native), pdf.php (Dompdf), header/sidebar/footer
│   └── vendor/dompdf/ # Dompdf 3.1.6 (vendored, tanpa Composer)
├── pages/
│   ├── auth/ dashboard/ profile/
│   ├── companies/ groups/ branches/ employees/ users/ roles/ audit/
│   ├── masters/       # referensi umum, engine: _engine.php + _import.php + subfolder per tabel
│   ├── vehicles/      # daftar unit + subfolder brands, categories, models
│   ├── hr/            # master HR (CRUD + import CSV per folder)
│   ├── attendance/    # absensi harian + rekap
│   ├── finance/       # cash, cash_accounts, payment_methods, payment_channels, invoices
│   ├── dispatch/      # kalender operasional, penugasan driver, surat jalan (manifest.php)
│   ├── transport/     # passengers, regulations (master bersama Travel & Rental)
│   ├── reports/       # 4 laporan: bookings, finance, operations, dispatch
│   ├── payroll/       # periods (list + generate, detail item gaji, cetak + PDF)
│   ├── settings/      # backup.php (Backup & Restore, khusus Super Admin)
│   ├── travel/        # rutes, bookings
│   └── rental/        # bookings
├── tests/             # 36 file *_check.php + browser_ui_e2e.mjs (+ helper scripts)
├── index.php          # landing publik platform multi-agen
└── tiket.php          # halaman tiket publik
```

## Aturan Skema & Keamanan

- Tabel operasional wajib `company_id` + FK ke `companies`; referensi umum (bank, wilayah, satuan) tanpa `company_id`.
- `company_id` saat create selalu dari perusahaan aktif (session), tidak pernah dari POST.
- UPDATE/DELETE tabel ber-`company_id` di-scope tenant di SQL (`company_id = ?` atau `company_id IS NULL` untuk baris global) + `assert_company_access()` di PHP.
- Semua POST wajib `verify_csrf()`; semua query prepared statements; output lewat `e()`.
- Nomor booking/invoice pelanggan (`document_number`) acak seperti resi, unik global; dipakai tiket publik tanpa login.
- Nomor invoice internal (`invoice_no`) berurutan dari `document_sequences`.
