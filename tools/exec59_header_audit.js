const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const PAGES = [
  { path: '/index.php', name: 'home' },
  { path: '/privacy.php', name: 'privacy' },
];
const WIDTHS = [360, 390, 768, 1024, 1280, 1440, 1920];

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const rows = [];
  for (const p of PAGES) {
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({
        viewport: { width: w, height: 900 },
        deviceScaleFactor: 1,
      });
      const page = await ctx.newPage();
      const resp = await page.goto(BASE + p.path, { waitUntil: 'networkidle' });
      // A stale cached stylesheet produces false overflow results.
      await page.evaluate(() => window.scrollTo(0, 0));

      const m = await page.evaluate(() => {
        const header = document.querySelector('.site-header');
        if (!header) return { missing: true };
        const cs = getComputedStyle(header);
        const brand = document.querySelector('.site-header .brand');
        const nav = document.querySelector('.site-header .main-nav');
        const actions = document.querySelector('.site-header .header-actions');
        const toggle = document.querySelector('.site-header .nav-toggle');
        const box = el => {
          if (!el) return null;
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width),
                   h: Math.round(r.height), right: Math.round(r.right),
                   display: s.display, visibility: s.visibility };
        };
        const b = box(brand), n = box(nav), a = box(actions), t = box(toggle);
        // Overlap between brand / nav / actions, which is the reported symptom.
        const overlaps = (p, q) => {
          if (!p || !q || p.display === 'none' || q.display === 'none') return false;
          if (p.w === 0 || q.w === 0) return false;
          return !(p.right <= q.x || q.right <= p.x || p.y + p.h <= q.y || q.y + q.h <= p.y);
        };
        const docOverflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
        const inner = header.querySelector('.container');
        const ib = box(inner);
        return {
          height: Math.round(header.getBoundingClientRect().height),
          position: cs.position,
          brand: b, nav: n, actions: a, toggle: t, container: ib,
          brandOverlapsNav: overlaps(b, n),
          navOverlapsActions: overlaps(n, a),
          brandOverlapsActions: overlaps(b, a),
          navVisible: n ? (n.display !== 'none' && n.w > 0) : false,
          toggleVisible: t ? (t.display !== 'none' && t.w > 0) : false,
          docOverflow,
          navLinks: document.querySelectorAll('.site-header .main-nav a').length,
        };
      });

      const shot = `/tmp/exec59_shots/${p.name}-${w}.png`;
      await page.locator('.site-header').screenshot({ path: shot }).catch(() => {});
      rows.push({ page: p.name, width: w, status: resp.status(), ...m });
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync('/tmp/exec59_shots/header-audit.json', JSON.stringify(rows, null, 2));
  for (const r of rows) {
    const bad = r.brandOverlapsNav || r.navOverlapsActions || r.brandOverlapsActions || r.docOverflow > 1;
    console.log(
      `${bad ? 'FAIL' : 'ok  '} ${r.page.padEnd(8)} ${String(r.width).padStart(4)}  ` +
      `h=${r.height} nav=${r.navVisible ? 'shown' : 'hidden'} toggle=${r.toggleVisible ? 'shown' : 'hidden'} ` +
      `ovl(b/n=${r.brandOverlapsNav},n/a=${r.navOverlapsActions},b/a=${r.brandOverlapsActions}) ` +
      `overflow=${r.docOverflow}`
    );
  }
})();
