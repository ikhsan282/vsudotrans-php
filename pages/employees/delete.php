<?php
/** Hapus karyawan (POST saja). assert_company_access wajib sebelum hapus. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('employees.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$row = $id > 0 ? employee_find($id) : null;
if (!$row) {
    http_response_code(404);
    exit('Karyawan tidak ditemukan.');
}
assert_company_access((int) $row['company_id']);

$stmt = db()->prepare('DELETE FROM employees WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $id, $row['company_id']);
$stmt->execute();
$stmt->close();

audit('employee.delete', "Hapus karyawan {$row['code']} — {$row['name']} (id {$id})");
flash('success', 'Karyawan berhasil dihapus.');
redirect('pages/employees/index.php');
