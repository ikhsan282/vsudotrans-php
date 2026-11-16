<?php
/**
 * Laporan Operasional: utilisesi kendaraan, aktivitas driver, tingkat
 * penyelesaian tugas, dan sebaran booking per status.
 * Filter from/to/booking_type seperti Laporan Booking.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_operations_query.php';

$f = report_ui_filter($_GET);
$data = ops_all($f);
$header_actions = report_header_actions('pages/reports/operations_export.php', $f);

$comp   = $data['completion'];
$veh    = $data['vehicles'];
$drv    = $data['drivers'];
$status = $data['status'];
$vt     = ops_totals($veh, ['tugas', 'selesai', 'berjalan', 'hari']);
$dt     = ops_totals($drv, ['tugas', 'selesai', 'berjalan']);
$st     = ops_totals($status, ['travel', 'rental', 'total']);

$cards = [
    ['Total Penugasan', number_format($comp['total'], 0, ',', '.'), 'primary', 'clipboard-check'],
    ['Selesai',         number_format($comp['selesai'], 0, ',', '.'), 'success', 'check2-circle'],
    ['Masih Berjalan',  number_format($comp['berjalan'], 0, ',', '.'), 'warning', 'hourglass-split'],
    ['Tingkat Selesai', $comp['persen'] . '%', 'info', 'percent'],
];

$active = 'reports';
$title  = 'Laporan Operasional';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Laporan Operasional',
        ['Dashboard' => 'pages/dashboard/index.php', 'Laporan' => null, 'Operasional' => null],
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
                <?= ui_filter_actions(APP_URL . '/pages/reports/operations.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['booking_type'] !== 'all' || (is_super_admin() && !empty($f['filter_company_id'])))) ?>
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
                            <div class="fs-5 fw-bold text-<?= e($clr) ?>"><?= e($val) ?></div>
                        </div>
                        <i class="bi bi-<?= e($icon) ?> fs-3 text-<?= e($clr) ?> opacity-50"></i>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Utilisasi Kendaraan</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>No. Kendaraan</th><th>Warna</th>
                    <th class="text-end">Tugas</th><th class="text-end">Hari</th>
                    <th class="text-end">Selesai</th><th class="text-end">Berjalan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($veh as $r): ?>
                    <tr>
                        <td><?= e((string) $r['vehicle_number']) ?></td>
                        <td><?= e((string) $r['vehicle_color']) ?></td>
                        <td class="text-end"><?= (int) $r['tugas'] ?></td>
                        <td class="text-end"><?= (int) $r['hari'] ?></td>
                        <td class="text-end"><?= (int) $r['selesai'] ?></td>
                        <td class="text-end"><?= (int) $r['berjalan'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$veh): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Belum ada kendaraan terdaftar.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= (int) $vt['tugas'] ?></td>
                        <td class="text-end"><?= (int) $vt['hari'] ?></td>
                        <td class="text-end"><?= (int) $vt['selesai'] ?></td>
                        <td class="text-end"><?= (int) $vt['berjalan'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Aktivitas Driver</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Karyawan</th><th>Kode</th>
                    <th class="text-end">Tugas</th><th class="text-end">Selesai</th><th class="text-end">Berjalan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($drv as $r): ?>
                    <tr>
                        <td><?= e((string) $r['name']) ?></td>
                        <td class="text-muted small"><?= e((string) $r['code']) ?></td>
                        <td class="text-end"><?= (int) $r['tugas'] ?></td>
                        <td class="text-end"><?= (int) $r['selesai'] ?></td>
                        <td class="text-end"><?= (int) $r['berjalan'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$drv): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">Tidak ada penugasan driver pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= (int) $dt['tugas'] ?></td>
                        <td class="text-end"><?= (int) $dt['selesai'] ?></td>
                        <td class="text-end"><?= (int) $dt['berjalan'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Booking per Status</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Status</th><th class="text-end">Travel</th><th class="text-end">Rental</th><th class="text-end">Total</th>
                </tr></thead>
                <tbody>
                <?php foreach ($status as $r): ?>
                    <tr>
                        <td><?= e((string) $r['status']) ?></td>
                        <td class="text-end"><?= (int) $r['travel'] ?></td>
                        <td class="text-end"><?= (int) $r['rental'] ?></td>
                        <td class="text-end"><?= (int) $r['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$status): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada booking pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= (int) $st['travel'] ?></td>
                        <td class="text-end"><?= (int) $st['rental'] ?></td>
                        <td class="text-end"><?= (int) $st['total'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
