<?php
/**
 * Floating AI Assistant Widget gaya Hostinger (Kodee) untuk Dashboard ERP vSudoTrans.
 * Di-include via includes/footer.php agar muncul di seluruh halaman admin setelah login.
 */
if (empty($_SESSION['user_id'])) {
    return;
}

$aiUid       = (int) ($_SESSION['user_id'] ?? 0);
$aiCid       = active_company_id();
$aiCompKey   = $aiCid === null ? 'all' : (string) $aiCid;
$aiCompLabel = active_company_name() ?: 'Semua Perusahaan';
$aiUser      = current_user() ?: [];
$aiUserName  = trim((string) ($aiUser['name'] ?? ($aiUser['full_name'] ?? 'Rekan')));
$aiFirstName = explode(' ', $aiUserName)[0] ?: 'Rekan';
$aiActiveKey = (string) ($active ?? 'dashboard');
$aiPageTitle = (string) ($title ?? 'Dashboard');

$aiCanTravel   = can('travel.view');
$aiCanRental   = can('rental.view');
$aiCanBookings = $aiCanTravel || $aiCanRental;
$aiCanFinance  = can('finance.view');
$aiCanInvoices = can('invoices.view') || $aiCanFinance;
$aiCanVehicles = can('vehicles.view');
$aiCanEmployees = can('employees.view');
$aiCanDispatch = can('dispatch.view');
$aiCanReports  = can('reports.view');

// Deteksi halaman detail yang sedang dibuka (page-aware context)
$aiScript     = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$aiDetailId   = (int) ($_GET['id'] ?? 0);
$aiPageEntity = '';
if ($aiDetailId > 0) {
    if (str_ends_with($aiScript, '/pages/travel/bookings/detail.php') && $aiCanTravel) {
        $aiPageEntity = 'travel:' . $aiDetailId;
    } elseif (str_ends_with($aiScript, '/pages/rental/bookings/detail.php') && $aiCanRental) {
        $aiPageEntity = 'rental:' . $aiDetailId;
    } elseif (str_ends_with($aiScript, '/pages/vehicles/detail.php') && $aiCanVehicles) {
        $aiPageEntity = 'vehicle:' . $aiDetailId;
    } elseif (str_ends_with($aiScript, '/pages/employees/detail.php') && $aiCanEmployees) {
        $aiPageEntity = 'employee:' . $aiDetailId;
    }
}

// Contextual quick prompts disesuaikan modul aktif, halaman detail, & hak akses RBAC
$aiChips = [];
if (str_starts_with($aiPageEntity, 'travel:') || str_starts_with($aiPageEntity, 'rental:')) {
    $aiChips[] = $aiCanInvoices ? 'Ringkasan & sisa tagihan booking ini' : 'Ringkasan & status booking ini';
    $aiChips[] = 'Buatkan pesan WA untuk booking ini';
} elseif (str_starts_with($aiPageEntity, 'vehicle:')) {
    $aiChips[] = 'Cek legalitas STNK, KIR & status unit ini';
} elseif (str_starts_with($aiPageEntity, 'employee:')) {
    $aiChips[] = 'Cek detail & jatuh tempo SIM karyawan ini';
}

if ($aiActiveKey === 'finance' && ($aiCanFinance || $aiCanInvoices)) {
    if ($aiCanFinance) {
        $aiChips[] = 'Ringkasan kas & omzet hari ini';
    }
    if ($aiCanInvoices) {
        $aiChips[] = 'Cek invoice yang belum lunas';
    }
} elseif ($aiActiveKey === 'travel' && $aiCanTravel) {
    $aiChips[] = 'Jadwal travel terdekat hari ini';
    $aiChips[] = 'Cek tarif rute travel aktif';
} elseif ($aiActiveKey === 'rental' && $aiCanRental) {
    $aiChips[] = 'Ringkasan sewa rental hari ini';
    $aiChips[] = 'Unit apa yang kosong besok?';
} elseif ($aiActiveKey === 'reports' && ($aiCanReports || $aiCanFinance)) {
    $aiChips[] = 'Bandingkan omzet & booking bulan ini vs bulan lalu';
    $aiChips[] = 'Rute, armada & driver paling laris bulan ini';
} elseif (in_array($aiActiveKey, ['vehicles', 'employees', 'dispatch'], true)) {
    if ($aiCanVehicles) {
        $aiChips[] = 'Unit apa yang kosong besok?';
    } elseif ($aiCanDispatch) {
        $aiChips[] = 'Driver yang sedang bertugas';
    }
}

