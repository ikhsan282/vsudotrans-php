<?php
/**
 * Engine CRUD generik — Referensi Umum (pages/masters/*) & Master HR (pages/hr/*).
 * VSudoTrans. PHP native + MySQLi, mengikuti pola modul branches/employees.
 *
 * Kontrak keamanan:
 *  - tiap halaman memanggil require_permission() sesuai spec;
 *  - tabel ber-company_id memakai company_scope() untuk list dan
 *    assert_company_access() untuk baris yang diambil dari URL;
 *  - company_id saat create selalu dari active_company_id(), tidak pernah POST;
 *  - seluruh query memakai prepared statement (tanpa interpolasi input);
 *  - hapus selalu POST + verify_csrf() + cek relasi FK RESTRICT lebih dahulu.
 *
 * Halaman tipis di pages/<modul>/<tabel>/{index,form,delete}.php hanya
 * memanggil master_list() / master_form() / master_delete() dengan key tabel.
 */

// ---------------------------------------------------------------------
// Validator murni — diuji tests/masters_engine_check.php (tanpa MySQL)
// ---------------------------------------------------------------------

/** Kode: 1–15 karakter huruf/angka/strip (huruf besar). */
function master_code_ok(string $code): bool
{
    return (bool) preg_match('/^[A-Z0-9-]{1,15}$/', $code);
}

/** Kolom numeric wajib berisi angka. */
function master_num_ok(string $v): bool
{
    return $v !== '' && is_numeric($v);
}

/**
 * Deteksi siklus relasi parent. $parents = map [id => parent_id|null].
 * true bila menetapkan $new_parent pada node $node_id membentuk siklus
 * (termasuk parent = diri sendiri).
 */
function master_cycle_detected(array $parents, int $node_id, ?int $new_parent): bool
{
    if ($new_parent === null || $new_parent === 0) {
        return false;
    }
    $seen = [];
    $cur = $new_parent;
    while ($cur !== null && $cur !== 0) {
        if ($cur === $node_id) {
            return true;
        }
        if (isset($seen[$cur])) {
            return false; // data lama sudah siklus — jangan mengulang tanpa akhir
        }
        $seen[$cur] = true;
        $cur = $parents[$cur] ?? null;
    }
    return false;
}

// ---------------------------------------------------------------------
// Registry tabel
// ---------------------------------------------------------------------

function mf_code(): array
{
    return ['col' => 'code', 'label' => 'Kode', 'type' => 'text', 'max' => 15, 'required' => true, 'code' => true, 'upper' => true];
}
function mf_name(): array
{
    return ['col' => 'name', 'label' => 'Nama', 'type' => 'text', 'max' => 255, 'required' => true];
}
function mf_note(): array
{
    return ['col' => 'note', 'label' => 'Catatan', 'type' => 'textarea', 'max' => 2000, 'required' => false, 'nullable' => true];
}
function mf_active(): array
{
    return ['col' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'];
}
function mf_level(): array
{
    return ['col' => 'level', 'label' => 'Level', 'type' => 'number', 'int' => true, 'required' => false];
}
/** Select relasi. $parent = select induk (untuk dropdown bertingkat). */
function mf_select(string $col, string $label, string $source, array $extra = []): array
{
    return ['col' => $col, 'label' => $label, 'type' => 'select', 'source' => $source, 'required' => true] + $extra;
}
/** Upload gambar ke folder $dir; tersimpan sebagai path relatif di kolom. */
function mf_file(string $col, string $label, string $dir, array $extra = []): array
{
    return ['col' => $col, 'label' => $label, 'type' => 'file', 'dir' => $dir] + $extra;
}
/** Upload gambar ke Azure Blob Storage; kolom menyimpan path blob
 *  ({company_code}/{module}/{sub}/...). Extra: module, sub, max_mb. */
function mf_blob(string $col, string $label, array $extra = []): array
{
    return ['col' => $col, 'label' => $label, 'type' => 'blob'] + $extra;
}

function master_tables(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }

    $ref = static fn (array $spec): array => $spec + [
        'module' => 'masters', 'perm_view' => 'masters.view', 'perm_manage' => 'masters.manage',
        'company' => false, 'unique' => 'global', 'order' => 'name',
    ];
    $hr = static fn (array $spec): array => $spec + [
        'module' => 'hr', 'perm_view' => 'hr.view', 'perm_manage' => 'hr.manage',
        'company' => true, 'unique' => 'company', 'order' => 'name',
    ];

    $defs = [
        // ---------------- REFERENSI UMUM (tanpa company_id) ----------------
        'banks' => $ref([
            'table' => 'banks', 'title' => 'Bank',
            'fields' => [mf_code(), mf_name(), mf_note()],
        ]),
        'countries' => $ref([
            'table' => 'countries', 'title' => 'Negara',
            'fields' => [mf_code(), mf_name(), mf_note()],
            'blockers' => [
                ['table' => 'provinces', 'col' => 'country_id', 'label' => 'provinsi'],
                ['table' => 'cities', 'col' => 'country_id', 'label' => 'kota'],
                ['table' => 'districts', 'col' => 'country_id', 'label' => 'kecamatan'],
                ['table' => 'villages', 'col' => 'country_id', 'label' => 'desa'],
            ],
        ]),
        'provinces' => $ref([
            'table' => 'provinces', 'title' => 'Provinsi',
            'fields' => [mf_select('country_id', 'Negara', 'countries'), mf_code(), mf_name(), mf_note()],
            'blockers' => [
                ['table' => 'cities', 'col' => 'province_id', 'label' => 'kota'],
                ['table' => 'districts', 'col' => 'province_id', 'label' => 'kecamatan'],
                ['table' => 'villages', 'col' => 'province_id', 'label' => 'desa'],
            ],
        ]),
        'cities' => $ref([
            'table' => 'cities', 'title' => 'Kota / Kabupaten',
            'fields' => [
                mf_select('country_id', 'Negara', 'countries', ['child' => 'province_id']),
                mf_select('province_id', 'Provinsi', 'provinces', ['endpoint' => 'pages/masters/provinces/options.php', 'param' => 'country_id']),
                mf_code(), mf_name(), mf_note(),
            ],
            'blockers' => [
                ['table' => 'districts', 'col' => 'city_id', 'label' => 'kecamatan'],
                ['table' => 'villages', 'col' => 'city_id', 'label' => 'desa'],
                ['table' => 'rutes', 'col' => 'pickup_point_city_id', 'label' => 'rute (titik jemput)'],
                ['table' => 'rutes', 'col' => 'delivery_point_city_id', 'label' => 'rute (titik antar)'],
            ],
        ]),
        'districts' => $ref([
            'table' => 'districts', 'title' => 'Kecamatan',
            'fields' => [
                mf_select('country_id', 'Negara', 'countries', ['child' => 'province_id']),
                mf_select('province_id', 'Provinsi', 'provinces', ['child' => 'city_id', 'endpoint' => 'pages/masters/provinces/options.php', 'param' => 'country_id']),
                mf_select('city_id', 'Kota / Kabupaten', 'cities', ['endpoint' => 'pages/masters/cities/options.php', 'param' => 'province_id']),
                mf_code(), mf_name(), mf_note(),
            ],
            'blockers' => [
                ['table' => 'villages', 'col' => 'district_id', 'label' => 'desa'],
                ['table' => 'rute_radius_districts', 'col' => 'district_id', 'label' => 'jangkauan radius rute'],
            ],
        ]),
        'villages' => $ref([
            'table' => 'villages', 'title' => 'Desa / Kelurahan',
            'fields' => [
                mf_select('country_id', 'Negara', 'countries', ['child' => 'province_id']),
                mf_select('province_id', 'Provinsi', 'provinces', ['child' => 'city_id', 'endpoint' => 'pages/masters/provinces/options.php', 'param' => 'country_id']),
                mf_select('city_id', 'Kota / Kabupaten', 'cities', ['child' => 'district_id', 'endpoint' => 'pages/masters/cities/options.php', 'param' => 'province_id']),
                mf_select('district_id', 'Kecamatan', 'districts', ['endpoint' => 'pages/masters/districts/options.php', 'param' => 'city_id']),
                mf_code(), mf_name(),
                ['col' => 'postal_code', 'label' => 'Kode Pos', 'type' => 'text', 'max' => 10, 'required' => false, 'nullable' => true],
                mf_note(),
            ],
        ]),
        'unit_measures' => $ref([
            'table' => 'unit_measures', 'title' => 'Satuan',
            'fields' => [
                mf_code(), mf_name(),
                ['col' => 'format', 'label' => 'Format', 'type' => 'text', 'max' => 100, 'required' => false, 'nullable' => true],
                mf_note(),
            ],
        ]),

        // ---------------- MASTER HR (dengan company_id) ----------------
        'job_grades' => $hr([
            'table' => 'job_grades', 'title' => 'Golongan Jabatan',
            'fields' => [
                mf_code(), mf_name(),
                ['col' => 'basic_salary', 'label' => 'Gaji Pokok', 'type' => 'money', 'required' => false],
                ['col' => 'daily_allowance', 'label' => 'Uang Harian', 'type' => 'money', 'required' => false],
                mf_note(), mf_active(),
            ],
            'blockers' => [
                ['table' => 'employees', 'col' => 'job_grade', 'match' => 'name', 'label' => 'karyawan'],
            ],
        ]),
        'job_titles' => $hr([
            'table' => 'job_titles', 'title' => 'Jabatan',
            'unique' => 'company_or_null', // company_id NULL = berlaku semua perusahaan
            'fields' => [mf_code(), mf_name(), mf_level(), mf_note(), mf_active()],
            'blockers' => [
                ['table' => 'job_positions', 'col' => 'job_title_id', 'label' => 'posisi'],
            ],
        ]),
        'job_positions' => $hr([
            'table' => 'job_positions', 'title' => 'Posisi',
            'fields' => [
                mf_select('job_title_id', 'Jabatan', 'job_titles'),
                mf_select('parent_id', 'Posisi Induk', 'job_positions', ['required' => false, 'nullable' => true]),
                mf_code(), mf_name(), mf_level(), mf_note(), mf_active(),
            ],
        ]),
        'organization_structures' => $hr([
            'table' => 'organization_structures', 'title' => 'Struktur Organisasi',
            'fields' => [
                mf_select('parent_id', 'Induk', 'organization_structures', ['required' => false, 'nullable' => true]),
                mf_code(), mf_name(), mf_level(), mf_note(), mf_active(),
            ],
        ]),
        'employee_recognitions' => $hr([
            'table' => 'employee_recognitions', 'title' => 'Penghargaan Karyawan',
            'order' => 'index_no',
            'fields' => [
                mf_select('employee_id', 'Karyawan', 'employees'),
                ['col' => 'type', 'label' => 'Jenis', 'type' => 'text', 'max' => 30, 'required' => true],
                ['col' => 'index_no', 'label' => 'No. Urut', 'type' => 'number', 'int' => true, 'required' => false],
                ['col' => 'template', 'label' => 'Template', 'type' => 'text', 'max' => 255, 'required' => false, 'nullable' => true],
                ['col' => 'size', 'label' => 'Ukuran', 'type' => 'number', 'int' => true, 'required' => false, 'nullable' => true],
                mf_note(),
            ],
        ]),

        // ---------------- METODE PEMBAYARAN (company_id, NULL = semua perusahaan) ----------------
        'payment_methods' => [
            'table' => 'payment_methods', 'title' => 'Metode Pembayaran', 'module' => 'finance',
            'perm_view' => 'finance.view', 'perm_manage' => 'finance.manage',
            'company' => true, 'unique' => 'company_or_null', 'order' => 'sort_order', 'reorder' => true,
            'fields' => [
                mf_code(), mf_name(),
                ['col' => 'account_name', 'label' => 'Nama Pemilik Rekening', 'type' => 'text', 'max' => 255, 'required' => false, 'nullable' => true],
                ['col' => 'account_number', 'label' => 'Nomor Rekening / Tujuan', 'type' => 'text', 'max' => 50, 'required' => false, 'nullable' => true],
                mf_blob('qr_blob_path', 'Gambar QR (opsional)', ['module' => 'finance', 'sub' => 'qris', 'max_mb' => 2]),
                ['col' => 'instructions', 'label' => 'Instruksi Pembayaran', 'type' => 'textarea', 'max' => 2000, 'required' => false, 'nullable' => true],
                ['col' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'int' => true, 'required' => false],
                mf_active(),
                mf_note(),
            ],
            'blockers' => [
                ['table' => 'invoices', 'col' => 'payment_method_id', 'label' => 'invoice'],
            ],
        ],
    ];
    return $defs;
}

