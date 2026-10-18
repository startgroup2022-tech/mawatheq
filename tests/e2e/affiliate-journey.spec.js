// Affiliate programme, driven through the real UI from the public form to a working
// referral link.
//
// This is the journey the affiliate system is for, and the one that was broken: a
// person could register and then never sign in again, an operator could "approve" an
// application without the account moving, and an approved account had no route to
// activation, so its referral link never appeared. Each test below pins one of those
// steps so a regression fails here instead of in production.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { ACCOUNTS, PASSWORD, login, expectNoPhpError } = require('./helpers');

const uniqueEmail = (prefix) => `${prefix}-${Date.now()}-${Math.floor(Math.random() * 1e4)}@example.com`;

// Query the test database through the application's own PDO configuration, so the
// spec uses the same credentials and database the server does. The `mariadb` client
// is not usable here: the local OS user has no MySQL account, while the PHP CLI does.
const CONFIG = path.resolve(__dirname, '../../config/config.php');
const php = (code, query) => execFileSync('php', ['-r', code], {
  encoding: 'utf8',
  env: { ...process.env, Q: query },
}).trim();

const dbScalar = (query) => php(
  `require ${JSON.stringify(CONFIG)}; $r=$pdo->query(getenv('Q')); echo $r ? (string)$r->fetchColumn() : '';`,
  query);
const dbExec = (query) => php(
  `require ${JSON.stringify(CONFIG)}; $pdo->exec(getenv('Q'));`,
  query);

test.beforeAll(() => {
  // The programme is disabled by default, so the public registration form is hidden
  // until an operator enables it. This spec drives the real form, so it enables the
  // programme in the test database first.
  dbExec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('affiliate_program_enabled','1') "
    + 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
  // Clear the per-address creation budget. The suite runs this file once per viewport,
  // which is far more registrations from 127.0.0.1 than a real address would produce;
  // leaving the counter in place would make the harness throttle itself.
  dbExec("DELETE FROM auth_rate_limits WHERE scope_key=SHA2(CONCAT('affiliate-register-created|ip|127.0.0.1'),256)");
  // The public programme form is limited per address too, and one application per
  // viewport run is enough to trip it. auth_rate_limit_remaining() checks two buckets
  // per scope and each derives two keys from the address (`auth-ip|` and
  // `auth-account|`), so all four are cleared.
  dbExec("DELETE FROM auth_rate_limits WHERE scope_key IN ("
    + "SHA2(CONCAT('auth-ip|affiliate-apply|127.0.0.1'),256),"
    + "SHA2(CONCAT('auth-account|affiliate-apply|127.0.0.1'),256),"
    + "SHA2(CONCAT('auth-ip|affiliate-apply-ip|127.0.0.1'),256),"
    + "SHA2(CONCAT('auth-account|affiliate-apply-ip|127.0.0.1'),256))");
});

async function registerAffiliate(page, email, fullName = 'مسوّق اختبار') {
  await page.goto('/affiliate/register.php');
  await page.fill('input[name="full_name"]', fullName);
  await page.fill('input[name="email"]', email);
  await page.fill('#affRegPassword', PASSWORD);
  await page.fill('#affRegPasswordConfirm', PASSWORD);
  await page.check('input[name="accepted_terms"]');
  await page.check('input[name="accepted_privacy"]');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
}

