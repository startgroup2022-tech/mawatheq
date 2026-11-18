const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  const results = [];

  for (const blockEnterprise of [false, true]) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    if (blockEnterprise) {
      // Production served enterprise.css as 403; the header must survive without it.
      await ctx.route('**/enterprise.css*', r => r.abort());
    }
    const page = await ctx.newPage();
    await page.goto(BASE + '/index.php', { waitUntil: 'networkidle' });
    await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; });

    const before = await page.evaluate(() => {
      const nav = document.querySelector('.site-header .main-nav');
      const r = nav.getBoundingClientRect();
      const cs = getComputedStyle(nav);
      return { visible: cs.display !== 'none' && r.width > 0 && r.height > 0,
               onscreen: r.right > 0 && r.left < window.innerWidth && r.bottom > 0,
               x: Math.round(r.x), w: Math.round(r.width) };
    });

    await page.click('.site-header .nav-toggle');
    await page.waitForTimeout(400);

    const after = await page.evaluate(() => {
      const nav = document.querySelector('.site-header .main-nav');
      const r = nav.getBoundingClientRect();
      const cs = getComputedStyle(nav);
      const links = [...nav.querySelectorAll('a')];
      const visibleLinks = links.filter(a => {
        const lr = a.getBoundingClientRect();
        return lr.width > 0 && lr.height > 0 && lr.right > 0 && lr.left < window.innerWidth;
      }).length;
      return { x: Math.round(r.x), w: Math.round(r.width),
               expanded: document.querySelector('.site-header .nav-toggle').getAttribute('aria-expanded'),
               visibleLinks, totalLinks: links.length,
               // A link whose hit area is under 44px is hard to tap.
               minLinkHeight: Math.min(...links.map(a => Math.round(a.getBoundingClientRect().height))) };
    });

    // Close again: the toggle must be reversible.
    await page.click('.site-header .nav-toggle');
    await page.waitForTimeout(400);
    const closed = await page.evaluate(() => {
      const nav = document.querySelector('.site-header .main-nav');
      const r = nav.getBoundingClientRect();
      return { expanded: document.querySelector('.site-header .nav-toggle').getAttribute('aria-expanded'),
               onscreen: r.right > 0 && r.left < window.innerWidth };
    });

    await page.locator('.site-header').screenshot({ path: `/tmp/exec59_shots/drawer-${blockEnterprise ? 'no-enterprise' : 'normal'}.png` }).catch(()=>{});
    results.push({ blockEnterprise, before, after, closed });
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync('/tmp/exec59_shots/drawer.json', JSON.stringify(results, null, 2));
  let fail = 0;
  for (const r of results) {
    const label = r.blockEnterprise ? 'enterprise.css BLOCKED' : 'normal';
    const drawerOpens = r.after.visibleLinks === r.after.totalLinks && r.after.expanded === 'true';
    const closed = r.closed.expanded === 'false';
    const tappable = r.after.minLinkHeight >= 44;
    const bad = !drawerOpens || !closed || !tappable;
    if (bad) fail++;
    console.log(`${bad ? 'FAIL' : 'ok  '} ${label.padEnd(24)} opens=${drawerOpens} links=${r.after.visibleLinks}/${r.after.totalLinks} closed=${closed} minTap=${r.after.minLinkHeight}px`);
  }
  console.log(fail === 0 ? '\nall drawer checks pass' : `\n${fail} drawer check(s) failed`);
  process.exit(fail === 0 ? 0 : 1);
})();
