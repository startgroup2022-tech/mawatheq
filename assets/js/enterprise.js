/* =============================================================================
   MAWATHEQ — Enterprise UI behaviour (Phase 4)
   Dependency-free, CSP-safe ("script-src 'self'"): no inline handlers are
   required and no third-party library is loaded.

   Everything degrades gracefully: if this file fails to load, every page still
   works because all navigation and forms are plain server-rendered HTML.
   ============================================================================= */
(function () {
  'use strict';

  var doc = document;
  function $(sel, root) { return (root || doc).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

  /* ---------------------------------------------------------- mobile nav ---- */
  /* The sidebar is a drawer below 1024px. Toggling adds .app-nav-open to <body>;
     the scrim and the Escape key close it, and focus returns to the button. */
  function initMobileNav() {
    var toggles = $$('[data-app-nav-toggle]');
    if (!toggles.length) return;

    function close() {
      if (!doc.body.classList.contains('app-nav-open')) return;
      doc.body.classList.remove('app-nav-open');
      toggles.forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
    }
    function open(btn) {
      doc.body.classList.add('app-nav-open');
      toggles.forEach(function (t) { t.setAttribute('aria-expanded', 'true'); });
      var first = $('.app-sidebar a, .app-sidebar button');
      if (first) first.focus();
    }
    toggles.forEach(function (btn) {
      btn.setAttribute('aria-expanded', 'false');
      btn.addEventListener('click', function () {
        if (doc.body.classList.contains('app-nav-open')) { close(); btn.focus(); } else { open(btn); }
      });
    });
    var scrim = $('.app-scrim');
    if (scrim) scrim.addEventListener('click', close);
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    $$('.app-sidebar a').forEach(function (a) { a.addEventListener('click', function () { if (window.innerWidth <= 1024) close(); }); });
    window.addEventListener('resize', function () { if (window.innerWidth > 1024) close(); });
  }

  /* ------------------------------------------------------ public site nav --- */
  function initSiteNav() {
    var btn = $('[data-nav-toggle]'), nav = $('.main-nav');
    if (!btn || !nav) return;
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function () {
      var isOpen = nav.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        nav.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false'); btn.focus();
      }
    });
  }

  /* --------------------------------------------------------------- modals --- */
  /* Keeps the historic openModal(id)/closeModal(id) API used by older pages,
     and adds focus management so the dialog is usable with a keyboard. */
  var lastFocus = null;

  function openModal(id) {
    var m = doc.getElementById(id);
    if (!m) return;
    lastFocus = doc.activeElement;
    m.classList.add('is-open');
    m.setAttribute('aria-hidden', 'false');
    doc.body.style.overflow = 'hidden';
    var focusable = $('input:not([type=hidden]), select, textarea, button, a[href]', m);
    if (focusable) focusable.focus();
  }
  function closeModal(id) {
    var m = doc.getElementById(id);
    if (!m) return;
    m.classList.remove('is-open');
    m.setAttribute('aria-hidden', 'true');
    doc.body.style.overflow = '';
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }
  window.openModal = openModal;
  window.closeModal = closeModal;

  function initModals() {
    $$('.modal-backdrop').forEach(function (bd) {
      if (!bd.hasAttribute('role')) bd.setAttribute('role', 'dialog');
      bd.setAttribute('aria-modal', 'true');
      if (!bd.hasAttribute('aria-hidden')) bd.setAttribute('aria-hidden', 'true');
      bd.addEventListener('mousedown', function (e) { if (e.target === bd) closeModal(bd.id); });
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var open = $$('.modal-backdrop.is-open');
      if (open.length) { closeModal(open[open.length - 1].id); return; }
      /* Close any open dropdown menu as well. */
      $$('[data-menu].open, .adm-menu.open').forEach(function (m) { m.classList.remove('open'); });
    });
  }

  /* ------------------------------------------------------------ dropdowns --- */
  function initDropdowns() {
    $$('[data-dropdown]').forEach(function (wrap) {
      var btn = $('[data-dropdown-toggle]', wrap), panel = $('.notif-dropdown, [data-dropdown-panel]', wrap);
      if (!btn || !panel) return;
      btn.setAttribute('aria-expanded', 'false');
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = panel.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
      doc.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) { panel.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false'); }
      });
    });
  }

  /* ------------------------------------------------- submit loading state --- */
  /* Prevents a double submit and gives immediate feedback. It never blocks the
     request: if the browser cannot submit, the class is removed again. */
  function initFormLoading() {
    $$('form').forEach(function (form) {
      if (form.hasAttribute('data-no-loading')) return;
      form.addEventListener('submit', function () {
        var btn = form.querySelector('[type=submit]');
        if (!btn || btn.disabled) return;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        setTimeout(function () {
          if (btn.classList.contains('is-loading')) { btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }
        }, 15000);
      });
    });
  }

  /* ---------------------------------------------- optional table sorting ---- */
  /* Opt in per table with <table data-sortable>. Purely presentational: it only
     reorders rows already rendered by the server, so authorization is untouched. */
  function initTableSort() {
    $$('table[data-sortable]').forEach(function (table) {
      var tbody = table.tBodies[0];
      if (!tbody) return;
      $$('th', table).forEach(function (th, idx) {
        if (th.hasAttribute('data-no-sort')) return;
        th.style.cursor = 'pointer';
        th.setAttribute('role', 'columnheader');
        th.setAttribute('tabindex', '0');
        th.setAttribute('aria-sort', 'none');
        function sort() {
          var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
          $$('th', table).forEach(function (o) { o.setAttribute('aria-sort', 'none'); });
          th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
          var rows = $$('tr', tbody).filter(function (r) { return !r.querySelector('.empty-state'); });
          rows.sort(function (a, b) {
            var av = (a.children[idx] ? a.children[idx].innerText : '').trim();
            var bv = (b.children[idx] ? b.children[idx].innerText : '').trim();
            var an = parseFloat(av.replace(/[^\d.\-]/g, '')), bn = parseFloat(bv.replace(/[^\d.\-]/g, ''));
            if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
            return av.localeCompare(bv, undefined, { numeric: true }) * dir;
          });
          rows.forEach(function (r) { tbody.appendChild(r); });
        }
        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
      });
    });
  }

  /* --------------------------------------------------------- file inputs ---- */
  /* Show the picked file name next to a custom upload control, if present. */
  function initUploads() {
    $$('[data-upload-input]').forEach(function (input) {
      var target = $(input.getAttribute('data-upload-input'));
      if (!target) return;
      input.addEventListener('change', function () {
        target.textContent = input.files && input.files.length ? input.files[0].name : target.getAttribute('data-default') || '';
      });
    });
  }

  function boot() {
    initMobileNav(); initSiteNav(); initModals(); initDropdowns();
    initFormLoading(); initTableSort(); initUploads();
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot); else boot();
})();
