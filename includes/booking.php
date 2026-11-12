<?php
/**
 * Helper booking (travel & rental): transisi status dan nomor resi acak.
 * Skema tidak punya kolom status; status diturunkan dari booking_status_history.
 */

const BOOKING_TRANSITIONS = [
    'draft'       => ['confirmed', 'cancelled', 'rescheduled'],
    'confirmed'   => ['scheduled', 'cancelled', 'rescheduled'],
    'scheduled'   => ['on_trip', 'cancelled', 'rescheduled'],
    'on_trip'     => ['done', 'cancelled', 'rescheduled'],
    'rescheduled' => ['confirmed', 'scheduled', 'cancelled'],
];

/** Transisi sah? Status 'done' & 'cancelled' tidak punya tujuan (terminal). */
function booking_transition_ok(string $from, string $to): bool
{
    return in_array($to, BOOKING_TRANSITIONS[$from] ?? [], true);
}

/** Resi acak legacy: prefix + 8 karakter dari random_bytes (tanpa 0/O/1/I). */
function booking_doc_number(string $prefix): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    foreach (str_split(random_bytes(8)) as $b) {
        $out .= $alphabet[ord($b) % strlen($alphabet)];
    }
    return $prefix . '-' . $out;
}

/**
 * Format nomor dokumen numerik standar ERP Desktop (12 digit):
 * 4 digit nomor urut (mulai 1001) + 8 digit tanggal YYYYMMDD.
 * Contoh: 100120261113
 */
function booking_numeric_doc_number(int $seq, ?string $date = null): string
{
    $num = max(1001, $seq);
    $ts  = ($date !== null && $date !== '') ? strtotime($date) : false;
    $ymd = $ts ? date('Ymd', $ts) : date('Ymd');
    return sprintf('%04d%s', $num, $ymd);
}

/**
 * Validasi nomor dokumen tiket/booking:
 * Menerima format numerik ERP (10-20 digit, mis. 10000720261110)
 * maupun format legacy (TRV-XXXXXXXX / RNT-XXXXXXXX).
 */
function booking_doc_valid(string $no): bool
{
    return (bool) preg_match('/^(\d{10,20}|(TRV|RNT)-[A-Z2-9]{8})$/', strtoupper(trim($no)));
}
