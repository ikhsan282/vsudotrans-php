<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
transport_require_manage();

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['code' => '', 'name' => '', 'phone_number' => '', 'email' => '', 'note' => ''];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM passengers WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Penumpang tidak ditemukan.');
        redirect('pages/transport/passengers/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $cid = (int) $found['company_id'];
    $row = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $phoneNorm = normalize_phone_62((string) ($_POST['phone_number'] ?? ''));
    $in = [
        'code'    => strtoupper(trim((string) ($_POST['code'] ?? ''))),
        'name'    => trim((string) ($_POST['name'] ?? '')),
        'phone'   => $phoneNorm ?? '',
        'email'   => trim((string) ($_POST['email'] ?? '')),
        'note'    => trim((string) ($_POST['note'] ?? '')),
    ];
    $err = '';
    if ($in['code'] === '' || strlen($in['code']) > 15) {
        $err = 'Kode wajib diisi (maks 15 karakter).';
    } elseif ($in['name'] === '' || strlen($in['name']) > 255) {
        $err = 'Nama wajib diisi (maks 255 karakter).';
    } elseif (strlen($in['phone']) > 20) {
        $err = 'Nomor telepon terlalu panjang (maks 20 digit).';
    } elseif ($in['email'] !== '' && (strlen($in['email']) > 100 || !filter_var($in['email'], FILTER_VALIDATE_EMAIL))) {
        $err = 'Format email tidak valid.';
    }
    $phone = $in['phone'] === '' ? null : $in['phone'];
    $email = $in['email'] === '' ? null : $in['email'];
    $note  = $in['note'] === '' ? null : $in['note'];
    $user  = $_SESSION['username'] ?? null;

    if ($err === '') {
        try {
            if ($id > 0) {
                $st = db()->prepare('UPDATE passengers SET code=?, name=?, phone_number=?, email=?, note=?,
                    modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?');
                $st->bind_param('ssssssii', $in['code'], $in['name'], $phone, $email, $note, $user, $id, $cid);
                $st->execute();
                $st->close();
                audit('penumpang.update', "Penumpang #{$id} {$in['code']}");
            } else {
                $st = db()->prepare('INSERT INTO passengers (company_id, code, name, phone_number, email, note, created_user)
                    VALUES (?,?,?,?,?,?,?)');
                $st->bind_param('issssss', $cid, $in['code'], $in['name'], $phone, $email, $note, $user);
                $st->execute();
                $id = (int) db()->insert_id;
                $st->close();
                audit('penumpang.create', "Penumpang #{$id} {$in['code']}");
            }
            flash('success', 'Penumpang berhasil disimpan.');
            redirect('pages/transport/passengers/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
            $err = 'Kode penumpang sudah dipakai.';
        }
    }
    flash('error', $err);
    $row = array_merge($row, ['code' => $in['code'], 'name' => $in['name'],
        'phone_number' => $in['phone'], 'email' => $in['email'], 'note' => $in['note']]);
}

$active = 'passengers';
$title  = $id > 0 ? ('Ubah Penumpang - ' . $row['name']) : 'Tambah Penumpang';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Penumpang',
        'person-lines-fill',
        $id > 0 ? (string) $row['name'] : 'Tambah Penumpang Baru',
        $id > 0 ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            !empty($row['phone_number']) ? ['text' => $row['phone_number'], 'class' => 'bg-light text-secondary border'] : null,
        ])) : [['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border']],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'pages/transport/passengers/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
            ['label' => 'Simpan', 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'formPassenger', 'class' => 'btn btn-primary btn-sm px-3'],
        ]
    ) ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="passengerFormTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-pax-detail-btn" data-bs-toggle="tab" data-bs-target="#tab-pax-detail" type="button" role="tab">Detail</button>
            </li>
            <?php if ($id > 0): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-pax-audit-btn" data-bs-toggle="tab" data-bs-target="#tab-pax-audit" type="button" role="tab">Audit Trail</button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="" id="formPassenger">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="tab-content" id="passengerFormTabContent">
                    <div class="tab-pane fade show active" id="tab-pax-detail" role="tabpanel" aria-labelledby="tab-pax-detail-btn">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header"><span>Informasi Penumpang / Pelanggan</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Kode <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm text-uppercase font-monospace" name="code" maxlength="15" required value="<?= e($row['code']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Nama Lengkap <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" name="name" maxlength="255" required value="<?= e($row['name']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">No. Telepon</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" name="phone_number" maxlength="20" value="<?= e((string) ($row['phone_number'] ?? '')) ?>" placeholder="62812xxxxxxxx">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Email</label>
                                    <div class="rnt-field">
                                        <input type="email" class="form-control form-control-sm" name="email" maxlength="100" value="<?= e((string) $row['email']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start mb-0">
                                    <label class="rnt-label pt-1">Catatan</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm rnt-ta-compact" name="note" rows="2" maxlength="2000"><?= e((string) $row['note']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($id > 0): ?>
                        <div class="tab-pane fade" id="tab-pax-audit" role="tabpanel" aria-labelledby="tab-pax-audit-btn">
                            <?= ui_erp_audit_panel($row) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
