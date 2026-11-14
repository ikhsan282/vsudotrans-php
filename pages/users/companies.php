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
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Pengguna',
        'building-check',
        'Akses Perusahaan: ' . $u['full_name'],
        [(string) $u['username']],
        [
            ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => APP_URL . '/pages/users/index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formUserCompanies', 'class' => 'btn-primary'],
        ]
    ) ?>
    <form method="post" action="<?= e(APP_URL) ?>/pages/users/companies.php?id=<?= $id ?>" id="formUserCompanies">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-uc-detail" data-bs-toggle="tab" data-bs-target="#pane-uc-detail" type="button" role="tab">Akses Perusahaan</button>
                </li>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-uc-detail" role="tabpanel">
                    <div class="rnt-panel mb-0">
                        <div class="rnt-panel-header"><i class="bi bi-buildings"></i> Daftar Perusahaan Diizinkan</div>
                        <div class="rnt-panel-body p-3">
                            <?php if (!$companies): ?>
                                <p class="text-muted small mb-0">Belum ada perusahaan.</p>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($companies as $c): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" name="company_ids[]" id="c<?= (int) $c['id'] ?>"
                                                       value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $granted, true) ? ' checked' : '' ?>>
                                                <label class="form-check-label small" for="c<?= (int) $c['id'] ?>">
                                                    <?= e($c['name']) ?> <span class="text-muted">(<?= e($c['code']) ?>)</span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
