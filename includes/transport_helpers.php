<?php
/**
 * Helper bersama domain transportasi & booking (Travel, Rental, Transportasi, Penjadwalan, Keuangan).
 * Ditempatkan terpusat di `includes/` agar lintas modul tidak saling meng-include halaman satu sama lain.
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

    return _merge_included_rows($rows, $include_ids, static function (array $ids) use ($company_id): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, CONCAT(code, ' - ', name) AS name, company_id FROM category_vehicles WHERE company_id = ? AND id IN ({$ph}) ORDER BY code, name");
        $st2->bind_param('i' . str_repeat('i', count($ids)), $company_id, ...$ids);
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

    return _merge_included_rows($rows, $include_ids, static function (array $ids) use ($company_id): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT v.id, v.category_vehicle_id,
                CONCAT_WS(' - ', bv.name, mu.name, v.vehicle_number, v.vehicle_color, v.seat) AS name
            FROM vehicles v
            LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id
            LEFT JOIN model_units mu ON mu.id = v.model_unit_id
            WHERE v.company_id = ? AND v.id IN ({$ph})
            ORDER BY v.vehicle_number ASC");
        $st2->bind_param('i' . str_repeat('i', count($ids)), $company_id, ...$ids);
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
    return _merge_included_rows($rows, $inc, static function (array $ids) use ($active_cid, $compIn): array {
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
              WHERE v.company_id IN ({$compIn}) AND v.id IN ({$ph})"
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
function opts_cities(?int $province_id = null, array $include_ids = [], int $limit = 100, bool $with_province = false): array
{
    $nameExpr = $with_province
        ? "CONCAT(c.name, IF(p.name IS NOT NULL AND p.name <> '', CONCAT(' (', p.name, ')'), '')) AS name"
        : 'c.name AS name';
    $selectCols = "c.id, c.province_id, {$nameExpr}" . ($with_province ? ', p.name AS province_name' : '');
    $joinSql    = $with_province ? ' LEFT JOIN provinces p ON p.id = c.province_id' : '';

    if ($province_id !== null && $province_id > 0) {
        $lim = max(1, min(200, $limit));
        $st = db()->prepare("SELECT {$selectCols} FROM cities c{$joinSql} WHERE c.province_id = ? ORDER BY c.name ASC LIMIT ?");
        $st->bind_param('ii', $province_id, $lim);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } else {
        $lim = max(1, min(100, min(50, $limit)));
        $st = db()->prepare("SELECT {$selectCols} FROM cities c{$joinSql} ORDER BY c.name ASC LIMIT ?");
        $st->bind_param('i', $lim);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    return _merge_included_rows($rows, $include_ids, static function (array $ids) use ($selectCols, $joinSql): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT {$selectCols} FROM cities c{$joinSql} WHERE c.id IN ({$ph})");
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

/** Ambil daftar kecamatan berlabel "Kecamatan — Kota" untuk ID terpilih (cakupan radius rute). */
function opts_districts_with_city(array $include_ids = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $include_ids), static fn ($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT d.id, d.city_id,
                CONCAT(d.name, IF(c.name IS NOT NULL AND c.name <> '', CONCAT(' — ', c.name), '')) AS name
           FROM districts d
           LEFT JOIN cities c ON c.id = d.city_id
          WHERE d.id IN ({$ph})
          ORDER BY d.name ASC"
    );
    $st->bind_param(str_repeat('i', count($ids)), ...$ids);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
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

