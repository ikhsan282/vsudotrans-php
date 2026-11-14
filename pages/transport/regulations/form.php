<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_helpers.php';
transport_require_view();
require_once __DIR__ . '/../../../includes/regulation_service.php';

regulation_ensure_schema();

$companies  = accessible_companies();
$active_cid = active_company_id();
$canManage  = transport_can_manage();
$user       = $_SESSION['username'] ?? null;

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$reg = $id > 0 ? regulation_find($id) : null;
if ($id > 0 && !$reg) {
    flash('error', 'Peraturan Komisi Karyawan tidak ditemukan.');
    redirect('pages/transport/regulations/index.php');
}
if ($reg) {
    assert_company_access((int) $reg['company_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengelola Peraturan Komisi Karyawan.');
        redirect('pages/transport/regulations/index.php');
    }
    $act = (string) ($_POST['act'] ?? 'save');

    try {
        if ($act === 'delete' && $id > 0 && $reg) {
            regulation_delete($id, (int) $reg['company_id']);
            audit('rental.regulation.delete', "Hapus Peraturan Komisi Karyawan #{$id}");
            flash('success', 'Peraturan Komisi Karyawan berhasil dihapus.');
            redirect('pages/transport/regulations/index.php');
        }

        $companyId  = (int) ($_POST['company_id'] ?? ($reg['company_id'] ?? $active_cid ?? 0));
        assert_company_access($companyId);
        $categoryId = (int) ($_POST['category_vehicle_id'] ?? ($reg['category_vehicle_id'] ?? 0));
        if ($categoryId > 0 && !fetch_company_ref_row('category_vehicles', $categoryId, $companyId)) {
            throw new InvalidArgumentException('Kategori kendaraan tidak valid untuk perusahaan yang dipilih.');
        }
        $trip       = max(1, (int) ($_POST['trip'] ?? 1));
        $startDate  = trim((string) ($_POST['start_date'] ?? date('Y-01-01')));
        $endDate    = trim((string) ($_POST['end_date'] ?? '9999-12-31'));
        $note       = trim((string) ($_POST['note'] ?? ''));

        $empTypes  = (array) ($_POST['detail_emp_type'] ?? []);
        $calcTypes = (array) ($_POST['detail_calc_type'] ?? []);
        $amounts   = (array) ($_POST['detail_amount'] ?? []);

        $details = [];
        foreach ($empTypes as $idx => $et) {
            $et = trim((string) $et);
            $ct = trim((string) ($calcTypes[$idx] ?? 'Tetap'));
            $rawAmt = trim((string) ($amounts[$idx] ?? '0'));

            if (strcasecmp($ct, 'Persentase') === 0) {
                // Untuk Persentase, izinkan desimal titik/koma (contoh: 13.5 atau 13,5)
                $norm = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $rawAmt) ?? '0');
                $val  = round((float) $norm, 2);
            } else {
                $val = parse_rupiah_input($rawAmt);
            }

            if ($et !== '' && $val > 0) {
                $details[] = [
                    'employee_type' => $et,
                    'calc_type'     => $ct,
                    'amount'        => $val,
                ];
            }
        }

        $savedId = regulation_save(
            $id,
            [
                'company_id'          => $companyId,
                'category_vehicle_id' => $categoryId,
                'trip'                => $trip,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'note'                => $note,
            ],
            $details,
            $user
        );

        audit(
            $id > 0 ? 'rental.regulation.update' : 'rental.regulation.create',
            "Peraturan Komisi Karyawan #{$savedId} (Company #{$companyId}, Category #{$categoryId})"
        );
        flash('success', 'Peraturan Komisi Karyawan berhasil disimpan.');
        redirect('pages/transport/regulations/form.php?id=' . $savedId);
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() ?: 'Gagal menyimpan Peraturan Komisi Karyawan.');
        if ($id > 0) {
            redirect('pages/transport/regulations/form.php?id=' . $id);
        }
    }
}

$curCompanyId  = (int) ($reg['company_id'] ?? ($active_cid ?? ($companies[0]['id'] ?? 0)));
$curCategoryId = (int) ($reg['category_vehicle_id'] ?? 0);
$curTrip       = (int) ($reg['trip'] ?? 1);
$curStartDate  = (string) ($reg['start_date'] ?? date('Y-01-01'));
$curEndDate    = (string) ($reg['end_date'] ?? '9999-12-31');
$curNote       = (string) ($reg['note'] ?? '');

