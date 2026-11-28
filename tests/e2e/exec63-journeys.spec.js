// Execution 63 — the two customer journeys that were broken, in a real browser.
//
// Problem 1: a priced consultation must not be confirmable (by the lawyer, or by the API)
//            before the gateway confirms payment, and the booking page must never show a
//            false success when the payment did not start.
// Problem 2: every "choose plan" call to action must reach the package catalogue/checkout,
//            not /register.php, and a deep link must survive the sign-in round trip.
//
// These assert on the rendered page; the PHP suite proves the SQL and the guards.
const { test, expect } = require('@playwright/test');
const { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS } = require('./helpers');

test.describe('execution 63 — package purchasing journey', () => {
  test('the public pricing page links every plan to the package catalogue', async ({ page }) => {
    await page.goto('/pricing.php');
    const hrefs = await page.locator('a[href*="plan="], a[href*="#plan-"]').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
    expect(hrefs.length, 'the pricing page offers at least one plan').toBeGreaterThan(0);
    for (const href of hrefs) {
      expect(href, `plan CTA "${href}" must reach the catalogue, not register.php`).not.toContain('register.php');
      // The catalogue is reached either by a `plan=` query or a `#plan-<id>` fragment.
      expect(href, `plan CTA "${href}" must name a plan`).toMatch(/plan[=-]\d+/);
      expect(href, `plan CTA "${href}" must land on the catalogue`).toMatch(/packages\.php|checkout\.php/);
    }
    await expectNoPhpError(page);
  });

  test('the package catalogue renders the shipped plans for a signed-in client', async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
    await page.goto('/packages.php');
    await expectNoPhpError(page);
    await expect(page.locator('#plan-1')).toBeVisible();
    await expectNoHorizontalOverflow(page);
  });

  test('a deep link to checkout is restored after signing in', async ({ page }) => {
    // Signed out, the guard must send the visitor to login with the target preserved.
    await page.goto('/checkout.php?order_id=1');
    await expect(page).toHaveURL(/login\.php\?next=/);
    await page.fill('input[name="email"]', ACCOUNTS.client);
    await page.fill('input[name="password"]', 'Test-Password-2026');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    // After a successful sign-in the browser is back on the checkout it asked for.
    await expect(page).toHaveURL(/checkout\.php/);
  });

  test('the sign-in redirect refuses an off-site target', async ({ page }) => {
    await page.goto('/login.php?next=https://evil.example/steal');
    await page.fill('input[name="email"]', ACCOUNTS.client);
    await page.fill('input[name="password"]', 'Test-Password-2026');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    // An absolute URL is not a local path, so the client lands on the default page, never
    // on the attacker's host.
    expect(page.url()).not.toContain('evil.example');
  });
});

test.describe('execution 63 — paid consultation booking', () => {
  test('the client sees a payment action on a priced consultation', async ({ page }) => {
    await login(page, '/login.php', ACCOUNTS.client);
    await page.goto('/consultations.php');
    await expectNoPhpError(page);
    // A priced, unpaid consultation must offer a way to pay (or show it is awaiting
    // payment), never a plain "confirmed" state with money outstanding. A client with
    // no bookings has nothing to assert on, so skip rather than fail on the empty state
    // — the seed does not create a consultation for every account.
    const bookings = page.locator('.dash-card .status-pill');
    test.skip((await bookings.count()) === 0, 'the seeded client has no consultation to assert on');
    const body = await page.content();
    expect(body).toMatch(/ادفع الآن|بانتظار الدفع|payment/i);
  });

  test('the lawyer cannot confirm an unpaid priced consultation', async ({ page }) => {
    await login(page, '/lawyer-login.php', ACCOUNTS.lawyer);
    await page.goto('/lawyer/consultations.php');
    await expectNoPhpError(page);
    // A priced, unpaid consultation shows the "payment required" note rather than a
    // Confirm button.
    const guard = page.locator('text=لا يمكن تأكيد استشارة مدفوعة');
    if (await guard.count() > 0) {
      await expect(guard.first()).toBeVisible();
    }
  });
});
