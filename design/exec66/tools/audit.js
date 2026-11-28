const { chromium } = require('@playwright/test');
const BASE = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:12000';
const PASSWORD = 'Test-Password-2026';
const PAGES = [
  ['browse-lawyers', '/consultations.php'],
  ['journey-specialty', '/consultation-journey.php'],
  ['journey-lawyers', '/consultation-journey-lawyers.php?category_id=1'],
  ['booking-form', '/booking.php?lawyer_id=1'],
];
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: {width:1366,height:900}, locale: 'ar-SA' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name="email"]', 'client-a@example.com');
  await page.fill('input[name="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('domcontentloaded');
  for (const [label, path] of PAGES) {
    await page.setViewportSize({width:1366,height:900});
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(500);
    const m = await page.evaluate(() => {
      const de = document.documentElement;
      const vis = (el) => { const r = el.getBoundingClientRect(); return r.width>0 && r.height>0; };
      const clipped = [...document.querySelectorAll('h1,h2,h3,label,button,a.btn,td,th,p,span')].filter(el=>{
        if (!vis(el)) return false;
        return el.scrollWidth > el.clientWidth + 2 && el.clientWidth > 0;
      }).slice(0,8).map(el=>({t:el.tagName, c:(el.className||'').toString().slice(0,40), txt:(el.textContent||'').trim().slice(0,40), sw:el.scrollWidth, cw:el.clientWidth}));
      const smallTargets = [...document.querySelectorAll('button,a,select,input[type=checkbox],input[type=radio]')].filter(el=>{
        if (!vis(el)) return false; const r=el.getBoundingClientRect(); return r.height<24 || r.width<24;
      }).slice(0,10).map(el=>({t:el.tagName, txt:(el.textContent||el.value||'').trim().slice(0,30), w:Math.round(el.getBoundingClientRect().width), h:Math.round(el.getBoundingClientRect().height)}));
      const tiny = [...document.querySelectorAll('*')].filter(el=>{
        if (!vis(el) || !el.textContent.trim()) return false;
        const fs = parseFloat(getComputedStyle(el).fontSize);
        return fs < 11 && el.children.length === 0;
      }).slice(0,8).map(el=>({t:el.tagName, fs:getComputedStyle(el).fontSize, txt:(el.textContent||'').trim().slice(0,30)}));
      const steps = document.querySelectorAll('.journey-steps li, .wizard-step, [class*=step]').length;
      const progress = document.querySelectorAll('progress, [role=progressbar], .progress, .journey-steps').length;
      const cards = document.querySelectorAll('.compare-card, .lawyer-card, .dash-card').length;
      const grid = document.querySelector('.compare-grid, .specialty-grid');
      const gridCols = grid ? getComputedStyle(grid).gridTemplateColumns : null;
      const cal = document.querySelectorAll('input[type=date], .calendar, [class*=calendar]').length;
      const forms = document.querySelectorAll('form').length;
      const fieldsets = document.querySelectorAll('fieldset').length;
      const h1 = document.querySelectorAll('h1').length, h2 = document.querySelectorAll('h2').length;
      const firstBtn = document.querySelector('form button[type=submit], .btn-primary');
      const btnBox = firstBtn ? firstBtn.getBoundingClientRect() : null;
      const btnStyle = firstBtn ? getComputedStyle(firstBtn) : null;
      return { overflow: {scroll: de.scrollWidth, client: de.clientWidth}, clipped, smallTargets, tiny,
        steps, progress, cards, gridCols, cal, forms, fieldsets, h1, h2,
        submitBtn: btnBox ? {w:Math.round(btnBox.width), h:Math.round(btnBox.height), bg:btnStyle.backgroundColor, color:btnStyle.color, opacity:btnStyle.opacity, visibility:btnStyle.visibility, display:btnStyle.display} : null };
    });
    console.log('\n===== ' + label + ' (' + path + ') =====');
    console.log('overflow: scroll=' + m.overflow.scroll + ' client=' + m.overflow.client + (m.overflow.scroll > m.overflow.client+2 ? '  <<< HORIZONTAL OVERFLOW' : ''));
    console.log('h1=' + m.h1 + ' h2=' + m.h2 + ' forms=' + m.forms + ' fieldsets=' + m.fieldsets + ' cards=' + m.cards + ' calendarInputs=' + m.cal);
    console.log('stepElements=' + m.steps + ' progressElements=' + m.progress);
    console.log('gridCols=' + m.gridCols);
    console.log('clipped(' + m.clipped.length + '): ' + JSON.stringify(m.clipped));
    console.log('smallTargets(' + m.smallTargets.length + '): ' + JSON.stringify(m.smallTargets));
    console.log('tinyText(' + m.tiny.length + '): ' + JSON.stringify(m.tiny));
    console.log('submitBtn: ' + JSON.stringify(m.submitBtn));
  }
  await browser.close();
})();
