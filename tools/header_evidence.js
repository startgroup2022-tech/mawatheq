/**
 * Execution 56/57 — the marketing header, measured at every required width.
 *
 * The owner reports the header is "still broken". A screenshot alone cannot settle
 * that, because the defect is geometric: on the production stylesheet the row is a
 * `justify-content:space-between` flex row, so once the eight nowrap nav links
 * outgrow their share of the width they paint over the brand and the action buttons.
 * Nothing about the page's HTTP status or byte count shows that.
 *
 * This harness measures the defect directly. For each width it reads the bounding
 * boxes of the brand, the nav and the header actions, then counts:
 *
 *   navOv    — px by which the nav's own box overflows its parent column;
 *   hits     — nav links whose rect is NOT fully inside the nav box (i.e. they draw
 *              over a sibling).
 *
 * A correct header scores 0/0 at every width. The production stylesheet scores
 * non-zero from ~1199px up, which is exactly the reported symptom.
 *
 * Usage:
 *   node tools/header_evidence.js --base https://mawatheq.com --label before
 *   node tools/header_evidence.js --base http://127.0.0.1:8080   --label after
 *
 * Screenshots land in /tmp/exec57_header/<label>/ and the numbers in report.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const WIDTHS = [360, 390, 768, 1024, 1280, 1440, 1920];
const PAGES = [
  { name: 'home', path: '/' },
  { name: 'privacy', path: '/privacy.php' },
];

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

async function measure(page) {
  return page.evaluate(() => {
    const nav = document.querySelector('.main-nav');
    const brand = document.querySelector('.brand');
    const actions = document.querySelector('.header-actions');
    const header = document.querySelector('.site-header');
    if (!nav || !brand || !actions || !header) {
      return { missing: true };
    }
    const nr = nav.getBoundingClientRect();
    const br = brand.getBoundingClientRect();
    const ar = actions.getBoundingClientRect();

    // Below the panel breakpoint the nav is deliberately hidden and replaced by the
    // drawer button. A zero-width nav is the correct state there, not an overlap —
    // reading its rect as one would report a defect at every phone width.
    const navVisible = nr.width > 1 && getComputedStyle(nav).display !== 'none';

    // How far the nav's box runs past its parent's content box.
    const parent = nav.parentElement.getBoundingClientRect();
    const navOv = navVisible
      ? Math.max(0, Math.round(nr.right - parent.right), Math.round(parent.left - nr.left))
      : 0;

    // A link that is not fully inside the nav box is drawing over a sibling.
    let hits = 0;
    const offenders = [];
    if (navVisible) {
      for (const a of nav.querySelectorAll('a')) {
        const r = a.getBoundingClientRect();
        if (r.width === 0) continue;
        const outside = r.right > nr.right + 1 || r.left < nr.left - 1;
        if (outside) {
          hits += 1;
          offenders.push({
            text: (a.textContent || '').trim().slice(0, 24),
            over: Math.round(Math.max(r.right - nr.right, nr.left - r.left)),
          });
        }
      }
    }

    // The three zones must not overlap each other either — again only meaningful
    // while the nav is actually on screen. This is a true interval intersection, not
    // `a.right - b.left`: the header is RTL, so the brand sits on the *right* and the
    // actions on the left, and a right-minus-left subtraction is large and positive
    // for two boxes that merely sit next to each other.
    const intersect = (a, b) =>
      Math.max(0, Math.round(Math.min(a.right, b.right) - Math.max(a.left, b.left)));
    const overlaps = navVisible
      ? { brandNav: intersect(br, nr), navActions: intersect(nr, ar) }
      : { brandNav: 0, navActions: 0 };

    const cs = getComputedStyle(nav.parentElement);
    return {
      missing: false,
      navVisible,
      display: cs.display,
      navOv,
      hits,
      offenders,
      overlaps,
      headerHeight: Math.round(header.getBoundingClientRect().height),
      navWidth: Math.round(nr.width),
      navScrollWidth: nav.scrollWidth,
      overflow: getComputedStyle(nav).overflow,
    };
  });
}

(async () => {
  const base = arg('base', 'http://127.0.0.1:8080');
  const label = arg('label', 'run');
  const outDir = path.join('/tmp/exec57_header', label);
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch();
  const report = { base, label, at: new Date().toISOString(), results: [] };
  let failures = 0;

  for (const p of PAGES) {
    for (const width of WIDTHS) {
      const ctx = await browser.newContext({
        viewport: { width, height: 900 },
        deviceScaleFactor: 1,
      });
      const page = await ctx.newPage();
      // Never let a cached stylesheet decide the result: that is the production
      // failure mode this harness exists to catch.
      await page.route('**/*', (route) => route.continue());
      await page.goto(base + p.path, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(350);

      const m = await measure(page);
      const shot = path.join(outDir, `${p.name}-${width}.png`);
      await page.screenshot({ path: shot, clip: { x: 0, y: 0, width, height: 200 } });

      const bad = m.missing || m.navOv > 0 || m.hits > 0 ||
        m.overlaps.brandNav > 0 || m.overlaps.navActions > 0;
      if (bad) failures += 1;
      report.results.push({ page: p.name, width, ...m, pass: !bad, screenshot: shot });
      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2));

  console.log(`\n${label} — ${base}`);
  console.log('page        width  display  navOv  hits  brandNav  navActions  result');
  for (const r of report.results) {
    if (r.missing) {
      console.log(`${r.page.padEnd(11)} ${String(r.width).padStart(5)}  -- header elements not found --  FAIL`);
      continue;
    }
    console.log(
      `${r.page.padEnd(11)} ${String(r.width).padStart(5)}  ${String(r.display).padEnd(7)} ` +
      `${String(r.navOv).padStart(5)}  ${String(r.hits).padStart(4)}  ` +
      `${String(r.overlaps.brandNav).padStart(8)}  ${String(r.overlaps.navActions).padStart(10)}  ` +
      (r.pass ? 'PASS' : 'FAIL'),
    );
    if (r.offenders.length) {
      for (const o of r.offenders) console.log(`      ↳ "${o.text}" paints ${o.over}px outside the nav`);
    }
  }
  console.log(`\n${report.results.filter((r) => r.pass).length}/${report.results.length} pass`);
  console.log(`screenshots + report.json -> ${outDir}`);
  process.exit(failures ? 1 : 0);
})();
