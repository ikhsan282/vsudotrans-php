<?php
/**
 * Modul Profil Saya: ganti password sendiri.
 * Tanpa DB: semua fungsi di sini murni (dipakai tests/profile_check.php).
 */

/**
 * Validasi input ganti password.
 * $hash = hash password user saat ini (dari DB).
 * Mengembalikan daftar pesan error; kosong = lolos.
 */
function profile_password_errors(string $lama, string $baru, string $konfirmasi, string $hash): array
{
    $errors = [];
    if ($lama === '' || $baru === '' || $konfirmasi === '') {
        $errors[] = 'Semua kolom password wajib diisi.';
        return $errors; // tanpa isi lengkap, verifikasi lama/panjang tidak bermakna
    }
    if (!password_verify($lama, $hash)) {
        $errors[] = 'Password lama tidak sesuai.';
    }
    if (strlen($baru) < 8) {
        $errors[] = 'Password baru minimal 8 karakter.';
    }
    if ($baru !== $konfirmasi) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }
    return $errors;
}

/** Ganti password user; $hash saat ini dipakai untuk verifikasi. true = berhasil. */
function profile_change_password(mysqli $db, int $user_id, string $lama, string $baru, string $konfirmasi, string $hash): bool
{
    if (profile_password_errors($lama, $baru, $konfirmasi, $hash) !== []) {
        return false;
    }
    $new_hash = password_hash($baru, PASSWORD_DEFAULT);
    $st = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
    $st->bind_param('si', $new_hash, $user_id);
    $ok = $st->execute();
    $st->close();
    return $ok;
}

/** Data akun sendiri + nama role + nama perusahaan (untuk ditampilkan read-only). */
function profile_fetch(mysqli $db, int $user_id): ?array
{
    $st = $db->prepare('SELECT u.id, u.username, u.email, u.full_name, u.phone_number, u.last_login,
                               r.name AS role_name, c.name AS company_name
                        FROM users u
                        JOIN roles r ON r.id = u.role_id
                        LEFT JOIN companies c ON c.id = u.company_id
                        WHERE u.id = ? LIMIT 1');
    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}
