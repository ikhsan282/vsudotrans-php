<?php
/**
 * Booking service (travel & rental): transaksi booking + invoice otomatis,
 * premi, status, reschedule/cancel, penugasan driver.
 *
 * booking_type: 'travel' | 'rental'. Status kini = to_status terakhir di
 * booking_status_history (skema tidak punya kolom status).
 */
require_once __DIR__ . '/booking.php';

function booking_prefix(string $type): string
{
    return $type === 'rental' ? 'RNT' : 'TRV';
}

function booking_table(string $type): string
{
    if (!in_array($type, ['travel', 'rental'], true)) {
        throw new InvalidArgumentException('booking_type tidak dikenal');
    }
    return $type === 'rental' ? 'rental_car_bookings' : 'travel_ticket_bookings';
}

/** Resi acak unik per perusahaan (dicek ke tabel booking). */
function booking_unique_doc(string $type, int $company_id): string
{
    $table = booking_table($type);
    for ($i = 0; $i < 12; $i++) {
        $doc = booking_doc_number(booking_prefix($type));
        $st = db()->prepare("SELECT 1 FROM {$table} WHERE company_id = ? AND document_number = ? LIMIT 1");
        $st->bind_param('is', $company_id, $doc);
        $st->execute();
        $exists = $st->get_result()->num_rows > 0;
        $st->close();
        if (!$exists) {
            return $doc;
        }
    }
    throw new RuntimeException('Gagal membuat nomor resi unik.');
}

