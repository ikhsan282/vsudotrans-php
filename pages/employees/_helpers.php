<?php
/**
 * Employee (karyawan) — helper data layer ber-tenant.
 *
 * Contract keamanan:
 *  - list selalu discope lewat company_scope('company_id');
 *  - setiap baca/ubah satu baris WAJIB assert_company_access($row['company_id']);
 *  - company_id saat create berasal dari active_company_id(), tidak pernah dari POST;
 *  - semua query memakai prepared statement (tidak ada interpolasi input).
 */

/** Company id aktif; user non-superadmin tanpa perusahaan -> 403. */
function employee_scope_cid(): ?int
{
    $cid = active_company_id();
    if ($cid === null && !is_super_admin()) {
        http_response_code(403);
        include __DIR__ . '/../../includes/403.php';
        exit;
    }
    return $cid;
}

/** Urai tanggal ISO (Y-m-d). '' = kosong; false = tidak valid. */
function employee_parse_date(array $post, string $key)
{
    $raw = trim((string) ($post[$key] ?? ''));
    if ($raw === '') {
        return '';
    }
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : false;
}

/** Cari satu karyawan, discope tenant (non-Super Admin hanya baris perusahaan aktif). */
function employee_find(int $id): ?array
{
    [$wsql, $wtypes, $wparams] = company_scope('company_id');
    $stmt = db()->prepare("SELECT * FROM employees WHERE id = ? AND {$wsql} LIMIT 1");
    $stmt->bind_param('i' . $wtypes, $id, ...$wparams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Cabang aktif milik perusahaan $cid. null (belum ada perusahaan aktif) -> kosong, tidak lintas tenant. */
function employee_branch_options(?int $cid): array
{
    if ($cid === null) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT id, code, name FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY name'
    );
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Pastikan kolom employees.organization_structure tersedia.
 */
function employee_ensure_hr_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = db();
    $res = $db->query("SHOW COLUMNS FROM `employees` LIKE 'organization_structure'");
    if ($res && $res->num_rows === 0) {
        $db->query("ALTER TABLE `employees` ADD COLUMN `organization_structure` VARCHAR(100) DEFAULT NULL AFTER `job_position`");
    }
}

/**
 * Ambil opsi Master HR aktif (Jabatan, Posisi, Struktur Organisasi) untuk perusahaan $cid.
 *
 * @return array{job_titles: list<array<string,mixed>>, job_positions: list<array<string,mixed>>, organization_structures: list<array<string,mixed>>}
 */
function employee_hr_options(?int $cid): array
{
    employee_ensure_hr_schema();
    $db = db();
    $titles = [];
    $positions = [];
    $structures = [];

    if ($cid !== null) {
        $st = $db->prepare('SELECT id, code, name, level FROM job_titles WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL) ORDER BY level ASC, name ASC');
        $st->bind_param('i', $cid);
        $st->execute();
        $titles = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $st = $db->prepare('SELECT jp.id, jp.code, jp.name, jp.level, jt.name AS job_title_name
            FROM job_positions jp
            LEFT JOIN job_titles jt ON jt.id = jp.job_title_id
            WHERE jp.is_active = 1 AND jp.company_id = ?
            ORDER BY jp.level ASC, jp.name ASC');
        $st->bind_param('i', $cid);
        $st->execute();
        $positions = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $st = $db->prepare('SELECT id, code, name, level FROM organization_structures WHERE is_active = 1 AND company_id = ? ORDER BY level ASC, name ASC');
        $st->bind_param('i', $cid);
        $st->execute();
        $structures = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } else {
        $titles = $db->query('SELECT id, code, name, level FROM job_titles WHERE is_active = 1 ORDER BY level ASC, name ASC')->fetch_all(MYSQLI_ASSOC);
        $positions = $db->query('SELECT jp.id, jp.code, jp.name, jp.level, jt.name AS job_title_name
            FROM job_positions jp
            LEFT JOIN job_titles jt ON jt.id = jp.job_title_id
            WHERE jp.is_active = 1
            ORDER BY jp.level ASC, jp.name ASC')->fetch_all(MYSQLI_ASSOC);
        $structures = $db->query('SELECT id, code, name, level FROM organization_structures WHERE is_active = 1 ORDER BY level ASC, name ASC')->fetch_all(MYSQLI_ASSOC);
    }

    return [
        'job_titles'              => $titles,
        'job_positions'           => $positions,
        'organization_structures' => $structures,
    ];
}

/** Nama perusahaan (dipakai judul form pada Super Admin). */
function employee_company_name(?int $cid): string
{
    if ($cid === null) {
        return 'Semua perusahaan';
    }
    $stmt = db()->prepare('SELECT name FROM companies WHERE id = ?');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $name = $stmt->get_result()->fetch_column();
    $stmt->close();
    return (string) $name;
}

/**
 * Cabang harus ada dan milik perusahaan target ($company_id).
 */
function employee_branch_owner(int $branch_id, int $company_id): bool
{
    if ($branch_id <= 0) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM branches WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $branch_id, $company_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/** Apakah code unik untuk company tertentu (exclude_id = id baris saat edit). */
function employee_code_unique(string $code, ?int $company_id, int $exclude_id = 0): bool
{
    if ($company_id === null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE code = ? AND company_id IS NULL AND id <> ?');
        $stmt->bind_param('si', $code, $exclude_id);
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE code = ? AND company_id = ? AND id <> ?');
        $stmt->bind_param('sii', $code, $company_id, $exclude_id);
    }
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n === 0;
}

/**
 * Daftar karyawan (search + filter status + pagination), discope tenant.
 *
 * @return array{0: list<array<string,mixed>>, 1: array} [rows, pagination]
 */
function employee_list(int $page, string $q, string $status, ?int $filter_cid = null): array
{
    employee_ensure_hr_schema();
    // Alias 'e.' wajib: query ini JOIN branches/companies yang juga punya company_id -> ambigu.
    if (is_super_admin() && $filter_cid !== null) {
        [$wsql, $wtypes, $wparams] = $filter_cid > 0 ? ['e.company_id = ?', 'i', [$filter_cid]] : ['1=1', '', []];
    } else {
        [$wsql, $wtypes, $wparams] = company_scope('e.company_id');
    }
    $where = [$wsql];
    $types = $wtypes;
    $params = [];
    foreach ($wparams as $v) {
        $params[] = $v;
    }

    if ($q !== '') {
        $where[] = '(e.name LIKE ? OR e.code LIKE ?)';
        $types .= 'ss';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($status !== '') {
        $where[] = 'e.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $where_sql = implode(' AND ', $where);

    $count_stmt = db()->prepare('SELECT COUNT(*) FROM employees e WHERE ' . $where_sql);
    if ($params) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int) $count_stmt->get_result()->fetch_row()[0];
    $count_stmt->close();

    $p = paginate($total, $page);
    $sql = 'SELECT e.*, b.name AS branch_name, co.code AS company_code, co.name AS company_name
        FROM employees e
        LEFT JOIN branches b ON b.id = e.branch_id
        LEFT JOIN companies co ON co.id = e.company_id
        WHERE ' . $where_sql . '
        ORDER BY e.code ASC
        LIMIT ? OFFSET ?';
    $stmt = db()->prepare($sql);
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$p['per_page'], $p['offset']]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [$rows, $p];
}

/**
 * Urai input nominal uang (mendukung format Indonesia `3.200.000` / `3.200.000,50`
 * maupun desimal standar `3200000.50`).
 */
function employee_parse_money($raw): float
{
    $s = trim(str_ireplace(['Rp', ' '], '', (string) $raw));
    if ($s === '') {
        return 0.0;
    }
    if (preg_match('/^-?\d+\.\d{1,2}$/', $s)) {
        return max(0.0, (float) $s);
    }
    $s = str_replace(['.', ','], ['', '.'], $s);
    return max(0.0, (float) $s);
}

/**
 * Petakan input POST -> data insert/update (hanya kolom yang ada di form).
 *
 * @return array{data: array<string,string|null>, types: array<string,string>, dates: array}
 */
function employee_map_input(array $post): array
{
    $str = static fn(string $k): ?string => (trim((string) ($post[$k] ?? '')) !== '' ? trim((string) $post[$k]) : null);
    $dates = [
        'join_date'        => employee_parse_date($post, 'join_date'),
        'resignation_date' => employee_parse_date($post, 'resignation_date'),
        'birth_date'       => employee_parse_date($post, 'birth_date'),
        'sim_due'          => employee_parse_date($post, 'sim_due'),
    ];
    $branch_raw = trim((string) ($post['branch_id'] ?? ''));
    $branch = ($branch_raw === '' || $branch_raw === '0') ? null : (string) (int) $branch_raw;

    $data = [
        'branch_id'        => $branch,
        'code'             => trim((string) ($post['code'] ?? '')),
        'name'             => trim((string) ($post['name'] ?? '')),
        'email'            => $str('email'),
        'phone_number'     => normalize_phone_62((string) ($post['phone_number'] ?? '')),
        'join_date'        => $dates['join_date'] === '' || $dates['join_date'] === false ? '' : $dates['join_date'],
        'resignation_date' => $dates['resignation_date'] === '' || $dates['resignation_date'] === false ? null : $dates['resignation_date'],
        'job_title'              => $str('job_title'),
        'job_position'           => $str('job_position'),
        'organization_structure' => $str('organization_structure'),
        'bank_name'              => $str('bank_name'),
        'account_number'   => $str('account_number'),
        'account_name'     => $str('account_name'),
        'id_number'        => $str('id_number'),
        'family_number'    => $str('family_number'),
        'tax_number'       => $str('tax_number'),
        'birth_date'       => $dates['birth_date'] === '' || $dates['birth_date'] === false ? null : $dates['birth_date'],
        'birth_place'      => $str('birth_place'),
        'gender'           => in_array($post['gender'] ?? '', ['L', 'P'], true) ? $post['gender'] : null,
        'religion'         => $str('religion'),
        'marital_status'   => $str('marital_status'),
        'address'          => $str('address'),
        'photo'            => $str('photo'),
        'ktp_doc_path'     => $str('ktp_doc_path'),
        'sim_doc_path'     => $str('sim_doc_path'),
        'sim_due'          => $dates['sim_due'] === '' || $dates['sim_due'] === false ? null : $dates['sim_due'],
        'status'           => in_array($post['status'] ?? '', ['aktif', 'resign'], true) ? $post['status'] : 'aktif',
    ];
    $types = ['branch_id' => 'i'];
    foreach (array_keys($data) as $f) {
        $types[$f] ??= 's';
    }
    return ['data' => $data, 'types' => $types, 'dates' => $dates];
}

/**
 * Validasi input form. Kembalikan list pesan error (kosong = valid).
 *
 * @param array{data: array, dates: array, types: array, id?: int} $mapped
 */
function employee_validate(array $mapped): array
{
    $errors = [];
    $cid = $mapped['company_id'];
    $is_create = empty($mapped['id']);

    if ($mapped['data']['code'] === '') {
        $errors[] = 'Kode karyawan wajib diisi.';
    } elseif (strlen($mapped['data']['code']) > 15) {
        $errors[] = 'Kode karyawan maksimal 15 karakter.';
    } elseif (!employee_code_unique($mapped['data']['code'], $cid, (int) ($mapped['id'] ?? 0))) {
        $errors[] = 'Kode karyawan sudah dipakai pada perusahaan ini.';
    }

    if ($mapped['data']['name'] === '') {
        $errors[] = 'Nama karyawan wajib diisi.';
    }
    if ($mapped['dates']['join_date'] === false || $mapped['data']['join_date'] === '') {
        $errors[] = 'Tanggal masuk wajib diisi dengan format valid.';
    }
    if ($mapped['dates']['resignation_date'] === false) {
        $errors[] = 'Tanggal resign tidak valid.';
    }
    if ($mapped['dates']['birth_date'] === false) {
        $errors[] = 'Tanggal lahir tidak valid.';
    }
    if ($mapped['dates']['sim_due'] === false) {
        $errors[] = 'Tanggal jatuh tempo SIM tidak valid.';
    }
    if ($mapped['data']['resignation_date'] !== null && $mapped['data']['join_date'] !== ''
        && $mapped['data']['resignation_date'] < $mapped['data']['join_date']) {
        $errors[] = 'Tanggal resign tidak boleh lebih awal dari tanggal masuk.';
    }
    if ($mapped['data']['birth_date'] !== null && $mapped['data']['birth_date'] > date('Y-m-d')) {
        $errors[] = 'Tanggal lahir tidak boleh melebihi hari ini.';
    }
    if ($is_create && $cid === null) {
        $errors[] = 'Perusahaan aktif belum dipilih, tidak dapat menambah karyawan.';
    }
    if ($mapped['data']['branch_id'] !== null && $cid !== null
        && !employee_branch_owner((int) $mapped['data']['branch_id'], $cid)) {
        $errors[] = 'Cabang harus dipilih dari daftar milik perusahaan aktif.';
    }
    if ($mapped['data']['email'] !== null && !filter_var($mapped['data']['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if ($mapped['data']['phone_number'] !== null && strlen($mapped['data']['phone_number']) > 20) {
        $errors[] = 'No. Telepon maksimal 20 digit (format 62...).';
    }
    return $errors;
}

/** Insert/update. Kembalikan id baris. company_id create = active_company_id(). */
function employee_save(int $id, array $mapped): int
{
    employee_ensure_hr_schema();
    $data = $mapped['data'];
    $cols = [
        'branch_id', 'code', 'name', 'email', 'phone_number', 'join_date', 'resignation_date',
        'job_title', 'job_position', 'organization_structure', 'bank_name', 'account_number', 'account_name',
        'id_number', 'family_number', 'tax_number', 'birth_date', 'birth_place', 'gender',
        'religion', 'marital_status', 'address', 'photo', 'ktp_doc_path', 'sim_doc_path', 'sim_due', 'status',
    ];
    $colTypes = $mapped['types'];
    $user = (string) ($_SESSION['username'] ?? '');
    $now = date('Y-m-d H:i:s');

    if ($id === 0) {
        $cols_sql = array_merge(['company_id', 'created_user', 'created_date'], $cols);
        $vals = array_merge([active_company_id(), $user, $now], array_map(fn($c) => $data[$c], $cols));
        $types = 'iss' . implode('', array_map(fn($c) => $colTypes[$c], $cols));
        $sql = 'INSERT INTO employees (' . implode(',', $cols_sql) . ') VALUES ('
            . implode(',', array_fill(0, count($cols_sql), '?')) . ')';
    } else {
        $set_cols = array_merge(['modified_user', 'modified_date'], $cols);
        $vals = array_merge([$user, $now], array_map(fn($c) => $data[$c], $cols), [$id]);
        $types = 'ss' . implode('', array_map(fn($c) => $colTypes[$c], $cols)) . 'ii';
        $vals[] = (int) $mapped['company_id'];
        $sql = 'UPDATE employees SET ' . implode(', ', array_map(fn($c) => "$c = ?", $set_cols)) . ' WHERE id = ? AND company_id = ?';
    }

    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $new_id = $id === 0 ? $stmt->insert_id : $id;
    $stmt->close();
    return $new_id;
}
