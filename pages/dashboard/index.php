<?php
/**
 * Dashboard: ringkasan jumlah karyawan, cabang, perusahaan (scoped tenant).
 */
require_once __DIR__ . '/../../config/config.php';
require_login();

/** Hitung baris pada tabel company-scoped memakai company_scope(). */
function count_scoped(string $table): int
{
    [$where, $types, $params] = company_scope('company_id');
    $sql = "SELECT COUNT(*) AS n FROM `{$table}` WHERE {$where}";
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

$employees = count_scoped('employees');
$branches  = count_scoped('branches');
$companies = count(accessible_companies());

$active = 'dashboard';
$title  = 'Dashboard';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-4">Dashboard</h4>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-primary me-3"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="text-muted small">Karyawan</div>
                        <div class="fs-3 fw-bold"><?= e((string) $employees) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-success me-3"><i class="bi bi-diagram-3"></i></div>
                    <div>
                        <div class="text-muted small">Cabang</div>
                        <div class="fs-3 fw-bold"><?= e((string) $branches) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="fs-1 text-warning me-3"><i class="bi bi-building"></i></div>
                    <div>
                        <div class="text-muted small">Perusahaan</div>
                        <div class="fs-3 fw-bold"><?= e((string) $companies) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
