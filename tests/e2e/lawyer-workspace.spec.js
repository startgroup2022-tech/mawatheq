// Lawyer workspace — the Executive 08/09 surfaces, driven through a real login.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

const LAWYER_PAGES = [
  ['/lawyer/dashboard.php', 'dashboard'],
  ['/lawyer/cases.php', 'cases'],
  ['/lawyer/consultations.php', 'consultations'],
  ['/lawyer/calendar.php', 'calendar'],
  ['/lawyer/invoices.php', 'invoices'],
  ['/lawyer/drafting.php', 'drafting'],
  ['/lawyer/conflicts.php', 'conflict screening'],
  ['/lawyer/hr.php', 'HR'],
  ['/lawyer/branches.php', 'branches'],
  ['/lawyer/domains.php', 'domains'],
  ['/lawyer/ai-copilot.php', 'AI copilot'],
  ['/lawyer/court-orders.php', 'court-order extraction'],
  ['/lawyer/sso.php', 'enterprise SSO'],
  ['/lawyer/review-queue.php', 'review queue'],
  ['/lawyer/firm.php', 'firm profile'],
];

test.describe('lawyer workspace', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
  });

  for (const [path, label] of LAWYER_PAGES) {
    test(`${label} renders for the firm owner`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response.status(), `${path} status`).toBe(200);
      await expectNoPhpError(page);
      await expectNoHorizontalOverflow(page);
    });
  }

  test('dashboard greets the signed-in lawyer', async ({ page }) => {
    await page.goto('/lawyer/dashboard.php');
    await expect(page.locator('h1')).toContainText('مرحباً');
  });

  test('workspace can be switched to English', async ({ page }) => {
    await page.goto('/lawyer/dashboard.php');
    await page.locator('a.lang-switch').first().click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await page.goto('/lawyer/conflicts.php');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
  });

  test('an unauthenticated visitor is redirected to the lawyer login', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/lawyer/dashboard.php');
    expect(page.url()).toContain('lawyer-login.php');
  });

  test('cross-tenant isolation: firm B owner cannot read firm A conflict review', async ({ page, browser }) => {
    // Firm A registers a party and screens the same name, which opens a review.
    const party = 'شركة عزل الاختبار ' + Date.now();
    await page.goto('/lawyer/conflicts.php');
    const token = await page.locator('input[name="csrf_token"]').first().inputValue();

    await page.evaluate(async ([tok, name]) => {
      const send = (body) => fetch('/lawyer/conflicts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body).toString(),
      });
      await send({ csrf_token: tok, action: 'add_party', party_type: 'client', name_ar: name });
      await send({ csrf_token: tok, action: 'screen', subject_type: 'opposing_party', subject_name_ar: name });
    }, [token, party]);

    await page.goto('/lawyer/conflicts.php');
    const openHref = await page.locator('a[href^="?review="]').first().getAttribute('href');
    expect(openHref, 'firm A must have an open review link').toBeTruthy();
    const reviewId = Number(openHref.replace('?review=', ''));

    // Firm A sees the review; firm B must not.
    await page.goto(`/lawyer/conflicts.php?review=${reviewId}`);
    await expect(page.locator('#review h2')).toContainText(`مراجعة تعارض #${reviewId}`);

    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();
    await login(pageB, '/lawyer-login.php', ACCOUNTS.lawyerB);
    await pageB.goto(`/lawyer/conflicts.php?review=${reviewId}`);
    await expect(pageB.locator('body')).not.toContainText(`مراجعة تعارض #${reviewId}`);
    await contextB.close();
  });

  test('domains page exposes the SSL controls added in Executive 09', async ({ page }) => {
    await page.goto('/lawyer/domains.php');
    await expectNoPhpError(page);
    await expect(page.locator('h2')).toContainText('نطاقات المكتب');
  });
});
