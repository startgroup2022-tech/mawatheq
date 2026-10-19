// Visual audit for Execution 34 — public header and affiliate landing page.
//
// Captures the header on the public pages and the affiliate landing page at the
// required viewports, plus the mobile menu in its open state and desktop browser zoom
// at 100/125/150%. Every capture is written as a PNG and accompanied by geometry
// measurements, so an overlap, an overflow or a mis-centred hero is visible in the
// output rather than asserted in prose.
//
//   node tools/visual_audit_exec34.js before          # capture the pre-change tree
//   node tools/visual_audit_exec34.js after           # capture the delivered tree
//
// Both runs must use the same base URL; the point is the same site before and after,
// not two different sites.
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const PHASE = process.argv[2] === 'after' ? 'after' : 'before';
const BASE = process.env.MAWATHEQ_BASE || 'http://127.0.0.1:12000';
const OUT = path.resolve(__dirname, '../docs/execution34/audit');
fs.mkdirSync(OUT, { recursive: true });

const VIEWPORTS = [
  { name: '360', width: 360, height: 800 },
  { name: '390', width: 390, height: 844 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];
const LANGS = ['ar', 'en'];
// Browser zoom is emulated with deviceScaleFactor plus a CSS zoom on <html>, which is
// what a user actually experiences: the layout viewport shrinks relative to the window.
const ZOOMS = [100, 125, 150];

const findings = [];
function note(kind, detail) {
  findings.push({ phase: PHASE, kind, detail });
  console.log(`  [${kind}] ${detail}`);
}

/** Measure the header: brand, nav links, actions, and whether anything overlaps or overflows. */
async function measureHeader(page) {
  return page.evaluate(() => {
    const header = document.querySelector('header.site-header');
    if (!header) return { missing: true };
    const container = header.querySelector('.container');
    const brand = header.querySelector('.brand');
    const nav = document.getElementById('mainNav');
    const actions = header.querySelector('.header-actions');
    const toggle = header.querySelector('[data-nav-toggle]');
    const r = (el) => (el ? el.getBoundingClientRect() : null);

    const navLinks = nav ? [...nav.querySelectorAll('a')] : [];
    const linkRects = navLinks.map((a) => {
      const b = a.getBoundingClientRect();
      return { text: a.textContent.trim().slice(0, 24), left: b.left, right: b.right, top: b.top, bottom: b.bottom, w: b.width, h: b.height };
    });

    // Two nav links overlap when their rectangles intersect on both axes.
    const overlaps = [];
    for (let i = 0; i < linkRects.length; i++) {
      for (let j = i + 1; j < linkRects.length; j++) {
        const a = linkRects[i], b = linkRects[j];
        if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) {
          overlaps.push(`${a.text} ∩ ${b.text}`);
        }
      }
    }

    // Horizontal overflow of the header content beyond the container.
    const cr = r(container);
    const overflow = [];
    for (const [name, el] of [['brand', brand], ['nav', nav], ['actions', actions]]) {
      const b = r(el);
      if (b && cr && (b.right > cr.right + 1 || b.left < cr.left - 1)) {
        overflow.push(`${name} (${Math.round(b.left)}..${Math.round(b.right)} vs ${Math.round(cr.left)}..${Math.round(cr.right)})`);
      }
    }

    return {
      headerH: r(header) ? Math.round(r(header).height) : null,
      containerW: cr ? Math.round(cr.width) : null,
      brand: r(brand) ? { w: Math.round(r(brand).width), h: Math.round(r(brand).height), left: Math.round(r(brand).left) } : null,
      brandImg: (() => { const i = header.querySelector('.brand img'); if (!i) return null; const b = i.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; })(),
      nav: r(nav) ? { w: Math.round(r(nav).width), h: Math.round(r(nav).height), left: Math.round(r(nav).left), right: Math.round(r(nav).right) } : null,
      navCount: navLinks.length,
      navVisible: nav ? getComputedStyle(nav).display !== 'none' : false,
      toggleVisible: toggle ? getComputedStyle(toggle).display !== 'none' : false,
      actions: r(actions) ? { w: Math.round(r(actions).width), left: Math.round(r(actions).left), right: Math.round(r(actions).right) } : null,
      docOverflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
      scrollW: document.documentElement.scrollWidth,
      innerW: window.innerWidth,
      overlaps,
      overflow,
    };
  });
}

