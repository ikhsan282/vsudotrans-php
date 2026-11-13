<?php
/**
 * Partial bersama Tab 2 ERP: Sopir/Kernet/Montir (& BBM opsional untuk Rental).
 * Styling & struktur tabel disamakan persis dengan Rental (`pages/rental/bookings/detail.php`).
 *
 * Variabel input dari pemanggil:
 * - string $formId       ID elemen <form> utama (mis. 'formTravelBooking' / 'travelDetailForm')
 * - int    $companyId    ID perusahaan pemilik booking
 * - bool   $canManage    Hak edit pengguna saat ini
 * - bool   $showBbm      (opsional, default true) Tampilkan input Bahan Bakar Minyak (BBM) — Travel pass false
 * - float  $bbm          Nominal BBM tersimpan (dipaksa 0 bila !$showBbm)
 * - array  $crew_rows    Daftar baris kru [['type'|'cost_type'=>, 'employee_id'=>, 'amount'=>], ...]
 * - array  $employees    Opsi karyawan aktif ter-scope perusahaan (via opts_employees)
 * - array  $regulations  Daftar peraturan komisi perusahaan (via regulation_list_by_company)
 * - string $activeTab    (opsional) Tab aktif saat ini ('detail'|'crew'|'payment'|'note'|'audit')
 * - array  $assignments  (opsional, mode detail) Daftar riwayat penugasan driver
 * - string $dispatchUrl  (opsional, mode detail) URL pengelola penjadwalan
 */

$formId      = (string) ($formId ?? 'formTravelBooking');
$companyId   = (int) ($companyId ?? 0);
$canManage   = !empty($canManage);
$showBbm     = !isset($showBbm) || !empty($showBbm);
$bbmVal      = $showBbm ? max(0.0, (float) ($bbm ?? 0)) : 0.0;
$crewRowsIn  = is_array($crew_rows ?? null) ? array_values($crew_rows) : [];
$employees   = is_array($employees ?? null) ? $employees : [];
$activeTab   = (string) ($activeTab ?? 'detail');
$assignments = is_array($assignments ?? null) ? $assignments : [];
$dispatchUrl = (string) ($dispatchUrl ?? '');

$crewRolesList = ['Sopir', 'Sopir 1', 'Sopir 2', 'Sopir Cadangan', 'Kernet', 'Montir', 'Agen'];

$empMetaById = [];
foreach ($employees as $em) {
    $empMetaById[(int) ($em['id'] ?? 0)] = $em;
}