function master_spec(string $key): array
{
    $defs = master_tables();
    if (!isset($defs[$key])) {
        http_response_code(404);
        exit('Tabel referensi tidak dikenal.');
    }
    return $defs[$key];
}

/** Daftar tabel pada modul yang sama (untuk tab navigasi + sidebar aktif). */
function master_siblings(string $key): array
{
    $out = [];
    foreach (master_tables() as $k => $s) {
        if ($s['module'] === master_spec($key)['module'] && can($s['perm_view'])) {
            $out[$k] = $s['title'];
        }
    }
    return $out;
}

function master_base_url(array $spec): string
{
    return APP_URL . '/pages/' . $spec['module'] . '/' . $spec['table'];
}

// ---------------------------------------------------------------------
// Scoping tenant
// ---------------------------------------------------------------------

/** Klausa WHERE list untuk tabel ini. Mengembalikan [sql, types, params]. */
function master_scope(array $spec): array
{
    if (empty($spec['company'])) {
        return ['1=1', '', []];
    }
    if (is_super_admin()) {
        return ['1=1', '', []];
    }
    $cid = active_company_id();
    if ($cid === null) {
        return ['1=0', '', []];
    }
    if (($spec['unique'] ?? '') === 'company_or_null') {
        return ['(company_id = ? OR company_id IS NULL)', 'i', [$cid]];
    }
    return ['company_id = ?', 'i', [$cid]];
}

/**
 * Ambil satu baris dari URL/POST id. Tabel ber-company_id: 403 bila bukan
 * milik perusahaan aktif (assert_company_access).
 */
function master_find(array $spec, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM `' . $spec['table'] . '` WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    if (!empty($spec['company'])) {
        assert_company_access($row['company_id'] !== null ? (int) $row['company_id'] : null);
    }
    return $row;
}

// ---------------------------------------------------------------------
// Lookup relasi (dropdown & nama kolom di list)
// ---------------------------------------------------------------------

function master_lookup(string $source, ?int $cid = null, int $limit = 0): array
{
    $sql = '';
    $types = '';
    $params = [];
    switch ($source) {
        case 'countries':
        case 'provinces':
        case 'cities':
        case 'districts':
            $sql = "SELECT id, name FROM `{$source}` ORDER BY name";
            break;
        case 'job_titles':
            $sql = 'SELECT id, name FROM job_titles';
            if ($cid !== null) {
                $sql .= ' WHERE company_id = ? OR company_id IS NULL';
                $types = 'i';
                $params = [(int) $cid];
            }
            $sql .= ' ORDER BY name';
            break;
        case 'job_positions':
        case 'organization_structures':
            $sql = "SELECT id, name FROM `{$source}`";
            if ($cid !== null) {
                $sql .= ' WHERE company_id = ?';
                $types = 'i';
                $params = [(int) $cid];
            }
            $sql .= ' ORDER BY name';
            break;
        case 'employees':
            $sql = 'SELECT id, CONCAT(code, \' - \', name) AS name FROM employees';
            if ($cid !== null) {
                $sql .= ' WHERE company_id = ?';
                $types = 'i';
                $params = [(int) $cid];
            }
            $sql .= ' ORDER BY name';
            break;
        default:
            return [];
    }
    if ($limit > 0) {
        $sql .= ' LIMIT ?';
        $types .= 'i';
        $params[] = $limit;
    }
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r['name'];
    }
    return $map;
}

/** Query SELECT standar (beserta parent geo metadata) untuk dropdown wilayah. */
function master_geo_select_query(string $source, bool $with_code = false, bool $with_province = false, bool $with_city = false): string
{
    if ($source === 'provinces') {
        $name_expr = $with_code ? "CONCAT(t.code, ' - ', t.name) AS name" : 't.name';
        return "SELECT t.id, {$name_expr}, t.code, t.country_id, co.name AS country_name
                  FROM provinces t
                  LEFT JOIN countries co ON co.id = t.country_id";
    }
    if ($source === 'cities') {
        $city_name_expr = $with_province
            ? "CONCAT(t.name, IF(p.name IS NOT NULL AND p.name <> '', CONCAT(' (', p.name, ')'), '')) AS name"
            : 't.name';
        return "SELECT t.id, {$city_name_expr}, t.province_id,
                       COALESCE(t.country_id, p.country_id) AS country_id,
                       p.name AS province_name, co.name AS country_name
                  FROM cities t
                  LEFT JOIN provinces p ON p.id = t.province_id
                  LEFT JOIN countries co ON co.id = COALESCE(t.country_id, p.country_id)";
    }
    if ($source === 'districts') {
        $dist_name_expr = $with_city
            ? "CONCAT(t.name, IF(c.name IS NOT NULL AND c.name <> '', CONCAT(' — ', c.name), '')) AS name"
            : 't.name';
        return "SELECT t.id, {$dist_name_expr}, t.city_id,
                       COALESCE(t.province_id, c.province_id) AS province_id,
                       COALESCE(t.country_id, c.country_id, p.country_id) AS country_id,
                       c.name AS city_name, p.name AS province_name, co.name AS country_name
                  FROM districts t
                  LEFT JOIN cities c ON c.id = t.city_id
                  LEFT JOIN provinces p ON p.id = COALESCE(t.province_id, c.province_id)
                  LEFT JOIN countries co ON co.id = COALESCE(t.country_id, c.country_id, p.country_id)";
    }
    return "SELECT t.id, t.name FROM `{$source}` t";
}

/** Ekstrak metadata parent geografis dari baris hasil query. */
function master_geo_row_meta(array $r): array
{
    $meta = [];
    foreach (['country_id', 'province_id', 'city_id'] as $fk) {
        if (!empty($r[$fk])) {
            $meta[$fk] = (int) $r[$fk];
        }
    }
    foreach (['country_name', 'province_name', 'city_name'] as $lk) {
        if (isset($r[$lk]) && $r[$lk] !== null && $r[$lk] !== '') {
            $meta[$lk] = (string) $r[$lk];
        }
    }
    return $meta;
}

