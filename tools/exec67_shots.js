// Execution 67 — evidence screenshots for the admin lawyer form, the professional
// directory filters, and the payment diagnostics. Runs against the local dev server.
//
//   node tools/exec67_shots.js
const { chromium } = require('/workspace/project/mawatheq/node_modules/playwright');
const BASE = 'http://127.0.0.1:8080';
const OUT = '/workspace/project/mawatheq/docs/exec67';

async function login(page, path, email, password) {
  await page.goto(BASE + path);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

(async () => {
  const browser = await chromium.launch();
  // One admin session for every admin shot: the admin login throttle keys on IP and
  // account, so logging in twice in one run silently fails the second time and the
  // screenshots become identical copies of the login page.
  const adminCtx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'ar' });
  const adminPage = await adminCtx.newPage();
  await login(adminPage, '/admin/login.php', 'admin@mawatheq.com', 'Test-Password-2026');

  // --- Admin lawyer form (the modal the owner's screenshot highlighted) ---------
  {
    const page = adminPage;
    await page.goto(BASE + '/admin/lawyers.php');
    await page.waitForTimeout(600);
    // Open the Add Lawyer modal.
    const addBtn = await page.$('[data-open-lawyer-modal]');
    if (addBtn) {
      await addBtn.click();
      await page.waitForTimeout(600);
      await page.screenshot({ path: OUT + '/admin-lawyer-form-desktop.png', fullPage: true });
      await page.setViewportSize({ width: 390, height: 844 });
      await page.waitForTimeout(400);
      await page.screenshot({ path: OUT + '/admin-lawyer-form-mobile.png', fullPage: true });
    } else {
      await page.screenshot({ path: OUT + '/admin-lawyers-list-desktop.png', fullPage: true });
    }
    await page.setViewportSize({ width: 1366, height: 900 });
  }

  // --- Website directory with the professional filters --------------------------
  {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'ar' });
    const page = await ctx.newPage();
    await login(page, '/login.php', 'client-a@example.com', 'Test-Password-2026');

    await page.goto(BASE + '/consultations.php');
    await page.waitForTimeout(700);
    await page.screenshot({ path: OUT + '/directory-filters-desktop.png', fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    await page.screenshot({ path: OUT + '/directory-filters-mobile.png', fullPage: true });

    await page.setViewportSize({ width: 768, height: 1024 });
    await page.waitForTimeout(400);
    await page.screenshot({ path: OUT + '/directory-filters-tablet.png', fullPage: true });

    // A narrowed set and the professional empty state.
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(BASE + '/consultations.php?country=SA&experience=under5');
    await page.waitForTimeout(700);
    await page.screenshot({ path: OUT + '/directory-filters-narrowed-desktop.png', fullPage: true });

    await page.goto(BASE + '/consultations.php?experience=over20&max_fee=50');
    await page.waitForTimeout(700);
    await page.screenshot({ path: OUT + '/directory-filters-empty-desktop.png', fullPage: true });
    await ctx.close();
  }

  // --- Admin: payment diagnostics and package management ------------------------
  {
    const page = adminPage;
    for (const [path, name] of [
      ['/admin/gateways.php', 'admin-gateway-diagnostics'],
      ['/admin/ngenius-test.php', 'admin-ngenius-test'],
      ['/admin/plans.php', 'admin-plans'],
    ]) {
      await page.goto(BASE + path);
      await page.waitForTimeout(700);
      await page.screenshot({ path: `${OUT}/${name}-desktop.png`, fullPage: true });
    }
  }

  await adminCtx.close();
  await browser.close();
  console.log('captured exec67 screenshots into ' + OUT);
})();
