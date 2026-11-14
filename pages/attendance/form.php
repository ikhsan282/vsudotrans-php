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
$selEmp = (int) $v('employee_id');
$employees = attendance_employee_options($cid, $selEmp > 0 ? [$selEmp] : [], 50);
$title = $id > 0 ? 'Ubah Absensi' : 'Input Absensi';
$active = 'attendance';
require __DIR__ . '/../../includes/header.php';
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Absensi',
        'calendar-check',
        $title,
        $id > 0 ? array_values(array_filter([
            $row['date'] ?? null,
            attendance_statuses()[$v('status')] ?? ($v('status') ?: null),
        ])) : [],
        [
            ['label' => 'Batal', 'icon' => 'arrow-left', 'href' => 'index.php', 'class' => 'btn-outline-secondary'],
            ['label' => 'Simpan', 'icon' => 'check2', 'type' => 'submit', 'form' => 'formAttendance', 'class' => 'btn-primary'],
        ]
    ) ?>

    <?php if (!$employees): ?>
        <div class="alert alert-warning py-2 mb-2">Belum ada karyawan aktif di perusahaan ini.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-2"><ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" id="formAttendance">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="erp-window-card">
            <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-att-detail" data-bs-toggle="tab" data-bs-target="#pane-att-detail" type="button" role="tab">Detail</button>
                </li>
                <?php if ($id > 0): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-att-audit" data-bs-toggle="tab" data-bs-target="#pane-att-audit" type="button" role="tab">Audit Trail</button>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="erp-content-body tab-content">
                <div class="tab-pane fade show active" id="pane-att-detail" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-person-check"></i> Data Kehadiran</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Karyawan <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select name="employee_id" class="form-select form-select-sm ts-select" required
                                                data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                                                <?= $cid !== null ? 'data-ts-company="' . (int) $cid . '"' : '' ?>
                                                data-ts-limit="30">
                                                <option value="">— pilih karyawan —</option>
                                                <?php foreach ($employees as $emp): ?>
                                                    <option value="<?= (int) $emp['id'] ?>"<?= (int) $v('employee_id') === (int) $emp['id'] ? ' selected' : '' ?>>
                                                        <?= e($emp['code'] . ' - ' . $emp['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="rnt-row<?= $id > 0 ? ' rnt-row-top' : '' ?>">
                                        <div class="rnt-label">Tanggal <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <input type="date" name="date" class="form-control form-control-sm" required value="<?= e($field('date')) ?>"
                                                   <?= $id > 0 ? 'disabled' : '' ?>>
                                            <?php if ($id > 0): ?><div class="form-text small">Tanggal tidak diubah saat mengedit baris.</div><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Status <span class="text-danger">*</span></div>
                                        <div class="rnt-field">
                                            <select name="status" class="form-select form-select-sm" required>
                                                <?php foreach (attendance_statuses() as $k => $lbl): ?>
                                                    <option value="<?= e($k) ?>"<?= $v('status') === $k || ($v('status') === '' && $k === 'hadir') ? ' selected' : '' ?>>
                                                        <?= e($lbl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="rnt-panel h-100 mb-0">
                                <div class="rnt-panel-header"><i class="bi bi-clock"></i> Jam Kerja &amp; Catatan</div>
                                <div class="rnt-panel-body">
                                    <div class="rnt-row">
                                        <div class="rnt-label">Jam Masuk</div>
                                        <div class="rnt-field">
                                            <input type="time" name="check_in" class="form-control form-control-sm" value="<?= e(substr($field('check_in'), 0, 5)) ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Jam Keluar</div>
                                        <div class="rnt-field">
                                            <input type="time" name="check_out" class="form-control form-control-sm" value="<?= e(substr($field('check_out'), 0, 5)) ?>">
                                        </div>
                                    </div>
                                    <div class="rnt-row">
                                        <div class="rnt-label">Catatan</div>
                                        <div class="rnt-field">
                                            <input name="note" class="form-control form-control-sm" maxlength="1000" value="<?= e($v('note')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($id > 0): ?>
                    <div class="tab-pane fade" id="pane-att-audit" role="tabpanel">
                        <?= ui_erp_audit_panel($row) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
