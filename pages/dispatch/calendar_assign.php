<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/../../includes/booking_service.php';
require_permission('dispatch.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman lalu coba lagi.']);
    exit;
}

$kind = strtolower(trim((string) ($_POST['kind'] ?? '')));
if (!in_array($kind, ['travel', 'rental'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Jenis pemesanan tidak valid.']);
    exit;
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID pemesanan tidak valid.']);
    exit;
}

$requiredPerm = $kind === 'rental' ? 'rental.manage' : 'travel.manage';
if (!can($requiredPerm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Anda tidak memiliki izin mengatur armada/sopir pemesanan ini.']);
    exit;
}

$activeCid = active_company_id();
if ($activeCid === null && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Pilih perusahaan aktif terlebih dahulu.']);
    exit;
}

$b = booking_find($kind, $bookingId);
if (!$b) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan tidak ditemukan.']);
    exit;
}

$bookingCid = (int) $b['company_id'];
if (!is_super_admin() && $bookingCid !== $activeCid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan bukan milik perusahaan aktif.']);
    exit;
}

$curStatus = booking_current_status($kind, $bookingId);
if ($curStatus === 'cancelled') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Pemesanan yang sudah dibatalkan tidak dapat diubah penugasannya.']);
    exit;
}

$vehicleId   = max(0, (int) ($_POST['vehicle_id'] ?? 0));
$driverId    = max(0, (int) ($_POST['driver_id'] ?? 0));
$clearDriver = ((string) ($_POST['clear_driver'] ?? '0')) === '1';

$db = db();
if ($vehicleId > 0) {
    $stV = $db->prepare('SELECT id FROM vehicles WHERE id = ? AND company_id = ? LIMIT 1');
    $stV->bind_param('ii', $vehicleId, $bookingCid);
    $stV->execute();
    $okVeh = $stV->get_result()->num_rows > 0;
    $stV->close();
    if (!$okVeh) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Armada bukan milik perusahaan pemesanan ini.']);
        exit;
    }

    $winStart = $kind === 'rental' ? (string) ($b['start_date'] ?: $b['date']) : (string) $b['date'];
    $winEnd   = $kind === 'rental' ? (string) ($b['end_date'] ?: $winStart) : $winStart;
    $exRentalId = $kind === 'rental' ? $bookingId : 0;
    $stC = $db->prepare("SELECT b.id, b.document_number, b.start_date, b.end_date,
                                COALESCE((SELECT h.to_status FROM booking_status_history h WHERE h.booking_type = 'rental' AND h.booking_id = b.id ORDER BY h.id DESC LIMIT 1), 'draft') AS cur_status
                           FROM rental_car_bookings b
                          WHERE b.company_id = ?
                            AND b.vehicle_id = ?
                            AND b.id <> ?
                            AND b.start_date <= ?
                            AND b.end_date >= ?
                         HAVING cur_status <> 'cancelled'
                          LIMIT 1");
    $stC->bind_param('iiiss', $bookingCid, $vehicleId, $exRentalId, $winEnd, $winStart);
    $stC->execute();
    $conflict = $stC->get_result()->fetch_assoc();
    $stC->close();
    if ($conflict) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => 'Armada sedang dipakai Rental ' . $conflict['document_number'] . ' (' . $conflict['start_date'] . ' s/d ' . $conflict['end_date'] . ').',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($driverId > 0) {
    $stE = $db->prepare("SELECT id FROM employees WHERE id = ? AND company_id = ? AND status = 'aktif' LIMIT 1");
    $stE->bind_param('ii', $driverId, $bookingCid);
    $stE->execute();
    $okEmp = $stE->get_result()->num_rows > 0;
    $stE->close();
    if (!$okEmp) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sopir harus karyawan aktif milik perusahaan pemesanan ini.']);
        exit;
    }
}

$user = $_SESSION['username'] ?? null;
$table = booking_table($kind);

try {
    if ($vehicleId > 0) {
        $stUp = $db->prepare("UPDATE {$table} SET vehicle_id = ?, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
        $stUp->bind_param('isii', $vehicleId, $user, $bookingId, $bookingCid);
    } else {
        $stUp = $db->prepare("UPDATE {$table} SET vehicle_id = NULL, modified_user = ?, modified_date = NOW() WHERE id = ? AND company_id = ?");
        $stUp->bind_param('sii', $user, $bookingId, $bookingCid);
    }
    $stUp->execute();
    $stUp->close();

    if ($driverId > 0) {
        booking_assign_driver($kind, $bookingId, $bookingCid, $driverId, $vehicleId > 0 ? $vehicleId : null, $user);
    } elseif ($clearDriver) {
        $stEnd = $db->prepare('UPDATE trip_assignments SET ended_at = NOW(), modified_user = ?, modified_date = NOW() WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND ended_at IS NULL');
        $stEnd->bind_param('ssii', $user, $kind, $bookingId, $bookingCid);
        $stEnd->execute();
        $stEnd->close();
    } else {
        if ($vehicleId > 0) {
            $stSyncV = $db->prepare('UPDATE trip_assignments SET vehicle_id = ?, modified_user = ?, modified_date = NOW() WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND ended_at IS NULL');
            $stSyncV->bind_param('issii', $vehicleId, $user, $kind, $bookingId, $bookingCid);
        } else {
            $stSyncV = $db->prepare('UPDATE trip_assignments SET vehicle_id = NULL, modified_user = ?, modified_date = NOW() WHERE booking_type = ? AND booking_id = ? AND company_id = ? AND ended_at IS NULL');
            $stSyncV->bind_param('ssii', $user, $kind, $bookingId, $bookingCid);
        }
        $stSyncV->execute();
        $stSyncV->close();
    }

    $newStatus = booking_auto_advance_status($kind, $bookingId, $bookingCid, $user);
    audit('booking.' . $kind . '.assign', "Pemesanan #{$bookingId} armada #{$vehicleId} sopir #{$driverId} (Kalender)");

    // Baca kembali state armada & sopir terbaru
    $vehInfo = ['vehicle_number' => '', 'vehicle_category' => '', 'brand_name' => ''];
    if ($vehicleId > 0) {
        $stVI = $db->prepare('SELECT COALESCE(v.vehicle_number, "") AS vehicle_number, COALESCE(v.vehicle_category, "") AS vehicle_category, COALESCE(bv.name, "") AS brand_name FROM vehicles v LEFT JOIN brand_vehicles bv ON bv.id = v.brand_id WHERE v.id = ? LIMIT 1');
        $stVI->bind_param('i', $vehicleId);
        $stVI->execute();
        $vehInfo = $stVI->get_result()->fetch_assoc() ?: $vehInfo;
        $stVI->close();
    }

    $drvSummary = booking_driver_summaries($kind, [$bookingId])[$bookingId]['primary'] ?? null;
    $resolvedDrvId = (int) ($drvSummary['id'] ?? 0);
    $resolvedDrvName = (string) ($drvSummary['name'] ?? '');
    $resolvedDrvPhone = (string) ($drvSummary['phone'] ?? '');
    $hasVehicle = $vehicleId > 0 && $vehInfo['vehicle_number'] !== '';
    $hasDriver = $resolvedDrvName !== '';
    $needsDispatch = $newStatus !== 'cancelled' && $newStatus !== 'done' && (!$hasVehicle || !$hasDriver);
    $startYmd = $kind === 'rental' ? (string) ($b['start_date'] ?: $b['date']) : (string) $b['date'];

    echo json_encode([
        'ok'                 => true,
        'vehicle_id'         => $vehicleId,
        'vehicle_number'     => (string) $vehInfo['vehicle_number'],
        'vehicle_category'   => (string) $vehInfo['vehicle_category'],
        'brand_name'         => (string) $vehInfo['brand_name'],
        'driver_id'          => $resolvedDrvId,
        'driver'             => $resolvedDrvName,
        'driver_phone'       => $resolvedDrvPhone,
        'driver_phone_62'    => normalize_phone_62($resolvedDrvPhone) ?? '',
        'has_vehicle'        => $hasVehicle,
        'has_driver'         => $hasDriver,
        'needs_dispatch'     => $needsDispatch,
        'status'             => $newStatus,
        'status_label'       => booking_status_label($newStatus),
        'message'            => 'Penugasan armada & sopir berhasil disimpan.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
