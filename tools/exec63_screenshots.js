// Execution 63 before/after evidence capture.
const { chromium } = require('playwright');

async function login(page, path, email, password) {
  await page.goto('http://127.0.0.1:8080' + path);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'ar' });
  const page = await ctx.newPage();

  await login(page, '/login.php', 'client-a@example.com', 'Test-Password-2026');
  await page.goto('http://127.0.0.1:8080/packages.php');
  await page.waitForTimeout(800);
  await page.screenshot({ path: '/tmp/exec63-shots/packages-desktop.png' });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(400);
  await page.screenshot({ path: '/tmp/exec63-shots/packages-mobile.png' });

  // Checkout summary for a paid plan
  await page.setViewportSize({ width: 1366, height: 900 });
  await page.goto('http://127.0.0.1:8080/checkout.php?order_id=1');
  await page.waitForTimeout(500);
  await page.screenshot({ path: '/tmp/exec63-shots/checkout-desktop.png' });

  // Client consultations with the payment-due alert
  await page.goto('http://127.0.0.1:8080/consultations.php');
  await page.waitForTimeout(500);
  await page.screenshot({ path: '/tmp/exec63-shots/consultations-desktop.png' });

  await browser.close();
  console.log('captured');
})();
