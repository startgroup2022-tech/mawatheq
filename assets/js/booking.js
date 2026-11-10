/*
 * Booking wizard behaviour — Execution 66 phase B.
 *
 * External file (no inline handlers) so the CSP stays 'self'-friendly. It talks to the
 * availability endpoint that already exists and draws a REAL calendar from the server's own
 * per-day availability map — it never guesses which days are free.
 *
 * Progressive enhancement: with JS off, the plain date/time inputs still submit and the server
 * validates everything (booking_slot_is_bookable). This layer only improves the choosing.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-booking-wizard]');
  if (!root) return;

  // Mark that the enhancement layer is active, so CSS can hide the no-JS fallback inputs.
  document.documentElement.classList.add('bk-js');

  var cfg = {
    lawyerId: parseInt(root.getAttribute('data-lawyer-id') || '0', 10),
    endpoint: root.getAttribute('data-endpoint') || '/api/available_slots.php',
    hasHours: root.getAttribute('data-has-hours') === '1',
    dir: root.getAttribute('data-dir') || 'rtl'
  };
  if (!cfg.lawyerId) return;

  var cal = root.querySelector('[data-bk-calendar]');
  var slotsBox = root.querySelector('[data-bk-slots]');
  var dateInput = root.querySelector('[data-bk-date]');
  var timeInput = root.querySelector('[data-bk-time]');
  var monthLabel = root.querySelector('[data-bk-month]');
  var prevBtn = root.querySelector('[data-bk-prev]');
  var nextBtn = root.querySelector('[data-bk-next]');
  var summaryDate = root.querySelector('[data-bk-summary-date]');

  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var view = new Date(today.getFullYear(), today.getMonth(), 1);
  var selectedDate = null;
  var monthCache = {};

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function monthKey(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }

  var AR_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
  var EN_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var AR_DOW = ['أحد', 'إثن', 'ثلا', 'أرب', 'خمي', 'جمع', 'سبت'];
  var EN_DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  function monthName(d) {
    return (cfg.dir === 'rtl' ? AR_MONTHS[d.getMonth()] : EN_MONTHS[d.getMonth()]) + ' ' + d.getFullYear();
  }
  function dowNames() { return cfg.dir === 'rtl' ? AR_DOW : EN_DOW; }

  function fetchMonth(key) {
    if (monthCache[key]) return Promise.resolve(monthCache[key]);
    var url = cfg.endpoint + '?lawyer_id=' + cfg.lawyerId + '&month=' + encodeURIComponent(key);
    return fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { monthCache[key] = data || {}; return monthCache[key]; })
      .catch(function () { monthCache[key] = { days: {} }; return monthCache[key]; });
  }

  function renderCalendar() {
    if (!cal) return;
    if (monthLabel) monthLabel.textContent = monthName(view);
    // Do not let the user page back before the current month.
    var thisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    if (prevBtn) prevBtn.disabled = view <= thisMonth;

    fetchMonth(monthKey(view)).then(function (data) {
      var days = (data && data.days) || {};
      var first = new Date(view.getFullYear(), view.getMonth(), 1);
      var startDow = first.getDay(); // 0=Sunday, matching PHP date('w')
      var daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();

      var html = '';
      dowNames().forEach(function (n) { html += '<div class="bk-cal__dow">' + n + '</div>'; });
      for (var i = 0; i < startDow; i++) html += '<button type="button" class="bk-cal__day is-empty" tabindex="-1" disabled></button>';
      for (var d = 1; d <= daysInMonth; d++) {
        var date = new Date(view.getFullYear(), view.getMonth(), d);
        var key = iso(date);
        var free = parseInt(days[key] || 0, 10);
        var isPast = date < today;
        var selectable = !isPast && (cfg.hasHours ? free > 0 : true);
        var cls = 'bk-cal__day';
        if (selectable) cls += ' is-free';
        if (selectedDate === key) cls += ' is-selected';
        html += '<button type="button" class="' + cls + '" data-date="' + key + '"' +
          (selectable ? '' : ' disabled') +
          ' aria-label="' + key + (free ? ' — ' + free : '') + '">' + d + '</button>';
      }
      var grid = cal.querySelector('[data-bk-grid]');
      if (grid) grid.innerHTML = html;
    });
  }

  function renderSlots(date) {
    if (!slotsBox) return;
    if (!date) {
      slotsBox.innerHTML = '<p class="field-hint">' + (cfg.dir === 'rtl' ? 'اختر يوماً من التقويم أولاً.' : 'Pick a day from the calendar first.') + '</p>';
      return;
    }
    slotsBox.innerHTML = '<p class="field-hint">' + (cfg.dir === 'rtl' ? 'جارٍ تحميل الأوقات…' : 'Loading times…') + '</p>';
    var url = cfg.endpoint + '?lawyer_id=' + cfg.lawyerId + '&date=' + encodeURIComponent(date);
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var slots = (data && data.slots) || [];
        if (!slots.length) {
          slotsBox.innerHTML = '<p class="field-hint">' + (cfg.dir === 'rtl'
            ? 'لا توجد أوقات متاحة في هذا اليوم، جرّب يوماً آخر.' : 'No times left this day — try another.') + '</p>';
          return;
        }
        var html = '';
        slots.forEach(function (s) {
          html += '<button type="button" class="slot-btn" data-slot="' + s + '">' + s + '</button>';
        });
        slotsBox.innerHTML = html;
        slotsBox.querySelectorAll('.slot-btn').forEach(function (b) {
          b.addEventListener('click', function () {
            slotsBox.querySelectorAll('.slot-btn').forEach(function (x) { x.classList.remove('selected'); });
            b.classList.add('selected');
            if (timeInput) { timeInput.value = b.getAttribute('data-slot'); timeInput.dispatchEvent(new Event('change', { bubbles: true })); }
          });
        });
      })
      .catch(function () {
        slotsBox.innerHTML = '<p class="field-hint">' + (cfg.dir === 'rtl'
          ? 'تعذّر تحميل الأوقات، حاول مجدداً.' : 'Could not load times, try again.') + '</p>';
      });
  }

  function selectDate(key) {
    selectedDate = key;
    if (dateInput) dateInput.value = key;
    if (timeInput) timeInput.value = '';
    if (summaryDate) summaryDate.textContent = key || '—';
    renderCalendar();
    renderSlots(key);
  }

  if (cal) {
    cal.addEventListener('click', function (e) {
      var btn = e.target.closest('.bk-cal__day');
      if (!btn || btn.disabled || !btn.getAttribute('data-date')) return;
      selectDate(btn.getAttribute('data-date'));
    });
  }
  // The native input (shown only when JS is off) and the calendar drive the same state.
  if (dateInput) dateInput.addEventListener('change', function () { if (dateInput.value) selectDate(dateInput.value); });
  if (prevBtn) prevBtn.addEventListener('click', function () { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); renderCalendar(); });
  if (nextBtn) nextBtn.addEventListener('click', function () { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); renderCalendar(); });

  // Type picker — reflect the choice on the hidden radio + the visual card.
  root.querySelectorAll('.bk-type input[type=radio]').forEach(function (r) {
    r.addEventListener('change', function () {
      root.querySelectorAll('.bk-type').forEach(function (c) { c.classList.remove('is-on'); });
      var wrap = r.closest('.bk-type'); if (wrap) wrap.classList.add('is-on');
    });
    if (r.checked) { var w = r.closest('.bk-type'); if (w) w.classList.add('is-on'); }
  });

  // Guard against submitting without a chosen time.
  var form = root.querySelector('form');
  if (form) {
    form.addEventListener('submit', function (e) {
      if (timeInput && !timeInput.value) {
        e.preventDefault();
        var msg = cfg.dir === 'rtl' ? 'يرجى اختيار وقت من الأوقات المتاحة.' : 'Please choose an available time.';
        var box = root.querySelector('[data-bk-formerror]');
        if (box) { box.textContent = msg; box.style.display = 'block'; }
        if (slotsBox) slotsBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      // Prevent a double submission while the server works.
      var btn = form.querySelector('[type=submit]');
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    });
  }

  // The mobile action bar's button submits the same form.
  var mobileSubmit = root.querySelector('[data-bk-mobile-submit]');
  if (mobileSubmit && form) {
    mobileSubmit.addEventListener('click', function () {
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
  }

  if (cfg.hasHours) {
    renderCalendar();
    if (dateInput && dateInput.value) selectDate(dateInput.value);
  }
})();
