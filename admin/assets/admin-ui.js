/* MAWATHEQ Admin UI — dependency-free (CSP: script-src 'self'). */
(function () {
  'use strict';
  var d = document, b = d.body, ls = window.localStorage;
  function q(s, r) { return (r || d).querySelector(s); }
  function qa(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function store(k, v) { try { if (v === undefined) return ls.getItem(k); ls.setItem(k, v); } catch (e) {} return null; }

  /* ---- Theme + sidebar state ---- */
  var theme = store('adm_theme') || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  b.setAttribute('data-theme', theme);
  if (store('adm_sb') === '1' && window.innerWidth > 1024) b.classList.add('sb-collapsed');
  d.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]'); if (!t) return;
    var a = t.getAttribute('data-act');
    if (a === 'theme') { theme = b.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'; b.setAttribute('data-theme', theme); store('adm_theme', theme); redrawCharts(); }
    if (a === 'sidebar') { if (window.innerWidth <= 1024) b.classList.toggle('sb-open'); else { b.classList.toggle('sb-collapsed'); store('adm_sb', b.classList.contains('sb-collapsed') ? '1' : '0'); } }
    if (a === 'scrim') b.classList.remove('sb-open');
  });

  /* ---- Dropdown menus ---- */
  d.addEventListener('click', function (e) {
    var btn = e.target.closest('.adm-menu>[data-menu]');
    qa('.adm-menu.open').forEach(function (m) { if (!btn || m !== btn.parentNode) m.classList.remove('open'); });
    if (btn) btn.parentNode.classList.toggle('open');
  });
  d.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { qa('.adm-menu.open').forEach(function (m) { m.classList.remove('open'); }); b.classList.remove('sb-open'); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { var s = q('.adm-search input'); if (s) { e.preventDefault(); s.focus(); } }
  });

  /* ---- Modals (kept API: openModal/closeModal used by legacy pages) ---- */
  window.openModal = function (id) { var m = d.getElementById(id); if (m) { m.classList.add('is-open'); b.style.overflow = 'hidden'; } };
  window.closeModal = function (id) { var m = d.getElementById(id); if (m) { m.classList.remove('is-open'); b.style.overflow = ''; } };
  qa('.modal-backdrop').forEach(function (bd) { bd.addEventListener('mousedown', function (e) { if (e.target === bd) window.closeModal(bd.id); }); });
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape') qa('.modal-backdrop.is-open').forEach(function (m) { window.closeModal(m.id); }); });

  /* ---- Tabs: <div data-tabs> buttons[data-tab] + .tab-pane[id] ---- */
  qa('[data-tabs]').forEach(function (wrap) {
    function show(id) {
      qa('[data-tab]', wrap).forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-tab') === id); });
      qa('.tab-pane', wrap.parentNode).forEach(function (p) { p.classList.toggle('on', p.id === id); });
      if (history.replaceState) history.replaceState(null, '', '#' + id);
      redrawCharts();
    }
    qa('[data-tab]', wrap).forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); }); });
    var h = location.hash.slice(1), first = q('[data-tab]', wrap);
    show(h && q('#' + h) ? h : (q('[data-tab].on', wrap) || first).getAttribute('data-tab'));
  });

  /* ---- Live table filter: <input data-filter="#tableId"> and chips [data-col-filter] ---- */
  qa('[data-filter]').forEach(function (inp) {
    var tbl = q(inp.getAttribute('data-filter')); if (!tbl) return;
    inp.addEventListener('input', function () {
      var v = inp.value.trim().toLowerCase();
      qa('tbody tr', tbl).forEach(function (tr) { if (tr.querySelector('.empty')) return; tr.style.display = tr.textContent.toLowerCase().indexOf(v) > -1 ? '' : 'none'; });
    });
  });
  qa('[data-status-filter]').forEach(function (c) {
    c.addEventListener('click', function (e) {
      e.preventDefault(); var tbl = q(c.getAttribute('data-status-filter')), s = c.getAttribute('data-value');
      qa('[data-status-filter="' + c.getAttribute('data-status-filter') + '"]').forEach(function (x) { x.classList.toggle('on', x === c); });
      qa('tbody tr', tbl).forEach(function (tr) { tr.style.display = (!s || tr.getAttribute('data-status') === s) ? '' : 'none'; });
    });
  });

  /* ---- Toast ---- */
  window.admToast = function (msg, bad) {
    var t = d.createElement('div');
    t.textContent = msg; t.style.cssText = 'position:fixed;bottom:22px;inset-inline-start:50%;transform:translateX(-50%);z-index:400;padding:11px 18px;border-radius:12px;color:#fff;font-size:13px;font-weight:600;box-shadow:0 12px 30px rgba(0,0,0,.25);background:' + (bad ? '#c2413a' : '#12805c');
    b.appendChild(t); setTimeout(function () { t.remove(); }, 2600);
  };

  /* ---- Charts (SVG) ---- */
  var NS = 'http://www.w3.org/2000/svg', tip;
  function el(n, a, p) { var e = d.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; }
  function fmt(v, f) { if (f === 'money') return Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); return Number(v).toLocaleString(); }
  function showTip(e, html) { if (!tip) { tip = d.createElement('div'); tip.className = 'chart-tip'; b.appendChild(tip); } tip.innerHTML = html; tip.style.display = 'block'; tip.style.left = (e.clientX + 12) + 'px'; tip.style.top = (e.clientY - 34) + 'px'; }
  function hideTip() { if (tip) tip.style.display = 'none'; }
  function cssv(n) { return getComputedStyle(b).getPropertyValue(n).trim(); }

  function drawSeries(box, cfg) {
    var W = 640, H = cfg.height || 240, pl = 44, pr = 10, pt = 12, pb = 26, n = cfg.labels.length, rtl = d.documentElement.dir === 'rtl';
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' }), max = 0;
    cfg.series.forEach(function (s) { s.data.forEach(function (v) { if (v > max) max = v; }); }); max = max || 1;
    var step = Math.pow(10, Math.floor(Math.log10(max))); max = Math.ceil(max / step) * step;
    var iw = W - pl - pr, ih = H - pt - pb, bw = iw / n;
    function X(i) { var x = cfg.type === 'bar' ? bw * i + bw / 2 : (n === 1 ? iw / 2 : iw * i / (n - 1)); return pl + (rtl ? iw - x : x); }
    function Y(v) { return pt + ih - ih * v / max; }
    for (var g = 0; g <= 4; g++) { var gy = pt + ih * g / 4; el('line', { x1: pl, x2: W - pr, y1: gy, y2: gy, 'class': 'gl' }, svg); var t = el('text', { x: rtl ? W - pl + 6 : pl - 6, y: gy + 4, 'class': 'ax', 'text-anchor': rtl ? 'start' : 'end' }, svg); t.textContent = fmt(max * (4 - g) / 4, cfg.fmt); }
    var every = Math.ceil(n / 8);
    cfg.labels.forEach(function (l, i) { if (i % every) return; var t = el('text', { x: X(i), y: H - 6, 'class': 'ax', 'text-anchor': 'middle' }, svg); t.textContent = l; });
    cfg.series.forEach(function (s, si) {
      var col = s.color || ['#2547b8', '#c39a3e', '#12805c', '#c2413a'][si % 4];
      if (cfg.type === 'bar') {
        var k = cfg.series.length, w = Math.min(28, bw / (k + 1));
        s.data.forEach(function (v, i) {
          var r = el('rect', { x: X(i) - (k * w) / 2 + si * w + 1, y: Y(v), width: w - 2, height: Math.max(0, pt + ih - Y(v)), rx: 4, fill: col, opacity: .92 }, svg);
          r.addEventListener('mousemove', function (e) { showTip(e, cfg.labels[i] + ' · ' + (s.name || '') + ': <b>' + fmt(v, cfg.fmt) + '</b>'); }); r.addEventListener('mouseleave', hideTip);
        });
      } else {
        var pts = s.data.map(function (v, i) { return X(i) + ',' + Y(v); });
        if (cfg.type === 'area') {
          var id = 'g' + Math.random().toString(36).slice(2, 7), df = el('defs', {}, svg), lg = el('linearGradient', { id: id, x1: 0, y1: 0, x2: 0, y2: 1 }, df);
          el('stop', { offset: '0%', 'stop-color': col, 'stop-opacity': .28 }, lg); el('stop', { offset: '100%', 'stop-color': col, 'stop-opacity': 0 }, lg);
          el('polygon', { points: X(0) + ',' + (pt + ih) + ' ' + pts.join(' ') + ' ' + X(n - 1) + ',' + (pt + ih), fill: 'url(#' + id + ')' }, svg);
        }
        el('polyline', { points: pts.join(' '), fill: 'none', stroke: col, 'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
        s.data.forEach(function (v, i) {
          var c = el('circle', { cx: X(i), cy: Y(v), r: 4, fill: cssv('--surface'), stroke: col, 'stroke-width': 2, opacity: n > 16 ? 0 : 1 }, svg);
          var hit = el('circle', { cx: X(i), cy: Y(v), r: 14, fill: 'transparent' }, svg);
          hit.addEventListener('mousemove', function (e) { c.setAttribute('opacity', 1); showTip(e, cfg.labels[i] + ' · ' + (s.name || '') + ': <b>' + fmt(v, cfg.fmt) + '</b>'); });
          hit.addEventListener('mouseleave', function () { c.setAttribute('opacity', n > 16 ? 0 : 1); hideTip(); });
        });
      }
    });
    box.appendChild(svg);
  }
  function drawDonut(box, cfg) {
    var total = cfg.data.reduce(function (a, b2) { return a + b2; }, 0), svg = el('svg', { viewBox: '0 0 120 120' }), R = 46, C = 2 * Math.PI * R, off = 0;
    el('circle', { cx: 60, cy: 60, r: R, fill: 'none', stroke: cssv('--line-2'), 'stroke-width': 16 }, svg);
    if (total > 0) cfg.data.forEach(function (v, i) {
      if (!v) return; var len = C * v / total;
      var c = el('circle', { cx: 60, cy: 60, r: R, fill: 'none', stroke: cfg.colors[i % cfg.colors.length], 'stroke-width': 16, 'stroke-dasharray': len + ' ' + (C - len), 'stroke-dashoffset': -off, transform: 'rotate(-90 60 60)' }, svg);
      c.addEventListener('mousemove', function (e) { showTip(e, cfg.labels[i] + ': <b>' + fmt(v) + '</b> (' + Math.round(100 * v / total) + '%)'); }); c.addEventListener('mouseleave', hideTip); off += len;
    });
    var t1 = el('text', { x: 60, y: 62, 'text-anchor': 'middle', 'font-size': 19, 'font-weight': 800, fill: cssv('--ink') }, svg); t1.textContent = fmt(total);
    var t2 = el('text', { x: 60, y: 77, 'text-anchor': 'middle', 'font-size': 8, fill: cssv('--muted') }, svg); t2.textContent = cfg.center || '';
    box.appendChild(svg);
  }
  function drawSpark(box, cfg) {
    var W = 200, H = 34, n = cfg.data.length, max = Math.max.apply(null, cfg.data.concat([1])), svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'spark', preserveAspectRatio: 'none' });
    var rtl = d.documentElement.dir === 'rtl';
    var pts = cfg.data.map(function (v, i) { var x = n === 1 ? W / 2 : W * i / (n - 1); if (rtl) x = W - x; return x + ',' + (H - 3 - (H - 8) * v / max); });
    el('polyline', { points: pts.join(' '), fill: 'none', stroke: cfg.color || cssv('--primary'), 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'vector-effect': 'non-scaling-stroke' }, svg); box.appendChild(svg);
  }
  function redrawCharts() {
    qa('[data-chart]').forEach(function (box) {
      if (box.offsetParent === null) return;
      var cfg; try { cfg = JSON.parse(box.getAttribute('data-chart')); } catch (e) { return; }
      box.innerHTML = '';
      if (cfg.type === 'donut') drawDonut(box, cfg); else if (cfg.type === 'spark') drawSpark(box, cfg); else drawSeries(box, cfg);
    });
  }
  redrawCharts();

  /* ---- Kanban drag & drop (HTML5 + <select> fallback for touch) ---- */
  qa('.kanban').forEach(function (board) {
    var url = board.getAttribute('data-url'), tok = board.getAttribute('data-token'), dragging = null, fromCol = null;
    function totals() {
      qa('.kcol', board).forEach(function (col) {
        var cards = qa('.kcard', col), sum = 0; cards.forEach(function (c) { sum += parseFloat(c.getAttribute('data-value') || 0); });
        var n = q('[data-count]', col), s = q('[data-sum]', col); if (n) n.textContent = cards.length; if (s) s.textContent = Math.round(sum).toLocaleString();
        var emp = q('.k-empty', col); if (emp) emp.style.display = cards.length ? 'none' : '';
      });
    }
    function move(card, col, revertCol) {
      var fd = new FormData(); fd.append('csrf_token', tok); fd.append('action', board.getAttribute('data-action') || 'move'); fd.append('id', card.getAttribute('data-id')); fd.append('stage', col.getAttribute('data-stage'));
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw 0; admToast(board.getAttribute('data-ok') || 'OK'); var sel = q('select', card); if (sel) sel.value = col.getAttribute('data-stage'); })
        .catch(function () { if (revertCol) revertCol.appendChild(card); totals(); admToast(board.getAttribute('data-fail') || 'Failed', true); });
    }
    board.addEventListener('dragstart', function (e) { var c = e.target.closest('.kcard'); if (!c) return; dragging = c; fromCol = c.parentNode.closest('.kcol'); c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', c.getAttribute('data-id')); } catch (x) {} });
    board.addEventListener('dragend', function () { if (dragging) dragging.classList.remove('drag'); qa('.kcol', board).forEach(function (c) { c.classList.remove('over'); }); dragging = null; });
    board.addEventListener('dragover', function (e) { var col = e.target.closest('.kcol'); if (!col || !dragging) return; e.preventDefault(); qa('.kcol', board).forEach(function (c) { c.classList.toggle('over', c === col); }); });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.kcol'); if (!col || !dragging) return; e.preventDefault();
      if (col !== fromCol) { q('.kbody', col).appendChild(dragging); totals(); move(dragging, col, q('.kbody', fromCol)); }
    });
    qa('.kcard select', board).forEach(function (sel) {
      sel.addEventListener('change', function () {
        var card = sel.closest('.kcard'), from = card.closest('.kcol'), to = q('.kcol[data-stage="' + sel.value + '"]', board);
        if (to && to !== from) { q('.kbody', to).appendChild(card); totals(); move(card, to, q('.kbody', from)); }
      });
    });
    totals();
  });

  var rz; window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(redrawCharts, 150); });
})();
