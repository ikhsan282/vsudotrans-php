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
    border-radius: 4px;
    overflow: hidden;
    font-size: 0.84rem;
}
.reg-doc-tabs {
    background: #e9ecef;
    border-bottom: 1px solid #ced4da;
    padding: 6px 8px 0;
    display: flex;
    gap: 3px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.reg-doc-tab {
    background: #dee2e6;
    color: #495057;
    border: 1px solid #ced4da;
    border-bottom: none;
    border-radius: 3px 3px 0 0;
    padding: 4px 11px;
    font-size: 0.79rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
.reg-doc-tab:hover { background: #e2e6ea; color: #212529; }
.reg-doc-tab.active {
    background: #fff;
    color: #212529;
    font-weight: 600;
    margin-bottom: -1px;
    padding-bottom: 5px;
    border-top: 2px solid #0078d4;
}
.reg-doc-tab .tab-close {
    font-size: 0.72rem;
    margin-left: 6px;
    color: #6c757d;
    cursor: pointer;
    border-radius: 50%;
    padding: 0 3px;
}
.reg-doc-tab .tab-close:hover {
    color: #dc3545;
    background: #f8d7da;
}
.reg-inner-tabs {
    background: #fff;
    border-bottom: 1px solid #dee2e6;
    padding: 6px 12px 0;
}
.reg-inner-tabs .nav-link {
    font-size: 0.81rem;
    padding: 4px 12px;
    color: #495057;
    border-radius: 3px 3px 0 0;
}
.reg-inner-tabs .nav-link.active {
    font-weight: 600;
    color: #0078d4;
    border-color: #dee2e6 #dee2e6 #fff;
}
.reg-form-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    padding: 10px 14px;
}
.reg-row {
    display: flex;
    align-items: center;
    margin-bottom: 6px;
    gap: 10px;
}
.reg-row:last-child { margin-bottom: 0; }
.reg-label {
    width: 165px;
    flex-shrink: 0;
    color: #212529;
    font-size: 0.82rem;
    margin-bottom: 0;
    font-weight: 500;
}
.reg-field { flex-grow: 1; min-width: 0; }
.reg-blue-divider {
    height: 2px;
    background: #0078d4;
    margin: 12px 0 8px 0;
}
.reg-table {
    border: 1px solid #ced4da;
    font-size: 0.83rem;
    background: #fff;
}
.reg-table th {
    background: #f8f9fa;
    color: #212529;
    font-weight: 600;
    border-bottom: 1px solid #ced4da;
    padding: 5px 8px;
}
.reg-table td {
    padding: 3px 6px;
    border-color: #e9ecef;
}
.reg-detail-row.active-row td {
    background-color: #f0f7ff;
}
.reg-nav-btn {
    font-family: inherit;
    font-size: 0.78rem;
    line-height: 1.2;
    padding: 2px 7px;
    border: 1px solid #ced4da;
    background: #fff;
    color: #495057;
    border-radius: 2px;
}
.reg-nav-btn:hover { background: #e9ecef; color: #212529; }
.reg-status-bar {
    background: #f8f9fa;
    border-top: 1px solid #ced4da;
    padding: 4px 12px;
    font-size: 0.76rem;
    color: #495057;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
@media (max-width: 767.98px) {
    .reg-row { flex-direction: column; align-items: stretch; gap: 3px; }
    .reg-label { width: 100%; font-weight: 600; }
}
</style>

<main class="p-2 p-lg-3 w-100 flex-grow-1">
    <div class="reg-workspace shadow-sm">
        <!-- Top Document Tabs (Persis seperti Desktop ERP pada Screenshot) -->
        <div class="reg-doc-tabs">
            <a href="<?= e(APP_URL) ?>/pages/rental/bookings/index.php" class="reg-doc-tab">
                Daftar Pemesanan Sewa Kendaraan
            </a>
            <?php
            // Ambil nomor booking rental terakhir jika ada untuk tab referensi
            $lastBooking = db()->query("SELECT id, document_number FROM rental_car_bookings ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $lastBkNum   = $lastBooking['document_number'] ?? '10000120261110';
            $lastBkId    = $lastBooking['id'] ?? 0;
            ?>
            <a href="<?= e(APP_URL) ?>/pages/rental/bookings/detail.php?id=<?= $lastBkId ?>" class="reg-doc-tab text-secondary">
                Detail Pemesanan Sewa Kendaraan (<?= e($lastBkNum) ?>)
            </a>
            <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="reg-doc-tab">
                Daftar Peraturan Komisi Karyawan
            </a>
            <span class="reg-doc-tab active">
                <?= e($tabTitle) ?>
                <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="tab-close text-decoration-none" title="Tutup Tab">&times;</a>
            </span>
            <div class="ms-auto d-flex align-items-center gap-1 pb-1">
                <?php if ($canManage && $id > 0): ?>
                    <a href="<?= e(APP_URL) ?>/pages/rental/regulations/form.php" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.78rem;">
                        <i class="bi bi-plus-lg me-1"></i>Buat Baru
                    </a>
                    <form method="post" action="" class="d-inline" onsubmit="return confirm('Hapus Peraturan Komisi Karyawan ini?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="act" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.78rem;">
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
                        <!-- Header Form Fields -->
                        <div class="reg-form-card mb-2">
                            <div class="reg-row">
                                <label class="reg-label">Perusahaan</label>
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
                                <label class="reg-label">Kategori Kendaraan</label>
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
                                <label class="reg-label">Trip</label>
                                <div class="reg-field">
                                    <input type="number" min="1" step="1" class="form-control form-control-sm" name="trip" value="<?= $curTrip ?>" <?= $canManage ? 'required' : 'readonly' ?>>
                                </div>
                            </div>

                            <div class="reg-row">
                                <label class="reg-label">Tanggal Mulai</label>
                                <div class="reg-field">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="start_date" value="<?= e($curStartDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                </div>
                            </div>

                            <div class="reg-row">
                                <label class="reg-label">Tanggal Selesai</label>
                                <div class="reg-field">
                                    <input type="text" class="form-control form-control-sm bg-white flatpickr-date" name="end_date" value="<?= e($curEndDate) ?>" placeholder="dd-MMM-yyyy" <?= $canManage ? 'required' : 'disabled' ?>>
                                </div>
                            </div>

                            <div class="reg-row align-items-start">
                                <label class="reg-label pt-1">
                                    Catatan
                                    <?php if ($canManage): ?>
                                        <div class="mt-1">
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnAutoSummaryNote" style="font-size:.73rem;">
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

                        <!-- Solid Blue Divider Line (Seperti Desktop ERP) -->
                        <div class="reg-blue-divider"></div>

                        <!-- Grid Rincian Komisi / Premi Karyawan -->
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 align-middle reg-table" id="regDetailTable">
                                <thead>
                                    <tr>
                                        <th style="width: 32px;" class="text-center text-warning" title="Row indicator">
                                            <i class="bi bi-sun-fill" style="color: #f59e0b;"></i>
                                        </th>
                                        <th style="width: 220px;">Tipe Karyawan</th>
                                        <th style="width: 180px;">Tipe</th>
                                        <th class="text-center text-muted fst-italic py-1" id="regQuickAddBanner" style="cursor: pointer; background: #fafafa;" title="Klik untuk menambah baris baru">
                                            Click here to add a new row
                                        </th>
                                        <th style="width: 170px;" class="text-end">Jumlah</th>
                                        <?php if ($canManage): ?><th style="width: 38px;" class="text-center">Aksi</th><?php endif; ?>
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
                                        $rowActive = $idx === 0 ? ' active-row' : '';
                                    ?>
                                    <tr class="reg-detail-row<?= $rowActive ?>" data-row-index="<?= $idx ?>">
                                        <td class="text-center text-muted reg-row-num" style="width: 32px; font-size: 0.75rem;">
                                            <?= $idx === 0 ? '▶' : ($idx + 1) ?>
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
                                                <?php foreach (REGULATION_CALC_TYPES as $co): ?>
                                                    <option value="<?= e($co) ?>" <?= strcasecmp($cType, $co) === 0 ? 'selected' : '' ?>><?= e($co) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-muted small fst-italic"></td>
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
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="py-2 bg-light">
                                            <!-- DevExpress / Access Style Bottom Navigation Bar -->
                                            <div class="d-flex align-items-center gap-1 font-monospace" style="font-size: 0.8rem;">
                                                <button type="button" class="reg-nav-btn" id="navFirst" title="Baris Pertama">|&lt;&lt;</button>
                                                <button type="button" class="reg-nav-btn" id="navPrev" title="Baris Sebelumnya">&lt;</button>
                                                <span class="px-2 text-dark font-sans-serif" id="regRecordCount" style="font-family: inherit; font-size: 0.8rem;">
                                                    Record 1 of <?= count($detailRows) ?>
                                                </span>
                                                <button type="button" class="reg-nav-btn" id="navNext" title="Baris Berikutnya">&gt;</button>
                                                <button type="button" class="reg-nav-btn" id="navLast" title="Baris Terakhir">&gt;&gt;|</button>
                                                <?php if ($canManage): ?>
                                                    <button type="button" class="reg-nav-btn ms-2 fw-bold" id="btnFooterAddReg" title="Tambah Baris Baru">+</button>
                                                    <button type="button" class="reg-nav-btn fw-bold" id="btnFooterDelReg" title="Hapus Baris Aktif">&minus;</button>
                                                    <button type="button" class="reg-nav-btn" id="btnFooterEditReg" title="Edit Baris">✎</button>
                                                    <button type="button" class="reg-nav-btn text-success fw-bold" id="btnFooterSaveReg" title="Simpan Peraturan">✓</button>
                                                    <button type="button" class="reg-nav-btn text-danger" id="btnFooterCancelReg" title="Batal / Reset">✕</button>
                                                    <button type="button" class="btn btn-xs btn-outline-secondary ms-2 py-0 px-2" id="btnLoadDefaultPreset" title="Preset Standar (LIZ Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%)" style="font-size:0.75rem;">
                                                        Preset LIZ
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-2 bg-light">
                                            <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="regTotalDisplay" value="0.00" readonly style="font-size: 0.85rem;">
                                        </td>
                                        <?php if ($canManage): ?><td class="bg-light"></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- ================= TAB 2: AUDIT TRAIL ================= -->
                    <div class="tab-pane fade" id="tab-reg-audit" role="tabpanel">
                        <div class="reg-form-card mb-0">
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

                <?php if ($canManage): ?>
                <div class="d-flex justify-content-end gap-2 mt-3 pt-2 border-top">
                    <a href="<?= e(APP_URL) ?>/pages/rental/regulations/index.php" class="btn btn-light btn-sm px-3">Kembali ke Daftar</a>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check2-circle me-1"></i>Simpan Peraturan Komisi
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Windows ERP Status Bar di bagian bawah (Persis Screenshot) -->
        <div class="reg-status-bar">
            <div class="d-flex align-items-center gap-3">
                <span><i class="bi bi-person-fill me-1"></i><?= e($user ?: 'Administrator') ?></span>
                <span><i class="bi bi-globe me-1 text-primary"></i>Url : <?= e($_SERVER['HTTP_HOST'] ?? 'vsudotrans.azurewebsites.net') ?></span>
                <span><i class="bi bi-shield-check me-1 text-success"></i>Akses Perusahaan : <?= e($reg ? ($reg['company_code'] . ' - ' . $reg['company_name']) : 'LIZ - PT LIZ WISATA ALAM INDAH') ?></span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span><i class="bi bi-calendar3 me-1 text-warning"></i>Tanggal : <?= date('d-M-Y H:i:s') ?></span>
                <span><i class="bi bi-gear-fill me-1 text-primary"></i>Versi : 1.0.1.19</span>
            </div>
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

    let activeRowIndex = 0;

    function parseRowVal(calcType, rawStr) {
        const s = String(rawStr || '').trim();
        if (!s) return 0;
        if (calcType === 'Persentase') {
            const clean = s.replace(/[^0-9.,]/g, '').replace(',', '.');
            const num = parseFloat(clean);
            return isNaN(num) ? 0 : num;
        } else {
            // Hilangkan pemisah ribuan koma/titik jika ada dua desimal di akhir
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
        if (activeRowIndex >= rows.length) activeRowIndex = Math.max(0, rows.length - 1);
        if (activeRowIndex < 0) activeRowIndex = 0;

        rows.forEach((tr, idx) => {
            const numCell = tr.querySelector('.reg-row-num');
            if (idx === activeRowIndex) {
                tr.classList.add('active-row');
                if (numCell) numCell.innerHTML = '<span class="text-primary fw-bold">▶</span>';
            } else {
                tr.classList.remove('active-row');
                if (numCell) numCell.textContent = String(idx + 1);
            }
        });

        if (recCountEl) {
            recCountEl.textContent = 'Record ' + (rows.length ? (activeRowIndex + 1) : 0) + ' of ' + rows.length;
        }
    }

    function setActiveRow(index, shouldFocus = false) {
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        if (!rows.length) return;
        activeRowIndex = Math.max(0, Math.min(rows.length - 1, index));
        updateRowIndicators();
        if (shouldFocus && rows[activeRowIndex]) {
            const inp = rows[activeRowIndex].querySelector('.reg-amount-input');
            if (inp) inp.focus();
        }
    }

    function recalcRegTotal() {
        if (!tbody) return;
        let sum = 0;
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        rows.forEach((tr) => {
            const cType = tr.querySelector('.reg-calc-type')?.value || 'Tetap';
            const inp   = tr.querySelector('.reg-amount-input');
            sum += parseRowVal(cType, inp ? inp.value : '0');
        });
        if (totalDisp) {
            totalDisp.value = sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

        tr.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            const rows = Array.from(tbody.querySelectorAll('tr.reg-detail-row'));
            const idx  = rows.indexOf(tr);
            if (idx >= 0) setActiveRow(idx);
        });

        if (cSel && inp) {
            cSel.addEventListener('change', () => {
                const v = parseRowVal(cSel.value, inp.value);
                inp.value = formatNumberDisplay(v, cSel.value);
                recalcRegTotal();
            });

            inp.addEventListener('focus', () => {
                const rows = Array.from(tbody.querySelectorAll('tr.reg-detail-row'));
                const idx  = rows.indexOf(tr);
                if (idx >= 0) setActiveRow(idx);
            });

            inp.addEventListener('blur', function () {
                const cType = cSel.value;
                const v = parseRowVal(cType, this.value);
                this.value = formatNumberDisplay(v, cType);
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
        const disp = formatNumberDisplay(amount, calcType);
        tr.innerHTML = `
            <td class="text-center text-muted reg-row-num" style="width: 32px; font-size: 0.75rem;">1</td>
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
            <td class="text-muted small fst-italic"></td>
            <td>
                <input type="text" inputmode="decimal" class="form-control form-control-sm text-end fw-semibold reg-amount-input" name="detail_amount[]" value="${disp}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-reg-row" title="Hapus baris"><i class="bi bi-x-lg"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
        bindRegRow(tr);
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        setActiveRow(rows.length - 1, true);
        recalcRegTotal();
    }

    if (tbody) {
        tbody.querySelectorAll('tr.reg-detail-row').forEach(bindRegRow);
        recalcRegTotal();
    }

    // Navigasi Toolbar Access/DevExpress
    const navFirst = document.getElementById('navFirst');
    if (navFirst) navFirst.addEventListener('click', () => setActiveRow(0, true));

    const navPrev = document.getElementById('navPrev');
    if (navPrev) navPrev.addEventListener('click', () => setActiveRow(activeRowIndex - 1, true));

    const navNext = document.getElementById('navNext');
    if (navNext) navNext.addEventListener('click', () => setActiveRow(activeRowIndex + 1, true));

    const navLast = document.getElementById('navLast');
    if (navLast) navLast.addEventListener('click', () => {
        const rows = tbody.querySelectorAll('tr.reg-detail-row');
        setActiveRow(rows.length - 1, true);
    });

    const btnEdit = document.getElementById('btnFooterEditReg');
    if (btnEdit) btnEdit.addEventListener('click', () => setActiveRow(activeRowIndex, true));

    const btnSave = document.getElementById('btnFooterSaveReg');
    if (btnSave) btnSave.addEventListener('click', () => {
        const form = document.getElementById('regulationForm');
        if (form) form.requestSubmit();
    });

    const btnCancel = document.getElementById('btnFooterCancelReg');
    if (btnCancel) btnCancel.addEventListener('click', () => {
        if (confirm('Batal perubahan formulir ini?')) {
            window.location.reload();
        }
    });

    ['btnAddRegRow', 'regQuickAddBanner', 'btnFooterAddReg'].forEach((btnId) => {
        const el = document.getElementById(btnId);
        if (el) el.addEventListener('click', () => addRegRow('Agen', 'Tetap', 0));
    });

    const btnDelCurrent = document.getElementById('btnFooterDelReg');
    if (btnDelCurrent && tbody) {
        btnDelCurrent.addEventListener('click', () => {
            const rows = tbody.querySelectorAll('tr.reg-detail-row');
            if (rows.length > 1) {
                if (rows[activeRowIndex]) {
                    rows[activeRowIndex].remove();
                } else {
                    rows[rows.length - 1].remove();
                }
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
                noteInput.value = 'Sopir 13.5%, Kernet 150k, Montir 145k, BBM 26.5%';
            }
            recalcRegTotal();
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

