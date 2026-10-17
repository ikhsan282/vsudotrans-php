<?php
/**
 * Laporan Penjadwalan Driver: beban kerja tiap driver + detail penugasan
 * per periode, dari `trip_assignments`.
 * Filter from/to/booking_type seperti Laporan Operasional.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_dispatch_query.php';

$f = report_filter($_GET);
$data = dispatch_all($f);

$export_qs = http_build_query([
    'format'       => 'csv',
    'from'         => $f['from'],
    'to'           => $f['to'],
    'booking_type' => $f['booking_type'],
]);
$xlsx_qs = str_replace('format=csv', 'format=xlsx', $export_qs);

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
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Laporan Penjadwalan Driver</h4>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-success" href="dispatch_export.php?<?= e($export_qs) ?>">
                <i class="bi bi-filetype-csv"></i> CSV</a>
            <a class="btn btn-success" href="dispatch_export.php?<?= e($xlsx_qs) ?>">
                <i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="btn btn-danger" href="dispatch_export.php?<?= e($export_qs) ?>&format=pdf">
                <i class="bi bi-filetype-pdf"></i> PDF</a>
            <a class="btn btn-outline-secondary" href="dispatch_export.php?<?= e($export_qs) ?>&format=html&print=1" target="_blank">
                <i class="bi bi-printer"></i> Cetak</a>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-3">
                    <label class="form-label small mb-1">Jenis Booking</label>
                    <select class="form-select form-select-sm" name="booking_type">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-3">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Terapkan</button>
                </div>
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
