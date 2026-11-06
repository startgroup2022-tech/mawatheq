const { chromium } = require('playwright');
const fs = require('fs');

// BEFORE is the live production site; AFTER is the repository build served locally.
// Measuring both with the same script is what makes the comparison meaningful.
const TARGETS = [
  { label: 'before', base: 'https://mawatheq.com', path: '/', out: '/tmp/exec59_shots/before' },
  { label: 'after',  base: 'http://127.0.0.1:8080', path: '/index.php', out: '/tmp/exec59_shots/after' },
];
const WIDTHS = [360, 768, 1280, 1440, 1920];

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const report = {};

  for (const t of TARGETS) {
    fs.mkdirSync(t.out, { recursive: true });
    report[t.label] = {};
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      try {
        await page.goto(t.base + t.path, { waitUntil: 'networkidle', timeout: 45000 });
      } catch (e) {
        report[t.label][w] = { error: String(e).slice(0, 80) };
        await ctx.close();
        continue;
      }
      await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; window.scrollTo(0,0); });

      const m = await page.evaluate(() => {
        const header = document.querySelector('.site-header');
        if (!header) return { missing: true };
        const q = s => header.querySelector(s);
        const box = el => {
          if (!el) return null;
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          if (s.display === 'none' || r.width === 0) return null;
          return { x: Math.round(r.x), right: Math.round(r.right), y: Math.round(r.y),
                   bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) };
        };
        const brand = box(q('.brand'));
        const nav = box(q('.main-nav'));
        const actions = box(q('.header-actions'));
        const overlap = (p, r) => {
          if (!p || !r) return false;
          return !(p.right <= r.x || r.right <= p.x || p.bottom <= r.y || r.bottom <= p.y);
        };
        const c = q('.container');
        return {
          display: c ? getComputedStyle(c).display : null,
          maxWidth: c ? getComputedStyle(c).maxWidth : null,
          headerHeight: Math.round(header.getBoundingClientRect().height),
          brand, nav, actions,
          brandOverNav: overlap(brand, nav),
          navOverActions: overlap(nav, actions),
          brandOverActions: overlap(brand, actions),
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
      });

      await page.locator('.site-header').screenshot({ path: `${t.out}/header-${w}.png` }).catch(() => {});
      await page.screenshot({ path: `${t.out}/page-${w}.png` }).catch(() => {});
      report[t.label][w] = m;
      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync('/tmp/exec59_shots/before-after.json', JSON.stringify(report, null, 2));

  console.log('width  | BEFORE (production)                        | AFTER (repo build)');
  console.log('-------|------------------------------------------|------------------------------------------');
  for (const w of WIDTHS) {
    const f = r => {
      if (!r || r.error || r.missing) return 'n/a';
      const bad = r.brandOverNav || r.navOverActions || r.brandOverActions;
      return `${r.display} ovl=${bad ? 'YES' : 'no '} of=${r.overflow}`;
    };
    console.log(`${String(w).padStart(5)}  | ${f(report.before[w]).padEnd(40)} | ${f(report.after[w])}`);
  }
})();
