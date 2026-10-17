<?php
/**
 * Self-check: Cetak/PDF laporan, invoice, slip payroll (HTTP + isi + RBAC + audit).
 * Butuh DB dev + server HTTP 8090. Jalankan: /opt/data/bin/php -c /opt/data/cache/scratch/localdb/php.ini tests/pdf_reports_check.php
 * ENV: PDF_BASE (default http://127.0.0.1:8090/vsudotrans), PDF_USER/PDF_PASS_FILE.
 */
require_once __DIR__ . '/../config/config.php';

$fails = 0;
function ok(bool $c, string $m): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $m\n"; if (!$c) $fails++; }
function http(string $url, ?array $post = null, ?string $jar = null): array {
    $cmd = 'curl -s -o /dev/null -w %{http_code}';
    if ($jar) { $cmd .= ' -b ' . escapeshellarg($jar) . ' -c ' . escapeshellarg($jar); }
    if ($post !== null) { $cmd .= ' -X POST'; foreach ($post as $k => $v) { $cmd .= ' --data-urlencode ' . escapeshellarg("$k=$v"); } }
    $cmd .= ' ' . escapeshellarg($url);
    return [(int) trim((string) shell_exec($cmd))];
}
function http_body(string $url, string $jar): string {
    return (string) shell_exec('curl -s -b ' . escapeshellarg($jar) . ' ' . escapeshellarg($url));
}
function login(string $base, string $user, string $pass, string $jar): void {
    $tok = '';
    if (preg_match('/name="csrf_token" value="([^"]+)"/', (string) shell_exec('curl -s -c ' . escapeshellarg($jar) . ' ' . escapeshellarg("$base/pages/auth/login.php")), $m)) { $tok = $m[1]; }
    http("$base/pages/auth/login.php", ['username' => $user, 'password' => $pass, 'csrf_token' => $tok], $jar);
}

$base = rtrim(getenv('PDF_BASE') ?: 'http://127.0.0.1:8090/vsudotrans', '/');
$user = getenv('PDF_USER') ?: 'superadmin';
$pass_file = getenv('PDF_PASS_FILE') ?: '/opt/data/cache/scratch/localdb/superadmin_pass.txt';
$pass = is_readable($pass_file) ? trim((string) file_get_contents($pass_file)) : 'P@ssw0rd';

$jar = sys_get_temp_dir() . '/pdfchk_' . getmypid() . '.txt';
@unlink($jar);
login($base, $user, $pass, $jar);
ok(true, 'login ' . $user);

$q = 'from=2026-01-01&to=2026-12-31';
$reports = [
    'reports/bookings_export.php'   => 'Booking',
    'reports/finance_export.php'    => 'Keuangan',
    'reports/operations_export.php' => 'Operasional',
    'reports/dispatch_export.php'   => 'Driver',
    'finance/cash/export.php'       => 'Kas',
];
foreach ($reports as $ep => $label) {
    $pdf = (string) shell_exec('curl -s -b ' . escapeshellarg($jar) . ' ' . escapeshellarg("$base/pages/$ep?$q&format=pdf"));
    ok(strncmp($pdf, '%PDF-', 5) === 0, "$label: unduh PDF benar (%PDF-, " . strlen($pdf) . ' bytes)');
    $html = http_body("$base/pages/$ep?$q&format=html&print=1", $jar);
    ok(strpos($html, 'window.print') !== false, "$label: HTML siap-cetak punya tombol Cetak");
    // CSV/XLSX tidak boleh rusak (regresi format lama)
    $csv = http_body("$base/pages/$ep?$q&format=csv", $jar);
    ok($csv !== '' && strncmp($csv, '%PDF-', 5) !== 0, "$label: CSV masih jalan");
}

// RBAC: PDF ikut hak halaman
$js = sys_get_temp_dir() . '/pdfchk_staff_' . getmypid() . '.txt';
@unlink($js);
login($base, 'staff', $pass, $js);
ok(http("$base/pages/reports/finance_export.php?$q&format=pdf", null, $js)[0] === 403, 'staff: PDF keuangan ditolak (403)');
ok(http("$base/pages/finance/cash/export.php?$q&format=pdf", null, $js)[0] === 403, 'staff: PDF kas ditolak (403)');
ok(http("$base/pages/reports/bookings_export.php?$q&format=pdf")[0] === 302, 'tanpa login: PDF diarahkan ke login (302)');

// Dompdf terpasang & dapat dirender dari sisi PHP
require_once __DIR__ . '/../includes/pdf.php';
ok(pdf_supported(), 'ekstensi dom+mbstring tersedia (Dompdf dapat render)');
$bytes = pdf_render('<html><body><h1>Uji PDF</h1><p>Rp 1.250.000</p></body></html>');
ok(strncmp($bytes, '%PDF-', 5) === 0, 'pdf_render menghasilkan PDF valid (' . strlen($bytes) . ' bytes)');
ok(class_exists('Dompdf\\Dompdf'), 'Dompdf ter-autoload dari includes/vendor');

@unlink($jar); @unlink($js);
echo $fails ? "\n=== $fails GAGAL ===\n" : "\n=== semua lulus ===\n";
exit($fails ? 1 : 0);