/** Opsi select untuk master_form(): dibatasi & mengikuti parent agar aman untuk jutaan baris. */
function master_form_select_options(array $f, array $row, string $table, int $id, ?int $cid, ?array &$meta_map = null): array
{
    $source = (string) ($f['source'] ?? '');
    $col = (string) ($f['col'] ?? '');
    $selected_id = (int) ($row[$col] ?? 0);
    $map = [];
    $meta = [];

    $allowed_geo = ['provinces', 'cities', 'districts'];
    $allowed_params = ['country_id', 'province_id', 'city_id'];
    if (
        isset($f['endpoint'], $f['param'])
        && in_array($source, $allowed_geo, true)
        && in_array($f['param'], $allowed_params, true)
    ) {
        $parent_col = (string) $f['param'];
        $parent_id = (int) ($row[$parent_col] ?? 0);
        $base_sql = master_geo_select_query($source, false);
        if ($parent_id > 0) {
            $st = db()->prepare("{$base_sql} WHERE t.`{$parent_col}` = ? ORDER BY t.name ASC LIMIT 200");
            $st->bind_param('i', $parent_id);
        } else {
            $lim = 50;
            $st = db()->prepare("{$base_sql} ORDER BY t.name ASC LIMIT ?");
            $st->bind_param('i', $lim);
        }
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $rid = (int) $r['id'];
            $map[$rid] = $r['name'];
            $rm = master_geo_row_meta($r);
            if ($rm) {
                $meta[$rid] = $rm;
            }
        }
        $st->close();
    } else {
        $map = master_lookup($source, $cid, $source === 'employees' ? 50 : 200);
    }

    if ($selected_id > 0 && !isset($map[$selected_id])) {
        if (in_array($source, $allowed_geo, true)) {
            $base_sql = master_geo_select_query($source, false);
            $st = db()->prepare("{$base_sql} WHERE t.id = ? LIMIT 1");
            $st->bind_param('i', $selected_id);
            $st->execute();
            $one = $st->get_result()->fetch_assoc();
            $st->close();
            if ($one) {
                $map[(int) $one['id']] = $one['name'];
                $rm = master_geo_row_meta($one);
                if ($rm) {
                    $meta[(int) $one['id']] = $rm;
                }
            }
        } elseif (in_array($source, ['countries', 'job_titles', 'job_positions', 'organization_structures', 'employees'], true)) {
            $name_expr = $source === 'employees' ? "CONCAT(code, ' - ', name) AS name" : 'name';
            $st = db()->prepare("SELECT id, {$name_expr} FROM `{$source}` WHERE id = ? LIMIT 1");
            $st->bind_param('i', $selected_id);
            $st->execute();
            $one = $st->get_result()->fetch_assoc();
            $st->close();
            if ($one) {
                $map[(int) $one['id']] = $one['name'];
            }
        }
    }

    if ($source === $table && $id > 0) {
        unset($map[$id], $meta[$id]); // tidak boleh jadi induk diri sendiri
    }
    if (func_num_args() >= 6) {
        $meta_map = $meta;
    }
    return $map;
}

/** Label kolom induk: '' bila kosong, '#'.. bila relasi hilang (data lama). */
function master_label(array $map, $value): string
{
    if ($value === null || $value === '' || (int) $value === 0) {
        return '';
    }
    return $map[(int) $value] ?? ('#' . (int) $value);
}

/** Validasi nilai relasi select (milik perusahaan aktif bila ber-company_id). */
function master_relation_ok(string $source, int $id, ?int $cid): bool
{
    if ($id <= 0) {
        return false;
    }
    $sql = 'SELECT 1 FROM `' . $source . '` WHERE id = ?';
    $types = 'i';
    $params = [$id];
    // Sumber ber-scope company dibatasi perusahaan aktif jika konteks $cid ada.
    if ($source === 'job_titles') {
        if ($cid !== null) {
            $sql .= ' AND (company_id = ? OR company_id IS NULL)';
            $types = 'ii';
            $params[] = (int) $cid;
        }
    } elseif ($source === 'job_positions' || $source === 'employees') {
        if ($cid !== null) {
            $sql .= ' AND company_id = ?';
            $types = 'ii';
            $params[] = (int) $cid;
        }
    }
    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------

function master_list(string $key): void
{
    $spec = master_spec($key);
    require_permission($spec['perm_view']);
    $cid = active_company_id();
    $can_manage = can($spec['perm_manage']);

    [$where, $types, $params] = master_scope($spec);
    $table = $spec['table'];
    $order = $spec['order'] === 'index_no' ? 'index_no, id' : '`' . $spec['order'] . '`';

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $q_like = '%' . $q . '%';
        if (column_exists($table, 'code') && column_exists($table, 'name')) {
            $where .= " AND (`code` LIKE ? OR `name` LIKE ?)";
            $types .= 'ss';
            $params[] = $q_like;
            $params[] = $q_like;
        } elseif (column_exists($table, 'name')) {
            $where .= " AND `name` LIKE ?";
            $types .= 's';
            $params[] = $q_like;
        } elseif (column_exists($table, 'code')) {
            $where .= " AND `code` LIKE ?";
            $types .= 's';
            $params[] = $q_like;
        }
    }

    $st = db()->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $total = (int) $st->get_result()->fetch_row()[0];
    $st->close();

    // -----------------------------------------------------------------
    // Export Grid (Excel .xlsx & PDF .pdf)
    // -----------------------------------------------------------------
    $export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
    if (in_array($export_format, ['xlsx', 'pdf'], true)) {
        $export_cols = array_values(array_filter($spec['fields'], fn ($f) => !in_array($f['type'] ?? '', ['blob', 'file', 'hidden'], true)));
        $export_headers = array_map(fn ($f) => $f['label'], $export_cols);

        $lookup_maps = [];
        foreach ($export_cols as $f) {
            if (($f['type'] ?? '') === 'select' && !isset($lookup_maps[$f['source']])) {
                $lookup_maps[$f['source']] = master_lookup($f['source'], $cid);
            }
        }

        $sql_export = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$order}";
        $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

        $row_stream = grid_query_stream(
            $sql_export,
            $types,
            $params,
            static function (array $r) use ($export_cols, $lookup_maps): array {
                $line = [];
                foreach ($export_cols as $f) {
                    $c = $f['col'];
                    $t = $f['type'] ?? '';
                    $val = $r[$c] ?? null;
                    if ($t === 'checkbox') {
                        $line[] = $val ? 'Aktif' : 'Nonaktif';
                    } elseif ($t === 'select') {
                        $line[] = master_label($lookup_maps[$f['source']] ?? [], $val);
                    } elseif ($t === 'money') {
                        $line[] = $val !== null ? (float) $val : 0;
                    } else {
                        $line[] = $val !== null ? (string) $val : '-';
                    }
                }
                return $line;
            },
            $max_export_rows
        );

        $sub = $q !== '' ? 'Pencarian: "' . $q . '"' : 'Semua Data';
        grid_export_send(
            'Master ' . $spec['title'],
            $export_headers,
            $row_stream,
            $export_format,
            'landscape',
            $sub,
            $total
        );
    }

    $p = paginate($total, (int) ($_GET['page'] ?? 1));
    $st = db()->prepare("SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?");
    $st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // Peta relasi untuk kolom select — hanya ambil ID yang tampil pada halaman aktif
    // agar hemat memori (O(1) RAM) dan tidak memuat ribuan baris parent tabel (mis. 7.285 kecamatan).
    $maps = [];
    foreach ($spec['fields'] as $f) {
        if (($f['type'] ?? '') === 'select' && !isset($maps[$f['source']])) {
            $col_name = $f['col'];
            $page_ids = [];
            foreach ($rows as $r) {
                if (!empty($r[$col_name])) {
                    $page_ids[(int) $r[$col_name]] = true;
                }
            }
            if ($page_ids) {
                $id_list = array_keys($page_ids);
                $in_clause = implode(',', array_fill(0, count($id_list), '?'));
                $st_map = db()->prepare("SELECT id, name FROM `{$f['source']}` WHERE id IN ({$in_clause})");
                $st_map->bind_param(str_repeat('i', count($id_list)), ...$id_list);
                $st_map->execute();
                $res_map = $st_map->get_result();
                $maps[$f['source']] = [];
                while ($mr = $res_map->fetch_assoc()) {
                    $maps[$f['source']][(int) $mr['id']] = $mr['name'];
                }
                $st_map->close();
            } else {
                $maps[$f['source']] = [];
            }
        }
    }

    $header_actions = ui_export_actions();
    if (can($spec['perm_manage'])) {
        $header_actions[] = ['label' => 'Import CSV', 'icon' => 'upload', 'href' => master_base_url($spec) . '/import.php', 'class' => 'btn btn-outline-primary btn-sm'];
        $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => master_base_url($spec) . '/form.php', 'class' => 'btn btn-primary btn-sm'];
    }

    $active = $spec['module'];
    $title  = $spec['title'];
    $list_cols = array_values(array_filter($spec['fields'], fn ($f) => !in_array($f['type'] ?? '', ['textarea', 'money', 'blob', 'file', 'hidden'], true)));
    $query_base = $q !== '' ? 'q=' . urlencode($q) : '';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
        <?= ui_page_header(
            $spec['title'],
            ['Dashboard' => 'pages/dashboard/index.php', ($spec['module'] === 'hr' ? 'Master HR' : 'Master Data') => null, $spec['title'] => null],
            [['text' => number_format($total, 0, ',', '.') . ' Data', 'class' => 'bg-light text-secondary border']],
            $header_actions
        ) ?>
        <?= master_tabs($key) ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-auto">
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control" placeholder="Cari kode atau nama..." value="<?= e($q) ?>" style="min-width: 220px;">
                            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Cari</button>
                            <?php if ($q !== ''): ?>
                                <a class="btn btn-outline-danger" href="<?= e(master_base_url($spec)) ?>/index.php" title="Reset pencarian"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($q !== ''): ?>
                        <div class="col-auto">
                            <span class="small text-muted">Menampilkan hasil untuk: <strong><?= e($q) ?></strong> (<?= $total ?> data)</span>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr>
                        <?php foreach ($list_cols as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?>
                        <th class="text-end">Aksi</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $is_global = !empty($spec['company']) && ($r['company_id'] === null);
                        $can_edit_this = $can_manage && (!$is_global || is_super_admin());
                    ?>
                        <tr>
                            <?php foreach ($list_cols as $idx_c => $f): $col = $f['col']; ?>
                                <td>
                                <?php if (($f['type'] ?? '') === 'checkbox'): ?>
                                    <span class="badge bg-<?= $r[$col] ? 'success' : 'secondary' ?>"><?= $r[$col] ? 'Aktif' : 'Nonaktif' ?></span>
                                <?php elseif (($f['type'] ?? '') === 'select'): ?>
                                    <?= e(master_label($maps[$f['source']], $r[$col])) ?>
                                <?php else: ?>
                                    <?= e((string) $r[$col]) ?>
                                <?php endif; ?>
                                <?php if ($idx_c === 0 && $is_global): ?>
                                    <span class="badge bg-light text-secondary border ms-1" title="Data bawaan sistem / berlaku semua perusahaan">Global</span>
                                <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-end text-nowrap">
                                <?php if ($can_edit_this && !empty($spec['reorder'])): ?>
                                    <?php foreach ([['up', '↑', 'Naikkan urutan'], ['down', '↓', 'Turunkan urutan']] as [$dir, $icon, $tip]): ?>
                                        <form method="post" action="<?= e(master_base_url($spec)) ?>/move.php" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="dir" value="<?= $dir ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"><?= $icon ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if ($can_edit_this): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(master_base_url($spec)) ?>/form.php?id=<?= (int) $r['id'] ?>" title="Ubah" aria-label="Ubah"><i class="bi bi-pencil-square"></i></a>
                                    <form method="post" action="<?= e(master_base_url($spec)) ?>/delete.php" class="d-inline" onsubmit="return confirm('Hapus data ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php elseif ($is_global): ?>
                                    <span class="badge bg-light text-muted border">Bawaan Sistem</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= count($list_cols) + 1 ?>" class="text-center text-muted py-4">Belum ada data<?= $q !== '' ? ' yang sesuai pencarian' : '' ?>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white"><?= pagination_html($p, $query_base) ?></div>
        </div>
    </main>
    <?php
    require __DIR__ . '/../../includes/footer.php';
}

