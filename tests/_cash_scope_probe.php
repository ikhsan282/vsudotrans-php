<?php
/**
 * Probe scoping Buku Kas — dijalankan sebagai SUBPROSES oleh tests/cash_check.php
 * karena assert_company_access() memanggil exit, yang akan menghentikan proses induk.
 *
 * Pakai: php tests/_cash_scope_probe.php tx <id>
 * Mencetak "Akses Ditolak" bila request berhenti di 403, "BOLEH AKSES" bila baris
 * ternyata boleh diakses (berarti scoping bocor).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../pages/finance/_cash_query.php';

// Sesi disuntik langsung: user perusahaan 1, bukan Super Admin.
$_SESSION['username'] = 'cash-probe';
$_SESSION['role_name'] = 'Admin Perusahaan';
$_SESSION['company_id'] = 1;

$what = $argv[1] ?? '';
$id   = (int) ($argv[2] ?? 0);

if ($what === 'tx') {
    cash_tx_find($id);
} else {
    fwrite(STDERR, "pakai: tx <id>\n");
    exit(2);
}

echo "BOLEH AKSES\n";
exit(0);
