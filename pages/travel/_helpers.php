<?php
/**
 * Helper halaman travel/rental: badge status, opsi dropdown, guard company aktif.
 * Dipakai pages/travel/* dan pages/rental/* agar tidak duplikasi query opsi.
 */

function status_badge(string $s): string
{
    $map = [
        'draft' => 'secondary', 'confirmed' => 'info', 'scheduled' => 'primary',
        'on_trip' => 'warning', 'done' => 'success', 'cancelled' => 'danger',
        'rescheduled' => 'dark',
    ];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . e($s) . '</span>';
}

/** Keterangan waktu AM/PM versi Bahasa Indonesia (Pagi, Siang, Sore, Malam, Dini Hari). */
function time_period_label(string $time): string
{
    $parts = explode(':', trim($time));
    $h = (int) ($parts[0] ?? 0);
    if ($h >= 0 && $h < 5) {
        return 'Dini Hari (AM)';
    }
    if ($h >= 5 && $h < 11) {
        return 'Pagi (AM)';
    }
    if ($h >= 11 && $h < 15) {
        return 'Siang (PM)';
    }
    if ($h >= 15 && $h < 18) {
        return 'Sore (PM)';
    }
    return 'Malam (PM)';
}

/** Pastikan ada perusahaan aktif; kembalikan id-nya. */
function require_active_company(): int
{
    $cid = active_company_id();
    if ($cid === null) {
        flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
        redirect('pages/dashboard/index.php');
    }
    return $cid;
}

/** Opsi <select> dari query scoped perusahaan. $sel = nilai terpilih. */
function select_options(array $rows, $sel, $label_key = 'name'): string
{
    $html = '';
    if (!is_string($label_key) || $label_key === '') {
        $label_key = 'name';
    }
    $sel = is_array($sel) ? array_map('strval', $sel) : [(string) $sel];
    foreach ($rows as $r) {
        $selected = in_array((string) $r['id'], $sel, true) ? ' selected' : '';
        $html .= '<option value="' . (int) $r['id'] . '"' . $selected . '>' . e($r[$label_key] ?? $r['name'] ?? '') . '</option>';
    }
    return $html;
}

function opts_branches(int $company_id): array
{
    $st = db()->prepare("SELECT id, CONCAT(code, ' - ', name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS name FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_passengers(int $company_id): array
{
    $st = db()->prepare("SELECT id, CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS name FROM passengers WHERE company_id = ? ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_categories(int $company_id): array
{
    $st = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name FROM category_vehicles WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_vehicles(int $company_id): array
{
    $st = db()->prepare("SELECT v.id, v.category_vehicle_id,
            CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number, v.vehicle_color, v.seat) AS name
        FROM vehicles v
        LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
        LEFT JOIN model_units mu ON mu.id = v.model_unit_id
        WHERE v.company_id = ? AND v.is_active = 1
        ORDER BY v.vehicle_number");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Provinsi global (tabel referensi). */
function opts_provinces(): array
{
    return db()->query("SELECT id, CONCAT(code, ' - ', name) AS name FROM provinces ORDER BY code, name")->fetch_all(MYSQLI_ASSOC);
}

/** Kota global (tabel referensi, tanpa company_id). */
function opts_cities(?int $province_id = null): array
{
    if ($province_id !== null && $province_id > 0) {
        $st = db()->prepare('SELECT id, province_id, name FROM cities WHERE province_id = ? ORDER BY name');
        $st->bind_param('i', $province_id);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }
    return db()->query('SELECT id, province_id, name FROM cities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
}

/** Karyawan aktif perusahaan (calon driver/kernet/montir/agen). */
function opts_employees(int $company_id): array
{
    $st = db()->prepare("SELECT id, code, name AS raw_name, CONCAT(code, ' - ', name) AS name,
            COALESCE(job_title, '') AS job_title, COALESCE(job_position, '') AS job_position
        FROM employees WHERE company_id = ? AND status = 'aktif' ORDER BY code, name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Regulasi tarif premi karyawan rental per kategori kendaraan & tipe kru. */
function opts_rental_regulations(int $company_id): array
{
    $st = db()->prepare('SELECT category_vehicle_id, type, amount, trip, start_date, end_date
        FROM rental_car_regulation_employees WHERE company_id = ? ORDER BY id DESC');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}
