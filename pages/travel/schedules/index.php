<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.view');
require_once __DIR__ . '/../_helpers.php';

[$where, $types, $params] = company_scope('s.company_id');
$st = db()->prepare("SELECT COUNT(*) FROM schedules s WHERE {$where}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT s.id, s.start, s.end, s.start_break, s.end_break, s.duration, s.total_duration
                     FROM schedules s WHERE {$where} ORDER BY s.start LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'travel';
$title  = 'Jadwal';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        'Jadwal Keberangkatan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Travel' => null, 'Jadwal' => null],
        [],
        can('travel.manage') && active_company_id() !== null ? [
            ['label' => 'Tambah', 'icon' => 'plus-lg', 'href' => 'pages/travel/schedules/form.php', 'class' => 'btn btn-primary btn-sm'],
        ] : []
    ) ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Mulai</th><th>Selesai</th><th>Istirahat</th><th class="text-end">Durasi (jam)</th><th class="text-end">Total (jam)</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
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
                    <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white"><?= pagination_html($p, '') ?></div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
