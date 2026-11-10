# VSudoTrans

Platform manajemen transportasi: **satu aplikasi untuk banyak Agen Travel & PO Bus** (multi-tenant).
Mencakup travel door-to-door, rental kendaraan, penjadwalan driver, keuangan, hingga payroll.
PHP Native + MySQLi + Bootstrap 5. Konversi dari VSudoTrans.API (ASP.NET Core).

- PHP 8.x Native, tanpa framework
- MySQLi prepared statements di semua query
- Bootstrap 5 (CDN), vanilla JavaScript
- Multi-tenant: semua data operasional di-scope `company_id` (isolasi data antar agen)
- Ekspor laporan: CSV, Excel (XLSX), PDF — semuanya tanpa Composer

## Instalasi

### 1. Import Database

```bash
mysql -u root -p < database/schema.sql
```

> Schema lengkap di 1 file: 53 tabel, 5 role (Super Admin/Admin Perusahaan/HRD/Staff/Viewer), 5 user default dengan password `P@ssw0rd`. Terverifikasi fresh install: 53 tabel + 5 user.
>
> **Hak akses (`.view`)**: Staff & Viewer hanya melihat modul **operasional** (travel, rental, kendaraan, absensi, referensi, laporan booking/operasional). Direktori pengguna (`users.view`), Payroll (`payroll.view` — memuat nominal gaji), dan Keuangan (`finance.view` + Laporan Keuangan) hanya untuk Super Admin, Admin Perusahaan, dan HRD (payroll).
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
define('APP_URL', 'http://localhost:8080/vsudotrans'); // tanpa trailing slash
define('DB_HOST', 'localhost');
define('DB_NAME', 'vsudotrans');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Jalankan

```bash
php -S 127.0.0.1:8080 -t /path/ke/parent-folder   # docroot = folder induk proyek
```

Buka `http://localhost:8080/vsudotrans/pages/auth/login.php`.

### 4. Login Default

| Username | Role |
|---|---|
| `superadmin` | Super Admin (lintas perusahaan) |
| `admin` | Admin Perusahaan |
| `hrd` | HRD |
| `staff` | Staff (view + entry terbatas) |
| `viewer` | Viewer (hanya lihat) |

Password semua user: `P@ssw0rd`. **Wajib diganti sebelum production.**

### 5. cPanel

- Upload semua file ke `public_html/vsudotrans/` (**sertakan `.htaccess`** — lihat catatan keamanan di bawah)
- Import `database/schema.sql` via phpMyAdmin
- Sesuaikan `APP_URL` dan kredensial DB di `config/config.php`
- Permission: folder `755`, file `644`
- **Azure Blob Storage**: Berkas unggahan (logo, stempel, QRIS, foto armada, STNK, KIR, foto karyawan, KTP, SIM, dan bukti bayar) tersimpan langsung di Azure Blob Storage private container dengan Service SAS Token URL. Pastikan kredensial Azure diisi di file `.env`.
- **Ekstensi PHP**: pastikan `mysqli`, `curl`, `dom`, `mbstring`, dan `zip` aktif (cPanel → *Select PHP Version* → *Extensions*). `curl` untuk Azure Storage REST API; `dom`+`mbstring` untuk PDF; bila tidak aktif, tombol ekspor otomatis beralih ke HTML siap-cetak (tidak error).

`.htaccess` memblokir akses web ke `config/`, `includes/`, `tests/`, `database/` dan file `.sql`/`.md`, plus menambah security headers. Tanpa file ini, `database/schema.sql` dan source dapat diunduh siapa saja.

## Status Modul

