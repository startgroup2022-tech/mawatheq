// Before/after header captures for the delivery report.
//
// The header element is screenshotted through its own ElementHandle, not through a
// document-relative clip: a bounding box is viewport-relative while a clip is
// document-relative, so a clip taken below the fold lands on the wrong region. The
// element handle keeps the two in the same frame.
const { chromium } = require('playwright');
const fs = require('fs');

const TARGETS = [
  { label: 'before', base: 'https://mawatheq.com', path: '/' },
  { label: 'after', base: 'http://127.0.0.1:8080', path: '/index.php' },
];
const WIDTHS = [360, 390, 768, 1024, 1280, 1440, 1920];

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage'] });
  for (const t of TARGETS) {
    const dir = `/tmp/exec59_shots/${t.label}`;
    fs.mkdirSync(dir, { recursive: true });
    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      // Disable the HTTP cache: a stale app.css/style.css produces false results.
      await page.route('**/*', route => route.continue());
      await page.goto(t.base + t.path, { waitUntil: 'networkidle', timeout: 45000 }).catch(()=>{});
      await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; window.scrollTo(0, 0); });
      const header = await page.$('.site-header');
      if (header) {
        await header.screenshot({ path: `${dir}/header-${w}.png` });
        console.log(`${t.label} ${w}px -> ${dir}/header-${w}.png`);
      } else {
        console.log(`${t.label} ${w}px -> no .site-header`);
      }
      await ctx.close();
    }
  }
  await browser.close();
})();