/** Tab navigasi antar tabel pada modul yang sama. */
function master_tabs(string $current): string
{
    $html = '<ul class="nav nav-pills nav-sm mb-3">';
    foreach (master_siblings($current) as $k => $label) {
        $spec = master_spec($k);
        $cls = $k === $current ? ' active' : '';
        $html .= '<li class="nav-item"><a class="nav-link py-1 px-3' . $cls . '" href="' . e(master_base_url($spec) . '/index.php') . '">' . e($label) . '</a></li>';
    }
    return $html . '</ul>';
}

// ---------------------------------------------------------------------
// Form (create/update)
// ---------------------------------------------------------------------

function master_form(string $key): void
{
    $spec = master_spec($key);
    require_permission($spec['perm_manage']);
    $db = db();
    $cid = active_company_id();
    $table = $spec['table'];

    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id === 0 && !empty($spec['company']) && $cid === null) {
        flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
        redirect('pages/' . $spec['module'] . '/' . $table . '/index.php');
    }

    $row = master_empty_row($spec);
    if ($id > 0) {
        $found = master_find($spec, $id);
        if (!$found) {
            flash('error', $spec['title'] . ' tidak ditemukan.');
            redirect('pages/' . $spec['module'] . '/' . $table . '/index.php');
        }
        $row = $found;
        if (!empty($spec['company'])) {
            $cid = $found['company_id'] !== null ? (int) $found['company_id'] : $cid;
        }
    }

    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $in = master_map_input($spec, $_POST);
        $errors = master_validate($spec, $in, $id, $cid);
        $row = array_merge($row, $in);
        if (!$errors) {
            // Unggah gambar Azure Blob (field 'blob') setelah validasi lolos.
            $old_blobs = [];
            try {
                foreach ($spec['fields'] as $f) {
                    if (($f['type'] ?? '') !== 'blob') {
                        continue;
                    }
                    $new_path = master_upload_blob($f, $cid);
                    if ($new_path !== null) {
                        $old_blobs[] = (string) ($row[$f['col']] ?? '');
                        $in[$f['col']] = $new_path;
                    }
                }
            } catch (InvalidArgumentException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
        if (!$errors) {
            try {
                master_save($spec, $id, $in, $cid);
                foreach ($old_blobs as $old) {
                    if ($old !== '') {
                        azure_blob_delete($old); // bersihkan berkas lama yang diganti
                    }
                }
                flash('success', $spec['title'] . ' berhasil ' . ($id > 0 ? 'diperbarui' : 'ditambahkan') . '.');
                redirect('pages/' . $spec['module'] . '/' . $table . '/index.php');
            } catch (mysqli_sql_exception $ex) {
                if ((int) $ex->getCode() === 1062) {
                    $errors[] = 'Kode sudah dipakai pada ' . mb_strtolower($spec['title']) . ' lain' . (!empty($spec['company']) ? ' di perusahaan ini' : '') . '.';
                } else {
                    throw $ex;
                }
            }
        }
    }

    $active = $spec['module'];
    $title  = ($id > 0 ? 'Ubah ' : 'Tambah ') . $spec['title'];
    $options = [];
    $option_meta = [];
    foreach ($spec['fields'] as $f) {
        if (($f['type'] ?? '') === 'select') {
            $meta = [];
            $options[$f['col']] = master_form_select_options($f, $row, $table, $id, $cid, $meta);
            if ($meta) {
                $option_meta[$f['col']] = $meta;
            }
        }
    }
    require __DIR__ . '/../../includes/header.php';
    ?>
    <main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
        <?= ui_page_header(
            $title,
            ['Dashboard' => 'pages/dashboard/index.php', $spec['title'] => master_base_url($spec) . '/index.php', ($id > 0 ? 'Ubah' : 'Tambah Baru') => null],
            $id > 0 ? array_values(array_filter([
                !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
                ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
                isset($row['is_active']) ? ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'] : null,
            ])) : [],
            [
                ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => master_base_url($spec) . '/index.php', 'class' => 'btn btn-sm btn-outline-secondary'],
                ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formMasterEngine', 'class' => 'btn btn-sm btn-primary'],
            ]
        ) ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="card shadow-sm border-0" style="max-width: 720px;">
            <div class="card-body">
                <form method="post" action="<?= e(master_base_url($spec)) ?>/form.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="formMasterEngine" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <?php foreach ($spec['fields'] as $f): $col = $f['col']; $val = $row[$col] ?? null; ?>
                        <?php if (($f['type'] ?? '') === 'hidden'): ?>
                            <input type="hidden" name="<?= e($col) ?>" value="<?= e((string) $val) ?>">
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php if (($f['type'] ?? '') === 'checkbox'): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="<?= e($col) ?>" name="<?= e($col) ?>" <?= $val ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= e($col) ?>"><?= e($f['label']) ?></label>
                            </div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php if (($f['type'] ?? '') === 'blob'): ?>
                            <div class="mb-3">
                                <label class="form-label" for="<?= e($col) ?>"><?= e($f['label']) ?></label>
                                <input class="form-control" type="file" id="<?= e($col) ?>" name="<?= e($col) ?>" accept="image/*">
                                <?php if ($id > 0 && !empty($val)): ?>
                                    <div class="form-text d-flex align-items-center gap-2 mt-1">
                                        <img src="<?= e(azure_blob_url((string) $val)) ?>" alt="" style="height:48px;width:48px;object-fit:contain" class="border rounded bg-white">
                                        <span>Tersimpan di Azure Blob (<code><?= e((string) $val) ?></code>). Unggah ulang untuk mengganti.</span>
                                        <a href="<?= e(azure_blob_url((string) $val)) ?>" target="_blank" class="btn btn-xs btn-outline-primary py-0 px-2">Lihat</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($col) ?>"><?= e($f['label']) ?><?php if (!empty($f['required'])): ?> <span class="text-danger">*</span><?php endif; ?></label>
                            <?php if (($f['type'] ?? '') === 'textarea'): ?>
                                <textarea class="form-control" id="<?= e($col) ?>" name="<?= e($col) ?>" rows="2" maxlength="<?= (int) ($f['max'] ?? 2000) ?>"><?= e((string) $val) ?></textarea>
                            <?php elseif (($f['type'] ?? '') === 'select'): ?>
                                <select class="form-select ts-select" id="<?= e($col) ?>" name="<?= e($col) ?>"
                                    data-ts-placeholder="— pilih —"
                                    <?= isset($f['child']) ? 'data-child="' . e($f['child']) . '"' : '' ?>
                                    <?php if (isset($f['endpoint'])): ?>
                                        data-endpoint="<?= e(APP_URL . '/' . $f['endpoint']) ?>" data-param="<?= e($f['param']) ?>" data-ts-url="<?= e(APP_URL . '/' . $f['endpoint']) ?>" data-ts-parent="#<?= e($f['param']) ?>" data-ts-param="<?= e($f['param']) ?>" data-ts-limit="50"
                                    <?php elseif (($f['source'] ?? '') === 'employees'): ?>
                                        data-ts-url="<?= e(APP_URL . '/pages/employees/options.php?status=all') ?>" data-ts-limit="50"<?= $cid ? ' data-ts-company="' . (int) $cid . '"' : '' ?>
                                    <?php endif; ?>>
                                    <option value="">— pilih —</option>
                                    <?php foreach ($options[$col] as $oid => $oname):
                                        $m = $option_meta[$col][(int) $oid] ?? [];
                                        $attrs = '';
                                        foreach (['country_id' => 'data-country-id', 'country_name' => 'data-country-name', 'province_id' => 'data-province-id', 'province_name' => 'data-province-name', 'city_id' => 'data-city-id', 'city_name' => 'data-city-name'] as $mk => $attrName) {
                                            if (!empty($m[$mk])) {
                                                $attrs .= ' ' . $attrName . '="' . e((string) $m[$mk]) . '"';
                                            }
                                        }
                                    ?>
                                        <option value="<?= (int) $oid ?>"<?= $attrs ?> <?= (int) $val === (int) $oid ? 'selected' : '' ?>><?= e($oname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (($f['type'] ?? '') === 'money' || ($f['type'] ?? '') === 'number'): ?>
                                <input class="form-control" type="number" step="<?= ($f['type'] ?? '') === 'money' ? '0.01' : '1' ?>" id="<?= e($col) ?>" name="<?= e($col) ?>"
                                    value="<?= e((string) ($val ?? '0')) ?>" <?= !empty($f['required']) ? 'required' : '' ?>>
                            <?php else: ?>
                                <input class="form-control<?= !empty($f['upper']) ? ' text-uppercase' : '' ?>" id="<?= e($col) ?>" name="<?= e($col) ?>"
                                    maxlength="<?= (int) ($f['max'] ?? 255) ?>" value="<?= e((string) $val) ?>" <?= !empty($f['required']) ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </form>
            </div>
        </div>
    </main>
    <script>
    // Dropdown bertingkat (Tom Select v2.3.1): isi ulang select anak dari endpoint JSON & sinkron cucu + auto-sync induk ke atas.
    document.addEventListener('DOMContentLoaded', function () {
        function getSelectVal(el) {
            if (!el) return '';
            return el.tomselect ? String(el.tomselect.getValue() || '') : String(el.value || '');
        }
        function getSelectedMeta(el, val) {
            if (!el || !val) return null;
            if (el.tomselect && el.tomselect.options && el.tomselect.options[String(val)]) {
                return el.tomselect.options[String(val)];
            }
            var optEl = Array.from(el.options || []).find(function (o) { return String(o.value) === String(val); });
            if (!optEl || !optEl.dataset) return null;
            return {
                country_id: optEl.dataset.countryId || '',
                country_name: optEl.dataset.countryName || '',
                province_id: optEl.dataset.provinceId || '',
                province_name: optEl.dataset.provinceName || '',
                city_id: optEl.dataset.cityId || '',
                city_name: optEl.dataset.cityName || ''
            };
        }
        var UPWARD_PARENTS = {
            province_id: ['country_id'],
            city_id: ['country_id', 'province_id'],
            district_id: ['country_id', 'province_id', 'city_id']
        };
        function syncParentsUpward(el, val) {
            if (!el || !val) return;
            var chain = UPWARD_PARENTS[el.id];
            if (!chain) return;
            var meta = getSelectedMeta(el, val);
            if (!meta) return;
            chain.forEach(function (ancId) {
                var ancVal = meta[ancId] ? String(meta[ancId]) : '';
                if (!ancVal || ancVal === '0') return;
                var ancEl = document.getElementById(ancId);
                if (!ancEl || getSelectVal(ancEl) === ancVal) return;

                var labelKey = ancId.replace(/_id$/, '_name');
                var ancLabel = (meta[labelKey] ? String(meta[labelKey]) : '') || ('#' + ancVal);
                if (ancEl.tomselect) {
                    if (!ancEl.tomselect.options[ancVal]) {
                        var newOpt = { value: ancVal, id: ancVal, text: ancLabel, name: ancLabel };
                        if (ancId === 'province_id' && meta.country_id) {
                            newOpt.country_id = String(meta.country_id);
                            newOpt.country_name = String(meta.country_name || '');
                        } else if (ancId === 'city_id') {
                            if (meta.country_id) {
                                newOpt.country_id = String(meta.country_id);
                                newOpt.country_name = String(meta.country_name || '');
                            }
                            if (meta.province_id) {
                                newOpt.province_id = String(meta.province_id);
                                newOpt.province_name = String(meta.province_name || '');
                            }
                        }
                        ancEl.tomselect.addOption(newOpt);
                    }
                    if (window.setTsValueSilent) {
                        window.setTsValueSilent(ancEl, ancVal);
                    } else {
                        ancEl.tomselect.setValue(ancVal, true);
                    }
                } else {
                    if (!Array.from(ancEl.options || []).some(function (o) { return String(o.value) === ancVal; })) {
                        ancEl.add(new Option(ancLabel, ancVal));
                    }
                    ancEl.value = ancVal;
                }
            });
        }

        function reloadChild(childEl, preserveVal) {
            if (!childEl) return;
            var ep = childEl.getAttribute('data-endpoint');
            var param = childEl.getAttribute('data-param');
            if (!ep || !param) return;
            var parentField = document.querySelector('select[data-child="' + childEl.id + '"]');
            if (!parentField) return;
            var val = getSelectVal(parentField);
            var curVal = getSelectVal(childEl);
            if (!val) {
                if (window.setTsOptions) {
                    window.setTsOptions(childEl, [], '', '— pilih —');
                }
                var gcId = childEl.getAttribute('data-child');
                if (gcId) reloadChild(document.getElementById(gcId), false);
                return;
            }
            var url = ep + '?' + encodeURIComponent(param) + '=' + encodeURIComponent(val) + '&limit=200';
            fetch(url).then(function (r) { return r.json(); }).then(function (rows) {
                var keep = preserveVal ? curVal : '';
                if (window.setTsOptions) {
                    window.setTsOptions(childEl, rows, keep, '— pilih —');
                }
                var nextVal = getSelectVal(childEl);
                var gcId = childEl.getAttribute('data-child');
                if (gcId && nextVal !== curVal) {
                    reloadChild(document.getElementById(gcId), false);
                }
            });
        }

        document.querySelectorAll('select[data-endpoint]').forEach(function (el) {
            var parentField = document.querySelector('select[data-child="' + el.id + '"]');
            if (parentField) {
                var onParentChange = function () { reloadChild(el, true); };
                if (window.bindTsChange) {
                    window.bindTsChange(parentField, onParentChange);
                } else {
                    parentField.addEventListener('change', onParentChange);
                }
            }
            if (UPWARD_PARENTS[el.id]) {
                var onSelfChange = function (val) { syncParentsUpward(el, val); };
                if (window.bindTsChange) {
                    window.bindTsChange(el, onSelfChange);
                } else {
                    el.addEventListener('change', function () { syncParentsUpward(el, getSelectVal(el)); });
                }
            }
        });
    });
    </script>
    <?php
    require __DIR__ . '/../../includes/footer.php';
}

