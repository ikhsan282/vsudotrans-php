<?php
/** Atur daftar perusahaan yang boleh diakses user (tabel user_companies). Hanya Super Admin. */
require_once __DIR__ . '/../../config/config.php';
require_permission('users.manage');

if (!is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $db->prepare('SELECT u.id, u.username, u.full_name, u.company_id, r.name AS role_name
                    FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
$st->bind_param('i', $id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u) {
    flash('error', 'Pengguna tidak ditemukan.');
    redirect('pages/users/index.php');
}
// Super Admin melihat semua perusahaan tanpa tabel user_companies.
if ($u['role_name'] === 'Super Admin') {
    flash('info', 'Super Admin dapat mengakses semua perusahaan; tidak perlu diatur.');
    redirect('pages/users/index.php');
}

$companies = $db->query('SELECT id, code, name FROM companies ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$valid_ids = array_map('intval', array_column($companies, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $raw    = $_POST['company_ids'] ?? [];
    $picked = array_values(array_unique(array_filter(
        array_map('intval', is_array($raw) ? $raw : []),
        fn (int $c): bool => in_array($c, $valid_ids, true)
    )));

    $db->begin_transaction();
    try {
        $st = $db->prepare('DELETE FROM user_companies WHERE user_id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();

        if ($picked) {
            $st = $db->prepare('INSERT INTO user_companies (user_id, company_id) VALUES (?, ?)');
            foreach ($picked as $cid) {
                $st->bind_param('ii', $id, $cid);
                $st->execute();
            }
            $st->close();
        }

        // Perusahaan aktif harus termasuk daftar; bila tidak, pindah ke yang pertama.
        // ponytail: tanpa pilihan perusahaan aktif baru, company_id di-set NULL (lintas perusahaan).
        $cur    = $u['company_id'] !== null ? (int) $u['company_id'] : null;
        $active = in_array($cur, $picked, true) ? $cur : ($picked[0] ?? null);
        if ($active !== $cur) {
            $st = $db->prepare('UPDATE users SET company_id = ? WHERE id = ?');
            $st->bind_param('ii', $active, $id);
            $st->execute();
            $st->close();
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }

    audit('user.companies', "Akses perusahaan pengguna #{$id} {$u['username']}: " . implode(',', $picked));
    flash('success', 'Akses perusahaan berhasil diperbarui.');
    redirect('pages/users/companies.php?id=' . $id);
}

// Tampilkan pilihan dari DB (bukan dari POST) agar sesuai kondisi tersimpan.
$st = $db->prepare('SELECT company_id FROM user_companies WHERE user_id = ?');
$st->bind_param('i', $id);
$st->execute();
$granted = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'company_id'));
$st->close();

$active = 'users';
$title  = 'Akses Perusahaan: ' . $u['username'];
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Akses Perusahaan: <?= e($u['full_name']) ?> <span class="text-muted small">(<?= e($u['username']) ?>)</span></h4>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/users/index.php">Kembali</a>
    </div>
    <div class="card shadow-sm border-0" style="max-width: 640px;">
        <div class="card-body">
            <form method="post" action="<?= e(APP_URL) ?>/pages/users/companies.php?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php if (!$companies): ?>
                    <p class="text-muted mb-3">Belum ada perusahaan.</p>
                <?php endif; ?>
                <?php foreach ($companies as $c): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="company_ids[]" id="c<?= (int) $c['id'] ?>"
                               value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $granted, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="c<?= (int) $c['id'] ?>">
                            <?= e($c['name']) ?> <span class="text-muted small">(<?= e($c['code']) ?>)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
