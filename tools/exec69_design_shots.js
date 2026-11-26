/**
 * Execution 69 — design preview screenshots.
 *
 * Renders every preview page at desktop and phone widths and writes PNGs to
 * docs/design/exec69/shots/. Verifies each page painted real content (a glyph
 * count, not just HTTP 200) before saving, because a blank capture looks
 * identical to a working one in a file listing.
 *
 * Usage: node tools/exec69_design_shots.js [baseUrl]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8099';
const OUT = path.join(__dirname, '..', 'design', 'exec69', 'screenshots');
const PAGES = [
  ['01-ai-workspace', 'ai-workspace'],
  ['02-templates', 'templates'],
  ['03-editor', 'editor'],
  ['04-clients', 'clients'],
  ['05-case-file', 'case-file'],
  ['06-calendar', 'calendar'],
  ['07-tasks', 'tasks'],
  ['08-finance', 'finance'],
  ['09-chatbot', 'chatbot'],
  ['10-plans', 'plans'],
  ['11-client-portal', 'client-portal'],
  ['12-admin-hub', 'admin-hub'],
];
const VIEWPORTS = [
  ['desktop', 1440, 980],
  ['phone', 390, 844],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  let failures = 0, shots = 0;

  for (const [file, slug] of PAGES) {
    for (const [vp, width, height] of VIEWPORTS) {
      const ctx = await browser.newContext({
        viewport: { width, height },
        deviceScaleFactor: vp === 'phone' ? 2 : 1,
      });
      const page = await ctx.newPage();
      const errs = [];
      page.on('pageerror', (e) => errs.push(e.message));
      page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });

      const resp = await page.goto(`${BASE}/${file}.php`, { waitUntil: 'load' });
      await page.waitForTimeout(350);

      const stats = await page.evaluate(() => {
        const text = (document.body.innerText || '').trim();
        const glyphs = text.replace(/\s+/g, '').length;
        const overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
        const h1 = document.querySelectorAll('h1').length;
        // Small text audit: anything under 11px is a legibility failure.
        let tiny = 0;
        document.querySelectorAll('*').forEach((el) => {
          if (!el.childElementCount && (el.textContent || '').trim()) {
            const fs = parseFloat(getComputedStyle(el).fontSize);
            if (fs && fs < 11) tiny++;
          }
        });
        return { glyphs, overflow, h1, tiny };
      });

      const problems = [];
      if (!resp || resp.status() !== 200) problems.push(`HTTP ${resp ? resp.status() : 'none'}`);
      if (stats.glyphs < 120) problems.push(`only ${stats.glyphs} glyphs (blank?)`);
      if (stats.overflow > 2) problems.push(`horizontal overflow ${stats.overflow}px`);
      if (stats.h1 !== 1) problems.push(`${stats.h1} <h1> elements`);
      if (stats.tiny > 0) problems.push(`${stats.tiny} text nodes under 11px`);
      if (errs.length) problems.push(`console: ${errs[0].slice(0, 90)}`);

      const out = path.join(OUT, `${slug}-${vp}.png`);
      await page.screenshot({ path: out, fullPage: true });
      shots++;

      if (problems.length) {
        failures++;
        console.log(`FAIL ${slug}-${vp}: ${problems.join(' · ')}`);
      } else {
        console.log(`ok   ${slug}-${vp}  ${stats.glyphs} glyphs`);
      }
      await ctx.close();
    }
  }
  await browser.close();
  console.log(`\n${shots} screenshots, ${failures} failure(s)`);
  process.exit(failures ? 1 : 0);
})();
