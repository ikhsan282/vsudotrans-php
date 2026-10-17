<?php
/**
 * Audit Trail — daftar log aktivitas (READ-ONLY), filter tanggal/user/aksi, scoped company_scope().
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('audit.view');

$from   = trim((string) ($_GET['from'] ?? ''));
$to     = trim((string) ($_GET['to'] ?? ''));
$f_user = trim((string) ($_GET['user'] ?? '')); // bukan $user: header.php menimpa $user
$action = trim((string) ($_GET['action'] ?? ''));

$valid_date = static fn(string $d): bool => (bool) (($dt = DateTime::createFromFormat('Y-m-d', $d)) && $dt->format('Y-m-d') === $d);
if ($from !== '' && !$valid_date($from)) {
    $from = '';
}
if ($to !== '' && !$valid_date($to)) {
    $to = '';
}
$like = static fn(string $s): string => '%' . addcslashes($s, '%_\\') . '%';

[$wsql, $wtypes, $wparams] = company_scope('a.company_id');
$where = [$wsql];
$types = $wtypes;
$params = $wparams;

if ($from !== '') {
    $where[] = 'a.created_date >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.created_date < ?';
    $types .= 's';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
}
if ($f_user !== '') {
    $where[] = 'a.username LIKE ?';
    $types .= 's';
    $params[] = $like($f_user);
}
if ($action !== '') {
    $where[] = 'a.action LIKE ?';
    $types .= 's';
    $params[] = $like($action);
}
$where_sql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM audit_trails a WHERE {$where_sql}");
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$total = (int) $st->get_result()->fetch_row()[0];
$st->close();

$p = paginate($total, (int) ($_GET['page'] ?? 1));
$st = db()->prepare("SELECT a.id, a.user_id, a.username, a.company_id, a.action, a.description,
                            a.ip_address, a.created_date
                     FROM audit_trails a
                     WHERE {$where_sql}
                     ORDER BY a.created_date DESC, a.id DESC
                     LIMIT ? OFFSET ?");
$st->bind_param($types . 'ii', ...[...$params, $p['per_page'], $p['offset']]);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$query_base = http_build_query(array_filter(['from' => $from, 'to' => $to, 'user' => $f_user, 'action' => $action]));

$active = 'audit';
$title  = 'Audit Trail';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-4"><?= e($title) ?></h4>
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end mb-3">
                <div class="col-auto">
                    <label class="form-label small mb-0" for="from">Dari tanggal</label>
                    <input type="date" id="from" name="from" class="form-control" value="<?= e($from) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0" for="to">Sampai tanggal</label>
                    <input type="date" id="to" name="to" class="form-control" value="<?= e($to) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0" for="user">User</label>
                    <input type="search" id="user" name="user" class="form-control" placeholder="Username"
                           value="<?= e($f_user) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0" for="action">Aksi</label>
                    <input type="search" id="action" name="action" class="form-control" placeholder="Mis. employee.create"
                           value="<?= e($action) ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary" type="submit">Filter</button>
                    <a class="btn btn-outline-secondary" href="?">Reset</a>
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
                <span><?= e((string) $p['total']) ?> catatan ditemukan</span>
                <?= pagination_html($p, $query_base) ?>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>User</th>
                        <th>Aksi</th>
                        <th>Keterangan</th>
                        <th>IP</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="small text-nowrap"><?= e($r['created_date']) ?></td>
                            <td><?= e($r['username'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                            <td><code><?= e($r['action']) ?></code></td>
                            <td class="small"><?= e($r['description'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($r['ip_address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada catatan audit.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
