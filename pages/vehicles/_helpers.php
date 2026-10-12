<?php
/**
 * Kendaraan — fungsi pembantu. Sengaja tanpa dependensi config/DB di level atas
 * supaya bisa diuji (tests/vehicles_check.php). Query ber-tenant ada di bagian bawah.
 */

/** Nilai ownership, sama dengan EnumOwnership di VSudoTrans.API (Internal=0, Eksternal=1). */
const VEHICLE_OWNERSHIP = ['Internal' => 'Internal', 'Eksternal' => 'Eksternal'];

/** Tanggal Y-m-d dari POST: null = kosong, false = tidak valid. */
function vehicle_date(array $post, string $key): string|null|false
{
    $raw = trim((string) ($post[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : false;
}

/** Jatuh tempo dalam 30 hari (termasuk yang sudah lewat). */
function vehicle_due_soon(?string $date, DateTimeImmutable $today): bool
{
    if ($date === null || $date === '') {
        return false;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d <= $today->modify('+30 days');
}

/** Validasi data yang sudah dinormalisasi. Mengembalikan daftar pesan error (Indonesia). */
function vehicle_validate(array $d): array
{
    $errors = [];
    foreach (['brand_id' => 'Merek', 'model_unit_id' => 'Model', 'type_engine_id' => 'Tipe mesin', 'category_vehicle_id' => 'Kategori'] as $k => $label) {
        if ((int) $d[$k] <= 0) {
            $errors[] = "{$label} wajib dipilih.";
        }
    }
    if ($d['vehicle_number'] === '' || !preg_match('/^[A-Z0-9 ]{1,15}$/', $d['vehicle_number'])) {
        $errors[] = 'Nomor polisi wajib diisi (huruf, angka, spasi; maks 15 karakter).';
    }
    if ($d['frame_number'] === '') {
        $errors[] = 'Nomor rangka wajib diisi.';
    }
    if ($d['machine_number'] === '') {
        $errors[] = 'Nomor mesin wajib diisi.';
    }
    if ($d['vehicle_color'] === '') {
        $errors[] = 'Warna wajib diisi.';
    }
    $maxYear = (int) date('Y') + 1;
    if ($d['production_year'] < 1950 || $d['production_year'] > $maxYear) {
        $errors[] = "Tahun produksi harus antara 1950 dan {$maxYear}.";
    }
    if ($d['seat'] < 1 || $d['seat'] > 99) {
        $errors[] = 'Jumlah kursi harus 1–99.';
    }
    if (!isset(VEHICLE_OWNERSHIP[$d['ownership']])) {
        $errors[] = 'Kepemilikan tidak valid.';
    }
    foreach (['tax_due' => 'Jatuh tempo pajak', 'stnk_due' => 'Jatuh tempo STNK', 'kir_due' => 'Jatuh tempo KIR'] as $k => $label) {
        if ($d[$k] === false) {
            $errors[] = "{$label} tidak valid (format YYYY-MM-DD).";
        }
    }
    return $errors;
}

/** Ubah error duplikat MySQL (1062) menjadi pesan Indonesia. */
function vehicle_dup_message(string $sqlMessage): string
{
    $map = [
        'uq_vehicles_number'  => 'Nomor polisi sudah terdaftar di perusahaan ini.',
        'uq_vehicles_frame'   => 'Nomor rangka sudah terdaftar di perusahaan ini.',
        'uq_vehicles_machine' => 'Nomor mesin sudah terdaftar di perusahaan ini.',
    ];
    foreach ($map as $key => $msg) {
        if (str_contains($sqlMessage, $key)) {
            return $msg;
        }
    }
    return str_contains($sqlMessage, '_code') ? 'Kode sudah dipakai di perusahaan ini.' : 'Data sudah ada di perusahaan ini.';
}

/* ---------- query ber-tenant (memakai koneksi db() dari config) ---------- */

/** Tabel master milik perusahaan? $table hanya dari pemanggil tetap (whitelist), tidak dari input. */
function vehicle_owned(mysqli $db, string $table, int $id, int $cid): bool
{
    $st = $db->prepare("SELECT 1 FROM {$table} WHERE id = ? AND company_id = ?");
    $st->bind_param('ii', $id, $cid);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/** Cek referensi master: milik perusahaan, dan model harus milik merek yang dipilih. */
function vehicle_check_refs(mysqli $db, int $cid, array $d): array
{
    $errors = [];
    $checks = [
        ['brand_vehicles', 'brand_id', 'Merek'],
        ['model_units', 'model_unit_id', 'Model'],
        ['type_engines', 'type_engine_id', 'Tipe mesin'],
        ['category_vehicles', 'category_vehicle_id', 'Kategori'],
    ];
    foreach ($checks as [$table, $col, $label]) {
        if ((int) $d[$col] > 0 && !vehicle_owned($db, $table, (int) $d[$col], $cid)) {
            $errors[] = "{$label} tidak ditemukan di perusahaan ini.";
        }
    }
    if (!$errors) {
        $st = $db->prepare('SELECT brand_id FROM model_units WHERE id = ? AND company_id = ?');
        $st->bind_param('ii', $d['model_unit_id'], $cid);
        $st->execute();
        $brand = $st->get_result()->fetch_row()[0] ?? null;
        $st->close();
        if ((int) $brand !== (int) $d['brand_id']) {
            $errors[] = 'Model tidak sesuai dengan merek yang dipilih.';
        }
    }
    return $errors;
}

/** Opsi dropdown master aktif perusahaan (+ baris saat ini walau nonaktif). Table dari pemanggil tetap. */
function vehicle_options(mysqli $db, string $table, int $cid, int $current): array
{
    $st = $db->prepare("SELECT id, name FROM {$table} WHERE company_id = ? AND (is_active = 1 OR id = ?) ORDER BY name");
    $st->bind_param('ii', $cid, $current);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}