/** Karyawan aktif perusahaan (dibatasi LIMIT + wajib sertakan $include_ids, aman jutaan data). */
function opts_employees(int $company_id, array $include_ids = [], int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $st = db()->prepare("SELECT id, code, name AS raw_name, CONCAT(code, ' - ', name) AS name,
            COALESCE(job_title, '') AS job_title, COALESCE(job_position, '') AS job_position
        FROM employees WHERE company_id = ? AND status = 'aktif' ORDER BY name ASC LIMIT ?");
    $st->bind_param('ii', $company_id, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    return _merge_included_rows($rows, $include_ids, static function (array $ids) use ($company_id): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st2 = db()->prepare("SELECT id, code, name AS raw_name, CONCAT(code, ' - ', name) AS name,
                COALESCE(job_title, '') AS job_title, COALESCE(job_position, '') AS job_position
            FROM employees WHERE company_id = ? AND id IN ({$ph}) ORDER BY name ASC");
        $st2->bind_param('i' . str_repeat('i', count($ids)), $company_id, ...$ids);
        $st2->execute();
        $out = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();
        return $out;
    });
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

/**
 * Ekspresi SQL subquery berkorelasi untuk mengekspor daftar penumpang tambahan (is_primary = 0)
 * tanpa N+1 query dan tetap kompatibel dengan streaming unbuffered cursor (MYSQLI_USE_RESULT).
 * Menggunakan index ix_bp_booking (booking_type, booking_id) -> eq_ref passengers(id).
 */
function booking_extra_passengers_export_sql(string $booking_type): string
{
    if (!in_array($booking_type, ['rental', 'travel'], true)) {
        return 'NULL';
    }
    $bt = $booking_type === 'rental' ? 'rental' : 'travel';
    return "(SELECT GROUP_CONCAT(
                CASE WHEN px.phone_number IS NOT NULL AND px.phone_number <> ''
                     THEN CONCAT(px.name, ' (', px.phone_number, ')')
                     ELSE px.name
                END
                ORDER BY bp.id ASC SEPARATOR ', '
            )
            FROM booking_passengers bp
            JOIN passengers px ON px.id = bp.passenger_id
            WHERE bp.booking_type = '{$bt}' AND bp.booking_id = b.id AND bp.is_primary = 0)";
}

/**
 * Ekspresi SQL subquery jumlah penumpang tambahan untuk export PDF ringkas (sesuai badge grid UI).
 */
function booking_extra_passenger_count_sql(string $booking_type): string
{
    if (!in_array($booking_type, ['rental', 'travel'], true)) {
        return '0';
    }
    $bt = $booking_type === 'rental' ? 'rental' : 'travel';
    return "(SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_type = '{$bt}' AND bp.booking_id = b.id AND bp.is_primary = 0)";
}

/**
 * Daftar kolom header export booking Travel/Rental (PDF = 1:1 grid UI, XLSX = flat Penumpang Tambahan).
 */
function booking_export_headers(bool $isPdf, string $originLabel, string $destLabel): array
{
    if ($isPdf) {
        return ['Resi', 'Penumpang', 'Kendaraan', 'Jam', 'Tanggal', $originLabel, $destLabel, 'Total Harga', 'Total Pembayaran', 'Status'];
    }
    return ['Resi', 'Penumpang (Nama & HP)', 'Penumpang Tambahan', 'Kendaraan', 'Jam', 'Tanggal', $originLabel, $destLabel, 'Total Harga', 'Total Pembayaran', 'Status'];
}

/**
 * Format 1 baris export booking Travel/Rental untuk PDF (ringkas 1:1 UI) atau XLSX (kolom datar).
 */
function booking_export_row(array $r, bool $isPdf, string $dateLabel): array
{
    $plate      = trim((string) ($r['vehicle_number'] ?? ''));
    $brandModel = trim(implode(' - ', array_filter([$r['brand_name'] ?? '', $r['model_name'] ?? ''])));
    $catName    = trim((string) ($r['category_name'] ?? ''));

    if ($isPdf) {
        $vehSub = $brandModel !== '' ? $brandModel : $catName;
        if ($plate !== '') {
            $veh = $plate . ($vehSub !== '' ? "\n" . $vehSub : '');
        } elseif ($brandModel !== '') {
            $veh = $brandModel . ($catName !== '' ? "\n" . $catName : '');
        } else {
            $veh = $catName !== '' ? $catName : '-';
        }
    } else {
        $veh = $plate !== '' ? ($plate . ($brandModel !== '' ? ' (' . $brandModel . ')' : '')) : ($brandModel ?: ($catName ?: '-'));
    }

    $jam    = !empty($r['time']) ? substr((string) $r['time'], 0, 5) : '-';
    $pickup = ($r['pickup_city_name'] ?? '') ?: '-';
    $deliv  = ($r['delivery_city_name'] ?? '') ?: '-';
    $status = ucfirst((string) ($r['status'] ?? ''));

    if ($isPdf) {
        $exCnt   = (int) ($r['extra_cnt'] ?? 0);
        $paxHead = (string) ($r['passenger_name'] ?? '') . ($exCnt > 0 ? " (+{$exCnt} tambahan)" : '');
        $paxCell = $paxHead . "\n" . (($r['passenger_phone'] ?? '') ?: '-');
        return [
            $r['document_number'],
            $paxCell,
            $veh,
            $jam,
            $dateLabel,
            $pickup,
            $deliv,
            rupiah((float) ($r['total_price'] ?? 0)),
            rupiah((float) ($r['total_payment'] ?? 0)),
            $status,
        ];
    }

    $extraPax = trim((string) ($r['extra_passengers'] ?? ''));
    return [
        $r['document_number'],
        trim((string) ($r['passenger_name'] ?? '') . (!empty($r['passenger_phone']) ? ' (' . $r['passenger_phone'] . ')' : '')),
        $extraPax !== '' ? $extraPax : '-',
        $veh,
        $jam,
        $dateLabel,
        $pickup,
        $deliv,
        (float) ($r['total_price'] ?? 0),
        (float) ($r['total_payment'] ?? 0),
        $status,
    ];
}

/**
 * Resolusi filter CompanyId seragam untuk halaman daftar Travel & Rental.
 * Default mengikuti perusahaan aktif dari switcher header; hanya Super Admin yang memilih
 * company_id=0 secara eksplisit dapat melihat lintas perusahaan.
 *
 * @return array{filter_company_id:int, where:string, types:string, params:array<int,mixed>, companies:array<int,array<string,mixed>>, active_cid:?int}
 */
function company_filter_scope(string $column, ?array $query = null): array
{
    $src        = $query ?? $_GET;
    $companies  = accessible_companies();
    $active_cid = active_company_id();

    if (isset($src['company_id'])) {
        $filter_company_id = $src['company_id'] !== '' ? (int) $src['company_id'] : 0;
    } else {
        $filter_company_id = $active_cid ?? 0;
    }

    if ($filter_company_id > 0) {
        $allowed = is_super_admin() || in_array($filter_company_id, array_map('intval', array_column($companies, 'id')), true);
        if ($allowed) {
            $where  = "{$column} = ?";
            $types  = 'i';
            $params = [$filter_company_id];
        } else {
            [$where, $types, $params] = company_scope($column);
            $filter_company_id = $active_cid ?? 0;
        }
    } elseif ($filter_company_id === 0 && is_super_admin()) {
        $where  = '1=1';
        $types  = '';
        $params = [];
    } else {
        [$where, $types, $params] = company_scope($column);
        $filter_company_id = $active_cid ?? 0;
    }

    return [
        'filter_company_id' => $filter_company_id,
        'where'             => $where,
        'types'             => $types,
        'params'            => $params,
        'companies'         => $companies,
        'active_cid'        => $active_cid,
    ];
}

/** Ambil satu baris entitas referensi bila benar-benar milik $company_id (whitelist tabel). */
function fetch_company_ref_row(string $table, int $id, int $company_id): ?array
{
    $colsMap = [
        'branches'          => 'id',
        'passengers'        => 'id',
        'category_vehicles' => 'id',
        'vehicles'          => 'id, category_vehicle_id',
        'employees'         => 'id, status',
    ];
    if ($id <= 0 || $company_id <= 0 || !isset($colsMap[$table])) {
        return null;
    }
    $st = db()->prepare("SELECT {$colsMap[$table]} FROM {$table} WHERE id = ? AND company_id = ? LIMIT 1");
    $st->bind_param('ii', $id, $company_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Pastikan seluruh ID (non-kosong) pada array benar-benar milik $company_id. */
function verify_company_ids_belong(string $table, array $ids, int $company_id): bool
{
    if (!in_array($table, ['passengers', 'employees'], true)) {
        return false;
    }
    $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$clean) {
        return true;
    }
    if ($company_id <= 0) {
        return false;
    }
    $ph = implode(',', array_fill(0, count($clean), '?'));
    $st = db()->prepare("SELECT COUNT(DISTINCT id) FROM {$table} WHERE company_id = ? AND id IN ({$ph})");
    $st->bind_param('i' . str_repeat('i', count($clean)), $company_id, ...$clean);
    $st->execute();
    $cnt = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $cnt === count($clean);
}

/** Validasi seragam kepemilikan referensi booking (cabang, pelanggan, kategori, kendaraan, kru) terhadap $company_id. */
function validate_booking_company_scope(
    int $company_id,
    int $branch_id,
    int $passenger_id,
    array $extra_passenger_ids,
    ?int &$category_vehicle_id,
    ?int $vehicle_id,
    array $crew_employee_ids = []
): void {
    if (!fetch_company_ref_row('branches', $branch_id, $company_id)) {
        throw new InvalidArgumentException('Cabang tidak valid untuk perusahaan ini.');
    }
    if (!fetch_company_ref_row('passengers', $passenger_id, $company_id)) {
        throw new InvalidArgumentException('Pelanggan utama tidak valid untuk perusahaan ini.');
    }
    if (!verify_company_ids_belong('passengers', $extra_passenger_ids, $company_id)) {
        throw new InvalidArgumentException('Penumpang tambahan tidak valid untuk perusahaan ini.');
    }
    if ($category_vehicle_id !== null && $category_vehicle_id > 0 && !fetch_company_ref_row('category_vehicles', $category_vehicle_id, $company_id)) {
        throw new InvalidArgumentException('Kategori kendaraan tidak valid untuk perusahaan ini.');
    }
    if ($vehicle_id !== null && $vehicle_id > 0) {
        $vehRow = fetch_company_ref_row('vehicles', $vehicle_id, $company_id);
        if (!$vehRow) {
            throw new InvalidArgumentException('Kendaraan tidak valid atau bukan milik perusahaan yang dipilih.');
        }
        if (($category_vehicle_id === null || $category_vehicle_id <= 0) && !empty($vehRow['category_vehicle_id'])) {
            $category_vehicle_id = (int) $vehRow['category_vehicle_id'];
        }
    }
    if ($crew_employee_ids && !verify_company_ids_belong('employees', $crew_employee_ids, $company_id)) {
        throw new InvalidArgumentException('Karyawan / kru tidak valid untuk perusahaan ini.');
    }
}

/** Opsi HTML <option> untuk dropdown filter perusahaan pada halaman daftar Travel/Rental. */
function ui_company_filter_options(array $companies, int $filter_company_id, bool $has_explicit_query): string
{
    $html = '';
    if (is_super_admin()) {
        $selAll = ($filter_company_id === 0 && $has_explicit_query) ? ' selected' : '';
        $html .= '<option value="0"' . $selAll . '>Semua Perusahaan</option>';
    }
    foreach ($companies as $c) {
        $cidVal = (int) $c['id'];
        $sel    = ($cidVal === $filter_company_id) ? ' selected' : '';
        $html  .= '<option value="' . $cidVal . '"' . $sel . '>' . e($c['code'] . ' - ' . $c['name']) . '</option>';
    }
    return $html;
}

/**
 * Parse baris kru & komisi (crew_type[], crew_employee_id[], crew_amount[]) dari $_POST.
 *
 * @return array<int, array{type:string, employee_id:int, amount:float}>
 */
function booking_parse_crew_post(array $post): array
{
    $c_types = (array) ($post['crew_type'] ?? []);
    $c_emps  = (array) ($post['crew_employee_id'] ?? []);
    $c_amts  = (array) ($post['crew_amount'] ?? []);
    $rows    = [];
    foreach ($c_types as $idx => $ct) {
        $empId = (int) ($c_emps[$idx] ?? 0);
        $amt   = parse_rupiah_input($c_amts[$idx] ?? 0);
        if ($empId > 0 || $amt > 0) {
            $rows[] = [
                'type'        => trim((string) $ct) ?: 'Sopir',
                'employee_id' => $empId,
                'amount'      => $amt,
            ];
        }
    }
    return $rows;
}

/**
 * Parse baris pembayaran (pay_id[], pay_type[], pay_method[], pay_date[], pay_amount[]) dari $_POST.
 * Menyimpan index asli `_file_idx` agar cocok dengan $_FILES['pay_proof'].
 *
 * @return array<int, array{
 *   _file_idx:int, id:int, pay_type:string, pay_method:string, pay_date:string, amount:float
 * }>
 */
function booking_parse_payments_post(array $post): array
{
    $p_ids     = (array) ($post['pay_id'] ?? []);
    $p_types   = (array) ($post['pay_type'] ?? []);
    $p_methods = (array) ($post['pay_method'] ?? []);
    $p_dates   = (array) ($post['pay_date'] ?? []);
    $p_amts    = (array) ($post['pay_amount'] ?? []);
    $rows      = [];
    foreach ($p_types as $idx => $pt) {
        $pAmt = parse_rupiah_input($p_amts[$idx] ?? 0);
        if ($pAmt > 0) {
            $rows[] = [
                '_file_idx'  => (int) $idx,
                'id'         => (int) ($p_ids[$idx] ?? 0),
                'pay_type'   => trim((string) $pt) ?: 'Down Payment (DP)',
                'pay_method' => trim((string) ($p_methods[$idx] ?? 'Transfer')) ?: 'Transfer',
                'pay_date'   => trim((string) ($p_dates[$idx] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'amount'     => $pAmt,
            ];
        }
    }
    return $rows;
}

/** Unggah bukti pembayaran terindeks per baris pembayaran untuk invoice terkait. */
function booking_upload_payment_proofs(array &$payments, int $company_id, int $invoice_id, string $fileField = 'pay_proof'): void
{
    if ($invoice_id <= 0 || !$payments) {
        return;
    }
    foreach ($payments as &$pr) {
        $fIdx = (int) ($pr['_file_idx'] ?? 0);
        $uploaded = azure_blob_handle_upload_indexed(
            $fileField,
            $fIdx,
            $company_id,
            'finance/payment-proofs',
            date('Y/m'),
            'inv-' . $invoice_id
        );
        if ($uploaded !== null) {
            $pr['proof_blob_path'] = $uploaded;
        }
    }
    unset($pr);
}

const RUTE_SCHEDULE_SLOTS = [
    '00:00', '01:00', '02:00', '03:00', '04:00', '05:00',
    '06:00', '07:00', '08:00', '09:00', '10:00', '11:00',
    '12:00', '13:00', '14:00', '15:00', '16:00', '17:00',
    '18:00', '19:00', '20:00', '21:00', '22:00', '23:00',
];

const RUTE_GRID_MAX_VISIBLE_SLOTS = 7;

/** Pastikan kolom radius penjemputan/pengantaran pada `rutes` serta tabel `rute_schedules` tersedia di DB. */
function rute_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = db();
    $wantCols = [
        'seat_capacity'               => 'INT(11) NOT NULL DEFAULT 0 AFTER `end_capacity_seat`',
        'pickup_free_radius_km'       => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `seat_capacity`',
        'pickup_max_radius_km'        => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pickup_free_radius_km`',
        'pickup_extra_price_per_km'   => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `pickup_max_radius_km`',
        'delivery_free_radius_km'     => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pickup_extra_price_per_km`',
        'delivery_max_radius_km'      => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_free_radius_km`',
        'delivery_extra_price_per_km' => 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `delivery_max_radius_km`',
    ];
    $have = [];
    $res  = $db->query('SHOW COLUMNS FROM `rutes`');
    while ($col = $res->fetch_assoc()) {
        $have[(string) $col['Field']] = true;
    }
    foreach ($wantCols as $colName => $ddl) {
        if (empty($have[$colName])) {
            $db->query("ALTER TABLE `rutes` ADD COLUMN `{$colName}` {$ddl}");
            if ($colName === 'seat_capacity') {
                $db->query('UPDATE `rutes` SET `seat_capacity` = `end_capacity_seat` WHERE `seat_capacity` = 0 AND `end_capacity_seat` > 0');
            }
        }
    }
    rute_tariff_ensure_schema();
    rute_schedule_ensure_schema();
    rute_radius_ensure_schema();
}

/** Pastikan tabel `rute_tariffs` (tarif & kapasitas per kategori kendaraan per rute) tersedia dan ter-backfill. */
function rute_tariff_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = db();
    $db->query(
        "CREATE TABLE IF NOT EXISTS `rute_tariffs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `company_id` INT(11) NOT NULL,
            `rute_id` INT(11) NOT NULL,
            `category_vehicle_id` INT(11) NOT NULL,
            `min_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `max_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `start_capacity_seat` INT(11) NOT NULL DEFAULT 0,
            `end_capacity_seat` INT(11) NOT NULL DEFAULT 0,
            `seat_capacity` INT(11) NOT NULL DEFAULT 0,
            `note` VARCHAR(2000) DEFAULT NULL,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_user` VARCHAR(255) DEFAULT NULL,
            `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `modified_user` VARCHAR(255) DEFAULT NULL,
            `modified_date` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rute_tariffs_cat` (`rute_id`, `category_vehicle_id`),
            KEY `ix_rute_tariffs_company` (`company_id`),
            KEY `ix_rute_tariffs_rute_cat` (`rute_id`, `category_vehicle_id`),
            KEY `ix_rute_tariffs_category` (`category_vehicle_id`),
            CONSTRAINT `fk_rute_tariffs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_rute_tariffs_rute` FOREIGN KEY (`rute_id`) REFERENCES `rutes` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rute_tariffs_category` FOREIGN KEY (`category_vehicle_id`) REFERENCES `category_vehicles` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $haveTariff = [];
    $resCol = $db->query('SHOW COLUMNS FROM `rute_tariffs`');
    while ($col = $resCol->fetch_assoc()) {
        $haveTariff[(string) $col['Field']] = true;
    }
    if (empty($haveTariff['seat_capacity'])) {
        $db->query('ALTER TABLE `rute_tariffs` ADD COLUMN `seat_capacity` INT(11) NOT NULL DEFAULT 0 AFTER `end_capacity_seat`');
        $db->query('UPDATE `rute_tariffs` SET `seat_capacity` = `end_capacity_seat` WHERE `seat_capacity` = 0 AND `end_capacity_seat` > 0');
    }

    // Backfill 1x bagi rute lama yang belum memiliki baris di `rute_tariffs`
    $db->query(
        "INSERT IGNORE INTO `rute_tariffs`
            (`company_id`, `rute_id`, `category_vehicle_id`, `min_price`, `price`, `max_price`,
             `start_capacity_seat`, `end_capacity_seat`, `seat_capacity`, `note`, `sort_order`, `created_user`)
         SELECT r.`company_id`, r.`id`, r.`category_vehicle_id`, r.`min_price`, r.`price`, r.`max_price`,
                r.`start_capacity_seat`, r.`end_capacity_seat`,
                COALESCE(NULLIF(r.`seat_capacity`, 0), r.`end_capacity_seat`, 0),
                r.`note`, 1, r.`created_user`
           FROM `rutes` r
          WHERE r.`category_vehicle_id` > 0
            AND NOT EXISTS (SELECT 1 FROM `rute_tariffs` rt WHERE rt.`rute_id` = r.`id`)"
    );
}

/** Parse nominal Rupiah bertanda agar input negatif tetap terdeteksi validasi. */
function rute_parse_signed_amount(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }
    $str = trim((string) $raw);
    if ($str === '') {
        return 0.0;
    }
    $neg = str_starts_with($str, '-');
    $abs = parse_rupiah_input($neg ? ltrim($str, "- \t") : $str);
    return $neg ? -$abs : $abs;
}

/** Ambil peta [rute_id => list baris tarif kategori] dari tabel `rute_tariffs`. */
function rute_tariffs_by_rute_ids(array $rute_ids, int $company_id = 0): array
{
    rute_tariff_ensure_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $rute_ids), static fn ($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $sql   = "SELECT rt.id, rt.rute_id, rt.category_vehicle_id, rt.min_price, rt.price, rt.max_price,
                     rt.start_capacity_seat, rt.end_capacity_seat, rt.seat_capacity, rt.note, rt.sort_order,
                     cv.code AS category_code, cv.name AS category_name
                FROM `rute_tariffs` rt
                JOIN `category_vehicles` cv ON cv.id = rt.category_vehicle_id
               WHERE rt.rute_id IN ({$ph})";
    $types = str_repeat('i', count($ids));
    $args  = $ids;
    if ($company_id > 0) {
        $sql   .= ' AND rt.company_id = ?';
        $types .= 'i';
        $args[] = $company_id;
    }
    $sql .= ' ORDER BY rt.sort_order ASC, rt.id ASC';
    $st = db()->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rid     = (int) $r['rute_id'];
        $seatCap = (int) ($r['seat_capacity'] ?? 0);
        if ($seatCap <= 0) {
            $seatCap = (int) ($r['end_capacity_seat'] ?? 0);
        }
        $out[$rid][] = [
            'id'                  => (int) $r['id'],
            'rute_id'             => $rid,
            'category_vehicle_id' => (int) $r['category_vehicle_id'],
            'category_code'       => (string) $r['category_code'],
            'category_name'       => (string) $r['category_name'],
            'min_price'           => (float) $r['min_price'],
            'price'               => (float) $r['price'],
            'max_price'           => (float) $r['max_price'],
            'seat_capacity'       => $seatCap,
            'start_capacity_seat' => (int) ($r['start_capacity_seat'] ?? ($seatCap > 0 ? 1 : 0)),
            'end_capacity_seat'   => $seatCap > 0 ? $seatCap : (int) ($r['end_capacity_seat'] ?? 0),
            'note'                => (string) ($r['note'] ?? ''),
            'sort_order'          => (int) $r['sort_order'],
        ];
    }
    $st->close();
    return $out;
}

/** Ambil daftar tarif kategori milik satu rute. */
function rute_tariffs_for_rute(int $rute_id, int $company_id = 0): array
{
    if ($rute_id <= 0) {
        return [];
    }
    $map = rute_tariffs_by_rute_ids([$rute_id], $company_id);
    return $map[$rute_id] ?? [];
}

/** Normalisasi dan validasi list tarif kategori kendaraan dari form rute. */
function rute_tariffs_normalize_and_validate(array $rows, int $company_id): array
{
    $clean = [];
    $seenCats = [];
    $sort = 1;
    foreach ($rows as $raw) {
        $catId     = (int) ($raw['category_vehicle_id'] ?? 0);
        $minP      = round(rute_parse_signed_amount($raw['min_price'] ?? 0), 2);
        $price     = round(rute_parse_signed_amount($raw['price'] ?? 0), 2);
        $maxP      = round(rute_parse_signed_amount($raw['max_price'] ?? 0), 2);
        $hasSeatIn = array_key_exists('seat_capacity', $raw) && $raw['seat_capacity'] !== '' && $raw['seat_capacity'] !== null;
        $seatCap   = $hasSeatIn ? (int) $raw['seat_capacity'] : (int) ($raw['end_capacity_seat'] ?? 0);
        $seatS     = $hasSeatIn ? ($seatCap > 0 ? 1 : 0) : (int) ($raw['start_capacity_seat'] ?? ($seatCap > 0 ? 1 : 0));
        $seatE     = $seatCap;
        $note      = trim((string) ($raw['note'] ?? ''));

        if ($catId <= 0 && $price == 0.0 && $minP == 0.0 && $maxP == 0.0 && $seatCap === 0 && $seatS === 0 && $note === '') {
            continue;
        }
        if ($catId <= 0) {
            throw new InvalidArgumentException('Setiap baris tarif wajib memilih kategori kendaraan.');
        }
        if (!fetch_company_ref_row('category_vehicles', $catId, $company_id)) {
            throw new InvalidArgumentException('Kategori kendaraan tidak valid atau bukan milik perusahaan yang dipilih.');
        }
        if (isset($seenCats[$catId])) {
            throw new InvalidArgumentException('Kategori kendaraan tidak boleh duplikat pada rute yang sama.');
        }
        $seenCats[$catId] = true;

        if ($price < 0 || $minP < 0 || $maxP < 0 || $seatCap < 0 || $seatS < 0) {
            throw new InvalidArgumentException('Harga dan jumlah kursi pada daftar tarif tidak boleh negatif.');
        }
        if ($seatCap > 999) {
            throw new InvalidArgumentException('Jumlah kursi maksimal 999.');
        }
        if ($minP > 0 && $minP > $price) {
            throw new InvalidArgumentException('Harga minimum tidak boleh melebihi harga standar.');
        }
        if ($maxP > 0 && $price > $maxP) {
            throw new InvalidArgumentException('Harga standar tidak boleh melebihi harga maksimum.');
        }
        if (!$hasSeatIn && $seatE > 0 && $seatS > $seatE) {
            throw new InvalidArgumentException('Kursi awal tidak boleh melebihi kursi akhir.');
        }
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Catatan baris tarif maksimal 2000 karakter.');
        }

        $clean[] = [
            'category_vehicle_id' => $catId,
            'min_price'           => $minP,
            'price'               => $price,
            'max_price'           => $maxP,
            'seat_capacity'       => $seatCap,
            'start_capacity_seat' => $seatS,
            'end_capacity_seat'   => $seatE,
            'note'                => $note,
            'sort_order'          => $sort++,
        ];
    }

    if (!$clean) {
        throw new InvalidArgumentException('Tambahkan minimal 1 kategori kendaraan beserta harga tiketnya.');
    }
    return $clean;
}

/** Sinkronkan daftar tarif per kategori kendaraan pada `rute_tariffs` untuk rute terkait. */
function rute_tariffs_sync(int $rute_id, int $company_id, array $tariffs, ?string $user = null): void
{
    if ($rute_id <= 0 || $company_id <= 0) {
        return;
    }
    rute_tariff_ensure_schema();
    $db = db();

    $keepCats = array_values(array_map(static fn (array $t): int => (int) $t['category_vehicle_id'], $tariffs));
    if ($keepCats) {
        $ph = implode(',', array_fill(0, count($keepCats), '?'));
        $stDel = $db->prepare("DELETE FROM `rute_tariffs` WHERE rute_id = ? AND company_id = ? AND category_vehicle_id NOT IN ({$ph})");
        $stDel->bind_param('ii' . str_repeat('i', count($keepCats)), $rute_id, $company_id, ...$keepCats);
        $stDel->execute();
        $stDel->close();
    }

    $sqlUp = "INSERT INTO `rute_tariffs`
                (`company_id`, `rute_id`, `category_vehicle_id`, `min_price`, `price`, `max_price`,
                 `start_capacity_seat`, `end_capacity_seat`, `seat_capacity`, `note`, `sort_order`, `created_user`)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                `min_price` = VALUES(`min_price`),
                `price` = VALUES(`price`),
                `max_price` = VALUES(`max_price`),
                `start_capacity_seat` = VALUES(`start_capacity_seat`),
                `end_capacity_seat` = VALUES(`end_capacity_seat`),
                `seat_capacity` = VALUES(`seat_capacity`),
                `note` = VALUES(`note`),
                `sort_order` = VALUES(`sort_order`),
                `modified_user` = ?,
                `modified_date` = NOW()";
    $stUp = $db->prepare($sqlUp);
    foreach ($tariffs as $t) {
        $catId   = (int) $t['category_vehicle_id'];
        $minP    = (float) $t['min_price'];
        $price   = (float) $t['price'];
        $maxP    = (float) $t['max_price'];
        $seatCap = (int) ($t['seat_capacity'] ?? $t['end_capacity_seat'] ?? 0);
        $seatS   = (int) ($t['start_capacity_seat'] ?? ($seatCap > 0 ? 1 : 0));
        $seatE   = $seatCap > 0 ? $seatCap : (int) ($t['end_capacity_seat'] ?? 0);
        $note    = $t['note'] !== '' ? (string) $t['note'] : null;
        $sort    = (int) $t['sort_order'];
        $stUp->bind_param(
            'iiidddiiisiss',
            $company_id,
            $rute_id,
            $catId,
            $minP,
            $price,
            $maxP,
            $seatS,
            $seatE,
            $seatCap,
            $note,
            $sort,
            $user,
            $user
        );
        $stUp->execute();
    }
    $stUp->close();
}

/** Format pasangan Provinsi (atas/utama) & Kota untuk grid maupun export PDF/Excel rute. */
function rute_format_loc_cell(string $city, string $province, bool $multiline = true): string
{
    $prov = trim($province);
    $cty  = trim($city);
    if ($prov === '') {
        return $cty !== '' ? $cty : '-';
    }
    return $multiline ? "{$prov}\n{$cty}" : "{$prov} - {$cty}";
}

/** Format daftar jam keberangkatan ENUM (bisa dibatasi maxVisible + (+N) agar seragam dengan grid UI). */
function rute_format_schedules_text(string $csv, int $maxVisible = 0): string
{
    $slots = array_values(array_filter(array_map('trim', explode(',', $csv)), static fn ($v) => $v !== ''));
    if (!$slots) {
        return '-';
    }
    if ($maxVisible > 0 && count($slots) > $maxVisible) {
        $extra = count($slots) - $maxVisible;
        return implode(', ', array_slice($slots, 0, $maxVisible)) . " (+{$extra})";
    }
    return implode(', ', $slots);
}

/** Ringkasan daftar tarif kategori kendaraan untuk ekspor Excel/PDF daftar rute. */
function rute_format_tariffs_summary(array $tariffs, string $delimiter = '; '): string
{
    if (!$tariffs) {
        return '-';
    }
    $parts = [];
    foreach ($tariffs as $t) {
        $cat = trim((string) ($t['category_name'] ?? ''));
        if ($cat === '') {
            $cat = 'Kategori #' . (int) ($t['category_vehicle_id'] ?? 0);
        }
        $seat = (int) (($t['seat_capacity'] ?? 0) > 0 ? $t['seat_capacity'] : ($t['end_capacity_seat'] ?? 0));
        $seatTag = $seat > 0 ? " ({$seat} seat)" : '';
        $parts[] = $cat . $seatTag . ': ' . format_nominal((float) ($t['price'] ?? 0));
    }
    return implode($delimiter, $parts);
}

/** Format daftar tarif dari hasil GROUP_CONCAT SQL (separator record ~;~ dan field ~|~) atau fallback kolom rutes. */
function rute_format_tariffs_export_cell(array $r, string $delimiter = '; '): string
{
    $raw = trim((string) ($r['tariffs_raw'] ?? ''));
    if ($raw !== '') {
        $items = [];
        foreach (explode('~;~', $raw) as $rec) {
            if ($rec === '') {
                continue;
            }
            $cols = explode('~|~', $rec);
            $items[] = [
                'category_name' => $cols[0] ?? '',
                'price'         => (float) ($cols[1] ?? 0),
                'seat_capacity' => (int) ($cols[2] ?? 0),
            ];
        }
        if ($items) {
            return rute_format_tariffs_summary($items, $delimiter);
        }
    }
    $sc    = (int) ($r['seat_capacity'] ?? 0);
    $price = (float) ($r['price'] ?? 0);
    if ($price <= 0 && empty($r['def_cat_name'])) {
        return '-';
    }
    $cat = trim((string) ($r['def_cat_name'] ?? ''));
    return ($cat !== '' ? $cat : 'Standar') . ($sc > 0 ? " ({$sc} seat)" : '') . ': ' . format_nominal($price);
}

/** Ringkasan singkat teks radius jemput/antar untuk tabel daftar dan export rute. */
function rute_format_radius_summary(float $freeKm, float $maxKm, float $extraPerKm, bool $includeFees = true): string
{
    if ($freeKm <= 0 && $maxKm <= 0 && (!$includeFees || $extraPerKm <= 0)) {
        return '-';
    }
    $fmtKm = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    $parts = [];
    if ($includeFees && $freeKm > 0) {
        $parts[] = 'Gratis ' . $fmtKm($freeKm) . ' km';
    }
    if ($maxKm > 0) {
        $parts[] = 'Maks ' . $fmtKm($maxKm) . ' km';
    } elseif (!$includeFees && $freeKm > 0) {
        $parts[] = 'Jangkauan ' . $fmtKm($freeKm) . ' km';
    }
    if ($includeFees && $extraPerKm > 0) {
        $parts[] = '+' . rupiah($extraPerKm) . '/km';
    }
    return $parts ? implode(' · ', $parts) : '-';
}

/** Pastikan tabel `rute_radius_districts` (cakupan kecamatan & biaya tambahan per rute) tersedia di DB. */
function rute_radius_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    db()->query(
        "CREATE TABLE IF NOT EXISTS `rute_radius_districts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `company_id` INT(11) NOT NULL,
            `rute_id` INT(11) NOT NULL,
            `radius_type` ENUM('pickup','delivery') NOT NULL,
            `district_id` INT(11) NOT NULL,
            `extra_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `note` VARCHAR(500) DEFAULT NULL,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_user` VARCHAR(255) DEFAULT NULL,
            `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `modified_user` VARCHAR(255) DEFAULT NULL,
            `modified_date` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rute_radius_area_dist` (`rute_id`, `radius_type`, `district_id`),
            KEY `ix_rute_radius_company` (`company_id`),
            KEY `ix_rute_radius_rute_type` (`rute_id`, `radius_type`),
            KEY `ix_rute_radius_district` (`district_id`),
            CONSTRAINT `fk_rute_radius_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_rute_radius_rute` FOREIGN KEY (`rute_id`) REFERENCES `rutes` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rute_radius_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Ambil peta [rute_id => ['pickup' => [...], 'delivery' => [...]]] dari `rute_radius_districts`. */
function rute_radius_by_rute_ids(array $rute_ids, int $company_id = 0): array
{
    rute_radius_ensure_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $rute_ids), static fn ($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $sql   = "SELECT rr.id, rr.rute_id, rr.radius_type, rr.district_id, rr.extra_price, rr.note, rr.sort_order,
                     d.name AS district_name, d.city_id, c.name AS city_name
                FROM `rute_radius_districts` rr
                JOIN `districts` d ON d.id = rr.district_id
                LEFT JOIN `cities` c ON c.id = d.city_id
               WHERE rr.rute_id IN ({$ph})";
    $types = str_repeat('i', count($ids));
    $args  = $ids;
    if ($company_id > 0) {
        $sql   .= ' AND rr.company_id = ?';
        $types .= 'i';
        $args[] = $company_id;
    }
    $sql .= ' ORDER BY rr.radius_type ASC, rr.sort_order ASC, d.name ASC, rr.id ASC';
    $st = db()->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $out = [];
    foreach ($ids as $rid) {
        $out[$rid] = ['pickup' => [], 'delivery' => []];
    }
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rid  = (int) $r['rute_id'];
        $type = ($r['radius_type'] === 'delivery') ? 'delivery' : 'pickup';
        $dist = (string) $r['district_name'];
        $city = (string) ($r['city_name'] ?? '');
        $out[$rid][$type][] = [
            'id'            => (int) $r['id'],
            'rute_id'       => $rid,
            'radius_type'   => $type,
            'district_id'   => (int) $r['district_id'],
            'district_name' => $dist,
            'city_id'       => (int) ($r['city_id'] ?? 0),
            'city_name'     => $city,
            'label'         => $city !== '' ? ($dist . ' — ' . $city) : $dist,
            'extra_price'   => (float) $r['extra_price'],
            'note'          => (string) ($r['note'] ?? ''),
            'sort_order'    => (int) $r['sort_order'],
        ];
    }
    $st->close();
    return $out;
}

/** Ambil daftar kecamatan radius penjemputan & pengantaran milik satu rute. */
function rute_radius_for_rute(int $rute_id, int $company_id = 0): array
{
    if ($rute_id <= 0) {
        return ['pickup' => [], 'delivery' => []];
    }
    $map = rute_radius_by_rute_ids([$rute_id], $company_id);
    return $map[$rute_id] ?? ['pickup' => [], 'delivery' => []];
}

/** Normalisasi & validasi baris kecamatan radius penjemputan atau pengantaran dari form rute. */
function rute_radius_normalize_and_validate(array $rows, string $radius_type): array
{
    $type = ($radius_type === 'delivery') ? 'delivery' : 'pickup';
    $areaLabel = ($type === 'delivery') ? 'pengantaran' : 'penjemputan';
    $clean = [];
    $seen  = [];
    $needCheckIds = [];
    $sort  = 1;

    foreach ($rows as $raw) {
        $distId = (int) ($raw['district_id'] ?? 0);
        $extra  = round(rute_parse_signed_amount($raw['extra_price'] ?? 0), 2);
        $note   = trim((string) ($raw['note'] ?? ''));

        if ($distId <= 0 && $extra == 0.0 && $note === '') {
            continue;
        }
        if ($distId <= 0) {
            throw new InvalidArgumentException("Setiap baris radius {$areaLabel} wajib memilih kecamatan.");
        }
        if (isset($seen[$distId])) {
            throw new InvalidArgumentException("Kecamatan pada daftar radius {$areaLabel} tidak boleh duplikat.");
        }
        if ($extra < 0) {
            throw new InvalidArgumentException("Biaya tambahan radius {$areaLabel} tidak boleh negatif.");
        }
        if (mb_strlen($note) > 500) {
            throw new InvalidArgumentException("Keterangan radius {$areaLabel} maksimal 500 karakter.");
        }
        $seen[$distId] = true;
        $needCheckIds[] = $distId;
        $clean[] = [
            'radius_type' => $type,
            'district_id' => $distId,
            'extra_price' => $extra,
            'note'        => $note,
            'sort_order'  => $sort++,
        ];
    }

    if ($needCheckIds) {
        $ph = implode(',', array_fill(0, count($needCheckIds), '?'));
        $st = db()->prepare("SELECT id FROM districts WHERE id IN ({$ph})");
        $st->bind_param(str_repeat('i', count($needCheckIds)), ...$needCheckIds);
        $st->execute();
        $validSet = [];
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $validSet[(int) $r['id']] = true;
        }
        $st->close();
        foreach ($needCheckIds as $did) {
            if (empty($validSet[$did])) {
                throw new InvalidArgumentException("Kecamatan #{$did} pada radius {$areaLabel} tidak ditemukan di master data.");
            }
        }
    }

    return $clean;
}