test.describe('affiliate journey', () => {
  test('registration signs the applicant in and states that review is pending', async ({ page }) => {
    const email = uniqueEmail('e2e-reg');
    await registerAffiliate(page, email);

    // A successful registration must land on the dashboard, not back on the form.
    await expect(page).toHaveURL(/affiliate\/dashboard\.php/);
    await expectNoPhpError(page);

    const body = await page.content();
    expect(body, 'the applicant is told their account is under review').toContain('قيد المراجعة');
    // Nothing may be offered that cannot work yet.
    await expect(page.locator('#affMainLink')).toHaveCount(0);
  });

  test('a pending applicant can sign in again', async ({ page, context }) => {
    const email = uniqueEmail('e2e-relogin');
    await registerAffiliate(page, email);
    await expect(page).toHaveURL(/affiliate\/dashboard\.php/);

    // Drop the session entirely: this is the re-login the account must support.
    await context.clearCookies();
    await login(page, '/affiliate/login.php', email);
    await page.waitForLoadState('domcontentloaded');

    expect(page.url(), 'signing in must not bounce back to the login page').toContain('dashboard.php');
    await expectNoPhpError(page);
    const body = await page.content();
    expect(body).toContain('قيد المراجعة');
  });

  test('a pending account cannot reach the referral link or activate itself', async ({ page }) => {
    const email = uniqueEmail('e2e-restrict');
    await registerAffiliate(page, email);

    await page.goto('/affiliate/dashboard.php');
    await expect(page.locator('#affMainLink')).toHaveCount(0);

    // The terms page must not offer the acceptance that would activate an unreviewed
    // account — otherwise anyone could approve themselves.
    await page.goto('/affiliate/terms.php');
    await expect(page.locator('form:has(input[name="action"][value="accept"])')).toHaveCount(0);
  });

  test('an operator approval activates the account through terms acceptance', async ({ page, browser }) => {
    const email = uniqueEmail('e2e-approve');

    // 1. the applicant registers
    await registerAffiliate(page, email);
    await expect(page).toHaveURL(/affiliate\/dashboard\.php/);

    // 2. the operator approves the application from the review queue
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await login(admin, '/admin/login.php', ACCOUNTS.admin);
    await admin.goto('/admin/affiliate-applications.php');
    await expectNoPhpError(admin);

    const row = admin.locator('tr', { hasText: email });
    await expect(row, 'the application appears in the review queue').toHaveCount(1);
    await row.locator('input[name="compliance_approved"]').check();
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      row.locator('button[value="approve"]').click(),
    ]);
    await adminContext.close();

    expect(dbScalar(`SELECT status FROM affiliates WHERE email='${email}'`)).toBe('approved');

    // 3. the applicant sees the approved state and the activation call to action
    await page.goto('/affiliate/dashboard.php');
    const approved = await page.content();
    expect(approved, 'the status reads approved').toContain('معتمد');
    expect(approved, 'the activation step is offered').toContain('قبول الشروط والتفعيل');
    // Still no link: approval alone does not make an account able to earn.
    await expect(page.locator('#affMainLink')).toHaveCount(0);

    // 4. accepting the terms activates the account
    await page.goto('/affiliate/terms.php');
    const acceptForm = page.locator('form:has(input[name="action"][value="accept"])');
    await expect(acceptForm).toHaveCount(1);
    await acceptForm.locator('input[name="accepted_terms"]').check();
    await acceptForm.locator('input[name="accepted_privacy"]').check();
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      acceptForm.locator('button[type="submit"]').click(),
    ]);

    expect(dbScalar(`SELECT status FROM affiliates WHERE email='${email}'`)).toBe('active');
    await expectNoPhpError(page);
  });

  test('the referral link an active affiliate receives attributes a visit', async ({ page, browser }) => {
    const email = uniqueEmail('e2e-link');

    // Reach an active account through the real journey.
    await registerAffiliate(page, email);
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await login(admin, '/admin/login.php', ACCOUNTS.admin);
    await admin.goto('/admin/affiliate-applications.php');
    const row = admin.locator('tr', { hasText: email });
    await row.locator('input[name="compliance_approved"]').check();
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      row.locator('button[value="approve"]').click(),
    ]);
    await adminContext.close();

    await page.goto('/affiliate/terms.php');
    const acceptForm = page.locator('form:has(input[name="action"][value="accept"])');
    await acceptForm.locator('input[name="accepted_terms"]').check();
    await acceptForm.locator('input[name="accepted_privacy"]').check();
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      acceptForm.locator('button[type="submit"]').click(),
    ]);

    // The dashboard now hands out a link.
    await page.goto('/affiliate/dashboard.php');
    const linkInput = page.locator('#affMainLink');
    await expect(linkInput).toHaveCount(1);
    const link = await linkInput.inputValue();
    expect(link, 'the link carries the affiliate code').toMatch(/\?ref=[A-Z0-9]+/);
    expect(link, 'the link must stay on the platform host').toMatch(/^https?:\/\/[^/]+\//);

    const clicksBefore = Number(dbScalar(
      `SELECT COUNT(*) FROM affiliate_clicks c JOIN affiliates f ON f.id=c.affiliate_id WHERE f.email='${email}'`) || 0);

    // Follow it as an unrelated anonymous visitor.
    const visitorContext = await browser.newContext();
    const visitor = await visitorContext.newPage();
    const response = await visitor.goto(link);
    expect(response.status(), 'the referral destination must render').toBeLessThan(400);
    await expectNoPhpError(visitor);
    expect((await visitorContext.cookies()).some((c) => /ref|aff/i.test(c.name)),
      'the referral is remembered for later attribution').toBe(true);

    const clicksAfter = Number(dbScalar(
      `SELECT COUNT(*) FROM affiliate_clicks c JOIN affiliates f ON f.id=c.affiliate_id WHERE f.email='${email}'`) || 0);
    expect(clicksAfter, 'the visit is recorded against the affiliate').toBeGreaterThan(clicksBefore);
  });

  test('an unauthenticated visitor cannot open the affiliate portal', async ({ page }) => {
    for (const path of ['/affiliate/dashboard.php', '/affiliate/terms.php', '/affiliate/links.php']) {
      const response = await page.goto(path);
      expect(page.url(), `${path} must redirect an anonymous visitor`).toMatch(/affiliate\/login\.php/);
      expect(response.status(), `${path} must not be served`).toBeLessThan(400);
    }
  });

  test('the review queue is not served without an admin session', async ({ page }) => {
    const response = await page.goto('/admin/affiliate-applications.php');
    expect(page.url(), 'an anonymous visitor is sent to the admin login').toMatch(/admin\/login\.php/);
    expect(response.status()).toBeLessThan(400);
  });

  // The public landing page takes an application without creating an account, so the
  // applicant never chooses a password. Approval then creates an account nobody can sign
  // into, and the invitation is the only route to credentials — but the invitation path
  // was broken three ways: it could not create an invitation at all (a NOT NULL column
  // received null), it refused any email that already had an account (exactly this case),
  // and accepting one pre-recorded the agreement, which left the terms page with no form
  // and the partner permanently `approved` and unable to earn.
  test('a public-form applicant is approved, invited, signs in and activates', async ({ page, browser }) => {
    const email = uniqueEmail('e2e-public');

    // 1. apply through the landing page's own form
    await page.goto('/affiliates.php');
    await page.fill('input[name="full_name"]', 'متقدم الصفحة العامة');
    await page.fill('input[name="email"]', email);
    await page.check('input[name="accepted_terms"]');
    await page.check('input[name="accepted_privacy"]');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.click('#apply button[type="submit"]'),
    ]);
    await expectNoPhpError(page);
    expect(await page.content(), 'the application is acknowledged').toContain('تم استلام طلبك');

    // The form does not create an account: there is nothing to sign in with yet.
    expect(dbScalar(`SELECT status FROM affiliate_applications WHERE email='${email}'`)).toBe('submitted');

    // 2. the operator approves it from the review queue
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await login(admin, '/admin/login.php', ACCOUNTS.admin);
    await admin.goto('/admin/affiliate-applications.php');
    const pending = admin.locator('tr', { hasText: email });
    await expect(pending).toHaveCount(1);
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      pending.locator('button[value="approve"]').click(),
    ]);

    const affiliateId = dbScalar(`SELECT affiliate_id FROM affiliate_applications WHERE email='${email}'`);
    expect(affiliateId, 'approval creates the account').toMatch(/^\d+$/);
    expect(dbScalar(`SELECT status FROM affiliates WHERE email='${email}'`)).toBe('approved');
    expect(dbScalar(`SELECT COALESCE(password_hash,'NULL') FROM affiliates WHERE email='${email}'`),
      'the account exists without credentials').toBe('NULL');

    // 3. the operator issues the invitation from the same queue
    await admin.goto('/admin/affiliate-applications.php?status=approved');
    const approved = admin.locator('tr', { hasText: email });
    await expect(approved).toHaveCount(1);
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      approved.locator('button[value="invite"]').click(),
    ]);
    const withLink = await admin.content();
    const inviteUrl = (withLink.match(/value="(https?:\/\/[^"]*accept-invite\.php\?token=[a-f0-9]+)"/) || [])[1];
    expect(inviteUrl, 'the one-time invitation link is shown for the operator to send').toBeTruthy();
    await adminContext.close();

    // 4. the applicant follows the invitation and sets a password
    const inviteContext = await browser.newContext();
    const invitee = await inviteContext.newPage();
    const inviteResponse = await invitee.goto(inviteUrl);
    expect(inviteResponse.status()).toBeLessThan(400);
    await invitee.fill('input[name="password"]', PASSWORD);
    await invitee.fill('input[name="password_confirm"]', PASSWORD);
    await invitee.check('input[name="accepted_terms"]');
    await Promise.all([
      invitee.waitForLoadState('domcontentloaded'),
      invitee.click('button[type="submit"]'),
    ]);
    await expectNoPhpError(invitee);
    expect(dbScalar(`SELECT COALESCE(password_hash,'NULL') FROM affiliates WHERE email='${email}'`),
      'the applicant now has credentials').not.toBe('NULL');
    expect(dbScalar(`SELECT COALESCE(accepted_at,'') FROM affiliate_invitations WHERE email='${email}' ORDER BY id DESC LIMIT 1`),
      'the invitation is consumed').not.toBe('');

    // 5. and can finally sign in
    await login(invitee, '/affiliate/login.php', email);
    await invitee.waitForLoadState('domcontentloaded');
    expect(invitee.url(), 'the invited applicant reaches the dashboard').toContain('dashboard.php');
    await expectNoPhpError(invitee);

    // 6. accepting the terms there activates the account, exactly as for a registrant
    await invitee.goto('/affiliate/terms.php');
    const acceptForm = invitee.locator('form:has(input[name="action"][value="accept"])');
    await expect(acceptForm, 'the terms form is still owed to the invited applicant').toHaveCount(1);
    await acceptForm.locator('input[name="accepted_terms"]').check();
    await acceptForm.locator('input[name="accepted_privacy"]').check();
    await Promise.all([
      invitee.waitForLoadState('domcontentloaded'),
      acceptForm.locator('button[type="submit"]').click(),
    ]);
    expect(dbScalar(`SELECT status FROM affiliates WHERE email='${email}'`)).toBe('active');
    await expectNoPhpError(invitee);

    await inviteContext.close();
  });
});
