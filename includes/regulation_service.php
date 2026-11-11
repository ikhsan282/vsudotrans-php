<?php
/**
 * Service untuk Peraturan Komisi Karyawan (Rental Mobil) — VSudoTrans ERP.
 * Mengelola tabel `rental_car_regulation_employees` (Header) dan
 * `rental_car_regulation_employee_details` (Rincian: Sopir, Kernet, Montir, BBM, Agen).
 */

const REGULATION_EMP_TYPES  = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'BBM', 'Agen'];
const REGULATION_CALC_TYPES = ['Persentase', 'Tetap'];

/** Pastikan tabel detail `rental_car_regulation_employee_details` tersedia di DB. */
function regulation_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = db();
    $db->query(
        "CREATE TABLE IF NOT EXISTS `rental_car_regulation_employee_details` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `regulation_id` INT(11) NOT NULL,
            `line_no` INT(11) NOT NULL DEFAULT 1,
            `employee_type` VARCHAR(40) NOT NULL COMMENT 'Sopir|Kernet|Montir|BBM|Agen',
            `calc_type` VARCHAR(20) NOT NULL DEFAULT 'Tetap' COMMENT 'Persentase|Tetap',
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `created_user` VARCHAR(255) DEFAULT NULL,
            `created_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_rental_reg_detail_reg` (`regulation_id`),
            CONSTRAINT `fk_rental_reg_detail_reg` FOREIGN KEY (`regulation_id`)
                REFERENCES `rental_car_regulation_employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Format nilai detail peraturan (mis. 13.5% atau 150.000). */
function regulation_format_val(string $calcType, float $amount): string
{
    if (strcasecmp($calcType, 'Persentase') === 0) {
        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        return ($formatted === '' ? '0' : $formatted) . '%';
    }
    return number_format(round($amount), 0, ',', '.');
}

/** Bangun ringkasan otomatis dari baris detail (contoh: "Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%"). */
function regulation_build_summary(array $details): string
{
    $parts = [];
    foreach ($details as $d) {
        $etype = trim((string) ($d['employee_type'] ?? ''));
        $ctype = trim((string) ($d['calc_type'] ?? 'Tetap'));
        $amt   = (float) ($d['amount'] ?? 0);
        if ($etype === '' || $amt <= 0) {
            continue;
        }
        if (strcasecmp($ctype, 'Persentase') === 0) {
            $v = rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.');
            $parts[] = "{$etype} {$v}%";
        } else {
            if ($amt >= 1000 && fmod($amt, 1000) == 0.0) {
                $parts[] = $etype . ' ' . (int) round($amt / 1000) . 'k';
            } else {
                $parts[] = $etype . ' ' . number_format(round($amt), 0, ',', '.');
            }
        }
    }
    return implode(', ', $parts);
}

/** Ambil satu peraturan komisi beserta daftar rinciannya (`details`). */
function regulation_find(int $id): ?array
{
    regulation_ensure_schema();
    $db = db();
    [$wsql, $wtypes, $wparams] = company_scope('r.company_id');
    $st = $db->prepare(
        "SELECT r.*,
                c.code AS company_code, c.name AS company_name,
                cv.code AS category_code, cv.name AS category_name
           FROM rental_car_regulation_employees r
           JOIN companies c ON c.id = r.company_id
           JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
          WHERE r.id = ? AND {$wsql}
          LIMIT 1"
    );
    $st->bind_param('i' . $wtypes, $id, ...$wparams);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    $stD = $db->prepare(
        'SELECT id, line_no, employee_type, calc_type, amount
           FROM rental_car_regulation_employee_details
          WHERE regulation_id = ?
          ORDER BY line_no ASC, id ASC'
    );
    $stD->bind_param('i', $id);
    $stD->execute();
    $details = $stD->get_result()->fetch_all(MYSQLI_ASSOC);
    $stD->close();

    // Fallback bila record lama hanya punya kolom type & amount di header
    if (!$details && !empty($row['type']) && (float) $row['amount'] > 0) {
        $details = [[
            'id'            => 0,
            'line_no'       => 1,
            'employee_type' => (string) $row['type'],
            'calc_type'     => 'Tetap',
            'amount'        => (float) $row['amount'],
        ]];
    }

    $row['details'] = $details;
    return $row;
}

/** Ambil semua peraturan komisi milik suatu perusahaan (untuk dropdown di Detail Booking Rental). */
function regulation_list_by_company(int $companyId): array
{
    regulation_ensure_schema();
    $db = db();
    $st = $db->prepare(
        "SELECT r.*,
                c.code AS company_code, c.name AS company_name,
                cv.code AS category_code, cv.name AS category_name
           FROM rental_car_regulation_employees r
           JOIN companies c ON c.id = r.company_id
           JOIN category_vehicles cv ON cv.id = r.category_vehicle_id
          WHERE r.company_id = ?
          ORDER BY cv.code ASC, r.trip ASC, r.start_date DESC, r.id DESC"
    );
    $st->bind_param('i', $companyId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    if (!$rows) {
        return [];
    }

    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $inList = implode(',', $ids);
    $detRes = $db->query(
        "SELECT regulation_id, line_no, employee_type, calc_type, amount
           FROM rental_car_regulation_employee_details
          WHERE regulation_id IN ({$inList})
          ORDER BY regulation_id ASC, line_no ASC, id ASC"
    );
    $byReg = [];
    if ($detRes) {
        while ($d = $detRes->fetch_assoc()) {
            $byReg[(int) $d['regulation_id']][] = $d;
        }
    }
    foreach ($rows as &$r) {
        $rid = (int) $r['id'];
        $r['details'] = $byReg[$rid] ?? [];
    }
    unset($r);
    return $rows;
}

/**
 * Cari peraturan komisi yang paling cocok untuk parameter booking rental:
 * - company_id & category_vehicle_id cocok
 * - tanggal keberangkatan berada di antara start_date..end_date
 * - prioritaskan trip yang sama persis, lalu trip terdekat
 */
function regulation_match(int $companyId, int $categoryVehicleId, float $trip = 1, ?string $bookingDate = null): ?array
{
    if ($companyId <= 0 || $categoryVehicleId <= 0) {
        return null;
    }
    regulation_ensure_schema();
    $bDate = ($bookingDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) ? $bookingDate : date('Y-m-d');
    $tripInt = max(1, (int) round($trip));

    $db = db();
    $st = $db->prepare(
        "SELECT id
           FROM rental_car_regulation_employees
          WHERE company_id = ?
            AND category_vehicle_id = ?
            AND start_date <= ?
            AND end_date >= ?
          ORDER BY (trip = ?) DESC, ABS(trip - ?) ASC, start_date DESC, id DESC
          LIMIT 1"
    );
    $st->bind_param('iissii', $companyId, $categoryVehicleId, $bDate, $bDate, $tripInt, $tripInt);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        // Fallback tanpa filter tanggal bila tanggal booking di luar range namun aturan kategori ada
        $st2 = $db->prepare(
            "SELECT id
               FROM rental_car_regulation_employees
              WHERE company_id = ?
                AND category_vehicle_id = ?
              ORDER BY (trip = ?) DESC, ABS(trip - ?) ASC, id DESC
              LIMIT 1"
        );
        $st2->bind_param('iiii', $companyId, $categoryVehicleId, $tripInt, $tripInt);
        $st2->execute();
        $row = $st2->get_result()->fetch_assoc();
        $st2->close();
    }

    return $row ? regulation_find((int) $row['id']) : null;
}

/**
 * Hitung nominal BBM & baris kru (Sopir, Kernet, Montir, Agen) dari Peraturan Komisi
 * terhadap Harga Sewa (`$totalPrice`).
 *
 * @return array{
 *   bbm: float,
 *   crew_rows: array<int, array{type:string, calc_type:string, raw_value:float, amount:float}>,
 *   total_crew: float,
 *   total_operational_cost: float
 * }
 */
function regulation_calculate(array $regulation, float $totalPrice): array
{
    $bbm = 0.0;
    $crewRows = [];
    $totalCrew = 0.0;
    $details = $regulation['details'] ?? [];

    foreach ($details as $d) {
        $empType  = trim((string) ($d['employee_type'] ?? 'Sopir'));
        $calcType = trim((string) ($d['calc_type'] ?? 'Tetap'));
        $rawVal   = (float) ($d['amount'] ?? 0);
        if ($empType === '' || $rawVal <= 0) {
            continue;
        }

        if (strcasecmp($calcType, 'Persentase') === 0) {
            $nominal = round($totalPrice * ($rawVal / 100));
        } else {
            $nominal = round($rawVal);
        }

        if (strcasecmp($empType, 'BBM') === 0) {
            $bbm += $nominal;
        } else {
            $crewRows[] = [
                'type'       => $empType,
                'calc_type'  => $calcType,
                'raw_value'  => $rawVal,
                'amount'     => $nominal,
            ];
            $totalCrew += $nominal;
        }
    }

    return [
        'bbm'                    => $bbm,
        'crew_rows'              => $crewRows,
        'total_crew'             => $totalCrew,
        'total_operational_cost' => $bbm + $totalCrew,
    ];
}

/**
 * Simpan Peraturan Komisi Karyawan (Create / Update) dalam 1 transaksi.
 *
 * @param array{company_id:int, category_vehicle_id:int, trip:int, start_date:string, end_date:string, note:string} $header
 * @param array<int, array{employee_type:string, calc_type:string, amount:float}> $details
 */
function regulation_save(int $id, array $header, array $details, ?string $user): int
{
    regulation_ensure_schema();
    $db = db();

    $companyId  = (int) ($header['company_id'] ?? 0);
    $categoryId = (int) ($header['category_vehicle_id'] ?? 0);
    $trip       = max(1, (int) ($header['trip'] ?? 1));
    $startDate  = trim((string) ($header['start_date'] ?? date('Y-01-01')));
    $endDate    = trim((string) ($header['end_date'] ?? '9999-12-31'));
    $note       = trim((string) ($header['note'] ?? ''));

    if ($companyId <= 0 || $categoryId <= 0) {
        throw new InvalidArgumentException('Perusahaan dan Kategori Kendaraan wajib dipilih.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new InvalidArgumentException('Format Tanggal Mulai dan Tanggal Selesai tidak valid.');
    }
    if ($endDate < $startDate) {
        throw new InvalidArgumentException('Tanggal Selesai tidak boleh sebelum Tanggal Mulai.');
    }

    $cleanDetails = [];
    $sumAmount    = 0.0;
    foreach ($details as $d) {
        $etype = trim((string) ($d['employee_type'] ?? ''));
        $ctype = trim((string) ($d['calc_type'] ?? 'Tetap'));
        if (!in_array($ctype, REGULATION_CALC_TYPES, true)) {
            $ctype = 'Tetap';
        }
        $amt = round((float) ($d['amount'] ?? 0), 2);
        if ($etype === '' || $amt <= 0) {
            continue;
        }
        $cleanDetails[] = [
            'employee_type' => $etype,
            'calc_type'     => $ctype,
            'amount'        => $amt,
        ];
        $sumAmount += $amt;
    }

    if (!$cleanDetails) {
        throw new InvalidArgumentException('Minimal 1 baris rincian komisi (Tipe Karyawan & Jumlah > 0) wajib diisi.');
    }

    if ($note === '') {
        $note = regulation_build_summary($cleanDetails);
    }
    $headerType = 'Komisi';

    $db->begin_transaction();
    try {
        if ($id > 0) {
            $up = $db->prepare(
                'UPDATE rental_car_regulation_employees
                    SET company_id = ?, category_vehicle_id = ?, trip = ?, start_date = ?, end_date = ?,
                        type = ?, amount = ?, note = ?, modified_user = ?, modified_date = NOW()
                  WHERE id = ?'
            );
            $up->bind_param(
                'iiisssdssi',
                $companyId,
                $categoryId,
                $trip,
                $startDate,
                $endDate,
                $headerType,
                $sumAmount,
                $note,
                $user,
                $id
            );
            $up->execute();
            $up->close();
        } else {
            $ins = $db->prepare(
                'INSERT INTO rental_car_regulation_employees
                    (company_id, category_vehicle_id, trip, start_date, end_date, type, amount, note, created_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->bind_param(
                'iiisssdss',
                $companyId,
                $categoryId,
                $trip,
                $startDate,
                $endDate,
                $headerType,
                $sumAmount,
                $note,
                $user
            );
            $ins->execute();
            $id = (int) $db->insert_id;
            $ins->close();
        }

        $del = $db->prepare('DELETE FROM rental_car_regulation_employee_details WHERE regulation_id = ?');
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();

        $insD = $db->prepare(
            'INSERT INTO rental_car_regulation_employee_details
                (regulation_id, line_no, employee_type, calc_type, amount, created_user)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $lineNo = 0;
        foreach ($cleanDetails as $cd) {
            $lineNo++;
            $insD->bind_param('iissds', $id, $lineNo, $cd['employee_type'], $cd['calc_type'], $cd['amount'], $user);
            $insD->execute();
        }
        $insD->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    return $id;
}

/** Hapus Peraturan Komisi Karyawan beserta rinciannya. */
function regulation_delete(int $id, int $companyId): void
{
    regulation_ensure_schema();
    $db = db();
    $db->begin_transaction();
    try {
        $delD = $db->prepare('DELETE FROM rental_car_regulation_employee_details WHERE regulation_id = ?');
        $delD->bind_param('i', $id);
        $delD->execute();
        $delD->close();

        $delH = $db->prepare('DELETE FROM rental_car_regulation_employees WHERE id = ? AND company_id = ?');
        $delH->bind_param('ii', $id, $companyId);
        $delH->execute();
        if ($delH->affected_rows <= 0) {
            $delH->close();
            throw new RuntimeException('Data peraturan komisi tidak ditemukan.');
        }
        $delH->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

