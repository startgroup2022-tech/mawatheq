const { chromium } = require('@playwright/test');
const BASE = 'http://127.0.0.1:12000';
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: {width:1920,height:1080}, locale: 'ar-SA' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name="email"]', 'client-a@example.com');
  await page.fill('input[name="password"]', 'Test-Password-2026');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('domcontentloaded');
  for (const [label, path] of [['booking-form','/booking.php?lawyer_id=1'],['browse-lawyers','/consultations.php'],['journey-lawyers','/consultation-journey-lawyers.php?category_id=1']]) {
    await page.setViewportSize({width:1920,height:1080});
    await page.goto(BASE + path, { waitUntil:'domcontentloaded' });
    await page.waitForTimeout(500);
    const r = await page.evaluate(() => {
      const content = document.querySelector('.app-content') || document.body;
      const cr = content.getBoundingClientRect();
      const kids = [...content.children].map(el => {
        const b = el.getBoundingClientRect();
        return { tag: el.tagName, cls: (el.className||'').toString().slice(0,50), y: Math.round(b.top), h: Math.round(b.height), w: Math.round(b.width), txt: (el.textContent||'').trim().slice(0,45) };
      });
      const form = document.querySelector('form.dash-card, form');
      const fr = form ? form.getBoundingClientRect() : null;
      const inputs = [...document.querySelectorAll('form input, form select, form textarea')].map(el=>{
        const b = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return { name: el.name||el.id||el.type, w: Math.round(b.width), dir: cs.direction, ta: cs.textAlign };
      });
      return { contentW: Math.round(cr.width), viewport: window.innerWidth, contentTop: Math.round(cr.top), kids, formW: fr?Math.round(fr.width):null, inputs };
    });
    console.log('\n##### ' + label + ' @1920  contentW=' + r.contentW + ' formW=' + r.formW);
    r.kids.forEach(k => console.log(`  ${String(k.y).padStart(5)}px h=${String(k.h).padStart(4)} w=${String(k.w).padStart(4)} ${k.tag}.${k.cls} | ${k.txt}`));
    if (r.inputs.length) { console.log('  inputs:'); r.inputs.forEach(i=>console.log(`    ${i.name}: w=${i.w} dir=${i.dir} text-align=${i.ta}`)); }
  }
  await browser.close();
})();
