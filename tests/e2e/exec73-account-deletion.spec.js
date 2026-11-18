// Execution 73 — the public account-deletion page (Google Play Data safety).
//
// Google Play rejects a Data safety form whose account-deletion link is invalid. The
// link must be reachable by a logged-out visitor, must actually offer deletion, and
// must disclose what is retained. This spec drives the real page at several viewports
// in both directions, and asserts the request form produces a tracking number.
//
// It does not sign in: the whole point is that the page works without an account.

const { test, expect } = require('@playwright/test');
const { expectNoPhpError, expectNoHorizontalOverflow } = require('./helpers');

const VIEWPORTS = [
  { width: 320, height: 568 },
  { width: 375, height: 812 },
  { width: 768, height: 1024 },
  { width: 1440, height: 900 },
];

test.describe('public account-deletion page', () => {
  for (const vp of VIEWPORTS) {
    test(`renders with no overflow at ${vp.width}x${vp.height}`, async ({ page }) => {
      await page.setViewportSize(vp);
      const resp = await page.goto('/account-deletion.php');
      expect(resp.status()).toBe(200);
      await expectNoPhpError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('is reachable at the clean URL and offers a real form', async ({ page }) => {
    const resp = await page.goto('/account-deletion');
    expect(resp.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('حذف الحساب');
    await expect(page.locator('input[name="identifier"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]').first()).toBeVisible();
    // The retention disclosure is present — the Data safety claim depends on it.
    await expect(page.getByText('يُحفظ لأسباب ملزمة')).toBeVisible();
    // Exactly one h1, as the accessibility spec requires.
    expect(await page.locator('h1').count()).toBe(1);
  });

  test('renders in English when asked', async ({ page }) => {
    await page.goto('/account-deletion.php?lang=en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('h1')).toContainText('Delete your account');
  });

  test('submitting the form returns a tracking number', async ({ page }) => {
    await page.goto('/account-deletion.php');
    await page.fill('input[name="identifier"]', 'play-console-probe@example.test');
    await page.check('input[name="ack"]');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.getByText('رقم المتابعة')).toBeVisible();
    await expect(page.locator('.adr-ref')).toContainText('DEL-');
  });
});
