/**
 * سلوك نماذج المحامي — حقل المدينة التابع للدولة، وحالات الإرسال.
 *
 * Loaded as an external file (never an inline handler) so the pages stay CSP-safe
 * under `script-src 'self'`.
 *
 * Two jobs:
 *
 *   1. City depends on country. The city `<select>` is rebuilt from the country
 *      data block the server rendered (`#mawatheq-city-data`), so changing the
 *      country cannot leave a city from the previous country selected. On load the
 *      server-selected city is restored, which is what makes the edit form show the
 *      stored value.
 *
 *   2. A form that is submitting disables its submit button and swaps in a progress
 *      label, so a slow request cannot be double-submitted. The button is restored
 *      on pageshow/bfcache so a back-navigation does not leave a dead button.
 */
(function () {
  'use strict';

  function cityData() {
    var el = document.getElementById('mawatheq-city-data');
    if (!el) return {};
    try {
      return JSON.parse(el.textContent || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function fillCity(select, cities, selected) {
    var placeholder = select.getAttribute('data-placeholder') || '';
    select.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);
    (cities || []).forEach(function (city) {
      var opt = document.createElement('option');
      opt.value = city;
      opt.textContent = city;
      if (city === selected) opt.selected = true;
      select.appendChild(opt);
    });
  }

  function initDependentCity(scope, data) {
    var country = scope.querySelector('[data-country-select]');
    var city = scope.querySelector('[data-city-select]');
    if (!country || !city) return;

    if (!city.getAttribute('data-placeholder')) {
      var existing = city.querySelector('option[value=""]');
      city.setAttribute('data-placeholder', existing ? existing.textContent : '');
    }

    var restore = city.getAttribute('data-selected') || '';
    fillCity(city, data[country.value] || [], restore);

    country.addEventListener('change', function () {
      // Changing the country deliberately drops the old city: keeping it would
      // persist a city that does not belong to the new country.
      fillCity(city, data[country.value] || [], '');
    });
  }

  function initSubmitState(form) {
    if (form.getAttribute('data-submit-state') === 'off') return;
    var submit = form.querySelector('[type="submit"]');
    if (!submit) return;
    var original = submit.innerHTML;
    var busy = submit.getAttribute('data-busy-label') || submit.textContent;

    form.addEventListener('submit', function () {
      if (form.checkValidity && !form.checkValidity()) return;
      // Defer so the browser includes the clicked button's name/value in the POST
      // before it is disabled.
      window.setTimeout(function () {
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>' + busy;
      }, 0);
    });

    window.addEventListener('pageshow', function () {
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
      submit.innerHTML = original;
    });
  }

  function boot() {
    var data = cityData();
    document.querySelectorAll('form[data-lawyer-form]').forEach(function (form) {
      initDependentCity(form, data);
      initSubmitState(form);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
