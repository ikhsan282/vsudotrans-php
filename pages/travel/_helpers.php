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
    $sel = is_array($sel) ? array_map('strval', $sel) : [(string) $sel];
    foreach ($rows as $r) {
        $selected = in_array((string) $r['id'], $sel, true) ? ' selected' : '';
        $html .= '<option value="' . (int) $r['id'] . '"' . $selected . '>' . e($r[$label_key]) . '</option>';
    }
    return $html;
}

function opts_branches(int $company_id): array
{
    $st = db()->prepare('SELECT id, name FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY name');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_passengers(int $company_id): array
{
    $st = db()->prepare('SELECT id, name FROM passengers WHERE company_id = ? ORDER BY name');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_categories(int $company_id): array
{
    $st = db()->prepare('SELECT id, name FROM category_vehicles WHERE company_id = ? AND is_active = 1 ORDER BY name');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function opts_vehicles(int $company_id): array
{
    $st = db()->prepare('SELECT id, CONCAT(vehicle_number, " - ", frame_number) AS name FROM vehicles WHERE company_id = ? AND is_active = 1 ORDER BY vehicle_number');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Kota global (tabel referensi, tanpa company_id). */
function opts_cities(): array
{
    return db()->query('SELECT id, name FROM cities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
}

/** Karyawan aktif perusahaan (calon driver). */
function opts_employees(int $company_id): array
{
    $st = db()->prepare("SELECT id, name FROM employees WHERE company_id = ? AND status = 'aktif' ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}
