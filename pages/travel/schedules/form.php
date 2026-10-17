<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('travel.manage');
require_once __DIR__ . '/../_helpers.php';

$cid = require_active_company();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = ['start' => '', 'end' => '', 'start_break' => '', 'end_break' => ''];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM schedules WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Jadwal tidak ditemukan.');
        redirect('pages/travel/schedules/index.php');
    }
    assert_company_access((int) $found['company_id']);
    $row = ['start' => substr($found['start'], 0, 5), 'end' => substr($found['end'], 0, 5),
        'start_break' => substr((string) $found['start_break'], 0, 5), 'end_break' => substr((string) $found['end_break'], 0, 5)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $start = (string) ($_POST['start'] ?? '');
    $end   = (string) ($_POST['end'] ?? '');
    $sb    = (string) ($_POST['start_break'] ?? '');
    $eb    = (string) ($_POST['end_break'] ?? '');
    $row   = ['start' => $start, 'end' => $end, 'start_break' => $sb, 'end_break' => $eb];

    $t = fn(string $s): ?int => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s)
        ? (int) substr($s, 0, 2) * 60 + (int) substr($s, 3, 2) : null;
    $ts = $t($start);
    $te = $t($end);
    $tsb = $sb === '' ? null : $t($sb);
    $teb = $eb === '' ? null : $t($eb);
    $err = '';

    if ($ts === null || $te === null) {
        $err = 'Jam mulai dan selesai wajib diisi (format HH:MM).';
    } elseif ($te <= $ts) {
        $err = 'Jam selesai harus setelah jam mulai.';
    } elseif (($sb !== '' || $eb !== '') && ($tsb === null || $teb === null || $teb <= $tsb || $tsb < $ts || $teb > $te)) {
        $err = 'Istirahat harus berada di dalam jam kerja dan selesai setelah mulai.';
    }

    if ($err === '') {
        // Durasi dalam jam; total = durasi dikurangi istirahat.
        $duration = round(($te - $ts) / 60, 2);
        $total    = round($duration - (($teb !== null && $tsb !== null) ? ($teb - $tsb) / 60 : 0), 2);
        $sb_val   = $tsb === null ? null : $sb . ':00';
        $eb_val   = $teb === null ? null : $eb . ':00';
        $user     = $_SESSION['username'] ?? null;
        try {
            if ($id > 0) {
                $st = db()->prepare('UPDATE schedules SET start=?, end=?, start_break=?, end_break=?, duration=?, total_duration=?,
                    modified_user=?, modified_date=NOW() WHERE id=? AND company_id=?');
                $st->bind_param('ssssddsii', $start, $end, $sb_val, $eb_val, $duration, $total, $user, $id, $cid);
                $st->execute();
                $st->close();
                audit('jadwal.update', "Jadwal #{$id} {$start}-{$end}");
            } else {
                $st = db()->prepare('INSERT INTO schedules (company_id, start, end, start_break, end_break, duration, total_duration, created_user)
                    VALUES (?,?,?,?,?,?,?,?)');
                $st->bind_param('issssdds', $cid, $start, $end, $sb_val, $eb_val, $duration, $total, $user);
                $st->execute();
                $id = (int) db()->insert_id;
                $st->close();
                audit('jadwal.create', "Jadwal #{$id} {$start}-{$end}");
            }
            flash('success', 'Jadwal berhasil disimpan.');
            redirect('pages/travel/schedules/index.php');
        } catch (mysqli_sql_exception $ex) {
            throw $ex;
        }
    }
    flash('error', $err);
}

$active = 'travel';
$title  = $id > 0 ? 'Ubah Jadwal' : 'Tambah Jadwal';
require __DIR__ . '/../../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-3"><?= e($title) ?></h4>
    <div class="card shadow-sm border-0"><div class="card-body">
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Mulai</label>
                <input type="time" class="form-control" name="start" required value="<?= e($row['start']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Selesai</label>
                <input type="time" class="form-control" name="end" required value="<?= e($row['end']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Istirahat Mulai</label>
                <input type="time" class="form-control" name="start_break" value="<?= e($row['start_break']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Istirahat Selesai</label>
                <input type="time" class="form-control" name="end_break" value="<?= e($row['end_break']) ?>"></div>
        </div>
        <div class="mt-4">
            <button class="btn btn-primary" type="submit">Simpan</button>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/travel/schedules/index.php">Batal</a>
        </div>
    </form>
    </div></div>
</main>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
