/**
 * مواثيق — المكتبة القانونية والقضائية: طبقة السلوك (Execution 68).
 * Mawatheq — legal library behaviour layer.
 *
 * CSP-safe: no inline handlers. Two small enhancements, both degrading to a plain
 * working page when the script does not load:
 *   - the mobile filter panel toggle;
 *   - submit the form when the sort control changes.
 */
(function () {
  'use strict';

  // Mobile filter panel toggle.
  document.querySelectorAll('[data-ll-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sel = btn.getAttribute('data-ll-toggle');
      var panel = sel ? document.querySelector(sel) : null;
      if (!panel) return;
      var collapsed = panel.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
  });

  // Sort select submits its form (replaces an inline onchange handler).
  document.querySelectorAll('[data-ll-sort]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (sel.form) sel.form.submit();
    });
  });

  // Copy-link buttons on the item page.
  document.querySelectorAll('[data-ll-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-ll-copy') || window.location.href;
      var done = function () {
        var old = btn.getAttribute('data-label') || btn.textContent;
        btn.textContent = btn.getAttribute('data-copied') || 'تم النسخ';
        setTimeout(function () { btn.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
      }
    });
  });

  // Print button.
  document.querySelectorAll('[data-ll-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });
})();
