(function (window, document) {
  'use strict';

  var CHAPTERS = [
    {
      no: '01',
      title: 'Pemesanan Travel & E-Tiket terintegrasi.',
      desc: 'Pemilihan rute antar-kota, banyak penumpang sekaligus, dan penerbitan E-Tiket otomatis.'
    },
    {
      no: '02',
      title: 'Sewa & Charter kendaraan multi-hari aman.',
      desc: 'Pengecekan ketersediaan armada anti double-booking dengan tarif rute terstandar.'
    },
    {
      no: '03',
      title: 'Kalender Dispatch & Surat Jalan.',
      desc: 'Penjadwalan sopir, drag-and-drop jadwal, serta cetak surat jalan dalam satu layar.'
    },
    {
      no: '04',
      title: 'Pembayaran QRIS, Kas, & kontrol piutang.',
      desc: 'Rekonsiliasi pembayaran booking otomatis masuk buku kas dan pelacakan tagihan belum lunas.'
    },
    {
      no: '05',
      title: 'Analitik Laba Kategori & CRM Pelanggan.',
      desc: 'Pantau margin per kategori layanan, profil pelanggan, serta pesan ulang 1-klik.'
    }
  ];

  var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 1. Theme Toggle Sync
  var themeBtn = document.getElementById('loginThemeToggle');
  if (themeBtn) {
    function syncTheme() {
      var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      var icon = themeBtn.querySelector('i');
      if (icon) {
        icon.classList.toggle('bi-moon-stars', !dark);
        icon.classList.toggle('bi-sun', dark);
      }
      var label = dark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap';
      themeBtn.setAttribute('title', label);
      themeBtn.setAttribute('aria-label', label);
    }
    syncTheme();
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { window.localStorage.setItem('vst_theme', next); } catch (e) {}
      syncTheme();
    });
  }

  // 2. Password Visibility Toggle
  var pwdBtn = document.getElementById('toggleLoginPwd');
  var pwdInp = document.getElementById('password');
  if (pwdBtn && pwdInp) {
    pwdBtn.addEventListener('click', function () {
      var show = pwdInp.type === 'password';
      pwdInp.type = show ? 'text' : 'password';
      pwdBtn.classList.toggle('is-shown', show);
      pwdBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  }

  // 3. Smart Enter Navigation (Username -> Password) & Submit Curtain Transition
  var userInp = document.getElementById('username');
  var form = document.getElementById('loginForm');
  var submitBtn = document.getElementById('loginBtn');
  var transitEl = document.getElementById('vstLoginTransit');

  if (userInp && pwdInp) {
    userInp.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !pwdInp.value) {
        ev.preventDefault();
        pwdInp.focus();
      }
    });
  }

  if (form && submitBtn) {
    var submitting = false;
    form.addEventListener('submit', function (ev) {
      if (submitting) {
        ev.preventDefault();
        return;
      }
      if (userInp && pwdInp && (!userInp.value.trim() || !pwdInp.value)) {
        return;
      }
      submitting = true;
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-busy', 'true');
      submitBtn.innerHTML = 'Memproses\u2026';
      try { window.sessionStorage.setItem('vst_login_split', '1'); } catch (e) {}

      if (transitEl && !prefersReduced) {
        ev.preventDefault();
        transitEl.classList.add('is-closing');
        window.setTimeout(function () {
          form.submit();
        }, 260);
      }
    });
  }

  // 4. Interactive 5-Chapter Stepper + Floating Tile Spotlight
  var storyEl = document.getElementById('lgxStory');
  var capBox = document.getElementById('lgxCaption');
  var capNo = document.getElementById('lgxCapNo');
  var capTitle = document.getElementById('lgxCapTitle');
  var capDesc = document.getElementById('lgxCapDesc');
  var chapBtns = Array.prototype.slice.call(document.querySelectorAll('.lgx-chap'));
  var tiles = Array.prototype.slice.call(document.querySelectorAll('.lgx-tile[data-k]'));
  var activeIdx = 0;
  var timerId = null;

  function setChapter(idx) {
    if (!CHAPTERS.length) return;
    activeIdx = ((idx % CHAPTERS.length) + CHAPTERS.length) % CHAPTERS.length;
    var item = CHAPTERS[activeIdx];

    if (capBox) {
      capBox.classList.add('is-swapping');
      window.setTimeout(function () {
        if (capNo) capNo.textContent = item.no;
        if (capTitle) capTitle.textContent = item.title;
        if (capDesc) capDesc.textContent = item.desc;
        capBox.classList.remove('is-swapping');
      }, prefersReduced ? 0 : 120);
    }

    chapBtns.forEach(function (btn, i) {
      var on = i === activeIdx;
      btn.classList.toggle('is-on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    tiles.forEach(function (tile) {
      var keys = String(tile.getAttribute('data-k') || '').split(',');
      tile.classList.toggle('is-hl', keys.indexOf(String(activeIdx)) !== -1);
    });
  }

  function startCycle() {
    if (prefersReduced || !chapBtns.length) return;
    if (timerId) window.clearInterval(timerId);
    timerId = window.setInterval(function () {
      if (storyEl && storyEl.classList.contains('is-paused')) return;
      setChapter(activeIdx + 1);
    }, 6500);
  }

  chapBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var step = parseInt(btn.getAttribute('data-step') || '0', 10);
      setChapter(isNaN(step) ? 0 : step);
      startCycle();
    });
  });

  if (storyEl && !prefersReduced) {
    storyEl.addEventListener('mouseenter', function () { storyEl.classList.add('is-paused'); });
    storyEl.addEventListener('mouseleave', function () { storyEl.classList.remove('is-paused'); });
    storyEl.addEventListener('focusin', function () { storyEl.classList.add('is-paused'); });
    storyEl.addEventListener('focusout', function () { storyEl.classList.remove('is-paused'); });
  }

  setChapter(0);
  startCycle();

  // 5. Smooth 3D Cursor Parallax on Left Hero Stage
  var rootEl = document.getElementById('loginPage');
  if (rootEl && storyEl && !prefersReduced && window.matchMedia('(pointer: fine)').matches) {
    var tx = 0;
    var ty = 0;
    var cx = 0;
    var cy = 0;
    var rafPending = false;

    function tickParallax() {
      cx += (tx - cx) * 0.14;
      cy += (ty - cy) * 0.14;
      rootEl.style.setProperty('--px', cx.toFixed(4));
      rootEl.style.setProperty('--py', cy.toFixed(4));
      if (Math.abs(tx - cx) > 0.002 || Math.abs(ty - cy) > 0.002) {
        window.requestAnimationFrame(tickParallax);
      } else {
        rafPending = false;
      }
    }

    storyEl.addEventListener('mousemove', function (ev) {
      var rect = storyEl.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      tx = ((ev.clientX - rect.left) / rect.width - 0.5) * 2;
      ty = ((ev.clientY - rect.top) / rect.height - 0.5) * 2;
      if (!rafPending) {
        rafPending = true;
        window.requestAnimationFrame(tickParallax);
      }
    });

    storyEl.addEventListener('mouseleave', function () {
      tx = 0;
      ty = 0;
      if (!rafPending) {
        rafPending = true;
        window.requestAnimationFrame(tickParallax);
      }
    });
  }
})(window, document);