| Modul | Skema | Halaman PHP | Catatan |
|-------|-------|-------------|---------|
| Auth, RBAC, tenant scoping | ✅ | ✅ | CSRF + rate limit login |
| Dashboard | ✅ | ✅ | |
| Perusahaan, Grup, Cabang | ✅ | ✅ | |
| Karyawan | ✅ | ✅ | |
| Pengguna, Role & Hak Akses | ✅ | ✅ | Batas level role (H1/H2/M1) |
| Audit Trail | ✅ | ✅ | Read-only |
| Referensi umum (bank, wilayah, satuan) | ✅ | ✅ | Wilayah: negara→provinsi→kota→kecamatan→desa; Bank: 22 bank seeded + TomSelect |
| Kendaraan (merek, kategori, model, kendaraan) | ✅ | ✅ | Foto unit, STNK, KIR terintegrasi Azure Blob |
| Master HR (grade, jabatan, posisi, struktur, penghargaan) | ✅ | ✅ | |
| Travel: rute, jadwal, booking | ✅ | ✅ | |
| Penumpang | ✅ | ✅ | Shared master data untuk Travel & Rental |
| Rental: booking + aturan premi/biaya karyawan | ✅ | ✅ | |
| Booking service: status, reschedule, assign driver, cancel | ✅ | ✅ | Invoice otomatis ikut status |
| Tiket publik `tiket.php?no=...` | ✅ | ✅ | Tanpa login, logo & stempel dari Azure SAS |
| Metode Pembayaran (bank/QR) | ✅ | ✅ | Master `payment_methods`: CRUD + QRIS Azure Blob SAS |
| **Invoice** | ✅ | ✅ | Otomatis saat booking; list + filter status; detail + catat pembayaran (bukti transfer di Azure Blob); cetak + **unduh PDF** |
| **Pembayaran** | ✅ | ✅ | Bayar sebagian/lunas, tolak overpay; `booking_cost_lines` cost_type `payment`; sinkron `total_payment` |
| **Penjadwalan Driver** | ✅ | ✅ | Papan aktif + riwayat, tugaskan/selesaikan tugas, riwayat per booking; `trip_assignments` |
| **Laporan Booking** | ✅ | ✅ | Rekap per periode, wilayah asal→tujuan, kendaraan, driver; export CSV & XLSX, PDF, cetak |
| **Laporan Keuangan** | ✅ | ✅ | Arus uang per periode: pendapatan (travel/rental), terbayar, piutang, rincian tagihan belum lunas, biaya operasional per jenis, laba kotor; export CSV & XLSX, PDF, cetak |
| **Laporan Operasional** | ✅ | ✅ | Utilisasi kendaraan (tugas + hari), aktivitas driver (selesai/berjalan), tingkat penyelesaian tugas, booking per status; export CSV & XLSX, PDF, cetak |
| **Laporan Driver** | ✅ | ✅ | Beban kerja driver (tugas/booking), detail penugasan; export CSV & XLSX, PDF, cetak |
| **Payroll** | ✅ | ✅ | Periode bulanan (draft→final→dibayar), generate per karyawan aktif, gaji dari grade, cetak + unduh PDF |
| **Absensi** | ✅ | ✅ | Absensi harian + rekap; sumber hari hadir untuk Payroll |
| **Buku Kas & Bank** | ✅ | ✅ | Akun kas/bank + buku besar transaksi; export CSV & XLSX, PDF, cetak |
| **Backup & Restore** | ✅ | ✅ | Dump/unduh `.sql` + restore dari file; khusus Super Admin |
| **Import CSV master data** | ✅ | ✅ | 12 master (referensi umum + HR): unduh template, all-or-nothing, mode upsert |

✅ selesai · ⏳ belum

## Menu & Fitur (Struktur Sidebar ERP)

Menu sidebar dikelompokkan dalam 7 kategori terstruktur:

