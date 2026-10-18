// Visual audit of the global public header and the affiliate landing page.
//
// Two sites are compared at identical viewports: the pre-Execution-32 tree on :12002
// ("before") and the delivered tree on :12000 ("after"). Only viewport screenshots of
// the header and the landing hero/apply area are captured, plus geometry measurements,
// so a regression in centring, wrapping or overflow is visible rather than asserted.
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT = path.resolve(__dirname, '../docs/execution32/audit');
const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1280', width: 1280, height: 800 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
  { name: '360', width: 360, height: 800 },
];
const SITES = [
  { key: 'before', base: 'http://127.0.0.1:12002' },
  { key: 'after', base: 'http://127.0.0.1:12000' },
];
const LANGS = ['ar', 'en'];

const results = [];

async function auditHeader(page, site, vp, lang) {
  await page.goto(`${site.base}/?lang=${lang}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);

  const geo = await page.evaluate(() => {
    const header = document.querySelector('header.site-header');
    const container = header && header.querySelector('.container');
    const nav = document.getElementById('mainNav');
    const brand = header && header.querySelector('.brand');
    const actions = header && header.querySelector('.header-actions');
    if (!header || !nav) return null;
    const navLinks = [...nav.querySelectorAll('a')];
    const rects = navLinks.map((a) => a.getBoundingClientRect());
    const headerRect = header.getBoundingClientRect();
    const containerRect = container ? container.getBoundingClientRect() : null;
    const navRect = nav.getBoundingClientRect();
    // Distinct vertical offsets within the nav = number of wrapped rows.
    const rowTops = [...new Set(rects.map((r) => Math.round(r.top)))].sort((a, b) => a - b);
    const heights = [...new Set(rects.map((r) => Math.round(r.height)))];
    // How far the nav's visual centre sits from the container centre.
    const navCentre = navRect.left + navRect.width / 2;
    const containerCentre = containerRect ? containerRect.left + containerRect.width / 2 : navCentre;
    return {
      docScrollW: document.documentElement.scrollWidth,
      docClientW: document.documentElement.clientWidth,
      headerH: Math.round(headerRect.height),
      navRows: rowTops.length,
      navLinkCount: navLinks.length,
      uniformLinkHeight: heights.length === 1,
      linkHeight: heights[0] || 0,
      navOffsetFromCentre: Math.round(navCentre - containerCentre),
      navWidth: Math.round(navRect.width),
      containerWidth: containerRect ? Math.round(containerRect.width) : 0,
      brandVisible: !!brand && brand.getBoundingClientRect().width > 0,
      actionsVisible: !!actions && actions.getBoundingClientRect().width > 0,
      // The mobile toggle must be the only nav affordance when the nav collapses.
      toggleVisible: (() => {
        const t = header.querySelector('.nav-toggle');
        return !!t && getComputedStyle(t).display !== 'none';
      })(),
      navVisible: getComputedStyle(nav).display !== 'none' && navRect.width > 0,
    };
  });

  await page.locator('header.site-header').screenshot({
    path: path.join(OUT, `header-${site.key}-${lang}-${vp.name}.png`),
  });
  results.push({ page: 'header', site: site.key, lang, vp: vp.name, ...geo });
}

async function auditLanding(page, site, vp, lang) {
  await page.goto(`${site.base}/affiliates.php?lang=${lang}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);

  const geo = await page.evaluate(() => {
    const form = document.querySelector('#apply form');
    const apply = document.getElementById('apply');
    const hero = document.querySelector('.aff-hero');
    const overflowing = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && (r.right > document.documentElement.clientWidth + 1 || r.left < -1)) {
        overflowing.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(/\s+/)[0] : ''));
        if (overflowing.length > 5) break;
      }
    }
    return {
      docScrollW: document.documentElement.scrollWidth,
      docClientW: document.documentElement.clientWidth,
      hasApplySection: !!apply,
      hasForm: !!form,
      // The consent checkboxes are what make an inline application possible.
      hasTerms: !!document.querySelector('#apply input[name="accepted_terms"]'),
      hasPrivacy: !!document.querySelector('#apply input[name="accepted_privacy"]'),
      hasSubmit: !!document.querySelector('#apply button[type="submit"]'),
      formWidth: form ? Math.round(form.getBoundingClientRect().width) : 0,
      heroVisible: !!hero && hero.getBoundingClientRect().height > 0,
      overflowing: overflowing.slice(0, 5),
      // A visible sign-in-only call to action instead of a form is the "before" symptom.
      heroApplyHref: (() => {
        const a = document.querySelector('.aff-hero-actions a[href="#apply"]');
        return a ? 'present' : 'absent';
      })(),
    };
  });

  await page.locator('.aff-hero').screenshot({
    path: path.join(OUT, `landing-hero-${site.key}-${lang}-${vp.name}.png`),
  });
  const applyLoc = page.locator('#apply');
  if (await applyLoc.count()) {
    await applyLoc.screenshot({
      path: path.join(OUT, `landing-apply-${site.key}-${lang}-${vp.name}.png`),
    });
  }
  results.push({ page: 'landing', site: site.key, lang, vp: vp.name, ...geo });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  for (const site of SITES) {
    for (const vp of VIEWPORTS) {
      for (const lang of LANGS) {
        const ctx = await browser.newContext({
          viewport: { width: vp.width, height: vp.height },
          deviceScaleFactor: 1,
        });
        const page = await ctx.newPage();
        try {
          await auditHeader(page, site, vp, lang);
          await auditLanding(page, site, vp, lang);
        } catch (e) {
          results.push({ site: site.key, lang, vp: vp.name, error: String(e.message).slice(0, 200) });
        }
        await ctx.close();
      }
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'audit-results.json'), JSON.stringify(results, null, 2));
  console.log(`captured ${results.length} records into ${OUT}`);
  const errs = results.filter((r) => r.error);
  if (errs.length) console.log('errors:', errs.length, JSON.stringify(errs.slice(0, 3), null, 2));
})();
