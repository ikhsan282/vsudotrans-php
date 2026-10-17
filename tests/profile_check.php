<?php
/**
 * Self-check modul Profil Saya.
 * Jalankan: php -d mysqli.default_socket=/opt/data/cache/scratch/localdb/mysql.sock tests/profile_check.php
 * Membuat user fixture sendiri (id di luar seed) dan menghapusnya lagi di akhir.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/profile/_helpers.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$db = db();

// --- Fixture: user mandiri, bukan bagian dari seed ---
$UNAME = 'zz_profile_check';
$PASS  = 'P@ssw0rd';
$db->query("DELETE FROM users WHERE username = '$UNAME'");
$hash0 = password_hash($PASS, PASSWORD_DEFAULT);
$st = $db->prepare('INSERT INTO users (username, email, password, full_name, role_id, company_id, is_active) VALUES (?, ?, ?, ?, 4, 1, 1)');
$EMAIL = $UNAME . '@vsudotrans.test';
$FULL  = 'Fixture Profile Check';
$st->bind_param('ssss', $UNAME, $EMAIL, $hash0, $FULL);
$st->execute();
$uid = (int) $db->insert_id;
$st->close();

$cur = fn (): string => (string) $db->query("SELECT password FROM users WHERE id = $uid")->fetch_row()[0];

try {
    $hash = $cur();
    ok($hash === $hash0, 'fixture tersimpan');

    // (a) password lama salah -> tidak berubah
    ok(profile_password_errors('salah-banget', 'PasswordBaru1', 'PasswordBaru1', $hash) !== [], 'password lama salah ditolak (validasi)');
    ok(profile_change_password($db, $uid, 'salah-banget', 'PasswordBaru1', 'PasswordBaru1', $hash) === false, 'change_password menolak password lama salah');
    ok($cur() === $hash, 'hash TIDAK berubah setelah password lama salah');
    ok(password_verify($PASS, $cur()), 'password lama masih berlaku');

    // (b) password baru < 8 karakter -> ditolak
    ok(profile_password_errors($PASS, 'Pendek1', 'Pendek1', $hash) !== [], 'password < 8 ditolak (validasi)');
    ok(profile_change_password($db, $uid, $PASS, 'Pendek1', 'Pendek1', $hash) === false, 'change_password menolak password < 8');
    ok($cur() === $hash, 'hash TIDAK berubah setelah password terlalu pendek');

    // (c) konfirmasi beda -> ditolak
    ok(profile_password_errors($PASS, 'PasswordBaru1', 'PasswordBaru2', $hash) !== [], 'konfirmasi beda ditolak (validasi)');
    ok(profile_change_password($db, $uid, $PASS, 'PasswordBaru1', 'PasswordBaru2', $hash) === false, 'change_password menolak konfirmasi beda');
    ok($cur() === $hash, 'hash TIDAK berubah setelah konfirmasi beda');

    // kolom kosong -> ditolak
    ok(profile_password_errors('', 'PasswordBaru1', 'PasswordBaru1', $hash) !== [], 'semua kolom wajib diisi');
    ok($cur() === $hash, 'hash TIDAK berubah setelah kolom kosong');

    // (d) sukses -> hash berubah & password_verify() true
    $BARU = 'PasswordBaru1';
    ok(profile_change_password($db, $uid, $PASS, $BARU, $BARU, $hash) === true, 'change_password sukses dengan input benar');
    $hash1 = $cur();
    ok($hash1 !== $hash, 'hash di DB berubah');
    ok(password_verify($BARU, $hash1) === true, 'password_verify(password baru) = true');
    ok(password_verify($PASS, $hash1) === false, 'password lama sudah tidak berlaku');
    ok($hash1 !== $BARU && !str_contains($hash1, $BARU), 'DB menyimpan hash, bukan plaintext');

    // ganti lagi memakai hash terbaru -> tetap konsisten
    ok(profile_change_password($db, $uid, $BARU, 'PasswordBaru2', 'PasswordBaru2', $cur()) === true, 'ganti password kedua kali sukses');
    ok(password_verify('PasswordBaru2', $cur()) === true, 'password terbaru berlaku');

    // profil read-only: nama role dari roles.name + perusahaan
    $me = profile_fetch($db, $uid);
    ok($me !== null && $me['role_name'] === 'Staff', "role_name memakai roles.name ('{$me['role_name']}')");
    ok(($me['company_name'] ?? '') !== '', 'company_name terisi');
    ok(!array_key_exists('password', $me), 'profile_fetch tidak mengembalikan hash password');
} finally {
    $db->query("DELETE FROM users WHERE id = $uid");
}
ok($db->query("SELECT id FROM users WHERE id = $uid")->num_rows === 0, 'fixture dibersihkan');

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
