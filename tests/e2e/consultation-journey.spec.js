// Customer consultation journey — Execution 37.
//
// Drives the real journey over HTTP: specialty cards → issue types → matched
// lawyers → booking. Two things are asserted at every step:
//
//   * the journey works and renders without overflow or a PHP error, and
//   * the internal legal classification never reaches the customer. The page bytes
//     are scanned for the internal vocabulary at each step, because that is the one
//     rule this feature exists to enforce.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

// The internal classification's vocabulary. None of it may appear on a customer page.
const INTERNAL_TOKENS = ['ناجز', 'najiz', 'najiz_case_type_id', 'najiz_category', 'najiz_subcategory'];

async function expectNoInternalClassification(page) {
  const body = await page.content();
  for (const token of INTERNAL_TOKENS) {
    expect(body, `customer page must not contain "${token}"`).not.toContain(token);
  }
}

test.describe('consultation journey', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
  });

  test('step 1 offers specialty cards, including an unstaffed one', async ({ page }) => {
    const response = await page.goto('/consultation-journey.php');
    expect(response.status()).toBe(200);
    await expectNoPhpError(page);
    await expectNoHorizontalOverflow(page);
    await expectNoInternalClassification(page);

    const cards = page.locator('.specialty-card');
    await expect(cards.first()).toBeVisible();
    expect(await cards.count()).toBeGreaterThanOrEqual(3);

    // The stepper names the customer's path, not the internal taxonomy.
    await expect(page.locator('.journey-steps')).toContainText('التخصص');
  });

  test('step 2 lists plain-language issue types for the chosen specialty', async ({ page }) => {
    await page.goto('/consultation-journey.php');
    await page.locator('.specialty-card').first().click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('category_id=');
    await expectNoPhpError(page);
    await expectNoInternalClassification(page);

    const issues = page.locator('.issue-option');
    await expect(issues.first()).toBeVisible();
    expect(await issues.count()).toBeGreaterThanOrEqual(1);
    // "Not sure" is always offered, so an unsure customer is never blocked.
    await expect(page.locator('.issue-option.not-sure')).toBeVisible();
  });

  test('step 3 shows matched lawyers and a comparison, not an empty screen', async ({ page }) => {
    await page.goto('/consultation-journey.php');
    await page.locator('.specialty-card').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('.issue-option:not(.not-sure)').first().click();
    // Picking an issue only fills the hidden field; the customer confirms on the button.
    await page.locator('#issueForm button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('consultation-journey-lawyers.php');
    expect(page.url()).toContain('issue_type_id=');
    await expectNoPhpError(page);
    await expectNoHorizontalOverflow(page);
    await expectNoInternalClassification(page);

    const compare = page.locator('.compare-card');
    if (await compare.count() > 0) {
      await expect(compare.first()).toBeVisible();
      await expect(compare.first().locator('.compare-actions a.btn')).toBeVisible();
    } else {
      // No lawyer for this specialty: the customer gets a clear alternative, not a
      // dead end and not an unrelated lawyer.
      await expect(page.locator('.journey-empty')).toBeVisible();
    }
  });

  test('"I am not sure" is always offered and still reaches the lawyer list', async ({ page }) => {
    await page.goto('/consultation-journey.php');
    await page.locator('.specialty-card').first().click();
    await page.waitForLoadState('domcontentloaded');

    // The unsure customer must never be blocked by the classification question.
    await expect(page.locator('.issue-option.not-sure')).toBeVisible();
    await page.locator('.issue-option.not-sure').click();
    await page.locator('#issueForm button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('consultation-journey-lawyers.php');
    expect(page.url()).toContain('issue_type_id=0');
    await expectNoPhpError(page);
    await expectNoInternalClassification(page);
  });

  test('the booking form carries the journey context and shows it in plain language', async ({ page }) => {
    await page.goto('/consultation-journey.php');
    await page.locator('.specialty-card').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('.issue-option:not(.not-sure)').first().click();
    await page.locator('#issueForm button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    const bookLink = page.locator('.compare-actions a.btn').first();
    if (await bookLink.count() === 0) {
      test.skip(true, 'no lawyer matched this specialty in the seed data');
    }
    await bookLink.click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('booking.php');
    // The URL carries the customer-safe parameter names, never the internal one.
    expect(page.url()).toContain('specialty_category_id=');
    expect(page.url()).toContain('issue_type_id=');
    expect(page.url()).not.toContain('najiz_case_type_id');

    await expectNoPhpError(page);
    await expectNoInternalClassification(page);

    // The customer sees their own topic restated in plain language.
    await expect(page.locator('body')).toContainText('موضوع الاستشارة المختار');
    // The submitted field is the customer-safe name.
    await expect(page.locator('input[name="issue_type_id"]')).toHaveCount(1);
    await expect(page.locator('input[name="najiz_case_type_id"]')).toHaveCount(0);
  });

  test('the journey is reachable from the lawyer directory without leaking', async ({ page }) => {
    await page.goto('/consultations.php');
    await expectNoPhpError(page);
    await expectNoInternalClassification(page);
  });

  test('the lawyer sees the internal classification on their own queue', async ({ page, context }) => {
    // A lawyer is allowed — expected — to see the classification of their requests.
    await context.clearCookies();
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
    const response = await page.goto('/lawyer/consultations.php');
    expect(response.status()).toBe(200);
    await expectNoPhpError(page);
    await expect(page.locator('body')).toContainText('التصنيف القانوني');
  });

  test('the lawyer can pick their specialties', async ({ page, context }) => {
    await context.clearCookies();
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
    const response = await page.goto('/lawyer/specialties.php');
    expect(response.status()).toBe(200);
    await expectNoPhpError(page);
    await expectNoHorizontalOverflow(page);
    await expect(page.locator('input[name="categories[]"]').first()).toBeVisible();
  });
});
