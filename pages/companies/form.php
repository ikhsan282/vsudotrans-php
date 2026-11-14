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
if (!in_array($tab, ['profile', 'payment', 'hermes', 'audit'], true) || $id === 0) {
    $tab = 'profile';
}

$row = [
    'group_id'     => '',
    'code'         => '',
    'name'         => '',
    'note'         => '',
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
        $row = array_merge($row, [
            'group_id'     => (string) (int) ($_POST['group_id'] ?? 0),
            'code'         => $id > 0 ? $existing_code : strtoupper($in('code', 15)),
            'name'         => $in('name', 255),
            'note'         => $in('note', 2000),
            'phone_number' => normalize_phone_62($in('phone_number', 30)) ?? '',
            'website'      => $in('website', 300),
            'email'        => $in('email', 200),
            'address'      => $in('address', 1000),
            'city'         => $in('city', 100),
            'logo'         => $existing_logo,  // path Azure Blob; hanya berubah via unggah berkas
            'stamp'        => $existing_stamp,
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
        ]);
        $group_id = (int) $row['group_id'];

        if (!preg_match('/^[A-Z0-9-]{1,15}$/', $row['code'])) {
            $errors[] = 'Kode wajib 1–15 karakter: huruf, angka, atau strip.';
        }
        if ($row['name'] === '') {
            $errors[] = 'Nama wajib diisi.';
        }
        if (strlen($row['phone_number']) > 20) {
            $errors[] = 'Nomor telepon maksimal 20 digit.';
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
            $vals = [$group_id, $row['code'], $row['name'], $nul($row['note']),
                     $nul($row['phone_number']), $nul($row['website']), $nul($row['email']),
                     $nul($row['address']), $nul($row['city']), $nul($row['logo']), $nul($row['stamp']), $row['is_active']];
            try {
                if ($id > 0) {
                    $st = $db->prepare('UPDATE companies SET group_id = ?, code = ?, name = ?, note = ?,
                        phone_number = ?, website = ?, email = ?, address = ?, city = ?, logo = ?, stamp = ?, is_active = ?,
                        modified_user = ?, modified_date = NOW() WHERE id = ?');
                    $st->bind_param('issssssssssisi', ...[...$vals, $user, $id]);
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
                    $st = $db->prepare('INSERT INTO companies (group_id, code, name, note, phone_number,
                        website, email, address, city, logo, stamp, bot_secret, is_active, created_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $st->bind_param('isssssssssssis', ...[...array_slice($vals, 0, 11), $botSecret, $vals[11], $user]);
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
                $staticErr = qris_validate_static($chMerchant);
                if ($staticErr !== null) {
                    $errors[] = $staticErr;
                } elseif (qris_make_dynamic($chMerchant, 10000) === null) {
                    $errors[] = 'String QRIS Statis tidak valid (bukan format EMVCo/QRIS standar).';
                } else {
                    $chMerchant = preg_replace('/[\r\n\t]+/', '', $chMerchant) ?? $chMerchant;
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

$toolbarActions = [
    ['label' => 'Kembali', 'icon' => 'arrow-left', 'href' => 'pages/companies/index.php', 'class' => 'btn btn-outline-secondary btn-sm'],
];
if ($id > 0) {
    $toolbarActions[] = ['label' => 'Lihat Detail', 'icon' => 'eye', 'href' => 'pages/companies/detail.php?id=' . $id, 'class' => 'btn btn-outline-secondary btn-sm'];
}
if ($tab === 'profile') {
    $toolbarActions[] = ['label' => ($id > 0 ? 'Simpan Perubahan' : 'Simpan Perusahaan'), 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'companyProfileForm', 'class' => 'btn btn-primary btn-sm px-3'];
} elseif ($tab === 'payment' && $id > 0 && $curChannel) {
    $toolbarActions[] = ['label' => 'Simpan Channel ' . $curChannel['name'], 'icon' => 'check2-circle', 'type' => 'submit', 'form' => 'companyPaymentForm', 'class' => 'btn btn-primary btn-sm px-3'];
}
?>
<main class="p-2 p-md-3">
    <?= ui_erp_toolbar(
        'Perusahaan',
        'building',
        $id > 0 ? (string) $row['name'] : 'Tambah Perusahaan Baru',
        $id > 0 ? [
            ['text' => (string) $row['code'], 'class' => 'bg-primary-subtle text-primary border border-primary-subtle font-monospace'],
            ['text' => 'ID #' . $id, 'class' => 'bg-light text-secondary border'],
            ['text' => !empty($row['is_active']) ? 'Aktif' : 'Nonaktif', 'class' => 'bg-' . (!empty($row['is_active']) ? 'success' : 'secondary') . '-subtle text-' . (!empty($row['is_active']) ? 'success' : 'secondary') . ' border'],
        ] : [['text' => 'Baru', 'class' => 'bg-info-subtle text-info-emphasis border']],
        $toolbarActions,
        $id > 0 && !empty($curGroupName) ? 'Grup: <strong>' . e($curGroupName) . '</strong>' : ''
    ) ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 px-3 small mb-2">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="erp-window-card">
        <ul class="nav nav-tabs erp-sub-tabs" id="companyTabs">
            <li class="nav-item">
                <a class="nav-link<?= $tab === 'profile' ? ' active' : '' ?>" href="<?= $id > 0 ? '?id=' . $id . '&tab=profile' : '#' ?>">
                    Profil &amp; Legalitas
                </a>
            </li>
            <?php if ($id > 0): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $tab === 'payment' ? ' active' : '' ?>" href="?id=<?= $id ?>&tab=payment&amp;provider=<?= e($activeProv) ?>">
                        Channel QRIS Otomatis
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $tab === 'hermes' ? ' active' : '' ?>" href="?id=<?= $id ?>&tab=hermes">
                        Bot WhatsApp (Hermes)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $tab === 'audit' ? ' active' : '' ?>" href="?id=<?= $id ?>&tab=audit">
                        Audit Trail
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="erp-content-body">
            <!-- ============================================================== -->
            <!-- TAB 1: PROFIL & IDENTITAS PERUSAHAAN                           -->
            <!-- ============================================================== -->
            <?php if ($tab === 'profile' || $id === 0): ?>
            <form method="post" action="?id=<?= $id ?>&tab=profile" id="companyProfileForm" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="_section" value="profile">

                <div class="row g-3 mb-2">
                    <div class="col-12 col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header">
                                <span>Identitas &amp; Legalitas</span>
                                <?php if ($id > 0): ?>
                                    <span class="small text-muted fw-normal"><i class="bi bi-lock-fill me-1"></i>Kode terkunci</span>
                                <?php endif; ?>
                            </div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" for="code">Kode Perusahaan <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm text-uppercase font-monospace<?= $id > 0 ? ' bg-light text-secondary' : '' ?>" id="code" name="code" maxlength="15" required value="<?= e($row['code']) ?>" placeholder="mis. MTM, GTM"<?= $id > 0 ? ' readonly tabindex="-1"' : '' ?>>
                                        <?php if ($id > 0): ?>
                                            <div class="form-text small mt-1">Permanen (acuan folder Azure Blob &amp; nomor dokumen).</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="name">Nama Resmi <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="name" name="name" maxlength="255" required value="<?= e($row['name']) ?>" placeholder="mis. Maura Trans Mandiri">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="group_id">Grup Holding <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm" id="group_id" name="group_id" required>
                                            <option value="">— Pilih Grup Holding —</option>
                                            <?php foreach ($groups as $g): ?>
                                                <option value="<?= (int) $g['id'] ?>"<?= (string) $g['id'] === (string) $row['group_id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="city">Kota Operasional</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="city" name="city" maxlength="100" value="<?= e($row['city']) ?>" placeholder="mis. Tangerang">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="is_active">Status Aktif</label>
                                    <div class="rnt-field">
                                        <div class="rnt-check-wrap">
                                            <input class="form-check-input mt-0" type="checkbox" id="is_active" name="is_active"<?= $row['is_active'] ? ' checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold" for="is_active">Perusahaan Aktif</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header"><span>Kontak &amp; Alamat Operasional</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row">
                                    <label class="rnt-label" for="phone_number">Telepon Kantor</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" id="phone_number" name="phone_number" maxlength="20" value="<?= e((string) ($row['phone_number'] ?? '')) ?>" placeholder="62812xxxxxxxx">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="email">Email</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" type="email" id="email" name="email" maxlength="200" value="<?= e($row['email']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="website">Website</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" type="url" id="website" name="website" maxlength="300" placeholder="https://mauratransmandiri.com" value="<?= e($row['website']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1" for="address">Alamat Lengkap</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm rnt-ta-compact" id="address" name="address" rows="2" maxlength="1000"><?= e($row['address']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-0">
                    <div class="col-12 col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header"><span>Logo &amp; Stempel (Azure Blob)</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1" for="logo_file">Logo Perusahaan</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm mb-1" type="file" id="logo_file" name="logo_file" accept="image/*">
                                        <?php if (!empty($row['logo'])): ?>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <img src="<?= e(resolve_asset_url($row['logo'])) ?>" alt="Logo" style="max-height: 36px; max-width: 120px;" class="border rounded p-1 bg-light">
                                                <a href="<?= e(resolve_asset_url($row['logo'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> Lihat (<code><?= e($row['logo']) ?></code>)</a>
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">Format JPG/PNG/WEBP, maks. 3 MB.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1" for="stamp_file">Stempel / Cap</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm mb-1" type="file" id="stamp_file" name="stamp_file" accept="image/*">
                                        <?php if (!empty($row['stamp'])): ?>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <img src="<?= e(resolve_asset_url($row['stamp'])) ?>" alt="Stempel" style="max-height: 36px; max-width: 120px;" class="border rounded p-1 bg-light">
                                                <a href="<?= e(resolve_asset_url($row['stamp'])) ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> Lihat (<code><?= e($row['stamp']) ?></code>)</a>
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">Cap basah resmi perusahaan (JPG/PNG/WEBP, maks. 3 MB).</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="rnt-panel h-100 mb-0">
                            <div class="rnt-panel-header"><span>Catatan Internal</span></div>
                            <div class="rnt-panel-body">
                                <div class="rnt-row align-items-start mb-0">
                                    <label class="rnt-label pt-1" for="note">Catatan</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm" id="note" name="note" rows="4" maxlength="2000" placeholder="Catatan internal perusahaan..."><?= e($row['note']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
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
            <div class="rnt-panel">
                <div class="rnt-panel-header">
                    <span>Konfigurasi Channel Pembayaran QRIS — <?= e($curChannel['name']) ?></span>
                    <button type="submit" form="companyPaymentForm" class="rnt-header-action">
                        <i class="bi bi-check2-circle me-1"></i>Simpan Channel <?= e($curChannel['name']) ?>
                    </button>
                </div>
                <ul class="nav rnt-subpill-bar">
                    <?php foreach ($payTabs as $pt): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $pt['provider'] === $activeProv ? ' active' : '' ?>"
                               href="?id=<?= $id ?>&tab=payment&amp;provider=<?= e($pt['provider']) ?>">
                                <?= e($pt['name']) ?>
                                <?php if ($pt['is_active']): ?><span class="badge bg-success ms-1">Aktif</span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="rnt-panel-body">
                    <form method="post" id="companyPaymentForm" action="?id=<?= $id ?>&tab=payment&amp;provider=<?= e($activeProv) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="payment">
                        <input type="hidden" name="_action" value="save">
                        <input type="hidden" name="provider" value="<?= e($activeProv) ?>">

                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <div class="rnt-row">
                                    <label class="rnt-label">Nama Tampilan <span class="text-danger">*</span></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" name="name" maxlength="100" required value="<?= e((string) $curChannel['name']) ?>">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Mode Channel</label>
                                    <div class="rnt-field">
                                        <select class="form-select form-select-sm" name="mode">
                                            <option value="production"<?= $curChannel['mode'] === 'production' ? ' selected' : '' ?>>Production</option>
                                            <option value="sandbox"<?= $curChannel['mode'] === 'sandbox' ? ' selected' : '' ?>>Sandbox</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Urutan Tampil</label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" type="number" name="sort_order" value="<?= (int) $curChannel['sort_order'] ?>" style="max-width: 140px;">
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label">Biaya Layanan</label>
                                    <div class="rnt-field">
                                        <div class="input-group input-group-sm">
                                            <select class="form-select form-select-sm" name="fee_type">
                                                <option value="none"<?= $feeType === 'none' ? ' selected' : '' ?>>Tanpa Biaya</option>
                                                <option value="flat"<?= $feeType === 'flat' ? ' selected' : '' ?>>Nominal (Rp)</option>
                                                <option value="percent"<?= $feeType === 'percent' ? ' selected' : '' ?>>Persen (%)</option>
                                            </select>
                                            <input class="form-control form-control-sm" type="number" step="0.01" min="0" name="fee_value" value="<?= e((string) $feeVal) ?>" style="max-width: 120px;">
                                        </div>
                                    </div>
                                </div>
                                <div class="rnt-row">
                                    <label class="rnt-label" for="is_active_pay">Status Channel</label>
                                    <div class="rnt-field">
                                        <div class="rnt-check-wrap">
                                            <input class="form-check-input mt-0" type="checkbox" name="is_active" id="is_active_pay"<?= $curChannel['is_active'] ? ' checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold" for="is_active_pay">Aktifkan Channel Ini untuk <?= e($row['name']) ?></label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <?php $apiLabel = match ($activeProv) {
                                    'gopay'     => 'Token / Sesi GoPay',
                                    'shopeepay' => 'Token ShopeePay',
                                    default     => 'API Key KlikQRIS',
                                }; ?>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1"><?= e($apiLabel) ?></label>
                                    <div class="rnt-field">
                                        <input class="form-control form-control-sm" type="password" name="api_key" autocomplete="new-password"
                                               value="" placeholder="Kosongkan jika tidak ingin mengubah">
                                        <div class="form-text small"><?= e($chMasked) ?></div>
                                    </div>
                                </div>
                                <?php if ($activeProv === 'gopay'): ?>
                                    <div class="rnt-row align-items-start">
                                        <label class="rnt-label pt-1">Merchant ID GoPay</label>
                                        <div class="rnt-field">
                                            <input class="form-control form-control-sm" name="gopay_merchant_id" maxlength="60" value="<?= e((string) ($chCfg['merchant_ids'] ?? '')) ?>" placeholder="G123456789 (opsional)">
                                            <div class="form-text small">Kosongkan bila tidak tahu — query API mengambil semua merchant sesi.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="rnt-row align-items-start">
                                    <label class="rnt-label pt-1">Instruksi Bayar</label>
                                    <div class="rnt-field">
                                        <textarea class="form-control form-control-sm rnt-ta-compact" name="instructions" rows="2" maxlength="2000"><?= e((string) ($curChannel['instructions'] ?? '')) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="my-2">
                        <div class="rnt-row align-items-start mb-0">
                            <label class="rnt-label pt-1"><?= $activeProv === 'klikqris' ? 'Merchant ID' : 'String QRIS Statis' ?></label>
                            <div class="rnt-field">
                                <textarea class="form-control form-control-sm font-monospace rnt-ta-compact" name="merchant_id" rows="2"
                                          placeholder="<?= $activeProv === 'klikqris' ? 'ID Merchant KlikQRIS' : '000201010211... (Hasil scan teks QRIS cetak/statis toko milik ' . e($row['name']) . ')' ?>"><?= e((string) ($curChannel['merchant_id'] ?? '')) ?></textarea>
                                <?php if ($activeProv !== 'klikqris'): ?>
                                    <div class="form-text small">Sistem otomatis mengubah Tag <code>01</code> ke <code>12</code> (Dinamis), menyisipkan nominal + kode unik 3 digit ke Tag <code>54</code>, dan hitung CRC16 Tag <code>63</code>.</div>
                                    <?php
                                    $storedStatic = trim((string) ($curChannel['merchant_id'] ?? ''));
                                    if ($storedStatic !== '' && ($storedErr = qris_validate_static($storedStatic)) !== null): ?>
                                        <div class="alert alert-danger py-1 px-2 small mt-2 mb-0">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($storedErr) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="rnt-panel mb-0">
                <div class="rnt-panel-header">
                    <span>
                        <?php if ($activeProv === 'gopay'): ?>Koneksi Sesi GoPay Merchant (OTP GoBiz)
                        <?php elseif ($activeProv === 'shopeepay'): ?>Verifikasi Token ShopeePay Partner
                        <?php else: ?>Webhook KlikQRIS
                        <?php endif; ?>
                    </span>
                </div>
                <div class="rnt-panel-body">
                    <?php if ($activeProv === 'gopay'): ?>
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
                                    : '-';
                            }
                            $gpExpires = !empty($chCfg['expires_at']) ? date('d-M-Y H:i', (int) $chCfg['expires_at']) : '';
                        ?>
                            <div class="alert alert-info py-2 px-3 small mb-2">
                                Terhubung dengan: <strong><?= e($gpMerchant) ?></strong>
                                <?= $gpPhone !== '' ? '(' . e($gpPhone) . ')' : '' ?>
                                · Diperbarui: <strong><?= e($gpUpdated) ?></strong>
                                <?php if ($gpExpires !== ''): ?>
                                    · Berlaku sampai: <strong><?= e($gpExpires) ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="_section" value="payment">
                                <input type="hidden" name="_action" value="gopay_refresh">
                                <input type="hidden" name="provider" value="gopay">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Cek / Refresh Sesi GoPay</button>
                            </form>
                        </div>

                        <?php if (empty($_SESSION['gopay_otp'])): ?>
                            <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="_section" value="payment">
                                <input type="hidden" name="_action" value="otp_request">
                                <input type="hidden" name="provider" value="gopay">
                                <div class="rnt-row mb-0">
                                    <label class="rnt-label" for="otpPhone">Nomor HP GoBiz</label>
                                    <div class="rnt-field">
                                        <div class="input-group input-group-sm" style="max-width: 440px;">
                                            <input class="form-control form-control-sm" id="otpPhone" name="otp_phone" inputmode="numeric" placeholder="0812xxxxxxxx" required>
                                            <button class="btn btn-success btn-sm" type="submit">Kirim Kode OTP SMS</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <?php $otpPhone = (string) ($_SESSION['gopay_otp']['phone'] ?? ''); ?>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <form method="post" action="?id=<?= $id ?>&tab=payment&provider=gopay" class="flex-grow-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="_section" value="payment">
                                    <input type="hidden" name="_action" value="otp_verify">
                                    <input type="hidden" name="provider" value="gopay">
                                    <div class="rnt-row mb-0">
                                        <label class="rnt-label" for="otpCode">OTP ke <?= e($otpPhone) ?></label>
                                        <div class="rnt-field">
                                            <div class="input-group input-group-sm" style="max-width: 440px;">
                                                <input class="form-control form-control-sm text-center fw-bold" id="otpCode" name="otp_code" inputmode="numeric" maxlength="8" placeholder="••••" required autofocus>
                                                <button class="btn btn-success btn-sm" type="submit">Verifikasi &amp; Hubungkan</button>
                                            </div>
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
                            </div>
                        <?php endif; ?>
                    <?php elseif ($activeProv === 'shopeepay'): ?>
                        <form method="post" action="?id=<?= $id ?>&tab=payment&provider=shopeepay" class="mb-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="_section" value="payment">
                            <input type="hidden" name="_action" value="spay_check">
                            <input type="hidden" name="provider" value="shopeepay">
                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Cek Koneksi Token ShopeePay</button>
                        </form>
                        <small class="text-muted d-block">Ambil token dari aplikasi/web ShopeePay Merchant (header <code>Authorization: B:...</code>), simpan di kolom Token di atas, lalu klik Cek Koneksi.</small>
                    <?php elseif ($activeProv === 'klikqris'): ?>
                        <?php $whUrl = gateway_webhook_url($curChannel); ?>
                        <div class="rnt-row">
                            <label class="rnt-label">URL Webhook</label>
                            <div class="rnt-field">
                                <div class="input-group input-group-sm">
                                    <input class="form-control form-control-sm font-monospace" id="webhookUrl" readonly value="<?= e($whUrl) ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)">Salin URL</button>
                                </div>
                            </div>
                        </div>
                        <form method="post" action="?id=<?= $id ?>&tab=payment&provider=klikqris" class="mb-0">
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
            <div class="alert alert-info py-2 px-3 small mb-2">
                <i class="bi bi-shield-lock me-1"></i>
                <strong>Isolasi Ketat Per Perusahaan:</strong> Secret Key di bawah ini khusus milik Bot Hermes <strong><?= e($row['name']) ?> (<?= e($row['code']) ?>)</strong>.
                Setiap request otomatis terkunci ke perusahaan <code><?= e($row['code']) ?></code> tanpa parameter <code>company</code>, dan ditolak (<code>403 FORBIDDEN_COMPANY_MISMATCH</code>) bila mengakses perusahaan lain.
            </div>

            <div class="rnt-panel">
                <div class="rnt-panel-header">
                    <span>Konfigurasi &amp; Keamanan Bot WhatsApp (Hermes) — <?= e($row['name']) ?></span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">1 Bot = 1 Perusahaan (<?= e($row['code']) ?>)</span>
                </div>
                <div class="rnt-panel-body">
                    <div class="rnt-row">
                        <label class="rnt-label">Kode Terkunci</label>
                        <div class="rnt-field">
                            <input class="form-control form-control-sm font-monospace fw-bold bg-light" readonly value="<?= e($row['code']) ?>" style="max-width: 220px;">
                        </div>
                    </div>
                    <div class="rnt-row align-items-start">
                        <label class="rnt-label pt-1">Bot Secret Key</label>
                        <div class="rnt-field">
                            <div class="input-group input-group-sm">
                                <input class="form-control form-control-sm font-monospace" type="password" id="hermesSecretInput" readonly value="<?= e((string) ($row['bot_secret'] ?? '')) ?>">
                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="const el=document.getElementById('hermesSecretInput'); el.type = el.type === 'password' ? 'text' : 'password';" title="Tampilkan/Sembunyikan">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-primary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('hermesSecretInput').value)">
                                    <i class="bi bi-clipboard me-1"></i>Salin Key
                                </button>
                            </div>
                            <div class="form-text small">Gunakan sebagai header <code>Authorization: Bearer &lt;secret_key&gt;</code> atau query <code>?secret=&lt;secret_key&gt;</code>.</div>
                        </div>
                    </div>
                    <form method="post" action="?id=<?= $id ?>&tab=hermes">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="hermes">
                        <input type="hidden" name="_action" value="save_phone">
                        <div class="rnt-row align-items-start">
                            <label class="rnt-label pt-1">Nomor WA Resmi Bot</label>
                            <div class="rnt-field">
                                <div class="input-group input-group-sm" style="max-width: 480px;">
                                    <input class="form-control form-control-sm font-monospace" name="bot_phone" placeholder="0812xxxxxxxx atau 62812xxxxxxxx" value="<?= e((string) ($row['bot_phone'] ?? '')) ?>">
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg me-1"></i>Simpan Nomor Bot</button>
                                </div>
                                <div class="form-text small">Nomor WhatsApp tempat pelanggan menghubungi bot ini (format: 628xxxxxxxxxx).</div>
                            </div>
                        </div>
                    </form>
                    <form method="post" action="?id=<?= $id ?>&tab=hermes" class="mb-0" onsubmit="return confirm('Buat ulang Secret Key Bot Hermes untuk <?= e($row['name']) ?>? Instance Hermes perusahaan ini harus diperbarui dengan key baru.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="_section" value="hermes">
                        <input type="hidden" name="_action" value="regenerate">
                        <div class="rnt-row mb-0">
                            <label class="rnt-label">Rotasi Key</label>
                            <div class="rnt-field">
                                <button class="btn btn-outline-warning btn-sm" type="submit">
                                    <i class="bi bi-arrow-repeat me-1"></i>Buat Ulang Secret Key Bot <?= e($row['code']) ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="rnt-panel mb-0">
                <div class="rnt-panel-header"><span>Contoh Pemanggilan API dari Instance Hermes <?= e($row['code']) ?></span></div>
                <div class="rnt-panel-body">
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

            <!-- ============================================================== -->
            <!-- TAB 4: AUDIT TRAIL                                             -->
            <!-- ============================================================== -->
            <?php if ($tab === 'audit' && $id > 0): ?>
                <?= ui_erp_audit_panel($row) ?>
            <?php endif; ?>
        </div><!-- /.erp-content-body -->
    </div><!-- /.erp-window-card -->
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
