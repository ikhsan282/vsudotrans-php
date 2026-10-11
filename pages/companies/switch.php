<?php
/**
 * Ganti perusahaan aktif via POST. switch_company() memvalidasi akses.
 */
require_once __DIR__ . '/../../config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/dashboard/index.php');
}

verify_csrf();

$cid = (int) ($_POST['company_id'] ?? 0);
if ($cid > 0 && switch_company($cid)) {
    audit('switch_company', 'Ganti perusahaan aktif ke #' . $cid);
    flash('success', 'Perusahaan aktif berhasil diganti.');
} else {
    flash('error', 'Perusahaan tidak dapat diakses.');
}

// Redirect ke path tetap: HTTP_REFERER bisa diatur klien (open redirect).
redirect('pages/dashboard/index.php');
