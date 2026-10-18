// Public (unauthenticated) site — renders in both directions and stays usable on small screens.
const { test, expect } = require('@playwright/test');
const { expectNoPhpError, expectNoHorizontalOverflow } = require('./helpers');

const PUBLIC_PAGES = [
  ['/', 'home'],
  ['/laws.php', 'laws'],
  ['/pricing.php', 'pricing'],
  ['/knowledge.php', 'knowledge'],
  ['/contact.php', 'contact'],
  ['/terms.php', 'terms'],
  ['/privacy.php', 'privacy'],
  ['/login.php', 'client login'],
  ['/lawyer-login.php', 'lawyer login'],
  ['/register.php', 'registration'],
];

test.describe('public site', () => {
  for (const [path, label] of PUBLIC_PAGES) {
    test(`${label} renders without errors`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response.status(), `${path} status`).toBeLessThan(400);
      await expectNoPhpError(page);
      await expect(page.locator('body')).toBeVisible();
    });
  }

  test('home page declares Arabic RTL by default', async ({ page }) => {
    await page.goto('/');
    const html = page.locator('html');
    await expect(html).toHaveAttribute('dir', 'rtl');
    await expect(html).toHaveAttribute('lang', 'ar');
  });

  test('language switch flips the document to English LTR', async ({ page }) => {
    await page.goto('/');
    await page.locator('a.lang-switch').first().click();
    await page.waitForLoadState('domcontentloaded');
    const html = page.locator('html');
    await expect(html).toHaveAttribute('dir', 'ltr');
    await expect(html).toHaveAttribute('lang', 'en');
  });

  test('home page has no horizontal overflow', async ({ page }) => {
    await page.goto('/');
    await expectNoHorizontalOverflow(page);
  });
});
