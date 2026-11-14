const { chromium } = require('playwright');
// Public marketing pages only. `/laws.php` is a client-portal page served by
// includes/app_header.php and legitimately has the app shell instead.
const PAGES = ['/index.php','/terms.php','/privacy.php','/delete-account.php','/contact.php'];
const WIDTHS = [390, 1280];
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  let fail = 0;
  for (const p of PAGES) {
    const line = [];
    let status = 0;
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
      const page = await ctx.newPage();
      const resp = await page.goto('http://127.0.0.1:8080' + p, { waitUntil: 'networkidle' });
      status = resp.status();
      const m = await page.evaluate(() => {
        const h = document.querySelector('.site-header');
        if (!h) return { missing: true };
        const c = h.querySelector('.container');
        const cs = getComputedStyle(c);
        return { display: cs.display, height: Math.round(h.getBoundingClientRect().height),
                 links: h.querySelectorAll('.main-nav a').length,
                 hasBrand: !!h.querySelector('.brand'),
                 hasActions: !!h.querySelector('.header-actions'),
                 overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
      });
      // The header must be identical on every public page.
      const ok = !m.missing && m.hasBrand && m.hasActions && m.links === 8 && m.overflow <= 1;
      if (!ok) fail++;
      line.push(`${w}:${ok ? 'ok' : 'FAIL'}(${m.display},h=${m.height},links=${m.links},of=${m.overflow})`);
      await ctx.close();
    }
    console.log(`${(status === 200 ? 'ok  ' : 'FAIL')} ${p.padEnd(20)} ${line.join('  ')}`);
  }
  await browser.close();
  console.log(fail === 0 ? '\nheader consistent on every public page' : `\n${fail} page(s) inconsistent`);
})();