/** Baris kosong sesuai spec (nilai awal form create). */
function master_empty_row(array $spec): array
{
    $row = [];
    foreach ($spec['fields'] as $f) {
        $t = $f['type'] ?? '';
        if ($t === 'checkbox') {
            $row[$f['col']] = 1;
        } elseif ($t === 'hidden' || $t === 'blob') {
            $row[$f['col']] = null;
        } else {
            $row[$f['col']] = '';
        }
    }
    return $row;
}

/**
 * Upload gambar field 'blob' dari $_FILES[$col] ke Azure Blob Storage.
 * Mengembalikan path blob baru, atau null bila tidak ada berkas diunggah.
 * Folder: {company_code}/{module}/{sub}/{slug}_... (slug = kode baris).
 */
function master_upload_blob(array $f, ?int $cid): ?string
{
    $slug = strtolower(trim((string) ($_POST['code'] ?? ''))) ?: $f['col'];
    return azure_blob_handle_upload(
        $f['col'],
        $cid,
        (string) ($f['module'] ?? 'masters'),
        (string) ($f['sub'] ?? ''),
        $slug,
        false,
        (int) ($f['max_mb'] ?? 2)
    );
}

/** Kolom ada di tabel? (dipakai untuk kolom opsional / lookup dinamis). */
function column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->bind_param('ss', $table, $column);
        $st->execute();
        $cache[$key] = (int) $st->get_result()->fetch_row()[0] > 0;
        $st->close();
    }
    return $cache[$key];
}

