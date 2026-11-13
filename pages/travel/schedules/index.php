<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

$scope = company_filter_scope('s.company_id');
$filter_company_id = $scope['filter_company_id'];
$where             = $scope['where'];
$types             = $scope['types'];
$params            = $scope['params'];
$companies         = $scope['companies'];
$active_cid        = $scope['active_cid'];

$st = db()->prepare("SELECT COUNT(*) FROM schedules s WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$export_format = strtolower(trim((string) ($_GET['export'] ?? '')));
if (in_array($export_format, ['xlsx', 'pdf'], true)) {
    $sql_export = "SELECT s.start, s.end, s.start_break, s.end_break, s.duration, s.total_duration
                   FROM schedules s WHERE {$where} ORDER BY s.start";
    $max_export_rows = $export_format === 'pdf' ? GRID_PDF_MAX_ROWS : null;

    $row_stream = grid_query_stream(
        $sql_export,
        $types,
        $params,
        static fn (array $r): array => [
            substr($r['start'], 0, 5),
            substr($r['end'], 0, 5),
            $r['start_break'] ? substr($r['start_break'], 0, 5) . ' - ' . substr((string) $r['end_break'], 0, 5) : '-',
            (float) $r['duration'],
            (float) $r['total_duration'],
        ],
        $max_export_rows
    );

    grid_export_send(
        'Daftar Jadwal Travel',
        ['Jam Mulai', 'Jam Selesai', 'Jam Istirahat', 'Durasi (Jam)', 'Total Durasi (Jam)'],
        $row_stream,
        $export_format,
        'portrait',
        'Semua Data',
        $total
    );
}

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT s.id, s.start, s.end, s.start_break, s.end_break, s.duration, s.total_duration
                     FROM schedules s WHERE {$where} ORDER BY s.start LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$header_actions = ui_export_actions();
if (can('travel.manage') && active_company_id() !== null) {
    $header_actions[] = ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/schedules/form.php', 'class' => 'btn btn-primary btn-sm'];
}

$has_explicit_company = isset($_GET['company_id']);
$query_base = $has_explicit_company ? http_build_query(['company_id' => $filter_company_id]) : '';
$has_active_filter = ($has_explicit_company && $filter_company_id !== ($active_cid ?? 0));

$active = 'travel';
$title  = 'Jadwal';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Jadwal Keberangkatan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Jadwal' => null],
        [['text' => number_format($total, 0, ',', '.') . ' Jadwal', 'class' => 'bg-light text-secondary border']],
        $header_actions
    ) ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2 px-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-6 col-md-3">
                    <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()" title="Pilih Perusahaan">
                        <?= ui_company_filter_options($companies, $filter_company_id, $has_explicit_company) ?>
                    </select>
                </div>
                <?php if ($has_active_filter): ?>
                    <div class="col-auto d-flex gap-1">
                        <a href="<?= e(APP_URL) ?>/pages/travel/schedules/index.php" class="btn btn-sm btn-outline-danger" title="Reset"><i class="bi bi-x-lg"></i> Reset</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light"><tr><th class="text-center" style="width: 52px;">No</th><th>Mulai</th><th>Selesai</th><th>Istirahat</th><th class="text-end">Durasi (jam)</th><th class="text-end">Total (jam)</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td class="text-center text-muted small"><?= number_format($p['offset'] + $idx + 1, 0, ',', '.') ?></td>
                        <td><?= e(substr($r['start'], 0, 5)) ?></td>
                        <td><?= e(substr($r['end'], 0, 5)) ?></td>
                        <td><?= $r['start_break'] ? e(substr($r['start_break'], 0, 5)) . ' - ' . e(substr((string) $r['end_break'], 0, 5)) : '-' ?></td>
                        <td class="text-end"><?= e((string) $r['duration']) ?></td>
                        <td class="text-end"><?= e((string) $r['total_duration']) ?></td>
                        <td class="text-end">
                            <?php if (can('travel.manage')): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/schedules/form.php?id=<?= (int) $r['id'] ?>">Ubah</a>
                                <form method="post" action="<?= e(APP_URL) ?>/pages/travel/schedules/delete.php" class="d-inline" onsubmit="return confirm('Hapus jadwal ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data jadwal.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <div class="small text-muted">
                <?php if ($total > 0): ?>
                    Menampilkan <strong><?= number_format($p['offset'] + 1, 0, ',', '.') ?>&ndash;<?= number_format(min($total, $p['offset'] + count($rows)), 0, ',', '.') ?></strong> dari <strong><?= number_format($total, 0, ',', '.') ?></strong> data
                <?php else: ?>
                    Total <strong>0</strong> data
                <?php endif; ?>
            </div>
            <?= pagination_html($p, $query_base) ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
