<?php
/**
 * Laporan Trip/Booking: filter periode + jenis, kartu ringkasan,
 * 4 tabel dimensi (periode, wilayah, kendaraan, driver) dengan total di <tfoot>.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_query.php';

$f = report_filter($_GET);
$data = report_all($f);

/** Bangun URL export dengan filter aktif. */
$export_qs = http_build_query([
    'format'       => 'csv',
    'from'         => $f['from'],
    'to'           => $f['to'],
    'booking_type' => $f['booking_type'],
]);

// Definisi kolom tiap tabel: [label, key, tipe] — tipe 'n' = angka, 'm' = uang.
$dims = [
    ['title' => 'Per Periode',          'rows' => $data['period'],  'cols' => [
        ['Tanggal', 'period', 's']]],
    ['title' => 'Per Wilayah Asal → Tujuan', 'rows' => $data['route'], 'cols' => [
        ['Wilayah', 'route_label', 's']]],
    ['title' => 'Per Kendaraan',        'rows' => $data['vehicle'], 'cols' => [
        ['No. Kendaraan', 'vehicle_number', 's'], ['Warna', 'vehicle_color', 's']]],
    ['title' => 'Per Driver',           'rows' => $data['driver'],  'cols' => [
        ['Driver', 'driver_name', 's']]],
];

$active = 'reports';
$title  = 'Laporan Booking';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">Laporan Booking</h4>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-success" href="bookings_export.php?<?= e($export_qs) ?>">
                <i class="bi bi-filetype-csv"></i> CSV</a>
            <a class="btn btn-success" href="bookings_export.php?<?= e(str_replace('format=csv', 'format=xlsx', $export_qs)) ?>">
                <i class="bi bi-file-earmark-excel"></i> Excel</a>
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

    <?php
    $s = $data['summary'];
    $cards = [
        ['Total Booking', number_format((float) $s['total_bookings'], 0, ',', '.'), 'primary', 'receipt'],
        ['Total Nilai', rupiah((float) $s['total_price']), 'success', 'cash-stack'],
        ['Terbayar', rupiah((float) $s['total_payment']), 'info', 'check2-circle'],
        ['Sisa Piutang', rupiah((float) $s['total_receivable']), 'warning', 'hourglass-split'],
    ];
    ?>
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

    <?php foreach ($dims as $d): $t = report_totals($d['rows']); ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold"><?= e($d['title']) ?></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr>
                        <?php foreach ($d['cols'] as [$lbl, , ]): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
                        <th class="text-end">Booking</th>
                        <th class="text-end">Nilai</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Sisa</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($d['rows'] as $r): ?>
                        <tr>
                            <?php foreach ($d['cols'] as [, $key, ]): ?>
                                <td><?= e((string) $r[$key]) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end"><?= (int) $r['total_bookings'] ?></td>
                            <td class="text-end"><?= e(rupiah((float) $r['total_price'])) ?></td>
                            <td class="text-end"><?= e(rupiah((float) $r['total_payment'])) ?></td>
                            <td class="text-end"><?= e(rupiah((float) $r['total_receivable'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$d['rows']): ?>
                        <tr><td colspan="<?= count($d['cols']) + 4 ?>" class="text-center text-muted py-3">
                            Tidak ada data pada periode ini.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td colspan="<?= count($d['cols']) ?>">Total</td>
                            <td class="text-end"><?= (int) $t['total_bookings'] ?></td>
                            <td class="text-end"><?= e(rupiah($t['total_price'])) ?></td>
                            <td class="text-end"><?= e(rupiah($t['total_payment'])) ?></td>
                            <td class="text-end"><?= e(rupiah($t['total_receivable'])) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
