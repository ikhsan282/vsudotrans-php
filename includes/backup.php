<?php
/**
 * Engine Backup & Restore database — murni PHP/MySQLi, tanpa exec (shared hosting
 * cPanel tidak memberi akses shell). Dipakai oleh pages/settings/backup.php dan
 * diuji langsung oleh tests/backup_check.php.
 *
 * Batas yang disadari (lihat juga komentar di tiap fungsi):
 *  - Hanya tabel & data (TABLE/VIEW, kolom, index, trigger, event). Tidak ada
 *    definisi stored routine/procedure/function/view.
 *  - Dump bukan pengganti mysqldump untuk DB besar: tiap batch INSERT = 1 request round-trip.
 *  - Nilai biner (BLOB/BINARY) ditulis sebagai string mentah — belum di-hex.
 *    Tambahkan hex saat ada kolom biner (None di schema VSudoTrans saat ini).
 *  - Restore mengandalkan DDL ter-quote tepat oleh SHOW CREATE TABLE; pernyataan
 *    yang dikirim sendiri oleh user bisa saja tidak ikut. Delimiter custom
 *    (BEGIN...END, trigger) TIDAK didukung.
 */

/** Daftar tabel basis data saat ini (urutan alfabetis dari SHOW TABLES). */
function backup_tables(): array
{
    return array_column(db()->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
}

/**
 * Nama tabel dari SHOW TABLES boleh berupa identifier aneh; kutip dengan backtick
 * dan tolak nama yang tak bisa dikutip dengan aman (tidak ada di schema saat ini).
 */
function backup_quote_ident(string $name): string
{
    if (str_contains($name, '`') || str_contains($name, "\0")) {
        throw new RuntimeException('Nama tabel tidak aman untuk dikutip: ' . $name);
    }
    return '`' . $name . '`';
}

/**
 * Tulis dump seluruh database ke stream (default php://output), per tabel, bertahap
 * supaya tidak ada DB utuh yang ditampung di satu string.
 *
 * Isi dump: CREATE TABLE (tanpa DROP TABLE) + DELETE FROM tabel + INSERT data.
 * Alasan: dump ini dipakai juga untuk restore IN-PLACE di aplikasi yang sedang hidup.
 * DROP TABLE akan membuat tabel `users` hilang di tengah request -> sesi Super Admin
 * yang menjalankan restore langsung tidak valid dan audit()/flash()/redirect() gagal.
 * Karena itu tabel dikosongkan dengan DELETE, bukan dijatuhkan.
 *
 * Perlu skema baru (mis. kolom bertambah dari versi aplikasi yang lebih baru)?
 * Buat dump sendiri dengan `SHOW CREATE TABLE` lalu impor lewat mySQL/phpMyAdmin —
 * tugas Restore UI adalah memulihkan DATA, dan itu tidak butuh DDL.
 *
 * Mengembalikan jumlah tabel yang ditulis.
 */
function backup_dump_to_stream($out = null, int $batch = 200): int
{
    $out = $out ?? fopen('php://output', 'wb');
    $db  = db();

    $write = static function (string $s) use ($out): void {
        fwrite($out, $s);
    };

    $write("-- VSudoTrans database backup\n");
    $write('-- Database: ' . DB_NAME . "\n");
    $write('-- Dibuat: ' . date('Y-m-d H:i:s') . " (Asia/Jakarta)\n");
    $write("-- Dibuat oleh: VSudoTrans (dump murni PHP, tanpa mysqldump)\n");
    $write("-- Mode: restore in-place (skema tidak dijatuhkan — sesi login tidak terputus)\n");
    $write("--\n");
    $write("SET NAMES utf8mb4;\n");
    $write("SET FOREIGN_KEY_CHECKS=0;\n");
    $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tables = backup_tables();
    foreach ($tables as $table) {
        $q = backup_quote_ident($table);
        $write("--\n-- Tabel {$q}\n--\n");

        $create = $db->query('SHOW CREATE TABLE ' . $q)->fetch_row();
        $write($create[1] . ";\n\n");
        // Kosongkan tabel (bukan DROP): idempoten, skema tetap, foreign key sudah non-aktif.
        $write('DELETE FROM ' . $q . ";\n");

        $res = $db->query('SELECT * FROM ' . $q);
        $cols = [];
        while ($f = $res->fetch_field()) {
            $cols[] = backup_quote_ident($f->name);
        }
        $ncols = count($cols);
        if ($ncols === 0) { // tabel tanpa kolom (secara teori tidak ada)
            $res->free();
            continue;
        }

        // Bagian kolom hanya dihitung sekali, lalu diulang per baris: hemat CPU
        // tanpa menampung seluruh tabel di memori. Nilai menempel di baris itu sendiri.
        $prefix = 'INSERT INTO ' . $q . ' (' . implode(', ', $cols) . ") VALUES\n";
        $chunk  = [];
        $rows   = 0;
        $flush = static function () use (&$chunk, &$rows, $write, $prefix): void {
            if (!$chunk) {
                return;
            }
            $write($prefix . implode(",\n", $chunk) . ";\n");
            $chunk = [];
            $rows  = 0;
        };

        $res->free();
        $res = $db->query('SELECT * FROM ' . $q);
        while ($row = $res->fetch_row()) {
            $vals = [];
            for ($i = 0; $i < $ncols; $i++) {
                $v = $row[$i];
                // NULL harus ditulis literal NULL, bukan 'NULL' (string).
                $vals[] = $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'";
            }
            $chunk[] = '(' . implode(', ', $vals) . ')';
            if (++$rows >= $batch) {
                $flush();
            }
        }
        $flush();
        $res->free();
        $write("\n");
        fflush($out);
    }

    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    $write('-- Selesai: ' . count($tables) . " tabel, " . date('Y-m-d H:i:s') . "\n");
    fflush($out);

    return count($tables);
}

// ---------------------------------------------------------------------
// Restore
// ---------------------------------------------------------------------

/** Batas ukuran file .sql yang diterima (byte). Naikkan bila dump Anda lebih besar. */
const BACKUP_RESTORE_MAX_BYTES = 33554432; // 32 MB

function backup_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi batas unggah server).',
        UPLOAD_ERR_PARTIAL    => 'Unggahan tidak selesai — coba lagi.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file sementara.',
        UPLOAD_ERR_EXTENSION  => 'Unggahan dihentikan oleh ekstensi server.',
        default               => 'Unggahan gagal (kode ' . $code . ').',
    };
}