$detailRows = $reg['details'] ?? [];
if (!$detailRows) {
    $detailRows = [
        ['employee_type' => 'Sopir',  'calc_type' => 'Persentase', 'amount' => 13.50],
        ['employee_type' => 'Kernet', 'calc_type' => 'Tetap',      'amount' => 150000],
        ['employee_type' => 'Montir', 'calc_type' => 'Tetap',      'amount' => 145000],
        ['employee_type' => 'BBM',    'calc_type' => 'Persentase', 'amount' => 26.50],
        ['employee_type' => 'Agen',   'calc_type' => 'Tetap',      'amount' => 20000],
    ];
}

// Ambil hanya kategori kendaraan milik perusahaan yang dapat diakses user (untuk filter dinamis JS)
$allowedCids = accessible_company_ids($curCompanyId);
$allCategories = [];
if ($allowedCids) {
    $ph = implode(',', array_fill(0, count($allowedCids), '?'));
    $stCats = db()->prepare(
        "SELECT id, company_id, code, name, CONCAT(code, ' - ', name) AS label
           FROM category_vehicles
          WHERE company_id IN ({$ph})
            AND (is_active = 1 OR id = ?)
          ORDER BY code, name"
    );
    $stCats->bind_param(str_repeat('i', count($allowedCids)) . 'i', ...[...$allowedCids, $curCategoryId]);
    $stCats->execute();
    $allCategories = $stCats->get_result()->fetch_all(MYSQLI_ASSOC);
    $stCats->close();
}

$tabTitle = $reg
    ? 'Detail Peraturan Komisi Karyawan (' . $reg['company_code'] . ' - ' . ($reg['note'] ?: ($reg['category_code'] . ' Trip ' . $reg['trip'])) . ')'
    : 'Tambah Peraturan Komisi Karyawan Baru';

$active = 'regulations';
$title  = $tabTitle;
require __DIR__ . '/../../../includes/header.php';
?>

<style>
.reg-panel-card {
    background: #ffffff;
    border: 1px solid #ced4da;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.reg-table thead th {
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    border-bottom: 1px solid #dee2e6;
    padding: 7px 10px;
    font-size: 0.81rem;
}
.reg-table tbody td {
    padding: 6px 10px;
    border-color: #f1f3f5;
    vertical-align: middle;
}
.reg-table tbody tr:hover td {
    background-color: #f8fbff;
}
</style>

