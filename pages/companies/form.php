<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('companies.manage');
require_once __DIR__ . '/../../includes/payment_gateway.php';
require_once __DIR__ . '/../../includes/payment_gateway_direct.php';
require_once __DIR__ . '/../../includes/gopay_auth.php';
require_once __DIR__ . '/../../includes/shopeepay_auth.php';

$db = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Membuat perusahaan baru: hanya Super Admin.
if ($id === 0 && !is_super_admin()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'profile');
if (!in_array($tab, ['profile', 'payment'], true)) {
    $tab = 'profile';
}

$row = [
    'group_id'     => '',
    'code'         => '',
    'name'         => '',
    'note'         => '',
    'country_code' => '+62',
    'phone_number' => '',
    'website'      => '',
    'email'        => '',
    'address'      => '',
    'city'         => '',
    'logo'         => '',
    'stamp'        => '',
    'is_active'    => 1,
];

if ($id > 0) {
    $st = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $found = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$found) {
        flash('error', 'Perusahaan tidak ditemukan.');
        redirect('pages/companies/index.php');
    }
    assert_company_access((int) $found['id']);
    $row = $found;
    gateway_ensure_company_channels($id);
}

$groups = $db->query('SELECT id, name FROM groups WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$errors = [];
$user = $_SESSION['username'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = (string) ($_POST['_section'] ?? 'profile');
    $in = fn (string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

    // ==========================================
    // SECTION 1: PROFIL & IDENTITAS PERUSAHAAN
    // ==========================================
    if ($section === 'profile') {
        $row = [
            'group_id'     => (string) (int) ($_POST['group_id'] ?? 0),
            'code'         => strtoupper($in('code', 15)),
            'name'         => $in('name', 255),
            'note'         => $in('note', 2000),
            'country_code' => $in('country_code', 10) ?: '+62',
            'phone_number' => $in('phone_number', 20),
            'website'      => $in('website', 300),
            'email'        => $in('email', 200),
            'address'      => $in('address', 1000),
            'city'         => $in('city', 100),
            'logo'         => $in('logo', 255),
            'stamp'        => $in('stamp', 255),
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
        ];
        $group_id = (int) $row['group_id'];

        if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
            $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
        }
        if ($row['name'] === '') {
            $errors[] = 'Nama wajib diisi.';
        }
        if ($group_id === 0 || !in_array($group_id, array_column($groups, 'id'), false)) {
            $errors[] = 'Grup tidak valid.';
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }
        if ($row['website'] !== '' && !filter_var($row['website'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Format website tidak valid.';
        }

        if (!$errors) {
            $st = $db->prepare('SELECT id FROM companies WHERE code = ? AND id <> ?');
            $st->bind_param('si', $row['code'], $id);
            $st->execute();
            $dup = $st->get_result()->num_rows > 0;
            $st->close();
            if ($dup) {
                $errors[] = 'Kode sudah dipakai perusahaan lain.';
            }
        }

        if (!$errors) {
            $nul = fn (string $v): ?string => $v === '' ? null : $v;
            $vals = [$group_id, $row['code'], $row['name'], $nul($row['note']), $row['country_code'],
                     $nul($row['phone_number']), $nul($row['website']), $nul($row['email']),
                     $nul($row['address']), $nul($row['city']), $nul($row['logo']), $nul($row['stamp']), $row['is_active']];
            try {
                if ($id > 0) {
                    $st = $db->prepare('UPDATE companies SET group_id = ?, code = ?, name = ?, note = ?, country_code = ?,
                        phone_number = ?, website = ?, email = ?, address = ?, city = ?, logo = ?, stamp = ?, is_active = ?,
                        modified_user = ?, modified_date = NOW() WHERE id = ?');
                    $st->bind_param('isssssssssssisi', ...[...$vals, $user, $id]);
                    $st->execute();
                    $st->close();
                    audit('company.update', "Perusahaan #{$id} {$row['code']}");
                    flash('success', 'Profil perusahaan berhasil diperbarui.');
                    redirect("pages/companies/form.php?id={$id}&tab=profile");
                } else {
                    $st = $db->prepare('INSERT INTO companies (group_id, code, name, note, country_code, phone_number,
                        website, email, address, city, logo, stamp, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $st->bind_param('isssssssssssis', ...[...$vals, $user]);
                    $st->execute();
                    $new_id = (int) $db->insert_id;
                    $st->close();
                    gateway_ensure_company_channels($new_id);
                    audit('company.create', "Perusahaan #{$new_id} {$row['code']}");
                    flash('success', 'Perusahaan berhasil ditambahkan. Silakan atur channel QRIS Dinamis.');
                    redirect("pages/companies/form.php?id={$new_id}&tab=payment");
                }
            } catch (mysqli_sql_exception $ex) {
                if ((int) $ex->getCode() === 1062) {
                    $errors[] = 'Kode sudah dipakai perusahaan lain.';
                } else {
                    throw $ex;
                }
            }
        }
    }

    // ==========================================
    // SECTION 2: CHANNEL QRIS OTOMATIS
    // ==========================================
    elseif ($section === 'payment' && $id > 0) {
        $act = (string) ($_POST['_action'] ?? 'save');
        $prov = (string) ($_POST['provider'] ?? 'gopay');

        $st = $db->prepare('SELECT * FROM payment_channels WHERE company_id = ? AND provider = ? LIMIT 1');
        $st->bind_param('is', $id, $prov);
        $st->execute();
        $channel = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$channel) {
            flash('error', 'Channel pembayaran tidak ditemukan.');
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider={$prov}");
        }

        if ($act === 'renew_webhook') {
            $token = bin2hex(random_bytes(24));
            $st = $db->prepare('UPDATE payment_channels SET webhook_token = ?, modified_date = NOW() WHERE id = ?');
            $st->bind_param('si', $token, $channel['id']);
            $st->execute();
            $st->close();
            audit('payment_channel.webhook_renew', "Company #{$id} {$prov}");
            flash('success', 'Token webhook berhasil dibuat ulang.');
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider={$prov}");
        } elseif ($act === 'otp_request' && $prov === 'gopay') {
            $r = gopay_request_otp((string) ($_POST['otp_phone'] ?? ''));
            if ($r['ok']) {
                flash('success', 'Kode OTP dikirim ke ' . $r['phone'] . '. Masukkan kode OTP di bawah.');
            } else {
                flash('error', $r['error']);
            }
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider=gopay");
        } elseif ($act === 'otp_cancel' && $prov === 'gopay') {
            unset($_SESSION['gopay_otp']);
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider=gopay");
        } elseif ($act === 'otp_verify' && $prov === 'gopay') {
            $r = gopay_verify_otp((string) ($_POST['otp_code'] ?? ''), $id);
            if ($r['ok']) {
                flash('success', 'Login GoPay Merchant berhasil (' . $r['merchant'] . '). Token tersimpan.');
            } else {
                flash('error', $r['error']);
            }
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider=gopay");
        } elseif ($act === 'gopay_refresh' && $prov === 'gopay') {
            $r = gopay_refresh_channel((int) $channel['id']);
            if ($r['ok']) {
                flash('success', 'Token GoPay Merchant berhasil diperbarui.');
            } else {
                flash('error', $r['error']);
            }
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider=gopay");
        } elseif ($act === 'spay_check' && $prov === 'shopeepay') {
            $r = shopeepay_verify_token((string) ($channel['api_key'] ?? ''));
            if ($r['ok']) {
                flash('success', 'Token ShopeePay Partner valid dan terhubung.');
            } else {
                flash('error', 'Cek token gagal: ' . $r['error']);
            }
            redirect("pages/companies/form.php?id={$id}&tab=payment&provider=shopeepay");
        } else {
            $chName = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
            $chMode = in_array($_POST['mode'] ?? '', ['sandbox', 'production'], true) ? $_POST['mode'] : 'production';
            $chMerchant = trim((string) ($_POST['merchant_id'] ?? ''));
            $chKey = trim((string) ($_POST['api_key'] ?? ''));
            if ($prov === 'shopeepay' && $chKey !== '') {
                $chKey = shopeepay_clean_token($chKey);
            }
            $instructions = mb_substr(trim((string) ($_POST['instructions'] ?? '')), 0, 2000);
            $sort = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            $cfg = json_decode((string) ($channel['config'] ?? ''), true);
            $cfg = is_array($cfg) ? $cfg : [];
            $feeType = in_array($_POST['fee_type'] ?? '', ['none', 'flat', 'percent'], true) ? $_POST['fee_type'] : 'none';
            $feeValue = max(0.0, (float) ($_POST['fee_value'] ?? 0));
            $cfg['fee_type'] = $feeType;
            $cfg['fee_value'] = $feeValue;
            $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $effectiveKey = $chKey !== '' ? $chKey : (string) ($channel['api_key'] ?? '');

            if ($chName === '') {
                $errors[] = 'Nama channel wajib diisi.';
            }
            if (in_array($prov, ['gopay', 'shopeepay'], true) && $chMerchant !== '') {
                if (qris_make_dynamic($chMerchant, 10000) === null) {
                    $errors[] = 'String QRIS Statis tidak valid (bukan format EMVCo/QRIS standar).';
                }
            }
            if ($isActive && ($effectiveKey === '' || $chMerchant === '')) {
                $errors[] = 'Token/Sesi dan String QRIS Statis wajib terisi sebelum channel diaktifkan.';
            }

            if (!$errors) {
                $chId = (int) $channel['id'];
                $st = $db->prepare(
                    "UPDATE payment_channels
                        SET name = ?, mode = ?, merchant_id = ?, config = ?, instructions = ?,
                            sort_order = ?, is_active = ?, api_key = ?, modified_user = ?, modified_date = NOW()
                      WHERE id = ? AND company_id = ?"
                );
                $st->bind_param('sssssiissii', $chName, $chMode, $chMerchant, $cfgJson, $instructions,
                    $sort, $isActive, $effectiveKey, $user, $chId, $id);
                $st->execute();
                $st->close();
                audit('payment_channel.update', "Channel {$prov} company #{$id}");
                flash('success', "Konfigurasi channel {$chName} berhasil disimpan.");
                redirect("pages/companies/form.php?id={$id}&tab=payment&provider={$prov}");
            }
        }
    }
}

// Data Pendukung Tab Payment
$activeProv = (string) ($_GET['provider'] ?? 'gopay');
if (!in_array($activeProv, ['gopay', 'shopeepay', 'klikqris'], true)) {
    $activeProv = 'gopay';
}
$payTabs = [];
$curChannel = null;
if ($id > 0) {
    $stP = $db->prepare('SELECT * FROM payment_channels WHERE company_id = ? ORDER BY sort_order, id');
    $stP->bind_param('i', $id);
    $stP->execute();
    $payTabs = $stP->get_result()->fetch_all(MYSQLI_ASSOC);
    $stP->close();
    foreach ($payTabs as $pt) {
        if ($pt['provider'] === $activeProv) {
            $curChannel = $pt;
            break;
        }
    }
    if (!$curChannel && $payTabs) {
        $curChannel = $payTabs[0];
        $activeProv = $curChannel['provider'];
    }
}

$active = 'companies';
$title  = $id > 0 ? ('Pengaturan ' . $row['name']) : 'Tambah Perusahaan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0"><?= e($title) ?></h4>
            <?php if ($id > 0): ?>
                <small class="text-muted">Kode Perusahaan: <strong><?= e($row['code']) ?></strong> · ID #<?= $id ?></small>
            <?php endif; ?>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL) ?>/pages/companies/index.php">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Perusahaan
        </a>
    </div>

    <?= flash_html() ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($id > 0): ?>
    <ul class="nav nav-tabs mb-4" id="companyTabs">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'profile' ? 'active fw-bold' : '' ?>" href="?id=<?= $id ?>&tab=profile">
                <i class="bi bi-building me-1"></i> Profil &amp; Legalitas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'payment' ? 'active fw-bold' : '' ?>" href="?id=<?= $id ?>&tab=payment&provider=<?= e($activeProv) ?>">
                <i class="bi bi-qr-code-scan me-1"></i> Channel QRIS Otomatis
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- TAB 1: PROFIL & IDENTITAS PERUSAHAAN                           -->
    <!-- ============================================================== -->
    <?php if ($tab === 'profile' || $id === 0): ?>
    <div class="card shadow-sm border-0" style="max-width: 880px;">
        <div class="card-header bg-white fw-semibold">Informasi Profil &amp; Legalitas Perusahaan</div>
        <div class="card-body">
            <form method="post" action="?id=<?= $id ?>&tab=profile">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="_section" value="profile">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="code">Kode Perusahaan <span class="text-danger">*</span></label>
                        <input class="form-control text-uppercase" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>" placeholder="mis. MTM, GTM">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="name">Nama Resmi Perusahaan <span class="text-danger">*</span></label>
                        <input class="form-control" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>" placeholder="mis. Maura Trans Mandiri">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="group_id">Grup Holding <span class="text-danger">*</span></label>
                        <select class="form-select" id="group_id" name="group_id" required>
                            <option value="">— pilih grup —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= (int) $g['id'] ?>" <?= (string) $g['id'] === (string) $row['group_id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="city">Kota Operasional</label>
                        <input class="form-control" id="city" name="city" maxlength="100" value="<?= e($row['city']) ?>" placeholder="mis. Tangerang">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="address">Alamat Lengkap</label>
                        <textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e($row['address']) ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="country_code">Kode Negara</label>
                        <input class="form-control" id="country_code" name="country_code" maxlength="10" value="<?= e($row['country_code']) ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="phone_number">Telepon Kantor</label>
                        <input class="form-control" id="phone_number" name="phone_number" maxlength="20" value="<?= e($row['phone_number']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" type="email" id="email" name="email" maxlength="200" value="<?= e($row['email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="website">Website Landing Page WordPress</label>
                        <input class="form-control" type="url" id="website" name="website" maxlength="300" placeholder="https://mauratransmandiri.com" value="<?= e($row['website']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="logo">Path Logo E-Tiket</label>
                        <input class="form-control" id="logo" name="logo" maxlength="255" placeholder="mis. assets/brand/logo-transmandiri.png" value="<?= e((string) ($row['logo'] ?? '')) ?>">
                        <small class="text-muted">Logo resmi perusahaan di header tiket.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="stamp">Path Stempel E-Tiket</label>
                        <input class="form-control" id="stamp" name="stamp" maxlength="255" placeholder="mis. assets/brand/stamp-mtm.png" value="<?= e((string) ($row['stamp'] ?? '')) ?>">
                        <small class="text-muted">Cap basah resmi perusahaan di pojok tanda tangan tiket.</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="note">Catatan Internal</label>
                        <textarea class="form-control" id="note" name="note" rows="2" maxlength="2000"><?= e($row['note']) ?></textarea>
                    </div>
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $row['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Perusahaan Aktif</label>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Simpan Profil</button>
                    <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/pages/companies/index.php">Batal</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- TAB 2: CHANNEL QRIS OTOMATIS (PAYMENT CHANNELS)                -->
    <!-- ============================================================== -->
    <?php if ($tab === 'payment' && $id > 0 && $curChannel): ?>
    <?php
    $chCfg = json_decode((string) ($curChannel['config'] ?? ''), true);
    $chCfg = is_array($chCfg) ? $chCfg : [];
    $feeType = (string) ($chCfg['fee_type'] ?? 'none');
    $feeVal  = (float) ($chCfg['fee_value'] ?? 0);
    $chMasked = empty($curChannel['api_key'])
        ? 'Belum diatur'
        : ('Tersimpan (' . strlen((string) $curChannel['api_key']) . ' karakter, akhiran …' . substr((string) $curChannel['api_key'], -6) . ')');
    ?>
    <div class="card shadow-sm border-0" style="max-width: 880px;">
        <div class="card-header bg-white pb-0 border-bottom-0">
            <ul class="nav nav-pills card-header-pills">
                <?php foreach ($payTabs as $pt): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $pt['provider'] === $activeProv ? 'active' : '' ?>"
                           href="?id=<?= $id ?>&tab=payment&provider=<?= e($pt['provider']) ?>">
                            <?= e($pt['name']) ?>
                            <?php if ($pt['is_active']): ?><span class="badge bg-success ms-1">Aktif</span><?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card-body">
            <form method="post" action="?id=<?= $id ?>&tab=payment&provider=<?= e($activeProv) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="_section" value="payment">
                <input type="hidden" name="_action" value="save">
                <input type="hidden" name="provider" value="<?= e($activeProv) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nama Channel Tampilan</label>
                        <input class="form-control" name="name" maxlength="100" required value="<?= e((string) $curChannel['name']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mode</label>
                        <select class="form-select" name="mode">
                            <option value="production" <?= $curChannel['mode'] === 'production' ? 'selected' : '' ?>>Production</option>
                            <option value="sandbox" <?= $curChannel['mode'] === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Urutan Tampil</label>
                        <input class="form-control" type="number" name="sort_order" value="<?= (int) $curChannel['sort_order'] ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">
                            <?= $activeProv === 'klikqris' ? 'Merchant ID KlikQRIS' : 'String QRIS Statis Merchant (EMVCo Tag 000201...)' ?>
                        </label>
                        <textarea class="form-control font-monospace small" name="merchant_id" rows="3"
                                  placeholder="<?= $activeProv === 'klikqris' ? 'ID Merchant KlikQRIS' : '000201010211... (Hasil scan teks QRIS cetak/statis toko milik ' . e($row['name']) . ')' ?>"><?= e((string) ($curChannel['merchant_id'] ?? '')) ?></textarea>
                        <?php if ($activeProv !== 'klikqris'): ?>
                            <small class="text-muted">Sistem akan otomatis mengubah Tag <code>01</code> menjadi <code>12</code> (Dinamis), menyisipkan nominal tagihan + kode unik 3 digit ke Tag <code>54</code>, dan menghitung ulang checksum CRC16-CCITT Tag <code>63</code>.</small>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-8">
                        <?php $apiLabel = match ($activeProv) {
                            'gopay'     => 'Header JSON / Sesi GoPay Merchant',
                            'shopeepay' => 'Token Authorization ShopeePay (diawali B:)',
                            default     => 'API Key KlikQRIS',
                        }; ?>
                        <label class="form-label"><?= e($apiLabel) ?> <small class="text-muted">(<?= e($chMasked) ?>)</small></label>
                        <input class="form-control" type="password" name="api_key" autocomplete="new-password"
                               value="" placeholder="Kosongkan jika tidak ingin mengubah token/sesi">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Biaya Layanan</label>
                        <select class="form-select" name="fee_type">
                            <option value="none" <?= $feeType === 'none' ? 'selected' : '' ?>>Tanpa Biaya</option>
                            <option value="flat" <?= $feeType === 'flat' ? 'selected' : '' ?>>Nominal (Rp)</option>
                            <option value="percent" <?= $feeType === 'percent' ? 'selected' : '' ?>>Persen (%)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Nilai Biaya</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="fee_value" value="<?= e((string) $feeVal) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Instruksi Pembayaran (Opsional)</label>
                        <textarea class="form-control" name="instructions" rows="2" maxlength="2000"><?= e((string) ($curChannel['instructions'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active_pay" <?= $curChannel['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_active_pay">Aktifkan Channel Ini untuk <?= e($row['name']) ?></label>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check-lg me-1"></i> Simpan Channel <?= e($curChannel['name']) ?></button>
            </form>

            <?php if ($activeProv === 'gopay'): ?>
                <hr class="my-4">
                <h6 class="fw-bold">Login Otomatis GoPay Merchant via OTP GoBiz</h6>
                <?php if (!empty($chCfg['refresh_token'])): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        Terhubung dengan: <strong><?= e((string) ($chCfg['merchant_name'] ?: 'GoPay Merchant')) ?></strong>
                        (<?= e((string) ($chCfg['phone'] ?? '')) ?>) · Diperbarui: <?= e((string) ($chCfg['updated_at'] ?? '—')) ?>
                    </div>
                    <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="payment">
                        <input type="hidden" name="_action" value="gopay_refresh">
                        <input type="hidden" name="provider" value="gopay">
                        <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i> Perbarui (Refresh) Token GoPay</button>
                    </form>
                <?php endif; ?>

                <?php if (empty($_SESSION['gopay_otp'])): ?>
                    <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="card card-body bg-body-tertiary border-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="payment">
                        <input type="hidden" name="_action" value="otp_request">
                        <input type="hidden" name="provider" value="gopay">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-7">
                                <label class="form-label small mb-1" for="otpPhone">Nomor HP Terdaftar di GoBiz / GoFood Merchant</label>
                                <input class="form-control" id="otpPhone" name="otp_phone" inputmode="numeric" placeholder="0812xxxxxxxx" required>
                            </div>
                            <div class="col-sm-5">
                                <button class="btn btn-success w-100" type="submit">Kirim Kode OTP SMS</button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <?php $otpPhone = (string) ($_SESSION['gopay_otp']['phone'] ?? ''); ?>
                    <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="card card-body bg-body-tertiary border-0 mb-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="payment">
                        <input type="hidden" name="_action" value="otp_verify">
                        <input type="hidden" name="provider" value="gopay">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-7">
                                <label class="form-label small mb-1" for="otpCode">Kode OTP yang dikirim ke <?= e($otpPhone) ?></label>
                                <input class="form-control text-center fw-bold" id="otpCode" name="otp_code" inputmode="numeric" maxlength="8" placeholder="••••" required autofocus>
                            </div>
                            <div class="col-sm-5">
                                <button class="btn btn-success w-100" type="submit">Verifikasi &amp; Hubungkan</button>
                            </div>
                        </div>
                    </form>
                    <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="payment">
                        <input type="hidden" name="_action" value="otp_cancel">
                        <input type="hidden" name="provider" value="gopay">
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Ganti Nomor HP</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($activeProv === 'shopeepay'): ?>
                <hr class="my-4">
                <h6 class="fw-bold">Verifikasi Token ShopeePay Partner</h6>
                <form method="post" action="?id=<?= $id ?>&tab=payment&provider=shopeepay" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="_section" value="payment">
                    <input type="hidden" name="_action" value="spay_check">
                    <input type="hidden" name="provider" value="shopeepay">
                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i> Cek Koneksi Token ShopeePay</button>
                </form>
                <small class="text-muted d-block">Ambil token dari aplikasi/web ShopeePay Merchant (header <code>Authorization: B:...</code>), simpan di kolom Token di atas, lalu klik Cek Koneksi.</small>
            <?php elseif ($activeProv === 'klikqris'): ?>
                <hr class="my-4">
                <h6 class="fw-bold">Webhook KlikQRIS</h6>
                <?php $whUrl = gateway_webhook_url($curChannel); ?>
                <div class="input-group mb-2">
                    <input class="form-control font-monospace small" id="webhookUrl" readonly value="<?= e($whUrl) ?>">
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)">Salin URL</button>
                </div>
                <form method="post" action="?id=<?= $id ?>&tab=payment&provider=klikqris">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="_section" value="payment">
                    <input type="hidden" name="_action" value="renew_webhook">
                    <input type="hidden" name="provider" value="klikqris">
                    <button class="btn btn-outline-warning btn-sm" type="submit">Buat Ulang Token Webhook</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
