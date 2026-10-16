<?php
/**
 * Self-check: halaman cetak invoice (HTTP + scoping).
 * Butuh DB dev + server HTTP untuk worktree ini.
 * Jalankan: php -d mysqli.default_socket=... tests/invoice_pdf_check.php
 * ENV: IV_BASE (default http://127.0.0.1:8091/invoice-pdf), IV_USER, IV_PASS_FILE.
 */

require_once __DIR__ . '/../config/config.php';

$fails = 0;
function ok(bool $c, string $msg): void { global $fails; echo ($c ? '  ok  ' : '  FAIL') . "  $msg\n"; if (!$c) $fails++; }

$base      = rtrim(getenv('IV_BASE') ?: 'http://127.0.0.1:8091/invoice-pdf', '/');
$user      = getenv('IV_USER') ?: 'admin';
$pass_file = getenv('IV_PASS_FILE') ?: '/opt/data/cache/scratch/iv_pass.txt';
$pass      = is_readable($pass_file) ? trim((string) file_get_contents($pass_file)) : 'P@ssw0rd';

/** HTTP via curl CLI (ekstensi curl PHP tidak ada), cookie jar per sesi. */
function http(string $url, ?array $post = null, ?string $jar = null): array
{
    $cmd = 'curl -s -m 20 -w "\\n%{http_code}"';
    if ($jar !== null) { $cmd .= ' -b ' . escapeshellarg($jar) . ' -c ' . escapeshellarg($jar); }
    if ($post !== null) {
        foreach ($post as $k => $v) { $cmd .= ' --data-urlencode ' . escapeshellarg($k . '=' . $v); }
    } else {
        $cmd .= ' -G';
    }
    $cmd .= ' ' . escapeshellarg($url);
    $raw   = (string) shell_exec($cmd);
    $pos   = strrpos($raw, "\n");
    $body  = substr($raw, 0, $pos !== false ? $pos : strlen($raw));
    $code  = (int) trim(substr($raw, $pos !== false ? $pos + 1 : 0));
    return ['code' => $code, 'body' => $body];
}

$c = db();
$inv = $c->query("SELECT i.id, i.company_id, i.invoice_no, i.total FROM invoices i ORDER BY i.id LIMIT 1")->fetch_assoc();
if (!$inv) { echo "skip: tidak ada invoice; buat booking dulu\n"; exit(0); }
$inv_id  = (int) $inv['id'];
$inv_no  = (string) $inv['invoice_no'];
$inv_tot = rupiah((float) $inv['total']);
$cid     = (int) $inv['company_id'];

// --- Login admin ---
$jar = '/opt/data/cache/scratch/iv_test_jar.txt';
@unlink($jar);
$login_page  = http($base . '/pages/auth/login.php', null, $jar);
ok($login_page['code'] === 200, "halaman login HTTP 200 ({$login_page['code']})");
if (preg_match('/name="csrf_token" value="([^"]+)"/', $login_page['body'], $m)) {
    $tok = $m[1];
} else {
    ok(false, 'csrf_token tidak ditemukan di form login');
    $tok = '';
}
$lp = http($base . '/pages/auth/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => $pass], $jar);
ok($lp['code'] === 302, "login POST redirect 302 ({$lp['code']})");

// --- 1. print.php render ---
$r = http($base . '/pages/finance/invoices/print.php?id=' . $inv_id, null, $jar);
ok($r['code'] === 200, "print.php HTTP 200 ({$r['code']})");
ok(str_contains($r['body'], 'INVOICE'), 'body memuat kata "INVOICE"');
ok(str_contains($r['body'], $inv_no), "body memuat nomor invoice nyata ($inv_no)");
ok(str_contains($r['body'], $inv_tot), "body memuat total rupiah ($inv_tot)");

// --- 2. mandiri: tanpa CDN Bootstrap ---
ok(!str_contains($r['body'], 'jsdelivr'), 'body TIDAK memuat jsdelivr (mandiri)');
ok(!str_contains($r['body'], 'bootstrap.min.css'), 'body TIDAK memuat CDN Bootstrap CSS');
ok(!preg_match('/<script\s+src=/i', $r['body']) || !str_contains($r['body'], 'bootstrap'), 'tidak ada <script src=> Bootstrap');

// --- 3. scoping: invoice company lain tidak bocor ---
$other = null;
$q = $c->query('SELECT id FROM companies WHERE id <> ' . $cid . ' ORDER BY id LIMIT 1');
if ($q && $q->num_rows) { $other = (int) $q->fetch_row()[0]; }

$temp_inv_id = 0;
if ($other === null) {
    // Buat perusahaan + invoice sementara milik company_id berbeda untuk menguji scoping.
    $c->query("INSERT INTO companies (group_id, code, name, is_active, created_date) VALUES (1, 'ZZ-TEST', 'ZZ Test', 1, NOW())");
    $other = (int) $c->insert_id;
}
// Invoice sementara milik company lain (tanpa FK booking; hanya untuk uji isolasi cetak).
$code = 'ZZ' . random_int(1000, 9999);
$c->query("INSERT INTO invoices (company_id, booking_type, booking_id, invoice_no, document_number, status, total, issued_at, created_date)
    VALUES ($other, 'travel', 0, 'INV/ZZ/$code', 'ZZ-$code', 'issued', 999999, NOW(), NOW())");
$temp_inv_id = (int) $c->insert_id;

$leak = http($base . '/pages/finance/invoices/print.php?id=' . $temp_inv_id, null, $jar);
ok($leak['code'] === 403, "invoice company lain -> 403 (dapat {$leak['code']})");
ok(!str_contains($leak['body'], 'INV/ZZ/' . $code), 'nomor invoice company lain TIDAK bocor');

// bersihkan data uji
$c->query('DELETE FROM invoices WHERE id = ' . $temp_inv_id);
if (getenv('IV_KEEP_COMPANY') !== '1') {
    // hapus hanya perusahaan sementara yang kita buat (kode ZZ-TEST)
    $c->query("DELETE FROM companies WHERE code = 'ZZ-TEST'");
}

// --- 4. halaman belum login wajib ditolak ---
$anon = http($base . '/pages/finance/invoices/print.php?id=' . $inv_id);
ok($anon['code'] === 302 || $anon['code'] === 403, "tanpa login -> dialihkan/ditolak ({$anon['code']})");
ok(!str_contains($anon['body'], $inv_no), 'tanpa login: nomor invoice tidak tampil');

echo $fails ? "\n$fails GAGAL\n" : "\nSEMUA LULUS\n";
exit($fails ? 1 : 0);
