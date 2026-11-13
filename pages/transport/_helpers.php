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
