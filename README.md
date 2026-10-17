# VSudoTrans

Sistem manajemen transportasi (travel door-to-door) & rental kendaraan, multi-tenant (multi-company).
PHP Native + MySQLi + Bootstrap 5. Konversi dari VSudoTrans.API (ASP.NET Core).

- PHP 8.x Native, tanpa framework
- MySQLi prepared statements di semua query
- Bootstrap 5 (CDN), vanilla JavaScript
- Multi-tenant: semua data operasional di-scope `company_id`

## Instalasi

### 1. Import Database

```bash
mysql -u root -p < database/schema.sql
```

> Schema lengkap di 1 file: 44 tabel, 5 role (Super Admin/Admin Perusahaan/HRD/Staff/Viewer), 5 user default dengan password `P@ssw0rd`. Terverifikasi fresh install: 44 tabel + 5 user.
>
> **Penting:** file ini sudah memuat `CREATE DATABASE IF NOT EXISTS \`vsudotrans\`` dan `USE \`vsudotrans\``. Jadi:
> - Tidak perlu membuat database dulu; cukup import.
> - Untuk mengganti nama database, edit baris `CREATE DATABASE`/`USE` di paling atas file **atau** ganti nama di cPanel sebelum import.
> - Import ulang akan **menghapus dan mengganti** seluruh tabel (fresh install, bukan migrasi).

### 2. Konfigurasi

`config/config.php` tidak ikut Git (berisi kredensial). Salin dari contoh:

```bash
cp config/config.php.sample config/config.php
```

Lalu sesuaikan:
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
- Permission: folder `755`, file `644`; folder `uploads/` harus writable

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
| Referensi umum (bank, wilayah, satuan) | ✅ | ✅ | Wilayah: negara→provinsi→kota→kecamatan→desa |
| Kendaraan (merek, kategori, model, kendaraan) | ✅ | ✅ | |
| Master HR (grade, jabatan, posisi, struktur, penghargaan) | ✅ | ✅ | |
| Travel: rute, jadwal, penumpang, booking | ✅ | ✅ | |
| Rental: booking + aturan premi/biaya karyawan | ✅ | ✅ | |
| Booking service: status, reschedule, assign driver, cancel | ✅ | ✅ | Invoice otomatis ikut status |
| Tiket publik `tiket.php?no=...` | ✅ | ✅ | Tanpa login, tanpa data internal |
| Metode Pembayaran (bank/QR) | ✅ | ✅ | Master `payment_methods`: CRUD + upload QR (LONGBLOB) |
| **Invoice** | ✅ | ✅ | Otomatis saat booking; list + filter status; detail + catat pembayaran; cetak invoice (browser → PDF) |
| **Pembayaran** | ✅ | ✅ | Bayar sebagian/lunas, tolak overpay; `booking_cost_lines` cost_type `payment`; sinkron `total_payment` |
| **Penjadwalan Driver** | ✅ | ✅ | Papan aktif + riwayat, tugaskan/selesaikan tugas, riwayat per booking; `trip_assignments` |
| Payroll (gaji pokok, uang harian) | ⏳ | ⏳ | Belum dirancang |
| Finance, Attendance | ⏳ | ⏳ | Belum dirancang |

✅ selesai · ⏳ belum

## Menu & Fitur (isi UI)

Menu sidebar, dan apa yang bisa dilakukan di dalamnya:

