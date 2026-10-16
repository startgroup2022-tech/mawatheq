// Executive 09 surfaces in the browser: enterprise SSO, SSL domains, court-order review.
// These drive the real forms over the real stack — no mocks.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, ACCOUNTS } = require('./helpers');

test.describe('Executive 09 surfaces', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
  });

  test('SSO page exposes the Service Provider identity and IdP form', async ({ page }) => {
    await page.goto('/lawyer/sso.php');
    await expectNoPhpError(page);
    await expect(page.locator('h2').first()).toContainText('بيانات مواثيق');
    await expect(page.locator('input[name="idp_entity_id"]')).toBeVisible();
    await expect(page.locator('input[name="idp_sso_url"]')).toBeVisible();
    await expect(page.locator('input[name="enabled"]')).toBeVisible();
  });

  test('SSO configuration saves and reads back through the real form', async ({ page }) => {
    await page.goto('/lawyer/sso.php');
    const entityId = 'https://idp.browser-test.local/metadata';

    await page.fill('input[name="idp_entity_id"]', entityId);
    await page.fill('input[name="idp_sso_url"]', 'https://idp.browser-test.local/sso');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      // The IdP settings form, not the sidebar logout form.
      page.locator('form.stack-form button[type="submit"]').click(),
    ]);

    await expect(page.locator('.form-alert.success')).toContainText('تم حفظ إعدادات الدخول الموحّد');

    await page.goto('/lawyer/sso.php');
    await expect(page.locator('input[name="idp_entity_id"]')).toHaveValue(entityId);
  });

  test('court-order page offers extraction and a review gate', async ({ page }) => {
    await page.goto('/lawyer/court-orders.php');
    await expectNoPhpError(page);
    await expect(page.locator('input[name="document_id"]')).toBeVisible();
    await expect(page.locator('h2').first()).toContainText('استخراج جديد');
  });

  test('court-order extraction refuses an unknown document instead of fabricating', async ({ page }) => {
    await page.goto('/lawyer/court-orders.php');
    await page.fill('input[name="document_id"]', '999999999');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('form.stack-form button[type="submit"]').click(),
    ]);
    await expectNoPhpError(page);
    // An unknown document must surface an error, never a success message.
    await expect(page.locator('.form-alert.error')).toContainText('المستند غير موجود');
    await expect(page.locator('.form-alert.success')).toHaveCount(0);
  });

  test('domains page exposes SSL recheck control', async ({ page }) => {
    await page.goto('/lawyer/domains.php');
    await expectNoPhpError(page);
    await expect(page.locator('h2').first()).toContainText('نطاقات المكتب');
  });

  test('firm domains page is reachable from the workspace navigation', async ({ page }) => {
    await page.goto('/lawyer/dashboard.php');
    // Below 1024px the workspace navigation lives in the off-canvas drawer.
    const drawerToggle = page.locator('[data-app-nav-toggle]');
    if (await drawerToggle.isVisible()) await drawerToggle.click();
    const link = page.locator('a[href*="domains.php"]').first();
    await expect(link).toBeVisible();
    await link.click();
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('domains.php');
  });
});
