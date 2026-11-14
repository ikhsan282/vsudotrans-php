/**
 * Headless Chrome DevTools Protocol (CDP) Browser E2E Verification.
 *
 * Uses Node.js 24 built-in WebSocket + fetch + child_process (zero npm dependencies).
 * Launches an isolated headless Chrome/Edge instance against http://127.0.0.1:8080/vsudotrans-php
 * and verifies real DOM rendering, TomSelect behavior, Bootstrap alerts, form validations,
 * layout bounding rectangles, and zero console/uncaught runtime JS errors.
 */
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE_URL = (process.env.APP_BASE_URL || 'http://127.0.0.1:8080/vsudotrans-php').replace(/\/+$/, '');

const BROWSER_CANDIDATES = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
];

function findBrowserExecutable() {
    for (const p of BROWSER_CANDIDATES) {
        if (fs.existsSync(p)) return p;
    }
    throw new Error('Tidak ditemukan Chrome atau Edge pada path standar Windows.');
}

const delay = (ms) => new Promise((r) => setTimeout(r, ms));

class CdpSession {
    constructor(wsUrl) {
        this.ws = new WebSocket(wsUrl);
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Map();
        this.jsErrors = [];
        this.httpErrors = [];
    }

    async connect() {
        const cdnCache = new Map();
        const fetchCdnCached = (url) => {
            if (!cdnCache.has(url)) {
                const job = (async () => {
                    const res = await fetch(url, { signal: AbortSignal.timeout(12000) });
                    const buf = Buffer.from(await res.arrayBuffer());
                    const ct = res.headers.get('content-type') || 'application/octet-stream';
                    return {
                        responseCode: res.status,
                        responseHeaders: [
                            { name: 'Content-Type', value: ct },
                            { name: 'Access-Control-Allow-Origin', value: '*' },
                            { name: 'Cache-Control', value: 'public, max-age=3600' },
                        ],
                        body: buf.toString('base64'),
                    };
                })().catch((err) => {
                    cdnCache.delete(url);
                    throw err;
                });
                cdnCache.set(url, job);
            }
            return cdnCache.get(url);
        };

        await new Promise((resolve, reject) => {
            const t = setTimeout(() => reject(new Error('Timeout koneksi WebSocket CDP')), 10000);
            this.ws.addEventListener('open', () => { clearTimeout(t); resolve(); }, { once: true });
            this.ws.addEventListener('error', (err) => { clearTimeout(t); reject(err); }, { once: true });
        });

        this.ws.addEventListener('message', (evt) => {
            const msg = JSON.parse(String(evt.data));
            if (msg.id) {
                const entry = this.pending.get(msg.id);
                if (entry) {
                    this.pending.delete(msg.id);
                    clearTimeout(entry.timer);
                    if (msg.error) {
                        entry.reject(new Error(`CDP ${entry.method}: ${msg.error.message} (${msg.error.code})`));
                    } else {
                        entry.resolve(msg.result || {});
                    }
                }
                return;
            }
            if (msg.method) {
                if (msg.method === 'Fetch.requestPaused') {
                    const { requestId, request } = msg.params || {};
                    fetchCdnCached(request.url)
                        .then((payload) => this.send('Fetch.fulfillRequest', { requestId, ...payload }))
                        .catch(() => this.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => { }));
                    return;
                } else if (msg.method === 'Runtime.exceptionThrown') {
                    const ex = msg.params?.exceptionDetails;
                    const text = ex?.exception?.description || ex?.text || 'Uncaught JS exception';
                    this.jsErrors.push(`Runtime.exceptionThrown: ${text}`);
                } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
                    const args = (msg.params.args || []).map((a) => a.value ?? a.description ?? a.type).join(' ');
                    if (!args.includes('favicon.ico')) {
                        this.jsErrors.push(`console.error: ${args}`);
                    }
                } else if (msg.method === 'Network.responseReceived') {
                    const res = msg.params?.response;
                    if (res && res.url && res.url.startsWith(BASE_URL) && res.status >= 400 && !res.url.endsWith('/favicon.ico')) {
                        this.httpErrors.push(`HTTP ${res.status} ${res.url}`);
                    }
                }
                const list = this.listeners.get(msg.method);
                if (list) {
                    for (const fn of [...list]) fn(msg.params || {});
                }
            }
        });

        await this.send('Page.enable');
        await this.send('Runtime.enable');
        await this.send('Log.enable');
        await this.send('Network.enable');
        await this.send('Fetch.enable', {
            patterns: [{ urlPattern: 'https://cdn.jsdelivr.net/*', requestStage: 'Request' }],
        });
    }

    send(method, params = {}, timeoutMs = 20000) {
        const id = this.nextId++;
        const timeoutErr = new Error(`Timeout CDP command: ${method}`);
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(timeoutErr);
            }, timeoutMs);
            this.pending.set(id, { method, resolve, reject, timer });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    onceEvent(method, timeoutMs = 30000, context = '') {
        const timeoutErr = new Error(`Timeout menunggu event CDP: ${method}${context ? ` (${context})` : ''}`);
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                off();
                reject(timeoutErr);
            }, timeoutMs);
            const handler = (params) => {
                clearTimeout(timer);
                off();
                resolve(params);
            };
            const off = () => {
                const arr = this.listeners.get(method);
                if (!arr) return;
                const idx = arr.indexOf(handler);
                if (idx >= 0) arr.splice(idx, 1);
            };
            if (!this.listeners.has(method)) this.listeners.set(method, []);
            this.listeners.get(method).push(handler);
        });
    }

    async waitLoadAndReady(context = '') {
        await this.onceEvent('Page.loadEventFired', 30000, context);
        await this.evalJs(`(async () => {
            const deadline = Date.now() + 5000;
            while (document.readyState !== 'complete' && Date.now() < deadline) {
                await new Promise(r => setTimeout(r, 40));
            }
            await new Promise(r => setTimeout(r, 150));
            return document.readyState;
        })()`);
    }

    async navigate(url) {
        const loadP = this.onceEvent('Page.loadEventFired', 30000, url);
        const navRes = await this.send('Page.navigate', { url });
        if (navRes.errorText) {
            throw new Error(`Navigasi gagal (${url}): ${navRes.errorText}`);
        }
        await loadP;
        await this.evalJs(`(async () => {
            const deadline = Date.now() + 5000;
            while (document.readyState !== 'complete' && Date.now() < deadline) {
                await new Promise(r => setTimeout(r, 40));
            }
            await new Promise(r => setTimeout(r, 180));
            return document.readyState;
        })()`);
    }

    async setViewport(width, height, mobile = false) {
        await this.send('Emulation.setDeviceMetricsOverride', {
            width,
            height,
            deviceScaleFactor: 1,
            mobile,
        });
        await delay(150);
    }

    async evalJs(expression) {
        const res = await this.send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        if (res.exceptionDetails) {
            const msg = res.exceptionDetails.exception?.description || res.exceptionDetails.text || 'JS eval error';
            throw new Error(`evalJs exception: ${msg}`);
        }
        return res.result ? res.result.value : undefined;
    }

    async typeFocused(text) {
        await this.send('Input.insertText', { text: String(text) });
        await delay(50);
    }

    async clickSelector(selector) {
        const pt = await this.evalJs(`(() => {
            const el = document.querySelector(${JSON.stringify(selector)});
            if (!el) throw new Error('Elemen tidak ditemukan: ' + ${JSON.stringify(selector)});
            el.scrollIntoView({ block: 'center', inline: 'nearest' });
            const r = el.getBoundingClientRect();
            return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2), w: r.width, h: r.height };
        })()`);
        if (!pt || pt.w <= 0 || pt.h <= 0) {
            throw new Error(`Elemen tidak terlihat untuk diklik: ${selector}`);
        }
        await this.send('Input.dispatchMouseEvent', {
            type: 'mouseMoved', x: pt.x, y: pt.y, button: 'left', clickCount: 0,
        });
        await this.send('Input.dispatchMouseEvent', {
            type: 'mousePressed', x: pt.x, y: pt.y, button: 'left', clickCount: 1,
        });
        await this.send('Input.dispatchMouseEvent', {
            type: 'mouseReleased', x: pt.x, y: pt.y, button: 'left', clickCount: 1,
        });
        await delay(80);
    }

    close() {
        try { this.ws.close(); } catch { }
    }
}

