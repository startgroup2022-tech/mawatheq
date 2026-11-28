// Dump the header geometry so two environments can be compared field by field.
//   node tools/header_probe.js <baseUrl> <width>
const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const WIDTH = parseInt(process.argv[3] || '1280', 10);

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: WIDTH, height: 900 } });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const out = await page.evaluate(() => {
    const info = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return {
        x: Math.round(r.x), y: Math.round(r.y),
        w: Math.round(r.width), h: Math.round(r.height),
        display: cs.display, position: cs.position, overflow: cs.overflow,
      };
    };
    const sheets = [...document.styleSheets].map((s) => {
      try { return s.href || '(inline)'; } catch (_) { return '(cross-origin)'; }
    });
    const navEl = document.querySelector('.site-header .main-nav');
    const navLinks = navEl ? [...navEl.querySelectorAll('a')].map((a) => {
      const r = a.getBoundingClientRect();
      return { text: a.textContent.trim().slice(0, 14), x: Math.round(r.x), w: Math.round(r.width) };
    }) : [];
    const navCS = navEl ? getComputedStyle(navEl) : null;
    return {
      sheets,
      header: info('.site-header'),
      container: info('.site-header .container'),
      containerDisplay: document.querySelector('.site-header .container')
        ? getComputedStyle(document.querySelector('.site-header .container')).display : null,
      brand: info('.site-header .brand'),
      nav: info('.site-header .main-nav'),
      navOverflow: navCS ? navCS.overflow : null,
      navScrollW: navEl ? navEl.scrollWidth : null,
      navClientW: navEl ? navEl.clientWidth : null,
      actions: info('.site-header .header-actions'),
      toggle: info('.site-header .nav-toggle'),
      navLinks,
      docScrollW: document.documentElement.scrollWidth,
      winW: window.innerWidth,
    };
  });
  console.log(JSON.stringify(out, null, 2));
  await browser.close();
})();
