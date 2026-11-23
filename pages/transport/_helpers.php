<?php
/**
 * Helper terpadu modul Transportasi (Penumpang & Peraturan Komisi).
 * Dipakai bersama oleh Travel maupun Rental Mobil agar bebas ketergantungan lintas modul.
 */
require_once __DIR__ . '/../../includes/transport_helpers.php';

/** Apakah pengguna berhak melihat data Transportasi (Travel atau Rental). */
function transport_can_view(): bool
{
    return can('travel.view') || can('rental.view') || can('travel.manage') || can('rental.manage');
}

/** Apakah pengguna berhak mengelola data Transportasi (Travel atau Rental). */
function transport_can_manage(): bool
{
    return can('travel.manage') || can('rental.manage');
}

/** Tolak akses lihat jika tidak memiliki izin Travel maupun Rental. */
function transport_require_view(bool $json = false): void
{
    if (transport_can_view()) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Akses ditolak.']);
        exit;
    }
    require_permission('travel.view');
}

/** Tolak akses ubah jika tidak memiliki izin kelola Travel maupun Rental. */
function transport_require_manage(bool $json = false): void
{
    if (transport_can_manage()) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Akses kelola ditolak.']);
        exit;
    }
    require_permission('travel.manage');
}

/**
 * Ambil lokasi & alamat jemput/antar terakhir pelanggan dari pemesanan non-cancelled
 * dalam satu perusahaan. Dipakai bersama oleh AJAX last_booking.php dan form pesan-ulang.
 */
function passenger_last_booking_locations(int $companyId, int $passengerId, string $prefType = 'travel'): ?array
{
    if ($companyId <= 0 || $passengerId <= 0) {
        return null;
    }
    $prefType = $prefType === 'rental' ? 'rental' : 'travel';
    $orderTables = $prefType === 'rental'
        ? ['rental' => 'rental_car_bookings', 'travel' => 'travel_ticket_bookings']
        : ['travel' => 'travel_ticket_bookings', 'rental' => 'rental_car_bookings'];

    $db = db();
    $hit = null;
    $hitType = '';

    foreach ($orderTables as $bType => $tbl) {
        $sql = "SELECT b.id, b.document_number, b.date, b.category_vehicle_id,
                       b.pickup_point_province_id, b.pickup_point_city_id, b.pickup_point_district_id, b.pickup_address,
                       b.delivery_point_province_id, b.delivery_point_city_id, b.delivery_point_district_id, b.delivery_address,
                       CONCAT(pp.code, ' - ', pp.name) AS pickup_province_name,
                       pc.name AS pickup_city_name,
                       pd.name AS pickup_district_name,
                       CONCAT(dp.code, ' - ', dp.name) AS delivery_province_name,
                       dc.name AS delivery_city_name,
                       dd.name AS delivery_district_name
                  FROM {$tbl} b
                  LEFT JOIN provinces pp ON pp.id = b.pickup_point_province_id
                  LEFT JOIN cities pc ON pc.id = b.pickup_point_city_id
                  LEFT JOIN districts pd ON pd.id = b.pickup_point_district_id
                  LEFT JOIN provinces dp ON dp.id = b.delivery_point_province_id
                  LEFT JOIN cities dc ON dc.id = b.delivery_point_city_id
                  LEFT JOIN districts dd ON dd.id = b.delivery_point_district_id
                 WHERE b.company_id = ? AND b.passenger_id = ?
                   AND COALESCE((SELECT h.to_status FROM booking_status_history h
                                  WHERE h.booking_type = ? AND h.booking_id = b.id
                                  ORDER BY h.id DESC LIMIT 1), 'draft') <> 'cancelled'
                   AND (b.pickup_point_district_id IS NOT NULL OR b.delivery_point_district_id IS NOT NULL
                        OR COALESCE(b.pickup_address, '') <> '' OR COALESCE(b.delivery_address, '') <> '')
                 ORDER BY b.date DESC, b.id DESC
                 LIMIT 1";
        $st = $db->prepare($sql);
        $st->bind_param('iis', $companyId, $passengerId, $bType);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $hit = $row;
            $hitType = $bType;
            break;
        }
    }

    if (!$hit) {
        return null;
    }

    $packSide = static function (string $pfx) use ($hit): array {
        $provId = (int) ($hit["{$pfx}_point_province_id"] ?? 0);
        $cityId = (int) ($hit["{$pfx}_point_city_id"] ?? 0);
        $distId = (int) ($hit["{$pfx}_point_district_id"] ?? 0);
        if ($distId > 0 && ($provId <= 0 || $cityId <= 0) && function_exists('resolve_district_info')) {
            if ($info = resolve_district_info($distId)) {
                if ($provId <= 0) {
                    $provId = (int) ($info['province_id'] ?? 0);
                }
                if ($cityId <= 0) {
                    $cityId = (int) ($info['city_id'] ?? 0);
                }
            }
        }
        return [
            'province_id'   => $provId,
            'province_name' => (string) ($hit["{$pfx}_province_name"] ?? ''),
            'city_id'       => $cityId,
            'city_name'     => (string) ($hit["{$pfx}_city_name"] ?? ''),
            'district_id'   => $distId,
            'district_name' => (string) ($hit["{$pfx}_district_name"] ?? ''),
            'address'       => trim((string) ($hit["{$pfx}_address"] ?? '')),
        ];
    };

    return [
        'doc_no'              => (string) ($hit['document_number'] ?? ''),
        'booking_type'        => $hitType,
        'date'                => (string) ($hit['date'] ?? ''),
        'category_vehicle_id' => (int) ($hit['category_vehicle_id'] ?? 0),
        'pickup'              => $packSide('pickup'),
        'delivery'            => $packSide('delivery'),
    ];
}