/** Status terkini booking (history terakhir; default 'draft'). */
function booking_current_status(string $type, int $booking_id): string
{
    $st = db()->prepare('SELECT to_status FROM booking_status_history
        WHERE booking_type = ? AND booking_id = ? ORDER BY id DESC LIMIT 1');
    $st->bind_param('si', $type, $booking_id);
    $st->execute();
    $s = $st->get_result()->fetch_column();
    $st->close();
    return $s ?: 'draft';
}

/** Pajak perusahaan (%). Belum ada kolom -> default 0. */
function booking_tax_percent(int $company_id): float
{
    // ponytail: tambah kolom tax_percent di companies bila pajak per perusahaan diperlukan
    return 0.0;
}

/** Nomor invoice urut dari document_sequences (doc_type 'INV'), FOR UPDATE. */
function invoice_next_no(int $company_id): string
{
    $db = db();
    // Pastikan baris ada lebih dulu (race-safe), lalu kunci baris untuk urutan.
    $st = $db->prepare("INSERT INTO document_sequences (company_id, doc_type, last_no) VALUES (?, 'INV', 0)
        ON DUPLICATE KEY UPDATE id = id");
    $st->bind_param('i', $company_id);
    $st->execute();
    $st->close();

    $st = $db->prepare("SELECT id, last_no FROM document_sequences
        WHERE company_id = ? AND doc_type = 'INV' FOR UPDATE");
    $st->bind_param('i', $company_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $next = (int) $row['last_no'] + 1;
    $st = $db->prepare('UPDATE document_sequences SET last_no = ?, modified_date = NOW() WHERE id = ?');
    $st->bind_param('ii', $next, $row['id']);
    $st->execute();
    $st->close();
    return 'INV/' . date('Y') . '/' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/** Salin premi_configs kategori -> booking_cost_lines (snapshot). */
function booking_copy_premi(string $type, int $booking_id, int $company_id, ?int $category_vehicle_id): void
{
    if ($category_vehicle_id === null) {
        return;
    }
    $st = db()->prepare('SELECT cost_type, premi_type, premi_value, note FROM premi_configs
        WHERE company_id = ? AND category_vehicle_id = ? AND is_active = 1');
    $st->bind_param('ii', $company_id, $category_vehicle_id);
    $st->execute();
    $configs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $ins = db()->prepare('INSERT INTO booking_cost_lines
        (company_id, booking_type, booking_id, cost_type, description, amount, premi_type, premi_value, premi_amount)
        VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)');
    foreach ($configs as $c) {
        $value = (float) $c['premi_value'];
        $premi_amount = $c['premi_type'] === 'persen' ? 0.0 : $value;
        $ins->bind_param('isisssdd', $company_id, $type, $booking_id, $c['cost_type'], $c['note'],
            $c['premi_type'], $value, $premi_amount);
        $ins->execute();
    }
    $ins->close();
}

function booking_insert_history(string $type, int $booking_id, int $company_id, ?string $from, string $to, ?string $reason, ?string $user): void
{
    $st = db()->prepare('INSERT INTO booking_status_history
        (company_id, booking_type, booking_id, from_status, to_status, reason, changed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('isissss', $company_id, $type, $booking_id, $from, $to, $reason, $user);
    $st->execute();
    $st->close();
}

/** Insert invoice; dipanggil DI DALAM transaksi booking_create. */
function invoice_issue(int $company_id, string $type, int $booking_id, ?int $branch_id, string $doc, float $total_price, ?string $user): int
{
    $invoice_no = invoice_next_no($company_id);
    $tax_percent = booking_tax_percent($company_id);
    $subtotal = $total_price;
    $tax_amount = round($subtotal * $tax_percent / 100, 2);
    $total = $subtotal + $tax_amount;

    $st = db()->prepare("INSERT INTO invoices
        (company_id, branch_id, booking_type, booking_id, invoice_no, document_number,
         status, subtotal, tax_percent, tax_amount, total, issued_at, created_user)
        VALUES (?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, ?, NOW(), ?)");
    $st->bind_param('iisissdddds', $company_id, $branch_id, $type, $booking_id, $invoice_no, $doc,
        $subtotal, $tax_percent, $tax_amount, $total, $user);
    $st->execute();
    $id = (int) $st->insert_id;
    $st->close();
    return $id;
}

/**
 * Buat booking + history 'draft' + premi + invoice otomatis, SATU transaksi.
 * $data sudah discope pemanggil (company_id dari active_company_id()).
 *
 * @return array{id:int, document_number:string, invoice_id:int}
 */
function booking_create(string $type, array $data): array
{
    $db = db();
    $table = booking_table($type);
    $company_id = (int) $data['company_id'];
    $doc = booking_unique_doc($type, $company_id);

    $db->begin_transaction();
    try {
        // Kolom wajib beda per tipe: rental butuh rentang tanggal & trip, keduanya NOT NULL.
        if ($type === 'rental') {
            foreach (['start_date', 'end_date'] as $k) {
                if (empty($data[$k])) {
                    throw new InvalidArgumentException("Rental wajib $k.");
                }
            }
            $st = $db->prepare("INSERT INTO rental_car_bookings
                (company_id, branch_id, document_number, date, time, start_date, end_date, trip,
                 pickup_address, pickup_point_city_id, pickup_point_district_id,
                 delivery_address, delivery_point_city_id, delivery_point_district_id,
                 bbm, total_price, category_vehicle_id, vehicle_id, passenger_id, note, created_user)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $trip = (float) ($data['trip'] ?? 0);
            $bbm = (float) ($data['bbm'] ?? 0);
            $st->bind_param('iisssssdsiisiiddiiiss',
                $company_id, $data['branch_id'], $doc, $data['date'], $data['time'],
                $data['start_date'], $data['end_date'], $trip,
                $data['pickup_address'], $data['pickup_point_city_id'], $data['pickup_point_district_id'],
                $data['delivery_address'], $data['delivery_point_city_id'], $data['delivery_point_district_id'],
                $bbm, $data['total_price'], $data['category_vehicle_id'], $data['vehicle_id'],
                $data['passenger_id'], $data['note'], $data['created_user']);
        } else {
            $st = $db->prepare("INSERT INTO travel_ticket_bookings
                (company_id, branch_id, document_number, date, time,
                 pickup_address, pickup_point_city_id, pickup_point_district_id,
                 delivery_address, delivery_point_city_id, delivery_point_district_id,
                 total_price, category_vehicle_id, vehicle_id, passenger_id, note, created_user)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('iissssiisiidiiiss',
                $company_id, $data['branch_id'], $doc, $data['date'], $data['time'],
                $data['pickup_address'], $data['pickup_point_city_id'], $data['pickup_point_district_id'],
                $data['delivery_address'], $data['delivery_point_city_id'], $data['delivery_point_district_id'],
                $data['total_price'], $data['category_vehicle_id'], $data['vehicle_id'],
                $data['passenger_id'], $data['note'], $data['created_user']);
        }
        $st->execute();
        $booking_id = (int) $db->insert_id;
        $st->close();

        booking_insert_history($type, $booking_id, $company_id, null, 'draft', null, $data['created_user']);
        booking_copy_premi($type, $booking_id, $company_id, $data['category_vehicle_id']);
        // Penumpang: daftar lengkap (opsional) + primary = passenger_id booking.
        $pids = $data['passenger_ids'] ?? [];
        if (!$pids) {
            $pids = [(int) $data['passenger_id']];
        }
        booking_passengers_set($type, $booking_id, $company_id, $pids, (int) $data['passenger_id'], $data['created_user']);
        $invoice_id = invoice_issue($company_id, $type, $booking_id, $data['branch_id'], $doc,
            (float) $data['total_price'], $data['created_user']);

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
    return ['id' => $booking_id, 'document_number' => $doc, 'invoice_id' => $invoice_id];
}

/** Ubah status booking dengan validasi transisi; catat history. Cancel -> invoice cancelled. */
function booking_change_status(string $type, int $booking_id, int $company_id, string $to, ?string $reason, ?string $user): void
{
    $from = booking_current_status($type, $booking_id);
    if (!booking_transition_ok($from, $to)) {
        throw new RuntimeException("Transisi tidak sah: {$from} -> {$to}.");
    }
    $db = db();
    $db->begin_transaction();
    try {
        booking_insert_history($type, $booking_id, $company_id, $from, $to, $reason, $user);
        if ($to === 'cancelled') {
            $st = $db->prepare("UPDATE invoices SET status = 'cancelled', modified_user = ?, modified_date = NOW()
                WHERE booking_type = ? AND booking_id = ? AND company_id = ?");
            $st->bind_param('ssii', $user, $type, $booking_id, $company_id);
            $st->execute();
            $st->close();
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/** Snapshot invoice terbaru ke invoice_revisions (sebelum invoice diubah). */
function invoice_snapshot(int $invoice_id, ?string $reason, ?string $user): void
{
    $st = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $st->bind_param('i', $invoice_id);
    $st->execute();
    $inv = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inv) {
        return;
    }
    $st = db()->prepare('SELECT COALESCE(MAX(revision_no), 0) + 1 FROM invoice_revisions WHERE invoice_id = ?');
    $st->bind_param('i', $invoice_id);
    $st->execute();
    $rev = (int) $st->get_result()->fetch_column();
    $st->close();

    $json = json_encode($inv, JSON_UNESCAPED_UNICODE);
    $st = db()->prepare('INSERT INTO invoice_revisions (invoice_id, revision_no, snapshot_json, reason, changed_by)
        VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('iisss', $invoice_id, $rev, $json, $reason, $user);
    $st->execute();
    $st->close();
}

/** Reschedule: ubah tanggal/jam booking, snapshot + perbarui invoice (nomor tetap). */
function booking_reschedule(string $type, int $booking_id, int $company_id, string $date, string $time, ?string $reason, ?string $user): void
{
    $table = booking_table($type);
    $db = db();
    $from = booking_current_status($type, $booking_id);
    if (!booking_transition_ok($from, 'rescheduled') && $from !== 'rescheduled') {
        throw new RuntimeException("Tidak bisa reschedule dari status {$from}.");
    }
    $db->begin_transaction();
    try {
        $st = $db->prepare("UPDATE {$table} SET date = ?, time = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
        $st->bind_param('sssii', $date, $time, $user, $booking_id, $company_id);
        $st->execute();
        $st->close();

        $st = $db->prepare('SELECT id, subtotal, tax_percent FROM invoices WHERE booking_type = ? AND booking_id = ? LIMIT 1');
        $st->bind_param('si', $type, $booking_id);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        $st->close();
        if ($inv) {
            invoice_snapshot((int) $inv['id'], 'Reschedule: ' . (string) $reason, $user);
            $st = $db->prepare('UPDATE invoices SET note = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
            $note = 'Reschedule ke ' . $date . ' ' . $time;
            $st->bind_param('ssi', $note, $user, $inv['id']);
            $st->execute();
            $st->close();
        }
        if ($from !== 'rescheduled') {
            booking_insert_history($type, $booking_id, $company_id, $from, 'rescheduled', $reason, $user);
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/** Ganti driver: tutup penugasan lama (ended_at), buat baru. Tidak UPDATE employee_id. */
function booking_assign_driver(string $type, int $booking_id, int $company_id, int $employee_id, ?int $vehicle_id, ?string $user): void
{
    $db = db();
    // Driver harus karyawan aktif perusahaan ini.
    $st = $db->prepare("SELECT 1 FROM employees WHERE id = ? AND company_id = ? AND status = 'aktif'");
    $st->bind_param('ii', $employee_id, $company_id);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$ok) {
        throw new RuntimeException('Driver harus karyawan aktif perusahaan ini.');
    }

    $db->begin_transaction();
    try {
        $st = $db->prepare('UPDATE trip_assignments SET ended_at = NOW(), modified_user = ?, modified_date = NOW()
            WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND ended_at IS NULL');
        $st->bind_param('ssii', $user, $type, $booking_id, $company_id);
        $st->execute();
        $st->close();

        $st = $db->prepare('INSERT INTO trip_assignments
            (company_id, booking_type, booking_id, employee_id, vehicle_id, started_at, created_user)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)');
        $st->bind_param('isiiis', $company_id, $type, $booking_id, $employee_id, $vehicle_id, $user);
        $st->execute();
        $st->close();
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
}

/** Ambil satu booking scoped tenant. */
function booking_find(string $type, int $id): ?array
{
    $table = booking_table($type);
    [$wsql, $wtypes, $wparams] = company_scope('company_id');
    $st = db()->prepare("SELECT * FROM {$table} WHERE id = ? AND {$wsql} LIMIT 1");
    $st->bind_param('i' . $wtypes, $id, ...$wparams);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Daftar penumpang booking (terurut; primary lebih dulu).
 * Jika booking_passengers kosong, fallback ke passenger_id booking lama.
 * @return array<int, array{id:int, passenger_id:int, name:string, phone:?string, is_primary:int}>
 */
function booking_passengers_list(string $type, int $booking_id, ?int $booking_passenger_id = null): array
{
    $st = db()->prepare('SELECT bp.passenger_id, bp.is_primary, p.name, p.phone_number
        FROM booking_passengers bp JOIN passengers p ON p.id = bp.passenger_id
        WHERE bp.booking_type = ? AND bp.booking_id = ?
        ORDER BY bp.is_primary DESC, bp.line_no, bp.id');
    $st->bind_param('si', $type, $booking_id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (!$rows && $booking_passenger_id) {
        $st = db()->prepare('SELECT id, name, phone_number FROM passengers WHERE id = ? LIMIT 1');
        $st->bind_param('i', $booking_passenger_id);
        $st->execute();
        $p = $st->get_result()->fetch_assoc();
        $st->close();
        if ($p) {
            $rows = [['passenger_id' => (int) $p['id'], 'is_primary' => 1, 'name' => $p['name'], 'phone_number' => $p['phone_number']]];
        }
    }
    foreach ($rows as &$r) {
        $r['passenger_id'] = (int) $r['passenger_id'];
        $r['is_primary'] = (int) $r['is_primary'];
    }
    return $rows;
}

/**
 * Ganti daftar penumpang booking (hapus lalu isi ulang). Primary = $primary_id.
 * Dipanggil di dalam transaksi booking_create / update.
 * @param int[] $passenger_ids
 */
function booking_passengers_set(string $type, int $booking_id, int $company_id, array $passenger_ids, int $primary_id, ?string $user): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $passenger_ids), static fn($v) => $v > 0)));
    $db = db();
    $st = $db->prepare('DELETE FROM booking_passengers WHERE booking_type = ? AND booking_id = ?');
    $st->bind_param('si', $type, $booking_id);
    $st->execute();
    $st->close();

    $ins = $db->prepare('INSERT INTO booking_passengers
        (company_id, booking_type, booking_id, passenger_id, is_primary, line_no, created_user)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $line = 0;
    foreach ($ids as $pid) {
        $line++;
        $is_primary = ($pid === $primary_id) ? 1 : 0;
        $ins->bind_param('isiiiis', $company_id, $type, $booking_id, $pid, $is_primary, $line, $user);
        $ins->execute();
    }
    $ins->close();
}
