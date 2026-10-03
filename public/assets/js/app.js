/**
 * Civil Registration Office — Mogadishu
 * Main JavaScript: theme toggle, mobile sidebar, live age calculation,
 * auto-dismiss flash messages, date validation helpers, and more.
 * No dependencies — vanilla JS only.
 */
(function () {
  'use strict';

  /* ── Theme toggle ──────────────────────────────────────────── */
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  });

  /* ── Mobile sidebar ────────────────────────────────────────── */
  var sidebar  = document.getElementById('sidebar');
  var menuBtns = document.querySelectorAll('[data-menu-toggle]');

  menuBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (sidebar) { sidebar.classList.toggle('open'); }
    });
  });

  // Close sidebar when clicking outside
  document.addEventListener('click', function (e) {
    if (sidebar && sidebar.classList.contains('open')) {
      if (!sidebar.contains(e.target) && !e.target.closest('[data-menu-toggle]')) {
        sidebar.classList.remove('open');
      }
    }
  });

  /* ── Live age calculation from date inputs ─────────────────── */
  /**
   * Given a date string (YYYY-MM-DD) and optional as-of date,
   * return a human-readable age string.
   */
  function calcAge(dob, asOf) {
    if (!dob) return '';
    var birth = new Date(dob);
    var ref   = asOf ? new Date(asOf) : new Date();
    if (isNaN(birth) || birth > ref) return '';

    var years  = ref.getFullYear()  - birth.getFullYear();
    var months = ref.getMonth()     - birth.getMonth();
    var days   = ref.getDate()      - birth.getDate();

    if (days < 0)   { months--; }
    if (months < 0) { years--; months += 12; }
    var totalDays = Math.floor((ref - birth) / 86400000);

    if (years >= 2)  { return years + ' years old'; }
    if (years === 1) { return '1 year old'; }
    if (months > 0)  { return months + ' month' + (months === 1 ? '' : 's') + ' old'; }
    return totalDays + ' day' + (totalDays === 1 ? '' : 's') + ' old';
  }

  /**
   * Wire a date input to a sibling span.age-display.
   * Usage: <input data-age-target="#dob-age-display" ...>
   *        <span class="age-display" id="dob-age-display"></span>
   */
  document.querySelectorAll('[data-age-target]').forEach(function (input) {
    var targetId = input.getAttribute('data-age-target');
    var display  = targetId ? document.querySelector(targetId) : null;
    if (!display) return;

    var asOfField = input.getAttribute('data-age-asof');

    function update() {
      var asOfVal = asOfField ? (document.getElementById(asOfField) || {}).value : null;
      display.textContent = calcAge(input.value, asOfVal) || '';
    }
    input.addEventListener('change', update);
    input.addEventListener('input',  update);
    update();
  });

  /* ── Confirm delete links ──────────────────────────────────── */
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      var msg = el.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(msg)) { e.preventDefault(); }
    });
  });

  /* ── Auto-dismiss flash messages ──────────────────────────── */
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
    var ms = parseInt(el.getAttribute('data-auto-dismiss'), 10) || 5000;
    setTimeout(function () {
      el.style.transition = 'opacity .4s';
      el.style.opacity    = '0';
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
    }, ms);
  });

  /* ── Signature pad ─────────────────────────────────────────── */
  var canvas = document.getElementById('sig-canvas');
  if (canvas) {
    var ctx     = canvas.getContext('2d');
    var drawing = false;
    var hidden  = document.getElementById('sig-data');

    // Retina / HiDPI
    function resizeCanvas() {
      var rect  = canvas.getBoundingClientRect();
      var dpr   = window.devicePixelRatio || 1;
      canvas.width  = rect.width  * dpr;
      canvas.height = rect.height * dpr;
      ctx.scale(dpr, dpr);
      ctx.strokeStyle = getComputedStyle(document.documentElement)
                          .getPropertyValue('--ink') || '#1b1f2a';
      ctx.lineWidth   = 2.5;
      ctx.lineCap     = 'round';
      ctx.lineJoin    = 'round';
    }
    resizeCanvas();

    function getPos(e) {
      var rect = canvas.getBoundingClientRect();
      var src  = e.touches ? e.touches[0] : e;
      return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }

    function startDraw(e) {
      e.preventDefault();
      drawing = true;
      var pos = getPos(e);
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
    }
    function draw(e) {
      if (!drawing) return;
      e.preventDefault();
      var pos = getPos(e);
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();
    }
    function endDraw() {
      if (!drawing) return;
      drawing = false;
      if (hidden) { hidden.value = canvas.toDataURL('image/png'); }
    }

    canvas.addEventListener('mousedown',  startDraw);
    canvas.addEventListener('mousemove',  draw);
    canvas.addEventListener('mouseup',    endDraw);
    canvas.addEventListener('mouseleave', endDraw);
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove',  draw,      { passive: false });
    canvas.addEventListener('touchend',   endDraw);

    // Clear button
    var clearBtn = document.getElementById('sig-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (hidden) { hidden.value = ''; }
      });
    }
  }

  /* ── Form section validator: prevent submission if required field empty ── */
  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var empty = false;
      form.querySelectorAll('[required]').forEach(function (el) {
        if (!el.value.trim()) { el.style.borderColor = 'var(--bad)'; empty = true; }
        else { el.style.borderColor = ''; }
      });
      if (empty) {
        e.preventDefault();
        var err = form.querySelector('.form-top-error');
        if (err) {
          err.textContent = 'Please complete all required fields before submitting.';
          err.style.display = 'block';
        }
      }
    });
  });

  /* ── Notification bell unread count refresh ─────────────────── */
  // Triggered from the topbar bell icon
  var bell = document.querySelector('[data-notif-bell]');
  if (bell) {
    bell.addEventListener('click', function () {
      // Navigate to notifications page
      var href = bell.getAttribute('data-notif-href');
      if (href) window.location.href = href;
    });
  }

  /* ── Print helper for report pages ──────────────────────────── */
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });

})();
