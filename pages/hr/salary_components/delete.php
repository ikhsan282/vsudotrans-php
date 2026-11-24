<?php
/** Hapus Master Komponen Gaji (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_permission('payroll.manage');
require_once __DIR__ . '/../../../includes/payroll_service.php';
require_once __DIR__ . '/../../travel/_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
try {
    $row = payroll_component_find($id);
    if (!$row) {
        throw new RuntimeException('Komponen gaji tidak ditemukan.');
    }
    payroll_component_delete($id);
    audit('salary_component.delete', "Hapus komponen #{$id} {$row['code']} — {$row['name']}");
    flash('success', "Komponen gaji '{$row['name']}' berhasil dihapus.");
} catch (Throwable $ex) {
    flash('error', $ex->getMessage());
}
redirect('pages/hr/salary_components/index.php');
