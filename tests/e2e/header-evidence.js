/**
 * Execution 55, Phase 1 — header evidence.
 *
 * Captures the real header at every width the brief names, from two origins:
 *   - the live site (before), which still serves the older stylesheet;
 *   - the local build (after), which carries the fix.
 *
 * It also measures the defect rather than describing it: for each visible header
 * child it compares the element's own rect against the header row, and flags any
 * pair of children whose rects intersect. A screenshot cannot tell "touching"
 * from "overlapping", so the numbers are the evidence and the images are the
 * illustration.
 *
 *   node tests/e2e/header-evidence.js <origin> <label>
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const WIDTHS = [360, 390, 768, 1024, 1199, 1200, 1280, 1440, 1920];
const ORIGIN = process.argv[2] || 'http://127.0.0.1:12000';
const LABEL = process.argv[3] || 'local';
const OUT = path.join('/tmp/exec55_evidence', LABEL);

async function audit(page) {
  return page.evaluate(() => {
    const header = document.querySelector('.site-header');
    if (!header) return { error: 'no .site-header' };

    const row = header.querySelector('.container') || header;
    const rowRect = row.getBoundingClientRect();

    const pick = (sel) => header.querySelector(sel);
    const brand = pick('.brand') || pick('.site-brand') || pick('.logo');
    const nav = pick('.main-nav') || pick('nav');
    const toggle = pick('.nav-toggle');

    const kids = [...row.children]
      .filter((el) => {
        const s = getComputedStyle(el);
        return s.display !== 'none' && s.visibility !== 'hidden' && el.getBoundingClientRect().width > 0;
      })
      .map((el) => {
        const r = el.getBoundingClientRect();
        return {
          tag: el.tagName.toLowerCase(),
          cls: (el.className || '').toString().split(/\s+/).filter(Boolean).slice(0, 3).join('.'),
          left: +r.left.toFixed(1), right: +r.right.toFixed(1),
          top: +r.top.toFixed(1), bottom: +r.bottom.toFixed(1),
        };
      });

    // Escapes the row, or collides with a sibling. Both are the reported defect.
    const overflow = kids.filter((k) => k.left < rowRect.left - 0.5 || k.right > rowRect.right + 0.5);
    const collisions = [];
    for (let i = 0; i < kids.length; i++) {
      for (let j = i + 1; j < kids.length; j++) {
        const a = kids[i], b = kids[j];
        const xOverlap = Math.min(a.right, b.right) - Math.max(a.left, b.left);
        const yOverlap = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
        if (xOverlap > 0.5 && yOverlap > 0.5) {
          collisions.push(`${a.cls} ∩ ${b.cls} (${xOverlap.toFixed(1)}px)`);
        }
      }
    }

    // The defect the owner reported is finer than the row: the nav is `flex:1 1 auto`
    // with `white-space:nowrap` links, so when the links stop fitting they are painted
    // outside the nav's OWN box — over the brand and the actions — while the nav box
    // itself still sits neatly between its siblings. Row-level comparison cannot see
    // that, which is why this second measurement exists.
    const navLinks = [];
    let navOverflow = [];
    if (nav) {
      const navRect = nav.getBoundingClientRect();
      [...nav.querySelectorAll('a, button')].forEach((el) => {
        const s = getComputedStyle(el);
        if (s.display === 'none' || s.visibility === 'hidden') return;
        const r = el.getBoundingClientRect();
        if (r.width === 0) return;
        const label = (el.textContent || '').trim().slice(0, 14);
        navLinks.push({ label, left: +r.left.toFixed(1), right: +r.right.toFixed(1) });
        // Only meaningful while the nav is laid out inline; inside the drawer the
        // links are in a panel and this geometry does not apply.
        const byLeft = navRect.left - r.left;
        const byRight = r.right - navRect.right;
        if (byLeft > 0.5 || byRight > 0.5) {
          navOverflow.push(`${label} outside nav by ${Math.max(byLeft, byRight).toFixed(1)}px`);
        }
      });
    }

    // Do the links actually paint over the brand or the actions?
    const brandRect = brand ? brand.getBoundingClientRect() : null;
    const actionsEl = pick('.header-actions') || pick('.site-actions') || pick('.btn-primary');
    const actionsRect = actionsEl ? actionsEl.getBoundingClientRect() : null;
    const linkHits = [];
    if (getComputedStyle(nav || document.body).display !== 'none') {
      navLinks.forEach((l) => {
        const hit = (r) => r && l.left < r.right - 0.5 && l.right > r.left + 0.5;
        if (hit(brandRect)) linkHits.push(`${l.label} over brand`);
        if (hit(actionsRect)) linkHits.push(`${l.label} over actions`);
      });
    }

    const cs = getComputedStyle(header);
    return {
      rowWidth: +rowRect.width.toFixed(1),
      children: kids.length,
      overflow,
      collisions,
      navDisplay: nav ? getComputedStyle(nav).display : 'absent',
      navVisible: nav ? getComputedStyle(nav).display !== 'none' : false,
      toggleVisible: toggle ? getComputedStyle(toggle).display !== 'none' : false,
      background: cs.backgroundColor,
      backdrop: cs.backdropFilter,
      position: cs.position,
      docOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      navOverflow,
      linkHits,
      navLinkCount: navLinks.length,
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const results = [];

  for (const w of WIDTHS) {
    const ctx = await browser.newContext({
      viewport: { width: w, height: 900 },
      deviceScaleFactor: 1,
      locale: 'ar-SA',
    });
    const page = await ctx.newPage();
    // A stale stylesheet is exactly what is being diagnosed, so never let the
    // cache answer for the server.
    await page.route('**/*', (r) => r.continue());
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });

    let r;
    try {
      await page.goto(ORIGIN + '/', { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(900);
      r = await audit(page);
    } catch (e) {
      r = { error: String(e).slice(0, 120) };
    }

    if (!r.error) {
      await page.locator('.site-header').screenshot({ path: path.join(OUT, `header-${w}.png`) });
      // The top of the page, so the sticky bar is shown in context.
      await page.screenshot({ path: path.join(OUT, `top-${w}.png`), clip: { x: 0, y: 0, width: w, height: 260 } });
    }

    const bad = r.error ? true
      : (r.overflow.length > 0 || r.collisions.length > 0 || r.docOverflow > 0 ||
         (r.navOverflow && r.navOverflow.length > 0) || (r.linkHits && r.linkHits.length > 0));
    results.push({ width: w, ...r, bad });
    console.log(
      String(w).padStart(5) + '  ' +
      (r.error ? 'ERROR ' + r.error
        : `nav=${r.navVisible ? 'inline' : 'drawer'} links=${r.navLinkCount} ` +
          `rowOv=${r.overflow.length} collide=${r.collisions.length} navOv=${(r.navOverflow || []).length} ` +
          `hits=${(r.linkHits || []).length} docOv=${r.docOverflow}`) +
      (bad ? '   <-- FAIL' : '')
    );
    if (!r.error && r.collisions.length) console.log('        ' + r.collisions.join('\n        '));
    if (!r.error && r.overflow.length) console.log('        outside row: ' + r.overflow.map((o) => `${o.cls}[${o.left}..${o.right}]`).join(', '));
    if (!r.error && (r.navOverflow || []).length) console.log('        ' + r.navOverflow.slice(0, 6).join('\n        '));
    if (!r.error && (r.linkHits || []).length) console.log('        hits: ' + [...new Set(r.linkHits)].slice(0, 6).join(', '));

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(results, null, 2));
  const failures = results.filter((r) => r.bad).length;
  console.log(`\n${LABEL}: ${failures} failure(s) of ${WIDTHS.length} widths`);
  console.log('images: ' + OUT);
  process.exit(failures ? 1 : 0);
})();
