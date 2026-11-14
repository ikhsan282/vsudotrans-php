<?php
/**
 * Rekap absensi per karyawan pada rentang tanggal: hadir/izin/sakit/cuti/alpa + total hari tercatat.
 * Angka dihitung on-the-fly (tidak ada tabel ringkasan) dan TIDAK menyentuh payroll.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.view');
attendance_scope_cid();

$cid = active_company_id();
[$from, $to] = attendance_range($_GET);
$rows = attendance_summary($from, $to, $cid);

// Total baris rekap = jumlah karyawan (termasuk yang 0 hari), dijumlahkan setelah loop di bawah.
$grand = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'alpa' => 0, 'total' => 0];
foreach ($rows as $r) {
    foreach ($grand as $k => $_) {
        $grand[$k] += (int) $r[$k];
    }
}

// ── Export CSV / XLSX / PDF / cetak ──────────────────────────────────────────
$fmt = (string) ($_GET['format'] ?? '');
if (in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true)) {
    $table = [['Kode', 'Karyawan', 'Hadir', 'Izin', 'Sakit', 'Cuti', 'Alpa', 'Total Hari']];
    foreach ($rows as $r) {
        $table[] = [$r['code'], $r['name'], (int) $r['hadir'], (int) $r['izin'],
                    (int) $r['sakit'], (int) $r['cuti'], (int) $r['alpa'], (int) $r['total']];
    }
    $table[] = ['', 'TOTAL', $grand['hadir'], $grand['izin'], $grand['sakit'],
                $grand['cuti'], $grand['alpa'], $grand['total']];

    $base = 'Rekap Absensi ' . $from . ' s-d ' . $to;
    $sub  = tanggal_indo($from) . ' s/d ' . tanggal_indo($to);

    if ($fmt === 'pdf' || $fmt === 'html') {
        require_once __DIR__ . '/../reports/_print.php';
        rpt_send($table, 'Rekap Absensi', $base, $sub, null, 'portrait', 'attendance.summary.print');
    }
    if ($fmt === 'csv') {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $ascii) . '.csv"; filename*=UTF-8\'\'' . rawurlencode($base . '.csv'));
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        foreach ($table as $row) fputcsv($out, $row, ',', '"', '');
        fclose($out);
        audit('attendance.summary.print', $base . ' (CSV)');
        exit;
    }
    require_once __DIR__ . '/../../includes/xlsx.php';
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $base);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $ascii) . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode($base . '.xlsx'));
    header('Cache-Control: no-store');
    audit('attendance.summary.print', $base . ' (XLSX)');
    echo xlsx_build($table, 'Rekap Absensi');
    exit;
}

$active = 'attendance';
$title  = 'Rekap Absensi';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Rekap Absensi</h4>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary" href="index.php?from=<?= e($from) ?>&to=<?= e($to) ?>">
                <i class="bi bi-list-ul"></i> Absensi Harian</a>
            <a class="btn btn-sm btn-outline-success" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&format=csv">
                <i class="bi bi-filetype-csv"></i> CSV</a>
            <a class="btn btn-sm btn-outline-success" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&format=xlsx">
                <i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="btn btn-sm btn-outline-danger" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&format=pdf">
                <i class="bi bi-file-earmark-pdf"></i> PDF</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i> Cetak</button>
        </div>
    </div>

    <?= flash_html() ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-6 col-lg-4">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($from) ?>">
                </div>
                <div class="col-6 col-lg-4">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($to) ?>">
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/attendance/summary.php', ($from !== date('Y-m-01') || $to !== date('Y-m-t'))) ?>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white small text-muted">
            <?= e(tanggal_indo($from)) ?> s/d <?= e(tanggal_indo($to)) ?> — <?= count($rows) ?> karyawan aktif
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead><tr>
                    <th>Karyawan</th>
                    <th class="text-end">Hadir</th>
                    <th class="text-end">Izin</th>
                    <th class="text-end">Sakit</th>
                    <th class="text-end">Cuti</th>
                    <th class="text-end">Alpa</th>
                    <th class="text-end">Total Hari Tercatat</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="fw-semibold"><?= e($r['code']) ?></span> — <?= e($r['name']) ?></td>
                        <?php foreach (['hadir', 'izin', 'sakit', 'cuti', 'alpa'] as $k): ?>
                            <td class="text-end"><?= (int) $r[$k] ?></td>
                        <?php endforeach; ?>
                        <td class="text-end fw-semibold"><?= (int) $r['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada karyawan aktif.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total</td>
                        <?php foreach (['hadir', 'izin', 'sakit', 'cuti', 'alpa', 'total'] as $k): ?>
                            <td class="text-end"><?= $grand[$k] ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
