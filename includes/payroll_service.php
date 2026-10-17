<?php
/**
 * Payroll service — gaji karyawan per periode bulanan.
 *
 * Alur: draft (generate) -> final (dikunci angkanya) -> paid (sudah dibayar).
 * Nilai gaji diambil dari master `job_grades` (basic_salary + daily_allowance)
 * lewat penghubung teks `employees.job_grade` = `job_grades.name`, lalu DISALIN
 * ke payroll_items saat generate supaya perubahan master tidak mengubah periode
 * yang sudah dibuat.
 *
 * Kontrak: sukses -> return array, error -> throw. Semua fungsi company-scoped
 * (is_super_admin() boleh lintas perusahaan).
 *
 * ponytail: tanpa tabel komponen gaji, BPJS, PPh21, atau prorata hari kerja —
 * potongan diisi manual oleh operator. Tambah tabel komponen bila rumusnya
 * mulai beda per perusahaan.
 */

/** Nama bulan Indonesia, 1-12. */
function payroll_month_name(int $m): string
{
    $names = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $names[$m] ?? ('Bulan ' . $m);
}

/** Label periode: "Oktober 2026". */
function payroll_period_label(array $p): string
{
    return payroll_month_name((int) $p['period_month']) . ' ' . (int) $p['period_year'];
}

/** Rumus gaji bersih. Satu tempat, dipakai generate & update. */
function payroll_net(float $basic, float $daily, int $days, float $deductions): float
{
    return round($basic + ($daily * $days) - $deductions, 2);
}

function payroll_status_badge(string $s): string
{
    $map = ['draft' => 'secondary', 'final' => 'info', 'paid' => 'success'];
    $label = ['draft' => 'Draft', 'final' => 'Final', 'paid' => 'Dibayar'];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . e($label[$s] ?? $s) . '</span>';
}

