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

/** Resi acak: prefix + 8 karakter dari random_bytes (tanpa 0/O/1/I). */
function booking_doc_number(string $prefix): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    foreach (str_split(random_bytes(8)) as $b) {
        $out .= $alphabet[ord($b) % strlen($alphabet)];
    }
    return $prefix . '-' . $out;
}
