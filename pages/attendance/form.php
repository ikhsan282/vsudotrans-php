<?php
/**
 * Input absensi. Tanggal yang sama untuk karyawan yang sama akan DIPERBARUI
 * (UNIQUE employee_id+date) — user cukup lihat "tersimpan/diperbarui".
 * Edit baris tertentu (dari tombol Ubah) memuat data lama dan hanya boleh
 * memperbarui baris itu (id + employee_id + date di kunci WHERE).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_helpers.php';
require_permission('attendance.manage');

$cid = attendance_scope_cid();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = $id > 0 ? attendance_find($id) : null;
if ($id > 0 && !$row) {
    http_response_code(404);
    exit('Absensi tidak ditemukan.');
}
if ($row) {
    assert_company_access((int) $row['company_id']);
}

$errors = [];
$input = $row ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$data, $errors] = attendance_map_input($_POST, $cid);
    $input = $_POST;

    if (!$errors) {
        $user = (string) ($_SESSION['username'] ?? '');
        if ($row) {
            // Ubah baris yang sedang diedit: kunci pada id + pemilik + tanggal asli,
            // supaya "Ubah" tidak bisa menyuntik baris karyawan/tanggal lain.
            $stmt = db()->prepare('UPDATE employee_attendances
                SET status = ?, check_in = ?, check_out = ?, note = ?, modified_user = ?, modified_date = NOW()
                WHERE id = ? AND employee_id = ? AND date = ? AND company_id = ?');
            $stmt->bind_param('sssssissi', $data['status'], $data['check_in'], $data['check_out'], $data['note'],
                $user, $id, $row['employee_id'], $row['date'], $row['company_id']);
            $stmt->execute();
            $stmt->close();
            audit('attendance.update', "Ubah absensi #{$id} — karyawan #{$row['employee_id']} {$row['date']}: {$data['status']}");
            flash('success', 'Absensi diperbarui.');
        } else {
            $saved_id = attendance_save($data, $cid, $user);
            audit('attendance.save', "Simpan absensi — karyawan #{$data['employee_id']} {$data['date']}: {$data['status']} (id {$saved_id})");
            flash('success', 'Absensi tersimpan.');
        }
        redirect('pages/attendance/index.php?from=' . urlencode((string) ($data['date'] ?: date('Y-m-01')))
            . '&to=' . urlencode((string) ($data['date'] ?: date('Y-m-t'))));
    }
}

$v = fn(string $k): string => (string) ($input[$k] ?? '');
$field = static fn(string $k): string => $v($k) !== '' ? $v($k) : ($k === 'date' ? date('Y-m-d') : '');
$employees = attendance_employee_options($cid);
$title = $id > 0 ? 'Ubah Absensi' : 'Input Absensi';
$active = 'attendance';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-4"><?= e($title) ?></h4>

    <?php if (!$employees): ?>
        <div class="alert alert-warning">Belum ada karyawan aktif di perusahaan ini.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <div class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Karyawan <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— pilih karyawan —</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int) $emp['id'] ?>"<?= (int) $v('employee_id') === (int) $emp['id'] ? ' selected' : '' ?>>
                                <?= e($emp['code'] . ' - ' . $emp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="date" class="form-control" required value="<?= e($field('date')) ?>"
                           <?= $id > 0 ? 'disabled' : '' ?>>
                    <?php if ($id > 0): ?><div class="form-text">Tanggal tidak diubah saat mengedit baris.</div><?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <?php foreach (attendance_statuses() as $k => $lbl): ?>
                            <option value="<?= e($k) ?>"<?= $v('status') === $k || ($v('status') === '' && $k === 'hadir') ? ' selected' : '' ?>>
                                <?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Jam Masuk</label>
                    <input type="time" name="check_in" class="form-control" value="<?= e(substr($field('check_in'), 0, 5)) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Jam Keluar</label>
                    <input type="time" name="check_out" class="form-control" value="<?= e(substr($field('check_out'), 0, 5)) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <input name="note" class="form-control" maxlength="1000" value="<?= e($v('note')) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-end gap-2">
            <a href="index.php" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
