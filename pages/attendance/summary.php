<?php
/**
 * Rekap absensi per karyawan pada rentang tanggal: hadir/izin/sakit/cuti/alpa + total hari tercatat.
 * Angka dihitung on-the-fly (tidak ada tabel ringkasan) dan TIDAK menyentuh payroll.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/transport_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.view');
if (!is_super_admin() && active_company_id() === null) {
    attendance_scope_cid();
}

$compScope     = company_filter_scope('e.company_id');
$filter_cid    = (int) $compScope['filter_company_id'];
$show_comp_col = is_super_admin() && $filter_cid === 0;
$cid           = active_company_id();
[$from, $to] = attendance_range($_GET);
$rows = attendance_summary($from, $to, $cid, is_super_admin() ? $filter_cid : null);

// Total baris rekap = jumlah karyawan (termasuk yang 0 hari), dijumlahkan setelah loop di bawah.
$grand = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'alpa' => 0, 'total' => 0];
foreach ($rows as $r) {
    foreach ($grand as $k => $_) {
        $grand[$k] += (int) $r[$k];
    }
}

$qs_exp = 'from=' . urlencode($from) . '&to=' . urlencode($to) . (is_super_admin() ? '&company_id=' . $filter_cid : '');

// ── Export CSV / XLSX / PDF / cetak ──────────────────────────────────────────
$fmt = (string) ($_GET['format'] ?? '');
if (in_array($fmt, ['csv', 'xlsx', 'pdf', 'html'], true)) {
    $table = [$show_comp_col
        ? ['Perusahaan', 'Kode', 'Karyawan', 'Hadir', 'Izin', 'Sakit', 'Cuti', 'Alpa', 'Total Hari']
        : ['Kode', 'Karyawan', 'Hadir', 'Izin', 'Sakit', 'Cuti', 'Alpa', 'Total Hari']];
    foreach ($rows as $r) {
        $baseRow = [$r['code'], $r['name'], (int) $r['hadir'], (int) $r['izin'],
            (int) $r['sakit'], (int) $r['cuti'], (int) $r['alpa'], (int) $r['total']];
        if ($show_comp_col) {
            array_unshift($baseRow, (string) ($r['company_code'] ?? '-'));
        }
        $table[] = $baseRow;
    }
    $totRow = ['', 'TOTAL', $grand['hadir'], $grand['izin'], $grand['sakit'],
        $grand['cuti'], $grand['alpa'], $grand['total']];
    if ($show_comp_col) {
        array_unshift($totRow, '');
    }
    $table[] = $totRow;

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

$sum_actions = [
    ['label' => 'Absensi Harian', 'icon' => 'list-ul',            'href' => 'pages/attendance/index.php?' . $qs_exp,               'class' => 'btn btn-outline-secondary btn-sm'],
    ['label' => 'CSV',            'icon' => 'filetype-csv',       'href' => 'pages/attendance/summary.php?' . $qs_exp . '&format=csv',  'class' => 'btn btn-outline-success btn-sm'],
    ['label' => 'Excel',          'icon' => 'file-earmark-excel', 'href' => 'pages/attendance/summary.php?' . $qs_exp . '&format=xlsx', 'class' => 'btn btn-success btn-sm'],
    ['label' => 'PDF',            'icon' => 'filetype-pdf',       'href' => 'pages/attendance/summary.php?' . $qs_exp . '&format=pdf',  'class' => 'btn btn-danger btn-sm'],
];

$active = 'attendance';
$title  = 'Rekap Absensi';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Rekap Absensi',
        ['Dashboard' => 'pages/dashboard/index.php', 'Absensi' => 'pages/attendance/index.php', 'Rekap' => null],
        [['text' => tanggal_indo($from) . ' – ' . tanggal_indo($to), 'class' => 'bg-light text-secondary border']],
        $sum_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <?php if (is_super_admin()): ?>
                <div class="col-12 col-lg-3">
                    <label class="form-label small mb-1">Perusahaan</label>
                    <select class="form-select form-select-sm" name="company_id" onchange="this.form.submit()">
                        <option value="0"<?= $filter_cid === 0 ? ' selected' : '' ?>>Semua Perusahaan</option>
                        <?php foreach ($compScope['companies'] as $co): ?>
                            <option value="<?= (int) $co['id'] ?>"<?= $filter_cid === (int) $co['id'] ? ' selected' : '' ?>>
                                <?= e(($co['code'] ?? '') . ' - ' . ($co['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($from) ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($to) ?>">
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/attendance/summary.php', ($from !== date('Y-m-01') || $to !== date('Y-m-t') || (is_super_admin() && $filter_cid > 0))) ?>
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
                    <?php if ($show_comp_col): ?><th>Perusahaan</th><?php endif; ?>
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
                        <?php if ($show_comp_col): ?><td><span class="badge bg-light text-dark border"><?= e((string) ($r['company_code'] ?? '-')) ?></span></td><?php endif; ?>
                        <td><span class="fw-semibold"><?= e($r['code']) ?></span> — <?= e($r['name']) ?></td>
                        <?php foreach (['hadir', 'izin', 'sakit', 'cuti', 'alpa'] as $k): ?>
                            <td class="text-end"><?= (int) $r[$k] ?></td>
                        <?php endforeach; ?>
                        <td class="text-end fw-semibold"><?= (int) $r['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $show_comp_col ? 8 : 7 ?>" class="text-center text-muted py-4">Belum ada karyawan aktif.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td<?= $show_comp_col ? ' colspan="2"' : '' ?>>Total</td>
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