<main class="p-2 p-md-3 w-100 flex-grow-1">
    <!-- Top Action Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-white"><i class="bi bi-sliders me-1"></i>Peraturan Komisi Karyawan</span>
            <span class="badge bg-secondary-subtle text-secondary border"><?= $reg ? 'Ubah Peraturan #' . (int) $reg['id'] : 'Draft Peraturan Baru' ?></span>
            <span class="small text-muted d-none d-md-inline">Akses: <strong><?= e($reg ? ($reg['company_code'] . ' - ' . $reg['company_name']) : active_company_name()) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <a href="<?= e(APP_URL) ?>/pages/transport/regulations/index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Kembali
            </a>
            <?php if ($canManage): ?>
                <button type="submit" form="regulationForm" class="btn btn-sm btn-primary px-3">
                    <i class="bi bi-check2-circle me-1"></i>Simpan Peraturan
                </button>
                <?php if ($id > 0): ?>
                    <a href="<?= e(APP_URL) ?>/pages/transport/regulations/form.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-lg me-1"></i>Buat Baru
                    </a>
                    <form method="post" action="" class="d-inline" onsubmit="return confirm('Hapus Peraturan Komisi Karyawan ini?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="act" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>Hapus
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ERP Window Container -->
    <div class="erp-window-card">
        <!-- Sub-Tabs: Detail | Audit Trail -->
        <ul class="nav nav-tabs erp-sub-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-reg-detail" type="button" role="tab">
                    <i class="bi bi-file-earmark-text me-1"></i>Detail Peraturan & Rincian Komisi
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reg-audit" type="button" role="tab">
                    <i class="bi bi-clock-history me-1"></i>Audit Trail
                </button>
            </li>
        </ul>

        <div class="erp-content-body">
            <form method="post" action="" id="regulationForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="save">

                <div class="tab-content">
                    <!-- ================= TAB 1: DETAIL ================= -->
                    <div class="tab-pane fade show active" id="tab-reg-detail" role="tabpanel">
                        <!-- Panel 1: Parameter Peraturan (2 Kolom Seimbang) -->
                        <div class="row g-2 mb-3">
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header"><span>Lingkup Peraturan</span></div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Perusahaan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" name="company_id" id="regCompanySelect" <?= $canManage ? 'required' : 'disabled' ?>>
                                                    <?php foreach ($companies as $c): ?>
                                                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $curCompanyId ? 'selected' : '' ?>>
                                                            <?= e($c['code'] . ' - ' . $c['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Kategori Kendaraan <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <select class="form-select form-select-sm" name="category_vehicle_id" id="regCategorySelect" <?= $canManage ? 'required' : 'disabled' ?>>
                                                    <option value="">-- Pilih Kategori Kendaraan --</option>
                                                    <?php foreach ($allCategories as $cv): ?>
                                                        <?php if ((int) $cv['company_id'] === $curCompanyId || (int) $cv['id'] === $curCategoryId): ?>
                                                            <option value="<?= (int) $cv['id'] ?>" <?= (int) $cv['id'] === $curCategoryId ? 'selected' : '' ?>>
                                                                <?= e($cv['label']) ?>
                                                            </option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Trip ke- <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <input type="number" min="1" step="1" class="form-control form-control-sm" name="trip" value="<?= $curTrip ?>" <?= $canManage ? 'required' : 'readonly' ?> style="max-width:140px;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="rnt-panel h-100 mb-0">
                                    <div class="rnt-panel-header">
                                        <span>Masa Berlaku &amp; Ringkasan</span>
                                        <?php if ($canManage): ?>
                                            <button type="button" class="rnt-header-action" id="btnAutoSummaryNote">
                                                <i class="bi bi-magic me-1"></i>Isi Otomatis Ringkasan
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rnt-panel-body">
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tgl Mulai Berlaku <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <div class="input-group input-group-sm" style="max-width:220px;">
                                                    <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                                                    <input type="text" class="form-control bg-white flatpickr-date" name="start_date" value="<?= e($curStartDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Tgl Selesai Berlaku <span class="text-danger">*</span></label>
                                            <div class="rnt-field">
                                                <div class="input-group input-group-sm" style="max-width:220px;">
                                                    <span class="input-group-text bg-light"><i class="bi bi-calendar-check"></i></span>
                                                    <input type="text" class="form-control bg-white flatpickr-date" name="end_date" value="<?= e($curEndDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="rnt-row">
                                            <label class="rnt-label">Catatan Ringkasan</label>
                                            <div class="rnt-field">
                                                <input type="text" class="form-control form-control-sm" name="note" id="regNoteInput" maxlength="2000" placeholder="Contoh: Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%" value="<?= e($curNote) ?>" <?= $canManage ? '' : 'readonly' ?>>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Panel 2: Komponen Komisi / Premi per Trip -->
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span>Komponen Komisi / Premi per Trip</span>
                                    <span id="regTotalRowsBadge" class="badge bg-primary-subtle text-primary border">0 Komponen</span>
                                </div>
                                <?php if ($canManage): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-3">
                                        <button type="button" class="rnt-header-action" id="btnLoadDefaultPreset" title="Preset Standar LIZ">
                                            <i class="bi bi-arrow-repeat me-1"></i>Preset LIZ
                                        </button>
                                        <button type="button" class="rnt-header-action" id="btnAddRegRow">
                                            <i class="bi bi-plus-lg me-1"></i>Tambah Baris Komisi
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle border-0 mb-0 reg-table" id="regDetailTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 45px;" class="text-center">#</th>
                                            <th style="width: 220px;">Penerima / Pos Komisi</th>
                                            <th style="width: 170px;">Tipe Perhitungan</th>
                                            <th style="width: 240px;" class="text-end">Nilai / Jumlah</th>
                                            <th class="ps-3">Keterangan / Preview</th>
                                            <?php if ($canManage): ?><th style="width: 50px;" class="text-center">Aksi</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody id="regDetailTbody">
                                        <?php foreach ($detailRows as $idx => $dr):
                                            $eType = (string) ($dr['employee_type'] ?? 'Sopir');
                                            $cType = (string) ($dr['calc_type'] ?? 'Tetap');
                                            $amt   = (float) ($dr['amount'] ?? 0);
                                            $isPct = strcasecmp($cType, 'Persentase') === 0;
                                            $dispVal = $isPct
                                                ? number_format($amt, 2, '.', '')
                                                : number_format($amt, 2, '.', ',');
                                        ?>
                                        <tr class="reg-detail-row" data-row-index="<?= $idx ?>">
                                            <td class="text-center text-muted reg-row-num" style="font-size: 0.8rem; font-weight: 600;">
                                                <?= $idx + 1 ?>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm reg-emp-type" name="detail_emp_type[]" <?= $canManage ? '' : 'disabled' ?>>
                                                    <?php
                                                    $empOpts = REGULATION_EMP_TYPES;
                                                    if (!in_array($eType, $empOpts, true) && $eType !== '') {
                                                        $empOpts[] = $eType;
                                                    }
                                                    foreach ($empOpts as $eo): ?>
                                                        <option value="<?= e($eo) ?>" <?= $eType === $eo ? 'selected' : '' ?>><?= e($eo) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm reg-calc-type" name="detail_calc_type[]" <?= $canManage ? '' : 'disabled' ?>>
                                                    <option value="Persentase" <?= $isPct ? 'selected' : '' ?>>Persentase (%)</option>
                                                    <option value="Tetap" <?= !$isPct ? 'selected' : '' ?>>Tetap (Rp)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm reg-amount-group">
                                                    <span class="input-group-text bg-light reg-addon-prefix" <?= $isPct ? 'style="display:none;"' : '' ?>>Rp</span>
                                                    <input type="text" inputmode="decimal" class="form-control form-control-sm text-end fw-semibold reg-amount-input" name="detail_amount[]" value="<?= e($dispVal) ?>" <?= $canManage ? '' : 'readonly' ?>>
                                                    <span class="input-group-text bg-light reg-addon-suffix" <?= !$isPct ? 'style="display:none;"' : '' ?>>%</span>
                                                </div>
                                            </td>
                                            <td class="ps-3">
                                                <span class="reg-preview-text small text-muted"></span>
                                            </td>
                                            <?php if ($canManage): ?>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-reg-row" title="Hapus baris" aria-label="Hapus baris">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light border-top">
                                        <tr>
                                            <td colspan="<?= $canManage ? '6' : '5' ?>" class="py-2 text-end">
                                                <div class="d-inline-flex flex-wrap gap-2 align-items-center justify-content-end">
                                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1" style="font-size: 0.82rem;">
                                                        Total Persentase: <strong id="regTotalPctDisplay">0.00%</strong>
                                                    </span>
                                                    <span class="badge bg-success-subtle text-success border px-2 py-1" style="font-size: 0.82rem;">
                                                        Total Nominal Tetap: <strong id="regTotalFixedDisplay">Rp 0,00</strong>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ================= TAB 2: AUDIT TRAIL ================= -->
                    <div class="tab-pane fade" id="tab-reg-audit" role="tabpanel">
                        <div class="rnt-panel mb-0">
                            <div class="rnt-panel-header"><span>Audit Trail Dokumen</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($reg['created_user'] ?? '')) ?: '-') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Dibuat Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($reg['created_date']) ? date('d-M-Y H:i', strtotime((string) $reg['created_date'])) : '-' ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Oleh</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e(trim((string) ($reg['modified_user'] ?? '')) ?: '-') ?>" readonly>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Diubah Tanggal</label>
                                    <div class="rnt-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($reg['modified_date']) ? date('d-M-Y H:i', strtotime((string) $reg['modified_date'])) : '-' ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const allCategories = <?= json_encode($allCategories) ?>;
    const compSel       = document.getElementById('regCompanySelect');
    const catSel        = document.getElementById('regCategorySelect');
    const tbody         = document.getElementById('regDetailTbody');
    const totalPctDisp  = document.getElementById('regTotalPctDisplay');
    const totalFixDisp  = document.getElementById('regTotalFixedDisplay');
    const totalRowsBadge = document.getElementById('regTotalRowsBadge');
    const noteInput     = document.getElementById('regNoteInput');

    document.querySelectorAll('.flatpickr-date').forEach((el) => {
        flatpickr(el, {
            altInput: true,
            altFormat: "d-M-Y",
            dateFormat: "Y-m-d",
            altInputClass: el.className,
            allowInput: true
        });
    });

    if (compSel && catSel) {
        compSel.addEventListener('change', () => {
            const cid = parseInt(compSel.value || '0', 10);
            const curVal = parseInt(catSel.value || '0', 10);
            catSel.innerHTML = '<option value="">-- Pilih Kategori Kendaraan --</option>';
            allCategories.filter(c => parseInt(c.company_id, 10) === cid).forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.label;
                if (parseInt(c.id, 10) === curVal) opt.selected = true;
                catSel.appendChild(opt);
            });
        });
    }

    function parseRowVal(calcType, rawStr) {
        const s = String(rawStr || '').trim();
        if (!s) return 0;
        if (calcType === 'Persentase') {
            const clean = s.replace(/[^0-9.,]/g, '').replace(',', '.');
            const num = parseFloat(clean);
            return isNaN(num) ? 0 : num;
        } else {
            let clean = s;
            if (/,00$|\.00$/.test(clean)) {
                clean = clean.replace(/,00$|\.00$/, '');
            }
            clean = clean.replace(/[^0-9]/g, '');
            const num = parseFloat(clean);
            return isNaN(num) ? 0 : num;
        }
    }

    function formatNumberDisplay(num, calcType = 'Tetap') {
        const val = Number(num) || 0;
        return val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateRowIndicators() {
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        rows.forEach((tr, idx) => {
            const numCell = tr.querySelector('.reg-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
        });

        if (totalRowsBadge) {
            totalRowsBadge.textContent = `${rows.length} Komponen`;
        }
    }

    function updateRowPreview(tr) {
        const cType = tr.querySelector('.reg-calc-type')?.value || 'Tetap';
        const inp   = tr.querySelector('.reg-amount-input');
        const prev  = tr.querySelector('.reg-preview-text');
        const preAddon = tr.querySelector('.reg-addon-prefix');
        const sufAddon = tr.querySelector('.reg-addon-suffix');

        const val = parseRowVal(cType, inp ? inp.value : '0');

        if (cType === 'Persentase') {
            if (preAddon) preAddon.style.display = 'none';
            if (sufAddon) sufAddon.style.display = '';
            if (prev) {
                prev.innerHTML = `<span class="badge bg-info-subtle text-info border">${val.toFixed(2)}% dari Total Biaya Sewa</span>`;
            }
        } else {
            if (preAddon) preAddon.style.display = '';
            if (sufAddon) sufAddon.style.display = 'none';
            if (prev) {
                prev.innerHTML = `<span class="badge bg-secondary-subtle text-secondary border">Rp ${val.toLocaleString('id-ID')} / trip</span>`;
            }
        }
    }

    function recalcRegTotals() {
        if (!tbody) return;
        let sumPct = 0;
        let sumFixed = 0;
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        rows.forEach((tr) => {
            const cType = tr.querySelector('.reg-calc-type')?.value || 'Tetap';
            const inp   = tr.querySelector('.reg-amount-input');
            const val   = parseRowVal(cType, inp ? inp.value : '0');
            if (cType === 'Persentase') {
                sumPct += val;
            } else {
                sumFixed += val;
            }
            updateRowPreview(tr);
        });

        if (totalPctDisp) {
            totalPctDisp.textContent = sumPct.toFixed(2) + '%';
        }
        if (totalFixDisp) {
            totalFixDisp.textContent = 'Rp ' + sumFixed.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        updateRowIndicators();
    }

    function buildAutoSummary() {
        if (!tbody) return '';
        const parts = [];
        tbody.querySelectorAll('tr.reg-detail-row').forEach((tr) => {
            const eType = tr.querySelector('.reg-emp-type')?.value || '';
            const cType = tr.querySelector('.reg-calc-type')?.value || 'Tetap';
            const inp   = tr.querySelector('.reg-amount-input');
            const val   = parseRowVal(cType, inp ? inp.value : '0');
            if (!eType || val <= 0) return;
            if (cType === 'Persentase') {
                const v = rtrimZeros(val.toFixed(2));
                parts.push(`${eType} ${v}%`);
            } else if (val >= 1000 && val % 1000 === 0) {
                parts.push(`${eType} ${val / 1000}k`);
            } else {
                parts.push(`${eType} ${val.toLocaleString('id-ID')}`);
            }
        });
        return parts.join(', ');
    }

    function rtrimZeros(valStr) {
        return valStr.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
    }

    function bindRegRow(tr) {
        const cSel = tr.querySelector('.reg-calc-type');
        const inp  = tr.querySelector('.reg-amount-input');
        const del  = tr.querySelector('.btn-remove-reg-row');
        const eSel = tr.querySelector('.reg-emp-type');

        if (cSel && inp) {
            cSel.addEventListener('change', () => {
                const v = parseRowVal(cSel.value, inp.value);
                inp.value = formatNumberDisplay(v, cSel.value);
                recalcRegTotals();
            });

            inp.addEventListener('blur', function () {
                const cType = cSel.value;
                const v = parseRowVal(cType, this.value);
                this.value = formatNumberDisplay(v, cType);
                recalcRegTotals();
            });
        }

        if (eSel) {
            eSel.addEventListener('change', () => {
                recalcRegTotals();
            });
        }

        if (del) {
            del.addEventListener('click', () => {
                tr.remove();
                recalcRegTotals();
            });
        }

        updateRowPreview(tr);
    }

    function addRegRow(empType = 'Agen', calcType = 'Tetap', amount = 0) {
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.className = 'reg-detail-row';
        const disp = formatNumberDisplay(amount, calcType);
        const isPct = calcType === 'Persentase';
        tr.innerHTML = `
            <td class="text-center text-muted reg-row-num" style="font-size: 0.8rem; font-weight: 600;">1</td>
            <td>
                <select class="form-select form-select-sm reg-emp-type" name="detail_emp_type[]">
                    <option value="Sopir" ${empType === 'Sopir' ? 'selected' : ''}>Sopir</option>
                    <option value="Sopir 1" ${empType === 'Sopir 1' ? 'selected' : ''}>Sopir 1</option>
                    <option value="Sopir 2" ${empType === 'Sopir 2' ? 'selected' : ''}>Sopir 2</option>
                    <option value="Sopir Cadangan" ${empType === 'Sopir Cadangan' ? 'selected' : ''}>Sopir Cadangan</option>
                    <option value="Kernet" ${empType === 'Kernet' ? 'selected' : ''}>Kernet</option>
                    <option value="Montir" ${empType === 'Montir' ? 'selected' : ''}>Montir</option>
                    <option value="BBM" ${empType === 'BBM' ? 'selected' : ''}>BBM</option>
                    <option value="Agen" ${empType === 'Agen' ? 'selected' : ''}>Agen</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm reg-calc-type" name="detail_calc_type[]">
                    <option value="Persentase" ${isPct ? 'selected' : ''}>Persentase (%)</option>
                    <option value="Tetap" ${!isPct ? 'selected' : ''}>Tetap (Rp)</option>
                </select>
            </td>
            <td>
                <div class="input-group input-group-sm reg-amount-group">
                    <span class="input-group-text bg-light reg-addon-prefix" ${isPct ? 'style="display:none;"' : ''}>Rp</span>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm text-end fw-semibold reg-amount-input" name="detail_amount[]" value="${disp}">
                    <span class="input-group-text bg-light reg-addon-suffix" ${!isPct ? 'style="display:none;"' : ''}>%</span>
                </div>
            </td>
            <td class="ps-3">
                <span class="reg-preview-text small text-muted"></span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-reg-row" title="Hapus baris" aria-label="Hapus baris">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
        bindRegRow(tr);
        recalcRegTotals();
        const inp = tr.querySelector('.reg-amount-input');
        if (inp) inp.focus();
    }

    if (tbody) {
        tbody.querySelectorAll('tr.reg-detail-row').forEach(bindRegRow);
        recalcRegTotals();
    }

    const btnAdd = document.getElementById('btnAddRegRow');
    if (btnAdd) {
        btnAdd.addEventListener('click', () => addRegRow('Agen', 'Tetap', 0));
    }

    const btnPreset = document.getElementById('btnLoadDefaultPreset');
    if (btnPreset && tbody) {
        btnPreset.addEventListener('click', () => {
            if (confirm('Muat ulang baris dengan template preset standar LIZ (Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%, Agen 20k, 25k, 5k)?')) {
                tbody.innerHTML = '';
                addRegRow('Sopir', 'Persentase', 13.50);
                addRegRow('Kernet', 'Tetap', 150000);
                addRegRow('Montir', 'Tetap', 145000);
                addRegRow('BBM', 'Persentase', 26.50);
                addRegRow('Agen', 'Tetap', 20000);
                addRegRow('Agen', 'Tetap', 25000);
                addRegRow('Agen', 'Tetap', 5000);
                if (noteInput && !noteInput.value.trim()) {
                    noteInput.value = 'Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%';
                }
                recalcRegTotals();
            }
        });
    }

    const btnAutoNote = document.getElementById('btnAutoSummaryNote');
    if (btnAutoNote && noteInput) {
        btnAutoNote.addEventListener('click', () => {
            noteInput.value = buildAutoSummary();
        });
    }
});
</script>
<?php require __DIR__ . '/../../../includes/footer.php'; ?>
