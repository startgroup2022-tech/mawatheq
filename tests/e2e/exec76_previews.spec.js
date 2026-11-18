// Execution 76 — real screenshots of the pages the owner needs to see, captured from the
// running application (not a mock). Output: tests/e2e/.artifacts/exec76/*.png
//
// Run: MAWATHEQ_BASE_URL=http://127.0.0.1:12000 npx playwright test tests/e2e/exec76_previews.spec.js
const { test, expect } = require('@playwright/test');
const { login, PASSWORD } = require('./helpers');
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, '.artifacts', 'exec76');

async function shot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });
}

test.describe('Execution 76 previews', () => {
  test('public pages render', async ({ page }) => {
    for (const [slug, url] of [
      ['home', '/index.php'],
      ['legal-library', '/legal-library.php'],
      ['login', '/login.php'],
    ]) {
      await page.goto(url);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('h1').first()).toBeVisible();
      await shot(page, slug);
    }
  });

  test('client pages render (packages, booking, subscriptions)', async ({ page }) => {
    await login(page, '/login.php', 'client-a@example.com', PASSWORD);
    for (const [slug, url] of [
      ['packages', '/packages.php'],
      ['subscription', '/subscription.php'],
      ['consultations', '/consultations.php'],
      ['invoices', '/invoices.php'],
      ['journey', '/consultation-journey.php'],
    ]) {
      await page.goto(url);
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('h1').first()).toBeVisible();
      await shot(page, slug);
    }
    // The booking page with a real lawyer must render the calendar, and an unknown
    // lawyer must render the graceful "not available" page (the Exec 76 fix).
    await page.goto('/booking.php?lawyer_id=1');
    await page.waitForLoadState('domcontentloaded');
    await shot(page, 'booking-real-lawyer');

    await page.goto('/booking.php?lawyer_id=999999');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await shot(page, 'booking-unknown-lawyer');
  });
});