if ($aiCanBookings && count($aiChips) < 4) {
    $aiChips[] = 'Ringkasan operasional hari ini';
}
if (($aiCanFinance || $aiCanInvoices) && count($aiChips) < 4) {
    $aiChips[] = 'Cek invoice yang belum lunas';
}
if (($aiCanDispatch || $aiCanBookings) && count($aiChips) < 4) {
    $aiChips[] = 'Driver yang sedang bertugas';
}
$aiChips = array_slice(array_values(array_unique($aiChips)), 0, 4);
if (!$aiChips) {
    $aiChips[] = 'Apa yang bisa kamu bantu di ERP ini?';
}
$chipIconFor = static function (string $label): string {
    $low = strtolower($label);
    if (str_contains($low, 'invoice') || str_contains($low, 'tagihan')) {
        return 'bi-receipt';
    }
    if (str_contains($low, 'wa') || str_contains($low, 'whatsapp')) {
        return 'bi-whatsapp';
    }
    if (str_contains($low, 'unit') || str_contains($low, 'stnk') || str_contains($low, 'kir')) {
        return 'bi-car-front';
    }
    if (str_contains($low, 'driver') || str_contains($low, 'sim') || str_contains($low, 'karyawan')) {
        return 'bi-person-badge';
    }
    if (str_contains($low, 'omzet') || str_contains($low, 'rute') || str_contains($low, 'tarif')) {
        return 'bi-graph-up-arrow';
    }
    if (str_contains($low, 'kas')) {
        return 'bi-cash-stack';
    }
    if (str_contains($low, 'jadwal') || str_contains($low, 'sewa') || str_contains($low, 'hari ini')) {
        return 'bi-calendar2-check';
    }
    return 'bi-stars';
};
$aiChipItems = array_map(static function (string $text) use ($chipIconFor): array {
    return ['text' => $text, 'icon' => $chipIconFor($text)];
}, $aiChips);
?>
<button type="button"
        id="vstAiFab"
        class="vst-ai-fab d-print-none"
        aria-label="Buka Asisten AI"
        aria-expanded="false"
        aria-controls="vstAiDrawer"
        title="Buka vSudo AI (Alt+K)">
    <span class="vst-ai-fab-icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
    <span class="vst-ai-fab-label">Tanya AI</span>
</button>

<div id="vstAiBackdrop" class="vst-ai-backdrop d-print-none" aria-hidden="true"></div>

<aside id="vstAiDrawer"
       class="vst-ai-drawer d-print-none"
       role="dialog"
       aria-modal="false"
       aria-hidden="true"
       inert
       aria-labelledby="vstAiTitle">
    <div class="vst-ai-handle d-sm-none" aria-hidden="true"></div>
    <div class="vst-ai-header">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <div class="vst-ai-avatar">
                <i class="bi bi-stars" aria-hidden="true"></i>
                <span class="vst-ai-online-dot" aria-hidden="true"></span>
            </div>
            <div class="min-w-0">
                <div class="d-flex align-items-center gap-1 min-w-0">
                    <h6 class="mb-0 fw-bold text-truncate" id="vstAiTitle">vSudo AI</h6>
                    <span class="vst-ai-badge">Asisten</span>
                </div>
                <div class="vst-ai-subtitle text-truncate" title="<?= e($aiCompLabel) ?>">
                    <i class="bi bi-building me-1" aria-hidden="true"></i><?= e($aiCompLabel) ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
            <button type="button" id="vstAiExpandBtn" class="vst-ai-icon-btn d-none d-sm-inline-flex" title="Perlebar panel" aria-label="Perlebar panel AI" aria-pressed="false">
                <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i>
            </button>
            <button type="button" id="vstAiResetBtn" class="vst-ai-icon-btn" title="Percakapan baru" aria-label="Reset percakapan">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
            <button type="button" id="vstAiCloseBtn" class="vst-ai-icon-btn" title="Tutup (Esc)" aria-label="Tutup asisten AI">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="vst-ai-body" id="vstAiBody" aria-live="polite"></div>

    <div class="vst-ai-chips is-popover d-none" id="vstAiChips" role="region" aria-label="Topik cepat">
        <div class="vst-ai-chips-head">
            <span><i class="bi bi-lightning-charge-fill text-primary me-1" aria-hidden="true"></i>Topik Cepat</span>
            <button type="button" class="vst-ai-chips-close" id="vstAiChipsCloseBtn" aria-label="Tutup topik cepat">
                <i class="bi bi-x" aria-hidden="true"></i>
            </button>
        </div>
        <?php foreach ($aiChipItems as $chip): ?>
            <button type="button" class="vst-ai-chip" data-prompt="<?= e($chip['text']) ?>">
                <span class="vst-ai-chip-icon"><i class="bi <?= e($chip['icon']) ?>" aria-hidden="true"></i></span>
                <span class="vst-ai-chip-text"><?= e($chip['text']) ?></span>
                <i class="bi bi-arrow-right-short vst-ai-chip-arrow" aria-hidden="true"></i>
            </button>
        <?php endforeach; ?>
    </div>

    <form class="vst-ai-footer" id="vstAiForm" autocomplete="off">
        <?= csrf_field() ?>
        <div class="vst-ai-input-wrap">
            <button type="button"
                    id="vstAiPromptsBtn"
                    class="vst-ai-prompts-btn"
                    title="Topik cepat"
                    aria-label="Tampilkan topik cepat"
                    aria-expanded="false"
                    aria-controls="vstAiChips">
                <i class="bi bi-lightning-charge" aria-hidden="true"></i>
            </button>
            <textarea id="vstAiInput"
                      class="vst-ai-input"
                      rows="1"
                      maxlength="1000"
                      placeholder="Tanya ringkasan, TRV/RNT/INV, atau nopol..."
                      aria-label="Tulis pertanyaan ke asisten AI"></textarea>
            <button type="submit" id="vstAiSendBtn" class="vst-ai-send-btn" title="Kirim pertanyaan" aria-label="Kirim" disabled>
                <i class="bi bi-arrow-up" aria-hidden="true"></i>
            </button>
        </div>
        <div class="vst-ai-meta">
            <span>Enter kirim · Shift+Enter baris baru</span>
            <span><kbd>Alt</kbd>+<kbd>K</kbd></span>
        </div>
    </form>
