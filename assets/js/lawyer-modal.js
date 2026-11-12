/**
 * نافذة إضافة/تعديل المحامي — فتح وإغلاق وتبديل الوضع.
 *
 * One modal serves both create and edit. The row's data is rendered into the row's
 * own `data-lawyer` JSON, so no per-row form is needed and no secret is ever put in
 * the markup: the JSON carries the phone number, never the password hash. The
 * password field is left empty on edit, and a blank value means "keep the current
 * one" — so the stored hash is never sent to the browser and never needs to be.
 *
 * CSP-safe: external file, delegated listeners, no inline handler.
 */
(function () {
  'use strict';

  function modal() { return document.getElementById('lawyerModal'); }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function resetError() {
    var box = document.getElementById('lawyerFormError');
    if (box) box.hidden = true;
    setText('lawyerFormErrorText', '');
  }

  function showError(message) {
    var box = document.getElementById('lawyerFormError');
    if (!box) return;
    setText('lawyerFormErrorText', message || '');
    box.hidden = !message;
  }

  function open() {
    var m = modal();
    if (!m) return;
    m.classList.add('is-open');
    document.body.classList.add('lawyers-modal-open');
    window.setTimeout(function () {
      var first = document.getElementById('lawyer_full_name');
      if (first) first.focus();
    }, 80);
  }

  function close() {
    var m = modal();
    if (!m) return;
    m.classList.remove('is-open');
    document.body.classList.remove('lawyers-modal-open');
  }

  function setCountry(code) {
    var sel = document.getElementById('lawyer_country');
    if (!sel) return;
    if (code) sel.value = code;
    // Rebuild the dependent city list for the new country, then the caller selects
    // the city (which only exists once the list is rebuilt).
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function setCity(city) {
    var sel = document.getElementById('lawyer_city');
    if (!sel) return;
    sel.value = city || '';
    // The option may not exist if the country has no such city; fall back to blank so
    // an invalid pairing cannot be submitted silently.
    if (sel.value !== (city || '')) sel.value = '';
  }

  function setSpecializations(list) {
    var chosen = Array.isArray(list) ? list : (list ? [list] : []);
    var boxes = document.querySelectorAll('#lawyerForm input[name="specializations[]"]');
    Array.prototype.forEach.call(boxes, function (box) {
      box.checked = chosen.indexOf(box.value) !== -1;
      var chip = box.closest('.spec-chip');
      if (chip) chip.classList.toggle('is-checked', box.checked);
    });
  }

  function openCreate() {
    var form = document.getElementById('lawyerForm');
    if (!form) return;
    form.reset();
    setText('lawyerModalTitle', 'إضافة محامٍ جديد');
    setText('lawyerModalSubtitle', 'أدخل البيانات الأساسية للمحامي لإنشاء حسابه.');
    setText('lawyerSubmitBtn', 'إضافة المحامي');
    document.getElementById('lawyerFormAction').value = 'create';
    document.getElementById('lawyerFormId').value = '0';
    var pw = document.getElementById('lawyer_password');
    if (pw) { pw.required = true; pw.placeholder = '8 أحرف على الأقل'; }
    resetError();
    setSpecializations([]);
    setCountry('SA');
    setCity('');
    open();
  }

  function openEdit(data) {
    if (!data) return;
    document.getElementById('lawyerFormAction').value = 'edit';
    document.getElementById('lawyerFormId').value = String(data.id || 0);
    setText('lawyerModalTitle', 'تعديل بيانات المحامي');
    setText('lawyerModalSubtitle', 'حدّث بيانات المحامي ثم احفظ التعديلات.');
    setText('lawyerSubmitBtn', 'حفظ التعديلات');

    var set = function (id, value) {
      var el = document.getElementById(id);
      if (el) el.value = value == null ? '' : String(value);
    };
    set('lawyer_full_name', data.full_name);
    set('lawyer_email', data.email);
    set('lawyer_experience', data.years_experience);
    set('lawyer_rate', data.hourly_rate);
    var status = document.getElementById('lawyer_status');
    if (status && data.status) status.value = data.status;

    var pw = document.getElementById('lawyer_password');
    if (pw) { pw.value = ''; pw.required = false; pw.placeholder = 'اتركها فارغة للإبقاء على الحالية'; }

    // Phone: the row carries the full stored number; split it so the dial code and the
    // local part land in their own controls.
    var phone = String(data.phone || '');
    var dialSel = document.querySelector('#lawyerForm select[name="phone_dial"]');
    var localInput = document.querySelector('#lawyerForm input[name="phone_local"]');
    if (dialSel && localInput) {
      var best = '+966';
      var local = phone;
      Array.prototype.forEach.call(dialSel.options, function (opt) {
        if (opt.value !== 'other' && phone.indexOf(opt.value) === 0 && opt.value.length > best.length) {
          best = opt.value;
        }
      });
      if (phone.indexOf(best) === 0) local = phone.slice(best.length);
      dialSel.value = best;
      localInput.value = local;
    }

    resetError();
    setSpecializations(data.specializations || []);
    setCountry(data.country_code);
    setCity(data.city);
    open();
  }

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-open-lawyer-modal]');
    if (opener) { event.preventDefault(); openCreate(); return; }

    if (event.target.closest('[data-close-lawyer-modal]')) { event.preventDefault(); close(); return; }

    var editBtn = event.target.closest('[data-edit-lawyer]');
    if (editBtn) {
      event.preventDefault();
      var raw = editBtn.getAttribute('data-lawyer') || '{}';
      try { openEdit(JSON.parse(raw)); } catch (e) { openEdit(null); }
      return;
    }

    // Backdrop click closes.
    var m = modal();
    if (m && event.target === m) close();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      var m = modal();
      if (m && m.classList.contains('is-open')) close();
    }
  });

  // A server-side validation failure re-renders the modal open; the form is already
  // in the right mode, so only the body scroll lock is needed.
  document.addEventListener('DOMContentLoaded', function () {
    var m = modal();
    if (m && m.classList.contains('is-open')) document.body.classList.add('lawyers-modal-open');
  });
})();
