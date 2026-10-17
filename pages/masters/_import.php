<?php
/**
 * Import CSV generik untuk SEMUA tabel master (Referensi Umum & Master HR).
 * Dipanggil halaman tipis pages/<modul>/<tabel>/import.php dengan key tabel.
 *
 * Kontrak keamanan (samakan dengan master_form/master_delete):
 *  - require_permission($spec['perm_manage']) — hanya yang berhak mengelola;
 *  - POST + verify_csrf();
 *  - company_id SELALU dari active_company_id() (tidak pernah dari CSV/POST);
 *  - tiap baris divalidasi lewat master_validate() + master_save() → sama persis
 *    dengan aturan form manual (kode, relasi, siklus, keunikan).
 *
 * Perilaku:
 *  - Kolom CSV = header baris pertama; nama kolom = 'col' spesifikasi (mis. code,name,note).
 *  - Mode "upsert" (default): baris dengan code yang sudah ada akan DIPERBARUI;
 *    mode "insert": kode duplikat dilewati sebagai error.
 *  - Semua baris dalam SATU transaksi: ada 1 error -> tidak ada yang tersimpan (all-or-nothing),
 *    sehingga impor selalu konsisten.
 *  - Selalu menyediakan unduh template CSV (header + 1 baris contoh) agar format pasti cocok.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_engine.php';

$key  = $MASTER_KEY ?? null;
$spec = master_spec((string) $key);
require_permission($spec['perm_manage']);
$cid = active_company_id();
$base = master_base_url($spec) . '/index.php';

/** Kolom yang boleh diisi dari CSV (lewati biner/hidden/sistem). */
function master_import_cols(array $spec): array
{
    $out = [];
    foreach ($spec['fields'] as $f) {
        if (in_array($f['type'] ?? 'text', ['bytes', 'file', 'hidden'], true)) {
            continue;
        }
        $out[$f['col']] = $f;
    }
    return $out;
}

/** Baris contoh untuk template (nilai wajar sesuai tipe kolom). */
function master_import_sample(array $spec, array $cols): array
{
    $row = [];
    foreach ($cols as $col => $f) {
        $t = $f['type'] ?? 'text';
        if ($t === 'checkbox') {
            $row[] = '1';
        } elseif ($t === 'select') {
            $row[] = '1';
        } elseif ($t === 'money' || $t === 'number') {
            $row[] = '0';
        } elseif ($col === 'code') {
            $row[] = 'CONTOH1';
        } else {
            $row[] = 'Contoh ' . $f['label'];
        }
    }
    return $row;
}

// ---- Unduh template CSV (?template=1) ----
if (isset($_GET['template'])) {
    $cols = master_import_cols($spec);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template-' . $spec['table'] . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // BOM agar Excel membaca aksen dengan benar
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map(fn ($f) => $f['label'], $cols), ',', '"', '');
    fputcsv($out, master_import_sample($spec, $cols), ',', '"', '');
    fclose($out);
    exit;
}

$report = null; // ['ok'=>int,'upd'=>int,'err'=>[[line,msg],...]]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mode = ($_POST['mode'] ?? 'upsert') === 'insert' ? 'insert' : 'upsert';
    $cols = master_import_cols($spec);

    if (empty($_FILES['file']['tmp_name']) || (int) ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $report = ['fatal' => 'Berkas CSV belum dipilih atau gagal diunggah.'];
    } elseif ((int) $_FILES['file']['size'] > 5 * 1024 * 1024) {
        $report = ['fatal' => 'Ukuran berkas maksimal 5 MB.'];
    } else {
        $fh = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$fh) {
            $report = ['fatal' => 'Berkas tidak dapat dibaca.'];
        } else {
            // Header -> indeks kolom yang dikenal.
            $header = fgetcsv($fh, 0, ',', '"', '');
            if ($header === false || $header === null) {
                $report = ['fatal' => 'CSV kosong.'];
            } else {
                if (isset($header[0])) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // buang BOM
                }
                $label_to_col = [];
                foreach ($cols as $col => $f) {
                    $label_to_col[mb_strtolower(trim($f['label']))] = $col;
                }
                $idx = [];
                foreach ($header as $i => $h) {
                    $h = mb_strtolower(trim((string) $h));
                    if (isset($cols[$h])) {
                        $idx[$i] = $h;             // header = nama kolom (dari template)
                    } elseif (isset($label_to_col[$h])) {
                        $idx[$i] = $label_to_col[$h]; // header = label kolom
                    }
                }
                if (!$idx) {
                    $report = ['fatal' => 'Header CSV tidak dikenali. Unduh template agar format sesuai.'];
                }
            }
        }
    }

    if ($report === null) {
        $ok = 0;
        $upd = 0;
        $errs = [];
        $line_no = 1; // header = baris 1
        $db = db();
        $db->begin_transaction();
        try {
            while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                $line_no++;
                if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                    continue; // baris kosong
                }
                $raw = [];
                foreach ($idx as $i => $col) {
                    $raw[$col] = (string) ($cells[$i] ?? '');
                }
                $in = master_map_input($spec, $raw);

                // Cari baris existing LEBIH DULU: validasi keunikan kode harus tahu
                // baris ini sendiri (id) agar kode yang sama tidak dianggap duplikat.
                $existing = !empty($in['code']) ? master_find_code($spec, (string) $in['code'], $cid) : null;
                $row_id = $existing ? (int) $existing['id'] : 0;

                $err = master_validate($spec, $in, $row_id, $cid);
                if ($err) {
                    $errs[] = [$line_no, implode(' ', $err)];
                    continue;
                }

                if ($existing) {
                    if ($mode === 'insert') {
                        $errs[] = [$line_no, 'Kode ' . $in['code'] . ' sudah ada (mode hanya-tambah).'];
                        continue;
                    }
                    if (master_import_only_changed($spec, $existing, $in)) {
                        master_save($spec, $row_id, $in, $cid);
                        $upd++;
                    } else {
                        $ok++; // tak ada perubahan = tetap dianggap sukses
                    }
                } else {
                    master_save($spec, 0, $in, $cid);
                    $ok++;
                }
            }
            if ($errs) {
                $db->rollback();
                $report = ['ok' => 0, 'upd' => 0, 'err' => $errs, 'rolled' => true];
            } else {
                $db->commit();
                audit($spec['module'] . '.import', $spec['title'] . ": import CSV ({$ok} baru, {$upd} diperbarui)");
                $report = ['ok' => $ok, 'upd' => $upd, 'err' => []];
            }
        } catch (mysqli_sql_exception $ex) {
            $db->rollback();
            $report = ['fatal' => 'Gagal impor: ' . $ex->getMessage()];
        }
        if (is_resource($fh)) {
            fclose($fh);
        }
    }
}

