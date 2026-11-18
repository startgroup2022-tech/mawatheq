// Capture real-content screenshots of the legal library for the Execution 68 review.
//
//   node tools/exec68_library_shots.js [outDir]
//
// The site must be serving on 127.0.0.1:12000. Nothing here mutates the database; it
// only renders the pages and writes PNGs. Each shot is captured twice: a 1440x900
// desktop viewport and a 390x844 phone viewport, so the responsive layout and the
// Arabic typeface are both visible.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:12000';
const OUT = process.argv[2] || '/tmp/exec68_shots';

const SHOTS = [
  { name: 'hub', url: '/legal-library.php', wait: 'h1' },
  { name: 'search', url: '/legal-library-search.php?q=%D8%A7%D9%84%D9%85%D8%AD%D9%83%D9%85%D8%A9', wait: '.ll-results-head' },
  { name: 'detail', url: '/legal-library-item.php?id=' + (process.env.SHOT_ITEM_ID || '56'), wait: 'h1' },
];

const VIEWPORTS = [
  { label: 'desktop', width: 1440, height: 900, dsf: 1 },
  { label: 'mobile', width: 390, height: 844, dsf: 2 },
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const results = [];
  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: vp.dsf,
      locale: 'ar-SA',
    });
    const page = await ctx.newPage();
    for (const s of SHOTS) {
      const url = BASE + s.url;
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      if (s.wait) await page.waitForSelector(s.wait, { timeout: 15000 });
      await page.waitForTimeout(700);
      const file = path.join(OUT, `library-${s.name}-${vp.label}.png`);
      await page.screenshot({ path: file, fullPage: true });
      const h1 = (await page.locator('h1').first().textContent().catch(() => '')) || '';
      const size = fs.statSync(file).size;
      results.push({ file, vp: vp.label, name: s.name, h1: h1.trim().slice(0, 60), size });
      console.log(`  ok ${vp.label}/${s.name} -> ${file} (${size}b) h1="${h1.trim().slice(0, 50)}"`);
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(results, null, 2));
  console.log(`\nwrote ${results.length} screenshots to ${OUT}`);
})().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
