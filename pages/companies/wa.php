<?php
/**
 * Setelan Notifikasi WhatsApp per perusahaan + kirim ulang manual (tanpa cron).
 *
 * Gate: finance.manage — di seed RBAC, "Admin Perusahaan" justru TIDAK punya
 * companies.manage (hanya Super Admin), padahal operator perusahaan inilah yang
 * mengisi endpoint/token gateway. finance.manage = izin kelola setelan operasional
 * perusahaan yang dimiliki Admin Perusahaan, jadi halaman ini tetap terjangkau
 * oleh yang berhak tanpa membuka kelola perusahaan/grup/role.
 */
require_once __DIR__ . '/../../config/config.php';
require_permission('finance.manage');
require_once __DIR__ . '/../travel/_helpers.php'; // require_active_company()
require_once __DIR__ . '/../../includes/wa.php';

$cid = require_active_company();
$db  = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string) ($_POST['act'] ?? 'save');
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $cur = wa_settings($cid);

    if ($act === 'resend') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM wa_outbox WHERE id = ? AND company_id = ? LIMIT 1');
        $st->bind_param('ii', $id, $cid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            flash('error', 'Baris outbox tidak ditemukan.');
        } else {
            $ok = wa_send($cid, (string) $row['booking_type'], (int) $row['booking_id'],
                (string) $row['phone'], (string) $row['message']);
            audit('wa.resend', "Outbox #{$id} " . ($ok ? 'terkirim' : 'gagal'));
            flash($ok ? 'success' : 'error', $ok ? 'Pesan dikirim ulang.' : 'Kirim ulang gagal — lihat kolom respons.');
        }
        redirect('pages/companies/wa.php');
    }

    $url = $in('api_url', 255);
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'URL endpoint tidak valid.';
    } elseif ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $errors[] = 'URL endpoint harus http:// atau https://.';
    }

    if (!$errors) {
        $nul = fn (string $v): ?string => $v === '' ? null : $v;
        $val = [
            isset($_POST['is_active']) ? 1 : 0,
            $url,
            $in('sender', 50),
            $in('notify_phones', 1000),
            $in('template_travel', 2000),
            $in('template_rental', 2000),
        ];
        $token = $in('api_token', 255);
        $token_saved = $nul($token);
        // bind_param menerima argumen by reference: nilai harus variabel, bukan ekspresi.
        $user = $_SESSION['username'] ?? null;

        if (!$cur) {
            $st = $db->prepare('INSERT INTO wa_settings (company_id, is_active, api_url, api_token, sender,
                notify_phones, template_travel, template_rental, created_user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->bind_param('iisssssss', $cid, $val[0], $val[1], $token_saved, $val[2], $val[3], $val[4], $val[5], $user);
        } elseif ($token === '') {
            // Token dibiarkan kosong = jangan ubah token tersimpan (tidak ditampilkan penuh di form).
            $st = $db->prepare('UPDATE wa_settings SET is_active = ?, api_url = ?, sender = ?, notify_phones = ?,
                template_travel = ?, template_rental = ?, modified_user = ?, modified_date = NOW() WHERE company_id = ?');
            $st->bind_param('issssssi', $val[0], $val[1], $val[2], $val[3], $val[4], $val[5], $user, $cid);
        } else {
            $st = $db->prepare('UPDATE wa_settings SET is_active = ?, api_url = ?, api_token = ?, sender = ?, notify_phones = ?,
                template_travel = ?, template_rental = ?, modified_user = ?, modified_date = NOW() WHERE company_id = ?');
            $st->bind_param('isssssssi', $val[0], $val[1], $token, $val[2], $val[3], $val[4], $val[5], $user, $cid);
        }
        $st->execute();
        $st->close();
        audit('wa.settings', 'Setelan notifikasi WhatsApp diperbarui' . ($val[0] ? ' (aktif)' : ' (nonaktif)'));
        flash('success', 'Setelan notifikasi WhatsApp disimpan.');
        redirect('pages/companies/wa.php');
    }
}

$s = wa_settings($cid);
$row = $s + ['is_active' => 0, 'api_url' => '', 'api_token' => '', 'sender' => '',
    'notify_phones' => '', 'template_travel' => '', 'template_rental' => ''];

// Masker token: hanya 4 karakter terakhir yang terlihat.
$token_mask = '';
if ((string) $row['api_token'] !== '') {
    $t = (string) $row['api_token'];
    $token_mask = str_repeat('•', max(4, strlen($t) - 4)) . substr($t, -4);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $db->query("SELECT COUNT(*) FROM wa_outbox WHERE company_id = $cid")->fetch_column();
$pg = paginate($total, $page);
$st = $db->prepare('SELECT * FROM wa_outbox WHERE company_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
$st->bind_param('iii', $cid, $pg['per_page'], $pg['offset']);
$st->execute();
$logs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$active = 'wa';
$title  = 'Notifikasi WhatsApp';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="alert alert-info py-2 small">
        Notifikasi dikirim <strong>sinkron</strong> saat booking baru disimpan (tanpa cron). Gagal kirim tidak
        menggagalkan penyimpanan booking — barisnya masuk log di bawah dan bisa dikirim ulang manual.
    </div>

    <div class="card shadow-sm border-0 mb-4" style="max-width: 900px;">
        <div class="card-body">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="save">
                <div class="row g-3">
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Aktifkan notifikasi WhatsApp</label>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="api_url">URL Endpoint Gateway</label>
                        <input class="form-control" id="api_url" name="api_url" maxlength="255"
                               placeholder="https://api.example.com/send" value="<?= e((string) $row['api_url']) ?>">
                        <div class="form-text">Menerima POST JSON <code>{"token","sender","target","message"}</code>. Isi sesuai provider gateway (Fonnte, Wablas, Woowa, dsb.).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="api_token">Token / API Key</label>
                        <input class="form-control" id="api_token" name="api_token" maxlength="255" autocomplete="off"
                               placeholder="<?= $token_mask !== '' ? e($token_mask) : 'token gateway' ?>">
                        <div class="form-text"><?= $token_mask !== '' ? 'Kosongkan bila tidak ingin mengubah token tersimpan.' : 'Belum ada token tersimpan.' ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="sender">Sender / Device</label>
                        <input class="form-control" id="sender" name="sender" maxlength="50" value="<?= e((string) $row['sender']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notify_phones">Nomor Tujuan</label>
                        <input class="form-control" id="notify_phones" name="notify_phones" maxlength="1000"
                               placeholder="08123456789, 08987654321" value="<?= e((string) $row['notify_phones']) ?>">
                        <div class="form-text">Pisah dengan koma. Awalan 08 otomatis diubah ke 62. Kosong = pesan dikirim ke nomor penumpang.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="template_travel">Template Travel</label>
                        <textarea class="form-control" id="template_travel" name="template_travel" rows="4" maxlength="2000"><?= e((string) $row['template_travel']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="template_rental">Template Rental</label>
                        <textarea class="form-control" id="template_rental" name="template_rental" rows="4" maxlength="2000"><?= e((string) $row['template_rental']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-text">
                            Placeholder: <code>{nama}</code> <code>{nomor}</code> <code>{tanggal}</code>
                            <code>{asal}</code> <code>{tujuan}</code> <code>{harga}</code> <code>{jenis}</code>.
                            Kosong = pakai template bawaan. Placeholder lain dibiarkan apa adanya.
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <h6 class="fw-bold">Log Pengiriman <span class="text-muted fw-normal small">(<?= (int) $pg['total'] ?> baris, terbaru dulu)</span></h6>
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Waktu</th><th>Booking</th><th>Nomor</th><th>Status</th><th>Respons</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">Belum ada log.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e((string) $l['created_date']) ?></td>
                        <td class="small"><?= e((string) $l['booking_type']) ?> #<?= (int) $l['booking_id'] ?></td>
                        <td class="small"><?= e((string) $l['phone']) ?></td>
                        <td><span class="badge bg-<?= $l['status'] === 'sent' ? 'success' : ($l['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= e((string) $l['status']) ?></span></td>
                        <td class="small text-truncate" style="max-width: 320px;"><?= e((string) $l['response']) ?></td>
                        <td>
                            <?php if ($l['status'] !== 'sent'): ?>
                                <form method="post" action="" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="act" value="resend">
                                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                    <button class="btn btn-outline-secondary btn-sm py-0" type="submit">Kirim ulang</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3"><?= pagination_html($pg, 'id=wa') ?></div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