async function launchHeadlessChrome(exePath, userDataDir) {
    const args = [
        '--headless=new',
        '--remote-debugging-port=0',
        `--user-data-dir=${userDataDir}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-gpu',
        '--disable-extensions',
        '--disable-background-networking',
        '--proxy-server=direct://',
        '--disable-quic',
        '--window-size=1440,900',
        'about:blank',
    ];

    const proc = spawn(exePath, args, { stdio: ['ignore', 'ignore', 'pipe'] });

    const devToolsWs = await new Promise((resolve, reject) => {
        let buf = '';
        const timer = setTimeout(() => {
            reject(new Error(`Timeout menunggu DevTools URL dari Chrome stderr: ${buf}`));
        }, 15000);
        proc.stderr.on('data', (chunk) => {
            buf += chunk.toString('utf8');
            const m = buf.match(/DevTools listening on (ws:\/\/[^\r\n]+)/);
            if (m) {
                clearTimeout(timer);
                resolve(m[1].trim());
            }
        });
        proc.once('exit', (code) => {
            clearTimeout(timer);
            reject(new Error(`Chrome exit lebih awal dengan kode ${code}. Stderr: ${buf}`));
        });
    });

    const u = new URL(devToolsWs);
    const listUrl = `http://${u.host}/json/list`;
    let pageWs = null;
    for (let i = 0; i < 20; i++) {
        const res = await fetch(listUrl);
        const targets = await res.json();
        const page = targets.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
        if (page) {
            pageWs = page.webSocketDebuggerUrl;
            break;
        }
        await delay(100);
    }
    if (!pageWs) {
        throw new Error('Target tab page CDP tidak ditemukan di /json/list');
    }
    return { proc, pageWs };
}

function cleanupTempDir(dir) {
    for (let i = 0; i < 6; i++) {
        try {
            fs.rmSync(dir, { recursive: true, force: true });
            return;
        } catch {
            try { execFileSync('cmd', ['/c', 'timeout', '/t', '1', '/nobreak'], { stdio: 'ignore' }); } catch { }
        }
    }
}

let passCount = 0;
function pass(label) {
    passCount++;
    console.log(`[PASS] ${label}`);
}

async function assertNoPhpErrors(cdp, pageLabel) {
    const info = await cdp.evalJs(`(() => {
        const txt = document.body ? document.body.innerText : '';
        const bad = /(Fatal error|Parse error|Warning:|Notice:|Uncaught mysqli_sql_exception)/i.exec(txt);
        return { match: bad ? bad[0] : null, url: location.href, len: txt.length };
    })()`);
    assert.equal(info.match, null, `${pageLabel} mengandung PHP error: ${info.match}`);
    assert.ok(info.len > 50, `${pageLabel} body terlalu kosong (${info.len} chars)`);
}

async function main() {
    console.log('=== Browser UI E2E Verification (Headless Chrome CDP) ===');
    const exePath = findBrowserExecutable();
    const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'vst_e2e_'));
    let chromeProc = null;
    let cdp = null;

    try {
        const launched = await launchHeadlessChrome(exePath, userDataDir);
        chromeProc = launched.proc;
        cdp = new CdpSession(launched.pageWs);
        await cdp.connect();
        await cdp.setViewport(1440, 900, false);

        // 1. Login sebagai Super Admin & switch active_company_id = 1 (MTM)
        await cdp.navigate(`${BASE_URL}/pages/auth/login.php`);
        await assertNoPhpErrors(cdp, 'Login Page');
        const loginLoad = cdp.waitLoadAndReady('login submit');
        await cdp.evalJs(`(() => {
            const form = document.querySelector('form');
            form.querySelector('input[name="username"]').value = 'superadmin';
            form.querySelector('input[name="password"]').value = 'P@ssw0rd';
            form.submit();
        })()`);
        await loginLoad;
        const postLoginUrl = await cdp.evalJs('location.href');
        assert.ok(!postLoginUrl.includes('login.php'), `Harus keluar dari login.php, actual: ${postLoginUrl}`);
        pass('Login browser Super Admin berhasil masuk dashboard');

        const switched = await cdp.evalJs(`(() => {
            const inp = document.querySelector('form[action*="companies/switch.php"] input[name="company_id"][value="1"]');
            const form = inp ? inp.closest('form') : null;
            if (!form) return false;
            const btn = form.querySelector('button[type="submit"]');
            if (btn && btn.classList.contains('active')) return 'already';
            form.submit();
            return 'submitted';
        })()`);
        if (switched === 'submitted') {
            await cdp.waitLoadAndReady('company switch');
        }
        assert.ok(switched === 'already' || switched === 'submitted', 'Topbar switcher perusahaan tersedia');
        pass('Session perusahaan aktif diset ke Company 1 (MTM)');

        // 2. Verifikasi Harmonisasi Panel Peraturan Komisi (regulations/form.php)
        await cdp.navigate(`${BASE_URL}/pages/transport/regulations/form.php`);
        await assertNoPhpErrors(cdp, 'Regulations Form');

        const regDom = await cdp.evalJs(`(() => {
            const content = document.querySelector('.erp-content-body');
            const panels = content ? content.querySelectorAll('.rnt-panel') : [];
            const headers = content ? content.querySelectorAll('.rnt-panel-header') : [];
            const cards = content ? content.querySelectorAll('.card') : [];
            const btns = ['btnAutoSummaryNote', 'btnAddRegRow'].map((id) => {
                const el = document.getElementById(id);
                if (!el) return { id, exists: false };
                const cs = getComputedStyle(el);
                return {
                    id,
                    exists: true,
                    hasClass: el.classList.contains('rnt-header-action'),
                    borderTop: cs.borderTopWidth,
                };
            });
            const auditHdr = document.querySelector('#tab-reg-audit .rnt-panel-header');
            return {
                panelCount: panels.length,
                headerCount: headers.length,
                cardCount: cards.length,
                btns,
                auditTitle: auditHdr ? auditHdr.textContent.trim() : '',
            };
        })()`);
        assert.ok(regDom.panelCount >= 3, `Harusnya >= 3 .rnt-panel, actual ${regDom.panelCount}`);
        assert.ok(regDom.headerCount >= 3, `Harusnya >= 3 .rnt-panel-header, actual ${regDom.headerCount}`);
        assert.equal(regDom.cardCount, 0, 'Tidak boleh ada sisa .card Bootstrap di .erp-content-body');
        for (const b of regDom.btns) {
            assert.ok(b.exists && b.hasClass, `Tombol ${b.id} wajib memakai class .rnt-header-action`);
            assert.equal(b.borderTop, '0px', `Tombol ${b.id} memakai styling inline borderless`);
        }
        assert.equal(regDom.auditTitle, 'Audit Trail Dokumen', 'Tab Audit Trail memakai .rnt-panel-header');

        const regAddRes = await cdp.evalJs(`(() => {
            const before = document.querySelectorAll('#regDetailTable tbody tr').length;
            document.getElementById('btnAddRegRow').click();
            const rows = document.querySelectorAll('#regDetailTable tbody tr');
            const lastBtn = rows[rows.length - 1]?.querySelector('button i.bi-x-lg')?.closest('button');
            return {
                before,
                after: rows.length,
                badge: document.getElementById('regTotalRowsBadge')?.textContent.trim(),
                iconOnlyDel: Boolean(lastBtn && lastBtn.textContent.trim() === ''),
            };
        })()`);
        assert.ok(regAddRes.after === regAddRes.before + 1, 'Tambah Baris Komisi menambah 1 baris di grid');
        assert.ok(regAddRes.badge.startsWith(`${regAddRes.after} `), `Badge baris terupdate (${regAddRes.badge})`);
        assert.ok(regAddRes.iconOnlyDel, 'Tombol hapus baris grid Peraturan Komisi hanya berupa icon');
        pass('transport/regulations/form.php memakai .rnt-panel + .rnt-header-action inline & tombol aksi icon-only');

        // 3. Verifikasi Validasi Duplikat Kategori, Alert Non-Autodismiss >5s, & Validasi Harga Live di travel/rutes/form.php
        await cdp.navigate(`${BASE_URL}/pages/travel/rutes/form.php`);
        await assertNoPhpErrors(cdp, 'Rutes Form');

        const dupSetup = await cdp.evalJs(`(async () => {
            const rows1 = document.querySelectorAll('#ruteTariffBody tr.rute-tariff-row');
            const sel1 = rows1[0]?.querySelector('.tariff-cat-select');
            const opts = Array.from(sel1.options).map(o => ({ val: o.value, text: o.textContent.trim() })).filter(o => o.val);
            if (opts.length < 2) throw new Error('Kategori kendaraan MTM minimal 2 untuk test duplikat');

            const catA = opts[0].val;
            const catB = opts[1].val;
            if (sel1.tomselect) sel1.tomselect.setValue(catA);
            else { sel1.value = catA; sel1.dispatchEvent(new Event('change', { bubbles: true })); }
            await new Promise(r => setTimeout(r, 50));

            document.getElementById('btnAddRuteTariff').click();
            await new Promise(r => setTimeout(r, 120));

            const rows2 = document.querySelectorAll('#ruteTariffBody tr.rute-tariff-row');
            const sel2 = rows2[1]?.querySelector('.tariff-cat-select');
            if (!sel2) throw new Error('Baris tarif ke-2 gagal dibuat');

            let dropdownOptText = '';
            let dropdownOptDisabled = false;
            if (sel2.tomselect) {
                sel2.tomselect.open();
                await new Promise(r => setTimeout(r, 60));
                const ddOpt = sel2.tomselect.dropdown_content?.querySelector('[data-value="' + CSS.escape(catA) + '"]');
                dropdownOptText = ddOpt ? ddOpt.textContent.trim() : '';
                dropdownOptDisabled = ddOpt ? (ddOpt.classList.contains('disabled') || ddOpt.getAttribute('aria-disabled') === 'true') : false;
                sel2.tomselect.close();
            }

            const tsOptDisabled = sel2.tomselect ? Boolean(sel2.tomselect.options[catA]?.disabled) : Boolean(sel2.querySelector('option[value="' + catA + '"]')?.disabled);
            const tsOptText = sel2.tomselect ? (sel2.tomselect.options[catA]?.text || '') : '';

            // Uji safety net JS: bila opsi terpakai tetap dipilih paksa secara programatik -> harus di-revert & memunculkan alert
            if (sel2.tomselect) {
                sel2.tomselect.options[catA].disabled = false;
                sel2.tomselect.setValue(catA);
            } else {
                const o = sel2.querySelector('option[value="' + catA + '"]');
                if (o) o.disabled = false;
                sel2.value = catA;
                sel2.dispatchEvent(new Event('change', { bubbles: true }));
            }
            await new Promise(r => setTimeout(r, 80));

            const warn = document.getElementById('ruteTariffDupWarn');
            const msg = document.getElementById('ruteTariffDupMsg');
            return {
                catA,
                catB,
                rowCount: rows2.length,
                tsOptDisabled,
                tsOptText,
                dropdownOptDisabled,
                dropdownOptText,
                sel2ValueAfterDup: sel2.tomselect ? sel2.tomselect.getValue() : sel2.value,
                warnVisible: warn ? !warn.classList.contains('d-none') : false,
                warnText: msg ? msg.textContent.trim() : '',
                sel2Invalid: sel2.classList.contains('is-invalid') && (!sel2.tomselect || sel2.tomselect.wrapper.classList.contains('is-invalid')),
            };
        })()`);

        assert.equal(dupSetup.rowCount, 2, 'Tambah Kategori menambah baris tarif ke-2');
        assert.ok(dupSetup.tsOptDisabled, 'Opsi kategori baris #1 menjadi disabled di TomSelect baris #2');
        assert.ok(dupSetup.tsOptText.includes('(Sudah dipilih)'), `Label opsi menampilkan "(Sudah dipilih)", actual: ${dupSetup.tsOptText}`);
        assert.ok(dupSetup.dropdownOptDisabled && dupSetup.dropdownOptText.includes('(Sudah dipilih)'), 'Dropdown DOM TomSelect menandai opsi terpakai disabled + (Sudah dipilih)');
        assert.equal(dupSetup.sel2ValueAfterDup, '', 'Pilihan duplikat otomatis ditolak & di-revert menjadi kosong');
        assert.ok(dupSetup.warnVisible, '#ruteTariffDupWarn tampil saat kategori duplikat dipilih');
        assert.ok(dupSetup.warnText.includes('baris #1'), `Pesan duplikat menyebut baris #1: ${dupSetup.warnText}`);
        assert.ok(dupSetup.sel2Invalid, 'Select & TomSelect wrapper baris #2 mendapat .is-invalid');
        pass('Validasi kategori ganda (TomSelect disabled + revert + .is-invalid + #ruteTariffDupWarn) berjalan real-time');

        // Cek #ruteTariffDupWarn bertahan > 5.3 detik (data-no-autodismiss="1" vs app.js 5000ms timer)
        await delay(5300);
        const dupStillAlive = await cdp.evalJs(`(() => {
            const warn = document.getElementById('ruteTariffDupWarn');
            return Boolean(warn && warn.isConnected && !warn.classList.contains('d-none'));
        })()`);
        assert.ok(dupStillAlive, '#ruteTariffDupWarn TIDAK boleh hilang otomatis oleh auto-dismiss 5 detik app.js');
        pass('Alert #ruteTariffDupWarn (data-no-autodismiss) tetap bertahan setelah > 5.3 detik');

        // Pilih kategori berbeda di baris #2 -> warning duplikat bersih; uji validasi hierarki harga real-time
        const priceRes = await cdp.evalJs(`(async () => {
            const rows = document.querySelectorAll('#ruteTariffBody tr.rute-tariff-row');
            const sel1 = rows[0].querySelector('.tariff-cat-select');
            const sel2 = rows[1].querySelector('.tariff-cat-select');
            const chosen1 = sel1.tomselect ? sel1.tomselect.getValue() : sel1.value;
            const opts = Array.from(sel2.options).map(o => o.value).filter(Boolean);
            const catB = opts.find(v => v !== chosen1);
            if (sel2.tomselect) sel2.tomselect.setValue(catB);
            else { sel2.value = catB; sel2.dispatchEvent(new Event('change', { bubbles: true })); }
            await new Promise(r => setTimeout(r, 80));
            const dupClearedAfterValidPick = document.getElementById('ruteTariffDupWarn').classList.contains('d-none');

            const tr1 = rows[0];
            const dMin = tr1.querySelector('.tariff-rp-disp[data-target="min"]');
            const dPrice = tr1.querySelector('.tariff-rp-disp[data-target="price"]');
            const dMax = tr1.querySelector('.tariff-rp-disp[data-target="max"]');
            const form = document.getElementById('formRute');
            const setRp = (el, v) => {
                el.value = String(v);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const wEl = document.getElementById('ruteTariffPriceWarn');
            const mEl = document.getElementById('ruteTariffPriceMsg');

            // Case A: Harga Min (500.000) > Harga Standar (300.000)
            setRp(dMin, 500000);
            setRp(dPrice, 300000);
            setRp(dMax, 0);
            const caseAMsg = mEl.textContent.trim();
            const caseAVis = !wEl.classList.contains('d-none');
            const caseABadFields = dMin.classList.contains('is-invalid') && dPrice.classList.contains('is-invalid');
            const submitBlockedA = !form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

            // Case B: Harga Standar (300.000) > Harga Maks (250.000)
            setRp(dMin, 200000);
            setRp(dPrice, 300000);
            setRp(dMax, 250000);
            const caseBMsg = mEl.textContent.trim();
            const caseBVis = !wEl.classList.contains('d-none');
            const caseBBadFields = dPrice.classList.contains('is-invalid') && dMax.classList.contains('is-invalid');
            const submitBlockedB = !form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

            // Case C: Valid Min (200.000) <= Standar (300.000) <= Maks (400.000)
            setRp(dMax, 400000);
            const caseCVis = !wEl.classList.contains('d-none');
            const caseCNoInvalid = !dMin.classList.contains('is-invalid') && !dPrice.classList.contains('is-invalid') && !dMax.classList.contains('is-invalid');

            const tr2 = rows[1];
            setRp(tr2.querySelector('.tariff-rp-disp[data-target="price"]'), 350000);

            return {
                dupClearedAfterValidPick,
                caseAVis, caseAMsg, caseABadFields, submitBlockedA,
                caseBVis, caseBMsg, caseBBadFields, submitBlockedB,
                caseCVis, caseCNoInvalid,
            };
        })()`);

        assert.ok(priceRes.dupClearedAfterValidPick, 'Alert duplikat kategori hilang saat kategori berbeda dipilih');
        assert.ok(priceRes.caseAVis && priceRes.caseABadFields && priceRes.submitBlockedA, `Case Min > Standar wajib muncul alert, tandai field, dan blokir submit (${priceRes.caseAMsg})`);
        assert.ok(priceRes.caseAMsg.includes('Harga Min') && priceRes.caseAMsg.includes('Harga Standar'));
        assert.ok(priceRes.caseBVis && priceRes.caseBBadFields && priceRes.submitBlockedB, `Case Standar > Maks wajib muncul alert, tandai field, dan blokir submit (${priceRes.caseBMsg})`);
        assert.ok(priceRes.caseBMsg.includes('Harga Standar') && priceRes.caseBMsg.includes('Harga Maks'));
        assert.ok(!priceRes.caseCVis && priceRes.caseCNoInvalid, 'Saat Min <= Standar <= Maks valid, alert & .is-invalid menghilang');
        pass('Validasi hierarki harga real-time (Min <= Standar <= Maks) & blokir submit bekerja presisi');

        // 4. Verifikasi Tab Radius Jemput & Antar: Layout Kiri-Kanan, Header Sejajar, Mobile Stacking, Anti-Duplikat Kecamatan
        const radiusLayoutDesktop = await cdp.evalJs(`(async () => {
            document.getElementById('tab-rute-radius-btn').click();
            await new Promise(r => setTimeout(r, 250));
            const p = document.querySelector('.rute-radius-panel[data-area="pickup"]')?.getBoundingClientRect();
            const d = document.querySelector('.rute-radius-panel[data-area="delivery"]')?.getBoundingClientRect();
            const headerRows = ['pickup', 'delivery'].map((area) => {
                const hdr = document.querySelector('.rute-radius-panel[data-area="' + area + '"] .rnt-panel-header');
                const hRect = hdr.getBoundingClientRect();
                const childTops = Array.from(hdr.children).map((c) => Math.round(c.getBoundingClientRect().top));
                const btnTops = Array.from(hdr.querySelectorAll('button')).map((b) => Math.round(b.getBoundingClientRect().top));
                return { area, height: Math.round(hRect.height), childTops, btnTops };
            });
            return {
                p: p ? { left: p.left, right: p.right, top: p.top, bottom: p.bottom, width: p.width, height: p.height } : null,
                d: d ? { left: d.left, right: d.right, top: d.top, bottom: d.bottom, width: d.width, height: d.height } : null,
                headerRows,
            };
        })()`);
        assert.ok(radiusLayoutDesktop.p && radiusLayoutDesktop.d, 'Panel radius pickup & delivery ter-render');
        assert.ok(radiusLayoutDesktop.p.width > 200 && radiusLayoutDesktop.d.width > 200, 'Kedua panel radius tampak penuh');
        assert.ok(radiusLayoutDesktop.p.left < radiusLayoutDesktop.d.left, 'Radius Penjemputan berada di KIRI Radius Pengantaran');
        assert.ok(radiusLayoutDesktop.p.right <= radiusLayoutDesktop.d.left + 2, 'Panel Penjemputan dan Pengantaran tidak tumpang tindih');
        assert.ok(Math.abs(radiusLayoutDesktop.p.top - radiusLayoutDesktop.d.top) < 20, 'Kedua panel sejajar berdampingan di desktop');
        for (const hr of radiusLayoutDesktop.headerRows) {
            assert.ok(hr.height <= 42, `Header radius ${hr.area} harus 1 baris sejajar (${hr.height}px)`);
            const minTop = Math.min(...hr.childTops, ...hr.btnTops);
            const maxTop = Math.max(...hr.childTops, ...hr.btnTops);
            assert.ok(maxTop - minTop <= 8, `Judul & tombol header radius ${hr.area} tidak sejajar vertikal: ${JSON.stringify(hr)}`);
        }
        pass('Layout desktop 1440px menempatkan Radius Penjemputan (kiri) & Pengantaran (kanan) beserta tombol header sejajar 1 baris');

        await cdp.setViewport(375, 812, true);
        const radiusLayoutMobile = await cdp.evalJs(`(() => {
            const p = document.querySelector('.rute-radius-panel[data-area="pickup"]')?.getBoundingClientRect();
            const d = document.querySelector('.rute-radius-panel[data-area="delivery"]')?.getBoundingClientRect();
            return {
                pBottom: p.bottom,
                dTop: d.top,
            };
        })()`);
        assert.ok(radiusLayoutMobile.dTop >= radiusLayoutMobile.pBottom - 2, 'Pada viewport mobile 375px panel menumpuk vertikal rapi');
        pass('Layout responsif mobile 375px menumpuk panel radius tanpa bertabrakan');
        await cdp.setViewport(1440, 900, false);

        // Tambah 1 baris manual dengan klik mouse nyata lalu klik input TomSelect Kecamatan
        await cdp.clickSelector('.rute-radius-panel[data-area="pickup"] .btn-add-radius-row');
        const manualAddCheck = await cdp.evalJs(`(() => {
            const panel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const rows = Array.from(panel.querySelectorAll('tr.rute-radius-row'));
            if (rows.length !== 1) throw new Error('Baris radius pickup harus 1 setelah klik Tambah, actual=' + rows.length);
            const sel1 = rows[0].querySelector('.radius-dist-select');
            if (!sel1 || !sel1.tomselect) throw new Error('TomSelect radius tidak terinisialisasi');
            return {
                isOpenBeforeClick: sel1.tomselect.isOpen,
                hasWrapper: Boolean(rows[0].querySelector('.ts-wrapper')),
            };
        })()`);
        assert.ok(manualAddCheck.hasWrapper && !manualAddCheck.isOpenBeforeClick, 'Setelah klik Tambah manual, dropdown belum terbuka/terjebak blur-toggle');

        // Klik kontrol TomSelect Row 1 lewat mouse event asli: dropdown harus langsung muncul & terisi daftar kecamatan
        await cdp.clickSelector('.rute-radius-panel[data-area="pickup"] tr.rute-radius-row:first-child .ts-control');
        const openOnClickCheck = await cdp.evalJs(`(async () => {
            const panel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const sel1 = panel.querySelector('tr.rute-radius-row .radius-dist-select');
            const ts = sel1.tomselect;
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const optCount = Object.keys(ts.options).filter(Boolean).length;
                const ddOptCount = ts.dropdown_content ? ts.dropdown_content.querySelectorAll('.option').length : 0;
                if (ts.isOpen && optCount >= 2 && ddOptCount >= 2) break;
                await new Promise(r => setTimeout(r, 60));
            }
            const ddStyle = getComputedStyle(ts.dropdown);
            const ddRect = ts.dropdown.getBoundingClientRect();
            const optCount = Object.keys(ts.options).filter(Boolean).length;
            const ddOptCount = ts.dropdown_content ? ts.dropdown_content.querySelectorAll('.option').length : 0;
            return {
                isOpen: ts.isOpen,
                ddDisplay: ddStyle.display,
                ddVisibility: ddStyle.visibility,
                ddHeight: Math.round(ddRect.height),
                optCount,
                ddOptCount,
            };
        })()`);
        assert.ok(
            openOnClickCheck.isOpen && openOnClickCheck.ddDisplay === 'block' && openOnClickCheck.ddHeight > 80 && openOnClickCheck.optCount >= 2 && openOnClickCheck.ddOptCount >= 2,
            `Klik dropdown kecamatan setelah Tambah manual wajib langsung membuka daftar kecamatan | actual=${JSON.stringify(openOnClickCheck)}`
        );
        pass('Klik "+ Tambah" manual lalu klik dropdown Kecamatan langsung memunculkan daftar opsi kecamatan');

        // Tambah baris ke-2 lalu uji pencarian remote 'ba' & proteksi duplikat kecamatan
        await cdp.clickSelector('.rute-radius-panel[data-area="pickup"] .btn-add-radius-row');
        await cdp.evalJs(`(async () => {
            const panel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const rows = Array.from(panel.querySelectorAll('tr.rute-radius-row'));
            if (rows.length !== 2) throw new Error('Gagal menambah 2 baris radius pickup');

            const sel1 = rows[0].querySelector('.radius-dist-select');
            const sel2 = rows[1].querySelector('.radius-dist-select');
            if (!sel1.tomselect || !sel2.tomselect) throw new Error('TomSelect radius tidak terinisialisasi');

            sel1.tomselect.open();
            sel1.tomselect.control_input.focus();
        })()`);
        // Ketik query 'ba' melalui CDP Input event agar memicu fetch remote districts/options.php asli
        await cdp.typeFocused('ba');

        const row1Pick = await cdp.evalJs(`(async () => {
            const panel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const rows = Array.from(panel.querySelectorAll('tr.rute-radius-row'));
            const sel1 = rows[0].querySelector('.radius-dist-select');
            const sel2 = rows[1].querySelector('.radius-dist-select');

            const deadline = Date.now() + 5000;
            let baOpts = [];
            while (Date.now() < deadline) {
                baOpts = Object.values(sel1.tomselect.options).filter(
                    (o) => o && o.value && String(o.text || '').toLowerCase().includes('ba')
                );
                if (sel1.tomselect.loading === 0 && baOpts.length >= 2) break;
                await new Promise(r => setTimeout(r, 60));
            }
            if (baOpts.length < 2) throw new Error('Opsi kecamatan query "ba" dari districts/options.php < 2');

            const d1 = String(baOpts[0].value);
            const d2 = String(baOpts[1].value);
            sel1.tomselect.setValue(d1);
            sel1.tomselect.close();
            await new Promise(r => setTimeout(r, 80));

            // Fokus dan buka Row 2 lalu siapkan input pencarian remote kecamatan nyata
            sel2.tomselect.close();
            sel2.tomselect.open();
            sel2.tomselect.control_input.focus();
            return { d1, d2 };
        })()`);
        await cdp.typeFocused('ba');

        const radiusDupAndBulk = await cdp.evalJs(`(async () => {
            const d1 = ${JSON.stringify(row1Pick.d1)};
            const d2 = ${JSON.stringify(row1Pick.d2)};
            const panel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const rows = Array.from(panel.querySelectorAll('tr.rute-radius-row'));
            const sel1 = rows[0].querySelector('.radius-dist-select');
            const sel2 = rows[1].querySelector('.radius-dist-select');

            const deadline = Date.now() + 5000;
            while ((!sel2.tomselect.options[d1] || !sel2.tomselect.options[d2]) && Date.now() < deadline) {
                await new Promise(r => setTimeout(r, 60));
            }
            await new Promise(r => setTimeout(r, 120));
            const s1Val = sel1.tomselect.getValue();
            const s2IsOpen = sel2.tomselect.isOpen;
            const opt1InRow2Disabled = Boolean(sel2.tomselect.options[d1]?.disabled);
            const opt1InRow2Text = String(sel2.tomselect.options[d1]?.text || '');
            const opt1InRow2Name = String(sel2.tomselect.options[d1]?.name || '');
            const ddOpt = sel2.tomselect.dropdown_content?.querySelector('[data-value="' + CSS.escape(d1) + '"]');
            const ddHtml = ddOpt ? ddOpt.outerHTML : '';
            sel2.tomselect.close();

            // Uji safety net JS untuk duplikat kecamatan pada Row 2
            sel2.tomselect.options[d1].disabled = false;
            sel2.tomselect.setValue(d1);
            await new Promise(r => setTimeout(r, 80));
            const warnEl = document.getElementById('ruteRadiusWarn_pickup');
            const warnOnDup = Boolean(warnEl && !warnEl.classList.contains('d-none'));
            const warnDupMsg = warnEl ? warnEl.textContent.trim() : '';
            const row2Reverted = (sel2.tomselect.getValue() === '');
            const row2Invalid = sel2.classList.contains('is-invalid') && sel2.tomselect.wrapper.classList.contains('is-invalid');

            // Pilih d2 (unik) lalu pastikan toolbar biaya seragam sudah dihapus & tombol aksi icon-only
            sel2.tomselect.setValue(d2);
            await new Promise(r => setTimeout(r, 80));
            const warnClearedOnUnique = warnEl ? warnEl.classList.contains('d-none') : false;
            const bulkToolbarRemoved = !panel.querySelector('.radius-bulk-disp')
                && !panel.querySelector('.btn-bulk-radius-zero')
                && !panel.querySelector('.btn-bulk-radius-all');

            const iconBtns = Array.from(document.querySelectorAll('.btn-remove-tariff, .btn-remove-radius'));
            const allIconOnly = iconBtns.every((b) => b.querySelector('i.bi-x-lg') && b.textContent.trim() === '');

            return {
                d1, s1Val, s2IsOpen, opt1InRow2Disabled, opt1InRow2Text, opt1InRow2Name, ddHtml,
                warnOnDup, warnDupMsg, row2Reverted, row2Invalid, warnClearedOnUnique,
                bulkToolbarRemoved, allIconOnly,
            };
        })()`);

        assert.ok(
            radiusDupAndBulk.opt1InRow2Disabled && (radiusDupAndBulk.opt1InRow2Text.includes('(Sudah dipilih)') || radiusDupAndBulk.opt1InRow2Name.includes('(Sudah dipilih)')),
            `Kecamatan terpilih pada Row 1 otomatis disabled + "(Sudah dipilih)" di TomSelect Row 2 | actual=${JSON.stringify({ d1: radiusDupAndBulk.d1, s1Val: radiusDupAndBulk.s1Val, s2IsOpen: radiusDupAndBulk.s2IsOpen, disabled: radiusDupAndBulk.opt1InRow2Disabled, text: radiusDupAndBulk.opt1InRow2Text, name: radiusDupAndBulk.opt1InRow2Name, ddHtml: radiusDupAndBulk.ddHtml })}`
        );
        assert.ok(radiusDupAndBulk.warnOnDup && radiusDupAndBulk.row2Reverted && radiusDupAndBulk.row2Invalid, `Duplikat kecamatan wajib ditolak, reverted, dan menampilkan #ruteRadiusWarn_pickup (${radiusDupAndBulk.warnDupMsg})`);
        assert.ok(radiusDupAndBulk.warnClearedOnUnique, 'Alert #ruteRadiusWarn_pickup hilang setelah kecamatan unik dipilih');
        assert.ok(radiusDupAndBulk.bulkToolbarRemoved, 'Toolbar "Biaya seragam (Rp)" tidak dirender di panel Radius');
        assert.ok(radiusDupAndBulk.allIconOnly, 'Seluruh tombol hapus grid Tarif & Radius hanya memakai icon');
        pass('Anti-duplikat kecamatan (TomSelect remote + revert + alert), penghapusan biaya seragam, & tombol icon-only terverifikasi');

        // 7b. Uji konsistensi teks Kosongkan, validasi baris radius setengah terisi, & preset Jadwal
        const radiusIncompleteAndPresets = await cdp.evalJs(`(async () => {
            const pPanel = document.querySelector('.rute-radius-panel[data-area="pickup"]');
            const dPanel = document.querySelector('.rute-radius-panel[data-area="delivery"]');
            pPanel.querySelector('.btn-clear-radius-area').click();
            dPanel.querySelector('.btn-clear-radius-area').click();
            await new Promise(r => setTimeout(r, 80));

            const pEmptyTxt = (pPanel.querySelector('tr.rute-radius-empty')?.textContent || '').trim();
            const dEmptyTxt = (dPanel.querySelector('tr.rute-radius-empty')?.textContent || '').trim();

            // Tambah 1 baris kosong, isi Biaya Tambahan 45.000 tanpa memilih Kecamatan -> submit wajib diblokir
            pPanel.querySelector('.btn-add-radius-row').click();
            await new Promise(r => setTimeout(r, 80));
            const row = pPanel.querySelector('tr.rute-radius-row');
            const dispExtra = row.querySelector('.radius-extra-disp');
            const sel = row.querySelector('.radius-dist-select');
            dispExtra.value = '45000';
            dispExtra.dispatchEvent(new Event('input', { bubbles: true }));
            dispExtra.dispatchEvent(new Event('blur', { bubbles: true }));

            const form = document.getElementById('formRute');
            const blocked = !form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            const warnEl = document.getElementById('ruteRadiusWarn_pickup');
            const warnVis = Boolean(warnEl && !warnEl.classList.contains('d-none'));
            const warnTxt = warnEl ? warnEl.textContent.trim() : '';
            const selInvalid = sel.classList.contains('is-invalid') && sel.tomselect.wrapper.classList.contains('is-invalid');

            // Kembalikan biaya ke 0 -> alert & invalid border langsung bersih
            dispExtra.value = '0';
            dispExtra.dispatchEvent(new Event('input', { bubbles: true }));
            dispExtra.dispatchEvent(new Event('blur', { bubbles: true }));
            await new Promise(r => setTimeout(r, 60));
            const clearedOnReset = Boolean(warnEl && warnEl.classList.contains('d-none')) && !sel.classList.contains('is-invalid');

            // Uji preset Tab Jadwal
            document.getElementById('tab-rute-schedules-btn').click();
            await new Promise(r => setTimeout(r, 150));
            const badge = document.getElementById('ruteScheduleCountBadge');
            document.querySelector('.btn-rute-preset[data-preset="15:00,16:00,17:00"]').click();
            const soreBadge = badge.textContent.trim();
            document.querySelector('.btn-rute-preset[data-preset="19:00,20:00,21:00"]').click();
            const malamBadge = badge.textContent.trim();
            document.querySelector('.btn-rute-preset[data-preset="*"]').click();
            const allBadge = badge.textContent.trim();
            document.querySelector('#tab-rute-schedules .btn-rute-preset[data-preset=""]').click();
            const zeroBadge = badge.textContent.trim();

            return {
                pEmptyTxt, dEmptyTxt,
                blocked, warnVis, warnTxt, selInvalid, clearedOnReset,
                soreBadge, malamBadge, allBadge, zeroBadge,
            };
        })()`);
        assert.ok(radiusIncompleteAndPresets.pEmptyTxt.includes('Isi Kota Asal'), `Pesan kosong radius pickup harus konsisten ("Isi Kota Asal"): ${radiusIncompleteAndPresets.pEmptyTxt}`);
        assert.ok(radiusIncompleteAndPresets.dEmptyTxt.includes('Isi Kota Tujuan'), `Pesan kosong radius delivery harus konsisten ("Isi Kota Tujuan"): ${radiusIncompleteAndPresets.dEmptyTxt}`);
        assert.ok(
            radiusIncompleteAndPresets.blocked && radiusIncompleteAndPresets.warnVis && radiusIncompleteAndPresets.selInvalid && radiusIncompleteAndPresets.clearedOnReset,
            `Validasi baris radius setengah terisi (Biaya > 0 tanpa Kecamatan) wajib blokir submit + bersihkan saat direset | actual=${JSON.stringify(radiusIncompleteAndPresets)}`
        );
        assert.equal(radiusIncompleteAndPresets.soreBadge, '3 Jam Dipilih', 'Preset Sore 15-17 memilih 3 jam');
        assert.equal(radiusIncompleteAndPresets.malamBadge, '3 Jam Dipilih', 'Preset Malam 19-21 memilih 3 jam');
        assert.equal(radiusIncompleteAndPresets.allBadge, '24 Jam Dipilih', 'Preset Semua Jam memilih seluruh 24 jam ENUM');
        assert.equal(radiusIncompleteAndPresets.zeroBadge, '0 Jam Dipilih', 'Preset Kosongkan mereset jadwal menjadi 0');
        pass('Konsistensi pesan Kosongkan, validasi radius setengah terisi, & preset Jadwal (Sore/Malam/Semua) terverifikasi');

        // 8. Verifikasi Grid Rute Index: filter kota/kategori, Daftar Harga, Status icon-only & toggle cepat AJAX
        await cdp.navigate(`${BASE_URL}/pages/travel/rutes/index.php`);
        await assertNoPhpErrors(cdp, 'Rutes Index');
        const rutesIdxCheck = await cdp.evalJs(`(() => {
            const ths = Array.from(document.querySelectorAll('table thead th'));
            const headers = ths.map((th) => th.textContent.trim());
            const statusIdx = headers.indexOf('Status');
            const rows = Array.from(document.querySelectorAll('table tbody tr'));
            const statusCells = statusIdx >= 0
                ? rows.map((tr) => tr.children[statusIdx]).filter(Boolean)
                : [];
            const allStatusIconOnly = statusCells.length > 0 && statusCells.every((td) => {
                const hasIcon = Boolean(td.querySelector('i.bi-check-circle-fill, i.bi-x-circle-fill'));
                return hasIcon && td.innerText.trim() === '';
            });
            const hasPickupFilterTs = Boolean(document.getElementById('filterRutePickupCity')?.tomselect);
            const hasDelivFilterTs = Boolean(document.getElementById('filterRuteDeliveryCity')?.tomselect);
            const hasCatFilter = Boolean(document.querySelector('select[name="category_vehicle_id"]'));
            const toggleBtn = document.querySelector('.rute-toggle-status');
            const codeEl = document.querySelector('.rute-code-cell');
            const codeFontSize = codeEl ? parseFloat(getComputedStyle(codeEl).fontSize) : null;
            const trFontSize = codeEl && codeEl.parentElement ? parseFloat(getComputedStyle(codeEl.parentElement).fontSize) : null;
            const provEl = document.querySelector('.rute-province-name');
            const cityEl = document.querySelector('.rute-city-name');
            const provFontSize = provEl ? parseFloat(getComputedStyle(provEl).fontSize) : null;
            const cityFontSize = cityEl ? parseFloat(getComputedStyle(cityEl).fontSize) : null;
            const provAboveCity = provEl && cityEl
                ? provEl.getBoundingClientRect().top < cityEl.getBoundingClientRect().top
                : null;
            const slotEl = document.querySelector('.rute-slot-badge');
            const slotFontSize = slotEl ? parseFloat(getComputedStyle(slotEl).fontSize) : null;
            const maxVisibleEnumSlots = rows.reduce((max, tr) => {
                const n = tr.querySelectorAll('.rute-slot-badge:not(.rute-slot-more)').length;
                return n > max ? n : max;
            }, 0);
            const noEl = document.querySelector('table > tbody > tr > td:first-child.text-center.text-muted.small:not([colspan])');
            const noFontSize = noEl ? parseFloat(getComputedStyle(noEl).fontSize) : null;
            const filterItems = Array.from(document.querySelectorAll('#rutesFilterForm > div'));
            const filterTops = filterItems.map((el) => Math.round(el.getBoundingClientRect().top));
            const filterAlignedOneRow = filterTops.length >= 6 && (Math.max(...filterTops) - Math.min(...filterTops)) <= 4;
            const hargaIdx = headers.indexOf('Daftar Harga');
            const radiusIdx = headers.indexOf('Radius Jemput / Antar');
            const hargaTexts = hargaIdx >= 0 ? rows.map((tr) => tr.children[hargaIdx]?.innerText || '') : [];
            const radiusTexts = radiusIdx >= 0 ? rows.map((tr) => {
                const td = tr.children[radiusIdx];
                if (!td) return '';
                const tips = Array.from(td.querySelectorAll('[title]')).map((el) => el.getAttribute('title') || '').join(' ');
                return (td.innerText || '') + ' ' + tips;
            }) : [];
            return {
                headers,
                statusCount: statusCells.length,
                allStatusIconOnly,
                hasPickupFilterTs,
                hasDelivFilterTs,
                hasCatFilter,
                hasToggleBtn: Boolean(toggleBtn),
                initialActive: toggleBtn ? toggleBtn.getAttribute('data-active') : null,
                codeFontSize,
                trFontSize,
                provFontSize,
                cityFontSize,
                provAboveCity,
                slotFontSize,
                maxVisibleEnumSlots,
                noFontSize,
                filterAlignedOneRow,
                filterTops,
                hargaTexts,
                radiusTexts,
            };
        })()`);
        assert.ok(rutesIdxCheck.headers.includes('#') && !rutesIdxCheck.headers.includes('No'), `Header kolom nomor wajib "#" dan bukan "No" (${rutesIdxCheck.headers.join(', ')})`);
        assert.ok(rutesIdxCheck.headers.includes('Daftar Harga'), `Header "Daftar Harga" harus muncul di rutes/index.php (${rutesIdxCheck.headers.join(', ')})`);
        assert.ok(!rutesIdxCheck.headers.includes('Nama') && !rutesIdxCheck.headers.includes('Nama Rute'), `Kolom Nama rute harus tersembunyi di grid rutes/index.php (${rutesIdxCheck.headers.join(', ')})`);
        assert.ok(!rutesIdxCheck.headers.includes('Tarif per Kategori Kendaraan'), 'Header lama "Tarif per Kategori Kendaraan" tidak boleh ada lagi');
        assert.ok(!rutesIdxCheck.hargaTexts.some((t) => /\bkursi\b/i.test(t)), 'Grid Daftar Harga wajib mengganti kata "kursi" menjadi "seat"');
        assert.ok(!rutesIdxCheck.hargaTexts.some((t) => /\bRp\.?/i.test(t)), 'Grid Daftar Harga tidak boleh menampilkan prefix Rp / Rp. pada nominal harga');
        assert.ok(!rutesIdxCheck.radiusTexts.some((t) => /\bgratis\b|\+Rp\b/i.test(t)), 'Grid Radius Jemput / Antar tidak boleh menampilkan teks "gratis" atau biaya tambahan (+Rp)');
        assert.ok(!rutesIdxCheck.radiusTexts.some((t) => /\bkec\./i.test(t)), 'Grid Radius Jemput / Antar tidak boleh menampilkan jumlah total kecamatan (X kec.)');
        assert.ok(rutesIdxCheck.statusCount === 0 || rutesIdxCheck.allStatusIconOnly, 'Kolom Status di rutes/index.php hanya menampilkan icon ceklis/silang tanpa teks');
        assert.ok(rutesIdxCheck.hasPickupFilterTs && rutesIdxCheck.hasDelivFilterTs && rutesIdxCheck.hasCatFilter, 'Filter Kota Asal/Tujuan (TomSelect) & Kategori Kendaraan tampil di rutes/index.php');
        if (rutesIdxCheck.codeFontSize !== null && rutesIdxCheck.trFontSize !== null) {
            const codeDiff = rutesIdxCheck.trFontSize - rutesIdxCheck.codeFontSize;
            assert.ok(codeDiff >= 0.9 && codeDiff <= 1.1, `Value kolom Kode wajib lebih kecil 1px dari sel default (tr=${rutesIdxCheck.trFontSize}px, code=${rutesIdxCheck.codeFontSize}px)`);
        }
        if (rutesIdxCheck.provFontSize !== null && rutesIdxCheck.cityFontSize !== null) {
            assert.strictEqual(rutesIdxCheck.provAboveCity, true, 'Kolom Asal/Tujuan wajib menempatkan Provinsi di atas Kota');
            assert.ok(rutesIdxCheck.provFontSize > rutesIdxCheck.cityFontSize, `Provinsi wajib lebih besar dari Kota di bawahnya (prov=${rutesIdxCheck.provFontSize}px, city=${rutesIdxCheck.cityFontSize}px)`);
        }
        if (rutesIdxCheck.slotFontSize !== null) {
            assert.ok(rutesIdxCheck.slotFontSize <= 11.2, `Badge Jadwal Keberangkatan wajib lebih kecil dari default 12px (actual=${rutesIdxCheck.slotFontSize}px)`);
        }
        assert.ok(rutesIdxCheck.maxVisibleEnumSlots <= 7, `Grid Jadwal Keberangkatan maksimal menampilkan 7 slot enum (actual=${rutesIdxCheck.maxVisibleEnumSlots})`);
        assert.ok(rutesIdxCheck.filterAlignedOneRow, `Baris filter rutes/index.php wajib sejajar 1 baris di desktop 1440px (tops=${JSON.stringify(rutesIdxCheck.filterTops)})`);
        if (rutesIdxCheck.noFontSize !== null) {
            assert.ok(rutesIdxCheck.noFontSize >= 10.5 && rutesIdxCheck.noFontSize <= 11.2, `Value kolom No pada grid wajib 11px (actual=${rutesIdxCheck.noFontSize}px)`);
        }

        if (rutesIdxCheck.hasToggleBtn) {
            const expectedFlipped = rutesIdxCheck.initialActive === '1' ? '0' : '1';
            await cdp.clickSelector('.rute-toggle-status');
            const afterToggle = await cdp.evalJs(`(async () => {
                const btn = document.querySelector('.rute-toggle-status');
                const deadline = Date.now() + 5000;
                while (btn.getAttribute('data-active') !== ${JSON.stringify(expectedFlipped)} && Date.now() < deadline) {
                    await new Promise(r => setTimeout(r, 60));
                }
                return {
                    active: btn.getAttribute('data-active'),
                    ariaLabel: btn.getAttribute('aria-label'),
                    hasOkIcon: Boolean(btn.querySelector('i.bi-check-circle-fill')),
                    hasNoIcon: Boolean(btn.querySelector('i.bi-x-circle-fill')),
                    feedbackVis: !document.getElementById('ruteIndexStatusFeedback').classList.contains('d-none'),
                };
            })()`);
            assert.equal(afterToggle.active, expectedFlipped, 'Klik icon Status mengubah data-active in-place via AJAX');
            assert.ok(expectedFlipped === '0' ? afterToggle.hasNoIcon : afterToggle.hasOkIcon, 'Icon status langsung berganti sesuai status baru');

            // Kembalikan status rute ke semula agar data awal tidak berubah
            await cdp.clickSelector('.rute-toggle-status');
            const afterRestore = await cdp.evalJs(`(async () => {
                const btn = document.querySelector('.rute-toggle-status');
                const deadline = Date.now() + 5000;
                while (btn.getAttribute('data-active') !== ${JSON.stringify(rutesIdxCheck.initialActive)} && Date.now() < deadline) {
                    await new Promise(r => setTimeout(r, 60));
                }
                return btn.getAttribute('data-active');
            })()`);
            assert.equal(afterRestore, rutesIdxCheck.initialActive, 'Status rute berhasil dikembalikan ke kondisi semula');
        }
        pass('rutes/index.php menampilkan filter Kota/Kategori, Daftar Harga, Status icon-only, & toggle status AJAX in-place');

        assert.deepEqual(cdp.jsErrors, [], `Ditemukan console/runtime JS errors: ${cdp.jsErrors.join(' | ')}`);
        assert.deepEqual(cdp.httpErrors, [], `Ditemukan HTTP >= 400 error pada aset/AJAX lokal: ${cdp.httpErrors.join(' | ')}`);
        pass('Nol uncaught JS exception, nol console.error, & nol HTTP 404/500 AJAX di seluruh alur browser');

        console.log(`\n=== BROWSER UI E2E SUMMARY ===\nPASSED: ${passCount}\nFAILED: 0\n`);
    } finally {
        if (cdp) cdp.close();
        if (chromeProc && chromeProc.pid) {
            try { chromeProc.kill(); } catch { }
            try { execFileSync('taskkill', ['/PID', String(chromeProc.pid), '/T', '/F'], { stdio: 'ignore' }); } catch { }
        }
        await delay(250);
        cleanupTempDir(userDataDir);
    }
}

main().catch((err) => {
    console.error(`[FAIL] ${err.stack || err.message}`);
    process.exit(1);
});