| Menu | Fitur |
|------|-------|
| **Dashboard** | Kartu statistik: jumlah karyawan, cabang, perusahaan (sesuai hak akses) |
| **Perusahaan** | CRUD perusahaan + detail; switch perusahaan aktif (multi-tenant) |
| **Cabang** | CRUD cabang per perusahaan |
| **Karyawan** | CRUD karyawan + detail; filter per cabang/jabatan |
| **Travel** ▾ | **Booking**: buat booking travel, resi acak, invoice otomatis, detail + riwayat status, transisi status (draft→confirmed→scheduled→on_trip→done), reschedule, assign driver, cancel · **Jadwal**: jadwal per rute + armada · **Rute**: rute + harga (min/max/kapasitas) · **Penumpang**: data penumpang |
| **Rental** | Booking rental: periode (start/end date), lama trip, BBM, premi & biaya per karyawan, invoice otomatis, status/reschedule/driver/cancel |
| **Kendaraan** | CRUD merek, kategori, model, tipe mesin, unit kendaraan |
| **Pengguna** | CRUD user, mapping user↔perusahaan, reset password |
| **Role & Hak Akses** | CRUD role, editor permission per role, batas level (aktor hanya boleh mengelola role di bawah levelnya) |
| **Referensi Umum** ▾ | Bank, Satuan, dan Wilayah berjenjang: Negara → Provinsi → Kota/Kab. → Kecamatan → Desa |
| **Master HR** ▾ | Grade jabatan, Jabatan, Posisi (bertingkat), Struktur Organisasi, Penghargaan karyawan |
| **Penjadwalan** ▾ | **Papan & Riwayat**: assignment aktif ("sedang jalan") + tombol Selesaikan Tugas, tab Riwayat dengan filter rentang tanggal · **Tugaskan Driver**: pilih booking travel/rental yang belum punya penugasan aktif (50 terbaru per tipe), driver (`job_position` = "Driver"), kendaraan opsional, catatan |
| **Audit Trail** | Read-only: waktu, user, aksi, IP, keterangan (login, logout, switch perusahaan, create/update/delete semua modul, aksi booking) |
| **Tiket publik** (tanpa login) | `tiket.php?no=<resi>` — cek tiket travel/rental: nomor, tanggal, jam, alamat, total |
| **Keuangan** ▾ | **Invoice**: daftar + filter status, detail, catat pembayaran (sebagian/lunas), cetak invoice (browser → cetak/Simpan PDF) · **Metode Pembayaran**: CRUD bank/QRIS/e-wallet per perusahaan (atau global), upload gambar QR, aktif/nonaktif, urutan tampil |

**Tanpa halaman (logika saja):** rate limit login, tenant scoping, CSRF, level rule role.

**Belum ada:** Payroll (gaji pokok, uang harian), Finance, Attendance. Kolom pajak invoice sudah ada tapi belum dihitung.

## Pengujian

Self-check tanpa framework (butuh DB lokal sesuai `config/config.php`):

```bash
php tests/booking_check.php          # premi, transisi status, reschedule, cancel
php tests/masters_engine_check.php   # engine master: save/scope referensi & HR
php tests/vehicles_check.php
php tests/level_rule_check.php       # batas level role
php tests/payment_methods_check.php  # metode pembayaran + blob QR
php tests/invoice_check.php          # invoice + pembayaran (partial/lunas/overpay)
php tests/invoice_pdf_check.php      # cetak invoice: render HTTP + scoping tenant (butuh server HTTP: IV_BASE)

php tests/dispatch_check.php         # penjadwalan driver: assign/end, validasi tenant, RBAC
```

> `tests/invoice_pdf_check.php` menguji halaman cetak lewat HTTP, jadi server harus hidup:
> `php -d mysqli.default_socket=... -S 127.0.0.1:8091 -t <folder-induk-proyek>` lalu
> `IV_BASE=http://127.0.0.1:8091/<nama-folder> php tests/invoice_pdf_check.php`.

## Struktur

```
vsudotrans/
├── config/            # APP_URL +DB + session
├── database/schema.sql# 44 tabel + seed, satu file
├── includes/          # auth.php (RBAC, company scope), booking_service.php, header/sidebar/footer
├── pages/
│   ├── auth/ dashboard/ profile/
│   ├── companies/ groups/ branches/ employees/ users/ roles/ audit/
│   ├── masters/       # referensi umum, engine: _engine.php + subfolder per tabel
│   ├── vehicles/ hr/  # master kendaraan + master HR
│   ├── finance/       # payment_methods (metode pembayaran + QR), invoices (list + detail/bayar)
│   ├── dispatch/      # penjadwalan driver: papan aktif/riwayat, tugaskan, detail booking+riwayat
│   ├── travel/        # rutes, schedules, passengers, bookings
│   └── rental/        # bookings
├── tests/             # self-check assert-style
└── tiket.php          # halaman tiket publik
```

## Aturan Skema & Keamanan

- Tabel operasional wajib `company_id` + FK ke `companies`; referensi umum (bank, wilayah, satuan) tanpa `company_id`.
- `company_id` saat create selalu dari perusahaan aktif (session), tidak pernah dari POST.
- UPDATE/DELETE tabel ber-`company_id` di-scope tenant di SQL (`company_id = ?` atau `company_id IS NULL` untuk baris global) + `assert_company_access()` di PHP.
- Semua POST wajib `verify_csrf()`; semua query prepared statements; output lewat `e()`.
- Nomor booking/invoice pelanggan (`document_number`) acak seperti resi, unik global; dipakai tiket publik tanpa login.
- Nomor invoice internal (`invoice_no`) berurutan dari `document_sequences`.
