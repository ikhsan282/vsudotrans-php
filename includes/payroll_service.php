<?php
/**
 * Layanan Komponen Gaji (`salary_components` & `employee_salary_components`).
 *
 * Alur:
 *   Master Komponen (`salary_components`) -> Penugasan ke Karyawan bermasa berlaku (`employee_salary_components`).
 *
 * Kontrak: sukses -> return array/int, error -> throw. Semua fungsi company-scoped
 * (is_super_admin() boleh lintas perusahaan).
 */

/** Urai input nominal uang (`3.200.000`, `3.200.000,50`, `3200000.50`, >= 0). */
function payroll_parse_money($raw): float
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

/** Validasi tanggal strict Y-m-d. */
function payroll_valid_ymd(string $raw): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return $d !== false && $d->format('Y-m-d') === $raw;
}

/** Daftar kategori komponen gaji. */
function payroll_component_categories(): array
{
    return [
        'earning'   => 'Pendapatan (+)',
        'deduction' => 'Potongan (-)',
    ];
}

/** Daftar basis perhitungan komponen gaji. */
function payroll_component_bases(): array
{
    return [
        'monthly' => 'Tetap Bulanan',
        'daily'   => 'Per Hari Hadir',
    ];
}

function payroll_component_category_badge(string $cat): string
{
    if ($cat === 'deduction') {
        return '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Potongan (-)</span>';
    }
    return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Pendapatan (+)</span>';
}

function payroll_component_basis_badge(string $basis): string
{
    if ($basis === 'daily') {
        return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Per Hari Hadir</span>';
    }
    return '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Tetap Bulanan</span>';
}

/** Hitung status masa berlaku penugasan (`active|upcoming|expired`) relatif terhadap tanggal acuan. */
function payroll_assignment_status(array $row, ?string $ref_date = null): string
{
    $ref  = $ref_date ?: date('Y-m-d');
    $from = (string) ($row['effective_from'] ?? '');
    $to   = trim((string) ($row['effective_to'] ?? ''));
    if ($from !== '' && $from > $ref) {
        return 'upcoming';
    }
    if ($to !== '' && $to < $ref) {
        return 'expired';
    }
    return 'active';
}

function payroll_assignment_status_badge(string $status): string
{
    return match ($status) {
        'upcoming' => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Akan Datang</span>',
        'expired'  => '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Berakhir</span>',
        default    => '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Aktif</span>',
    };
}

/**
 * Pastikan 2 tabel komponen gaji ada, seed 6 komponen awal per perusahaan bila kosong,
 * serta migrasikan nilai `employees.basic_salary` / `daily_allowance` lama secara aman.
 */
