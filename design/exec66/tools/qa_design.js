const { chromium } = require('@playwright/test');
const BASE = 'http://127.0.0.1:13000';
const VPS = [[360,800],[390,844],[768,1024],[1366,900],[1920,1080]];
const PAGES = [
  ['website-01-journey-a.html', [1,2,3,4,5,6]],
  ['website-02-journey-b.html', [1,2,3,4,5]],
  ['website-03-states.html', [1,2,3,4]],
];
(async () => {
  const browser = await chromium.launch();
  let problems = 0;
  for (const [file, stages] of PAGES) {
    for (const [w,h] of VPS) {
      const ctx = await browser.newContext({ viewport:{width:w,height:h}, locale:'ar-SA' });
      const page = await ctx.newPage();
      for (const s of stages) {
        await page.goto(`${BASE}/${file}?stage=${s}`, { waitUntil:'networkidle' });
        const m = await page.evaluate(() => {
          const de = document.documentElement;
          const vis = el => { const r = el.getBoundingClientRect(); return r.width>0 && r.height>0; };
          const clipped = [...document.querySelectorAll('h1,h2,h3,label,button,.btn,td,th,p,span,div')].filter(el=>{
            if (!vis(el)) return false;
            const cs = getComputedStyle(el);
            if (cs.overflow !== 'visible' && cs.textOverflow === 'clip') return false;
            return el.scrollWidth > el.clientWidth + 3 && el.clientWidth > 0 && el.children.length === 0;
          }).slice(0,5).map(el=>({t:el.tagName,c:(el.className||'').toString().slice(0,30),txt:(el.textContent||'').trim().slice(0,35),sw:el.scrollWidth,cw:el.clientWidth}));
          const small = [...document.querySelectorAll('button,.btn,select,input')].filter(el=>{
            if (!vis(el)) return false; const r=el.getBoundingClientRect(); return r.height<24 || r.width<24;
          }).slice(0,6).map(el=>({t:el.tagName,txt:(el.textContent||el.value||'').trim().slice(0,25),w:Math.round(el.getBoundingClientRect().width),h:Math.round(el.getBoundingClientRect().height)}));
          const tiny = [...document.querySelectorAll('*')].filter(el=>{
            if (!vis(el)||!el.textContent.trim()||el.children.length) return false;
            return parseFloat(getComputedStyle(el).fontSize) < 11;
          }).slice(0,6).map(el=>({t:el.tagName,fs:getComputedStyle(el).fontSize,txt:(el.textContent||'').trim().slice(0,25)}));
          return { ov: de.scrollWidth - de.clientWidth, clipped, small, tiny, stage: document.querySelector('.stage.on') ? document.querySelector('.stage.on').dataset.stage : null };
        });
        const bad = [];
        if (m.ov > 2) bad.push(`OVERFLOW +${m.ov}px`);
        if (m.clipped.length) bad.push(`clipped=${JSON.stringify(m.clipped)}`);
        if (m.small.length) bad.push(`small=${JSON.stringify(m.small)}`);
        if (m.tiny.length) bad.push(`tiny=${JSON.stringify(m.tiny)}`);
        if (!m.stage) bad.push('NO STAGE RENDERED');
        if (bad.length) { problems++; console.log(`✗ ${file} s${s} @${w}x${h}: ${bad.join(' | ')}`); }
        else console.log(`✓ ${file} s${s} @${w}x${h}`);
      }
      await ctx.close();
    }
  }
  console.log(problems === 0 ? '\nALL DESIGN QA CHECKS PASS' : `\n${problems} PROBLEM(S)`);
  await browser.close();
})();
