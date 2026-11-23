<?php
/**
 * Laporan Trip/Booking: filter periode + jenis, kartu ringkasan,
 * 4 tabel dimensi (periode, wilayah, kendaraan, driver) dengan total di <tfoot>.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('reports.view');
require_once __DIR__ . '/_query.php';

$f = report_ui_filter($_GET);
$data = report_all($f);
$header_actions = report_header_actions('pages/reports/bookings_export.php', $f);

// Definisi kolom tiap tabel: [label, key, tipe] — tipe 'n' = angka, 'm' = uang.
$dims = [
    ['title' => 'Per Kategori Layanan',       'rows' => $data['category'], 'cols' => [
        ['Kategori Layanan', 'category_label', 's'], ['Layanan', 'service_label', 's']]],
    ['title' => 'Per Periode',                'rows' => $data['period'],  'cols' => [
        ['Tanggal', 'period', 's']]],
    ['title' => 'Per Wilayah Asal → Tujuan',  'rows' => $data['route'],   'cols' => [
        ['Wilayah', 'route_label', 's']]],
    ['title' => 'Per Kendaraan',              'rows' => $data['vehicle'], 'cols' => [
        ['No. Kendaraan', 'vehicle_number', 's'], ['Warna', 'vehicle_color', 's']]],
    ['title' => 'Per Driver',                 'rows' => $data['driver'],  'cols' => [
        ['Driver', 'driver_name', 's']]],
];

$active = 'reports';
$title  = 'Laporan Booking';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-3 p-lg-4 w-100 flex-grow-1">
    <?= ui_page_header(
        'Laporan Booking',
        ['Dashboard' => 'pages/dashboard/index.php', 'Laporan' => null, 'Booking' => null],
        [['text' => tanggal_indo($f['from']) . ' – ' . tanggal_indo($f['to']), 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form class="row g-2 align-items-end erp-filter-bar" method="get">
                <?= report_company_filter_col($f) ?>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Dari Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="from" value="<?= e($f['from']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Sampai Tanggal</label>
                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" data-flatpickr-auto placeholder="dd-MMM-yyyy" name="to" value="<?= e($f['to']) ?>">
                </div>
                <div class="col-12 col-sm-4 col-lg-2">
                    <label class="form-label small mb-1">Jenis Booking</label>
                    <select class="form-select form-select-sm" name="booking_type">
                        <?php foreach (['all' => 'Semua', 'travel' => 'Travel', 'rental' => 'Rental'] as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $f['booking_type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= report_category_filter_col($f) ?>
                <?= ui_filter_actions(APP_URL . '/pages/reports/bookings.php', ($f['from'] !== date('Y-m-01') || $f['to'] !== date('Y-m-t') || $f['booking_type'] !== 'all' || !empty($f['category_id']) || (is_super_admin() && !empty($f['filter_company_id'])))) ?>
            </form>
            <?= report_preset_pills_html('pages/reports/bookings.php', $f) ?>
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