$active = $spec['module'];
$cols = master_import_cols($spec);
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-4">
    <h4 class="fw-bold mb-1">Import CSV — <?= e($spec['title']) ?></h4>
    <p class="text-muted small">Unggah berkas CSV untuk menambah banyak data sekaligus. Semua baris diproses dalam satu transaksi: bila ada satu baris bermasalah, tidak ada yang tersimpan.</p>

    <?php if ($report !== null): ?>
        <?php if (!empty($report['fatal'])): ?>
            <div class="alert alert-danger py-2"><?= e($report['fatal']) ?></div>
        <?php elseif (!empty($report['err'])): ?>
            <div class="alert alert-danger py-2">
                <div class="fw-semibold mb-1">Impor dibatalkan — <?= count($report['err']) ?> baris bermasalah. Tidak ada data yang tersimpan.</div>
                <ul class="mb-0 small">
                    <?php foreach (array_slice($report['err'], 0, 20) as [$ln, $msg]): ?>
                        <li>Baris <?= (int) $ln ?>: <?= e($msg) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($report['err']) > 20): ?><li>… dan <?= count($report['err']) - 20 ?> lainnya.</li><?php endif; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="alert alert-success py-2">
                Impor selesai: <strong><?= (int) $report['ok'] ?></strong> baris baru<?= $report['upd'] ? ', <strong>' . (int) $report['upd'] . '</strong> diperbarui' : '' ?>.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" action="<?= e(master_base_url($spec)) ?>/import.php">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="file">Berkas CSV</label>
                            <input class="form-control" type="file" id="file" name="file" accept=".csv,text/csv" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="mode">Bila kode sudah ada</label>
                            <select class="form-select" id="mode" name="mode">
                                <option value="upsert">Perbarui baris yang ada (upsert)</option>
                                <option value="insert">Lewati / jadikan error (hanya tambah)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Impor</button>
                        <a class="btn btn-outline-secondary" href="<?= e($base) ?>">Kembali</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h6 class="fw-bold mb-2">Format kolom</h6>
                    <p class="small text-muted">Baris pertama = header. Nama kolom yang dikenali (boleh juga memakai label):</p>
                    <div class="table-responsive"><table class="table table-sm mb-2">
                        <tbody>
                        <?php foreach ($cols as $col => $f): ?>
                            <tr>
                                <td><code><?= e($col) ?></code></td>
                                <td class="small text-muted"><?= e($f['label']) ?></td>
                                <td class="small"><?= e($f['type'] ?? 'text') ?></td>
                                <td class="small"><?= !empty($f['required']) ? '<span class="text-danger">wajib</span>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php if (!empty($spec['company'])): ?>
                        <p class="small text-muted mb-2">Kolom <code>company_id</code> tidak perlu diisi — otomatis memakai perusahaan aktif.</p>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(master_base_url($spec)) ?>/import.php?template=1"><i class="bi bi-download me-1"></i>Unduh template CSV</a>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../../includes/footer.php';
