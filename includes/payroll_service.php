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

/**
 * Hari yang dihitung sebagai "hadir" per status absensi (kebijakan satu tempat).
 * Ubah di sini bila aturan perusahaan berubah; status yang tidak terdaftar = 0,
 * sama seperti hari tanpa catatan absensi.
 */
const PAYROLL_ATTENDANCE_DAYS = ['hadir' => 1, 'izin' => 1, 'sakit' => 1, 'cuti' => 1, 'alpa' => 0];

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
    $st = db()->prepare("SELECT i.*, e.code AS employee_code, e.name AS employee_name, e.bank_name, e.account_number,
            e.job_position
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
 * Tarik hari hadir tiap item payroll periode $period_id dari tabel
 * employee_attendances, untuk bulan periode. Skema pemetaan status via
 * PAYROLL_ATTENDANCE_DAYS; hari tanpa catatan = 0. Net dihitung ulang
 * via payroll_net(); deductions & note item dibiarkan apa adanya.
 *
 * @return array{updated:int} jumlah item yang days_present-nya berubah
 */
function payroll_pull_attendance(int $period_id, int $company_id, ?string $user): array
{
    $db = db();
    $db->begin_transaction();
    try {
        $period = payroll_period_assert($period_id, $company_id);
        $year  = (int) $period['period_year'];
        $month = (int) $period['period_month'];
        if ($period['status'] !== 'draft') {
            throw new RuntimeException('Periode tidak berstatus draft — angka sudah dikunci.');
        }
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-t', strtotime($from . ' last day of this month'));

        $st = $db->prepare('SELECT id, employee_id, basic_salary, daily_allowance, days_present, deductions, net_salary
            FROM payroll_items WHERE payroll_period_id = ? AND company_id = ? FOR UPDATE');
        $st->bind_param('ii', $period_id, $company_id);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        // Hari terhitung per karyawan, dari absensi bulan periode. Hari tanpa
        // catatan absensi tidak pernah muncul di sini — nilainya tetap 0.
        $days = [];
        if ($rows) {
            $ids = array_map(static fn(array $r) => (int) $r['employee_id'], $rows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT employee_id, status FROM employee_attendances
                WHERE company_id = ? AND date BETWEEN ? AND ? AND employee_id IN ($in)");
            $st->bind_param('iss' . str_repeat('i', count($ids)), ...array_merge([$company_id, $from, $to], $ids));
            $st->execute();
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
                $eid = (int) $a['employee_id'];
                $days[$eid] = ($days[$eid] ?? 0) + (PAYROLL_ATTENDANCE_DAYS[$a['status']] ?? 0);
            }
            $st->close();
        }

        $st = $db->prepare('UPDATE payroll_items
            SET days_present = ?, net_salary = ?, modified_user = ?, modified_date = NOW()
            WHERE id = ?');
        $updated = 0;
        foreach ($rows as $r) {
            $d = $days[(int) $r['employee_id']] ?? 0;
            $net = payroll_net((float) $r['basic_salary'], (float) $r['daily_allowance'], $d, (float) $r['deductions']);
            $changed = (int) $r['days_present'] !== $d || abs((float) $r['net_salary'] - $net) >= 0.005;
            if (!$changed) {
                continue; // idempoten: angka sudah sesuai, tidak perlu ditulis
            }
            $st->bind_param('idsi', $d, $net, $user, $r['id']);
            $st->execute();
            $updated++;
        }
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    return ['updated' => $updated];
}

/**
 * Transisi status periode: draft -> final -> paid. Tidak ada transisi mundur,
 * dan status 'paid' bersifat final.
 */
function payroll_period_set_status(int $period_id, int $company_id, string $status, ?string $user, ?int $cash_account_id = null): void
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

        // Jika status diubah menjadi 'paid' dan akun kas/bank dipilih, catat pengeluaran kas otomatis
        if ($status === 'paid' && $cash_account_id !== null && $cash_account_id > 0) {
            $c_st = $db->prepare('SELECT id FROM cash_accounts WHERE id = ? AND company_id = ? AND is_active = 1');
            $c_st->bind_param('ii', $cash_account_id, $company_id);
            $c_st->execute();
            $c_acc = $c_st->get_result()->fetch_assoc();
            $c_st->close();
            if ($c_acc) {
                // Hitung total gaji bersih periode ini
                $n_st = $db->prepare('SELECT COALESCE(SUM(net_salary), 0) AS total_net, COUNT(*) AS total_items FROM payroll_items WHERE payroll_period_id = ? AND company_id = ?');
                $n_st->bind_param('ii', $period_id, $company_id);
                $n_st->execute();
                $p_totals = $n_st->get_result()->fetch_assoc();
                $n_st->close();

                $total_paid = (float) ($p_totals['total_net'] ?? 0);
                if ($total_paid > 0) {
                    $c_date = date('Y-m-d');
                    $c_dir  = 'out';
                    $c_cat  = 'Gaji Karyawan';
                    $period_lbl = payroll_period_label($period);
                    $c_desc = 'Payroll Periode ' . $period_lbl . ' (' . (int) ($p_totals['total_items'] ?? 0) . ' karyawan)';
                    $null_method = null;
                    $t_st = $db->prepare('INSERT INTO cash_transactions
                        (company_id, cash_account_id, date, direction, amount, category, description, payment_method_id, created_user, created_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    $t_st->bind_param('iissdssis', $company_id, $cash_account_id, $c_date, $c_dir, $total_paid, $c_cat, $c_desc, $null_method, $user);
                    $t_st->execute();
                    $t_st->close();
                }
            }
        }

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}