/**
 * Terapkan prefill pelanggan & lokasi terakhir ke array input form booking (Travel / Rental)
 * saat menerima parameter GET ?passenger_id=<id>[&prefill_last=1], dengan validasi tenant.
 */
function booking_apply_passenger_prefill(int $cid, string $serviceType, array &$in, ?array $query = null): ?array
{
    $src = $query ?? $_GET;
    $prefillPaxId = (int) ($src['passenger_id'] ?? 0);
    if ($cid <= 0 || $prefillPaxId <= 0) {
        return null;
    }

    $stPaxPre = db()->prepare('SELECT id, code, name FROM passengers WHERE id = ? AND company_id = ? LIMIT 1');
    $stPaxPre->bind_param('ii', $prefillPaxId, $cid);
    $stPaxPre->execute();
    $paxPreRow = $stPaxPre->get_result()->fetch_assoc();
    $stPaxPre->close();
    if (!$paxPreRow) {
        return null;
    }

    $in['passenger_id'] = (string) $prefillPaxId;
    if ((string) ($src['prefill_last'] ?? '') !== '1') {
        return null;
    }

    $srvType = $serviceType === 'rental' ? 'rental' : 'travel';
    $lastLoc = passenger_last_booking_locations($cid, $prefillPaxId, $srvType);
    if (!$lastLoc) {
        return [
            'passenger_name' => (string) $paxPreRow['name'],
            'doc_no'         => '',
            'date'           => '',
            'has_loc'        => false,
        ];
    }

    foreach (['pickup', 'delivery'] as $side) {
        $sd = $lastLoc[$side] ?? [];
        if (!empty($sd['province_id'])) {
            $in["{$side}_point_province_id"] = (string) (int) $sd['province_id'];
        }
        if (!empty($sd['city_id'])) {
            $in["{$side}_point_city_id"] = (string) (int) $sd['city_id'];
        }
        if (!empty($sd['district_id'])) {
            $in["{$side}_point_district_id"] = (string) (int) $sd['district_id'];
        }
        if (!empty($sd['address'])) {
            $in["{$side}_address"] = (string) $sd['address'];
        }
    }

    $lastCatId = (int) ($lastLoc['category_vehicle_id'] ?? 0);
    if ($lastCatId > 0 && ($lastLoc['booking_type'] ?? '') === $srvType) {
        category_service_ensure_schema();
        $stCatChk = db()->prepare('SELECT id FROM category_vehicles WHERE id = ? AND company_id = ? AND is_active = 1 AND service_type = ? LIMIT 1');
        $stCatChk->bind_param('iis', $lastCatId, $cid, $srvType);
        $stCatChk->execute();
        if ($stCatChk->get_result()->fetch_row()) {
            $in['category_vehicle_id'] = (string) $lastCatId;
        }
        $stCatChk->close();
    }

    return [
        'passenger_name' => (string) $paxPreRow['name'],
        'doc_no'         => (string) ($lastLoc['doc_no'] ?? ''),
        'date'           => !empty($lastLoc['date']) ? tanggal_indo((string) $lastLoc['date']) : '',
        'has_loc'        => true,
    ];
}

/** Render alert banner info saat form booking dibuka via Pesan Lagi dari profil pelanggan. */
function booking_prefill_banner_html(?array $banner): string
{
    if (!$banner) {
        return '';
    }
    $name = e((string) ($banner['passenger_name'] ?? ''));
    if (!empty($banner['has_loc'])) {
        $doc  = e((string) ($banner['doc_no'] ?? ''));
        $date = trim((string) ($banner['date'] ?? ''));
        $msg  = "Pesan ulang untuk <strong>{$name}</strong>: lokasi jemput &amp; antar diisi otomatis dari pemesanan terakhir <code>{$doc}</code>"
            . ($date !== '' ? ' (' . e($date) . ')' : '') . '.';
    } else {
        $msg = "Pelanggan <strong>{$name}</strong> dipilih otomatis (belum ada riwayat lokasi sebelumnya).";
    }
    return '<div class="alert alert-info alert-dismissible fade show py-2 px-3 small mb-2 d-flex align-items-center gap-2" role="alert">'
        . '<i class="bi bi-arrow-repeat"></i><span>' . $msg . '</span>'
        . '<button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Tutup"></button>'
        . '</div>';
}