</aside>

<script>
(function () {
    var fab            = document.getElementById('vstAiFab');
    var drawer         = document.getElementById('vstAiDrawer');
    var backdrop       = document.getElementById('vstAiBackdrop');
    var closeBtn       = document.getElementById('vstAiCloseBtn');
    var resetBtn       = document.getElementById('vstAiResetBtn');
    var expandBtn      = document.getElementById('vstAiExpandBtn');
    var bodyEl         = document.getElementById('vstAiBody');
    var chipsEl        = document.getElementById('vstAiChips');
    var chipsCloseBtn  = document.getElementById('vstAiChipsCloseBtn');
    var promptsBtn     = document.getElementById('vstAiPromptsBtn');
    var formEl         = document.getElementById('vstAiForm');
    var inputEl        = document.getElementById('vstAiInput');
    var sendBtn        = document.getElementById('vstAiSendBtn');
    if (!fab || !drawer || !bodyEl || !formEl || !inputEl) return;

    var endpoint   = <?= json_encode(APP_URL . '/api/ai_assistant.php') ?>;
    var pageActive = <?= json_encode($aiActiveKey) ?>;
    var pageTitle  = <?= json_encode($aiPageTitle) ?>;
    var pageEntity = <?= json_encode($aiPageEntity) ?>;
    var userFirst  = <?= json_encode($aiFirstName) ?>;
    var compLabel  = <?= json_encode($aiCompLabel) ?>;
    var chipItems  = <?= json_encode($aiChipItems, JSON_UNESCAPED_UNICODE) ?>;
    var histKey    = <?= json_encode("vst_ai_hist_u{$aiUid}_c{$aiCompKey}") ?>;
    var openKey    = <?= json_encode("vst_ai_open_u{$aiUid}") ?>;
    var wideKey    = <?= json_encode("vst_ai_wide_u{$aiUid}") ?>;

    var busy = false;
    var history = [];
    var focusTimer = null;
    var activeCtrl = null;

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatAiText(raw) {
        var safe = escHtml(raw);
        safe = safe.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        safe = safe.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/(^|\n)[ \t]*_([^_\n]+)_/g, '$1<span class="vst-ai-tip">$2</span>');
        var lines = safe.split(/\r?\n/);
        var html = [];
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var numMatch = line.match(/^\s*(\d+\.)\s+(.+)$/);
            if (/^\s*#{1,3}\s+/.test(line)) {
                html.push('<div class="vst-ai-heading">' + line.replace(/^\s*#{1,3}\s+/, '') + '</div>');
            } else if (/^\s{2,}[-•]\s+/.test(line)) {
                html.push('<div class="vst-ai-subbullet">' + line.replace(/^\s+[-•]\s+/, '') + '</div>');
            } else if (/^\s*[-•]\s+/.test(line)) {
                html.push('<div class="vst-ai-bullet">' + line.replace(/^\s*[-•]\s+/, '') + '</div>');
            } else if (numMatch) {
                html.push('<div class="vst-ai-num-bullet"><span class="vst-ai-num">' + escHtml(numMatch[1]) + '</span><span>' + numMatch[2] + '</span></div>');
            } else if (line.trim() === '') {
                html.push('<div class="vst-ai-spacer"></div>');
            } else {
                html.push('<div>' + line + '</div>');
            }
        }
        return html.join('');
    }

    function saveHistory() {
        try {
            sessionStorage.setItem(histKey, JSON.stringify(history.slice(-12)));
        } catch (e) {}
    }

    function loadHistory() {
        try {
            var raw = sessionStorage.getItem(histKey);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function scrollToBottom() {
        bodyEl.scrollTop = bodyEl.scrollHeight;
    }

    function setChipsOpen(open) {
        if (!chipsEl) return;
        var isOpen = !!open;
        chipsEl.classList.toggle('d-none', !isOpen);
        if (promptsBtn) {
            promptsBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            promptsBtn.classList.toggle('is-active', isOpen);
        }
    }

    function syncSendState() {
        if (!sendBtn) return;
        if (busy) {
            sendBtn.type = 'button';
            sendBtn.disabled = false;
            sendBtn.classList.add('is-stop');
            sendBtn.title = 'Hentikan jawaban';
            sendBtn.setAttribute('aria-label', 'Hentikan');
            sendBtn.innerHTML = '<i class="bi bi-stop-fill" aria-hidden="true"></i>';
        } else {
            sendBtn.type = 'submit';
            sendBtn.classList.remove('is-stop');
            sendBtn.title = 'Kirim pertanyaan';
            sendBtn.setAttribute('aria-label', 'Kirim');
            sendBtn.innerHTML = '<i class="bi bi-arrow-up" aria-hidden="true"></i>';
            sendBtn.disabled = inputEl.value.trim() === '';
        }
    }

    function setExpanded(wide, save) {
        var isWide = !!wide;
        drawer.classList.toggle('is-expanded', isWide);
        if (expandBtn) {
            expandBtn.setAttribute('aria-pressed', isWide ? 'true' : 'false');
            expandBtn.title = isWide ? 'Kembali ukuran normal' : 'Perlebar panel';
            expandBtn.setAttribute('aria-label', expandBtn.title);
            var ic = expandBtn.querySelector('i');
            if (ic) {
                ic.className = isWide ? 'bi bi-arrows-angle-contract' : 'bi bi-arrows-angle-expand';
            }
        }
        if (save) {
            try { sessionStorage.setItem(wideKey, isWide ? '1' : '0'); } catch (e) {}
        }
    }

    function formatWaCopy(raw) {
        return String(raw || '')
            .replace(/\*\*([^*\n]+)\*\*/g, '*$1*')
            .replace(/`([^`\n]+)`/g, '$1')
            .replace(/^_([^_\n]+)_$/gm, '$1')
            .trim();
    }

    function formatWaSendText(raw) {
        return formatWaCopy(raw)
            .replace(/^\*?Draft\s+Pesan\s+WhatsApp[^:\n]*:\*?\s*\n+/i, '')
            .trim();
    }

    function copyTextToClipboard(text, btn) {
        var done = function () {
            if (!btn) return;
            btn.classList.add('is-copied');
            btn.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Tersalin';
            setTimeout(function () {
                btn.classList.remove('is-copied');
                btn.innerHTML = '<i class="bi bi-clipboard me-1" aria-hidden="true"></i>Salin';
            }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text);
                done();
            });
            return;
        }
        fallbackCopy(text);
        done();
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    function renderActionChips(wrap, actions) {
        if (!Array.isArray(actions) || !actions.length) return;
        var actWrap = document.createElement('div');
        actWrap.className = 'vst-ai-actions';
        actions.forEach(function (act) {
            if (!act || !act.url || !act.label) return;
            var a = document.createElement('a');
            a.className = 'vst-ai-action-chip';
            a.href = act.url;
            a.innerHTML = '<i class="bi bi-arrow-up-right-circle me-1" aria-hidden="true"></i>' + escHtml(act.label);
            actWrap.appendChild(a);
        });
        if (actWrap.children.length) {
            wrap.appendChild(actWrap);
        }
    }

    function renderCopyTool(wrap, rawText, waTarget) {
        if (!rawText) return;
        var tools = document.createElement('div');
        tools.className = 'vst-ai-msg-tools';
        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'vst-ai-copy-btn';
        copyBtn.title = 'Salin teks ke clipboard (siap tempel ke WhatsApp)';
        copyBtn.innerHTML = '<i class="bi bi-clipboard me-1" aria-hidden="true"></i>Salin';
        copyBtn.addEventListener('click', function () {
            copyTextToClipboard(formatWaCopy(rawText), copyBtn);
        });
        tools.appendChild(copyBtn);

        if (waTarget && waTarget.phone && /^[1-9]\d{8,14}$/.test(String(waTarget.phone))) {
            var waBtn = document.createElement('a');
            waBtn.className = 'vst-ai-wa-btn';
            waBtn.href = 'https://wa.me/' + encodeURIComponent(String(waTarget.phone))
                       + '?text=' + encodeURIComponent(formatWaSendText(rawText));
            waBtn.target = '_blank';
            waBtn.rel = 'noopener noreferrer';
            waBtn.title = 'Buka chat WhatsApp' + (waTarget.name ? ' ke ' + waTarget.name : '');
            waBtn.innerHTML = '<i class="bi bi-whatsapp me-1" aria-hidden="true"></i>Kirim via WhatsApp';
            tools.appendChild(waBtn);
        }

        wrap.appendChild(tools);
    }

    function createBotAvatar() {
        var av = document.createElement('div');
        av.className = 'vst-ai-msg-avatar';
        av.setAttribute('aria-hidden', 'true');
        av.innerHTML = '<i class="bi bi-stars"></i>';
        return av;
    }

    function appendMessage(msg, opts) {
        opts = opts || {};
        var role = msg.role === 'user' ? 'user' : 'assistant';
        var wrap = document.createElement('div');
        wrap.className = 'vst-ai-msg vst-ai-msg-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'vst-ai-bubble' + (opts.isError ? ' vst-ai-bubble-error' : '');
        if (role === 'user') {
            bubble.textContent = msg.content || '';
            wrap.appendChild(bubble);
        } else {
            var content = document.createElement('div');
            content.className = 'vst-ai-msg-content';
            bubble.innerHTML = formatAiText(msg.content || '');
            content.appendChild(bubble);
            renderActionChips(content, msg.actions);
            if (!opts.noTools) {
                renderCopyTool(content, msg.content || '', msg.wa_target || null);
            }
            wrap.appendChild(createBotAvatar());
            wrap.appendChild(content);
        }

        bodyEl.appendChild(wrap);
        scrollToBottom();
    }

    function renderWelcome() {
        setChipsOpen(false);
        var chipsHtml = (chipItems || []).map(function (c) {
            return '<button type="button" class="vst-ai-chip" data-prompt="' + escHtml(c.text) + '">'
                + '<span class="vst-ai-chip-icon"><i class="bi ' + escHtml(c.icon || 'bi-stars') + '" aria-hidden="true"></i></span>'
                + '<span class="vst-ai-chip-text">' + escHtml(c.text) + '</span>'
                + '<i class="bi bi-arrow-right-short vst-ai-chip-arrow" aria-hidden="true"></i>'
                + '</button>';
        }).join('');

        bodyEl.innerHTML = ''
            + '<div class="vst-ai-welcome">'
            +   '<div class="vst-ai-welcome-hero">'
            +     '<div class="vst-ai-welcome-icon"><i class="bi bi-stars" aria-hidden="true"></i></div>'
            +     '<div class="vst-ai-welcome-title">Halo, ' + escHtml(userFirst) + '!</div>'
            +     '<p class="vst-ai-welcome-sub">Siap bantu pantau operasional <strong>' + escHtml(compLabel) + '</strong>.</p>'
            +   '</div>'
            +   '<div class="vst-ai-welcome-label">Pilih topik cepat</div>'
            +   '<div class="vst-ai-welcome-chips">' + chipsHtml + '</div>'
            +   '<div class="vst-ai-welcome-hint">'
            +     '<i class="bi bi-info-circle me-1" aria-hidden="true"></i>'
            +     'Bisa langsung cari kode <code>TRV-...</code>, <code>RNT-...</code>, <code>INV/...</code>, nama/HP pelanggan, atau nopol armada.'
            +   '</div>'
            + '</div>';
    }

    function renderAll() {
        if (!history.length) {
            renderWelcome();
            return;
        }
        bodyEl.innerHTML = '';
        history.forEach(function (m) { appendMessage(m); });
    }

    function syncInputHeight() {
        inputEl.style.height = 'auto';
        var sh = inputEl.scrollHeight;
        inputEl.style.height = Math.min(sh, 96) + 'px';
        inputEl.style.overflowY = sh > 96 ? 'auto' : 'hidden';
        syncSendState();
    }

    function setOpen(open, opts) {
        opts = opts || {};
        var isOpen = !!open;
        if (focusTimer) {
            clearTimeout(focusTimer);
            focusTimer = null;
        }
        if (opts.instant) {
            drawer.classList.add('is-instant');
            fab.classList.add('is-instant');
            if (backdrop) backdrop.classList.add('is-instant');
        }
        if (isOpen) {
            if (fab.contains(document.activeElement) && document.activeElement && typeof document.activeElement.blur === 'function') {
                document.activeElement.blur();
            }
            fab.classList.add('is-hidden');
            fab.setAttribute('aria-expanded', 'true');
            fab.setAttribute('aria-hidden', 'true');
            fab.inert = true;
            drawer.inert = false;
            drawer.setAttribute('aria-hidden', 'false');
            drawer.classList.add('is-open');
            if (backdrop) backdrop.classList.add('is-open');
            scrollToBottom();
            if (!opts.skipFocus) {
                focusTimer = setTimeout(function () {
                    focusTimer = null;
                    if (drawer.classList.contains('is-open') && !drawer.inert) {
                        try { inputEl.focus({ preventScroll: true }); } catch (e) { inputEl.focus(); }
                    }
                }, 140);
            }
        } else {
            setChipsOpen(false);
            var hadFocus = drawer.contains(document.activeElement);
            if (hadFocus && document.activeElement && typeof document.activeElement.blur === 'function') {
                document.activeElement.blur();
            }
            drawer.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-open');
            drawer.inert = true;
            drawer.setAttribute('aria-hidden', 'true');
            fab.inert = false;
            fab.removeAttribute('aria-hidden');
            fab.classList.remove('is-hidden');
            fab.setAttribute('aria-expanded', 'false');
            if (opts.restoreFocus && hadFocus) {
                try { fab.focus({ preventScroll: true }); } catch (e) { fab.focus(); }
            }
        }
        try {
            sessionStorage.setItem(openKey, isOpen ? '1' : '0');
        } catch (e) {}
        if (opts.instant) {
            void drawer.offsetWidth;
            var clearInstant = function () {
                drawer.classList.remove('is-instant');
                fab.classList.remove('is-instant');
                if (backdrop) backdrop.classList.remove('is-instant');
            };
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(clearInstant);
            } else {
                setTimeout(clearInstant, 0);
            }
        }
    }

    function showTyping() {
        var wrap = document.createElement('div');
        wrap.id = 'vstAiTyping';
        wrap.className = 'vst-ai-msg vst-ai-msg-assistant';
        wrap.appendChild(createBotAvatar());
        var content = document.createElement('div');
        content.className = 'vst-ai-msg-content';
        content.innerHTML = '<div class="vst-ai-bubble vst-ai-shimmer" role="status" aria-label="AI sedang menyusun jawaban">'
            + '<span class="vst-skel vst-skel-line" style="width:92%"></span>'
            + '<span class="vst-skel vst-skel-line" style="width:76%"></span>'
            + '<span class="vst-skel vst-skel-line" style="width:54%"></span>'
            + '</div>';
        wrap.appendChild(content);
        bodyEl.appendChild(wrap);
        scrollToBottom();
        return wrap;
    }

    function sendPrompt(text) {
        var msgText = (text || '').trim();
        if (!msgText || busy) return;

        setChipsOpen(false);
        busy = true;
        inputEl.value = '';
        syncInputHeight();

        var reqCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        activeCtrl = reqCtrl;

        if (!history.length && bodyEl.querySelector('.vst-ai-welcome')) {
            bodyEl.innerHTML = '';
        }

        var userMsg = { role: 'user', content: msgText };
        history.push(userMsg);
        appendMessage(userMsg);
        saveHistory();

        var typingEl = showTyping();
        var csrfInp = formEl.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('message', msgText);
        fd.append('page_active', pageActive);
        fd.append('page_title', pageTitle);
        fd.append('stream', '1');
        if (pageEntity) fd.append('page_entity', pageEntity);
        if (csrfInp) fd.append('csrf_token', csrfInp.value);

        var liveWrap = null;
        var liveContent = null;
        var liveBubble = null;
        var liveText = '';
        var finalized = false;

        var removeTyping = function () {
            if (typingEl && typingEl.parentNode) {
                typingEl.parentNode.removeChild(typingEl);
                typingEl = null;
            }
        };

        var ensureLiveBubble = function () {
            removeTyping();
            if (!liveWrap) {
                liveWrap = document.createElement('div');
                liveWrap.className = 'vst-ai-msg vst-ai-msg-assistant';
                liveWrap.appendChild(createBotAvatar());
                liveContent = document.createElement('div');
                liveContent.className = 'vst-ai-msg-content';
                liveBubble = document.createElement('div');
                liveBubble.className = 'vst-ai-bubble';
                liveContent.appendChild(liveBubble);
                liveWrap.appendChild(liveContent);
                bodyEl.appendChild(liveWrap);
            }
        };

        var applyFinal = function (data) {
            if (finalized) return;
            finalized = true;
            removeTyping();
            if (activeCtrl === reqCtrl) activeCtrl = null;
            busy = false;
            syncSendState();
            if (reqCtrl && reqCtrl.signal && reqCtrl.signal.aborted) {
                if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                return;
            }

            if (!data || !data.ok) {
                if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                var errTxt = (data && data.error) ? data.error : 'Gagal memproses permintaan.';
                appendMessage({ role: 'assistant', content: 'Maaf, terjadi kendala: ' + errTxt }, { noTools: true, isError: true });
                return;
            }

            var finalReply = data.reply || liveText || '';
            var finalActions = Array.isArray(data.actions) ? data.actions : [];
            var finalWaTarget = (data.wa_target && data.wa_target.phone) ? data.wa_target : null;
            var botMsg = { role: 'assistant', content: finalReply, actions: finalActions, wa_target: finalWaTarget };
            history.push(botMsg);
            saveHistory();

            if (liveWrap && liveBubble && liveContent) {
                liveBubble.innerHTML = formatAiText(finalReply);
                renderActionChips(liveContent, finalActions);
                renderCopyTool(liveContent, finalReply, finalWaTarget);
                scrollToBottom();
            } else {
                appendMessage(botMsg);
            }
        };

        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', signal: reqCtrl ? reqCtrl.signal : undefined })
            .then(function (r) {
                var ctype = (r.headers.get('content-type') || '').toLowerCase();
                if (!r.ok || ctype.indexOf('text/event-stream') === -1 || !r.body || !r.body.getReader) {
                    return r.text().then(function (raw) {
                        var parsed = null;
                        try { parsed = JSON.parse(raw); } catch (e) {}
                        if (!r.ok) {
                            applyFinal({ ok: false, error: (parsed && parsed.error) ? parsed.error : ('Gagal (HTTP ' + r.status + ')') });
                        } else {
                            applyFinal(parsed);
                        }
                    });
                }

                var reader = r.body.getReader();
                var decoder = new TextDecoder('utf-8');
                var buf = '';

                function handleEventBlock(block) {
                    var lines = block.split(/\r?\n/);
                    var evName = 'message';
                    var dataParts = [];
                    for (var i = 0; i < lines.length; i++) {
                        var ln = lines[i];
                        if (ln.indexOf('event:') === 0) {
                            evName = ln.slice(6).trim();
                        } else if (ln.indexOf('data:') === 0) {
                            dataParts.push(ln.slice(5).trim());
                        }
                    }
                    if (!dataParts.length) return;
                    var payload = null;
                    try { payload = JSON.parse(dataParts.join('\n')); } catch (e) { return; }
                    if (evName === 'delta' && payload && typeof payload.text === 'string') {
                        ensureLiveBubble();
                        liveText += payload.text;
                        liveBubble.innerHTML = formatAiText(liveText) + '<span class="vst-ai-streaming-cursor" aria-hidden="true"></span>';
                        scrollToBottom();
                    } else if (evName === 'done') {
                        applyFinal(payload);
                    }
                }

                function pump() {
                    return reader.read().then(function (chunk) {
                        if (reqCtrl && reqCtrl.signal && reqCtrl.signal.aborted) {
                            applyFinal({ ok: false });
                            return;
                        }
                        if (chunk.done) {
                            if (buf.trim()) handleEventBlock(buf);
                            if (!finalized) {
                                if (liveText) {
                                    applyFinal({ ok: true, reply: liveText, actions: [] });
                                } else {
                                    applyFinal({ ok: false, error: 'Koneksi stream terputus sebelum selesai.' });
                                }
                            }
                            return;
                        }
                        buf += decoder.decode(chunk.value, { stream: true });
                        var sepIdx;
                        while ((sepIdx = buf.search(/\r?\n\r?\n/)) !== -1) {
                            var match = buf.match(/\r?\n\r?\n/);
                            var block = buf.slice(0, sepIdx);
                            buf = buf.slice(sepIdx + match[0].length);
                            if (block.trim()) handleEventBlock(block);
                        }
                        return pump();
                    });
                }

                return pump();
            })
            .catch(function (err) {
                removeTyping();
                if (activeCtrl === reqCtrl) activeCtrl = null;
                busy = false;
                syncSendState();
                if (err && err.name === 'AbortError') {
                    if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                    return;
                }
                if (!finalized) {
                    if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                    appendMessage({ role: 'assistant', content: 'Tidak dapat terhubung ke server aplikasi. Periksa koneksi Anda lalu coba lagi.' }, { noTools: true, isError: true });
                }
            });
    }

    fab.addEventListener('click', function () { setOpen(true); });
    if (backdrop) {
        backdrop.addEventListener('click', function () { setOpen(false); });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function (ev) {
            setOpen(false, { restoreFocus: ev.detail === 0 });
        });
    }
    if (expandBtn) {
        expandBtn.addEventListener('click', function () {
            setExpanded(!drawer.classList.contains('is-expanded'), true);
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (activeCtrl) {
                try { activeCtrl.abort(); } catch (e) {}
                activeCtrl = null;
            }
            busy = false;
            history = [];
            syncSendState();
            try { sessionStorage.removeItem(histKey); } catch (e) {}
            renderWelcome();
            var csrfInp = formEl.querySelector('input[name="csrf_token"]');
            if (csrfInp) {
                var rfd = new FormData();
                rfd.append('act', 'reset');
                rfd.append('csrf_token', csrfInp.value);
                fetch(endpoint, { method: 'POST', body: rfd, credentials: 'same-origin' }).catch(function () {});
            }
            inputEl.focus();
        });
    }
    if (promptsBtn) {
        promptsBtn.addEventListener('click', function () {
            if (!chipsEl) return;
            setChipsOpen(chipsEl.classList.contains('d-none'));
        });
    }
    if (chipsCloseBtn) {
        chipsCloseBtn.addEventListener('click', function () { setChipsOpen(false); });
    }
    drawer.addEventListener('click', function (ev) {
        var chip = ev.target.closest('.vst-ai-chip');
        if (chip) {
            if (!drawer.classList.contains('is-open')) setOpen(true);
            sendPrompt(chip.getAttribute('data-prompt') || (chip.querySelector('.vst-ai-chip-text') ? chip.querySelector('.vst-ai-chip-text').textContent : chip.textContent));
            return;
        }
        if (chipsEl && !chipsEl.classList.contains('d-none') && !chipsEl.contains(ev.target) && (!promptsBtn || !promptsBtn.contains(ev.target))) {
            setChipsOpen(false);
        }
    });
    if (sendBtn) {
        sendBtn.addEventListener('click', function (ev) {
            if (busy && activeCtrl) {
                ev.preventDefault();
                try { activeCtrl.abort(); } catch (e) {}
                activeCtrl = null;
                busy = false;
                syncSendState();
            }
        });
    }
    formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (busy) return;
        sendPrompt(inputEl.value);
    });
    inputEl.addEventListener('input', syncInputHeight);
    inputEl.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            if (!busy) sendPrompt(inputEl.value);
        }
    });
    var sbToggler = document.getElementById('sidebarToggler');
    if (sbToggler) {
        sbToggler.addEventListener('click', function () {
            if (drawer.classList.contains('is-open') && window.innerWidth < 992) {
                setOpen(false);
            }
        });
    }
    document.addEventListener('pointerdown', function (ev) {
        if (drawer.classList.contains('is-open') && window.innerWidth < 576 && !drawer.contains(ev.target) && !fab.contains(ev.target)) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.altKey && !ev.ctrlKey && !ev.metaKey && ev.key.toLowerCase() === 'k') {
            ev.preventDefault();
            var willOpen = !drawer.classList.contains('is-open');
            setOpen(willOpen, { restoreFocus: !willOpen });
        } else if (ev.key === 'Escape' && drawer.classList.contains('is-open') && !document.querySelector('.modal.show')) {
            ev.preventDefault();
            setOpen(false, { restoreFocus: true });
        }
    });

    history = loadHistory();
    renderAll();
    syncSendState();
    try {
        if (sessionStorage.getItem(wideKey) === '1') {
            setExpanded(true, false);
        }
        if (sessionStorage.getItem(openKey) === '1') {
            setOpen(true, { instant: true, skipFocus: true });
        } else {
            drawer.inert = true;
        }
    } catch (e) {
        drawer.inert = true;
    }
})();
</script>