/** Measure the affiliate landing page hero and section rhythm. */
async function measureAffiliate(page) {
  return page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const h1 = q('.aff-hero h1') || q('h1');
    const hero = q('.aff-hero');
    const heroInner = q('.aff-hero-inner') || (hero && hero.firstElementChild);
    const r = (el) => (el ? el.getBoundingClientRect() : null);
    const cs = (el) => (el ? getComputedStyle(el) : null);
    const h1cs = cs(h1), heroInnerCs = cs(heroInner);
    return {
      affiliateCss: [...document.styleSheets].some((s) => (s.href || '').includes('affiliate.css')),
      hasAffClass: !!q('.aff-public') || !!hero,
      h1: h1 ? {
        text: h1.textContent.trim().slice(0, 40),
        fontSize: h1cs.fontSize,
        lineHeight: h1cs.lineHeight,
        fontWeight: h1cs.fontWeight,
        width: Math.round(r(h1).width),
        left: Math.round(r(h1).left),
        align: h1cs.textAlign,
      } : null,
      hero: r(hero) ? { h: Math.round(r(hero).height), w: Math.round(r(hero).width) } : null,
      heroInner: r(heroInner) ? {
        w: Math.round(r(heroInner).width),
        left: Math.round(r(heroInner).left),
        align: heroInnerCs.textAlign,
        maxW: heroInnerCs.maxWidth,
        padding: heroInnerCs.padding,
      } : null,
      docOverflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
      scrollW: document.documentElement.scrollWidth,
      innerW: window.innerWidth,
      cards: document.querySelectorAll('.aff-card').length,
      // Buttons that wrap their label to two lines look broken; measure each one.
      wrappedButtons: [...document.querySelectorAll('.aff-public .btn')].filter((b) => b.getBoundingClientRect().height > 52).length,
      registerLinkPresent: !!document.querySelector('a[href*="affiliate/register.php"]'),
    };
  });
}

