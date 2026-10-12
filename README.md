# VSudoTrans

Sistem manajemen transportasi & rental kendaraan, multi-tenant (multi-company).
PHP Native + MySQLi + Bootstrap 5. Konversi dari VSudoTrans.API (ASP.NET Core).

## Setup
1. Import `database/schema.sql` (fresh install, satu file).
2. Sesuaikan `config/config.php` (APP_URL, DB_*).
3. Login default: `superadmin` / `P@ssw0rd` (wajib diganti sebelum production).

## Status modul

| Modul | Skema | Halaman PHP | Catatan |
|-------|-------|-------------|---------|
| Auth, RBAC, tenant scoping | ✅ | ✅ | |
| Perusahaan, Grup, Cabang | ✅ | ✅ | |
| Karyawan | ✅ | ✅ | |
| Pengguna, Role & Hak Akses | ✅ | ✅ | Batas level role (H1/H2/M1) |
| Audit Trail | ✅ | ✅ | Read-only |
| Referensi umum (bank, wilayah, satuan) | ✅ | ⏳ | |
| Kendaraan (merek, kategori, model, tipe mesin, kendaraan) | ✅ | ⏳ | |
| Travel (rute, jadwal, penumpang, booking) | ✅ | ⏳ | |
| Rental (booking, aturan biaya karyawan) | ✅ | ⏳ | |
| Booking: status, penugasan driver, premi, biaya | ✅ | ⏳ | |
| Invoice & kwitansi (otomatis saat booking) | ✅ | ⏳ | Pajak disiapkan, belum dihitung |
| Master HR (grade, jabatan, struktur, penghargaan) | ✅ | ⏳ | |
| Payroll (gaji pokok, uang harian) | ⏳ | ⏳ | Belum dirancang |
| Finance, Attendance | ⏳ | ⏳ | Belum dirancang |

✅ selesai · ⏳ belum

Skema: `database/schema.sql` berisi 44 tabel. Belum pernah dijalankan ke MySQL.

## Aturan skema
- Tabel operasional wajib `company_id` + FK ke `companies`.
- Tabel referensi (bank, wilayah, satuan, storage) tanpa `company_id`.
- Nomor booking dan invoice untuk pelanggan (`document_number`) acak seperti resi, dipakai link `/tiket/{document_number}` tanpa login.
- Nomor invoice (`invoice_no`) berurutan dari `document_sequences`.
