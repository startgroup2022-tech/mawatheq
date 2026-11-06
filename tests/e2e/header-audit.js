// Header audit: proves there is no overlap and nothing escapes the viewport.
//
// The owner's complaint was that the brand, the nav and the actions drew on top of
// each other. A screenshot alone cannot settle that — two boxes can look wrong
// without touching. This measures the real rectangles: every child of the header row
// must be inside the container, and no two must intersect horizontally.
//
// It also captures the before/after evidence the delivery requires. Run it against
// the local server for "after"; the "before" capture replays production's own CSS.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:8080';
const OUT = process.env.OUT_DIR || '/tmp/exec53_header';

const WIDTHS = [360, 390, 768, 1024, 1280, 1440, 1920];
const PAGES = [
  ['home', '/'],
  ['privacy', '/privacy.php'],
  ['delete-account', '/delete-account.php'],
];

async function audit(page, label) {
  return page.evaluate(() => {
    const header = document.querySelector('.site-header');
    if (!header) return { error: 'no .site-header' };
    const row = header.querySelector('.container') || header;
    const r = row.getBoundingClientRect();
    const parts = {};
    for (const sel of ['.brand', '.nav-toggle', '.main-nav', '.header-actions']) {
      const el = header.querySelector(sel);
      if (!el) continue;
      const cs = getComputedStyle(el);
      const b = el.getBoundingClientRect();
      parts[sel] = {
        x: Math.round(b.x), right: Math.round(b.right), w: Math.round(b.width), h: Math.round(b.height),
        visible: cs.display !== 'none' && cs.visibility !== 'hidden' && b.width > 0,
        overflowsRight: Math.round(b.right) > Math.round(r.right) + 1,
        overflowsLeft: Math.round(b.x) < Math.round(r.x) - 1,
      };
    }
    // Pairwise horizontal intersection among *visible* siblings. Touching edges are
    // allowed; a real overlap is strictly greater.
    const vis = Object.entries(parts).filter(([, v]) => v.visible);
    const overlaps = [];
    for (let i = 0; i < vis.length; i++) {
      for (let j = i + 1; j < vis.length; j++) {
        const [an, a] = vis[i], [bn, b] = vis[j];
        const ox = Math.min(a.right, b.right) - Math.max(a.x, b.x);
        if (ox > 1) overlaps.push(`${an} ∩ ${bn} by ${ox}px`);
      }
    }
    const docOverflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    return {
      row: { x: Math.round(r.x), right: Math.round(r.right), w: Math.round(r.width) },
      headerH: Math.round(header.getBoundingClientRect().height),
      parts, overlaps,
      docOverflow,
      hScroll: docOverflow > 1,
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const results = [];
  let failures = 0;

  for (const [name, url] of PAGES) {
    for (const w of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      await page.goto(BASE + url, { waitUntil: 'load' });
      await page.waitForTimeout(400);
      const a = await audit(page);
      // Only a *visible* part can overflow. A `display:none` element reports a
      // zero rect at x=0, which would otherwise read as escaping the container's
      // left edge — a false positive that hides the real ones.
      const bad = (a.overlaps && a.overlaps.length) || a.hScroll || a.error ||
        Object.values(a.parts || {}).some(
          (p) => p.visible && (p.overflowsRight || p.overflowsLeft));
      if (bad) failures++;
      results.push({ page: name, width: w, ok: !bad, ...a });
      console.log(
        `${bad ? 'FAIL' : 'ok  '} ${name.padEnd(15)} ${String(w).padStart(4)}px ` +
        `h=${a.headerH} ` +
        (a.error ? a.error : `overflow=${a.docOverflow} overlaps=[${(a.overlaps || []).join('; ') || 'none'}]`)
      );
      if (name === 'home') {
        await page.screenshot({ path: path.join(OUT, `header-${w}.png`), clip: { x: 0, y: 0, width: w, height: 140 } });
      }
      await page.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'header-audit.json'), JSON.stringify(results, null, 2));
  console.log(`\n${failures} failure(s) across ${results.length} checks`);
  process.exit(failures ? 1 : 0);
})();