| Kategori | Menu | Fitur |
|----------|------|-------|
| **1. UTAMA** | **Dashboard** | **Operasional:** booking hari ini & minggu ini (jumlah + nilai), driver sedang bertugas (nama, kendaraan, nomor booking, jam mulai), piutang jatuh tempo (invoice belum lunas lewat tanggal booking, urut sisa terbesar). **Grafik:** booking & pendapatan 6 bulan terakhir. **Statistik:** jumlah karyawan, cabang, perusahaan (sesuai hak akses) |
| **2. OPERASIONAL & ARMADA** | **Travel** ▾ | **Booking**: buat booking travel, resi acak, invoice otomatis, detail + riwayat status, transisi status (draft→confirmed→scheduled→on_trip→done), reschedule, assign driver, cancel · **Jadwal**: jadwal per rute + armada · **Rute**: rute + harga (min/max/kapasitas) |
| | **Rental Mobil** | Booking rental: periode (start/end date), lama trip, BBM, premi & biaya per karyawan, invoice otomatis, status/reschedule/driver/cancel |
| | **Penumpang** | Master data pelanggan/penumpang bersama untuk Travel & Rental: pencarian cepat, riwayat, CRUD |
| | **Penjadwalan** ▾ | **Kalender Operasional**: agenda bulanan/mingguan/harian armada & driver · **Papan & Riwayat**: assignment aktif ("sedang jalan") + tombol Selesaikan Tugas, tab Riwayat dengan filter rentang tanggal · **Tugaskan Driver**: pilih booking travel/rental yang belum punya penugasan aktif (50 terbaru per tipe), driver (`job_position` = "Driver"), kendaraan opsional, catatan |
| | **Kendaraan** | CRUD armada unit kendaraan, foto unit, dokumen STNK & KIR (Azure Blob), serta master merek, kategori, model, tipe mesin |
| **3. KEUANGAN & AKUNTANSI** | **Keuangan** ▾ | **Invoice**: daftar + filter status, detail, catat pembayaran (bukti bayar ke Azure Blob), cetak + **unduh PDF** · **Buku Kas & Bank**: buku besar transaksi + saldo, export CSV/XLSX/PDF/cetak · **Akun Kas/Bank**: CRUD akun kas & bank (terintegrasi master Bank) · **Metode Pembayaran**: CRUD bank/QRIS/e-wallet per perusahaan (atau global), upload gambar QRIS ke Azure Blob, aktif/nonaktif, urutan tampil (naik/turun) |
| **4. SDM & PAYROLL** | **Karyawan** | CRUD karyawan + detail, rekening payroll (terintegrasi master Bank), foto profil, berkas KTP & SIM (Azure Blob), filter per cabang/jabatan |
| | **Absensi** ▾ | **Absensi Harian**: input/daftar kehadiran per tanggal · **Rekap Absensi**: rekap hari hadir/izin/sakit/cuti/alpa per karyawan pada rentang tanggal (default bulan berjalan), sumber data Payroll, **export CSV, Excel (XLSX), PDF, cetak** |
| | **Payroll** | Daftar periode per tahun + generate periode bulanan (satu item per karyawan aktif, nilai dari grade jabatan); detail: ubah hari hadir/potongan per baris lalu gaji bersih dihitung ulang; alur status draft→final→dibayar (tidak bisa mundur, edit diblokir setelah dibayar); halaman cetak daftar gaji; **export CSV, Excel (XLSX), PDF** per periode |
| | **Master HR** ▾ | Grade jabatan, Jabatan, Posisi (bertingkat), Struktur Organisasi, Penghargaan karyawan — semua punya **Import CSV** |
| **5. LAPORAN** | **Laporan** ▾ | **Laporan Booking**: filter rentang tanggal + jenis (travel/rental/semua), kartu ringkasan, 4 tabel dimensi (periode, wilayah asal→tujuan, kendaraan, driver) · **Laporan Keuangan**: kartu laba kotor/pendapatan/biaya/piutang, 3 grafik visual, rincian biaya operasional · **Laporan Operasional**: utilisasi armada, aktivitas driver, tingkat penyelesaian tugas (%) · **Laporan Driver**: beban kerja driver + detail penugasan. Semua laporan export **CSV, Excel (XLSX), PDF**, dan **Cetak** (HTML siap cetak) |
| **6. MASTER DATA & ORGANISASI** | **Organisasi** ▾ | **Grup Perusahaan**, **Perusahaan** (logo & stempel di Azure Blob, kode terkunci permanen setelah dibuat), **Cabang** |
| | **Referensi Umum** ▾ | **Bank** (22 bank resmi di Indonesia), **Satuan**, dan **Wilayah** berjenjang (Negara → Provinsi → Kota/Kab. → Kecamatan → Desa). Setiap master data dilengkapi **Streaming Grid Export** ($O(1)$ RAM) dan **Import CSV** |
| **7. ADMINISTRASI SISTEM** | **Pengguna & Akses** ▾ | **Pengguna**: CRUD user, mapping user↔perusahaan, reset password · **Role & Hak Akses**: CRUD role, editor permission per role, batas level role · **Audit Trail**: Read-only log aktivitas sistem |
| | **Sistem** ▾ | **Backup & Restore** (khusus Super Admin): unduh dump `.sql` seluruh database, restore dari file unggahan (aman: DDL/`USE`/`CREATE DATABASE` dari dump diabaikan, transaksional) |

**Tanpa halaman (logika saja):** rate limit login, tenant scoping, CSRF, level rule role.

**Belum ada / tidak dipakai:** jurnal/neraca double-entry, portal pelanggan/driver, lembur otomatis, antrean/retry WhatsApp (butuh cron). **Pajak invoice sengaja tidak dipakai** — tarif tetap 0 (`booking_tax_percent()`), kolom `tax_percent`/`tax_amount` dipertahankan hanya agar invoice lama tetap terbaca bila nanti diperlukan.