$crewSubtotal = 0.0;
foreach ($crewRowsIn as $cr) {
    $crewSubtotal += max(0.0, (float) ($cr['amount'] ?? 0));
}
$totalOpsPreview = ($showBbm ? $bbmVal : 0.0) + $crewSubtotal;
$isCrewActive    = ($activeTab === 'crew');
?>
<div class="tab-pane fade<?= $isCrewActive ? ' show active' : '' ?>" id="tab-crew" role="tabpanel" aria-labelledby="tab-crew-btn" data-show-bbm="<?= $showBbm ? '1' : '0' ?>">
    <?php if ($canManage): ?>
        <input type="hidden" name="crew_sync_present" value="1" form="<?= e($formId) ?>">
        <?php if (!$showBbm): ?>
            <input type="hidden" name="bbm" id="bbmValue" form="<?= e($formId) ?>" value="0">
        <?php endif; ?>
    <?php endif; ?>

    <!-- Ringkasan Biaya Operasional -->
    <div class="rnt-panel">
        <div class="rnt-panel-body">
            <div class="rnt-row">
                <div class="rnt-label">Total Biaya Operasional</div>
                <div class="rnt-field">
                    <input type="text" class="form-control form-control-sm fw-bold bg-light" id="crewTotalOpsDisplay" value="<?= e(format_nominal($totalOpsPreview)) ?>" readonly>
                </div>
            </div>
            <?php if ($showBbm): ?>
                <div class="rnt-row">
                    <div class="rnt-label">Bahan Bakar Minyak (BBM)</div>
                    <div class="rnt-field">
                        <input type="text" inputmode="numeric" class="form-control form-control-sm fw-semibold" id="bbmDisplay" value="<?= e(format_nominal($bbmVal)) ?>" placeholder="0" <?= $canManage ? '' : 'readonly' ?>>
                        <input type="hidden" name="bbm" id="bbmValue" form="<?= e($formId) ?>" value="<?= (int) round($bbmVal) ?>">
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sub-panel Karyawan (Grid Premi / Biaya Kru) -->
    <div class="rnt-panel<?= empty($assignments) && $dispatchUrl === '' ? ' mb-0' : '' ?>">
        <div class="rnt-panel-header">
            <div class="d-flex align-items-center gap-2">
                <span>Karyawan</span>
                <span id="crewRegStatusMsg" class="small text-muted fw-normal"></span>
            </div>
            <?php if ($canManage): ?>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" id="btnApplyRegulation"
                        title="<?= $showBbm ? 'Hitung otomatis premi dan BBM sesuai peraturan komisi kategori armada' : 'Hitung otomatis premi sesuai peraturan komisi kategori armada' ?>">
                        <i class="bi bi-calculator me-1"></i>Auto Hitung Komisi
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddCrewRow">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle" id="crewTable" style="font-size:.84rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px;" class="text-center">#</th>
                        <th style="width:160px;">Tipe</th>
                        <th style="min-width:240px;">Karyawan</th>
                        <th style="width:190px;">Organisasi</th>
                        <th style="width:190px;">Posisi</th>
                        <th style="width:170px;" class="text-end">Nominal</th>
                        <?php if ($canManage): ?><th style="width:42px;" class="text-center">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="crewTableBody">
                    <?php foreach ($crewRowsIn as $idx => $cr):
                        $crType  = (string) ($cr['cost_type'] ?? $cr['type'] ?? 'Sopir');
                        $crEmpId = (int) ($cr['employee_id'] ?? 0);
                        $crAmt   = max(0.0, (float) ($cr['amount'] ?? 0));
                        $empMeta = $crEmpId > 0 ? ($empMetaById[$crEmpId] ?? null) : null;
                        $crOrg   = (string) ($cr['organization'] ?? $empMeta['job_title'] ?? '');
                        $crPos   = (string) ($cr['position'] ?? $empMeta['job_position'] ?? '');
                        $rowRoles = in_array($crType, $crewRolesList, true) ? $crewRolesList : array_merge($crewRolesList, [$crType]);
                    ?>
                        <tr class="crew-row">
                            <td class="text-center text-muted crew-row-num"><?= $idx + 1 ?></td>
                            <td>
                                <select class="form-select form-select-sm crew-type-select" name="crew_type[]" form="<?= e($formId) ?>" <?= $canManage ? '' : 'disabled' ?>>
                                    <?php foreach ($rowRoles as $tp): ?>
                                        <option value="<?= e($tp) ?>"<?= $tp === $crType ? ' selected' : '' ?>><?= e($tp) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select class="form-select form-select-sm ts-select crew-emp-select" name="crew_employee_id[]" form="<?= e($formId) ?>"
                                    data-ts-url="<?= e(APP_URL) ?>/pages/employees/options.php"
                                    data-ts-company="<?= $companyId ?>"
                                    data-ts-limit="30"
                                    data-ts-dropdown-parent="body"
                                    <?= $canManage ? '' : 'disabled' ?>>
                                    <option value="">-- Pilih Karyawan --</option>
                                    <?php foreach ($employees as $emp):
                                        $eid = (int) ($emp['id'] ?? 0);
                                        $org = (string) ($emp['job_title'] ?? '');
                                        $pos = (string) ($emp['job_position'] ?? '');
                                    ?>
                                        <option value="<?= $eid ?>" data-org="<?= e($org) ?>" data-pos="<?= e($pos) ?>"<?= $eid === $crEmpId ? ' selected' : '' ?>>
                                            <?= e($emp['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm bg-light crew-org-input" value="<?= e($crOrg) ?>" readonly>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm bg-light crew-pos-input" value="<?= e($crPos) ?>" readonly>
                            </td>
                            <td>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm text-end fw-semibold crew-amt-display" value="<?= e(format_nominal($crAmt)) ?>" placeholder="0" <?= $canManage ? '' : 'readonly' ?>>
                                <input type="hidden" name="crew_amount[]" class="crew-amt-value" form="<?= e($formId) ?>" value="<?= (int) round($crAmt) ?>">
                            </td>
                            <?php if ($canManage): ?>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-crew" title="Hapus baris">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="5" class="py-2">
                            <div class="d-flex align-items-center gap-2 small text-muted">
                                <span id="crewRecordCount">Record <?= count($crewRowsIn) ? 1 : 0 ?> of <?= count($crewRowsIn) ?></span>
                                <?php if ($canManage): ?>
                                    <div class="btn-group btn-group-sm ms-2">
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnCrewFooterAdd" title="Tambah Baris">+</button>
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnCrewFooterRemove" title="Hapus Baris Terakhir">&minus;</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-2">
                            <input type="text" class="form-control form-control-sm text-end fw-bold bg-white" id="crewSubtotalDisplay" value="<?= e(format_nominal($crewSubtotal)) ?>" readonly>
                        </td>
                        <?php if ($canManage): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <template id="crewEmployeeOptionsTpl">
        <option value="">-- Pilih Karyawan --</option>
        <?php foreach ($employees as $emp): ?>
            <option value="<?= (int) ($emp['id'] ?? 0) ?>" data-org="<?= e($emp['job_title'] ?? '') ?>" data-pos="<?= e($emp['job_position'] ?? '') ?>">
                <?= e($emp['name'] ?? '') ?>
            </option>
        <?php endforeach; ?>
    </template>

    <?php if (!empty($assignments) || $dispatchUrl !== ''): ?>
        <div class="rnt-panel mb-0 mt-3">
            <div class="rnt-panel-header">
                <span>Riwayat Penugasan Driver</span>
                <?php if ($dispatchUrl !== ''): ?>
                    <a href="<?= e($dispatchUrl) ?>" class="text-primary text-decoration-none fw-normal small">
                        <i class="bi bi-calendar2-check me-1"></i>Kelola di Penjadwalan
                    </a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.84rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:150px;">Tanggal &amp; Jam</th>
                            <th>Driver</th>
                            <th>Armada</th>
                            <th style="width:110px;">Status</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($assignments): ?>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold"><?= !empty($a['trip_date']) ? e(date('d-M-Y', strtotime((string) $a['trip_date']))) : '-' ?></span>
                                        <span class="text-muted small ms-1"><?= e(substr((string) ($a['trip_time'] ?? ''), 0, 5)) ?></span>
                                    </td>
                                    <td class="fw-semibold"><?= e($a['driver_name'] ?? '-') ?></td>
                                    <td><?= e($a['vehicle_number'] ?? '-') ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($a['status'] ?? 'assigned') ?></span></td>
                                    <td class="small text-muted"><?= e($a['notes'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Belum ada penugasan driver tercatat pada papan penjadwalan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
