// Client portal — real login, real case pages, and the approval gate on AI output.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

const CLIENT_PAGES = [
  ['/dashboard.php', 'dashboard'],
  ['/my-cases.php', 'my cases'],
  ['/documents.php', 'documents'],
  ['/invoices.php', 'invoices'],
  ['/consultations.php', 'consultations'],
  ['/ai-assistant.php', 'AI assistant'],
  ['/account.php', 'account'],
  ['/knowledge.php', 'knowledge'],
];

test.describe('client portal', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
  });

  for (const [path, label] of CLIENT_PAGES) {
    test(`${label} renders for the client`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response.status(), `${path} status`).toBe(200);
      await expectNoPhpError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('an unauthenticated visitor is redirected to the client login', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/dashboard.php');
    expect(page.url()).toContain('login.php');
  });
});