function payroll_ensure_salary_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = db();

    $db->query("CREATE TABLE IF NOT EXISTS `salary_components` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) DEFAULT NULL COMMENT 'NULL = berlaku semua perusahaan',
        `code` VARCHAR(20) NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `category` ENUM('earning','deduction') NOT NULL DEFAULT 'earning',
        `calc_basis` ENUM('monthly','daily') NOT NULL DEFAULT 'monthly',
        `note` VARCHAR(2000) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_user` VARCHAR(255) DEFAULT NULL,
        `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `modified_user` VARCHAR(255) DEFAULT NULL,
        `modified_date` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_salary_components_code` (`company_id`, `code`),
        KEY `ix_salary_components_company` (`company_id`),
        CONSTRAINT `fk_salary_components_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `employee_salary_components` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company_id` INT(11) NOT NULL,
        `employee_id` INT(11) NOT NULL,
        `salary_component_id` INT(11) NOT NULL,
        `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `effective_from` DATE NOT NULL,
        `effective_to` DATE DEFAULT NULL,
        `note` VARCHAR(500) DEFAULT NULL,
        `created_user` VARCHAR(255) DEFAULT NULL,
        `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `modified_user` VARCHAR(255) DEFAULT NULL,
        `modified_date` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_esc_comp_emp` (`company_id`, `employee_id`),
        KEY `ix_esc_component` (`salary_component_id`),
        KEY `ix_esc_emp_comp_dates` (`employee_id`, `salary_component_id`, `effective_from`, `effective_to`),
        CONSTRAINT `fk_esc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_esc_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_esc_component` FOREIGN KEY (`salary_component_id`) REFERENCES `salary_components` (`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaultSeeds = [
        ['GAPOK',    'Gaji Pokok',          'earning',   'monthly', 'Komponen pendapatan gaji pokok bulanan'],
        ['UH',       'Uang Harian',         'earning',   'daily',   'Komponen uang hadir harian per hari masuk'],
        ['TUNJ-JBT', 'Tunjangan Jabatan',   'earning',   'monthly', 'Tunjangan tetap berdasarkan jabatan'],
        ['TUNJ-TRN', 'Tunjangan Transport', 'earning',   'monthly', 'Tunjangan operasional & transport bulanan'],
        ['TUNJ-MKN', 'Uang Makan',          'earning',   'daily',   'Tunjangan makan per hari hadir'],
        ['POT-BPJS', 'Potongan BPJS',       'deduction', 'monthly', 'Iuran potongan BPJS bulanan'],
    ];

    $companies = $db->query('SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC')->fetch_all(MYSQLI_ASSOC);
    $stChk = $db->prepare('SELECT COUNT(*) FROM salary_components WHERE company_id = ?');
    $stIns = $db->prepare('INSERT IGNORE INTO salary_components (company_id, code, name, category, calc_basis, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    $sysUser = 'system';
    foreach ($companies as $comp) {
        $cid = (int) $comp['id'];
        $stChk->bind_param('i', $cid);
        $stChk->execute();
        $cnt = (int) $stChk->get_result()->fetch_row()[0];
        if ($cnt === 0) {
            foreach ($defaultSeeds as [$sCode, $sName, $sCat, $sBasis, $sNote]) {
                $stIns->bind_param('issssss', $cid, $sCode, $sName, $sCat, $sBasis, $sNote, $sysUser);
                $stIns->execute();
            }
        }
    }
    $stChk->close();
    $stIns->close();

    // Migrasi satu kali data lama employees.basic_salary & employees.daily_allowance (bila kolom masih ada)
    $hasEmpBasic = ($resB = $db->query("SHOW COLUMNS FROM `employees` LIKE 'basic_salary'")) && $resB->num_rows > 0;
    $hasEmpDaily = ($resD = $db->query("SHOW COLUMNS FROM `employees` LIKE 'daily_allowance'")) && $resD->num_rows > 0;
    if ($hasEmpBasic && $hasEmpDaily) {
        $oldRows = $db->query('SELECT id, company_id, join_date, basic_salary, daily_allowance FROM employees WHERE basic_salary > 0 OR daily_allowance > 0')->fetch_all(MYSQLI_ASSOC);
        if ($oldRows) {
            $stEmpCnt  = $db->prepare('SELECT COUNT(*) FROM employee_salary_components WHERE employee_id = ?');
            $stFindSc  = $db->prepare('SELECT id FROM salary_components WHERE (company_id = ? OR company_id IS NULL) AND code = ? ORDER BY company_id IS NULL ASC, id ASC LIMIT 1');
            $stMakeSc  = $db->prepare('INSERT IGNORE INTO salary_components (company_id, code, name, category, calc_basis, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
            $stEscIns  = $db->prepare('INSERT INTO employee_salary_components (company_id, employee_id, salary_component_id, amount, effective_from, effective_to, note, created_user) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
            $migNote   = 'Migrasi otomatis dari data gaji awal';

            $resolveCompId = static function (int $cid, string $code, string $name, string $cat, string $basis) use ($stFindSc, $stMakeSc, $db, $sysUser): int {
                $stFindSc->bind_param('is', $cid, $code);
                $stFindSc->execute();
                $found = $stFindSc->get_result()->fetch_row();
                if ($found) {
                    return (int) $found[0];
                }
                $note = $name . ' (auto-migrasi)';
                $stMakeSc->bind_param('issssss', $cid, $code, $name, $cat, $basis, $note, $sysUser);
                $stMakeSc->execute();
                $insId = (int) $db->insert_id;
                if ($insId > 0) {
                    return $insId;
                }
                $stFindSc->bind_param('is', $cid, $code);
                $stFindSc->execute();
                $found2 = $stFindSc->get_result()->fetch_row();
                return $found2 ? (int) $found2[0] : 0;
            };

            foreach ($oldRows as $er) {
                $eid = (int) $er['id'];
                $ecid = (int) $er['company_id'];
                $stEmpCnt->bind_param('i', $eid);
                $stEmpCnt->execute();
                $existingCnt = (int) $stEmpCnt->get_result()->fetch_row()[0];
                if ($existingCnt > 0) {
                    continue;
                }
                $effFrom = (!empty($er['join_date']) && $er['join_date'] !== '0000-00-00') ? (string) $er['join_date'] : date('Y-m-d');
                $basicAmt = (float) $er['basic_salary'];
                $dailyAmt = (float) $er['daily_allowance'];
                if ($basicAmt > 0) {
                    $gapokId = $resolveCompId($ecid, 'GAPOK', 'Gaji Pokok', 'earning', 'monthly');
                    if ($gapokId > 0) {
                        $stEscIns->bind_param('iiidsss', $ecid, $eid, $gapokId, $basicAmt, $effFrom, $migNote, $sysUser);
                        $stEscIns->execute();
                    }
                }
                if ($dailyAmt > 0) {
                    $uhId = $resolveCompId($ecid, 'UH', 'Uang Harian', 'earning', 'daily');
                    if ($uhId > 0) {
                        $stEscIns->bind_param('iiidsss', $ecid, $eid, $uhId, $dailyAmt, $effFrom, $migNote, $sysUser);
                        $stEscIns->execute();
                    }
                }
            }
            $stEmpCnt->close();
            $stFindSc->close();
            $stMakeSc->close();
            $stEscIns->close();
        }
    }
}

/** Opsi komponen gaji milik perusahaan + global. */
function payroll_component_options(?int $company_id, bool $only_active = true): array
{
    payroll_ensure_salary_schema();
    $db = db();
    $where = [];
    $types = '';
    $params = [];
    if ($company_id !== null && $company_id > 0) {
        $where[] = '(sc.company_id = ? OR sc.company_id IS NULL)';
        $types  .= 'i';
        $params[] = $company_id;
    }
    if ($only_active) {
        $where[] = 'sc.is_active = 1';
    }
    $sql = 'SELECT sc.*, co.code AS company_code, co.name AS company_name
            FROM salary_components sc
            LEFT JOIN companies co ON co.id = sc.company_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY sc.category ASC, sc.calc_basis ASC, sc.code ASC, sc.name ASC';
    $st = $db->prepare($sql);
    if ($params) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Cari satu komponen gaji berdasarkan id. */
function payroll_component_find(int $id): ?array
{
    payroll_ensure_salary_schema();
    $st = db()->prepare('SELECT sc.*, co.code AS company_code, co.name AS company_name
        FROM salary_components sc
        LEFT JOIN companies co ON co.id = sc.company_id
        WHERE sc.id = ? LIMIT 1');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Daftar komponen gaji berfilter lengkap dengan jumlah penugasan aktif. */
function payroll_component_list(?int $company_id, string $q = '', string $category = '', string $basis = '', ?int $is_active = null): array
{
    payroll_ensure_salary_schema();
    $db = db();
    $where = [];
    $types = '';
    $params = [];
    $today = date('Y-m-d');

    if ($company_id !== null && $company_id > 0) {
        $where[] = '(sc.company_id = ? OR sc.company_id IS NULL)';
        $types  .= 'i';
        $params[] = $company_id;
    }
    if ($q !== '') {
        $where[] = '(sc.code LIKE ? OR sc.name LIKE ?)';
        $types  .= 'ss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($category, ['earning', 'deduction'], true)) {
        $where[] = 'sc.category = ?';
        $types  .= 's';
        $params[] = $category;
    }
    if (in_array($basis, ['monthly', 'daily'], true)) {
        $where[] = 'sc.calc_basis = ?';
        $types  .= 's';
        $params[] = $basis;
    }
    if ($is_active !== null) {
        $where[] = 'sc.is_active = ?';
        $types  .= 'i';
        $params[] = $is_active ? 1 : 0;
    }

    $activeCountSub = ($company_id !== null && $company_id > 0)
        ? '(SELECT COUNT(*) FROM employee_salary_components esc WHERE esc.salary_component_id = sc.id AND esc.company_id = ' . (int) $company_id . ' AND esc.effective_from <= \'' . $today . '\' AND (esc.effective_to IS NULL OR esc.effective_to >= \'' . $today . '\'))'
        : '(SELECT COUNT(*) FROM employee_salary_components esc WHERE esc.salary_component_id = sc.id AND esc.effective_from <= \'' . $today . '\' AND (esc.effective_to IS NULL OR esc.effective_to >= \'' . $today . '\'))';

    $totalUseSub = '(SELECT COUNT(*) FROM employee_salary_components esc2 WHERE esc2.salary_component_id = sc.id)';

    $sql = "SELECT sc.*, co.code AS company_code, co.name AS company_name,
                {$activeCountSub} AS active_assignments,
                {$totalUseSub} AS total_assignments
            FROM salary_components sc
            LEFT JOIN companies co ON co.id = sc.company_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY sc.company_id IS NULL DESC, co.code ASC, sc.category ASC, sc.calc_basis ASC, sc.code ASC';

    $st = $db->prepare($sql);
    if ($params) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Simpan master komponen gaji (insert/update). Validasi tenant & keunikan kode. */
function payroll_component_save(int $id, array $in, ?int $company_id, ?string $user): int
{
    payroll_ensure_salary_schema();
    $db = db();

    $code     = strtoupper(trim((string) ($in['code'] ?? '')));
    $name     = trim((string) ($in['name'] ?? ''));
    $category = trim((string) ($in['category'] ?? 'earning'));
    $basis    = trim((string) ($in['calc_basis'] ?? 'monthly'));
    $note     = trim((string) ($in['note'] ?? ''));
    $isActive = isset($in['is_active']) ? ((int) $in['is_active'] ? 1 : 0) : 1;

    if ($code === '') {
        throw new InvalidArgumentException('Kode komponen gaji wajib diisi.');
    }
    if (strlen($code) > 20) {
        throw new InvalidArgumentException('Kode komponen gaji maksimal 20 karakter.');
    }
    if ($name === '') {
        throw new InvalidArgumentException('Nama komponen gaji wajib diisi.');
    }
    if (!in_array($category, ['earning', 'deduction'], true)) {
        throw new InvalidArgumentException('Kategori komponen tidak valid.');
    }
    if (!in_array($basis, ['monthly', 'daily'], true)) {
        throw new InvalidArgumentException('Basis perhitungan tidak valid.');
    }

    $targetCid = ($company_id !== null && $company_id > 0) ? $company_id : null;
    if ($targetCid === null && !is_super_admin()) {
        throw new RuntimeException('Hanya Super Admin yang dapat membuat atau mengubah komponen global.');
    }
    if ($targetCid !== null) {
        assert_company_access($targetCid);
    }

    if ($id > 0) {
        $existing = payroll_component_find($id);
        if (!$existing) {
            throw new RuntimeException('Komponen gaji tidak ditemukan.');
        }
        if ($existing['company_id'] !== null) {
            assert_company_access((int) $existing['company_id']);
        } elseif (!is_super_admin()) {
            throw new RuntimeException('Hanya Super Admin yang dapat mengubah komponen global.');
        }
        $targetCid = $existing['company_id'] !== null ? (int) $existing['company_id'] : null;
    }

    if ($targetCid === null) {
        $stU = $db->prepare('SELECT id FROM salary_components WHERE company_id IS NULL AND code = ? AND id <> ? LIMIT 1');
        $stU->bind_param('si', $code, $id);
    } else {
        $stU = $db->prepare('SELECT id FROM salary_components WHERE company_id = ? AND code = ? AND id <> ? LIMIT 1');
        $stU->bind_param('isi', $targetCid, $code, $id);
    }
    $stU->execute();
    $dup = $stU->get_result()->fetch_row();
    $stU->close();
    if ($dup) {
        throw new InvalidArgumentException("Kode komponen '{$code}' sudah digunakan.");
    }

    $noteVal = $note !== '' ? $note : null;
    if ($id === 0) {
        $st = $db->prepare('INSERT INTO salary_components (company_id, code, name, category, calc_basis, note, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('isssssis', $targetCid, $code, $name, $category, $basis, $noteVal, $isActive, $user);
        $st->execute();
        $newId = (int) $st->insert_id;
        $st->close();
        return $newId;
    }

    $st = $db->prepare('UPDATE salary_components SET code = ?, name = ?, category = ?, calc_basis = ?, note = ?, is_active = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
    $st->bind_param('sssssisi', $code, $name, $category, $basis, $noteVal, $isActive, $user, $id);
    $st->execute();
    $st->close();
    return $id;
}

/** Hapus komponen gaji jika tidak lagi dipakai oleh penugasan karyawan. */
function payroll_component_delete(int $id): void
{
    payroll_ensure_salary_schema();
    $row = payroll_component_find($id);
    if (!$row) {
        throw new RuntimeException('Komponen gaji tidak ditemukan.');
    }
    if ($row['company_id'] !== null) {
        assert_company_access((int) $row['company_id']);
    } elseif (!is_super_admin()) {
        throw new RuntimeException('Hanya Super Admin yang dapat menghapus komponen global.');
    }

    $db = db();
    $stCnt = $db->prepare('SELECT COUNT(*) FROM employee_salary_components WHERE salary_component_id = ?');
    $stCnt->bind_param('i', $id);
    $stCnt->execute();
    $cnt = (int) $stCnt->get_result()->fetch_row()[0];
    $stCnt->close();
    if ($cnt > 0) {
        throw new RuntimeException("Komponen '{$row['name']}' tidak dapat dihapus karena masih digunakan pada {$cnt} penugasan gaji karyawan.");
    }

    $stDel = $db->prepare('DELETE FROM salary_components WHERE id = ?');
    $stDel->bind_param('i', $id);
    $stDel->execute();
    $stDel->close();
}

/** Cari satu baris penugasan komponen gaji karyawan. */
function payroll_assignment_find(int $id): ?array
{
    payroll_ensure_salary_schema();
    $st = db()->prepare('SELECT esc.*, sc.code AS component_code, sc.name AS component_name, sc.category, sc.calc_basis,
            e.code AS employee_code, e.name AS employee_name
        FROM employee_salary_components esc
        JOIN salary_components sc ON sc.id = esc.salary_component_id
        JOIN employees e ON e.id = esc.employee_id
        WHERE esc.id = ? LIMIT 1');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Daftar penugasan komponen gaji milik satu karyawan (opsional filter status: active|upcoming|expired). */
function payroll_employee_assignments(int $employee_id, int $company_id, string $status_filter = ''): array
{
    payroll_ensure_salary_schema();
    $st = db()->prepare('SELECT esc.*, sc.code AS component_code, sc.name AS component_name, sc.category, sc.calc_basis, sc.is_active AS component_active
        FROM employee_salary_components esc
        JOIN salary_components sc ON sc.id = esc.salary_component_id
        WHERE esc.employee_id = ? AND esc.company_id = ?
        ORDER BY sc.category ASC, sc.calc_basis ASC, sc.name ASC, esc.effective_from DESC, esc.id DESC');
    $st->bind_param('ii', $employee_id, $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $stCode = payroll_assignment_status($r, $today);
        $r['eff_status'] = $stCode;
        if ($status_filter !== '' && $stCode !== $status_filter) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/** Daftar penugasan komponen gaji lintas karyawan perusahaan (untuk halaman manajemen terpusat). */
function payroll_company_assignments(?int $company_id, int $employee_id = 0, int $component_id = 0, string $status_filter = ''): array
{
    payroll_ensure_salary_schema();
    $db = db();
    $where = [];
    $types = '';
    $params = [];

    if ($company_id !== null && $company_id > 0) {
        $where[] = 'esc.company_id = ?';
        $types  .= 'i';
        $params[] = $company_id;
    }
    if ($employee_id > 0) {
        $where[] = 'esc.employee_id = ?';
        $types  .= 'i';
        $params[] = $employee_id;
    }
    if ($component_id > 0) {
        $where[] = 'esc.salary_component_id = ?';
        $types  .= 'i';
        $params[] = $component_id;
    }

    $sql = 'SELECT esc.*,
                sc.code AS component_code, sc.name AS component_name, sc.category, sc.calc_basis, sc.is_active AS component_active,
                e.code AS employee_code, e.name AS employee_name, e.job_title, e.status AS employee_status,
                co.code AS company_code, co.name AS company_name
            FROM employee_salary_components esc
            JOIN salary_components sc ON sc.id = esc.salary_component_id
            JOIN employees e ON e.id = esc.employee_id
            LEFT JOIN companies co ON co.id = esc.company_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY e.name ASC, sc.category ASC, sc.name ASC, esc.effective_from DESC, esc.id DESC';

    $st = $db->prepare($sql);
    if ($params) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $stCode = payroll_assignment_status($r, $today);
        $r['eff_status'] = $stCode;
        if ($status_filter !== '' && $stCode !== $status_filter) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * Ambil komponen efektif karyawan yang tumpang tindih dengan rentang tanggal [$period_start, $period_end].
 * Bila ada lebih dari satu penugasan komponen yang sama di rentang tersebut, ambil yang effective_from terbaru.
 *
 * @return array{components: list<array<string,mixed>>, monthly_earning: float, daily_earning: float, monthly_deduction: float, daily_deduction: float}
 */
function payroll_employee_effective_components(int $employee_id, int $company_id, string $period_start, string $period_end): array
{
    payroll_ensure_salary_schema();
    $st = db()->prepare('SELECT esc.id, esc.salary_component_id, esc.amount, esc.effective_from, esc.effective_to,
            sc.code AS component_code, sc.name AS component_name, sc.category, sc.calc_basis
        FROM employee_salary_components esc
        JOIN salary_components sc ON sc.id = esc.salary_component_id
        WHERE esc.employee_id = ? AND esc.company_id = ? AND sc.is_active = 1
          AND esc.effective_from <= ?
          AND (esc.effective_to IS NULL OR esc.effective_to >= ?)
        ORDER BY esc.effective_from DESC, esc.id DESC');
    $st->bind_param('iiss', $employee_id, $company_id, $period_end, $period_start);
    $st->execute();
    $raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $seen = [];
    $components = [];
    $monthlyEarning   = 0.0;
    $dailyEarning     = 0.0;
    $monthlyDeduction = 0.0;
    $dailyDeduction   = 0.0;

    foreach ($raw as $r) {
        $cidKey = (int) $r['salary_component_id'];
        if (isset($seen[$cidKey])) {
            continue;
        }
        $seen[$cidKey] = true;
        $amt   = (float) $r['amount'];
        $cat   = (string) $r['category'];
        $basis = (string) $r['calc_basis'];

        if ($cat === 'deduction') {
            if ($basis === 'daily') {
                $dailyDeduction += $amt;
            } else {
                $monthlyDeduction += $amt;
            }
        } else {
            if ($basis === 'daily') {
                $dailyEarning += $amt;
            } else {
                $monthlyEarning += $amt;
            }
        }
        $components[] = $r;
    }

    return [
        'components'        => $components,
        'monthly_earning'   => round($monthlyEarning, 2),
        'daily_earning'     => round($dailyEarning, 2),
        'monthly_deduction' => round($monthlyDeduction, 2),
        'daily_deduction'   => round($dailyDeduction, 2),
    ];
}

/**
 * Simpan / assign komponen gaji ke karyawan dengan masa berlaku dan nominal.
 * Jika `$auto_close_prev = true`, penugasan sebelumnya yang masih terbuka (effective_to NULL & effective_from < baru)
 * otomatis ditutup menjadi H-1 sebelum penugasan baru disimpan.
 */
function payroll_assignment_save(
    int $id,
    int $company_id,
    int $employee_id,
    int $component_id,
    float $amount,
    string $effective_from,
    ?string $effective_to,
    ?string $note,
    ?string $user,
    bool $auto_close_prev = false
): int {
    payroll_ensure_salary_schema();
    if ($amount < 0) {
        throw new InvalidArgumentException('Nominal komponen gaji tidak boleh negatif.');
    }
    $effective_from = trim($effective_from);
    if (!payroll_valid_ymd($effective_from)) {
        throw new InvalidArgumentException('Tanggal mulai berlaku wajib diisi dengan format YYYY-MM-DD.');
    }
    $effective_to = $effective_to !== null ? trim($effective_to) : null;
    if ($effective_to === '') {
        $effective_to = null;
    }
    if ($effective_to !== null) {
        if (!payroll_valid_ymd($effective_to)) {
            throw new InvalidArgumentException('Tanggal berakhir berlaku tidak valid (YYYY-MM-DD).');
        }
        if ($effective_to < $effective_from) {
            throw new InvalidArgumentException('Tanggal berakhir tidak boleh lebih awal dari tanggal mulai berlaku.');
        }
    }

    $db = db();
    // Validasi tenant employee & akses komponen
    $stEmp = $db->prepare('SELECT id, company_id FROM employees WHERE id = ? LIMIT 1');
    $stEmp->bind_param('i', $employee_id);
    $stEmp->execute();
    $empRow = $stEmp->get_result()->fetch_assoc();
    $stEmp->close();
    if (!$empRow || (int) $empRow['company_id'] !== $company_id) {
        throw new RuntimeException('Karyawan tidak ditemukan pada perusahaan ini.');
    }

    $stSc = $db->prepare('SELECT id, company_id, name FROM salary_components WHERE id = ? LIMIT 1');
    $stSc->bind_param('i', $component_id);
    $stSc->execute();
    $scRow = $stSc->get_result()->fetch_assoc();
    $stSc->close();
    if (!$scRow || ($scRow['company_id'] !== null && (int) $scRow['company_id'] !== $company_id)) {
        throw new RuntimeException('Komponen gaji tidak tersedia untuk perusahaan ini.');
    }

    $noteVal = ($note !== null && trim($note) !== '') ? mb_substr(trim($note), 0, 500) : null;

    $db->begin_transaction();
    try {
        if ($id > 0) {
            $stChk = $db->prepare('SELECT id, company_id, employee_id FROM employee_salary_components WHERE id = ? FOR UPDATE');
            $stChk->bind_param('i', $id);
            $stChk->execute();
            $curRow = $stChk->get_result()->fetch_assoc();
            $stChk->close();
            if (!$curRow || (int) $curRow['company_id'] !== $company_id) {
                throw new RuntimeException('Penugasan komponen tidak ditemukan pada perusahaan ini.');
            }
        }

        if ($auto_close_prev) {
            $closeDate = date('Y-m-d', strtotime($effective_from . ' -1 day'));
            $stPrev = $db->prepare('SELECT id FROM employee_salary_components WHERE employee_id = ? AND salary_component_id = ? AND id <> ? AND effective_to IS NULL AND effective_from < ? FOR UPDATE');
            $stPrev->bind_param('iiis', $employee_id, $component_id, $id, $effective_from);
            $stPrev->execute();
            $prevIds = array_column($stPrev->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
            $stPrev->close();

            if ($prevIds) {
                $stUpdPrev = $db->prepare('UPDATE employee_salary_components SET effective_to = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                foreach ($prevIds as $pidPrev) {
                    $pidInt = (int) $pidPrev;
                    $stUpdPrev->bind_param('ssi', $closeDate, $user, $pidInt);
                    $stUpdPrev->execute();
                }
                $stUpdPrev->close();
            }
        }

        // Cek bentrok rentang tanggal pada (employee_id, salary_component_id)
        $stOv = $db->prepare('SELECT id, effective_from, effective_to
            FROM employee_salary_components
            WHERE employee_id = ? AND salary_component_id = ? AND id <> ?
              AND (effective_to IS NULL OR effective_to >= ?)
              AND (? IS NULL OR effective_from <= ?)
            LIMIT 1');
        $stOv->bind_param('iiisss', $employee_id, $component_id, $id, $effective_from, $effective_to, $effective_to);
        $stOv->execute();
        $ov = $stOv->get_result()->fetch_assoc();
        $stOv->close();
        if ($ov) {
            $ovToLbl = $ov['effective_to'] ? (string) $ov['effective_to'] : 'tanpa batas';
            throw new RuntimeException("Masa berlaku bentrok dengan penugasan #{$ov['id']} ({$ov['effective_from']} s/d {$ovToLbl}). Aktifkan penutupan periode sebelumnya atau ubah tanggal.");
        }

        if ($id === 0) {
            $stIns = $db->prepare('INSERT INTO employee_salary_components (company_id, employee_id, salary_component_id, amount, effective_from, effective_to, note, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stIns->bind_param('iiidssss', $company_id, $employee_id, $component_id, $amount, $effective_from, $effective_to, $noteVal, $user);
            $stIns->execute();
            $resId = (int) $stIns->insert_id;
            $stIns->close();
        } else {
            $stUpd = $db->prepare('UPDATE employee_salary_components SET employee_id = ?, salary_component_id = ?, amount = ?, effective_from = ?, effective_to = ?, note = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
            $stUpd->bind_param('iidssssii', $employee_id, $component_id, $amount, $effective_from, $effective_to, $noteVal, $user, $id, $company_id);
            $stUpd->execute();
            $stUpd->close();
            $resId = $id;
        }

        $db->commit();
        return $resId;
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/** Hapus satu baris penugasan komponen gaji karyawan (terjaga company_id). */
function payroll_assignment_delete(int $id, int $company_id): void
{
    payroll_ensure_salary_schema();
    $st = db()->prepare('DELETE FROM employee_salary_components WHERE id = ? AND company_id = ?');
    $st->bind_param('ii', $id, $company_id);
    $st->execute();
    $aff = $st->affected_rows;
    $st->close();
    if ($aff === 0) {
        throw new RuntimeException('Penugasan komponen gaji tidak ditemukan atau bukan milik perusahaan ini.');
    }
}
