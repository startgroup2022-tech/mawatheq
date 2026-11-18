/**
 * Public lawyer profile behaviour — the contact and availability-alert sheets.
 *
 * Loaded as an external file (never an inline handler) so the page stays CSP-safe
 * under `script-src 'self'`. Every action here only reveals a form that is a normal
 * server-rendered POST; with JavaScript disabled the page still works because the
 * forms exist in the markup and are submitted to the same handler.
 */
(function () {
  'use strict';

  function bind(openSelector, closeSelector, modalSelector) {
    var modal = document.querySelector(modalSelector);
    if (!modal) return;

    function open() {
      modal.hidden = false;
      // Move focus to the first field so a keyboard user lands inside the dialog.
      var first = modal.querySelector('input, textarea, button');
      if (first) first.focus();
    }
    function close() { modal.hidden = true; }

    document.querySelectorAll(openSelector).forEach(function (btn) {
      btn.addEventListener('click', open);
    });
    document.querySelectorAll(closeSelector).forEach(function (btn) {
      btn.addEventListener('click', close);
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) close();
    });
  }

  bind('[data-contact-toggle]', '[data-contact-close]', '[data-contact-modal]');
  bind('[data-alert-toggle]', '[data-alert-close]', '[data-alert-modal]');
})();
