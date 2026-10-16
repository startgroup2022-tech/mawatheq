// Shared helpers for the Mawatheq browser suite.
const { expect } = require('@playwright/test');

const PASSWORD = process.env.MAWATHEQ_TEST_PASSWORD || 'Test-Password-2026';

const ACCOUNTS = {
  // Seeded by tests/seed_phase6.php + tests/seed_phase6_business.php.
  lawyer: process.env.MAWATHEQ_LAWYER_EMAIL || 'owner-a@example.com',
  lawyerB: 'owner-b@example.com',
  client: 'client-firm17@example.com',
  admin: 'admin@mawatheq.com',
};

async function login(page, loginPath, email, password = PASSWORD) {
  await page.goto(loginPath);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
}

// The dev server prints deprecations; a real PHP error must still fail the page.
async function expectNoPhpError(page) {
  const body = await page.content();
  for (const marker of ['Fatal error', 'Parse error', 'Uncaught']) {
    expect(body, `page must not contain "${marker}"`).not.toContain(marker);
  }
}

// Every page under test must render without horizontal overflow at its viewport.
async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => {
    const de = document.documentElement;
    return { scroll: de.scrollWidth, client: de.clientWidth };
  });
  expect(overflow.scroll, `scrollWidth ${overflow.scroll} vs clientWidth ${overflow.client}`)
    .toBeLessThanOrEqual(overflow.client + 2);
}

module.exports = { login, expectNoPhpError, expectNoHorizontalOverflow, ACCOUNTS, PASSWORD };
