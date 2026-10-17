<?php
/**
 * Backup & Restore database — KHUSUS Super Admin (memuat seluruh data lintas perusahaan).
 * Murni PHP: dump + impor lewat MySQLi, tanpa mysqldump/exec (shared hosting cPanel).
 *
 * Dua aksi:
 *  - ?action=download (GET) : stream dump SQL sebagai unduhan.
 *  - POST action=restore    : impor file .sql yang diunggah — DESTRUKTIF.
 *
 * Gate: is_super_admin() langsung, bukan perm string (lihat catatan di sidebar.php).
 */
require_once __DIR__ . '/../../config/config.php';

if (!is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}
require_once __DIR__ . '/../../includes/backup.php';

$max_bytes = min(BACKUP_RESTORE_MAX_BYTES, backup_upload_max_bytes());
$errors    = [];

// ---------------------------------------------------------------------
// Unduhan dump
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'download') {
    $filename = 'vsudotrans-backup-' . date('Ymd-His') . '.sql';

    // Tidak ada header Content-Length: dump di-stream, panjangnya tidak dihitung dua kali.
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) {
        ob_end_clean(); // keluaran langsung: tidak menahan dump besar di buffer PHP
    }

    backup_dump_to_stream();
    audit('database.backup', 'Unduh backup database: ' . $filename);
    exit;
}

// ---------------------------------------------------------------------
// Restore dari unggahan
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') !== 'restore') {
        $errors[] = 'Aksi tidak dikenal.';
    } elseif (($_POST['confirm_overwrite'] ?? '') !== 'yes') {
        $errors[] = 'Konfirmasi "Timpa seluruh data" belum dicentang.';
    } else {
        $file = $_FILES['sql_file'] ?? null;
        $err  = backup_validate_upload($file, $max_bytes);
        if ($err !== null) {
            $errors[] = $err;
        } else {
            // Salin ke lokasi sementara bernama acak (nama unggahan tidak dipakai), lalu
            // hapus setelah selesai — juga saat impor gagal.
            $tmp = sys_get_temp_dir() . '/vsudo_restore_' . bin2hex(random_bytes(8)) . '.sql';
            if (!move_uploaded_file($file['tmp_name'], $tmp)) {
                $errors[] = 'Gagal menyimpan file unggahan ke lokasi sementara.';
            } else {
                $sql = (string) file_get_contents($tmp);
                @unlink($tmp);
                [$sukses, $jumlah, $error] = backup_import_sql($sql);
                if ($sukses) {
                    audit('database.restore', 'Restore database dari ' . $file['name'] . " ({$jumlah} statement)");
                    flash('success', "Restore selesai: {$jumlah} statement dieksekusi.");
                    redirect('pages/settings/backup.php');
                }
                $errors[] = 'Restore GAGAL pada ' . $error
                    . '. Statement sebelum baris itu sudah ter-commit; pulihkan dari file backup bila perlu.';
            }
        }
    }
}

$active = 'system';
$title  = 'Backup & Restore Database';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h6 class="fw-bold"><i class="bi bi-download me-1"></i>Backup</h6>
                    <p class="text-muted small mb-3">
                        Menghasilkan file <code>.sql</code> (<code>CREATE TABLE</code> + <code>INSERT</code>)
                        untuk seluruh <?= count(backup_tables()) ?> tabel, semua perusahaan.
                        Simpan di tempat aman — isinya termasuk password user yang sudah di-hash.
                    </p>
                    <a class="btn btn-primary" href="?action=download"><i class="bi bi-download me-1"></i>Unduh Backup Sekarang</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-danger h-100">
                <div class="card-body">
                    <h6 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Restore</h6>
                    <p class="text-muted small mb-3">
                        Mengimpor file <code>.sql</code> dan <strong>MENIMPA seluruh data</strong> di database ini.
                        Tidak bisa dibatalkan — unduh backup dulu.
                        Maksimal <?= number_format($max_bytes / 1048576, 0) ?> MB.
                    </p>
                    <form method="post" action="" enctype="multipart/form-data"
                          onsubmit="return confirm('Timpa seluruh data database dengan isi file ini?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="restore">
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="sql_file">File .sql</label>
                            <input class="form-control" type="file" id="sql_file" name="sql_file" accept=".sql" required>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm_overwrite" name="confirm_overwrite" value="yes" required>
                            <label class="form-check-label small" for="confirm_overwrite">
                                Timpa seluruh data? — saya mengerti restore bersifat <strong>destruktif</strong>.
                            </label>
                        </div>
                        <button class="btn btn-danger" type="submit"><i class="bi bi-upload me-1"></i>Mulai Restore</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
