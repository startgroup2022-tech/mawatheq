// Overlap and text-clipping audit for the public header and affiliate landing page.
//
// Screenshots show what happened but cannot be diffed by a script, so this measures the
// two visual failures that actually matter and are otherwise only visible by eye:
//   1. sibling elements in the header occupying the same pixels (overlap), and
//   2. text clipped by its own box (scrollWidth > clientWidth) or truncated by a fixed
//      height (scrollHeight > clientHeight), which is how a nav label silently loses
//      letters rather than wrapping.
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT = path.resolve(__dirname, '../docs/execution32/audit');
const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1280', width: 1280, height: 800 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
  { name: '360', width: 360, height: 800 },
];
const SITES = [
  { key: 'before', base: 'http://127.0.0.1:12002' },
  { key: 'after', base: 'http://127.0.0.1:12000' },
];

const MEASURE = () => {
  const clip = (el) => {
    const cs = getComputedStyle(el);
    const overflowHidden = ['hidden', 'clip'].includes(cs.overflowX) || ['hidden', 'clip'].includes(cs.overflow);
    return {
      hClip: el.scrollWidth > el.clientWidth + 1,
      vClip: el.scrollHeight > el.clientHeight + 1,
      // Only report a clip when the box is actually allowed to hide the overflow.
      clipped: overflowHidden && (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1),
    };
  };
  const describe = (el) => {
    const cls = typeof el.className === 'string' && el.className.trim()
      ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
      : '';
    return el.tagName.toLowerCase() + cls;
  };

  const header = document.querySelector('header.site-header');
  const out = { overlaps: [], clipped: [], navWrapRows: null, headerH: 0 };
  if (!header) return out;

  out.headerH = Math.round(header.getBoundingClientRect().height);

  // Top-level header children must not sit on top of one another.
  const kids = [...header.querySelector('.container').children].filter((el) => {
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none';
  });
  const inter = (a, b) => {
    const x = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left));
    const y = Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
    return x * y;
  };
  for (let i = 0; i < kids.length; i++) {
    for (let j = i + 1; j < kids.length; j++) {
      const a = kids[i].getBoundingClientRect();
      const b = kids[j].getBoundingClientRect();
      const area = inter(a, b);
      // Sub-pixel touching is fine; a real overlap is a meaningful number of pixels.
      if (area > 4) {
        out.overlaps.push({ a: describe(kids[i]), b: describe(kids[j]), px: Math.round(area) });
      }
    }
  }

  // Clipped text anywhere in the header, and in the landing form controls.
  const scan = [header, ...document.querySelectorAll('#apply label span, #apply button, #apply legend')];
  for (const el of scan) {
    if (!el) continue;
    const c = clip(el);
    if (c.clipped) out.clipped.push({ el: describe(el), h: c.hClip, v: c.vClip });
  }

  // Wrapped nav rows: distinct top offsets across the nav's own links.
  const nav = header.querySelector('#mainNav');
  if (nav && getComputedStyle(nav).display !== 'none') {
    const tops = [...nav.querySelectorAll('a')].map((a) => Math.round(a.getBoundingClientRect().top));
    out.navWrapRows = new Set(tops).size;
  }
  return out;
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  for (const site of SITES) {
    for (const vp of VIEWPORTS) {
      for (const lang of ['ar', 'en']) {
        const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
        const page = await ctx.newPage();
        for (const [name, url] of [['home', `${site.base}/?lang=${lang}`], ['landing', `${site.base}/affiliates.php?lang=${lang}`]]) {
          await page.goto(url, { waitUntil: 'domcontentloaded' });
          await page.waitForTimeout(350);
          const m = await page.evaluate(MEASURE);
          rows.push({ site: site.key, vp: vp.name, lang, page: name, ...m });
        }
        await ctx.close();
      }
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'visual-defects.json'), JSON.stringify(rows, null, 2));

  const show = (site, vp, lang, page) =>
    rows.find((r) => r.site === site && r.vp === vp && r.lang === lang && r.page === page) || {};
  console.log('vp/lang  site    page     navRows  hdrH  overlaps  clipped');
  for (const vp of VIEWPORTS.map((v) => v.name)) {
    for (const lang of ['ar', 'en']) {
      for (const page of ['home', 'landing']) {
        for (const site of ['before', 'after']) {
          const r = show(site, vp, lang, page);
          console.log(
            `${vp.padStart(5)}/${lang}  ${site.padEnd(6)}  ${page.padEnd(7)}  ${String(r.navWrapRows).padStart(5)}  ${String(r.headerH).padStart(4)}  ${String((r.overlaps || []).length).padStart(8)}  ${String((r.clipped || []).length).padStart(7)}`
          );
        }
      }
    }
  }
  const afterBad = rows.filter((r) => r.site === 'after' && ((r.overlaps || []).length || (r.clipped || []).length));
  console.log('\nAFTER-state defects:', afterBad.length ? JSON.stringify(afterBad.map((r) => ({ vp: r.vp, lang: r.lang, page: r.page, overlaps: r.overlaps, clipped: r.clipped })), null, 2) : 'none');
})();
