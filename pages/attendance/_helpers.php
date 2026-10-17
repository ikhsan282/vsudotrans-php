<?php
/**
 * Absensi (kehadiran harian karyawan) — helper data layer ber-tenant.
 *
 * Contract keamanan:
 *  - list & rekap selalu discope lewat company_scope('a.company_id');
 *  - setiap baca/ubah satu baris WAJIB assert_company_access($row['company_id']);
 *  - company_id saat simpan berasal dari perusahaan aktif, tidak pernah dari POST;
 *  - karyawan divalidasi milik perusahaan aktif (attendance_employee_owner);
 *  - semua query memakai prepared statement.
 */

/** Pastikan ada perusahaan aktif; kembalikan id-nya. */
function attendance_scope_cid(): int
{
    $cid = active_company_id();
    if ($cid === null) {
        flash('error', 'Pilih perusahaan aktif terlebih dahulu.');
        redirect('pages/dashboard/index.php');
    }
    return $cid;
}

/** Status absensi yang sah. */
function attendance_statuses(): array
{
    return ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'cuti' => 'Cuti', 'alpa' => 'Alpa'];
}

function attendance_status_label(string $s): string
{
    return attendance_statuses()[$s] ?? $s;
}

function attendance_status_badge(string $s): string
{
    $map = ['hadir' => 'success', 'izin' => 'info', 'sakit' => 'warning', 'cuti' => 'primary', 'alpa' => 'danger'];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . e(attendance_status_label($s)) . '</span>';
}

/** Urai tanggal ISO (Y-m-d). '' = kosong; false = tidak valid. */
function attendance_parse_date(array $src, string $key)
{
    $raw = trim((string) ($src[$key] ?? ''));
    if ($raw === '') {
        return '';
    }
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : false;
}

/** Urai jam HH:MM (juga terima HH:MM:SS dari DB). '' = kosong; false = tidak valid. */
function attendance_parse_time(array $src, string $key)
{
    $raw = trim((string) ($src[$key] ?? ''));
    if ($raw === '') {
        return '';
    }
    foreach (['H:i', 'H:i:s'] as $fmt) {
        $t = DateTime::createFromFormat($fmt, $raw);
        if ($t && $t->format($fmt) === $raw) {
            return $t->format('H:i');
        }
    }
    return false;
}

function attendance_date_valid(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return (bool) ($x && $x->format('Y-m-d') === $d);
}

