// Accessibility and internationalisation checks that hold across the whole app.
const { test, expect } = require('@playwright/test');
const { login, ACCOUNTS } = require('./helpers');

const SURFACES = [
  ['/', 'public home', null],
  ['/login.php', 'client login', null],
  ['/lawyer-login.php', 'lawyer login', null],
  ['/admin/login.php', 'admin login', null],
  ['/register.php', 'registration', null],
  ['/lawyer/dashboard.php', 'lawyer dashboard', ACCOUNTS.lawyer],
  ['/dashboard.php', 'client dashboard', ACCOUNTS.client],
  // Customer account pages added in Execution 76: each must expose exactly one <h1>.
  ['/packages.php', 'client packages', ACCOUNTS.client],
  ['/subscription.php', 'client subscription', ACCOUNTS.client],
  ['/invoices.php', 'client invoices', ACCOUNTS.client],
  ['/my-cases.php', 'client cases', ACCOUNTS.client],
  ['/documents.php', 'client documents', ACCOUNTS.client],
  ['/notification-settings.php', 'client notifications', ACCOUNTS.client],
  ['/ai-assistant.php', 'client assistant', ACCOUNTS.client],
  // Execution 69: the plan catalogue is where a customer chooses what to buy, so its
  // heading structure is not cosmetic. It rendered its title as an <h2>, leaving the page
  // with no <h1> at all.
  ['/pricing.php', 'public pricing', null],
];

// Path -> the login page that must be used before visiting it.
const LOGIN_FOR = {
  '/lawyer/dashboard.php': '/lawyer-login.php',
  '/dashboard.php': '/login.php',
  '/packages.php': '/login.php',
  '/subscription.php': '/login.php',
  '/invoices.php': '/login.php',
  '/my-cases.php': '/login.php',
  '/documents.php': '/login.php',
  '/notification-settings.php': '/login.php',
  '/ai-assistant.php': '/login.php',
};

test.describe('accessibility & i18n', () => {
  for (const [path, label, account] of SURFACES) {
    test(`${label}: images have alt text and headings are ordered`, async ({ page }) => {
      if (account) await login(page, LOGIN_FOR[path], account);
      await page.goto(path);

      const imagesWithoutAlt = await page.evaluate(() =>
        Array.from(document.querySelectorAll('img')).filter((img) => !img.hasAttribute('alt')).length);
      expect(imagesWithoutAlt, 'every <img> must carry an alt attribute').toBe(0);

      const h1Count = await page.locator('h1').count();
      expect(h1Count, 'each page should expose exactly one <h1>').toBe(1);

      // A link whose only content is an image is named by that image's alt text,
      // so an empty alt on a lone logo leaves the link unannounced.
      const unnamedLinks = await page.evaluate(() =>
        Array.from(document.querySelectorAll('a')).filter((a) => {
          if (a.getAttribute('aria-label') || a.getAttribute('aria-labelledby')) return false;
          if (a.textContent.trim()) return false;
          if (a.getAttribute('title')) return false;
          const img = a.querySelector('img');
          if (img && img.getAttribute('alt')) return false;
          return true;
        }).map((a) => a.outerHTML.slice(0, 140)));
      expect(unnamedLinks, `links without an accessible name: ${unnamedLinks.join(' | ')}`).toEqual([]);
    });

    test(`${label}: every form control has an accessible name`, async ({ page }) => {
      if (account) await login(page, LOGIN_FOR[path], account);
      await page.goto(path);

      const unnamed = await page.evaluate(() => {
        const controls = Array.from(document.querySelectorAll('input:not([type=hidden]), select, textarea'));
        return controls.filter((el) => {
          if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return false;
          if (el.closest('label')) return false;
          if (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`)) return false;
          if (el.getAttribute('title')) return false;
          if (['submit', 'button', 'reset', 'image'].includes((el.getAttribute('type') || '').toLowerCase())) return false;
          return true;
        }).map((el) => el.outerHTML.slice(0, 120));
      });
      expect(unnamed, `unlabelled controls: ${unnamed.join(' | ')}`).toEqual([]);
    });

    test(`${label}: the document declares a language and direction`, async ({ page }) => {
      if (account) await login(page, LOGIN_FOR[path], account);
      await page.goto(path);
      const html = page.locator('html');
      await expect(html).toHaveAttribute('lang', /^(ar|en)$/);
      await expect(html).toHaveAttribute('dir', /^(rtl|ltr)$/);
    });
  }

  test('interactive controls are reachable by keyboard', async ({ page }) => {
    await page.goto('/lawyer-login.php');
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => document.activeElement?.tagName);
    expect(['A', 'BUTTON', 'INPUT']).toContain(focused);
  });

  test('language choice persists across a page navigation', async ({ page }) => {
    await page.goto('/');
    await page.locator('a.lang-switch').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.goto('/pricing.php');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
  });
});
