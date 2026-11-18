const { chromium } = require('/workspace/project/mawatheq/node_modules/playwright');
const BASE = 'http://127.0.0.1:8080';
const VPS = [[360,640],[390,740],[768,1024],[1366,768],[1920,1080]];
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'ar' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name="email"]', 'client-a@example.com');
  await page.fill('input[name="password"]', 'Test-Password-2026');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
  const url = process.argv[2];
  for (const [w,h] of VPS) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(BASE + url);
    await page.waitForTimeout(700);
    const m = await page.evaluate(() => {
      const de = document.documentElement;
      const overflow = de.scrollWidth - de.clientWidth;
      const dir = getComputedStyle(document.body).direction;
      // Price inputs must be LTR; Arabic text RTL.
      const num = document.querySelector('input[name="max_fee"]');
      const numDir = num ? getComputedStyle(num).direction : 'n/a';
      return { overflow, dir, numDir, h1: document.querySelectorAll('h1').length };
    });
    console.log(`${w}x${h}  overflow=${m.overflow}px  dir=${m.dir}  feeDir=${m.numDir}  h1=${m.h1}`);
  }
  await browser.close();
})();
