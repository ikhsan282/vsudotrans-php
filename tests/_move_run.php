<?php
/**
 * Helper uji (bukan bagian aplikasi): jalankan satu aksi urut di proses terpisah.
 * Dipakai tests/masters_reorder_check.php — aksi master_move() berakhir redirect()+exit,
 * sehingga tidak bisa dipanggil inline di dalam proses tes.
 * Usage: php -c <php.ini> tests/_move_run.php <payload.json>
 *   payload: {id, dir, csrf, role, cid}
 *
 * Penanda hasil ditulis ke <payload>.out supaya pemanggil bisa membedakan:
 *   aksi_selesai — master_move() selesai dan mengalihkan (redirect → request berakhir)
 *   pindah_sama  — aksi selesai tanpa mengubah urutan (mis. baris sudah di tepi daftar)
 *   csrf_403     — token CSRF ditolak
 *   error:<pesan>
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/masters/_engine.php';

$f = $argv[1] ?? '';
$in = $f !== '' ? json_decode((string) @file_get_contents($f), true) : null;
if (!is_array($in)) {
    fwrite(STDERR, "payload tidak valid\n");
    exit(2);
}
$result = $f . '.out';
file_put_contents($result, 'mulai');

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'reorder-check';
$_SESSION['csrf_token'] = 'reorder-check-token';
$_SESSION['role_name'] = (string) ($in['role'] ?? 'Admin');
$_SESSION['company_id'] = $in['cid'] ?? 1;
$_SESSION['permissions'] = ['finance.view', 'finance.manage']; // role non-Super-Admin: scope tenant aktif
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['id' => (int) $in['id'], 'dir' => (string) $in['dir'], 'csrf_token' => (string) $in['csrf']];

// Snapshot nilai urut sesudah aksi: redirect() memanggil exit, jadi hasilnya dicatat
// lewat shutdown function (tetap berjalan saat exit).
register_shutdown_function(static function () use ($result, $in): void {
    $cur = file_get_contents($result);
    if ($cur !== 'mulai') {
        return; // sudah ditulis lebih awal (csrf/error)
    }
    if (http_response_code() === 403) {
        file_put_contents($result, 'csrf_403');
        return;
    }
    $st = db()->prepare('SELECT `sort_order` FROM payment_methods WHERE id = ?');
    $id = (int) $in['id'];
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    file_put_contents($result, $row ? 'aksi_selesai' : 'aksi_selesai:baris_hilang');
});

$dir = match ((string) ($in['dir'] ?? '')) { 'up' => -1, 'down' => 1, default => 0 };
master_move('payment_methods', (int) $in['id'], $dir);
file_put_contents($result, 'pindah_sama'); // hanya tercapai bila tidak redirect (no-op)