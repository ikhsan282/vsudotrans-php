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
if (!in_array($tab, ['profile', 'payment', 'hermes'], true)) {
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
    'bot_secret'   => '',
    'bot_phone'    => '',
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
    // Pastikan setiap perusahaan aktif memiliki dedicated bot_secret sendiri
    if (empty($found['bot_secret'])) {
        $autoSecret = strtolower((string) $found['code']) . '_hermes_secret_key_' . bin2hex(random_bytes(12));
        $stSec = $db->prepare('UPDATE companies SET bot_secret = ? WHERE id = ?');
        $stSec->bind_param('si', $autoSecret, $id);
        $stSec->execute();
        $stSec->close();
        $found['bot_secret'] = $autoSecret;
    }
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
        $existing_code  = (string) ($row['code'] ?? '');
        $existing_logo  = (string) ($row['logo'] ?? '');
        $existing_stamp = (string) ($row['stamp'] ?? '');
        $row = [
            'group_id'     => (string) (int) ($_POST['group_id'] ?? 0),
            'code'         => $id > 0 ? $existing_code : strtoupper($in('code', 15)),
            'name'         => $in('name', 255),
            'note'         => $in('note', 2000),
            'country_code' => $in('country_code', 10) ?: '+62',
            'phone_number' => $in('phone_number', 20),
            'website'      => $in('website', 300),
            'email'        => $in('email', 200),
            'address'      => $in('address', 1000),
            'city'         => $in('city', 100),
            'logo'         => $existing_logo,  // path Azure Blob; hanya berubah via unggah berkas
            'stamp'        => $existing_stamp,
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

        $old_blobs = [];
        if (!$errors) {
            try {
                // Prefix folder Azure Blob = kode perusahaan (permanen, juga untuk perusahaan baru).
                $code = $row['code'];
                $up_logo = azure_blob_handle_upload('logo_file', $id > 0 ? $id : null, 'company', '', 'logo', false, 3, $code);
                if ($up_logo !== null) {
                    $old_blobs[] = $existing_logo;
                    $row['logo'] = $up_logo;
                }
                $up_stamp = azure_blob_handle_upload('stamp_file', $id > 0 ? $id : null, 'company', '', 'stamp', false, 3, $code);
                if ($up_stamp !== null) {
                    $old_blobs[] = $existing_stamp;
                    $row['stamp'] = $up_stamp;
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
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
                    foreach ($old_blobs as $old) {
                        if ($old !== '') {
                            azure_blob_delete($old); // berkas lama yang diganti
                        }
                    }
                    audit('company.update', "Perusahaan #{$id} {$row['code']}");
                    flash('success', 'Profil perusahaan berhasil diperbarui.');
                    redirect("pages/companies/form.php?id={$id}&tab=profile");
                } else {
                    $botSecret = strtolower($row['code']) . '_hermes_secret_key_' . bin2hex(random_bytes(12));
                    $st = $db->prepare('INSERT INTO companies (group_id, code, name, note, country_code, phone_number,
                        website, email, address, city, logo, stamp, bot_secret, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $st->bind_param('issssssssssssis', ...[...array_slice($vals, 0, 12), $botSecret, $vals[12], $user]);
                    $st->execute();
                    $new_id = (int) $db->insert_id;
                    $st->close();
                    gateway_ensure_company_channels($new_id);
                    audit('company.create', "Perusahaan #{$new_id} {$row['code']}");
                    flash('success', 'Perusahaan berhasil ditambahkan beserta Secret Key Bot Hermes khusus perusahaan ini.');
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
            $chk = gopay_session_check($channel);
            if (($chk['state'] ?? '') === 'valid') {
                $outletLbl = !empty($chk['outlet']) ? ' (' . $chk['outlet'] . ')' : '';
                flash('success', 'Sesi GoPay aktif dan token valid' . $outletLbl . '.');
            } elseif (($chk['state'] ?? '') === 'expired') {
                $r = gopay_refresh_channel((int) $channel['id']);
                if ($r['ok']) {
                    flash('success', 'Token kedaluwarsa — berhasil di-refresh otomatis.');
                } else {
                    flash('error', 'Token kedaluwarsa dan refresh gagal: ' . $r['error']);
                }
            } elseif (($chk['state'] ?? '') === 'empty') {
                flash('warning', 'Belum ada sesi GoPay. Silakan login lewat OTP di bawah.');
            } else {
                flash('error', 'Gagal cek sesi: ' . ($chk['error'] ?? 'Respons tidak dikenali.'));
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
            if ($prov === 'gopay' && isset($_POST['gopay_merchant_id'])) {
                $gmid = trim((string) $_POST['gopay_merchant_id']);
                if ($gmid === '') {
                    unset($cfg['merchant_ids']);
                } else {
                    $cfg['merchant_ids'] = $gmid;
                }
            }
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

    // ==========================================
    // SECTION 3: BOT WHATSAPP (HERMES) PER PERUSAHAAN
    // ==========================================
    elseif ($section === 'hermes' && $id > 0) {
        $act = (string) ($_POST['_action'] ?? 'save_phone');
        if ($act === 'save_phone') {
            $rawBotPhone = trim((string) ($_POST['bot_phone'] ?? ''));
            $normBotPhone = $rawBotPhone !== '' ? bot_normalize_phone($rawBotPhone) : null;
            if ($rawBotPhone !== '' && $normBotPhone === null) {
                $errors[] = 'Format Nomor WhatsApp Bot tidak valid (contoh: 0812xxxxxxxx atau 62812xxxxxxxx).';
            } else {
                $st = $db->prepare('UPDATE companies SET bot_phone = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
                $st->bind_param('ssi', $normBotPhone, $user, $id);
                $st->execute();
                $st->close();
                audit('company.bot_phone_update', "Perusahaan #{$id} {$row['code']} bot_phone: " . ($normBotPhone ?? 'NULL'));
                flash('success', "Nomor WhatsApp Bot untuk {$row['name']} berhasil disimpan.");
                redirect("pages/companies/form.php?id={$id}&tab=hermes");
            }
        } elseif ($act === 'regenerate') {
            $newSecret = strtolower((string) $row['code']) . '_hermes_secret_key_' . bin2hex(random_bytes(12));
            $st = $db->prepare('UPDATE companies SET bot_secret = ?, modified_user = ?, modified_date = NOW() WHERE id = ?');
            $st->bind_param('ssi', $newSecret, $user, $id);
            $st->execute();
            $st->close();
            audit('company.bot_secret_renew', "Perusahaan #{$id} {$row['code']}");
            flash('success', "Secret Key Bot Hermes untuk {$row['name']} ({$row['code']}) berhasil dibuat ulang.");
            redirect("pages/companies/form.php?id={$id}&tab=hermes");
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

$curGroupName = '';
if (!empty($row['group_id'])) {
    foreach ($groups as $g) {
        if ((int) $g['id'] === (int) $row['group_id']) {
            $curGroupName = $g['name'];
            break;
        }
    }
}

$active = 'companies';
$title  = $id > 0 ? ('Pengaturan ' . $row['name']) : 'Tambah Perusahaan';
require __DIR__ . '/../../includes/header.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <?= ui_page_header(
        $id > 0 ? $row['name'] : 'Tambah Perusahaan',
        ['Dashboard' => 'pages/dashboard/index.php', 'Perusahaan' => 'pages/companies/index.php', ($id > 0 ? $row['name'] : 'Tambah Baru') => null],
        $id > 0 ? [
            ['text' => $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'],
        ] : [],
        array_merge(
            $id > 0 ? [['label' => 'Detail', 'icon' => 'eye', 'href' => 'pages/companies/detail.php?id=' . $id]] : [],
            [['label' => 'Kembali ke Daftar', 'icon' => 'arrow-left', 'href' => 'pages/companies/index.php']]
        ),
        $id > 0 && !empty($curGroupName) ? 'Grup: ' . e($curGroupName) : ''
    ) ?>

    <?= flash_html() ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($id > 0): ?>
    <div class="mb-3">
        <ul class="nav nav-pills gap-2" id="companyTabs">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'profile' ? 'active shadow-sm fw-semibold' : 'text-secondary bg-white border shadow-sm' ?> rounded-pill px-3 py-2" href="?id=<?= $id ?>&tab=profile">
                    <i class="bi bi-building me-1"></i> Profil &amp; Legalitas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'payment' ? 'active shadow-sm fw-semibold' : 'text-secondary bg-white border shadow-sm' ?> rounded-pill px-3 py-2" href="?id=<?= $id ?>&tab=payment&provider=<?= e($activeProv) ?>">
                    <i class="bi bi-qr-code-scan me-1"></i> Channel QRIS Otomatis
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'hermes' ? 'active shadow-sm fw-semibold' : 'text-secondary bg-white border shadow-sm' ?> rounded-pill px-3 py-2" href="?id=<?= $id ?>&tab=hermes">
                    <i class="bi bi-robot me-1"></i> Bot WhatsApp (Hermes)
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- TAB 1: PROFIL & IDENTITAS PERUSAHAAN                           -->
    <!-- ============================================================== -->
    <?php if ($tab === 'profile' || $id === 0): ?>
    <div class="card shadow-sm border-0" style="max-width: 880px;">
        <div class="card-header bg-white fw-semibold">Informasi Profil &amp; Legalitas Perusahaan</div>
        <div class="card-body">
            <form method="post" action="?id=<?= $id ?>&tab=profile" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="_section" value="profile">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="code">
                            Kode Perusahaan <span class="text-danger">*</span>
                            <?php if ($id > 0): ?><i class="bi bi-lock-fill text-muted ms-1" title="Kode perusahaan bersifat permanen"></i><?php endif; ?>
                        </label>
                        <input class="form-control text-uppercase font-monospace <?= $id > 0 ? 'bg-light text-secondary' : '' ?>" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>" placeholder="mis. MTM, GTM" <?= $id > 0 ? 'readonly tabindex="-1"' : '' ?>>
                        <?php if ($id > 0): ?>
                            <div class="form-text small">Permanen (acuan folder Azure Blob &amp; nomor dokumen).</div>
                        <?php endif; ?>
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
                        <label class="form-label" for="logo_file">Logo Perusahaan (Azure Blob)</label>
                        <input class="form-control mb-1" type="file" id="logo_file" name="logo_file" accept="image/*">
                        <?php if (!empty($row['logo'])): ?>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <img src="<?= e(resolve_asset_url($row['logo'])) ?>" alt="Logo" style="max-height: 36px; max-width: 120px;" class="border rounded p-1 bg-light">
                                <a href="<?= e(resolve_asset_url($row['logo'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> Lihat (<code><?= e($row['logo']) ?></code>)</a>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">Unggah logo resmi perusahaan (JPG/PNG/WEBP, maks. 3 MB).</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="stamp_file">Stempel / Cap Perusahaan (Azure Blob)</label>
                        <input class="form-control mb-1" type="file" id="stamp_file" name="stamp_file" accept="image/*">
                        <?php if (!empty($row['stamp'])): ?>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <img src="<?= e(resolve_asset_url($row['stamp'])) ?>" alt="Stempel" style="max-height: 36px; max-width: 120px;" class="border rounded p-1 bg-light">
                                <a href="<?= e(resolve_asset_url($row['stamp'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> Lihat (<code><?= e($row['stamp']) ?></code>)</a>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">Unggah cap basah resmi perusahaan (JPG/PNG/WEBP, maks. 3 MB).</small>
                        <?php endif; ?>
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

                    <?php if ($activeProv === 'gopay'): ?>
                        <div class="col-12">
                            <label class="form-label">Merchant ID GoPay <small class="text-muted">(opsional, untuk query API mutasi)</small></label>
                            <input class="form-control" name="gopay_merchant_id" maxlength="60" value="<?= e((string) ($chCfg['merchant_ids'] ?? '')) ?>" placeholder="G123456789">
                            <small class="text-muted">Kosongkan bila tidak tahu — query API akan mengambil semua merchant dari sesi.</small>
                        </div>
                    <?php endif; ?>

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
                <h6 class="fw-bold">Sesi GoPay Merchant via OTP GoBiz</h6>
                <?php if (!empty($chCfg['refresh_token'])):
                    $gpMerchant = trim((string) ($chCfg['outlet'] ?? ''))
                        ?: trim((string) ($chCfg['merchant_name'] ?? ''))
                        ?: (!empty($chCfg['merchant_ids']) ? ('GoPay Merchant #' . $chCfg['merchant_ids']) : 'GoPay Merchant');
                    $gpPhone = trim((string) ($chCfg['phone'] ?? ''));
                    if ($gpPhone !== '' && str_starts_with($gpPhone, '8')) {
                        $gpPhone = '0' . $gpPhone;
                    }
                    $gpUpdatedRaw = $chCfg['updated_at'] ?? ($chCfg['login_at'] ?? null);
                    if (is_numeric($gpUpdatedRaw) && (int) $gpUpdatedRaw > 0) {
                        $gpUpdated = date('d-M-Y H:i', (int) $gpUpdatedRaw);
                    } elseif (is_string($gpUpdatedRaw) && $gpUpdatedRaw !== '') {
                        $tsUp = strtotime($gpUpdatedRaw);
                        $gpUpdated = $tsUp ? date('d-M-Y H:i', $tsUp) : $gpUpdatedRaw;
                    } else {
                        $gpUpdated = !empty($curChannel['modified_date'])
                            ? date('d-M-Y H:i', strtotime((string) $curChannel['modified_date']))
                            : '—';
                    }
                    $gpExpires = !empty($chCfg['expires_at']) ? date('d-M-Y H:i', (int) $chCfg['expires_at']) : '';
                ?>
                    <div class="alert alert-info py-2 small mb-3">
                        Terhubung dengan: <strong><?= e($gpMerchant) ?></strong>
                        <?= $gpPhone !== '' ? '(' . e($gpPhone) . ')' : '' ?>
                        · Diperbarui: <strong><?= e($gpUpdated) ?></strong>
                        <?php if ($gpExpires !== ''): ?>
                            · Berlaku sampai: <strong><?= e($gpExpires) ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="_section" value="payment">
                    <input type="hidden" name="_action" value="gopay_refresh">
                    <input type="hidden" name="provider" value="gopay">
                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i> Cek / Refresh Sesi GoPay</button>
                </form>

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

    <!-- ============================================================== -->
    <!-- TAB 3: BOT WHATSAPP (HERMES) KHUSUS PERUSAHAAN INI             -->
    <!-- ============================================================== -->
    <?php if ($tab === 'hermes' && $id > 0): ?>
    <?php $baseUrl = rtrim((string) APP_URL, '/'); ?>
    <div class="card shadow-sm border-0" style="max-width: 880px;">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-robot me-1 text-primary"></i> Konfigurasi Bot WhatsApp (Hermes) — <?= e($row['name']) ?></span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">1 Bot = 1 Perusahaan (<?= e($row['code']) ?>)</span>
        </div>
        <div class="card-body">
            <div class="alert alert-info py-2 small mb-3">
                <i class="bi bi-shield-lock me-1"></i>
                <strong>Isolasi Ketat Per Perusahaan:</strong> Secret Key di bawah ini khusus milik Bot Hermes <strong><?= e($row['name']) ?> (<?= e($row['code']) ?>)</strong>.
                Setiap request yang menggunakan Secret Key ini otomatis terkunci ke perusahaan <code><?= e($row['code']) ?></code> tanpa perlu mengirim parameter <code>company</code>, dan ditolak (<code>403 FORBIDDEN_COMPANY_MISMATCH</code>) bila mencoba mengakses perusahaan lain.
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Kode Perusahaan Terkunci</label>
                    <input class="form-control font-monospace fw-bold bg-light" readonly value="<?= e($row['code']) ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Hermes Bot Secret Key (Khusus <?= e($row['code']) ?>)</label>
                    <div class="input-group">
                        <input class="form-control font-monospace" type="password" id="hermesSecretInput" readonly value="<?= e((string) ($row['bot_secret'] ?? '')) ?>">
                        <button class="btn btn-outline-secondary" type="button" onclick="const el=document.getElementById('hermesSecretInput'); el.type = el.type === 'password' ? 'text' : 'password';">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('hermesSecretInput').value)">
                            <i class="bi bi-clipboard me-1"></i> Salin Key
                        </button>
                    </div>
                    <small class="text-muted">Gunakan sebagai header <code>Authorization: Bearer &lt;secret_key&gt;</code> atau query <code>?secret=&lt;secret_key&gt;</code> pada instance Hermes <?= e($row['code']) ?>.</small>
                </div>

                <div class="col-12">
                    <form method="post" action="?id=<?= $id ?>&tab=hermes" class="card card-body bg-light border p-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="hermes">
                        <input type="hidden" name="_action" value="save_phone">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small mb-1">Nomor WhatsApp Resmi Bot Perusahaan</label>
                                <input class="form-control font-monospace" name="bot_phone" placeholder="0812xxxxxxxx atau 62812xxxxxxxx" value="<?= e((string) ($row['bot_phone'] ?? '')) ?>">
                                <small class="text-muted">Nomor WhatsApp tempat pelanggan menghubungi bot ini (format: 628xxxxxxxxxx).</small>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg me-1"></i> Simpan Nomor Bot</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <form method="post" action="?id=<?= $id ?>&tab=hermes" class="mb-4" onsubmit="return confirm('Buat ulang Secret Key Bot Hermes untuk <?= e($row['name']) ?>? Instance Hermes perusahaan ini harus diperbarui dengan key baru.');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="_section" value="hermes">
                <input type="hidden" name="_action" value="regenerate">
                <button class="btn btn-outline-warning btn-sm" type="submit">
                    <i class="bi bi-arrow-repeat me-1"></i> Buat Ulang Secret Key Bot <?= e($row['code']) ?>
                </button>
            </form>

            <hr>
            <h6 class="fw-bold mb-2">Contoh Pemanggilan API dari Instance Hermes <?= e($row['code']) ?></h6>
            <p class="small text-muted mb-2">Karena Secret Key sudah terikat langsung ke <code><?= e($row['code']) ?></code>, bot tidak perlu mengirim <code>?company=<?= e($row['code']) ?></code>:</p>
            <pre class="bg-dark text-light p-3 rounded small mb-0"><code># 1. Cek Profil &amp; Rute Aktif <?= e($row['code']) ?>

curl -s "<?= e($baseUrl) ?>/api/bot/routes.php?phone=6281234567890" \
  -H "Authorization: Bearer <?= e((string) ($row['bot_secret'] ?? '')) ?>"

# 2. Tarik Antrean Outbox WA Khusus <?= e($row['code']) ?>

curl -s "<?= e($baseUrl) ?>/api/bot/outbox.php?phone=6281234567890" \
  -H "Authorization: Bearer <?= e((string) ($row['bot_secret'] ?? '')) ?>"</code></pre>
        </div>
    </div>
    <?php endif; ?>

</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