/** Rentang filter: default bulan berjalan. Kembalikan [from, to] valid. */
function attendance_range(array $src): array
{
    $from = attendance_parse_date($src, 'from');
    $to   = attendance_parse_date($src, 'to');
    if ($from === '' || $from === false) {
        $from = date('Y-m-01');
    }
    if ($to === '' || $to === false) {
        $to = date('Y-m-t');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/** Karyawan milik perusahaan aktif (non-Super Admin). Super Admin: semua bila $cid null. */
function attendance_employee_options(?int $cid): array
{
    $sql = 'SELECT id, code, name FROM employees WHERE status = \'aktif\'';
    $types = '';
    $params = [];
    if ($cid !== null) {
        $sql .= ' AND company_id = ?';
        $types = 'i';
        $params = [$cid];
    }
    $sql .= ' ORDER BY code';
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Peta id karyawan -> "kode - nama" untuk tabel rekap (scoped). */
function attendance_employee_map(?int $cid): array
{
    $map = [];
    foreach (attendance_employee_options($cid) as $e) {
        $map[(int) $e['id']] = $e['code'] . ' - ' . $e['name'];
    }
    return $map;
}

/**
 * Karyawan harus ada dan milik perusahaan target. Super Admin pun dibatasi ke
 * perusahaan yang sedang aktif — tidak ada tautan lintas perusahaan.
 */
function attendance_employee_owner(int $employee_id, int $company_id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM employees WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $employee_id, $company_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

/** Cari satu absensi, discope tenant. */
function attendance_find(int $id): ?array
{
    [$wsql, $wtypes, $wparams] = company_scope('a.company_id');
    $stmt = db()->prepare("SELECT a.*, e.code AS employee_code, e.name AS employee_name
        FROM employee_attendances a JOIN employees e ON e.id = a.employee_id
        WHERE a.id = ? AND {$wsql} LIMIT 1");
    $stmt->bind_param('i' . $wtypes, $id, ...$wparams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Daftar absensi (rentang tanggal + karyawan + status + pagination), discope tenant.
 *
 * @return array{0: list<array<string,mixed>>, 1: array}
 */
function attendance_list(string $from, string $to, int $employee_id, string $status, int $page): array
{
    [$wsql, $wtypes, $wparams] = company_scope('a.company_id');
    $where = [$wsql, 'a.date BETWEEN ? AND ?'];
    $types = $wtypes . 'ss';
    $params = array_merge($wparams, [$from, $to]);

    if ($employee_id > 0) {
        $where[] = 'a.employee_id = ?';
        $types .= 'i';
        $params[] = $employee_id;
    }
    if (isset(attendance_statuses()[$status])) {
        $where[] = 'a.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $where_sql = implode(' AND ', $where);

    $count_stmt = db()->prepare('SELECT COUNT(*) FROM employee_attendances a WHERE ' . $where_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = (int) $count_stmt->get_result()->fetch_row()[0];
    $count_stmt->close();

    $p = paginate($total, $page);
    $stmt = db()->prepare('SELECT a.*, e.code AS employee_code, e.name AS employee_name
        FROM employee_attendances a JOIN employees e ON e.id = a.employee_id
        WHERE ' . $where_sql . '
        ORDER BY a.date DESC, e.code ASC
        LIMIT ? OFFSET ?');
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$p['per_page'], $p['offset']]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [$rows, $p];
}

/**
 * Rekap per karyawan pada rentang tanggal: jumlah tiap status + total hari tercatat.
 * Dihitung on-the-fly (tanpa tabel ringkasan); karyawan tanpa absensi tampil 0.
 */
function attendance_summary(string $from, string $to, ?int $cid): array
{
    [$wsql, $wtypes, $wparams] = company_scope('a.company_id');
    // Urutan placeholder WAJIB sama dengan urutan string tipe: rentang tanggal (JOIN) dulu,
    // baru scope tenant, terakhir filter perusahaan karyawan.
    $types = 'ss' . $wtypes;
    $params = array_merge([$from, $to], $wparams);
    $sql = "SELECT e.id, e.code, e.name,
            SUM(a.status = 'hadir') AS hadir,
            SUM(a.status = 'izin')  AS izin,
            SUM(a.status = 'sakit') AS sakit,
            SUM(a.status = 'cuti')  AS cuti,
            SUM(a.status = 'alpa')  AS alpa,
            COUNT(a.id)             AS total
        FROM employees e
        LEFT JOIN employee_attendances a
               ON a.employee_id = e.id AND a.date BETWEEN ? AND ? AND {$wsql}
        WHERE e.status = 'aktif'";
    if ($cid !== null) {
        $sql .= ' AND e.company_id = ?';
        $types .= 'i';
        $params[] = $cid;
    }
    $sql .= ' GROUP BY e.id, e.code, e.name ORDER BY e.code';
    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Petakan POST -> data absensi. Kembalikan [data, errors].
 * company_id tidak pernah dari POST.
 */
function attendance_map_input(array $post, int $company_id): array
{
    $errors = [];
    $employee_id = (int) ($post['employee_id'] ?? 0);
    $date = attendance_parse_date($post, 'date');
    $status = (string) ($post['status'] ?? '');
    $in = attendance_parse_time($post, 'check_in');
    $out = attendance_parse_time($post, 'check_out');
    $note = trim((string) ($post['note'] ?? ''));

    if (!attendance_employee_owner($employee_id, $company_id)) {
        $errors[] = 'Karyawan harus dipilih dari daftar milik perusahaan aktif.';
    }
    if ($date === false) {
        $errors[] = 'Tanggal tidak valid.';
    } elseif ($date === '') {
        $errors[] = 'Tanggal wajib diisi.';
    }
    if (!isset(attendance_statuses()[$status])) {
        $errors[] = 'Status absensi tidak valid.';
    }
    if ($in === false) {
        $errors[] = 'Jam masuk tidak valid (format HH:MM).';
    }
    if ($out === false) {
        $errors[] = 'Jam keluar tidak valid (format HH:MM).';
    }
    if (is_string($in) && is_string($out) && $in !== '' && $out !== '' && $out < $in) {
        $errors[] = 'Jam keluar tidak boleh lebih awal dari jam masuk (shift lintas hari belum didukung).';
    }
    if (strlen($note) > 1000) {
        $errors[] = 'Catatan maksimal 1000 karakter.';
    }

    return [[
        'employee_id' => $employee_id,
        'date'        => is_string($date) ? $date : '',
        'status'      => $status,
        'check_in'    => $in === false ? '' : $in,
        'check_out'   => $out === false ? '' : $out,
        'note'        => $note === '' ? null : $note,
    ], $errors];
}

/**
 * Simpan absensi. Form hanya membuat (tanpa id): UNIQUE (employee_id, date)
 * dipakai sebagai kunci — tanggal yang sama diinput lagi akan DIPERBARUI,
 * bukan melempar error ke user.
 */
function attendance_save(array $data, int $company_id, string $user): int
{
    $ci = $data['check_in'] === '' ? null : $data['check_in'];
    $co = $data['check_out'] === '' ? null : $data['check_out'];
    $stmt = db()->prepare('INSERT INTO employee_attendances
        (company_id, employee_id, date, status, check_in, check_out, note, created_user, created_date, modified_user, modified_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
        ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id), status = VALUES(status), check_in = VALUES(check_in),
            check_out = VALUES(check_out), note = VALUES(note),
            modified_user = VALUES(modified_user), modified_date = NOW()');
    $stmt->bind_param('iisssssss', $company_id, $data['employee_id'], $data['date'], $data['status'],
        $ci, $co, $data['note'], $user, $user);
    $stmt->execute();
    $id = (int) ($stmt->insert_id ?: 0);
    $stmt->close();

    if ($id === 0) { // sudah ada baris -> ambil id-nya untuk laporan
        $stmt = db()->prepare('SELECT id FROM employee_attendances WHERE employee_id = ? AND date = ?');
        $stmt->bind_param('is', $data['employee_id'], $data['date']);
        $stmt->execute();
        $id = (int) $stmt->get_result()->fetch_column();
        $stmt->close();
    }
    return $id;
}
