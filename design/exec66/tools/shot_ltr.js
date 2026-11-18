const { chromium } = require('@playwright/test');
const fs = require('fs');
const BASE = 'http://127.0.0.1:13000';
const OUT = '/workspace/project/mawatheq/.agent_tmp/exec66/design_ltr';
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport:{width:1366,height:900}, deviceScaleFactor:2, locale:'en-US' });
  const page = await ctx.newPage();
  const shots = [
    ['website-01-journey-a.html', [1,3,6]],
    ['website-02-journey-b.html', [1,4]],
    ['website-03-states.html', [4]],
  ];
  for (const [file, stages] of shots) {
    for (const s of stages) {
      await page.goto(`${BASE}/${file}?stage=${s}&dir=ltr`, { waitUntil:'networkidle' });
      await page.waitForTimeout(400);
      const out = `${OUT}/${file.replace('.html','')}_stage${s}_ltr.png`;
      await page.screenshot({ path: out, fullPage: true });
      const dir = await page.evaluate(() => getComputedStyle(document.body).direction);
      console.log(`${out.split('/').pop()}  ${fs.statSync(out).size}  bodyDir=${dir}`);
    }
  }
  await browser.close();
})();