async function shoot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${PHASE}-${name}.png`), fullPage: false });
}

(async () => {
  const browser = await chromium.launch();
  const report = { phase: PHASE, base: BASE, header: [], affiliate: [], zoom: [], menu: [], findings };

  console.log(`\n=== Execution 34 visual audit — ${PHASE} (${BASE}) ===\n`);

  // ---- 1. Public header across viewports and languages ----
  console.log('1. Public header');
  for (const vp of VIEWPORTS) {
    for (const lang of LANGS) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      await page.goto(`${BASE}/?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(350);
      const m = await measureHeader(page);
      report.header.push({ vp: vp.name, lang, ...m });
      await shoot(page, `header-${vp.name}-${lang}`);
      if (m.overlaps && m.overlaps.length) note('HEADER-OVERLAP', `${vp.name}/${lang}: ${m.overlaps.join(', ')}`);
      if (m.overflow && m.overflow.length) note('HEADER-OVERFLOW', `${vp.name}/${lang}: ${m.overflow.join(', ')}`);
      if (m.docOverflowX) note('PAGE-OVERFLOW-X', `${vp.name}/${lang}: scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
      await page.close();
    }
  }
  console.log(`  captured ${report.header.length} header shots`);

  // ---- 2. Mobile menu open/close ----
  console.log('\n2. Mobile menu');
  for (const vp of VIEWPORTS.filter((v) => v.width <= 768)) {
    for (const lang of LANGS) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      await page.goto(`${BASE}/?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(300);
      const toggle = page.locator('[data-nav-toggle]');
      const toggleShown = await toggle.isVisible().catch(() => false);
      let opened = false, navAfter = null;
      if (toggleShown) {
        await toggle.click().catch(() => {});
        await page.waitForTimeout(400);
        opened = await page.evaluate(() => {
          const n = document.getElementById('mainNav');
          if (!n) return false;
          const cs = getComputedStyle(n);
          const b = n.getBoundingClientRect();
          return cs.display !== 'none' && b.height > 0 && (n.classList.contains('is-open') || cs.visibility !== 'hidden');
        });
        navAfter = await page.evaluate(() => {
          const n = document.getElementById('mainNav');
          const b = n.getBoundingClientRect();
          return { h: Math.round(b.height), w: Math.round(b.width), scrollW: n.scrollWidth, clientW: n.clientWidth };
        });
        await shoot(page, `menu-open-${vp.name}-${lang}`);
        // Close again and confirm it actually closes.
        await toggle.click().catch(() => {});
        await page.waitForTimeout(350);
      }
      const closed = await page.evaluate(() => {
        const n = document.getElementById('mainNav');
        const b = n.getBoundingClientRect();
        const cs = getComputedStyle(n);
        return cs.display === 'none' || b.height === 0 || !n.classList.contains('is-open');
      });
      report.menu.push({ vp: vp.name, lang, toggleShown, opened, closed, navAfter });
      if (toggleShown && !opened) note('MENU-WILL-NOT-OPEN', `${vp.name}/${lang}`);
      if (toggleShown && !closed) note('MENU-WILL-NOT-CLOSE', `${vp.name}/${lang}`);
      if (navAfter && navAfter.scrollW > navAfter.clientW + 1) note('MENU-OVERFLOW', `${vp.name}/${lang}: nav scrollWidth ${navAfter.scrollW} > clientWidth ${navAfter.clientW}`);
      await page.close();
    }
  }
  console.log(`  checked ${report.menu.length} menu states`);

  // ---- 3. Affiliate landing page ----
  console.log('\n3. Affiliate landing page');
  for (const vp of VIEWPORTS) {
    for (const lang of LANGS) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      await page.goto(`${BASE}/affiliates.php?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(350);
      const m = await measureAffiliate(page);
      report.affiliate.push({ vp: vp.name, lang, ...m });
      await shoot(page, `affiliate-${vp.name}-${lang}`);
      if (!m.affiliateCss) note('AFFILIATE-CSS-MISSING', `${vp.name}/${lang}: affiliate.css not loaded`);
      if (!m.hasAffClass) note('AFFILIATE-CLASS-MISSING', `${vp.name}/${lang}: no .aff-public/.aff-hero wrapper — page is unstyled`);
      if (m.docOverflowX) note('AFF-PAGE-OVERFLOW-X', `${vp.name}/${lang}: scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
      if (m.h1 && parseInt(m.h1.fontSize, 10) >= 56 && vp.width <= 390) note('HERO-TITLE-TOO-LARGE', `${vp.name}/${lang}: h1 font-size ${m.h1.fontSize}`);
      if (m.wrappedButtons > 0) note('BUTTON-LABEL-WRAPS', `${vp.name}/${lang}: ${m.wrappedButtons} button(s) over 52px tall`);
      await page.close();
    }
  }
  console.log(`  captured ${report.affiliate.length} affiliate shots`);

  // ---- 4. Desktop browser zoom ----
  //
  // Browser zoom is NOT a CSS `zoom` on <html>. It shrinks the CSS viewport while the
  // device pixels stay put, so a 1280px window at 150% gives a 853px CSS viewport. That
  // matters here: the header's desktop/mobile breakpoint is at 1025px, so real zoom at
  // 125% and 150% crosses into the mobile layout and shows the hamburger — behaviour the
  // old CSS-zoom emulation could never reach, because `html { zoom: 1.5 }` leaves
  // window.innerWidth at 1280 and every media query unmoved.
  //
  // Modelling it as a narrower viewport at a higher deviceScaleFactor reproduces both the
  // reduced CSS width and the crisper rendering a real zoom produces.
  console.log('\n4. Desktop zoom 100/125/150% (CSS viewport shrinks, device pixels fixed)');
  const ZOOM_WINDOW = { width: 1280, height: 800 };
  for (const zoom of ZOOMS) {
    for (const lang of LANGS) {
      const cssWidth = Math.round(ZOOM_WINDOW.width / (zoom / 100));
      const page = await browser.newPage({
        viewport: { width: cssWidth, height: Math.round(ZOOM_WINDOW.height / (zoom / 100)) },
        deviceScaleFactor: zoom / 100,
      });
      await page.goto(`${BASE}/?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(400);
      const m = await measureHeader(page);
      const layout = await page.evaluate(() => ({
        cssViewport: window.innerWidth,
        desktopNav: matchMedia('(min-width: 1025px)').matches,
      }));
      report.zoom.push({ zoom, lang, windowWidth: ZOOM_WINDOW.width, cssViewport: layout.cssViewport, desktopNav: layout.desktopNav, ...m });
      await shoot(page, `zoom-${zoom}-${lang}`);
      if (m.overlaps && m.overlaps.length) note('ZOOM-HEADER-OVERLAP', `${zoom}%/${lang}: ${m.overlaps.join(', ')}`);
      if (m.overflow && m.overflow.length) note('ZOOM-HEADER-OVERFLOW', `${zoom}%/${lang}: ${m.overflow.join(', ')}`);
      if (m.docOverflowX) note('ZOOM-PAGE-OVERFLOW-X', `${zoom}%/${lang}: scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
      // At a reduced CSS width the desktop nav must not still be on screen with the
      // hamburger alongside it — that double-header is the classic zoom artefact.
      if (m.navVisible && m.toggleVisible) note('ZOOM-BOTH-NAV-AND-TOGGLE', `${zoom}%/${lang}: desktop nav and toggle both visible at CSS width ${layout.cssViewport}`);
      await page.close();
    }
  }
  console.log(`  captured ${report.zoom.length} zoom shots`);

  // ---- 4b. The affiliate landing page under the same zoom ----
  // Part C is about the landing page, so it needs the same treatment the header gets.
  console.log('\n4b. Affiliate landing page zoom 100/125/150%');
  for (const zoom of ZOOMS) {
    for (const lang of LANGS) {
      const cssWidth = Math.round(ZOOM_WINDOW.width / (zoom / 100));
      const page = await browser.newPage({
        viewport: { width: cssWidth, height: Math.round(ZOOM_WINDOW.height / (zoom / 100)) },
        deviceScaleFactor: zoom / 100,
      });
      await page.goto(`${BASE}/affiliates.php?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(400);
      const m = await measureAffiliate(page);
      report.affiliateZoom = report.affiliateZoom || [];
      report.affiliateZoom.push({ zoom, lang, cssViewport: cssWidth, ...m });
      await shoot(page, `zoom-affiliate-${zoom}-${lang}`);
      if (m.docOverflowX) note('ZOOM-AFF-PAGE-OVERFLOW-X', `${zoom}%/${lang}: scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
      if (m.wrappedButtons > 0) note('ZOOM-AFF-BUTTON-LABEL-WRAPS', `${zoom}%/${lang}: ${m.wrappedButtons} button(s) over 52px tall`);
      if (!m.affiliateCss) note('ZOOM-AFFILIATE-CSS-MISSING', `${zoom}%/${lang}`);
      await page.close();
    }
  }
  console.log(`  captured ${report.affiliateZoom.length} affiliate zoom shots`);

  // ---- 5. Affiliate registration page (Part C3 form spacing) ----
  console.log('\n5. Affiliate registration form');
  for (const vp of VIEWPORTS.filter((v) => [360, 390, 768, 1280].includes(v.width))) {
    for (const lang of LANGS) {
      const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
      const resp = await page.goto(`${BASE}/affiliate/register.php?lang=${lang}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(300);
      const m = await page.evaluate(() => {
        const fields = [...document.querySelectorAll('form .field, form .form-field')];
        const gaps = [];
        for (let i = 1; i < fields.length; i++) {
          const a = fields[i - 1].getBoundingClientRect(), b = fields[i].getBoundingClientRect();
          gaps.push(Math.round(b.top - a.bottom));
        }
        return {
          status: document.title ? 'rendered' : 'unknown',
          fieldCount: fields.length,
          gapMin: gaps.length ? Math.min(...gaps) : null,
          gapMax: gaps.length ? Math.max(...gaps) : null,
          docOverflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
          affiliateCss: [...document.styleSheets].some((s) => (s.href || '').includes('affiliate.css')),
        };
      });
      report.register = report.register || [];
      report.register.push({ vp: vp.name, lang, http: resp ? resp.status() : null, ...m });
      await shoot(page, `register-${vp.name}-${lang}`);
      if (m.docOverflowX) note('REGISTER-OVERFLOW-X', `${vp.name}/${lang}`);
      await page.close();
    }
  }

  await browser.close();

  const jsonPath = path.join(OUT, `measurements-${PHASE}.json`);
  fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2));
  console.log(`\n=== ${PHASE} complete ===`);
  console.log(`screenshots: ${OUT}/${PHASE}-*.png`);
  console.log(`measurements: ${jsonPath}`);
  console.log(`findings: ${findings.length}`);
  const byKind = {};
  for (const f of findings) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
  for (const [k, v] of Object.entries(byKind)) console.log(`  ${k}: ${v}`);
})();
