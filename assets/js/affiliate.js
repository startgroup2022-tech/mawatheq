/* ===========================================================================
   Affiliate portal behaviour (Execution 31)
   ===========================================================================
   Deliberately small and dependency-free. The portal's server-rendered HTML is
   the source of truth for every figure and every state; this file only adds
   conveniences that can be absent without breaking anything:

     • copy-to-clipboard for referral links and copy blocks
     • the mobile sidebar toggle

   If JavaScript is disabled or fails, every page still works — the links are
   readable in a text input and selectable by hand, and the navigation is a plain
   list of anchors.
   =========================================================================== */
(function () {
  'use strict';

  /**
   * Copy the value of the element named by data-copy-target.
   *
   * The Clipboard API needs a secure context, which a cPanel site served over
   * HTTPS has but a local test over plain HTTP does not. The textarea fallback
   * covers that case so the button is not silently dead in development.
   */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.top = '-1000px';
      document.body.appendChild(helper);
      helper.select();
      try {
        var ok = document.execCommand('copy');
        document.body.removeChild(helper);
        ok ? resolve() : reject(new Error('copy failed'));
      } catch (err) {
        document.body.removeChild(helper);
        reject(err);
      }
    });
  }

  function flash(button, message) {
    var original = button.getAttribute('data-copy-original');
    if (original === null) {
      button.setAttribute('data-copy-original', button.textContent);
      original = button.textContent;
    }
    button.textContent = message;
    button.disabled = true;
    window.setTimeout(function () {
      button.textContent = original;
      button.disabled = false;
    }, 1600);
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-target]');
    if (!button) return;
    event.preventDefault();

    var target = document.querySelector(button.getAttribute('data-copy-target'));
    if (!target) return;
    var text = typeof target.value === 'string' ? target.value : target.textContent;
    if (!text) return;

    var done = button.getAttribute('data-copy-done') || 'Copied';
    copyText(text.trim()).then(
      function () { flash(button, done); },
      function () {
        // Leave the value on screen so it can still be selected by hand.
        if (typeof target.select === 'function') target.select();
      }
    );
  });

  // Mobile sidebar toggle, mirroring the other panels' markup contract.
  var toggle = document.querySelector('[data-app-nav-toggle]');
  if (toggle) {
    toggle.addEventListener('click', function () {
      document.body.classList.toggle('app-nav-open');
    });
  }
  var scrim = document.querySelector('.app-scrim');
  if (scrim) {
    scrim.addEventListener('click', function () {
      document.body.classList.remove('app-nav-open');
    });
  }
})();
