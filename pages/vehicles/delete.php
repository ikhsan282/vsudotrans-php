<?php
/** Hapus kendaraan (POST saja). Cek relasi FK RESTRICT dulu: trip_schedules. */
require_once __DIR__ . '/../../config/config.php';
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/vehicles/index.php');
}
verify_csrf();

$db = db();
$id = (int) ($_POST['id'] ?? 0);
$st = $db->prepare('SELECT id, company_id, vehicle_number FROM vehicles WHERE id = ?');
$st->bind_param('i', $id);
$st->execute();
$v = $st->get_result()->fetch_assoc();
$st->close();

if (!$v) {
    flash('error', 'Kendaraan tidak ditemukan.');
} else {
    assert_company_access((int) $v['company_id']);
    $st = $db->prepare('SELECT COUNT(*) FROM trip_schedules WHERE vehicle_id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $used = (int) $st->get_result()->fetch_row()[0];
    $st->close();

    if ($used > 0) {
        flash('error', "Kendaraan {$v['vehicle_number']} tidak dapat dihapus: masih dipakai {$used} jadwal perjalanan.");
    } else {
        try {
            $st = $db->prepare('DELETE FROM vehicles WHERE id = ?');
            $st->bind_param('i', $id);
            $st->execute();
            $st->close();
            audit('vehicle.delete', "Kendaraan #{$id} {$v['vehicle_number']}");
            flash('success', 'Kendaraan berhasil dihapus.');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1451) {
                throw $ex;
            }
            flash('error', "Kendaraan {$v['vehicle_number']} tidak dapat dihapus: masih dipakai data lain.");
        }
    }
}
redirect('pages/vehicles/index.php');
