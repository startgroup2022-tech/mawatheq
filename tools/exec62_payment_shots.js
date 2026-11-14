// Execution 62 delivery screenshots — the N-Genius payment settings surface.
//
// The admin settings page is the visible half of this execution: the Realm Name field
// now reads "leave blank" and explains the realmName trap that silently disabled every
// payment. The screenshot is taken from the payment fieldset element, not a document
// clip, so the region is correct at every viewport.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:8080';
const PASSWORD = 'Test-Password-2026';
const WIDTHS = [360, 390, 768, 1366, 1920];
const OUT = '/tmp/exec62_shots';

async function login(page) {
  await page.goto(`${BASE}/admin/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'admin@mawatheq.com');
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
  if (page.url().includes('login.php')) throw new Error('admin login rejected');
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  for (const w of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    await page.goto(`${BASE}/admin/settings.php`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
    await page.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; window.scrollTo(0, 0); });
    // The Realm Name field is the deliverable; capture its containing fieldset.
    const realmInput = await page.$('input[name="ngenius_realm_name"]');
    if (!realmInput) { console.log(`${w}px -> no realm field (gateway section hidden?)`); await ctx.close(); continue; }
    const box = await realmInput.evaluateHandle(el => el.closest('.card') || el.closest('form') || el.parentElement);
    const el = box.asElement();
    const value = await realmInput.inputValue();
    const hint = await realmInput.evaluate(el => el.parentElement.querySelector('.field-hint')?.textContent || '');
    await el.screenshot({ path: `${OUT}/ngenius-settings-${w}.png` });
    console.log(`${w}px -> ${OUT}/ngenius-settings-${w}.png  (realm value: "${value}")`);
    if (w === 1366) {
      console.log(`   hint: ${hint.trim().slice(0, 80)}...`);
    }
    await ctx.close();
  }
  await browser.close();
})();