/** POST -> map nilai final sesuai spec. */
function master_map_input(array $spec, array $post): array
{
    $out = [];
    foreach ($spec['fields'] as $f) {
        $col = $f['col'];
        $type = $f['type'] ?? 'text';
        if ($type === 'checkbox') {
            $out[$col] = isset($post[$col]) ? 1 : 0;
            continue;
        }
        // Gambar Azure Blob: diunggah di master_form() SETELAH validasi lolos
        // (hindari blob yatim). Tanpa upload, nilai lama dipertahankan.
        if ($type === 'blob') {
            continue;
        }
        // Nilai tersembunyi — tidak menimpa nilai lama bila kosong.
        if ($type === 'hidden') {
            $v = $post[$col] ?? '';
            if ($v !== '') {
                $out[$col] = (string) $v;
            }
            continue;
        }
        $v = trim((string) ($post[$col] ?? ''));
        if ($type === 'select' || ($f['int'] ?? false) === true) {
            $v = $v === '' ? '' : (string) (int) $v;
        } elseif (!empty($f['upper'])) {
            $v = strtoupper($v);
        }
        if (empty($f['required']) && $v === '') {
            $v = !empty($f['nullable']) ? null : (($type === 'money' || $type === 'number') ? '0' : $v);
        }
        $out[$col] = $v;
    }
    return $out;
}

/** Validasi konsistensi hierarki wilayah (geografis). */
function master_validate_geo_hierarchy(string $table, array $in): array
{
    $errors = [];
    $db = db();
    if ($table === 'cities') {
        $cid = (int) ($in['country_id'] ?? 0);
        $pid = (int) ($in['province_id'] ?? 0);
        if ($cid > 0 && $pid > 0) {
            $st = $db->prepare('SELECT country_id FROM provinces WHERE id = ?');
            $st->bind_param('i', $pid);
            $st->execute();
            $prov_cid = $st->get_result()->fetch_column();
            $st->close();
            if ($prov_cid !== false && (int) $prov_cid !== $cid) {
                $errors[] = 'Provinsi tidak sesuai dengan Negara yang dipilih.';
            }
        }
    } elseif ($table === 'districts') {
        $cid = (int) ($in['country_id'] ?? 0);
        $pid = (int) ($in['province_id'] ?? 0);
        $cty = (int) ($in['city_id'] ?? 0);
        if ($cty > 0) {
            $st = $db->prepare('SELECT country_id, province_id FROM cities WHERE id = ?');
            $st->bind_param('i', $cty);
            $st->execute();
            $c_row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($c_row) {
                if ($pid > 0 && (int) $c_row['province_id'] !== $pid) {
                    $errors[] = 'Kota / Kabupaten tidak sesuai dengan Provinsi yang dipilih.';
                }
                if ($cid > 0 && (int) $c_row['country_id'] !== $cid) {
                    $errors[] = 'Kota / Kabupaten tidak sesuai dengan Negara yang dipilih.';
                }
            }
        }
    } elseif ($table === 'villages') {
        $cid = (int) ($in['country_id'] ?? 0);
        $pid = (int) ($in['province_id'] ?? 0);
        $cty = (int) ($in['city_id'] ?? 0);
        $did = (int) ($in['district_id'] ?? 0);
        if ($did > 0) {
            $st = $db->prepare('SELECT country_id, province_id, city_id FROM districts WHERE id = ?');
            $st->bind_param('i', $did);
            $st->execute();
            $d_row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($d_row) {
                if ($cty > 0 && (int) $d_row['city_id'] !== $cty) {
                    $errors[] = 'Kecamatan tidak sesuai dengan Kota / Kabupaten yang dipilih.';
                }
                if ($pid > 0 && (int) $d_row['province_id'] !== $pid) {
                    $errors[] = 'Kecamatan tidak sesuai dengan Provinsi yang dipilih.';
                }
                if ($cid > 0 && (int) $d_row['country_id'] !== $cid) {
                    $errors[] = 'Kecamatan tidak sesuai dengan Negara yang dipilih.';
                }
            }
        }
    }
    return $errors;
}

/** Validasi lengkap. Kembalikan daftar pesan error (kosong = valid). */
function master_validate(array $spec, array $in, int $id, ?int $cid): array
{
    $errors = [];
    foreach ($spec['fields'] as $f) {
        $col = $f['col'];
        $type = $f['type'] ?? 'text';
        if ($type === 'blob' || $type === 'hidden') {
            continue;
        }
        $v = $in[$col] ?? null;
        $label = $f['label'];

        if (!empty($f['required']) && ($v === null || $v === '')) {
            $errors[] = $label . ' wajib diisi.';
            continue;
        }
        if ($v === null || $v === '') {
            continue;
        }
        $max_len = (int) ($f['max'] ?? ($type === 'money' || $type === 'number' ? 20 : 255));
        if ($max_len > 0 && mb_strlen((string) $v) > $max_len) {
            $errors[] = $label . ' maksimal ' . $max_len . ' karakter.';
        }
        if (!empty($f['code']) && !master_code_ok((string) $v)) {
            $errors[] = $label . ' wajib 1–15 karakter: huruf, angka, atau strip.';
        }
        if ($type === 'money' || $type === 'number') {
            if (!master_num_ok((string) $v)) {
                $errors[] = $label . ' harus berupa angka.';
            }
        }
        if ($type === 'select') {
            if (!master_relation_ok($f['source'], (int) $v, $cid)) {
                $errors[] = $label . ' harus dipilih dari daftar yang tersedia.';
            } elseif ($f['source'] === $spec['table']) {
                // Self-reference: cegah parent = diri sendiri dan siklus.
                if ((int) $v === $id && $id > 0) {
                    $errors[] = $label . ' tidak boleh menunjuk ke dirinya sendiri.';
                } elseif ($id > 0 && master_cycle_detected(master_parent_map($spec, $cid), $id, (int) $v)) {
                    $errors[] = $label . ' membentuk siklus struktur.';
                }
            }
        }
    }

    // Validasi konsistensi hierarki wilayah (geografis)
    if (in_array($spec['table'], ['cities', 'districts', 'villages'], true)) {
        $geo_errors = master_validate_geo_hierarchy($spec['table'], $in);
        if ($geo_errors) {
            $errors = array_merge($errors, $geo_errors);
        }
    }

    // Keunikan kode.
    if (!$errors) {
        $code_field = null;
        foreach ($spec['fields'] as $f) {
            if ($f['col'] === 'code') {
                $code_field = $f;
                break;
            }
        }
        if ($code_field !== null && $in['code'] !== null && $in['code'] !== '') {
            if (!master_code_unique($spec, (string) $in['code'], $cid, $id)) {
                $errors[] = 'Kode sudah dipakai pada ' . mb_strtolower($spec['title']) . ' lain' . (!empty($spec['company']) ? ' di perusahaan ini' : '') . '.';
            }
        }
    }

    if ($id === 0 && !empty($spec['company']) && $cid === null) {
        $errors[] = 'Perusahaan aktif belum dipilih, tidak dapat menambah data.';
    }
    return $errors;
}

/** Peta [id => parent_id] untuk deteksi siklus (discope tenant). */
function master_parent_map(array $spec, ?int $cid): array
{
    [$where, $types, $params] = master_scope($spec);
    $st = db()->prepare("SELECT id, parent_id FROM `{$spec['table']}` WHERE {$where}");
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
    }
    return $map;
}

function master_code_unique(array $spec, string $code, ?int $cid, int $exclude_id): bool
{
    $table = $spec['table'];
    $sql = "SELECT COUNT(*) FROM `{$table}` WHERE code = ? AND id <> ?";
    $types = 'si';
    $params = [$code, $exclude_id];
    if (!empty($spec['company'])) {
        if (is_super_admin()) {
            // Super Admin lintas perusahaan: unik per (company_id) baris.
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE code = ? AND id <> ? AND company_id <=> ?";
            $types = 'sii';
            $params = [$code, $exclude_id, $cid];
        } elseif (($spec['unique'] ?? '') === 'company_or_null') {
            $sql .= ' AND (company_id = ? OR company_id IS NULL)';
            $types = 'sii';
            $params[] = (int) $cid;
        } else {
            $sql .= ' AND company_id = ?';
            $types = 'sii';
            $params[] = (int) $cid;
        }
    }
    $st = db()->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n === 0;
}