/** Periode + jumlah item & total gaji bersih. null bila tidak ada. */
function payroll_period_find(int $id): ?array
{
    $st = db()->prepare('SELECT p.*,
            (SELECT COUNT(*) FROM payroll_items i WHERE i.payroll_period_id = p.id) AS item_count,
            (SELECT COALESCE(SUM(i.net_salary),0) FROM payroll_items i WHERE i.payroll_period_id = p.id) AS total_net
        FROM payroll_periods p WHERE p.id = ? LIMIT 1');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Item gaji satu periode, urut nama karyawan. */
function payroll_items(int $period_id): array
{
    $st = db()->prepare("SELECT i.*, e.code AS employee_code, e.name AS employee_name, e.bank_name, e.account_number
        FROM payroll_items i JOIN employees e ON e.id = i.employee_id
        WHERE i.payroll_period_id = ? ORDER BY e.name");
    $st->bind_param('i', $period_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Karyawan aktif perusahaan (scoped). */
function payroll_active_employees(int $company_id): array
{
    $st = db()->prepare("SELECT id, name, job_grade FROM employees
        WHERE company_id = ? AND status = 'aktif' ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Gaji master per nama grade (company-scoped). */
function payroll_grade_map(int $company_id): array
{
    $st = db()->prepare('SELECT name, basic_salary, daily_allowance FROM job_grades WHERE company_id = ?');
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['name']] = $r;
    }
    return $map;
}

/**
 * Buat periode + satu item per karyawan aktif. Semua dalam satu transaksi.
 * Gaji tanpa grade terpasang dicatat 0 (operator menyesuaikan di detail);
 * jumlahnya dilaporkan di 'grade_missing'.
 *
 * @return array{period_id:int, items:int, grade_missing:array<int,string>}
 */
function payroll_generate(int $company_id, int $year, int $month, ?string $user): array
{
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Bulan harus 1-12.');
    }
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Tahun di luar rentang wajar (2000-2100).');
    }

    $db = db();
    $db->begin_transaction();
    try {
        // Cek duplikat di dalam transaksi; UNIQUE (company_id, year, month) jadi jaring terakhir.
        $st = $db->prepare('SELECT id FROM payroll_periods WHERE company_id = ? AND period_year = ? AND period_month = ?');
        $st->bind_param('iii', $company_id, $year, $month);
        $st->execute();
        $exists = $st->get_result()->fetch_row();
        $st->close();
        if ($exists) {
            throw new RuntimeException('Periode ' . payroll_month_name($month) . " $year sudah ada.");
        }

        $st = $db->prepare('INSERT INTO payroll_periods (company_id, period_year, period_month, status, created_user) VALUES (?, ?, ?, \'draft\', ?)');
        $st->bind_param('iiis', $company_id, $year, $month, $user);
        $st->execute();
        $period_id = (int) $st->insert_id;
        $st->close();

        $grades = payroll_grade_map($company_id);
        $employees = payroll_active_employees($company_id);

        $st = $db->prepare('INSERT INTO payroll_items
            (payroll_period_id, employee_id, company_id, basic_salary, daily_allowance, days_present, deductions, net_salary, created_user)
            VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)');
        $missing = [];
        foreach ($employees as $e) {
            $grade = $grades[$e['job_grade'] ?? ''] ?? null;
            $basic = (float) ($grade['basic_salary'] ?? 0);
            $daily = (float) ($grade['daily_allowance'] ?? 0);
            $net = payroll_net($basic, $daily, 0, 0.0);
            $eid = (int) $e['id'];
            $st->bind_param('iiiddds', $period_id, $eid, $company_id, $basic, $daily, $net, $user);
            $st->execute();
            if ($grade === null) {
                $missing[] = $e['name'];
            }
        }
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    return ['period_id' => $period_id, 'items' => count($employees), 'grade_missing' => $missing];
}

/** Periode milik perusahaan ini? throw bila bukan. */
function payroll_period_assert(int $period_id, int $company_id): array
{
    $period = payroll_period_find($period_id);
    if (!$period) {
        throw new RuntimeException('Periode payroll tidak ditemukan.');
    }
    if ((int) $period['company_id'] !== $company_id && !is_super_admin()) {
        throw new RuntimeException('Periode bukan milik perusahaan aktif.');
    }
    return $period;
}

/**
 * Ubah hari hadir & potongan satu item, lalu hitung ulang gaji bersih.
 * Ditolak bila periode sudah 'paid'.
 */
function payroll_item_update(int $period_id, int $company_id, int $item_id, int $days_present, float $deductions, ?string $note, ?string $user): array
{
    if ($days_present < 0 || $days_present > 31) {
        throw new InvalidArgumentException('Hari hadir harus 0-31.');
    }
    if ($deductions < 0) {
        throw new InvalidArgumentException('Potongan tidak boleh negatif.');
    }

    $db = db();
    $db->begin_transaction();
    try {
        $period = payroll_period_assert($period_id, $company_id);
        if ($period['status'] === 'paid') {
            throw new RuntimeException('Periode sudah dibayar — item tidak bisa diubah.');
        }

        $st = $db->prepare('SELECT id, basic_salary, daily_allowance FROM payroll_items WHERE id = ? AND payroll_period_id = ? AND company_id = ? FOR UPDATE');
        $st->bind_param('iii', $item_id, $period_id, $company_id);
        $st->execute();
        $item = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$item) {
            throw new RuntimeException('Item gaji tidak ditemukan pada periode ini.');
        }

        $net = payroll_net((float) $item['basic_salary'], (float) $item['daily_allowance'], $days_present, $deductions);
        $st = $db->prepare('UPDATE payroll_items SET days_present = ?, deductions = ?, net_salary = ?, note = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
        $st->bind_param('iddssi', $days_present, $deductions, $net, $note, $user, $item_id);
        $st->execute();
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    return ['item_id' => $item_id, 'days_present' => $days_present, 'deductions' => $deductions, 'net_salary' => $net];
}

/**
 * Transisi status periode: draft -> final -> paid. Tidak ada transisi mundur,
 * dan status 'paid' bersifat final.
 */
function payroll_period_set_status(int $period_id, int $company_id, string $status, ?string $user): void
{
    $allowed = ['draft' => 'final', 'final' => 'paid'];
    $db = db();
    $db->begin_transaction();
    try {
        $period = payroll_period_assert($period_id, $company_id);
        $from = (string) $period['status'];
        if (($allowed[$from] ?? null) !== $status) {
            throw new RuntimeException('Transisi status ' . $from . ' -> ' . $status . ' tidak diizinkan.');
        }
        $st = $db->prepare('UPDATE payroll_periods SET status = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?');
        $st->bind_param('ssii', $status, $user, $period_id, $company_id);
        $st->execute();
        $st->close();
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}