/**
 * Validasi file unggahan .sql: kode error, ekstensi, ukuran, isi teks.
 * Mengembalikan pesan error, atau null bila file layak diproses.
 */
function backup_validate_upload(?array $file, int $max_bytes = BACKUP_RESTORE_MAX_BYTES): ?string
{
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        return 'File tidak terkirim dengan benar.';
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return backup_upload_error_message((int) $file['error']);
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return 'File tidak melalui proses unggah yang sah.';
    }
    if (strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'sql') {
        return 'File harus berekstensi .sql.';
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return 'File kosong.';
    }
    if ($size > min($max_bytes, backup_upload_max_bytes())) {
        return 'File terlalu besar (maksimal ' . number_format(min($max_bytes, backup_upload_max_bytes()) / 1048576, 0) . ' MB).';
    }
    // Cek isi: dump yang wajar adalah teks. Cegah .sql yang sebenarnya biner.
    $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4096);
    if ($head === '' || str_contains($head, "\0")) {
        return 'Isi file bukan teks SQL yang valid.';
    }
    return null;
}

/** Batas ukuran efektif dari konfigurasi PHP (0/unlimited -> anggap tak terbatas). */
function backup_upload_max_bytes(): int
{
    $ini = static function (string $key): int {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($v, -1));
        $n = (int) $v;
        return match ($unit) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    };
    return min($ini('upload_max_filesize') ?: PHP_INT_MAX, $ini('post_max_size') ?: PHP_INT_MAX);
}

