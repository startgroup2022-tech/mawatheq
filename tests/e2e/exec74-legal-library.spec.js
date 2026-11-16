// Execution 74 — the public legal & judicial library.
//
// The library is public content, so a visitor with no account must be able to reach
// the hub, search it, and open an item. Saving an item is the only action that asks
// for a sign-in, and the saved list must never be served to a guest.
const { test, expect } = require('@playwright/test');
const { expectNoPhpError, expectNoHorizontalOverflow, login, ACCOUNTS } = require('./helpers');

test.describe('legal & judicial library (public)', () => {
  test('the hub renders and names the library', async ({ page }) => {
    const response = await page.goto('/legal-library.php');
    expect(response.status()).toBeLessThan(400);
    await expectNoPhpError(page);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('body')).toContainText('المكتبة');
  });

  test('the hub has no horizontal overflow', async ({ page }) => {
    await page.goto('/legal-library.php');
    await expectNoHorizontalOverflow(page);
  });

  test('search renders and offers its filter controls', async ({ page }) => {
    const response = await page.goto('/legal-library-search.php');
    expect(response.status()).toBeLessThan(400);
    await expectNoPhpError(page);
    await expect(page.locator('form')).toHaveCount(1);
    await expectNoHorizontalOverflow(page);
  });

  test('an unknown item id does not 500', async ({ page }) => {
    const response = await page.goto('/legal-library-item.php?id=999999999');
    // A missing item is a friendly not-found, never a server error.
    expect(response.status()).toBeLessThan(500);
    await expectNoPhpError(page);
  });

  test('the favorites page refuses a guest and sends them to sign in', async ({ page }) => {
    await page.goto('/legal-library-favorites.php');
    // The client guard redirects an anonymous visitor to the login page.
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login');
  });
});

test.describe('legal & judicial library (signed in)', () => {
  test('a client can open the library hub from the client portal', async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
    const response = await page.goto('/legal-library.php');
    expect(response.status()).toBeLessThan(400);
    await expectNoPhpError(page);
    await expect(page.locator('h1')).toHaveCount(1);
  });

  test('a signed-in client sees the saved-items page', async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
    const response = await page.goto('/legal-library-favorites.php');
    expect(response.status()).toBeLessThan(400);
    await expectNoPhpError(page);
  });
});

test.describe('legal & judicial library (English mode)', () => {
  test('the hub renders in LTR English', async ({ page }) => {
    await page.goto('/legal-library.php?lang=en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expectNoPhpError(page);
    await expect(page.locator('h1')).toHaveCount(1);
  });
});