## Pengujian

Self-check tanpa framework (butuh DB lokal sesuai `config/config.php`). Total 28 file `tests/*_check.php`:

```bash
php tests/booking_check.php          # premi, transisi status, reschedule, cancel
php tests/masters_engine_check.php   # engine master: save/scope referensi & HR
php tests/master_import_check.php    # import CSV master (all-or-nothing, upsert, RBAC, CSRF)
php tests/masters_reorder_check.php  # ubah urutan item master (naik/turun)
php tests/vehicles_check.php
php tests/level_rule_check.php       # batas level role
php tests/payment_methods_check.php  # metode pembayaran + blob QR
php tests/invoice_check.php          # invoice + pembayaran (partial/lunas/overpay)

php tests/dispatch_check.php         # penjadwalan driver: assign/end, validasi tenant, RBAC
php tests/payroll_check.php          # payroll: generate, hitung gaji bersih, transisi status, RBAC
php tests/reports_check.php          # laporan booking (ringkasan, 4 dimensi, scoping, XLSX)
php tests/reports_finance_check.php  # laporan keuangan (pendapatan/biaya/laba, piutang, scoping)
php tests/reports_operations_check.php # laporan operasional (utilisasi, driver, status, scoping)
php tests/pdf_reports_check.php      # PDF/Cetak 5 laporan + invoice + slip payroll, RBAC, audit

php tests/backup_check.php           # dump/restore: parsing SQL, tolak DDL & USE berbahaya
```

> Beberapa test menguji lewat HTTP, jadi server harus hidup:
> `php -d mysqli.default_socket=... -S 127.0.0.1:8090 -t <folder-induk-proyek>` lalu set
> `PDF_BASE=http://127.0.0.1:8090/<nama-folder> php tests/pdf_reports_check.php`
> (`IV_BASE` untuk `invoice_pdf_check.php`).
>
> `tests/backup_restore_http_check.php` bersifat **destruktif** dan otomatis SKIP kecuali
> env `VSUDO_ALLOW_DESTRUCTIVE=1` diset — jangan dijalankan di database produksi.

Export XLSX ditulis native tanpa Composer (`includes/xlsx.php`) — ZIP metode STORED + CRC32 manual,
satu sheet, tanpa dependensi eksternal.

PDF server-side memakai Dompdf 3.1.6 yang di-vendor ke `includes/vendor/dompdf` (PHP murni, tanpa
Composer, tanpa `exec`/shell — aman untuk shared hosting). Butuh ekstensi PHP `dom` + `mbstring`;
bila tidak tersedia, halaman otomatis turun ke mode HTML siap-cetak, bukan error.

## Struktur

```
vsudotrans/
├── config/            # APP_URL + DB + session
├── database/schema.sql# 53 tabel + seed, satu file
├── includes/          # auth.php (RBAC, company scope), booking_service.php, invoice_service.php,
│                      # payroll_service.php, dispatch_service.php, backup.php, wa.php, ai.php,
│                      # xlsx.php (XLSX native), pdf.php (Dompdf), header/sidebar/footer
│   └── vendor/dompdf/ # Dompdf 3.1.6 (vendored, tanpa Composer)
├── pages/
│   ├── auth/ dashboard/ profile/
│   ├── companies/ groups/ branches/ employees/ users/ roles/ audit/
│   ├── masters/       # referensi umum, engine: _engine.php + _import.php + subfolder per tabel
│   ├── vehicles/ hr/  # master kendaraan + master HR (CRUD + import CSV per folder)
│   ├── attendance/    # absensi harian + rekap
│   ├── finance/       # cash (buku kas/bank), cash_accounts, payment_methods (+QR, reorder), invoices
│   ├── dispatch/      # penjadwalan driver: papan aktif/riwayat, tugaskan, detail booking+riwayat
│   ├── reports/       # 4 laporan: bookings, finance, operations, dispatch
│   │                  #   _query.php / _finance_query.php / _operations_query.php / _dispatch_query.php
│   │                  #   _print.php (presenter PDF/HTML) + *_export.php (CSV/XLSX/PDF/HTML)
│   ├── payroll/       # periods (list + generate, detail item gaji, cetak + PDF)
│   ├── settings/      # backup.php (Backup & Restore, khusus Super Admin)
│   ├── travel/        # rutes, schedules, passengers, bookings
│   └── rental/        # bookings
├── tests/             # self-check assert-style (28 file)
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