/**
 * Pecah isi dump jadi daftar statement. Aturan (disengaja sederhana):
 *  - '-' diikuti '-' atau '#' di luar string = komentar sampai akhir baris.
 *  - ';' di luar string/komentar = akhir statement.
 *  - Koma dua / blok komentar C-style tidak didukung.
 * Mengembalikan array of ['sql' => string, 'line' => int].
 */
function backup_split_sql(string $sql): array
{
    $stmts = [];
    $len   = strlen($sql);
    $buf   = '';
    $start_line = 1;
    $line  = 1;
    $in_s  = false;  // di dalam string '...' atau "..."
    $q     = '';     // karakter kutip aktif
    $esc   = false;  // backslash aktif (hanya di dalam string)
    $in_c  = false;  // di dalam komentar

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($ch === "\n") {
            $line++;
            if ($in_c) {
                $in_c = false;
            }
        }
        if ($in_c) {
            continue;
        }

        if ($in_s) {
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === $q) {
                // Dump MySQL meng-escape '' sebagai \' — kutip ganda '' tetap ditangani di sini
                if (($i + 1) < $len && $sql[$i + 1] === $q) {
                    $buf .= $ch . $q;
                    $i++;
                    continue;
                }
                $in_s = false;
            }
            $buf .= $ch;
            continue;
        }

        // Di luar string & komentar
        if ($ch === '-' && ($i + 1) < $len && $sql[$i + 1] === '-' && (($i + 2) >= $len || ctype_space($sql[$i + 2]))) {
            $in_c = true;
            continue;
        }
        if ($ch === '#') {
            $in_c = true;
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $in_s = true;
            $q = $ch;
        } elseif ($ch === ';') {
            if (trim($buf) !== '') {
                $stmts[] = ['sql' => trim($buf), 'line' => $start_line];
            }
            $buf = '';
            $start_line = $line + 1;
            continue;
        }

        if (trim($buf) === '' && !ctype_space($ch)) {
            $start_line = $line;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $stmts[] = ['sql' => trim($buf), 'line' => $start_line];
    }
    return $stmts;
}

/**
 * Jalankan dump, satu statement per query. Mengembalikan [sukses, dijalankan, error].
 *
 * Restore in-place: skema TIDAK disentuh. Statement DDL (CREATE TABLE dari dump sendiri,
 * atau DROP/ALTER dari dump phpMyAdmin) DILEWATI, bukan dijalankan:
 *  - DDL tidak transaksional, jadi bisa merusak jaminan ROLLBACK;
 *  - DROP/CREATE tabel yang sedang dipakai = sesi login putus di tengah request.
 * Hasilnya: hanya data yang dipulihkan, dan seluruh impor berjalan dalam SATU transaksi
 * InnoDB — gagal di tengah = ROLLBACK, database tetap seperti sebelum restore.
 * Pindah server / perubahan skema: impor lewat mysql atau phpMyAdmin, bukan halaman ini.
 */
function backup_import_sql(string $sql): array
{
    $db = db();
    // Koneksi persisten/terpakai ulang bisa masih punya transaksi terbuka: pastikan bersih.
    try { $db->query('ROLLBACK'); } catch (mysqli_sql_exception) {}
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $db->begin_transaction();

    $ok = 0;
    $err = null;
    $skipped = 0;
    $s = ['line' => 0];
    try {
        foreach (backup_split_sql($sql) as $s) {
            if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $s['sql'])) {
                $skipped++;
                continue;
            }
            $db->query($s['sql']);
            $ok++;
        }
    } catch (mysqli_sql_exception $ex) {
        $db->rollback();
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        return [false, $ok, 'Baris ' . $s['line'] . ': ' . $ex->getMessage() . ' — ROLLBACK, database tidak berubah'];
    }

    $db->commit();
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    return [true, $ok, $skipped, null];
}
