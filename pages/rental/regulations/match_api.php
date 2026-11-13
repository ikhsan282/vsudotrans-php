<?php
/**
 * Endpoint JSON untuk mencocokkan & menghitung Peraturan Komisi Karyawan
 * terhadap Booking Rental (berdasarkan regulation_id spesifik atau auto-match
 * company_id + category_vehicle_id + trip + start_date + total_price).
 */
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');
require_once __DIR__ . '/../../../includes/regulation_service.php';

header('Content-Type: application/json; charset=utf-8');

try {
    regulation_ensure_schema();
    $regId       = (int) ($_GET['regulation_id'] ?? $_POST['regulation_id'] ?? 0);
    $companyId   = (int) ($_GET['company_id'] ?? $_POST['company_id'] ?? (active_company_id() ?? 0));
    $categoryId  = (int) ($_GET['category_vehicle_id'] ?? $_POST['category_vehicle_id'] ?? 0);
    $trip        = max(1.0, (float) ($_GET['trip'] ?? $_POST['trip'] ?? 1));
    $startDate   = trim((string) ($_GET['start_date'] ?? $_POST['start_date'] ?? date('Y-m-d')));
    $totalPrice  = parse_rupiah_input($_GET['total_price'] ?? $_POST['total_price'] ?? 0);

    if ($regId > 0) {
        $reg = regulation_find($regId);
        if ($reg) {
            assert_company_access((int) $reg['company_id']);
            if ($companyId > 0 && (int) $reg['company_id'] !== $companyId) {
                echo json_encode(['ok' => false, 'error' => 'Peraturan komisi tidak sesuai dengan perusahaan booking ini.']);
                exit;
            }
        }
    } else {
        if ($companyId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Perusahaan belum dipilih.']);
            exit;
        }
        assert_company_access($companyId);
        if ($categoryId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Pilih Kategori Kendaraan terlebih dahulu pada tab Detail.']);
            exit;
        }
        $reg = regulation_match($companyId, $categoryId, $trip, $startDate);
    }

    if (!$reg) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Belum ada Peraturan Komisi Karyawan untuk Kategori Kendaraan dan Perusahaan ini.',
        ]);
        exit;
    }

    $calc = regulation_calculate($reg, $totalPrice);

    echo json_encode([
        'ok'         => true,
        'regulation' => [
            'id'            => (int) $reg['id'],
            'company_code'  => $reg['company_code'],
            'category_code' => $reg['category_code'],
            'category_name' => $reg['category_name'],
            'trip'          => (int) $reg['trip'],
            'note'          => (string) ($reg['note'] ?: regulation_build_summary($reg['details'] ?? [])),
            'details'       => $reg['details'] ?? [],
        ],
        'calculated' => $calc,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

