<?php
/**
 * Dispatch service: penjadwalan driver pada tabel `trip_assignments`.
 *
 * Kontrak: kembalikan data saat sukses, throw exception saat error.
 * Satu booking = maksimal satu assignment aktif (ended_at IS NULL); riwayat
 * penugasan lama tetap tersimpan (tutup task lama, buat baru).
 *
 * Scoping tenant memakai parameter $company_id, BUKAN company_scope() yang
 * membaca session: company_scope() mengembalikan "1=0" bila session kosong
 * (mis. dipanggil dari test CLI), sehingga semua baris hilang. Halaman tetap
 * mengirim perusahaan aktif dari session, sama seperti pola
 * booking_service/invoice_service. Akses lintas perusahaan Super Admin
 * ditangani di dispatch_booking_guard().
 *
 * Kriteria "driver": karyawan `status = 'aktif'` dan kolom bebas
 * `employees.job_position` = 'Driver' (case-insensitive). Skema tidak punya
 * penanda jabatan terstruktur — `job_title` di data uji berisi 'Staff
 * Operasional' untuk driver maupun admin, sedangkan `job_position` berisi
 * 'Driver'. Tanpa penanda ini, semua karyawan akan muncul sebagai calon driver.
 */
require_once __DIR__ . '/booking_service.php';

/** Tipe booking yang boleh ditugaskan (whitelist). */
const DISPATCH_TYPES = ['travel', 'rental'];

function dispatch_assert_type(string $booking_type): void
{
    if (!in_array($booking_type, DISPATCH_TYPES, true)) {
        throw new InvalidArgumentException("booking_type tidak dikenal: {$booking_type}");
    }
}

/**
 * Karyawan yang bisa ditugaskan sebagai driver, scoped perusahaan.
 * Kriteria: status aktif AND job_position = 'Driver'.
 */