/** Sinkronkan daftar kecamatan radius penjemputan & pengantaran ke tabel `rute_radius_districts`. */
function rute_radius_sync(int $rute_id, int $company_id, array $pickupRows, array $deliveryRows, ?string $user = null): void
{
    if ($rute_id <= 0 || $company_id <= 0) {
        return;
    }
    rute_radius_ensure_schema();
    $db = db();

    $syncArea = static function (string $type, array $items) use ($db, $rute_id, $company_id, $user): void {
        $keepIds = array_values(array_map(static fn ($x) => (int) $x['district_id'], $items));
        if ($keepIds) {
            $ph  = implode(',', array_fill(0, count($keepIds), '?'));
            $stD = $db->prepare("DELETE FROM `rute_radius_districts` WHERE rute_id = ? AND radius_type = ? AND district_id NOT IN ({$ph})");
            $stD->bind_param('is' . str_repeat('i', count($keepIds)), $rute_id, $type, ...$keepIds);
            $stD->execute();
            $stD->close();
        } else {
            $stD = $db->prepare('DELETE FROM `rute_radius_districts` WHERE rute_id = ? AND radius_type = ?');
            $stD->bind_param('is', $rute_id, $type);
            $stD->execute();
            $stD->close();
            return;
        }

        $stUp = $db->prepare(
            "INSERT INTO `rute_radius_districts`
                (company_id, rute_id, radius_type, district_id, extra_price, note, sort_order, created_user, modified_user, modified_date)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                extra_price = VALUES(extra_price),
                note = VALUES(note),
                sort_order = VALUES(sort_order),
                modified_user = VALUES(modified_user),
                modified_date = NOW()"
        );
        foreach ($items as $it) {
            $did   = (int) $it['district_id'];
            $extra = (float) $it['extra_price'];
            $note  = $it['note'] !== '' ? (string) $it['note'] : null;
            $sort  = (int) $it['sort_order'];
            $stUp->bind_param('iisidsiss', $company_id, $rute_id, $type, $did, $extra, $note, $sort, $user, $user);
            $stUp->execute();
        }
        $stUp->close();
    };

    $syncArea('pickup', $pickupRows);
    $syncArea('delivery', $deliveryRows);
}

