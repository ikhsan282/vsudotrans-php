<?php
require_once __DIR__ . '/../../../config/config.php';
if (!can('travel.manage') && !can('rental.manage')) {
    require_permission('travel.manage');
}
require_once __DIR__ . '/../_helpers.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['code' => '', 'name' => '', 'country_code' => '+62', 'phone_number' => '', 'email' => '', 'note' => ''];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM passengers WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Penumpang tidak ditemukan.');
        redirect('pages/travel/passengers/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $row = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = [
        'code'    => strtoupper(trim((string) ($_POST['code'] ?? ''))),
        'name'    => trim((string) ($_POST['name'] ?? '')),
        'cc'      => trim((string) ($_POST['country_code'] ?? '+62')),
        'phone'   => trim((string) ($_POST['phone_number'] ?? '')),
        'email'   => trim((string) ($_POST['email'] ?? '')),
        'note'    => trim((string) ($_POST['note'] ?? '')),
    ];
    $err = '';
    if ($in['code'] === '' || strlen($in['code']) > 15) {
        $err = 'Kode wajib diisi (maks 15 karakter).';
    } elseif ($in['name'] === '' || strlen($in['name']) > 255) {
        $err = 'Nama wajib diisi (maks 255 karakter).';
    } elseif (strlen($in['cc']) > 10 || strlen($in['phone']) > 20) {
        $err = 'Kode negara atau nomor telepon terlalu panjang.';
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
                $st = db()->prepare('UPDATE passengers SET code=?, name=?, country_code=?, phone_number=?, email=?, note=?,
                    modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?');
                $st->bind_param('sssssssii', $in['code'], $in['name'], $in['cc'], $phone, $email, $note, $user, $id, $cid);
                $st->execute();
                $st->close();
                audit('penumpang.update', "Penumpang #{$id} {$in['code']}");
            } else {
                $st = db()->prepare('INSERT INTO passengers (company_id, code, name, country_code, phone_number, email, note, created_user)
                    VALUES (?,?,?,?,?,?,?,?)');
                $st->bind_param('isssssss', $cid, $in['code'], $in['name'], $in['cc'], $phone, $email, $note, $user);
                $st->execute();
                $id = (int) db()->insert_id;
                $st->close();
                audit('penumpang.create', "Penumpang #{$id} {$in['code']}");
            }
            flash('success', 'Penumpang berhasil disimpan.');
            redirect('pages/travel/passengers/index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() !== 1062) {
                throw $ex;
            }
            $err = 'Kode penumpang sudah dipakai.';
        }
    }
    flash('error', $err);
    $row = array_merge($row, ['code' => $in['code'], 'name' => $in['name'], 'country_code' => $in['cc'],
        'phone_number' => $in['phone'], 'email' => $in['email'], 'note' => $in['note']]);
}

$active = 'passengers';
$title  = $id > 0 ? 'Ubah Penumpang' : 'Tambah Penumpang';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        $title,
        ['Dashboard' => 'pages/dashboard/index.php', 'Operasional' => null, 'Penumpang' => 'pages/travel/passengers/index.php', ($id > 0 ? $row['name'] : 'Tambah Baru') => null],
        $id > 0 ? array_values(array_filter([
            !empty($row['code']) ? ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'] : null,
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            !empty($row['phone_number']) ? ['text' => $row['phone_number'], 'class' => 'bg-light text-secondary border'] : null,
        ])) : [],
        [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/travel/passengers/index.php']]
    ) ?>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Kode</label>
                <input class="form-control" name="code" maxlength="15" required value="<?= e($row['code']) ?>"></div>
            <div class="col-md-8"><label class="form-label">Nama</label>
                <input class="form-control" name="name" maxlength="255" required value="<?= e($row['name']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Kode Negara</label>
                <input class="form-control" name="country_code" maxlength="10" value="<?= e($row['country_code']) ?>"></div>
            <div class="col-md-5"><label class="form-label">Telepon</label>
                <input class="form-control" name="phone_number" maxlength="20" value="<?= e((string) $row['phone_number']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" maxlength="100" value="<?= e((string) $row['email']) ?>"></div>
            <div class="col-12"><label class="form-label">Catatan</label>
                <textarea class="form-control" name="note" rows="2" maxlength="2000"><?= e((string) $row['note']) ?></textarea></div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/passengers/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
