<?php
require_once __DIR__ . '/../../../config/config.php';
require_permission('rental.view');
require_once __DIR__ . '/../../travel/_helpers.php';
require_once __DIR__ . '/../../../includes/regulation_service.php';

regulation_ensure_schema();

$companies  = accessible_companies();
$active_cid = active_company_id();
$canManage  = can('rental.manage');
$user       = $_SESSION['username'] ?? null;

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$reg = $id > 0 ? regulation_find($id) : null;
if ($id > 0 && !$reg) {
    flash('error', 'Peraturan Komisi Karyawan tidak ditemukan.');
    redirect('pages/rental/regulations/index.php');
}
if ($reg) {
    assert_company_access((int) $reg['company_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'Anda tidak memiliki izin mengelola Peraturan Komisi Karyawan.');
        redirect('pages/rental/regulations/index.php');
    }
    $act = (string) ($_POST['act'] ?? 'save');

    try {
        if ($act === 'delete' && $id > 0 && $reg) {
            regulation_delete($id, (int) $reg['company_id']);
            audit('rental.regulation.delete', "Hapus Peraturan Komisi Karyawan #{$id}");
            flash('success', 'Peraturan Komisi Karyawan berhasil dihapus.');
            redirect('pages/rental/regulations/index.php');
        }

        $companyId  = (int) ($_POST['company_id'] ?? ($reg['company_id'] ?? $active_cid ?? 0));
        assert_company_access($companyId);
        $categoryId = (int) ($_POST['category_vehicle_id'] ?? ($reg['category_vehicle_id'] ?? 0));
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
        redirect('pages/rental/regulations/form.php?id=' . $savedId);
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage() ?: 'Gagal menyimpan Peraturan Komisi Karyawan.');
        if ($id > 0) {
            redirect('pages/rental/regulations/form.php?id=' . $id);
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

// Ambil semua kategori kendaraan milik perusahaan yang dapat diakses (untuk filter dinamis JS)
$allCategories = db()->query(
    "SELECT id, company_id, code, name, CONCAT(code, ' - ', name) AS label
       FROM category_vehicles
      WHERE is_active = 1
      ORDER BY code, name"
)->fetch_all(MYSQLI_ASSOC);

$tabTitle = $reg
    ? 'Detail Peraturan Komisi Karyawan (' . $reg['company_code'] . ' - ' . ($reg['note'] ?: ($reg['category_code'] . ' Trip ' . $reg['trip'])) . ')'
    : 'Tambah Peraturan Komisi Karyawan Baru';

$active = 'rental';
$title  = $tabTitle;
require __DIR__ . '/../../../includes/header.php';
?>
<style>
.reg-workspace {
    background: #f4f6f9;
    border: 1px solid #d0d7de;
    border-radius: 6px;
    overflow: hidden;
    font-size: 0.85rem;
}
.reg-doc-tabs {
    background: #e9ecef;
    border-bottom: 1px solid #ced4da;
    padding: 6px 10px 0;
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.reg-doc-tab {
    background: #dee2e6;
    color: #495057;
    border: 1px solid #ced4da;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    padding: 5px 12px;
    font-size: 0.8rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.reg-doc-tab:hover { background: #e2e6ea; color: #212529; }
.reg-doc-tab.active {
    background: #fff;
    color: #212529;
    font-weight: 600;
    margin-bottom: -1px;
    padding-bottom: 6px;
}
.reg-inner-tabs {
    background: #fff;
    border-bottom: 1px solid #dee2e6;
    padding: 8px 14px 0;
}
.reg-inner-tabs .nav-link {
    font-size: 0.82rem;
    padding: 5px 14px;
    color: #495057;
    border-radius: 4px 4px 0 0;
}
.reg-inner-tabs .nav-link.active {
    font-weight: 600;
    color: #0d6efd;
}
.reg-panel {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    margin-bottom: 12px;
}
.reg-panel-header {
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    padding: 6px 12px;
    font-weight: 600;
    font-size: 0.82rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.reg-panel-body { padding: 12px; }
.reg-row {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
    gap: 10px;
}
.reg-row:last-child { margin-bottom: 0; }
.reg-label {
    width: 155px;
    flex-shrink: 0;
    color: #343a40;
    font-size: 0.82rem;
    margin-bottom: 0;
}
.reg-field { flex-grow: 1; min-width: 0; }
@media (max-width: 767.98px) {
    .reg-row { flex-direction: column; align-items: stretch; gap: 3px; }
    .reg-label { width: 100%; font-weight: 600; }
}
</style>

<main class="p-2 p-lg-3 w-100 flex-grow-1">
    <div class="reg-workspace shadow-sm">
        <!-- Top Document Tabs (Seperti Desktop ERP pada Screenshot) -->
        <div class="reg-doc-tabs">
            <a href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php" class="reg-doc-tab">
                <i class="bi bi-list-ul"></i>Daftar Pemesanan Sewa Kendaraan
            </a>
            <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="reg-doc-tab">
                <i class="bi bi-sliders"></i>Daftar Peraturan Komisi Karyawan
            </a>
            <span class="reg-doc-tab active">
                <i class="bi bi-file-earmark-ruled text-primary"></i><?= e($tabTitle) ?>
            </span>
            <div class="ms-auto d-flex align-items-center gap-1 pb-1">
                <?php if ($canManage && $id > 0): ?>
                    <a href="<?= e(APP_URL) ?>/pages/rental/regulations/form.php" class="btn btn-sm btn-outline-primary py-0 px-2">
                        <i class="bi bi-plus-lg me-1"></i>Buat Baru
                    </a>
                    <form method="post" action="" class="d-inline" onsubmit="return confirm('Hapus Peraturan Komisi Karyawan ini?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="act" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                            <i class="bi bi-trash me-1"></i>Hapus
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sub-Tab: Detail | Audit Trail -->
        <ul class="nav nav-tabs reg-inner-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-reg-detail" type="button" role="tab">Detail</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reg-audit" type="button" role="tab">Audit Trail</button>
            </li>
        </ul>

        <div class="p-3 bg-white">
            <form method="post" action="" id="regulationForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="act" value="save">

                <div class="tab-content">
                    <!-- ================= TAB 1: DETAIL ================= -->
                    <div class="tab-pane fade show active" id="tab-reg-detail" role="tabpanel">
                        <div class="reg-panel">
                            <div class="reg-panel-body">
                                <div class="reg-row">
                                    <label class="reg-label">Perusahaan <span class="text-danger">*</span></label>
                                    <div class="reg-field">
                                        <select class="form-select form-select-sm" name="company_id" id="regCompanySelect" <?= $canManage ? 'required' : 'disabled' ?>>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $curCompanyId ? 'selected' : '' ?>>
                                                    <?= e($c['code'] . ' - ' . $c['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="reg-row">
                                    <label class="reg-label">Kategori Kendaraan <span class="text-danger">*</span></label>
                                    <div class="reg-field">
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

                                <div class="reg-row">
                                    <label class="reg-label">Trip <span class="text-danger">*</span></label>
                                    <div class="reg-field">
                                        <input type="number" min="1" step="1" class="form-control form-control-sm" name="trip" value="<?= $curTrip ?>" <?= $canManage ? 'required' : 'readonly' ?>>
                                    </div>
                                </div>

                                <div class="reg-row">
                                    <label class="reg-label">Tanggal Mulai <span class="text-danger">*</span></label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="start_date" value="<?= e($curStartDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                    </div>
                                </div>

                                <div class="reg-row">
                                    <label class="reg-label">Tanggal Selesai <span class="text-danger">*</span></label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="end_date" value="<?= e($curEndDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                    </div>
                                </div>

                                <div class="reg-row align-items-start">
                                    <label class="reg-label pt-1">
                                        Catatan
                                        <?php if ($canManage): ?>
                                            <div class="mt-1">
                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnAutoSummaryNote" style="font-size:.75rem;">
                                                    <i class="bi bi-magic me-1"></i>Isi Otomatis
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </label>
                                    <div class="reg-field">
                                        <textarea class="form-control form-control-sm" name="note" id="regNoteInput" rows="3" maxlength="2000" placeholder="Contoh: Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5% (Kosongkan untuk diisi otomatis)" <?= $canManage ? '' : 'readonly' ?>><?= e($curNote) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Grid Rincian Komisi / Premi Karyawan -->
                        <div class="reg-panel mb-0">
                            <div class="reg-panel-header">
                                <span>Rincian Komisi &amp; Premi Karyawan / BBM</span>
                                <?php if ($canManage): ?>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnLoadDefaultPreset" title="Muat baris standar (Sopir, Kernet, Montir, BBM, Agen)">
                                        <i class="bi bi-lightning-charge me-1"></i>Preset Standar
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddRegRow">
                                        <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0 align-middle" id="regDetailTable" style="font-size:.84rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px;" class="text-center">#</th>
                                            <th style="width:240px;">Tipe Karyawan</th>
                                            <th style="width:220px;">Tipe</th>
                                            <th class="text-end">Jumlah</th>
                                            <?php if ($canManage): ?><th style="width:46px;" class="text-center">Aksi</th><?php endif; ?>
                                        </tr>
                                        <?php if ($canManage): ?>
                                        <tr class="bg-light text-muted small" style="cursor:pointer;" id="regQuickAddBanner" title="Klik untuk menambah baris baru">
                                            <td class="text-center text-warning"><i class="bi bi-sun-fill"></i></td>
                                            <td colspan="4" class="text-center fst-italic py-1">Click here to add a new row</td>
                                        </tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody id="regDetailTbody">
                                        <?php foreach ($detailRows as $idx => $dr):
                                            $eType = (string) ($dr['employee_type'] ?? 'Sopir');
                                            $cType = (string) ($dr['calc_type'] ?? 'Tetap');
                                            $amt   = (float) ($dr['amount'] ?? 0);
                                            $isPct = strcasecmp($cType, 'Persentase') === 0;
                                            $dispVal = $isPct
                                                ? number_format($amt, 2, '.', '')
                                                : format_nominal($amt);
                                        ?>
                                        <tr class="reg-detail-row">
                                            <td class="text-center text-muted reg-row-num"><?= $idx + 1 ?></td>
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
                                                    <?php foreach (REGULATION_CALC_TYPES as $co): ?>
                                                        <option value="<?= e($co) ?>" <?= strcasecmp($cType, $co) === 0 ? 'selected' : '' ?>><?= e($co) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" inputmode="decimal" class="form-control form-control-sm text-end fw-semibold reg-amount-input" name="detail_amount[]" value="<?= e($dispVal) ?>" <?= $canManage ? '' : 'readonly' ?>>
                                            </td>
                                            <?php if ($canManage): ?>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-reg-row" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="py-2">
                                                <div class="d-flex align-items-center gap-2 small text-muted">
                                                    <span id="regRecordCount">Record 1 of <?= count($detailRows) ?></span>
                                                    <?php if ($canManage): ?>
                                                    <div class="btn-group btn-group-sm ms-2">
                                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterAddReg" title="Tambah Baris">+</button>
                                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnFooterDelReg" title="Hapus Baris Terakhir">&minus;</button>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-2">
                                                <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="regTotalDisplay" value="0.00" readonly>
                                            </td>
                                            <?php if ($canManage): ?><td></td><?php endif; ?>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ================= TAB 2: AUDIT TRAIL ================= -->
                    <div class="tab-pane fade" id="tab-reg-audit" role="tabpanel">
                        <div class="reg-panel mb-0">
                            <div class="reg-panel-body">
                                <div class="reg-row">
                                    <label class="reg-label">Dibuat Oleh</label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e((string) ($reg['created_user'] ?? '-')) ?>" readonly>
                                    </div>
                                </div>
                                <div class="reg-row">
                                    <label class="reg-label">Dibuat Tanggal</label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($reg['created_date']) ? date('d-M-Y H:i', strtotime((string) $reg['created_date'])) : '-' ?>" readonly>
                                    </div>
                                </div>
                                <div class="reg-row">
                                    <label class="reg-label">Diubah Oleh</label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= e((string) ($reg['modified_user'] ?? '-')) ?>" readonly>
                                    </div>
                                </div>
                                <div class="reg-row">
                                    <label class="reg-label">Diubah Tanggal</label>
                                    <div class="reg-field">
                                        <input type="text" class="form-control form-control-sm bg-light" value="<?= !empty($reg['modified_date']) ? date('d-M-Y H:i', strtotime((string) $reg['modified_date'])) : '-' ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($canManage): ?>
                <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top">
                    <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="btn btn-light btn-sm px-3">Kembali</a>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check2-circle me-1"></i>Simpan Peraturan Komisi
                    </button>
                </div>
                <?php endif; ?>
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
    const totalDisp     = document.getElementById('regTotalDisplay');
    const recCountEl    = document.getElementById('regRecordCount');
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
            let str = s;
            if (/^\d+\.(0|00)$/.test(str)) {
                str = str.replace(/\.(0|00)$/, '');
            }
            const digits = str.replace(/[^0-9]/g, '');
            return digits ? parseInt(digits, 10) : 0;
        }
    }

    function formatNominalId(num) {
        const n = Math.round(Number(num) || 0);
        if (n === 0) return '0';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function recalcRegTotal() {
        if (!tbody) return;
        let sum = 0;
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        rows.forEach((tr, idx) => {
            const numCell = tr.querySelector('.reg-row-num');
            if (numCell) numCell.textContent = String(idx + 1);
            const cType = tr.querySelector('.reg-calc-type')?.value || 'Tetap';
            const inp   = tr.querySelector('.reg-amount-input');
            sum += parseRowVal(cType, inp ? inp.value : '0');
        });
        if (totalDisp) {
            totalDisp.value = sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (recCountEl) {
            recCountEl.textContent = 'Record ' + (rows.length ? 1 : 0) + ' of ' + rows.length;
        }
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
                parts.push(`${eType} ${val}%`);
            } else if (val >= 1000 && val % 1000 === 0) {
                parts.push(`${eType} ${val / 1000}k`);
            } else {
                parts.push(`${eType} ${formatNominalId(val)}`);
            }
        });
        return parts.join(', ');
    }

    function bindRegRow(tr) {
        const cSel = tr.querySelector('.reg-calc-type');
        const inp  = tr.querySelector('.reg-amount-input');
        const del  = tr.querySelector('.btn-remove-reg-row');

        if (cSel && inp) {
            cSel.addEventListener('change', () => {
                const v = parseRowVal(cSel.value, inp.value);
                if (cSel.value === 'Persentase') {
                    inp.value = v > 100 ? '10.00' : v.toFixed(2);
                } else {
                    inp.value = formatNominalId(v);
                }
                recalcRegTotal();
            });

            inp.addEventListener('input', function () {
                const cType = cSel.value;
                if (cType === 'Tetap') {
                    const clean = this.value.replace(/[^0-9]/g, '');
                    const num = parseInt(clean || '0', 10);
                    this.value = clean === '' ? '' : formatNominalId(num);
                }
                recalcRegTotal();
            });

            inp.addEventListener('blur', function () {
                const cType = cSel.value;
                const v = parseRowVal(cType, this.value);
                if (cType === 'Persentase') {
                    this.value = v.toFixed(2);
                } else {
                    this.value = formatNominalId(v);
                }
                recalcRegTotal();
            });
        }

        if (del) {
            del.addEventListener('click', () => {
                tr.remove();
                recalcRegTotal();
            });
        }
    }

    function addRegRow(empType = 'Agen', calcType = 'Tetap', amount = 0) {
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.className = 'reg-detail-row';
        const disp = calcType === 'Persentase' ? Number(amount).toFixed(2) : formatNominalId(amount);
        tr.innerHTML = `
            <td class="text-center text-muted reg-row-num">1</td>
            <td>
                <select class="form-select form-select-sm reg-emp-type" name="detail_emp_type[]">
                    <option value="Sopir" ${empType === 'Sopir' ? 'selected' : ''}>Sopir</option>
                    <option value="Kernet" ${empType === 'Kernet' ? 'selected' : ''}>Kernet</option>
                    <option value="Montir" ${empType === 'Montir' ? 'selected' : ''}>Montir</option>
                    <option value="BBM" ${empType === 'BBM' ? 'selected' : ''}>BBM</option>
                    <option value="Agen" ${empType === 'Agen' ? 'selected' : ''}>Agen</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm reg-calc-type" name="detail_calc_type[]">
                    <option value="Persentase" ${calcType === 'Persentase' ? 'selected' : ''}>Persentase</option>
                    <option value="Tetap" ${calcType === 'Tetap' ? 'selected' : ''}>Tetap</option>
                </select>
            </td>
            <td>
                <input type="text" inputmode="decimal" class="form-control form-control-sm text-end fw-semibold reg-amount-input" name="detail_amount[]" value="${disp}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-reg-row" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
        bindRegRow(tr);
        recalcRegTotal();
    }

    if (tbody) {
        tbody.querySelectorAll('tr.reg-detail-row').forEach(bindRegRow);
        recalcRegTotal();
    }

    ['btnAddRegRow', 'regQuickAddBanner', 'btnFooterAddReg'].forEach((btnId) => {
        const el = document.getElementById(btnId);
        if (el) el.addEventListener('click', () => addRegRow('Agen', 'Tetap', 0));
    });

    const btnDelLast = document.getElementById('btnFooterDelReg');
    if (btnDelLast && tbody) {
        btnDelLast.addEventListener('click', () => {
            const rows = tbody.querySelectorAll('tr.reg-detail-row');
            if (rows.length > 1) {
                rows[rows.length - 1].remove();
                recalcRegTotal();
            }
        });
    }

    const btnPreset = document.getElementById('btnLoadDefaultPreset');
    if (btnPreset && tbody) {
        btnPreset.addEventListener('click', () => {
            tbody.innerHTML = '';
            addRegRow('Sopir', 'Persentase', 13.50);
            addRegRow('Kernet', 'Tetap', 150000);
            addRegRow('Montir', 'Tetap', 145000);
            addRegRow('BBM', 'Persentase', 26.50);
            addRegRow('Agen', 'Tetap', 20000);
            addRegRow('Agen', 'Tetap', 25000);
            addRegRow('Agen', 'Tetap', 5000);
            if (noteInput && !noteInput.value.trim()) {
                noteInput.value = buildAutoSummary();
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

