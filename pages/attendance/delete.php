<?php
/** Hapus satu absensi (POST + CSRF). assert_company_access wajib sebelum hapus. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$row = $id > 0 ? attendance_find($id) : null;
if (!$row) {
    http_response_code(404);
    exit('Absensi tidak ditemukan.');
}
assert_company_access((int) $row['company_id']);

$stmt = db()->prepare('DELETE FROM employee_attendances WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $id, $row['company_id']);
$stmt->execute();
$stmt->close();

audit('attendance.delete', "Hapus absensi #{$id} — {$row['employee_code']} {$row['date']}");
flash('success', 'Absensi berhasil dihapus.');
redirect('pages/attendance/index.php');
