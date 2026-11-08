const { chromium } = require('@playwright/test');
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport:{width:1366,height:900}, locale:'ar-SA' });
  const page = await ctx.newPage();
  await page.goto('http://127.0.0.1:13000/website-01-journey-a.html?stage=1', { waitUntil:'networkidle' });
  const r = await page.evaluate(() => {
    const body = document.body;
    const cs = getComputedStyle(body);
    // Measure a known Arabic string in the design font vs a deliberately missing family.
    const probe = document.createElement('span');
    probe.textContent = 'الأحوال الشخصية';
    probe.style.font = "700 24px 'Cairo'";
    probe.style.position = 'absolute'; probe.style.visibility='hidden';
    document.body.appendChild(probe);
    const w1 = probe.getBoundingClientRect().width;
    probe.style.font = "700 24px 'NoSuchFontFamilyXYZ'";
    const w2 = probe.getBoundingClientRect().width;
    probe.remove();
    // Check computed direction on the key blocks.
    const dirs = {};
    for (const sel of ['.mk-topbar','.mk-lcard','.mk-sum','.mk-progress','.mk-cal','.mk-slotgrid']) {
      const el = document.querySelector(sel);
      dirs[sel] = el ? getComputedStyle(el).direction : 'MISSING';
    }
    // Money / numeric elements must be LTR-isolated.
    const numEls = [...document.querySelectorAll('.num, .ltr')].slice(0,4).map(e=>({t:e.tagName, cls:e.className, dir:getComputedStyle(e).direction, txt:(e.textContent||'').trim().slice(0,20)}));
    return { bodyDir: cs.direction, fontFamily: cs.fontFamily, cairoWidth: Math.round(w1), missingFontWidth: Math.round(w2), dirs, numEls };
  });
  console.log('body direction:', r.bodyDir);
  console.log('body font-family:', r.fontFamily.slice(0,60));
  console.log('Arabic string width with Cairo =', r.cairoWidth, 'px; with a missing family =', r.missingFontWidth, 'px');
  console.log('  -> ' + (r.cairoWidth !== r.missingFontWidth ? 'CAIRO IS LOADING (widths differ)' : 'WARNING: widths identical — font may not be applied'));
  console.log('block directions:', JSON.stringify(r.dirs, null, 1));
  console.log('numeric elements:', JSON.stringify(r.numEls));
  await browser.close();
})();
