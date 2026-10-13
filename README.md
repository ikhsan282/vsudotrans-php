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
| Payroll (gaji pokok, uang harian) | ⏳ | ⏳ | Belum dirancang |
| Finance, Attendance | ⏳ | ⏳ | Belum dirancang |

✅ selesai · ⏳ belum

## Pengujian

Self-check tanpa framework (butuh DB lokal sesuai `config/config.php`):

```bash
php tests/booking_check.php          # premi, transisi status, reschedule, cancel
php tests/masters_engine_check.php   # engine master: save/scope referensi & HR
php tests/vehicles_check.php
php tests/level_rule_check.php       # batas level role
```

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
