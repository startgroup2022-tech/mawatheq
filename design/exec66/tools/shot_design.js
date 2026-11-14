const { chromium } = require('@playwright/test');
const fs = require('fs');
const BASE = 'http://127.0.0.1:13000';
const OUT = process.env.OUT || '/workspace/project/mawatheq/.agent_tmp/exec66/design';
const file = process.argv[2];
const stages = (process.argv[3] || '1').split(',');
const vps = (process.argv[4] || '1366x900').split(',');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  for (const vp of vps) {
    const [w,h] = vp.split('x').map(Number);
    const ctx = await browser.newContext({ viewport: {width:w,height:h}, deviceScaleFactor: 2, locale:'ar-SA' });
    const page = await ctx.newPage();
    for (const s of stages) {
      await page.goto(`${BASE}/${file}?stage=${s}`, { waitUntil:'networkidle' });
      await page.waitForTimeout(500);
      const name = file.replace('.html','');
      const out = `${OUT}/${name}_stage${s}_${vp}.png`;
      await page.screenshot({ path: out, fullPage: true });
      console.log(`${out.split('/').pop()}  ${fs.statSync(out).size}`);
    }
    await ctx.close();
  }
  await browser.close();
})();
