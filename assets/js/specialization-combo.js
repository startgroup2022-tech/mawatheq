/**
 * حقل التخصص القابل للبحث — بحث بالكتابة فوق قائمة منسدلة أصلية.
 *
 * The `<select>` stays the real control, so native `required` validation and the
 * no-JS path are unchanged. This script only:
 *
 *   - hides options that do not match the typed text (it never rebuilds the list, so
 *     the selected value and the server-rendered order survive);
 *   - keeps the currently selected option visible even when it does not match, so
 *     typing a filter never silently clears a valid choice;
 *   - shows an explicit "no match" line instead of an empty dropdown;
 *   - expands the select to show the filtered options while typing (a `size`
 *     attribute), then restores it on blur/selection.
 *
 * CSP-safe: an external file, no inline handler.
 */
(function () {
  'use strict';

  function normalize(text) {
    return (text || '')
      .toString()
      .trim()
      .toLowerCase()
      .replace(/[\u064B-\u0652\u0640]/g, '')   // Arabic diacritics + tatweel
      .replace(/[أإآٱ]/g, 'ا')
      .replace(/ى/g, 'ي')
      .replace(/ة/g, 'ه');
  }

  function filter(combo) {
    var input = combo.querySelector('[data-spec-search]');
    var select = combo.querySelector('[data-spec-select]');
    var empty = combo.querySelector('[data-spec-empty]');
    if (!input || !select) return;

    var needle = normalize(input.value);
    var selected = select.value;
    var visible = 0;

    Array.prototype.forEach.call(select.options, function (opt) {
      if (opt.value === '' && opt.disabled) { opt.hidden = false; return; }
      var match = needle === '' || normalize(opt.textContent).indexOf(needle) !== -1 || opt.value === selected;
      opt.hidden = !match;
      if (match) visible++;
    });

    if (empty) empty.hidden = !(needle !== '' && visible === 0);
    select.setAttribute('size', needle === '' ? '1' : String(Math.min(Math.max(visible, 2), 8)));
  }

  function resetSize(combo) {
    var select = combo.querySelector('[data-spec-select]');
    if (select) select.setAttribute('size', '1');
  }

  function boot() {
    document.querySelectorAll('[data-spec-combo]').forEach(function (combo) {
      var input = combo.querySelector('[data-spec-search]');
      var select = combo.querySelector('[data-spec-select]');
      var toggle = combo.querySelector('[data-spec-toggle]');
      if (!input || !select) return;

      input.addEventListener('input', function () { filter(combo); });
      input.addEventListener('focus', function () { filter(combo); });
      input.addEventListener('blur', function () {
        window.setTimeout(function () { resetSize(combo); }, 150);
      });
      select.addEventListener('change', function () {
        // Reflect the choice in the search box so the field reads as "chosen".
        var opt = select.options[select.selectedIndex];
        if (opt && opt.value !== '') input.value = opt.textContent;
        resetSize(combo);
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      });
      if (toggle) {
        toggle.addEventListener('click', function () {
          var open = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
          if (!open) { input.focus(); filter(combo); } else { resetSize(combo); }
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
