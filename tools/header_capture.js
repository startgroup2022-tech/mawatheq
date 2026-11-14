// Header capture + overlap detector.
//
// Renders the public header at every width the brief names and asserts the three
// zones (brand / nav / actions) do not overlap, using real bounding boxes from the
// live page. An overlap is the reported defect; a screenshot alone cannot show it
// as a pass/fail, so both are produced.
//
//   node tools/header_capture.js <baseUrl> <outDir>
//
// The viewport widths are the ones the owner listed, plus the two breakpoints
// around the collapse (1210 hides the social row, ~980 collapses to the drawer).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const OUT = process.argv[3] || '/tmp/header-shots';
const WIDTHS = [360, 390, 768, 980, 1024, 1210, 1280, 1440, 1920];
const PATHS = ['/', '/privacy.php'];

function overlaps(a, b) {
  if (!a || !b) return false;
  const x = Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x);
  const y = Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y);
  return x > 1 && y > 1; // >1px tolerance: sub-pixel seams are not overlaps
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  let failures = 0;
  let checks = 0;

  for (const urlPath of PATHS) {
    for (const width of WIDTHS) {
      const page = await browser.newPage({
        viewport: { width, height: 900 },
        deviceScaleFactor: 1,
      });
      await page.goto(BASE + urlPath, { waitUntil: 'networkidle' });

      const geo = await page.evaluate(() => {
        const box = (sel) => {
          const el = document.querySelector(sel);
          if (!el) return null;
          const r = el.getBoundingClientRect();
          return { x: r.x, y: r.y, width: r.width, height: r.height };
        };
        const navEl = document.querySelector('.site-header .main-nav');
        const navCS = navEl ? getComputedStyle(navEl) : null;
        // The links' own painted rects. The defect is invisible on the nav *box*
        // because production sets `overflow:visible`, so the links draw outside the
        // box and land on the brand and the action buttons. Measuring the box alone
        // reported 0 failures on the broken page.
        const navLinks = navEl
          ? [...navEl.querySelectorAll('a')].map((a) => {
              const r = a.getBoundingClientRect();
              return { x: r.x, y: r.y, width: r.width, height: r.height };
            })
          : [];
        return {
          brand: box('.site-header .brand'),
          nav: box('.site-header .main-nav'),
          actions: box('.site-header .header-actions'),
          toggle: box('.site-header .nav-toggle'),
          navLinks,
          navVisible: !!navEl && navCS.display !== 'none',
          // A clipped nav cannot paint outside its box; an unclipped one can.
          navClips: !!navCS && navCS.overflow !== 'visible',
        };
      });

      const label = `${urlPath} @${width}`;
      // The brand and the actions must not be touched by the nav *links* either.
      const navPieces = geo.navVisible
        ? (geo.navClips ? [['nav', geo.nav]] : geo.navLinks.map((l, i) => [`navlink${i}`, l]))
        : [];
      const pairs = [
        ['brand', 'nav'],
        ['brand', 'actions'],
        ['brand', 'toggle'],
      ];
      for (const [n, rect] of navPieces) {
        pairs.push(['brand', [n, rect]]);
        pairs.push([n, geo.actions]);
      }
      if (geo.navVisible) pairs.push(['nav', 'actions']);

      for (const [a, b] of pairs) {
        checks++;
        const ra = Array.isArray(a) ? a[1] : geo[a];
        const rb = Array.isArray(b) ? b[1] : geo[b];
        const an = Array.isArray(a) ? a[0] : a;
        const bn = Array.isArray(b) ? b[0] : b;
        if (overlaps(ra, rb)) {
          failures++;
          console.log(`OVERLAP ${label}: ${an} ∩ ${bn}`);
        }
      }

      // A visible header must also be inside the viewport horizontally.
      const hdr = await page.evaluate(() => {
        const r = document.querySelector('.site-header').getBoundingClientRect();
        return { left: r.left, right: r.right };
      });
      checks++;
      if (hdr.left < -1 || hdr.right > width + 1) {
        failures++;
        console.log(`OVERFLOW ${label}: header spans ${hdr.left}..${hdr.right}`);
      }

      const shot = path.join(OUT, `${urlPath.replace(/\W+/g, '_') || 'home'}-${width}.png`);
      await page.locator('.site-header').screenshot({ path: shot });

      // Scrolling must not reintroduce the overlap: the sticky bar compresses and
      // applies `.is-scrolled`, and a regression there would only show after a scroll.
      await page.evaluate(() => window.scrollTo(0, 400));
      await page.waitForTimeout(150);
      const scrolled = await page.evaluate(() => {
        const navEl = document.querySelector('.site-header .main-nav');
        const cs = navEl ? getComputedStyle(navEl) : null;
        const r = (el) => {
          const b = el.getBoundingClientRect();
          return { x: b.x, y: b.y, width: b.width, height: b.height };
        };
        const brand = document.querySelector('.site-header .brand');
        const links = navEl ? [...navEl.querySelectorAll('a')] : [];
        return {
          clips: cs ? cs.overflow !== 'visible' : true,
          brand: brand ? r(brand) : null,
          linkRects: links.map(r),
          navVisible: !!navEl && cs.display !== 'none',
        };
      });
      if (scrolled.navVisible) {
        const pieces = scrolled.clips
          ? [await page.evaluate(() => {
              const b = document.querySelector('.site-header .main-nav').getBoundingClientRect();
              return { x: b.x, y: b.y, width: b.width, height: b.height };
            })]
          : scrolled.linkRects;
        for (const p of pieces) {
          checks++;
          if (overlaps(scrolled.brand, p)) {
            failures++;
            console.log(`OVERLAP ${label} (scrolled): brand ∩ nav`);
          }
        }
      }

      // Below the collapse breakpoint the nav is a drawer: it must be hidden until
      // the toggle is pressed, and when open every link must sit inside the viewport.
      if (!geo.navVisible) {
        checks++;
        const before = await page.evaluate(
          () => getComputedStyle(document.querySelector('.site-header .main-nav')).display);
        if (before !== 'none') {
          failures++;
          console.log(`DRAWER ${label}: nav should be collapsed before the toggle`);
        }
        const toggle = page.locator('.site-header [data-nav-toggle]');
        if (await toggle.count()) {
          await toggle.first().click();
          await page.waitForTimeout(150);
          const open = await page.evaluate(() => {
            const nav = document.querySelector('.site-header .main-nav');
            const cs = getComputedStyle(nav);
            const rects = [...nav.querySelectorAll('a')].map((a) => {
              const b = a.getBoundingClientRect();
              return { x: b.x, right: b.x + b.width, y: b.y, h: b.height };
            });
            return { display: cs.display, rects, vw: window.innerWidth };
          });
          checks++;
          if (open.display === 'none') {
            failures++;
            console.log(`DRAWER ${label}: toggle did not open the menu`);
          }
          for (const rct of open.rects) {
            checks++;
            if (rct.x < -1 || rct.right > open.vw + 1) {
              failures++;
              console.log(`DRAWER ${label}: a link runs outside the viewport`);
              break;
            }
          }
          await page.keyboard.press('Escape');
        }
      }

      await page.close();
    }
  }

  await browser.close();
  console.log(`\n${checks} checks, ${failures} failure(s)`);
  process.exit(failures === 0 ? 0 : 1);
})();
