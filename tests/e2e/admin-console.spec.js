// Admin console — platform governance surfaces.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

const ADMIN_PAGES = [
  ['/admin/index.php', 'dashboard'],
  ['/admin/users.php', 'users'],
  ['/admin/lawyers.php', 'lawyers'],
  ['/admin/firms.php', 'firms'],
  ['/admin/invoices.php', 'invoices'],
  ['/admin/plans.php', 'plans'],
  ['/admin/settings.php', 'settings'],
  ['/admin/ai-operations.php', 'AI operations'],
  ['/admin/ai-control.php', 'AI control'],
  ['/admin/audit-log.php', 'audit log'],
  ['/admin/gateways.php', 'payment gateways'],
  ['/admin/integrations.php', 'integrations'],
];

test.describe('admin console', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/admin/login.php', ACCOUNTS.admin);
  });

  for (const [path, label] of ADMIN_PAGES) {
    test(`${label} renders for the platform admin`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response.status(), `${path} status`).toBe(200);
      await expectNoPhpError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('an unauthenticated visitor cannot reach the admin console', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/admin/index.php');
    expect(page.url()).toContain('login.php');
  });
});