/** Cari baris berdasarkan kode (discope tenant). null = belum ada. */
function master_find_code(array $spec, string $code, ?int $cid): ?array
{
    if ($code === '') {
        return null;
    }
    $sql = 'SELECT * FROM `' . $spec['table'] . '` WHERE code = ?';
    $types = 's';
    $params = [$code];
    if (!empty($spec['company'])) {
        if (is_super_admin()) {
            $sql .= ' AND company_id <=> ?';
            $types .= 'i';
            $params[] = $cid;
        } elseif (($spec['unique'] ?? '') === 'company_or_null') {
            $sql .= ' AND (company_id = ? OR company_id IS NULL)';
            $types .= 'i';
            $params[] = (int) $cid;
        } else {
            $sql .= ' AND company_id = ?';
            $types .= 'i';
            $params[] = (int) $cid;
        }
    }
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->bind_param($types, ...$params);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Cari data existing untuk form/import (berdasarkan code atau kunci unik alternatif). */
function master_find_existing(array $spec, array $in, ?int $cid): ?array
{
    if (!empty($in['code'])) {
        return master_find_code($spec, (string) $in['code'], $cid);
    }
    if ($spec['table'] === 'employee_recognitions') {
        $emp_id = (int) ($in['employee_id'] ?? 0);
        $type = (string) ($in['type'] ?? '');
        $idx = (int) ($in['index_no'] ?? 0);
        if ($emp_id > 0 && $type !== '') {
            $st = db()->prepare('SELECT * FROM employee_recognitions WHERE employee_id = ? AND type = ? AND index_no = ? AND company_id = ? LIMIT 1');
            $st->bind_param('isii', $emp_id, $type, $idx, $cid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            return $row ?: null;
        }
    }
    return null;
}

/**
 * Resolusi nilai relasi: bisa ID angka, Kode, atau Nama.
 * Berguna saat import CSV agar user tidak harus tahu ID integer internal.
 */
function master_resolve_relation_id(string $source, string $raw_val, ?int $cid): ?int
{
    $val = trim($raw_val);
    if ($val === '') {
        return null;
    }
    $db = db();
    // 1. Coba sebagai ID numerik langsung
    if (ctype_digit($val)) {
        $id = (int) $val;
        if (master_relation_ok($source, $id, $cid)) {
            return $id;
        }
    }
    // 2. Coba lookup berdasarkan code jika ada kolom code
    if (column_exists($source, 'code')) {
        $sql = "SELECT id FROM `{$source}` WHERE code = ?";
        $types = 's';
        $params = [$val];
        if ($source === 'job_titles') {
            if ($cid !== null) {
                $sql .= ' AND (company_id = ? OR company_id IS NULL)';
                $types .= 'i';
                $params[] = (int) $cid;
            }
        } elseif (column_exists($source, 'company_id') && $cid !== null) {
            $sql .= ' AND company_id = ?';
            $types .= 'i';
            $params[] = (int) $cid;
        }
        $st = $db->prepare($sql . ' LIMIT 1');
        $st->bind_param($types, ...$params);
        $st->execute();
        $found = $st->get_result()->fetch_column();
        $st->close();
        if ($found) {
            return (int) $found;
        }
    }
    // 3. Coba lookup berdasarkan name jika ada kolom name
    if (column_exists($source, 'name')) {
        $sql = "SELECT id FROM `{$source}` WHERE name = ?";
        $types = 's';
        $params = [$val];
        if ($source === 'job_titles') {
            if ($cid !== null) {
                $sql .= ' AND (company_id = ? OR company_id IS NULL)';
                $types .= 'i';
                $params[] = (int) $cid;
            }
        } elseif (column_exists($source, 'company_id') && $cid !== null) {
            $sql .= ' AND company_id = ?';
            $types .= 'i';
            $params[] = (int) $cid;
        }
        $st = $db->prepare($sql . ' LIMIT 1');
        $st->bind_param($types, ...$params);
        $st->execute();
        $found = $st->get_result()->fetch_column();
        $st->close();
        if ($found) {
            return (int) $found;
        }
    }
    return null;
}

/** true bila ada nilai yang benar-benar berubah (untuk laporan upsert). */
function master_import_only_changed(array $spec, array $existing, array $in): bool
{
    foreach ($in as $col => $v) {
        if ((string) ($existing[$col] ?? null) !== (string) $v) {
            return true;
        }
    }
    return false;
}

/**
 * Insert/update. company_id hanya diset saat create (dari sesi). Update
 * tidak mengubah company_id; akses baris sudah dijamin master_find().
 */
function master_save(array $spec, int $id, array $in, ?int $cid): int
{
    $table = $spec['table'];
    $cols = array_keys($in);
    $user = (string) ($_SESSION['username'] ?? '');
    $now = date('Y-m-d H:i:s');

    $types_map = [];
    foreach ($spec['fields'] as $f) {
        $t = 's';
        if (($f['type'] ?? '') === 'money') {
            $t = 'd';
        } elseif (!empty($f['int']) || ($f['type'] ?? '') === 'checkbox') {
            $t = 'i';
        }
        // Kolom blob (path Azure) bertipe 's' (VARCHAR).
        $types_map[$f['col']] = $t;
    }

    if ($id === 0) {
        // Hanya tabel ber-company_id yang punya kolom company_id (referensi global tidak).
        $has_company = !empty($spec['company']);
        $cols_sql = array_merge($has_company ? ['company_id'] : [], ['created_user', 'created_date'], $cols);
        $vals = array_merge($has_company ? [$cid] : [], [$user, $now], array_map(fn ($c) => $in[$c], $cols));
        $types = ($has_company ? 'i' : '') . 'ss' . implode('', array_map(fn ($c) => $types_map[$c], $cols));
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols_sql) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols_sql), '?')) . ')';
    } else {
        $old_name = null;
        if ($table === 'job_grades' && isset($in['name'])) {
            $st_old = db()->prepare('SELECT name, company_id FROM job_grades WHERE id = ?');
            $st_old->bind_param('i', $id);
            $st_old->execute();
            $r_old = $st_old->get_result()->fetch_assoc();
            $st_old->close();
            if ($r_old) {
                $old_name = $r_old['name'];
                $cid = $cid ?? ($r_old['company_id'] !== null ? (int) $r_old['company_id'] : null);
            }
        }

        $set = array_merge(['modified_user', 'modified_date'], $cols);
        $vals = array_merge([$user, $now], array_map(fn ($c) => $in[$c], $cols), [$id]);
        $types = 'ss' . implode('', array_map(fn ($c) => $types_map[$c], $cols)) . 'i';
        $sql = "UPDATE `{$table}` SET " . implode(', ', array_map(fn ($c) => "`{$c}` = ?", $set)) . ' WHERE id = ?';
        if (!empty($spec['company'])) { // defense-in-depth: scope tenant di SQL juga (NULL = global)
            $sql .= ' AND (company_id = ? OR company_id IS NULL)';
            $vals[] = $cid;
            $types .= 'i';
        }
    }

    $st = db()->prepare($sql);
    $st->bind_param($types, ...$vals);
    $st->execute();
    $new_id = $id === 0 ? $st->insert_id : $id;
    $st->close();

    // Sinkronisasi nama grade pada employees jika grade diubah namanya
    if ($id > 0 && isset($old_name) && $old_name !== '' && $old_name !== $in['name'] && $cid !== null) {
        $st_sync = db()->prepare('UPDATE employees SET job_grade = ? WHERE job_grade = ? AND company_id = ?');
        $st_sync->bind_param('ssi', $in['name'], $old_name, $cid);
        $st_sync->execute();
        $st_sync->close();
    }

    return $new_id;
}

// ---------------------------------------------------------------------
// Reorder (naik/turun) — tabel master yang punya kolom urutan ($spec['order'])
// ---------------------------------------------------------------------

/**
 * Beri ulang 10,20,30… menurut urutan efektif daftar (ORDER BY kolom urutan, id).
 * Aktif HANYA bila ada duplikat/NULL — nilai unik yang sudah rapi (mis. 10,20,30
 * dari awal) dibiarkan apa adanya, jadi memindahkan baris tidak pernah menolak
 * urutan "aneh" yang ditulis manual di form.
 */
function master_normalize_order(array $spec): void
{
    [$where, $types, $params] = master_scope($spec);
    $table = $spec['table'];
    $col = $spec['order'];
    $st = db()->prepare("SELECT id, `{$col}` FROM `{$table}` WHERE {$where} ORDER BY `{$col}`, id");
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $seen = [];
    $dirty = false;
    foreach ($rows as $r) {
        $v = $r[$col] === null ? null : (int) $r[$col];
        if ($v === null || isset($seen[$v])) {
            $dirty = true;
            break;
        }
        $seen[$v] = true;
    }
    if (!$dirty) {
        return;
    }

    $fixes = [];
    $n = 10;
    foreach ($rows as $r) { // urutan efektif daftar dipertahankan, nilainya dinormalkan
        $fixes[] = [(int) $r['id'], $n];
        $n += 10;
    }
    $st = db()->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE id = ?");
    foreach ($fixes as [$id, $v]) {
        $st->bind_param('ii', $v, $id);
        $st->execute();
    }
    $st->close();
}

/**
 * Aksi POST naik/turun urutan item master. Param: id, dir ('up'|'down' → ±1), csrf_token.
 * Tetangga diambil di dalam scope perusahaan aktif, lalu sort_order bertukar.
 * Endpoint tipis: pages/<modul>/<tabel>/move.php memanggil master_move($key, $id, $dir).
 */
