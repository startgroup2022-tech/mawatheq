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
  await page.setViewportSize({width:1920,height:1080});
  await page.goto(BASE + '/booking.php?lawyer_id=1', { waitUntil:'domcontentloaded' });
  await page.waitForTimeout(600);
  const r = await page.evaluate(() => {
    const bf = document.getElementById('bookingForm');
    const b = bf.getBoundingClientRect();
    const btn = bf.querySelector('button[type=submit]');
    const bb = btn.getBoundingClientRect();
    const fields = [...bf.querySelectorAll('.field')].map(f => {
      const fb = f.getBoundingClientRect();
      const lbl = f.querySelector('label');
      const inp = f.querySelector('input,select,textarea');
      const ib = inp ? inp.getBoundingClientRect() : null;
      const ics = inp ? getComputedStyle(inp) : null;
      return { label: lbl ? lbl.textContent.trim() : null, fw: Math.round(fb.width), fh: Math.round(fb.height),
        iw: ib ? Math.round(ib.width) : null, ih: ib ? Math.round(ib.height) : null,
        dir: ics ? ics.direction : null, type: inp ? (inp.type||inp.tagName) : null };
    });
    const cs = getComputedStyle(bf);
    return { formW: Math.round(b.width), formH: Math.round(b.height), formTop: Math.round(b.top),
      submit: { w: Math.round(bb.width), h: Math.round(bb.height), y: Math.round(bb.top), bg: getComputedStyle(btn).backgroundColor, color: getComputedStyle(btn).color },
      maxW: cs.maxWidth, display: cs.display, gridCols: cs.gridTemplateColumns, fields,
      docH: document.documentElement.scrollHeight, vh: window.innerHeight };
  });
  console.log('bookingForm: w=' + r.formW + ' h=' + r.formH + ' top=' + r.formTop + ' maxW=' + r.maxW + ' display=' + r.display);
  console.log('gridTemplateColumns: ' + r.gridCols);
  console.log('doc height=' + r.docH + ' viewport height=' + r.vh + '  => ' + Math.round(r.docH/r.vh*100) + '% of a screen');
  console.log('submit button: y=' + r.submit.y + ' w=' + r.submit.w + ' h=' + r.submit.h + ' bg=' + r.submit.bg + ' color=' + r.submit.color);
  console.log('  -> submit is ' + (r.submit.y > r.vh ? 'BELOW THE FOLD (y=' + r.submit.y + ' > ' + r.vh + ')' : 'in view'));
  console.log('fields:');
  r.fields.forEach(f => console.log(`  ${f.label}: field w=${f.fw} h=${f.fh} | input w=${f.iw} h=${f.ih} dir=${f.dir} type=${f.type}`));
  await browser.close();
})();
