const { chromium } = require('playwright');
const fs = require('fs');
const TARGETS = [
  { label: 'before', base: 'https://mawatheq.com', path: '/' },
  { label: 'after',  base: 'http://127.0.0.1:8080', path: '/index.php' },
];
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const out = {};
  for (const t of TARGETS) {
    out[t.label] = {};
    for (const w of [360, 768, 1280, 1440]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(t.base + t.path, { waitUntil: 'networkidle', timeout: 45000 });
      await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; });
      const before = await page.evaluate(() => {
        const h = document.querySelector('.site-header');
        return { h: Math.round(h.getBoundingClientRect().height),
                 cls: h.className, bg: getComputedStyle(h).backgroundColor,
                 pos: getComputedStyle(h).position };
      });
      await page.evaluate(() => window.scrollTo(0, 600));
      await page.waitForTimeout(500);
      const after = await page.evaluate(() => {
        const h = document.querySelector('.site-header');
        const r = h.getBoundingClientRect();
        const c = h.querySelector('.container');
        return { h: Math.round(r.height), top: Math.round(r.top),
                 cls: h.className, bg: getComputedStyle(h).backgroundColor,
                 pos: getComputedStyle(h).position,
                 containerH: c ? Math.round(c.getBoundingClientRect().height) : null,
                 // Sticky means the bar must still be pinned at the viewport top.
                 pinned: Math.abs(r.top) <= 1 };
      });
      await page.locator('.site-header').screenshot({ path: `/tmp/exec59_shots/${t.label}-scrolled-${w}.png` }).catch(()=>{});
      out[t.label][w] = { before, after };
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync('/tmp/exec59_shots/scroll.json', JSON.stringify(out, null, 2));
  for (const w of [360,768,1280,1440]) {
    const b = out.before[w], a = out.after[w];
    console.log(`${String(w).padStart(4)}px BEFORE  h=${b.before.h}->${b.after.h} pos=${b.after.pos} pinned=${b.after.pinned} cls="${b.after.cls}"`);
    console.log(`${String(w).padStart(4)}px AFTER   h=${a.before.h}->${a.after.h} pos=${a.after.pos} pinned=${a.after.pinned} cls="${a.after.cls}"`);
  }
})();
