const { chromium } = require('/workspace/project/mawatheq/node_modules/playwright');
const BASE = 'http://127.0.0.1:8080';
const OUT = '/workspace/project/mawatheq/docs/exec66';

async function login(page, path, email, password) {
  await page.goto(BASE + path);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'ar' });
  const page = await ctx.newPage();
  await login(page, '/login.php', 'client-a@example.com', 'Test-Password-2026');

  // Lawyer comparison list with the professional filters, at desktop and mobile.
  await page.goto(BASE + '/consultation-journey-lawyers.php?category_id=1');
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/lawyer-filters-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: OUT + '/lawyer-filters-mobile.png', fullPage: true });

  // A narrowed result set (experience 5–10 + Riyadh + max fee 600).
  await page.setViewportSize({ width: 1366, height: 900 });
  await page.goto(BASE + '/consultation-journey-lawyers.php?category_id=1&country=SA&city=' + encodeURIComponent('الرياض') + '&experience=5to10&max_fee=600');
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/lawyer-filters-narrowed-desktop.png', fullPage: true });

  // Empty result — the professional empty state.
  await page.goto(BASE + '/consultation-journey-lawyers.php?category_id=1&experience=over20&max_fee=100');
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/lawyer-filters-empty-desktop.png', fullPage: true });

  // The booking page with the real availability calendar.
  const lawyerId = await page.evaluate(() => 1);
  await page.goto(BASE + '/booking.php?lawyer_id=1');
  await page.waitForTimeout(900);
  await page.screenshot({ path: OUT + '/booking-calendar-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: OUT + '/booking-calendar-mobile.png', fullPage: true });

  await browser.close();
  console.log('captured');
})();