function driver_list(int $company_id): array
{
    $st = db()->prepare("SELECT id, code, name, job_position, phone_number
        FROM employees
        WHERE company_id = ? AND status = 'aktif' AND job_position = 'Driver'
        ORDER BY name");
    $st->bind_param('i', $company_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Validasi booking: ada, tipe sah, dan milik perusahaan yang sama.
 * Super Admin boleh lintas perusahaan (seperti company_scope()).
 * @return array{id:int, company_id:int, document_number:string, date:?string, time:?string}
 */
function dispatch_booking_guard(int $company_id, string $booking_type, int $booking_id): array
{
    dispatch_assert_type($booking_type);
    $table = booking_table($booking_type);

    $st = db()->prepare("SELECT id, company_id, document_number, date, time FROM {$table} WHERE id = ? LIMIT 1");
    $st->bind_param('i', $booking_id);
    $st->execute();
    $b = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$b) {
        throw new RuntimeException('Booking tidak ditemukan.');
    }
    if ((int) $b['company_id'] !== $company_id && !is_super_admin()) {
        throw new RuntimeException('Booking bukan milik perusahaan aktif.');
    }
    $b['company_id'] = (int) $b['company_id'];
    return $b;
}

/** Karyawan & kendaraan wajib milik perusahaan yang sama dengan booking. */
function dispatch_asset_guard(int $company_id, int $employee_id, ?int $vehicle_id): void
{
    $st = db()->prepare("SELECT 1 FROM employees WHERE id = ? AND company_id = ? AND status = 'aktif'");
    $st->bind_param('ii', $employee_id, $company_id);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$ok) {
        throw new RuntimeException('Driver harus karyawan aktif perusahaan ini.');
    }

    if ($vehicle_id !== null) {
        $st = db()->prepare('SELECT 1 FROM vehicles WHERE id = ? AND company_id = ?');
        $st->bind_param('ii', $vehicle_id, $company_id);
        $st->execute();
        $ok = $st->get_result()->num_rows > 0;
        $st->close();
        if (!$ok) {
            throw new RuntimeException('Kendaraan bukan milik perusahaan ini.');
        }
    }
}

/** Assignment aktif sebuah booking, atau null. */
function dispatch_active_for(string $booking_type, int $booking_id): ?array
{
    $st = db()->prepare('SELECT id, employee_id, vehicle_id, started_at, created_user
        FROM trip_assignments
        WHERE booking_type = ? AND booking_id = ? AND ended_at IS NULL
        ORDER BY id DESC LIMIT 1');
    $st->bind_param('si', $booking_type, $booking_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Tugaskan driver ke booking. started_at = NOW().
 * @return array{id:int, booking_type:string, booking_id:int, employee_id:int, vehicle_id:?int, started_at:string}
 */
function dispatch_assign(int $company_id, string $booking_type, int $booking_id, int $employee_id, ?int $vehicle_id, ?string $reason, string $user): array
{
    $booking = dispatch_booking_guard($company_id, $booking_type, $booking_id);
    dispatch_asset_guard($company_id, $employee_id, $vehicle_id);

    if (dispatch_active_for($booking_type, $booking_id) !== null) {
        throw new RuntimeException('Booking ini sudah punya penugasan aktif. Selesaikan dulu sebelum menugaskan ulang.');
    }

    $st = db()->prepare('INSERT INTO trip_assignments
        (company_id, booking_type, booking_id, employee_id, vehicle_id, started_at, reason, created_user)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)');
    // MySQLi mengikat null sebagai SQL NULL (bukan ''), jadi satu bind cukup —
    // pemanggil WAJIB mengirim null, bukan 0, agar fk_ta_vehicle tidak dilanggar.
    $st->bind_param('isiiiss', $company_id, $booking_type, $booking_id, $employee_id, $vehicle_id, $reason, $user);
    $st->execute();
    $inserted = (int) $st->insert_id;
    $st->close();

    $row = dispatch_find($inserted, $company_id);
    if (!$row) {
        throw new RuntimeException('Penugasan gagal dibaca kembali setelah disimpan.');
    }
    return $row;
}

/** Satu assignment scoped perusahaan, atau null. */
function dispatch_find(int $assignment_id, int $company_id): ?array
{
    $st = db()->prepare('SELECT t.*, e.name AS employee_name, v.vehicle_number
        FROM trip_assignments t
        LEFT JOIN employees e ON e.id = t.employee_id
        LEFT JOIN vehicles  v ON v.id = t.vehicle_id
        WHERE t.id = ? AND t.company_id = ? LIMIT 1');
    $st->bind_param('ii', $assignment_id, $company_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Selesaikan penugasan: ended_at = NOW(). Tolak bila tidak ada / sudah berakhir.
 * @return array{id:int, ended_at:string}
 */
function dispatch_end(int $assignment_id, int $company_id, ?string $reason, string $user): array
{
    $st = db()->prepare('SELECT id, company_id, reason FROM trip_assignments
        WHERE id = ? AND company_id = ? AND ended_at IS NULL LIMIT 1');
    $st->bind_param('ii', $assignment_id, $company_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        // Beda pesan: tidak ada vs sudah berakhir — agar operator tahu penyebabnya.
        throw new RuntimeException(dispatch_find($assignment_id, $company_id)
            ? 'Penugasan ini sudah berakhir.'
            : 'Penugasan tidak ditemukan.');
    }

    $new_reason = $reason !== null && $reason !== '' ? $reason : (string) $row['reason'];
    $st = db()->prepare('UPDATE trip_assignments
        SET ended_at = NOW(), reason = ?, modified_user = ?, modified_date = NOW()
        WHERE id = ? AND company_id = ? AND ended_at IS NULL');
    $st->bind_param('siii', $new_reason, $user, $assignment_id, $company_id);
    $st->execute();
    $st->close();

    $ended = db()->prepare('SELECT ended_at FROM trip_assignments WHERE id = ?');
    $ended->bind_param('i', $assignment_id);
    $ended->execute();
    $ended_at = (string) $ended->get_result()->fetch_column();
    $ended->close();

    return ['id' => $assignment_id, 'ended_at' => $ended_at];
}

/** Document number booking untuk sebuah assignment (tabel beda per tipe). */
function dispatch_doc_sql(string $booking_type, string $alias): string
{
    return "(SELECT document_number FROM " . booking_table($booking_type)
        . " WHERE id = {$alias}.booking_id)";
}

/**
 * Papan pemantauan: assignment aktif (ended_at IS NULL) + info booking/driver/kendaraan.
 * Dua query (bukan UNION) karena document_number diambil dengan helper booking_table().
 */
function dispatch_active(int $company_id): array
{
    $out = [];
    foreach (DISPATCH_TYPES as $type) {
        $doc = dispatch_doc_sql($type, 't');
        $st = db()->prepare("SELECT t.id, t.booking_type, t.booking_id, t.started_at, t.reason,
                {$doc} AS document_number,
                e.name AS employee_name, e.phone_number AS employee_phone,
                v.vehicle_number, v.vehicle_color
            FROM trip_assignments t
            LEFT JOIN employees e ON e.id = t.employee_id
            LEFT JOIN vehicles  v ON v.id = t.vehicle_id
            WHERE t.company_id = ? AND t.booking_type = ? AND t.ended_at IS NULL");
        $st->bind_param('is', $company_id, $type);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = $r;
        }
        $st->close();
    }
    usort($out, static fn($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));
    return $out;
}

/**
 * Riwayat penugasan satu booking (aktif & selesai), terbaru dulu.
 * Tidak memakai company_scope: pemanggil sudah memvalidasi booking milik tenant.
 */
function dispatch_by_booking(string $booking_type, int $booking_id): array
{
    dispatch_assert_type($booking_type);
    $st = db()->prepare('SELECT t.id, t.booking_type, t.booking_id, t.employee_id, t.vehicle_id,
            t.started_at, t.ended_at, t.reason, t.created_user,
            e.name AS employee_name, v.vehicle_number
        FROM trip_assignments t
        LEFT JOIN employees e ON e.id = t.employee_id
        LEFT JOIN vehicles  v ON v.id = t.vehicle_id
        WHERE t.booking_type = ? AND t.booking_id = ?
        ORDER BY t.id DESC');
    $st->bind_param('si', $booking_type, $booking_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Riwayat penugasan perusahaan (rentang tanggal opsional, inklusif pada started_at).
 * @return array<int, array<string, mixed>>
 */
function dispatch_history(int $company_id, ?string $from = null, ?string $to = null): array
{
    $where = 't.company_id = ?';
    $types = 'i';
    $params = [$company_id];
    if ($from !== null && $from !== '') {
        $where .= ' AND t.started_at >= ?';
        $types .= 's';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== null && $to !== '') {
        $where .= ' AND t.started_at <= ?';
        $types .= 's';
        $params[] = $to . ' 23:59:59';
    }

    $out = [];
    foreach (DISPATCH_TYPES as $type) {
        $doc = dispatch_doc_sql($type, 't');
        $st = db()->prepare("SELECT t.id, t.booking_type, t.booking_id, t.started_at, t.ended_at, t.reason,
                {$doc} AS document_number,
                e.name AS employee_name, v.vehicle_number
            FROM trip_assignments t
            LEFT JOIN employees e ON e.id = t.employee_id
            LEFT JOIN vehicles  v ON v.id = t.vehicle_id
            WHERE t.booking_type = ? AND {$where}
            ORDER BY t.id DESC LIMIT 300");
        $st->bind_param('s' . $types, $type, ...$params);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = $r;
        }
        $st->close();
    }
    usort($out, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
    return $out;
}

/**
 * Booking yang BELUM punya assignment aktif, untuk dropdown penugasan.
 * $limit 50 terbaru per tipe (dan per rentang di papan) agar dropdown tidak meledak.
 * @return array<int, array{booking_type:string, booking_id:int, document_number:string, date:?string, time:?string, label:string}>
 */
function dispatch_assignable(int $company_id, int $limit = 50): array
{
    $out = [];
    foreach (DISPATCH_TYPES as $type) {
        $table = booking_table($type);
        $st = db()->prepare("SELECT b.id, b.document_number, b.date, b.time
            FROM {$table} b
            WHERE b.company_id = ? AND NOT EXISTS (
                SELECT 1 FROM trip_assignments t
                WHERE t.booking_type = ? AND t.booking_id = b.id AND t.ended_at IS NULL)
            ORDER BY b.date DESC, b.id DESC LIMIT ?");
        $st->bind_param('isi', $company_id, $type, $limit);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = [
                'booking_type' => $type,
                'booking_id' => (int) $r['id'],
                'document_number' => $r['document_number'],
                'date' => $r['date'],
                'time' => $r['time'],
                'label' => strtoupper($type) . ' · ' . $r['document_number'] . ' · ' . $r['date'] . ' ' . substr((string) $r['time'], 0, 5),
            ];
        }
        $st->close();
    }
    return $out;
}