function master_move(string $key, int $id, int $dir): void
{
    $spec = master_spec($key);
    $base = 'pages/' . $spec['module'] . '/' . $spec['table'] . '/index.php';
    require_permission($spec['perm_manage']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($base);
    }
    verify_csrf();

    $row = $dir !== 0 ? master_find($spec, $id) : null; // 403 bila baris tenant lain
    if (!$row) {
        flash('error', $spec['title'] . ' tidak ditemukan.');
        redirect($base);
    }

    master_normalize_order($spec);

    // Ambil ulang baris setelah normalisasi: nilai urut bisa berubah di langkah itu.
    $row = master_find($spec, (int) $row['id']);
    if (!$row) {
        flash('error', $spec['title'] . ' tidak ditemukan.');
        redirect($base);
    }

    $col = $spec['order'];
    $cmp = $dir < 0 ? '<' : '>';
    $ord = $dir < 0 ? 'DESC' : 'ASC';
    $val = $row[$col] === null ? 0 : (int) $row[$col];

    // Tetangga terdekat di dalam scope tenant yang sama dengan baris yang dipindahkan
    $row_cid = $row['company_id'] ?? null;
    $table = $spec['table'];
    if (!empty($spec['company'])) {
        if ($row_cid !== null) {
            $where = "company_id = ?";
            $types = "i";
            $params = [(int) $row_cid];
        } else {
            $where = "company_id IS NULL";
            $types = "";
            $params = [];
        }
    } else {
        $where = "1=1";
        $types = "";
        $params = [];
    }

    $st = db()->prepare("SELECT id, `{$col}` FROM `{$table}` WHERE {$where} AND `{$col}` {$cmp} ? ORDER BY `{$col}` {$ord} LIMIT 1");
    $types .= 'i'; // tambah satu parameter untuk nilai urutan
    $params = [...$params, $val];
    $st->bind_param($types, ...$params);
    $st->execute();
    $neighbor = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$neighbor) {
        redirect($base); // di tepi daftar — tidak ada yang diubah
    }

    $nval = $neighbor[$col] === null ? 0 : (int) $neighbor[$col];
    $id = (int) $row['id'];
    $nid = (int) $neighbor['id'];
    $st = db()->prepare("UPDATE `{$table}` SET `{$col}` = CASE id WHEN ? THEN ? WHEN ? THEN ? END WHERE id IN (?, ?)");
    $st->bind_param('iiiiii', $id, $nval, $nid, $val, $id, $nid);
    $st->execute();
    $st->close();

    audit($spec['module'] . '.move', $spec['title'] . " #{$id} " . ($dir < 0 ? 'naik' : 'turun'));
    redirect($base);
}

// ---------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------

function master_delete(string $key): void
{
    $spec = master_spec($key);
    require_permission($spec['perm_manage']);
    $base = 'pages/' . $spec['module'] . '/' . $spec['table'] . '/index.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($base);
    }
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $row = master_find($spec, $id); // tabel ber-company_id: 403 bila tenant lain
    if (!$row) {
        flash('error', $spec['title'] . ' tidak ditemukan.');
        redirect($base);
    }

    // Cek relasi dulu agar tidak muncul error SQL mentah (FK RESTRICT).
    foreach ($spec['blockers'] ?? [] as $b) {
        $match_by = $b['match'] ?? 'id';
        if ($match_by === 'name') {
            $val = (string) ($row['name'] ?? '');
            if (!empty($spec['company']) && column_exists($b['table'], 'company_id')) {
                $sql_b = "SELECT COUNT(*) FROM `{$b['table']}` WHERE `{$b['col']}` = ? AND company_id = ?";
                $st = db()->prepare($sql_b);
                $cid_val = (int) ($row['company_id'] ?? active_company_id());
                $st->bind_param('si', $val, $cid_val);
            } else {
                $sql_b = "SELECT COUNT(*) FROM `{$b['table']}` WHERE `{$b['col']}` = ?";
                $st = db()->prepare($sql_b);
                $st->bind_param('s', $val);
            }
        } else {
            $sql_b = "SELECT COUNT(*) FROM `{$b['table']}` WHERE `{$b['col']}` = ?";
            if (!empty($spec['company']) && column_exists($b['table'], 'company_id')) {
                $sql_b .= " AND company_id = ?";
                $st = db()->prepare($sql_b);
                $cid_val = (int) ($row['company_id'] ?? active_company_id());
                $st->bind_param('ii', $id, $cid_val);
            } else {
                $st = db()->prepare($sql_b);
                $st->bind_param('i', $id);
            }
        }
        $st->execute();
        $n = (int) $st->get_result()->fetch_row()[0];
        $st->close();
        if ($n > 0) {
            flash('error', $spec['title'] . ' tidak dapat dihapus: masih dipakai oleh ' . $n . ' ' . $b['label'] . '.');
            redirect($base);
        }
    }

    try {
        $sql = "DELETE FROM `{$spec['table']}` WHERE id = ?";
        $params = [$id];
        $types = 'i';
        if (!empty($spec['company']) && !is_super_admin()) { // Non-superadmin dibatasi ke perusahaannya
            if ($row['company_id'] === null) {
                flash('error', 'Data bawaan sistem (global) hanya dapat dihapus oleh Super Admin.');
                redirect($base);
            }
            $sql .= ' AND company_id = ?';
            $params[] = (int) active_company_id();
            $types .= 'i';
        }
        $st = db()->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();
        if ($affected > 0) {
            audit($spec['module'] . '.delete', $spec['title'] . " #{$id} " . ($row['code'] ?? ''));
            flash('success', $spec['title'] . ' berhasil dihapus.');
        } else {
            flash('error', $spec['title'] . ' tidak dapat dihapus atau sudah dihapus.');
        }
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1451) {
            flash('error', $spec['title'] . ' tidak dapat dihapus: masih dipakai oleh data lain.');
        } else {
            throw $ex;
        }
    }
    redirect($base);
}

// ---------------------------------------------------------------------
// Endpoint JSON dropdown bertingkat
// ---------------------------------------------------------------------

/**
 * Output JSON [{id, text}] untuk select anak.
 * $source = tabel sumber; $parent_col = kolom FK yang difilter ($_GET[$param]).
 */
function master_options_json(string $source, string $parent_col, string $param): void
{
    require_login();
    header('Content-Type: application/json; charset=utf-8');

    if (!in_array($source, ['countries', 'provinces', 'cities', 'districts', 'villages'], true)) {
        echo '[]';
        return;
    }

    $parent       = (int) ($_GET[$param] ?? 0);
    $q            = trim((string) ($_GET['q'] ?? ''));
    $allowAll     = (($_GET['all'] ?? '') === '1');
    $withCode     = (($_GET['with_code'] ?? '') === '1');
    $withProvince = (($_GET['with_province'] ?? '') === '1');
    $withCity     = (($_GET['with_city'] ?? '') === '1');
    $hasQuery     = ($q !== '');
    $limit        = isset($_GET['limit']) ? (int) $_GET['limit'] : ($hasQuery ? 50 : 200);
    $limit        = max(1, min(200, $limit));

    if ($parent <= 0 && !$hasQuery && !$allowAll) {
        echo '[]';
        return;
    }

    $baseSql   = master_geo_select_query($source, $withCode, $withProvince, $withCity);
    $parentSql = $parent > 0 ? "t.`{$parent_col}` = ?" : '1=1';

    $db   = db();
    $rows = [];
    $seen = [];
    $append = static function (array $batch) use (&$rows, &$seen, $limit): void {
        foreach ($batch as $r) {
            $id = (int) $r['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = $r;
            if (count($rows) >= $limit) {
                break;
            }
        }
    };

    if (!$hasQuery) {
        $orderBy = $source === 'provinces' ? 't.code ASC, t.name ASC' : 't.name ASC';
        $sql = "{$baseSql} WHERE {$parentSql} ORDER BY {$orderBy} LIMIT ?";
        $st  = $db->prepare($sql);
        if ($parent > 0) {
            $st->bind_param('ii', $parent, $limit);
        } else {
            $st->bind_param('i', $limit);
        }
        $st->execute();
        $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();
    } else {
        $escaped = addcslashes($q, '%_\\');
        $prefix  = $escaped . '%';
        $searchCond = ($source === 'provinces' && preg_match('/^[0-9]+$/', $q))
            ? '(t.code LIKE ? OR t.name LIKE ?)'
            : 't.name LIKE ?';
        $searchParams = ($source === 'provinces' && preg_match('/^[0-9]+$/', $q))
            ? [$prefix, $prefix]
            : [$prefix];
        $searchTypes = str_repeat('s', count($searchParams));

        // Query 1: prefix match berbasis index ix_*_name
        $sql = "{$baseSql} WHERE {$parentSql} AND {$searchCond} ORDER BY t.name ASC LIMIT ?";
        $st  = $db->prepare($sql);
        if ($parent > 0) {
            $st->bind_param('i' . $searchTypes . 'i', $parent, ...[...$searchParams, $limit]);
        } else {
            $st->bind_param($searchTypes . 'i', ...[...$searchParams, $limit]);
        }
        $st->execute();
        $append($st->get_result()->fetch_all(MYSQLI_ASSOC));
        $st->close();

        // Fallback infix hanya bila hasil prefix masih sedikit & kata kunci >= 2 karakter
        if (count($rows) < min(15, $limit) && mb_strlen($q) >= 2) {
            $rem  = $limit - count($rows);
            $like = '%' . $escaped . '%';
            $sql2 = "{$baseSql} WHERE {$parentSql} AND t.name LIKE ? ORDER BY t.name ASC LIMIT ?";
            $st2  = $db->prepare($sql2);
            if ($parent > 0) {
                $st2->bind_param('isi', $parent, $like, $rem);
            } else {
                $st2->bind_param('si', $like, $rem);
            }
            $st2->execute();
            $append($st2->get_result()->fetch_all(MYSQLI_ASSOC));
            $st2->close();
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $item = array_merge([
            'value' => (string) (int) $r['id'],
            'id'    => (int) $r['id'],
            'text'  => (string) $r['name'],
        ], master_geo_row_meta($r));
        $out[] = $item;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
