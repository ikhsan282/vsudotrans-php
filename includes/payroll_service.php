<?php
require_once __DIR__ . '/finance_sync.php';

/**
 * Payroll service — gaji karyawan per periode bulanan.
 *
 * Alur: draft (generate) -> final (dikunci angkanya) -> paid (sudah dibayar).
 * Nilai gaji pokok, uang harian, hari hadir, dan potongan disimpan per item
 * di payroll_items.
 *
 * Kontrak: sukses -> return array, error -> throw. Semua fungsi company-scoped
 * (is_super_admin() boleh lintas perusahaan).
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

/** Karyawan aktif perusahaan (scoped) beserta gaji pokok & uang harian (atau fallback dari master job_titles). */
function payroll_active_employees(int $company_id): array
{
    $db = db();
    $hasEmpBasic = false;
    $resCol = $db->query("SHOW COLUMNS FROM `employees` LIKE 'basic_salary'");
    if ($resCol && $resCol->num_rows > 0) {
        $hasEmpBasic = true;
    }
    $hasTitleBasic = false;
    $resJtCol = $db->query("SHOW COLUMNS FROM `job_titles` LIKE 'basic_salary'");
    if ($resJtCol && $resJtCol->num_rows > 0) {
        $hasTitleBasic = true;
    }

    $titleMap = [];
    if ($hasTitleBasic) {
        $stJt = $db->prepare('SELECT name, basic_salary, daily_allowance FROM job_titles WHERE is_active = 1 AND (company_id = ? OR company_id IS NULL)');
        $stJt->bind_param('i', $company_id);
        $stJt->execute();
        foreach ($stJt->get_result()->fetch_all(MYSQLI_ASSOC) as $jt) {
            $titleMap[$jt['name']] = $jt;
        }
        $stJt->close();
    }

    $cols = $hasEmpBasic ? 'id, name, job_title, basic_salary, daily_allowance' : 'id, name, job_title';
    $st = $db->prepare("SELECT {$cols} FROM employees WHERE company_id = ? AND status = 'aktif' ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach ($rows as &$r) {
        $empBasic = (float) ($r['basic_salary'] ?? 0);
        $empDaily = (float) ($r['daily_allowance'] ?? 0);
        $jt = $titleMap[$r['job_title'] ?? ''] ?? null;
        $r['eff_basic_salary']    = $empBasic > 0 ? $empBasic : (float) ($jt['basic_salary'] ?? 0);
        $r['eff_daily_allowance'] = $empDaily > 0 ? $empDaily : (float) ($jt['daily_allowance'] ?? 0);
    }
    unset($r);

    return $rows;
}

/**
 * Buat periode + satu item per karyawan aktif. Semua dalam satu transaksi.
 *
 * @return array{period_id:int, items:int}
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

        $employees = payroll_active_employees($company_id);

        $st = $db->prepare('INSERT INTO payroll_items
            (payroll_period_id, employee_id, company_id, basic_salary, daily_allowance, days_present, deductions, net_salary, created_user)
            VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)');
        foreach ($employees as $e) {
            $basic = (float) ($e['eff_basic_salary'] ?? 0);
            $daily = (float) ($e['eff_daily_allowance'] ?? 0);
            $net = payroll_net($basic, $daily, 0, 0.0);
            $eid = (int) $e['id'];
            $st->bind_param('iiiddds', $period_id, $eid, $company_id, $basic, $daily, $net, $user);
            $st->execute();
        }
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    return ['period_id' => $period_id, 'items' => count($employees)];
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
 * Ubah komponen gaji satu item, lalu hitung ulang gaji bersih.
 * Ditolak bila periode sudah 'paid'.
 */
function payroll_item_update(int $period_id, int $company_id, int $item_id, int $days_present, float $deductions, ?string $note, ?string $user, ?float $basic_salary = null, ?float $daily_allowance = null): array
{
    if ($days_present < 0 || $days_present > 31) {
        throw new InvalidArgumentException('Hari hadir harus 0-31.');
    }
    if ($deductions < 0) {
        throw new InvalidArgumentException('Potongan tidak boleh negatif.');
    }
    if ($basic_salary !== null && $basic_salary < 0) {
        throw new InvalidArgumentException('Gaji pokok tidak boleh negatif.');
    }
    if ($daily_allowance !== null && $daily_allowance < 0) {
        throw new InvalidArgumentException('Uang harian tidak boleh negatif.');
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

        $eff_basic = $basic_salary !== null ? $basic_salary : (float) $item['basic_salary'];
        $eff_daily = $daily_allowance !== null ? $daily_allowance : (float) $item['daily_allowance'];

        $net = payroll_net($eff_basic, $eff_daily, $days_present, $deductions);
        $st = $db->prepare('UPDATE payroll_items SET basic_salary = ?, daily_allowance = ?, days_present = ?, deductions = ?, net_salary = ?, note = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
        $st->bind_param('ddiddssi', $eff_basic, $eff_daily, $days_present, $deductions, $net, $note, $user, $item_id);
        $st->execute();
        $st->close();

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    return ['item_id' => $item_id, 'basic_salary' => $eff_basic, 'daily_allowance' => $eff_daily, 'days_present' => $days_present, 'deductions' => $deductions, 'net_salary' => $net];
}

/**
 * Transisi status periode: draft -> final -> paid. Tidak ada transisi mundur,
 * dan status 'paid' bersifat final.
 */
function payroll_period_set_status(int $period_id, int $company_id, string $status, ?string $user, bool $record_cash = true): void
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

        // Jika status diubah menjadi 'paid' & pencatatan kas aktif, catat pengeluaran kas otomatis ke Buku Kas
        if ($status === 'paid' && $record_cash) {
            // Hitung total gaji bersih periode ini
            $n_st = $db->prepare('SELECT COALESCE(SUM(net_salary), 0) AS total_net, COUNT(*) AS total_items FROM payroll_items WHERE payroll_period_id = ? AND company_id = ?');
            $n_st->bind_param('ii', $period_id, $company_id);
            $n_st->execute();
            $p_totals = $n_st->get_result()->fetch_assoc();
            $n_st->close();

            $total_paid = (float) ($p_totals['total_net'] ?? 0);
            if ($total_paid > 0) {
                finance_ensure_schema();
                $srcType = 'payroll_period';
                $chkTx = $db->prepare('SELECT id FROM cash_transactions WHERE company_id = ? AND source_type = ? AND source_id = ? LIMIT 1');
                $chkTx->bind_param('isi', $company_id, $srcType, $period_id);
                $chkTx->execute();
                $alreadyTx = $chkTx->get_result()->fetch_assoc();
                $chkTx->close();

                if (!$alreadyTx) {
                    $c_date = date('Y-m-d');
                    $c_dir  = 'out';
                    $c_cat  = 'Gaji Karyawan';
                    $period_lbl = payroll_period_label($period);
                    $c_desc = 'Payroll Periode ' . $period_lbl . ' (' . (int) ($p_totals['total_items'] ?? 0) . ' karyawan)';
                    $null_method = null;
                    $t_st = $db->prepare('INSERT INTO cash_transactions
                        (company_id, date, direction, amount, category, description, payment_method_id, source_type, source_id, created_user, created_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    $t_st->bind_param('issdssisis', $company_id, $c_date, $c_dir, $total_paid, $c_cat, $c_desc, $null_method, $srcType, $period_id, $user);
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
