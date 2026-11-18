const { chromium } = require('@playwright/test');
const fs = require('fs');
const BASE = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:12000';
const OUT = '/workspace/project/mawatheq/.agent_tmp/exec66/before';
const PASSWORD = 'Test-Password-2026';
const VIEWPORTS = { '360': {width:360,height:800}, '390': {width:390,height:844}, '768': {width:768,height:1024}, '1366': {width:1366,height:900}, '1920': {width:1920,height:1080} };
const PAGES = [
  ['01-browse-lawyers', '/consultations.php'],
  ['02-journey-specialty', '/consultation-journey.php'],
  ['03-journey-lawyers', '/consultation-journey-lawyers.php?category_id=1'],
  ['04-booking-form', '/booking.php?lawyer_id=1'],
];
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: {width:1366,height:900}, locale: 'ar-SA' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name="email"]', 'client-a@example.com');
  await page.fill('input[name="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('domcontentloaded');
  if (page.url().includes('login.php')) { console.error('LOGIN FAILED'); process.exit(1); }
  console.log('logged in ->', page.url());
  for (const [name, vp] of Object.entries(VIEWPORTS)) {
    for (const [label, path] of PAGES) {
      await page.setViewportSize(vp);
      await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(600);
      const file = `${OUT}/${label}_${name}.png`;
      await page.screenshot({ path: file, fullPage: true });
      const bytes = fs.statSync(file).size;
      console.log(`${label}_${name}  ${bytes}`);
    }
  }
  await browser.close();
})();