/** Ringkasan daftar kecamatan radius untuk tabel daftar rute maupun export Excel/PDF. */
function rute_format_district_radius_summary(
    array $districts,
    float $legacyFreeKm = 0,
    float $legacyMaxKm = 0,
    float $legacyExtraPerKm = 0,
    bool $detailed = false,
    bool $includeFees = true,
    bool $includeCount = true
): string {
    if (!$districts) {
        return rute_format_radius_summary($legacyFreeKm, $legacyMaxKm, $legacyExtraPerKm, $includeFees);
    }
    if ($detailed) {
        $lines = [];
        foreach ($districts as $d) {
            $name  = trim((string) ($d['district_name'] ?? ''));
            $label = $name !== '' ? $name : ('Kec #' . (int) ($d['district_id'] ?? 0));
            if ($includeFees) {
                $extra = (float) ($d['extra_price'] ?? 0);
                $tag   = $extra > 0 ? '+' . rupiah($extra) : 'Gratis';
                $label .= ' (' . $tag . ')';
            }
            $lines[] = $label;
        }
        return implode('; ', $lines);
    }
    $count     = count($districts);
    $freeCount = 0;
    $paidExtras = [];
    $names     = [];
    foreach ($districts as $d) {
        $extra = (float) ($d['extra_price'] ?? 0);
        if ($extra <= 0) {
            $freeCount++;
        } else {
            $paidExtras[] = $extra;
        }
        $n = trim((string) ($d['district_name'] ?? ''));
        if ($n !== '' && count($names) < 2) {
            $names[] = $n;
        }
    }
    $namesText = $names ? (implode(', ', $names) . ($count > 2 ? ', …' : '')) : '';
    if (!$includeFees) {
        if (!$includeCount) {
            return $namesText !== '' ? $namesText : '-';
        }
        return $namesText !== '' ? "{$count} kec. · {$namesText}" : "{$count} kec.";
    }
    $sample = $namesText !== '' ? " · {$namesText}" : '';
    $feeText = 'Gratis';
    if ($paidExtras) {
        $minE = min($paidExtras);
        $maxE = max($paidExtras);
        $range = ($minE == $maxE) ? ('+' . rupiah($minE)) : ('+' . rupiah($minE) . '–' . rupiah($maxE));
        $feeText = ($freeCount > 0) ? ("{$freeCount} gratis, {$range}") : $range;
    }
    return ($includeCount ? "{$count} kec. " : '') . "({$feeText}){$sample}";
}

