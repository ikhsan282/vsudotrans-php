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

/** Daftar company_id yang boleh diakses user saat ini, selalu menyertakan $fallback_cid bila > 0. */
function accessible_company_ids(int $fallback_cid = 0): array
{
    $ids = [];
    if (function_exists('accessible_companies')) {
        foreach (accessible_companies() as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    if ($fallback_cid > 0) {
        $ids[] = $fallback_cid;
    }
    return array_values(array_unique($ids));
}

/**
 * Gabungkan baris wajib-terpilih ($include_ids) yang belum ada pada hasil LIMIT awal.
 * @param callable(int[]): array $loader
 */
function _merge_included_rows(array $rows, array $include_ids, callable $loader): array
{
    $need = [];
    $seen = [];
    foreach ($rows as $r) {
        $seen[(int) $r['id']] = true;
    }
    foreach ($include_ids as $raw_id) {
        $id = (int) $raw_id;
        if ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $need[] = $id;
        }
    }
    if (!$need) {
        return $rows;
    }
    $extra = $loader($need);
    return array_merge($extra, $rows);
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

/** Opsi penumpang perusahaan (dibatasi LIMIT agar aman hingga jutaan data + wajib sertakan $include_ids). */
function opts_passengers(int $company_id, array $include_ids = [], int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $st = db()->prepare(
        "SELECT id, code, phone_number,
                CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS name
           FROM passengers
          WHERE company_id = ?
          ORDER BY name ASC
          LIMIT ?"
    );
    $st->bind_param('ii', $company_id, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return _merge_included_rows($rows, $include_ids, static function (array $ids) use ($company_id): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $st2 = db()->prepare(
            "SELECT id, code, phone_number,
                    CONCAT(name, IF(phone_number IS NOT NULL AND phone_number <> '', CONCAT(' - ', phone_number), '')) AS name
               FROM passengers
              WHERE company_id = ? AND id IN ({$ph})
              ORDER BY name ASC"
        );
        $st2->bind_param($types, $company_id, ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

function opts_categories(int $company_id, array $include_ids = [], int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $st = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name, company_id FROM category_vehicles WHERE company_id = ? AND is_active = 1 ORDER BY code, name LIMIT ?");
    $st->bind_param('ii', $company_id, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return _merge_included_rows($rows, $include_ids, static function (array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name, company_id FROM category_vehicles WHERE id IN ({$ph}) ORDER BY code, name");
        $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

function opts_vehicles(int $company_id, array $include_ids = [], int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $st = db()->prepare("SELECT v.id, v.category_vehicle_id,
            CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number, v.vehicle_color, v.seat) AS name
        FROM vehicles v
        LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
        LEFT JOIN model_units mu ON mu.id = v.model_unit_id
        WHERE v.company_id = ? AND v.is_active = 1
        ORDER BY v.vehicle_number ASC
        LIMIT ?");
    $st->bind_param('ii', $company_id, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return _merge_included_rows($rows, $include_ids, static function (array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT v.id, v.category_vehicle_id,
                CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number, v.vehicle_color, v.seat) AS name
            FROM vehicles v
            LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
            LEFT JOIN model_units mu ON mu.id = v.model_unit_id
            WHERE v.id IN ({$ph})
            ORDER BY v.vehicle_number ASC");
        $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

/** Opsi kendaraan lengkap (untuk booking form/detail) dengan LIMIT & wajib sertakan $selected_id. */
function opts_vehicles_detailed(int $active_cid, array $company_ids, ?int $selected_id = null, ?int $category_id = null, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $cids = array_values(array_unique(array_filter(array_map('intval', $company_ids), static fn($x) => $x > 0)));
    if (!$cids && $active_cid > 0) {
        $cids = [$active_cid];
    }
    if (!$cids) {
        return [];
    }
    $compIn = implode(',', $cids);
    $where = "v.is_active = 1 AND v.company_id IN ({$compIn})";
    $types = '';
    $params = [];
    if ($category_id !== null && $category_id > 0) {
        $where .= ' AND v.category_vehicle_id = ?';
        $types .= 'i';
        $params[] = $category_id;
    }
    $sql = "SELECT v.id, v.company_id, v.category_vehicle_id, v.vehicle_number, v.vehicle_color, v.seat,
                   bv.name AS brand_name, mu.name AS model_name, cv.name AS category_name, c.code AS company_code,
                   CONCAT(v.vehicle_number, ' - ', COALESCE(bv.name, ''), ' ', COALESCE(mu.name, ''),
                          IF(v.vehicle_color != '' OR v.seat > 0, CONCAT(' (', CONCAT_WS(', ', NULLIF(v.vehicle_color, ''), IF(v.seat > 0, CONCAT(v.seat, ' Kursi'), NULL)), ')'), ''),
                          IF(v.company_id != ?, CONCAT(' [', c.code, ']'), '')) AS name
              FROM vehicles v
              LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
              LEFT JOIN model_units mu ON mu.id = v.model_unit_id
              LEFT JOIN category_vehicles cv ON cv.id = v.category_vehicle_id
              LEFT JOIN companies c ON c.id = v.company_id
             WHERE {$where}
             ORDER BY v.vehicle_number ASC
             LIMIT ?";
    $st = db()->prepare($sql);
    $st->bind_param('i' . $types . 'i', $active_cid, ...[...$params, $limit]);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $inc = ($selected_id !== null && $selected_id > 0) ? [$selected_id] : [];
    return _merge_included_rows($rows, $inc, static function (array $ids) use ($active_cid): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare(
            "SELECT v.id, v.company_id, v.category_vehicle_id, v.vehicle_number, v.vehicle_color, v.seat,
                    bv.name AS brand_name, mu.name AS model_name, cv.name AS category_name, c.code AS company_code,
                    CONCAT(v.vehicle_number, ' - ', COALESCE(bv.name, ''), ' ', COALESCE(mu.name, ''),
                           IF(v.vehicle_color != '' OR v.seat > 0, CONCAT(' (', CONCAT_WS(', ', NULLIF(v.vehicle_color, ''), IF(v.seat > 0, CONCAT(v.seat, ' Kursi'), NULL)), ')'), ''),
                           IF(v.company_id != ?, CONCAT(' [', c.code, ']'), '')) AS name
               FROM vehicles v
               LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
               LEFT JOIN model_units mu ON mu.id = v.model_unit_id
               LEFT JOIN category_vehicles cv ON cv.id = v.category_vehicle_id
               LEFT JOIN companies c ON c.id = v.company_id
              WHERE v.id IN ({$ph})"
        );
        $st2->bind_param('i' . str_repeat('i', count($ids)), $active_cid, ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

/** Provinsi global (tabel referensi, dibatasi LIMIT aman + sertakan terpilih). */
function opts_provinces(array $include_ids = [], int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $st = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name FROM provinces ORDER BY code, name LIMIT ?");
    $st->bind_param('i', $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return _merge_included_rows($rows, $include_ids, static function (array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name FROM provinces WHERE id IN ({$ph})");
        $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

/** Kota global (tabel referensi, dibatasi LIMIT agar aman saat jutaan data + wajib sertakan $include_ids). */
function opts_cities(?int $province_id = null, array $include_ids = [], int $limit = 100): array
{
    if ($province_id !== null && $province_id > 0) {
        $lim = max(1, min(200, $limit));
        $st = db()->prepare('SELECT id, province_id, name FROM cities WHERE province_id = ? ORDER BY name ASC LIMIT ?');
        $st->bind_param('ii', $province_id, $lim);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } else {
        $lim = max(1, min(100, min(50, $limit)));
        $st = db()->prepare('SELECT id, province_id, name FROM cities ORDER BY name ASC LIMIT ?');
        $st->bind_param('i', $lim);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    return _merge_included_rows($rows, $include_ids, static function (array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, province_id, name FROM cities WHERE id IN ({$ph})");
        $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

/** Kecamatan berdasarkan kota (+ wajib sertakan $include_ids), dibatasi LIMIT aman. */
function opts_districts_by_city(int $city_id, array $include_ids = [], int $limit = 100): array
{
    $rows = [];
    if ($city_id > 0) {
        $lim = max(1, min(200, $limit));
        $st = db()->prepare('SELECT id, city_id, province_id, name FROM districts WHERE city_id = ? ORDER BY name ASC LIMIT ?');
        $st->bind_param('ii', $city_id, $lim);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    return _merge_included_rows($rows, $include_ids, static function (array $ids): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, city_id, province_id, name FROM districts WHERE id IN ({$ph})");
        $st2->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
}

/** Lookup province_id dari satu city_id via Primary Key (O(1)). */
function resolve_city_province_id(int $city_id): int
{
    if ($city_id <= 0) {
        return 0;
    }
    $st = db()->prepare('SELECT province_id FROM cities WHERE id = ? LIMIT 1');
    $st->bind_param('i', $city_id);
    $st->execute();
    $val = $st->get_result()->fetch_column();
    $st->close();
    return $val !== false ? (int) $val : 0;
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

/**
 * Resolusi district_id, city_id, province_id dari nilai input (bisa primary key id atau kode Kemendagri).
 * @return array{id:int, city_id:int, province_id:int, name:string, code:string}|null
 */
function resolve_district_info($val): ?array
{
    if (empty($val)) {
        return null;
    }
    $v = (int) $val;
    if ($v > 0) {
        $st = db()->prepare('SELECT id, city_id, province_id, name, code FROM districts WHERE id = ? LIMIT 1');
        $st->bind_param('i', $v);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return [
                'id'          => (int) $row['id'],
                'city_id'     => (int) $row['city_id'],
                'province_id' => (int) $row['province_id'],
                'name'        => (string) $row['name'],
                'code'        => (string) $row['code'],
            ];
        }
    }

    $s = trim((string) $val);
    if ($s !== '') {
        $st2 = db()->prepare("SELECT id, city_id, province_id, name, code FROM districts WHERE code = ? OR REPLACE(code, '.', '') = ? LIMIT 1");
        $st2->bind_param('ss', $s, $s);
        $st2->execute();
        $row2 = $st2->get_result()->fetch_assoc();
        $st2->close();
        if ($row2) {
            return [
                'id'          => (int) $row2['id'],
                'city_id'     => (int) $row2['city_id'],
                'province_id' => (int) $row2['province_id'],
                'name'        => (string) $row2['name'],
                'code'        => (string) $row2['code'],
            ];
        }
    }

    return null;
}

function resolve_district_id($val): int
{
    $info = resolve_district_info($val);
    return $info ? $info['id'] : (int) $val;
}

/**
 * Kondisi WHERE pencarian resi, penumpang utama, maupun penumpang tambahan (booking_passengers).
 * Membutuhkan 5 parameter string ($q_like diulang 5x).
 */
function booking_passenger_search_sql(string $booking_type): string
{
    $bt = $booking_type === 'travel' ? 'travel' : 'rental';
    return "(b.document_number LIKE ? OR p.name LIKE ? OR p.phone_number LIKE ?
        OR EXISTS (
            SELECT 1 FROM booking_passengers bp
            JOIN passengers px ON px.id = bp.passenger_id
            WHERE bp.booking_type = '{$bt}' AND bp.booking_id = b.id
              AND (px.name LIKE ? OR px.phone_number LIKE ?)
        ))";
}

/**
 * Hitung jumlah penumpang tambahan (is_primary = 0) secara batch untuk baris halaman index (maks 1 query IN).
 * @param int[] $booking_ids
 * @return array<int,int>
 */
function booking_extra_passenger_counts(string $booking_type, array $booking_ids): array
{
    if (!in_array($booking_type, ['rental', 'travel'], true)) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $booking_ids), static fn($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT booking_id, COUNT(*) AS cnt
           FROM booking_passengers
          WHERE booking_type = ? AND is_primary = 0 AND booking_id IN ({$ph})
          GROUP BY booking_id"
    );
    $st->bind_param('s' . str_repeat('i', count($ids)), $booking_type, ...$ids);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int) $r['booking_id']] = (int) $r['cnt'];
    }
    $st->close();
    return $out;
}
