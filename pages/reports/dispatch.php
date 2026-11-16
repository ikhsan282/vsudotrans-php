<?php
/**
 * Laporan Penjadwalan Driver: beban kerja tiap driver + detail penugasan
 * per periode, dari `trip_assignments`.
 * Filter from/to/booking_type seperti Laporan Operasional.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_dispatch_query.php';

$f = report_ui_filter($_GET);
$data = dispatch_all($f);
$header_actions = report_header_actions('pages/reports/dispatch_export.php', $f);

$sum = $data['summary'];
$drv = $data['drivers'];
$det = $data['details'];
$dt  = dispatch_totals($drv, ['tugas', 'selesai', 'berjalan', 'hari']);

$cards = [
    ['Total Penugasan',      $sum['total'],    'primary', 'clipboard-check'],
    ['Selesai',              $sum['selesai'],  'success', 'check2-circle'],
    ['Sedang Jalan',         $sum['berjalan'], 'warning', 'hourglass-split'],
    ['Driver Aktif',         $sum['driver'],   'info',    'person-badge'],
];

$active = 'reports';
$title  = 'Laporan Penjadwalan Driver';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Laporan Penjadwalan Driver',
        ['Dashboard' => 'pages/dashboard/index.php', 'Laporan' => null, 'Penjadwalan Driver' => null],
        [['text' => tanggal_indo($f['from']) . ' – ' . tanggal_indo($f['to']), 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <?= report_company_filter_col($f) ?>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Jenis Booking</label>
                    <select class="form-select form-select-sm" name="booking_type">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= ui_filter_actions(APP_URL . '/pages/reports/dispatch.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['booking_type'] !== 'all' || (is_super_admin() && !empty($f['filter_company_id'])))) ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ($cards as [$lbl, $val, $clr, $icon]): ?>
            <div class="col-6 col-lg-3">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small"><?= e($lbl) ?></div>
                            <div class="fs-5 fw-bold text-<?= e($clr) ?>"><?= number_format((int) $val, 0, ',', '.') ?></div>
                        </div>
                        <i class="bi bi-<?= e($icon) ?> fs-3 text-<?= e($clr) ?> opacity-50"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Beban Kerja per Driver</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Driver</th><th>Kode</th>
                    <th class="text-end">Tugas</th><th class="text-end">Selesai</th>
                    <th class="text-end">Berjalan</th><th class="text-end">Hari Bertugas</th>
                </tr></thead>
                <tbody>
                <?php foreach ($drv as $r): ?>
                    <tr>
                        <td><?= e((string) $r['name']) ?></td>
                        <td class="text-muted small"><?= e((string) $r['code']) ?></td>
                        <td class="text-end"><?= (int) $r['tugas'] ?></td>
                        <td class="text-end"><?= (int) $r['selesai'] ?></td>
                        <td class="text-end"><?= (int) $r['berjalan'] ?></td>
                        <td class="text-end"><?= (int) $r['hari'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$drv): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Tidak ada penugasan driver pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= (int) $dt['tugas'] ?></td>
                        <td class="text-end"><?= (int) $dt['selesai'] ?></td>
                        <td class="text-end"><?= (int) $dt['berjalan'] ?></td>
                        <td class="text-end"><?= (int) $dt['hari'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Detail Penugasan <?= e($f['from']) ?> s/d <?= e($f['to']) ?></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Mulai</th><th>Driver</th><th>Kendaraan</th><th>Jenis</th>
                    <th>No. Dokumen</th><th>Status</th><th class="text-end">Durasi</th><th>Catatan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($det as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(dispatch_when($r['started_at'])) ?></td>
                        <td><?= e((string) ($r['driver_name'] ?? '-')) ?></td>
                        <td><?= e((string) ($r['vehicle_number'] ?? '-')) ?></td>
                        <td><?= e($r['booking_type'] === 'rental' ? 'Rental' : 'Travel') ?></td>
                        <td><?= e((string) ($r['document_number'] ?? '-')) ?></td>
                        <td>
                            <?php $done = $r['ended_at'] !== null; ?>
                            <span class="badge text-bg-<?= $done ? 'success' : 'warning' ?>">
                                <?= e(dispatch_status_label($r['ended_at'])) ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap"><?= e(dispatch_duration_label(dispatch_duration($r['started_at'], $r['ended_at']))) ?></td>
                        <td class="text-muted small"><?= e((string) ($r['reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$det): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">Tidak ada penugasan pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
