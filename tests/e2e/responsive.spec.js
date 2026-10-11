// Responsive behaviour: the mobile navigation must open and close on every surface,
// and no page may scroll sideways at any supported width.
const { test, expect } = require('@playwright/test');
const { login, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

const WIDTHS = [320, 375, 768, 1024, 1440, 1920];

test.describe('responsive layout', () => {
  for (const width of WIDTHS) {
    test(`public home has no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expectNoHorizontalOverflow(page);
    });

    test(`lawyer workspace has no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
      await page.goto('/lawyer/conflicts.php');
      await expectNoHorizontalOverflow(page);
    });
  }

  test('the public mobile menu opens and closes', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    const toggle = page.locator('[data-nav-toggle]');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  });

  test('the lawyer sidebar opens and closes on a small screen', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
    await page.goto('/lawyer/dashboard.php');

    const toggle = page.locator('[data-app-nav-toggle]');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(page.locator('body')).toHaveClass(/app-nav-open/);
    await expect(page.locator('#appSidebar')).toBeInViewport();

    // The scrim is the documented way to dismiss the drawer.
    await page.locator('.app-scrim').click({ position: { x: 20, y: 400 } });
    await expect(page.locator('body')).not.toHaveClass(/app-nav-open/);
  });
});