/**
 * Format daftar kecamatan radius dari hasil GROUP_CONCAT SQL (separator ~;~) tanpa label biaya/jumlah kec.
 * $compact = true memotong maksimal 2 kecamatan + ', …' persis tampilan sel grid UI (untuk PDF).
 */
function rute_format_district_names_export(
    string $rawNames,
    float $legacyFreeKm = 0,
    float $legacyMaxKm = 0,
    float $legacyExtraPerKm = 0,
    bool $compact = true
): string {
    $names = array_values(array_filter(array_map('trim', explode('~;~', $rawNames)), static fn ($v) => $v !== ''));
    if (!$names) {
        return rute_format_radius_summary($legacyFreeKm, $legacyMaxKm, $legacyExtraPerKm, false);
    }
    if ($compact && count($names) > 2) {
        return implode(', ', array_slice($names, 0, 2)) . ', …';
    }
    return implode(', ', $names);
}

/** Cari kecocokan biaya tambahan kecamatan pada daftar radius rute. */
function rute_find_district_radius_extra(array $districts, int $district_id): array
{
    if ($district_id > 0) {
        foreach ($districts as $d) {
            if ((int) ($d['district_id'] ?? 0) === $district_id) {
                return [
                    'matched'       => true,
                    'extra_price'   => (float) ($d['extra_price'] ?? 0),
                    'note'          => (string) ($d['note'] ?? ''),
                    'district_name' => (string) ($d['district_name'] ?? ''),
                ];
            }
        }
    }
    return ['matched' => false, 'extra_price' => 0.0, 'note' => '', 'district_name' => ''];
}

