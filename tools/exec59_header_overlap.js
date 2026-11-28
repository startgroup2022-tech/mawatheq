// Overlap must be measured on painted boxes, not on the nav's flex container: that
// container legitimately spans the middle column, so comparing it to the brand reports
// a collision that no visitor can see. Measure the brand image, the first and last nav
// link, and the first header action instead.
const { chromium } = require('playwright');
const WIDTHS = [1200, 1240, 1280, 1360, 1440, 1600, 1920];
const TARGETS = [
  { label: 'PROD', base: 'https://mawatheq.com', path: '/' },
  { label: 'REPO', base: 'http://127.0.0.1:8080', path: '/index.php' },
];
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  for (const t of TARGETS) {
    console.log('\n=== ' + t.label + ' ===');
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
      const page = await ctx.newPage();
      await page.goto(t.base + t.path, { waitUntil: 'networkidle', timeout: 45000 }).catch(()=>{});
      const r = await page.evaluate(() => {
        const h = document.querySelector('.site-header'); if (!h) return {missing:true};
        const g = s => h.querySelector(s);
        const R = el => { if(!el) return null; const s=getComputedStyle(el); if(s.display==='none'||s.visibility==='hidden') return null; const b=el.getBoundingClientRect(); return {l:Math.round(b.left), r:Math.round(b.right)}; };
        const brand = R(g('.brand img')) || R(g('.brand'));
        const links = [...h.querySelectorAll('.main-nav a')].map(a=>R(a)).filter(Boolean);
        const acts = [...h.querySelectorAll('.header-actions > *')].map(a=>R(a)).filter(Boolean);
        const firstLink = links[0] || null, lastLink = links[links.length-1] || null;
        const firstAct = acts[0] || null;
        // True interval intersection. In RTL the brand sits on the right and the links
        // run leftward, so comparing one box's right edge to another's left edge reports
        // a collision between boxes that are merely ordered — not overlapping.
        const hit = (a,b) => (a && b) ? Math.max(0, Math.min(a.r,b.r) - Math.max(a.l,b.l)) : 0;
        return {
          brand, firstLink, lastLink, firstAct,
          nLinks: links.length,
          brandLink: hit(brand, firstLink),
          linkAct: hit(lastLink, firstAct),
          brandAct: hit(brand, firstAct),
        };
      });
      const pad = v => String(v ?? 0).padStart(3);
      console.log(`  ${String(w).padStart(5)}  links=${r.nLinks}  brand→link=${pad(r.brandLink)}px  lastLink→act=${pad(r.linkAct)}px  brand→act=${pad(r.brandAct)}px`);
      await ctx.close();
    }
  }
  await browser.close();
})();
