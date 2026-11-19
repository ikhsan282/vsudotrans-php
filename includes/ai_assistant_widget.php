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
} elseif (in_array($aiActiveKey, ['vehicles', 'employees', 'dispatch'], true)) {
    if ($aiCanVehicles) {
        $aiChips[] = 'Unit apa yang kosong besok?';
    } elseif ($aiCanDispatch) {
        $aiChips[] = 'Driver yang sedang bertugas';
    }
    if ($aiCanVehicles || $aiCanEmployees) {
        $aiChips[] = 'Cek STNK, KIR & SIM jatuh tempo';
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
if (($aiCanVehicles || $aiCanEmployees) && count($aiChips) < 4) {
    $aiChips[] = 'Cek STNK, KIR & SIM jatuh tempo';
}
$aiChips = array_slice(array_values(array_unique($aiChips)), 0, 4);
if (!$aiChips) {
    $aiChips[] = 'Apa yang bisa kamu bantu di ERP ini?';
}
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

<aside id="vstAiDrawer"
       class="vst-ai-drawer d-print-none"
       role="dialog"
       aria-modal="false"
       aria-hidden="true"
       aria-labelledby="vstAiTitle">
    <div class="vst-ai-header">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <div class="vst-ai-avatar"><i class="bi bi-stars" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <h6 class="mb-0 fw-bold text-truncate" id="vstAiTitle">vSudo AI</h6>
                <div class="vst-ai-subtitle text-truncate" title="<?= e($aiCompLabel) ?>">
                    <i class="bi bi-building me-1" aria-hidden="true"></i><?= e($aiCompLabel) ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
            <button type="button" id="vstAiResetBtn" class="vst-ai-icon-btn" title="Reset percakapan" aria-label="Reset percakapan">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
            <button type="button" id="vstAiCloseBtn" class="vst-ai-icon-btn" title="Tutup (Esc)" aria-label="Tutup asisten AI">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="vst-ai-chips" id="vstAiChips">
        <?php foreach ($aiChips as $chip): ?>
            <button type="button" class="vst-ai-chip" data-prompt="<?= e($chip) ?>"><?= e($chip) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="vst-ai-body" id="vstAiBody" aria-live="polite"></div>

    <form class="vst-ai-footer" id="vstAiForm" autocomplete="off">
        <?= csrf_field() ?>
        <div class="vst-ai-input-wrap">
            <textarea id="vstAiInput"
                      class="vst-ai-input"
                      rows="1"
                      maxlength="1000"
                      placeholder="Tanya ringkasan hari ini, cek TRV/RNT/INV, atau nopol..."
                      aria-label="Tulis pertanyaan ke asisten AI"></textarea>
            <button type="submit" id="vstAiSendBtn" class="vst-ai-send-btn" title="Kirim pertanyaan" aria-label="Kirim">
                <i class="bi bi-send-fill" aria-hidden="true"></i>
            </button>
        </div>
        <div class="vst-ai-meta">
            <span>Shortcut: <kbd>Alt</kbd>+<kbd>K</kbd> · Enter untuk kirim</span>
            <span>Asisten Operasional</span>
        </div>
    </form>
</aside>

<script>
(function () {
    var fab      = document.getElementById('vstAiFab');
    var drawer   = document.getElementById('vstAiDrawer');
    var closeBtn = document.getElementById('vstAiCloseBtn');
    var resetBtn = document.getElementById('vstAiResetBtn');
    var bodyEl   = document.getElementById('vstAiBody');
    var chipsEl  = document.getElementById('vstAiChips');
    var formEl   = document.getElementById('vstAiForm');
    var inputEl  = document.getElementById('vstAiInput');
    var sendBtn  = document.getElementById('vstAiSendBtn');
    if (!fab || !drawer || !bodyEl || !formEl || !inputEl) return;

    var endpoint   = <?= json_encode(APP_URL . '/api/ai_assistant.php') ?>;
    var pageActive = <?= json_encode($aiActiveKey) ?>;
    var pageTitle  = <?= json_encode($aiPageTitle) ?>;
    var pageEntity = <?= json_encode($aiPageEntity) ?>;
    var userFirst  = <?= json_encode($aiFirstName) ?>;
    var compLabel  = <?= json_encode($aiCompLabel) ?>;
    var histKey    = <?= json_encode("vst_ai_hist_u{$aiUid}_c{$aiCompKey}") ?>;
    var openKey    = <?= json_encode("vst_ai_open_u{$aiUid}") ?>;

    var busy = false;
    var history = [];

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
            if (/^\s{2,}[-•]\s+/.test(line)) {
                html.push('<div class="vst-ai-subbullet">' + line.replace(/^\s+[-•]\s+/, '') + '</div>');
            } else if (/^\s*[-•]\s+/.test(line)) {
                html.push('<div class="vst-ai-bullet">' + line.replace(/^\s*[-•]\s+/, '') + '</div>');
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

    function formatWaCopy(raw) {
        return String(raw || '')
            .replace(/\*\*([^*\n]+)\*\*/g, '*$1*')
            .replace(/`([^`\n]+)`/g, '$1')
            .replace(/^_([^_\n]+)_$/gm, '$1')
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

    function renderCopyTool(wrap, rawText) {
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
        wrap.appendChild(tools);
    }

    function appendMessage(msg, opts) {
        opts = opts || {};
        var role = msg.role === 'user' ? 'user' : 'assistant';
        var wrap = document.createElement('div');
        wrap.className = 'vst-ai-msg vst-ai-msg-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'vst-ai-bubble';
        if (role === 'user') {
            bubble.textContent = msg.content || '';
        } else {
            bubble.innerHTML = formatAiText(msg.content || '');
        }
        wrap.appendChild(bubble);

        if (role === 'assistant') {
            renderActionChips(wrap, msg.actions);
            if (!opts.noTools) {
                renderCopyTool(wrap, msg.content || '');
            }
        }

        bodyEl.appendChild(wrap);
        scrollToBottom();
    }

    function renderWelcome() {
        bodyEl.innerHTML = '';
        appendMessage({
            role: 'assistant',
            content: 'Halo **' + userFirst + '**! Saya siap membantu memantau operasional **' + compLabel + '**.\n'
                   + '- Klik topik cepat di atas untuk ringkasan langsung.\n'
                   + '- Tanya unit kosong besok, tarif rute, cek nama/HP pelanggan, kode booking (`TRV-...`/`RNT-...`), atau invoice (`INV/...`).'
        }, { noTools: true });
    }

    function renderAll() {
        if (!history.length) {
            renderWelcome();
            return;
        }
        bodyEl.innerHTML = '';
        history.forEach(function (m) { appendMessage(m); });
    }

    function setOpen(open) {
        drawer.classList.toggle('is-open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        fab.classList.toggle('is-hidden', open);
        try {
            sessionStorage.setItem(openKey, open ? '1' : '0');
        } catch (e) {}
        if (open) {
            scrollToBottom();
            setTimeout(function () { inputEl.focus(); }, 120);
        }
    }

    function showTyping() {
        var wrap = document.createElement('div');
        wrap.id = 'vstAiTyping';
        wrap.className = 'vst-ai-msg vst-ai-msg-assistant';
        wrap.innerHTML = '<div class="vst-ai-bubble vst-ai-typing"><span></span><span></span><span></span></div>';
        bodyEl.appendChild(wrap);
        scrollToBottom();
        return wrap;
    }

    function sendPrompt(text) {
        var msgText = (text || '').trim();
        if (!msgText || busy) return;

        busy = true;
        sendBtn.disabled = true;
        inputEl.value = '';
        inputEl.style.height = 'auto';

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
                liveBubble = document.createElement('div');
                liveBubble.className = 'vst-ai-bubble';
                liveWrap.appendChild(liveBubble);
                bodyEl.appendChild(liveWrap);
            }
        };

        var applyFinal = function (data) {
            if (finalized) return;
            finalized = true;
            removeTyping();
            busy = false;
            sendBtn.disabled = false;

            if (!data || !data.ok) {
                if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                var errTxt = (data && data.error) ? data.error : 'Gagal memproses permintaan.';
                appendMessage({ role: 'assistant', content: 'Maaf, terjadi kendala: ' + errTxt }, { noTools: true });
                return;
            }

            var finalReply = data.reply || liveText || '';
            var finalActions = Array.isArray(data.actions) ? data.actions : [];
            var botMsg = { role: 'assistant', content: finalReply, actions: finalActions };
            history.push(botMsg);
            saveHistory();

            if (liveWrap && liveBubble) {
                liveBubble.innerHTML = formatAiText(finalReply);
                renderActionChips(liveWrap, finalActions);
                renderCopyTool(liveWrap, finalReply);
                scrollToBottom();
            } else {
                appendMessage(botMsg);
            }
        };

        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
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
            .catch(function () {
                removeTyping();
                busy = false;
                sendBtn.disabled = false;
                if (!finalized) {
                    if (liveWrap && liveWrap.parentNode) liveWrap.parentNode.removeChild(liveWrap);
                    appendMessage({ role: 'assistant', content: 'Tidak dapat terhubung ke server aplikasi. Periksa koneksi Anda lalu coba lagi.' }, { noTools: true });
                }
            });
    }

    fab.addEventListener('click', function () { setOpen(true); });
    if (closeBtn) {
        closeBtn.addEventListener('click', function () { setOpen(false); });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            history = [];
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
    if (chipsEl) {
        chipsEl.addEventListener('click', function (ev) {
            var chip = ev.target.closest('.vst-ai-chip');
            if (!chip) return;
            if (!drawer.classList.contains('is-open')) setOpen(true);
            sendPrompt(chip.getAttribute('data-prompt') || chip.textContent);
        });
    }
    formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        sendPrompt(inputEl.value);
    });
    inputEl.addEventListener('input', function () {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 96) + 'px';
    });
    inputEl.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            sendPrompt(inputEl.value);
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.altKey && !ev.ctrlKey && !ev.metaKey && ev.key.toLowerCase() === 'k') {
            ev.preventDefault();
            setOpen(!drawer.classList.contains('is-open'));
        } else if (ev.key === 'Escape' && drawer.classList.contains('is-open')) {
            setOpen(false);
        }
    });

    history = loadHistory();
    renderAll();
    try {
        if (sessionStorage.getItem(openKey) === '1') {
            setOpen(true);
        }
    } catch (e) {}
})();
</script>