/** Pastikan tabel terpisah `rute_schedules` (ENUM jam keberangkatan per rute) tersedia di DB. */
function rute_schedule_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $enumList = "'" . implode("','", RUTE_SCHEDULE_SLOTS) . "'";
    db()->query(
        "CREATE TABLE IF NOT EXISTS `rute_schedules` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `company_id` INT(11) NOT NULL,
            `rute_id` INT(11) NOT NULL,
            `departure_time` ENUM({$enumList}) NOT NULL,
            `created_user` VARCHAR(255) DEFAULT NULL,
            `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rute_schedules_slot` (`rute_id`, `departure_time`),
            KEY `ix_rute_schedules_company` (`company_id`),
            KEY `ix_rute_schedules_rute_time` (`rute_id`, `departure_time`),
            CONSTRAINT `fk_rute_schedules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_rute_schedules_rute` FOREIGN KEY (`rute_id`) REFERENCES `rutes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Pengelompokan periode jam keberangkatan ENUM rute untuk tampilan form. */
function rute_schedule_periods(): array
{
    return [
        ['label' => 'Pagi',      'slots' => ['05:00', '06:00', '07:00', '08:00', '09:00', '10:00', '11:00']],
        ['label' => 'Siang',     'slots' => ['12:00', '13:00', '14:00']],
        ['label' => 'Sore',      'slots' => ['15:00', '16:00', '17:00']],
        ['label' => 'Malam',     'slots' => ['18:00', '19:00', '20:00', '21:00', '22:00', '23:00']],
        ['label' => 'Dini Hari', 'slots' => ['00:00', '01:00', '02:00', '03:00', '04:00']],
    ];
}

/** Validasi & urutkan daftar jam keberangkatan terhadap whitelist ENUM `RUTE_SCHEDULE_SLOTS`. */
function rute_schedule_normalize_slots(array $rawSlots): array
{
    $seen = [];
    foreach ($rawSlots as $raw) {
        $val = trim((string) $raw);
        if ($val === '') {
            continue;
        }
        if (!in_array($val, RUTE_SCHEDULE_SLOTS, true)) {
            throw new InvalidArgumentException("Jam keberangkatan '{$val}' tidak valid. Pilih slot ENUM per jam (00:00 - 23:00).");
        }
        $seen[$val] = true;
    }
    $slots = array_keys($seen);
    sort($slots, SORT_STRING);
    return $slots;
}

/** Ambil peta [rute_id => ['07:00', '08:00', ...]] dari tabel `rute_schedules`. */
function rute_schedules_by_rute_ids(array $rute_ids, int $company_id = 0): array
{
    rute_schedule_ensure_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $rute_ids), static fn ($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $sql   = "SELECT rute_id, departure_time FROM rute_schedules WHERE rute_id IN ({$ph})";
    $types = str_repeat('i', count($ids));
    $args  = $ids;
    if ($company_id > 0) {
        $sql   .= ' AND company_id = ?';
        $types .= 'i';
        $args[] = $company_id;
    }
    $sql .= ' ORDER BY departure_time ASC';
    $st = db()->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[(int) $row['rute_id']][] = (string) $row['departure_time'];
    }
    $st->close();
    return $out;
}

/** Ambil daftar jam keberangkatan ENUM milik satu rute. */
function rute_schedule_times(int $rute_id, int $company_id = 0): array
{
    if ($rute_id <= 0) {
        return [];
    }
    $map = rute_schedules_by_rute_ids([$rute_id], $company_id);
    return $map[$rute_id] ?? [];
}

/** Sinkronkan daftar slot jam keberangkatan pada tabel `rute_schedules` untuk rute terkait. */
function rute_schedule_sync(int $rute_id, int $company_id, array $slots, ?string $user = null): void
{
    if ($rute_id <= 0 || $company_id <= 0) {
        return;
    }
    rute_schedule_ensure_schema();
    $slots    = rute_schedule_normalize_slots($slots);
    $existing = rute_schedule_times($rute_id, $company_id);
    $db       = db();

    $toDelete = array_values(array_diff($existing, $slots));
    if ($toDelete) {
        $ph = implode(',', array_fill(0, count($toDelete), '?'));
        $stDel = $db->prepare("DELETE FROM rute_schedules WHERE rute_id = ? AND company_id = ? AND departure_time IN ({$ph})");
        $stDel->bind_param('ii' . str_repeat('s', count($toDelete)), $rute_id, $company_id, ...$toDelete);
        $stDel->execute();
        $stDel->close();
    }

    $toInsert = array_values(array_diff($slots, $existing));
    if ($toInsert) {
        $stIns = $db->prepare('INSERT INTO rute_schedules (company_id, rute_id, departure_time, created_user) VALUES (?,?,?,?)');
        foreach ($toInsert as $slot) {
            $stIns->bind_param('iiss', $company_id, $rute_id, $slot, $user);
            $stIns->execute();
        }
        $stIns->close();
    }
}

/** Ambil saran kapasitas kursi terbanyak/tertinggi dari master armada (`vehicles.seat`) per kategori kendaraan. */
function rute_category_default_seats(int $company_id): array
{
    if ($company_id <= 0) {
        return [];
    }
    $st = db()->prepare(
        'SELECT category_vehicle_id, MAX(seat) AS max_seat
           FROM vehicles
          WHERE company_id = ? AND is_active = 1 AND seat > 0
          GROUP BY category_vehicle_id'
    );
    $st->bind_param('i', $company_id);
    $st->execute();
    $out = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int) $r['category_vehicle_id']] = (int) $r['max_seat'];
    }
    $st->close();
    return $out;
}

/** Cari rute aktif & aturan tarif kategori berdasarkan kota asal, kota tujuan, dan kategori kendaraan. */
function travel_lookup_route_tariff(
    int $company_id,
    int $pickup_city_id,
    int $delivery_city_id,
    int $category_vehicle_id = 0,
    int $pickup_district_id = 0,
    int $delivery_district_id = 0
): ?array {
    if ($company_id <= 0 || $pickup_city_id <= 0 || $delivery_city_id <= 0) {
        return null;
    }
    rute_ensure_schema();
    $db    = db();
    $route = null;
    $categoryMatched = ($category_vehicle_id <= 0);

    $cols = 'r.id, r.name, r.pickup_point_city_id, r.delivery_point_city_id, r.category_vehicle_id, r.price,
             r.min_price, r.max_price, r.start_capacity_seat, r.end_capacity_seat, r.seat_capacity,
             r.pickup_free_radius_km, r.pickup_max_radius_km, r.pickup_extra_price_per_km,
             r.delivery_free_radius_km, r.delivery_max_radius_km, r.delivery_extra_price_per_km,
             pp.name AS pickup_province_name, dp.name AS delivery_province_name';
    $geoJoins = 'LEFT JOIN cities pc ON pc.id = r.pickup_point_city_id
                 LEFT JOIN provinces pp ON pp.id = pc.province_id
                 LEFT JOIN cities dc ON dc.id = r.delivery_point_city_id
                 LEFT JOIN provinces dp ON dp.id = dc.province_id';

    if ($category_vehicle_id > 0) {
        $stTar = $db->prepare(
            "SELECT {$cols},
                    rt.category_vehicle_id AS t_category_id, rt.min_price AS t_min_price, rt.price AS t_price,
                    rt.max_price AS t_max_price, rt.start_capacity_seat AS t_start_seat,
                    rt.end_capacity_seat AS t_end_seat, rt.seat_capacity AS t_seat_cap
               FROM rutes r
               {$geoJoins}
               JOIN rute_tariffs rt ON rt.rute_id = r.id
              WHERE r.company_id = ? AND r.pickup_point_city_id = ? AND r.delivery_point_city_id = ? AND r.is_active = 1
                AND rt.category_vehicle_id = ?
              ORDER BY r.id ASC
              LIMIT 1"
        );
        $stTar->bind_param('iiii', $company_id, $pickup_city_id, $delivery_city_id, $category_vehicle_id);
        $stTar->execute();
        $route = $stTar->get_result()->fetch_assoc() ?: null;
        $stTar->close();

        if ($route) {
            $categoryMatched = true;
        } else {
            $stCat = $db->prepare(
                "SELECT {$cols}
                   FROM rutes r
                   {$geoJoins}
                  WHERE r.company_id = ? AND r.pickup_point_city_id = ? AND r.delivery_point_city_id = ? AND r.is_active = 1
                    AND r.category_vehicle_id = ?
                  ORDER BY r.id ASC
                  LIMIT 1"
            );
            $stCat->bind_param('iiii', $company_id, $pickup_city_id, $delivery_city_id, $category_vehicle_id);
            $stCat->execute();
            $route = $stCat->get_result()->fetch_assoc() ?: null;
            $stCat->close();
            if ($route) {
                $categoryMatched = true;
            }
        }
    }

    if (!$route) {
        $st = $db->prepare(
            "SELECT {$cols}
               FROM rutes r
               {$geoJoins}
              WHERE r.company_id = ? AND r.pickup_point_city_id = ? AND r.delivery_point_city_id = ? AND r.is_active = 1
              ORDER BY r.id ASC
              LIMIT 1"
        );
        $st->bind_param('iii', $company_id, $pickup_city_id, $delivery_city_id);
        $st->execute();
        $route = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }

    if (!$route) {
        return null;
    }

    $tariffs    = rute_tariffs_for_rute((int) $route['id'], $company_id);
    $firstTar   = $tariffs[0] ?? [];
    $effCat     = (int) ($route['t_category_id'] ?? ($firstTar['category_vehicle_id'] ?? $route['category_vehicle_id']));
    $effPrc     = isset($route['t_price']) ? (float) $route['t_price'] : ($firstTar ? (float) $firstTar['price'] : (float) $route['price']);
    $effMin     = isset($route['t_min_price']) ? (float) $route['t_min_price'] : ($firstTar ? (float) $firstTar['min_price'] : (float) $route['min_price']);
    $effMax     = isset($route['t_max_price']) ? (float) $route['t_max_price'] : ($firstTar ? (float) $firstTar['max_price'] : (float) $route['max_price']);
    $effSeatCap = 0;
    if (isset($route['t_seat_cap']) && (int) $route['t_seat_cap'] > 0) {
        $effSeatCap = (int) $route['t_seat_cap'];
    } elseif (isset($route['t_end_seat']) && (int) $route['t_end_seat'] > 0) {
        $effSeatCap = (int) $route['t_end_seat'];
    } elseif (!empty($firstTar)) {
        $effSeatCap = (int) ($firstTar['seat_capacity'] ?? $firstTar['end_capacity_seat'] ?? 0);
    } else {
        $effSeatCap = (int) ($route['seat_capacity'] ?? 0) > 0
            ? (int) $route['seat_capacity']
            : (int) ($route['end_capacity_seat'] ?? 0);
    }
    $effSeatS = $effSeatCap > 0 ? 1 : 0;
    $radiusMap      = rute_radius_for_rute((int) $route['id'], $company_id);
    $pickupDistRes  = rute_find_district_radius_extra($radiusMap['pickup'], $pickup_district_id);
    $deliveryDistRes = rute_find_district_radius_extra($radiusMap['delivery'], $delivery_district_id);

    return [
        'id'                        => (int) $route['id'],
        'name'                      => (string) $route['name'],
        'pickup_point_city_id'      => (int) $route['pickup_point_city_id'],
        'pickup_province_name'      => (string) ($route['pickup_province_name'] ?? ''),
        'delivery_point_city_id'    => (int) $route['delivery_point_city_id'],
        'delivery_province_name'    => (string) ($route['delivery_province_name'] ?? ''),
        'category_vehicle_id'       => $effCat,
        'category_matched'          => $categoryMatched,
        'min_price'                 => $effMin,
        'price'                     => $effPrc,
        'max_price'                 => $effMax,
        'seat_capacity'             => $effSeatCap,
        'start_capacity_seat'       => $effSeatS,
        'end_capacity_seat'         => $effSeatCap,
        'tariffs'                   => $tariffs,
        'schedules'                 => rute_schedule_times((int) $route['id'], $company_id),
        'pickup_district_extra'     => (float) $pickupDistRes['extra_price'],
        'pickup_district_in_radius' => ($pickup_district_id <= 0 || empty($radiusMap['pickup']) || $pickupDistRes['matched']),
        'delivery_district_extra'   => (float) $deliveryDistRes['extra_price'],
        'delivery_district_in_radius' => ($delivery_district_id <= 0 || empty($radiusMap['delivery']) || $deliveryDistRes['matched']),
        'pickup_radius'             => [
            'free_km'            => (float) $route['pickup_free_radius_km'],
            'max_km'             => (float) $route['pickup_max_radius_km'],
            'extra_price_per_km' => (float) $route['pickup_extra_price_per_km'],
            'districts'          => $radiusMap['pickup'],
        ],
        'delivery_radius'           => [
            'free_km'            => (float) $route['delivery_free_radius_km'],
            'max_km'             => (float) $route['delivery_max_radius_km'],
            'extra_price_per_km' => (float) $route['delivery_extra_price_per_km'],
            'districts'          => $radiusMap['delivery'],
        ],
    ];
}

/** Validasi batas Harga Min dan Harga Maks rute (plus biaya tambahan kecamatan) untuk pemesanan tiket perjalanan. */
function travel_validate_route_price_bounds(
    int $company_id,
    int $pickup_city_id,
    int $delivery_city_id,
    int $category_vehicle_id,
    int $passenger_count,
    float $total_price,
    int $pickup_district_id = 0,
    int $delivery_district_id = 0
): void {
    $matched = travel_lookup_route_tariff(
        $company_id,
        $pickup_city_id,
        $delivery_city_id,
        $category_vehicle_id,
        $pickup_district_id,
        $delivery_district_id
    );
    if (!$matched || ($category_vehicle_id > 0 && empty($matched['category_matched']))) {
        return;
    }
    $paxCnt      = max(1, $passenger_count);
    $minUnit     = (float) ($matched['min_price'] ?? 0);
    $maxUnit     = (float) ($matched['max_price'] ?? 0);
    $radiusExtra = round((float) ($matched['pickup_district_extra'] ?? 0) + (float) ($matched['delivery_district_extra'] ?? 0), 2);
    $extraText   = $radiusExtra > 0 ? (' + radius ' . rupiah($radiusExtra)) : '';

    if ($minUnit > 0) {
        $minTotal = round($minUnit * $paxCnt + $radiusExtra, 2);
        if ($total_price + 0.001 < $minTotal) {
            throw new InvalidArgumentException(
                'Total harga (' . rupiah($total_price) . ') di bawah Harga Min rute "' . $matched['name'] . '" untuk '
                . $paxCnt . ' penumpang (' . rupiah($minTotal) . ' = ' . $paxCnt . ' x ' . rupiah($minUnit) . $extraText . ').'
            );
        }
    }
    if ($maxUnit > 0) {
        $maxTotal = round($maxUnit * $paxCnt + $radiusExtra, 2);
        if ($total_price - 0.001 > $maxTotal) {
            throw new InvalidArgumentException(
                'Total harga (' . rupiah($total_price) . ') melebihi Harga Maks rute "' . $matched['name'] . '" untuk '
                . $paxCnt . ' penumpang (' . rupiah($maxTotal) . ' = ' . $paxCnt . ' x ' . rupiah($maxUnit) . $extraText . ').'
            );
        }
    }
}
