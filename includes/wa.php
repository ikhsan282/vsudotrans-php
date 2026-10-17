<?php
/**
 * Notifikasi WhatsApp — setelan per perusahaan + pengiriman + log outbox.
 *
 * Shared hosting cPanel: TIDAK ada cron yang bisa diandalkan dan TIDAK ada
 * ekstensi curl PHP. Pengiriman dilakukan sinkron saat booking disimpan
 * (stream_context_create + file_get_contents), gagal WA tidak menggagalkan booking.
 */

require_once __DIR__ . '/booking.php';

/** Setelan WA perusahaan; array kosong bila belum diisi (default aman). */
function wa_settings(int $cid): array
{
    $st = db()->prepare('SELECT * FROM wa_settings WHERE company_id = ? LIMIT 1');
    $st->bind_param('i', $cid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: [];
}

/**
 * Ganti placeholder {nama} {nomor} {tanggal} {asal} {tujuan} {harga} {jenis}.
 * Placeholder tak dikenal dibiarkan apa adanya.
 */
function wa_render(string $template, array $vars): string
{
    foreach ($vars as $k => $v) {
        $template = str_replace('{' . $k . '}', (string) $v, $template);
    }
    return $template;
}

function wa_outbox_log(int $cid, string $type, int $booking_id, string $phone, string $message, string $status, string $response = ''): void
{
    $st = db()->prepare('INSERT INTO wa_outbox (company_id, booking_type, booking_id, phone, message, status, response)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $resp = $response === '' ? null : $response;
    $st->bind_param('issssss', $cid, $type, $booking_id, $phone, $message, $status, $resp);
    $st->execute();
    $st->close();
}

/**
 * Kirim satu pesan; SELALU mencatat ke wa_outbox. Tidak pernah throw — return false.
 */
function wa_send(int $cid, string $booking_type, int $booking_id, string $phone, string $message): bool
{
    try {
        $phone = wa_phone_normalize($phone);
        $msg = substr($message, 0, 2000);
        $s = wa_settings($cid);
        $url = trim((string) ($s['api_url'] ?? ''));

        if (empty($s['is_active']) || $url === '' || $phone === '') {
            wa_outbox_log($cid, $booking_type, $booking_id, $phone, $msg, 'skipped',
                empty($s['is_active']) ? 'WA nonaktif' : ($url === '' ? 'endpoint kosong' : 'nomor tujuan kosong'));
            return false;
        }

        $payload = json_encode([
            'token'    => (string) ($s['api_token'] ?? ''),
            'sender'   => (string) ($s['sender'] ?? ''),
            'target'   => $phone,
            'phone'    => $phone,
            'message'  => $msg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 5, // ponytail: timeout pendek; pengiriman sinkron saat simpan booking
            'ignore_errors' => true, // body respons tetap terbaca walau status >= 400
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = (int) (explode(' ', (string) ($http_response_header[0] ?? ''))[1] ?? 0);
        $ok = $body !== false && $code >= 200 && $code < 300;

        wa_outbox_log($cid, $booking_type, $booking_id, $phone, $msg,
            $ok ? 'sent' : 'failed',
            $code . ' ' . substr((string) $body, 0, 900));
        return $ok;
    } catch (Throwable $ex) {
        // Gagal log pun tak boleh menjatuhkan booking.
        @error_log('wa_send: ' . $ex->getMessage());
        return false;
    }
}

/** Nomor Indonesia: 08xx / 8xx -> 628xx (tanpa + dan tanpa pemisah). */
function wa_phone_normalize(string $phone): string
{
    $d = preg_replace('/[^0-9]/', '', $phone);
    if ($d === '' || $d === null) {
        return '';
    }
    if (str_starts_with($d, '0')) {
        return '62' . substr($d, 1);
    }
    if (str_starts_with($d, '8')) {
        return '62' . $d;
    }
    return $d;
}

/**
 * Pemicu notifikasi booking BARU. Dipanggil SETELAH booking tersimpan —
 * kegagalan di sini tidak boleh membatalkan booking (semua ditelan di dalam).
 */
function wa_notify_booking(string $type, int $booking_id): void
{
    try {
        $table = booking_table($type);
        $st = db()->prepare("SELECT b.company_id, b.document_number, b.date, b.total_price,
                b.pickup_point_district_id, b.delivery_point_district_id, b.passenger_id
            FROM {$table} b WHERE b.id = ? LIMIT 1");
        $st->bind_param('i', $booking_id);
        $st->execute();
        $b = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$b) {
            return;
        }
        $cid = (int) $b['company_id'];
        $s = wa_settings($cid);
        if (empty($s['is_active'])) {
            return; // belum dipakai: jangan banjiri wa_outbox
        }

        $st = db()->prepare('SELECT name, phone_number FROM passengers WHERE id = ? LIMIT 1');
        $st->bind_param('i', $b['passenger_id']);
        $st->execute();
        $pax = $st->get_result()->fetch_assoc() ?: [];
        $st->close();

        $district = static function (int $id): string {
            $st = db()->prepare('SELECT name FROM districts WHERE id = ? LIMIT 1');
            $st->bind_param('i', $id);
            $st->execute();
            $n = (string) $st->get_result()->fetch_column();
            $st->close();
            return $n;
        };

        $vars = [
            'nama'    => (string) ($pax['name'] ?? ''),
            'nomor'   => (string) $b['document_number'],
            'tanggal' => tanggal_indo((string) $b['date']),
            'asal'    => $district((int) $b['pickup_point_district_id']),
            'tujuan'  => $district((int) $b['delivery_point_district_id']),
            'harga'   => rupiah((float) $b['total_price']),
            'jenis'   => $type === 'rental' ? 'Rental' : 'Travel',
        ];

        $tpl = (string) ($s['template_' . $type] ?? '');
        $message = $tpl !== '' ? wa_render($tpl, $vars) : wa_default_template($vars);

        // Nomor tujuan admin; bila kosong pakai nomor penumpang (beri tahu pelanggan).
        $targets = array_filter(array_map('trim', explode(',', (string) ($s['notify_phones'] ?? ''))));
        if (!$targets) {
            $targets = [(string) ($pax['phone_number'] ?? '')];
        }
        foreach (array_slice(array_values($targets), 0, 20) as $to) {
            wa_send($cid, $type, $booking_id, (string) $to, $message);
        }
    } catch (Throwable $ex) {
        @error_log('wa_notify_booking: ' . $ex->getMessage());
    }
}

/** Template bawaan bila kolom template perusahaan masih kosong. */
function wa_default_template(array $v): string
{
    return "*Booking {$v['jenis']} Baru*\n"
        . "No: {$v['nomor']}\n"
        . "Nama: {$v['nama']}\n"
        . "Tanggal: {$v['tanggal']}\n"
        . "Asal: {$v['asal']}\n"
        . "Tujuan: {$v['tujuan']}\n"
        . "Harga: {$v['harga']}";
}
