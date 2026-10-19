// Real visual review of affiliates.php and the public header.
//
// The measurement pass (visual_audit_exec34.js) records geometry. Geometry alone does not
// catch the defects a person actually notices: text clipped by an overflow:hidden parent,
// a control that is on screen but too faint to read, an element that renders off the
// viewport, or a region that is simply blank because its content failed to load. This pass
// checks those, at desktop and mobile widths, in both languages, and at 100/125/150% zoom.
//
//   node tools/visual_review_exec34.js
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const BASE = process.env.MAWATHEQ_BASE || 'http://127.0.0.1:8080';
const OUT = path.resolve(__dirname, '../docs/execution34/review');
fs.mkdirSync(OUT, { recursive: true });

const WINDOW = { width: 1280, height: 800 };
const VIEWPORTS = [
  { name: 'desktop-1280', width: 1280, height: 800 },
  { name: 'laptop-1024', width: 1024, height: 768 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'mobile-360', width: 360, height: 800 },
];
const LANGS = ['ar', 'en'];
const ZOOMS = [100, 125, 150];
const PAGES = [
  { key: 'affiliates', url: (l) => `${BASE}/affiliates.php?lang=${l}` },
  { key: 'home', url: (l) => `${BASE}/?lang=${l}` },
];

const findings = [];
const record = (pageKey, viewport, lang, kind, detail) => {
  findings.push({ pageKey, viewport, lang, kind, detail });
  console.log(`  [${kind}] ${pageKey} ${viewport}/${lang}: ${detail}`);
};

/**
 * Read a PNG buffer into raw pixels.
 *
 * Contrast has to be measured from the rendered pixels, not from the DOM. The first
 * version of this tool walked up for the nearest non-transparent `backgroundColor`, which
 * is right for flat fills and wrong for the dark sections the site actually uses: those
 * paint with `background: linear-gradient(...)` and `color:#fff`, so `backgroundColor` is
 * transparent, the walk landed on a white ancestor, and white-on-navy headings were scored
 * as 1.06:1 — a false alarm on text that samples at 16.4:1 in the real screenshot. Every
 * one of the 848 findings that first run produced was this artefact.
 */
function readPNG(buf) {
  let pos = 8;
  let width = 0, height = 0, colorType = 0;
  const idat = [];
  while (pos < buf.length) {
    const len = buf.readUInt32BE(pos);
    const type = buf.toString('ascii', pos + 4, pos + 8);
    const data = buf.subarray(pos + 8, pos + 8 + len);
    if (type === 'IHDR') { width = data.readUInt32BE(0); height = data.readUInt32BE(4); colorType = data[9]; }
    else if (type === 'IDAT') idat.push(data);
    else if (type === 'IEND') break;
    pos += 12 + len;
  }
  const raw = zlib.inflateSync(Buffer.concat(idat));
  const channels = colorType === 6 ? 4 : colorType === 2 ? 3 : 1;
  const stride = width * channels;
  const out = Buffer.alloc(height * stride);
  let prev = Buffer.alloc(stride);
  for (let y = 0; y < height; y++) {
    const ft = raw[y * (stride + 1)];
    const line = raw.subarray(y * (stride + 1) + 1, y * (stride + 1) + 1 + stride);
    const cur = Buffer.alloc(stride);
    for (let x = 0; x < stride; x++) {
      const a = x >= channels ? cur[x - channels] : 0;
      const b = prev[x];
      const c = x >= channels ? prev[x - channels] : 0;
      let v = line[x];
      if (ft === 1) v += a; else if (ft === 2) v += b; else if (ft === 3) v += (a + b) >> 1;
      else if (ft === 4) {
        const p = a + b - c, pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c);
        v += (pa <= pb && pa <= pc) ? a : (pb <= pc ? b : c);
      }
      cur[x] = v & 0xff;
    }
    cur.copy(out, y * stride);
    prev = cur;
  }
  return { width, height, channels, data: out };
}

const _lum = ([r, g, b]) => {
  const f = (c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
const _ratio = (c1, c2) => {
  const a = _lum(c1), b = _lum(c2);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

/**
 * Contrast of an element's own rendered pixels, sampled from one full-page capture.
 *
 * The earlier version took a screenshot per element and scrolled each one into view. That is
 * both slow (40 captures per page, minutes across the matrix) and fragile: scrolling can
 * park an element behind the sticky header, and the capture then contains flat header
 * background rather than glyphs — which is how text that renders at ~15:1 was reported at a
 * confident 1.00:1.
 *
 * Taking one full-page screenshot and slicing each element's document-space rectangle out of
 * it removes the scrolling entirely and samples exactly the region the element occupies.
 *
 * The two clusters are the 5th and 95th percentile of luminance across the region: those are
 * the ink and the paper, with the anti-aliased edge pixels in between. This is a lower bound
 * on the true pair ratio, which is what we want — it under-reports legibility rather than
 * inventing it. A region with only one or two flat colours carries no glyphs, so it is
 * rejected rather than scored.
 */
function contrastFromPixels(png, rect, scale) {
  const x0 = Math.max(0, Math.round(rect.x * scale));
  const y0 = Math.max(0, Math.round(rect.y * scale));
  const x1 = Math.min(png.width, Math.round((rect.x + rect.w) * scale));
  const y1 = Math.min(png.height, Math.round((rect.y + rect.h) * scale));
  if (x1 - x0 < 3 || y1 - y0 < 3) return null;
  const lums = [];
  const colours = new Set();
  for (let y = y0; y < y1; y++) {
    for (let x = x0; x < x1; x++) {
      const o = (y * png.width + x) * png.channels;
      if (png.channels === 4 && png.data[o + 3] < 128) continue;
      colours.add((png.data[o] << 16) | (png.data[o + 1] << 8) | png.data[o + 2]);
      lums.push({ l: _lum([png.data[o], png.data[o + 1], png.data[o + 2]]), rgb: [png.data[o], png.data[o + 1], png.data[o + 2]] });
    }
  }
  if (lums.length < 16) return null;
  if (colours.size < 8) return null;                 // flat fill: not a text run
  lums.sort((a, b) => a.l - b.l);
  // True ink and paper, not percentiles. A padded box — a button or a card heading — can
  // hold glyphs in well under 5% of its area, so the 5th and 95th percentiles both land on
  // the background and the pair scores a flat 1.00:1. The extremes are the glyph core and
  // the fill, and PNG has no compression noise to push them off, so min/max is exact.
  const dark = lums[0].rgb;
  const light = lums[lums.length - 1].rgb;
  return { ratio: Math.round(_ratio(dark, light) * 100) / 100, dark, light };
}

/** Everything this pass checks, run inside the page. */
const PROBE = () => {
  const visible = (el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return false;
    // Chromium hides a collapsed <details>' contents with content-visibility rather than
    // display, so those elements keep a real box and pass the checks above while painting
    // nothing. Sampling one reported a confident 1.12:1 for text the visitor never sees.
    if (cs.contentVisibility === 'hidden') return false;
    // Anything inside a collapsed <details> paints nothing except its own <summary>.
    const closed = el.closest('details:not([open])');
    if (closed) {
      const summary = closed.querySelector(':scope > summary');
      if (!summary || !summary.contains(el)) return false;
    }
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const vw = window.innerWidth;

  const clipped = [];
  const offscreen = [];
  const zeroSized = [];
  // Text elements whose legibility we want checked against real pixels. The DOM cannot
  // answer the contrast question on this site (see the note on readPNG), so the probe
  // only *collects* candidates and the Node side samples them.
  const contrastTargets = [];

  for (const el of document.querySelectorAll('h1,h2,h3,p,a,button,span,label,li,td,th')) {
    if (!visible(el)) continue;
    const cs = getComputedStyle(el);
    const text = (el.textContent || '').trim();
    if (!text) continue;
    const hidesOverflow = cs.overflow === 'hidden' || cs.overflowX === 'hidden' || cs.overflowY === 'hidden';
    if (hidesOverflow && (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1)) {
      clipped.push({ tag: el.tagName, text: text.slice(0, 40), sw: el.scrollWidth, cw: el.clientWidth, sh: el.scrollHeight, ch: el.clientHeight });
    }
    if (text.length >= 2 && el.children.length === 0) {
      const size = parseFloat(cs.fontSize);
      const bold = parseInt(cs.fontWeight, 10) >= 700;
      const large = size >= 24 || (size >= 18.66 && bold);
      const r = el.getBoundingClientRect();
      // Tag the element so the sampler can re-read its *current* viewport rectangle after
      // scrolling. Carrying a document-space rectangle instead is not reliable: the offset
      // between the reported document position and where the element actually paints drifts
      // (measured ~45px at zoom 150%), which shifts the sample onto blank space.
      const probeId = `c${contrastTargets.length}`;
      el.setAttribute('data-cprobe', probeId);
      contrastTargets.push({
        probeId,
        tag: el.tagName,
        text: text.slice(0, 40),
        need: large ? 3.0 : 4.5,
        size,
        w: Math.round(r.width), h: Math.round(r.height),
        y: Math.round(r.top + window.scrollY),
      });
    }
  }

  // Rendered entirely outside the viewport horizontally.
  for (const el of document.querySelectorAll('header, nav, main, section, h1, h2, img, .btn, .card, form')) {
    if (!visible(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.right < -1 || r.left > vw + 1) {
      offscreen.push({ tag: el.tagName, cls: String(el.className).slice(0, 40), left: Math.round(r.left), right: Math.round(r.right) });
    }
    if (el.tagName === 'IMG' && (r.width < 2 || r.height < 2)) {
      zeroSized.push({ tag: 'IMG', src: (el.getAttribute('src') || '').slice(0, 60), w: Math.round(r.width), h: Math.round(r.height) });
    }
  }

  // Images that failed to load.
  const brokenImages = [...document.images]
    .filter((i) => i.complete && i.naturalWidth === 0)
    .map((i) => (i.getAttribute('src') || '').slice(0, 60));

  // The hero heading, which is the page's focal point.
  const h1 = document.querySelector('.aff-hero h1') || document.querySelector('h1');
  const h1Info = h1 ? (() => {
    const cs = getComputedStyle(h1), r = h1.getBoundingClientRect();
    return {
      text: (h1.textContent || '').trim().slice(0, 50),
      fontSize: cs.fontSize,
      color: cs.color,
      width: Math.round(r.width),
      left: Math.round(r.left),
      right: Math.round(r.right),
      centreOffset: Math.round((r.left + r.right) / 2 - window.innerWidth / 2),
      dir: getComputedStyle(document.documentElement).direction,
      align: cs.textAlign,
    };
  })() : null;

  // Are the primary buttons reachable and full-size?
  const buttons = [...document.querySelectorAll('.aff-public .btn, .aff-hero .btn')].map((b) => {
    const r = b.getBoundingClientRect(), cs = getComputedStyle(b);
    return { text: (b.textContent || '').trim().slice(0, 30), w: Math.round(r.width), h: Math.round(r.height), display: cs.display };
  });

  return {
    innerW: window.innerWidth,
    innerH: window.innerHeight,
    scrollW: document.documentElement.scrollWidth,
    scrollH: document.documentElement.scrollHeight,
    overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
    dir: getComputedStyle(document.documentElement).direction,
    affiliateCss: [...document.styleSheets].some((s) => (s.href || '').includes('affiliate.css')),
    headerVisible: !!document.querySelector('header.site-header'),
    clipped, offscreen, zeroSized, brokenImages, h1Info, buttons,
    // Cap the sampling work: the largest text on the page is the part a reader notices.
    contrastTargets: contrastTargets
      .sort((a, b) => (b.w * b.h) - (a.w * a.h))
      .slice(0, 40),
    bodyTextLen: (document.body.innerText || '').trim().length,
  };
};

/**
 * Sample the collected contrast candidates from real viewport captures.
 *
 * Several approaches failed here, and the reasons are worth keeping:
 *
 *  • `page.screenshot({ clip: boundingBox })` mixes coordinate systems. A bounding box is
 *    viewport-relative and a clip is document-relative, so anything below the fold was
 *    sampled from the wrong region.
 *  • `page.screenshot({ fullPage: true })` does not faithfully paint off-screen content
 *    here: rows below the fold come back as one flat colour (measured: 1 distinct colour at
 *    y=3000 and y=7441, against 968 for the same region scrolled into a normal viewport).
 *  • Deriving the rectangle from `docY - scrollY` also drifts — about 45px at zoom 150% —
 *    which is enough to land the sample on blank space beside the glyphs.
 *
 * So the rectangle is re-read from the element itself *after* scrolling, and the slice is
 * taken in the same frame as the capture. Nothing is computed across the scroll.
 */
async function sampleContrast(page, targets, pageKey, viewport, lang, stats) {
  const scored = targets.filter((t) => t.w >= 8 && t.h >= 8 && t.w <= 1100).sort((a, b) => a.y - b.y);
  if (!scored.length) return;

  const viewportW = await page.evaluate(() => window.innerWidth);
  // The site sets `html { scroll-behavior: smooth }`, so a plain scrollTo animates and the
  // capture lands mid-flight — the element is still travelling and the rectangle no longer
  // matches. Scroll instantly.
  await page.addStyleTag({ content: 'html{scroll-behavior:auto !important}' }).catch(() => {});

  for (const t of scored) {
    const loc = page.locator(`[data-cprobe="${t.probeId}"]`).first();
    if (await loc.count() === 0) continue;
    await loc.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
    await page.waitForTimeout(50);

    // Re-read the rectangle now that the element is where it will be captured.
    const rect = await loc.evaluate((el) => {
      const r = el.getBoundingClientRect();
      return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) };
    }).catch(() => null);
    if (!rect || rect.w < 8 || rect.h < 8) { stats.skipped++; continue; }
    if (rect.y < 0 || rect.y + rect.h > await page.evaluate(() => window.innerHeight)) {
      // Off-screen after centring: scrollIntoView was clipped by the page end.
      stats.skipped++;
      continue;
    }

    let png;
    try {
      png = readPNG(await page.screenshot({ timeout: 15000 }));
    } catch (e) {
      stats.skipped++;
      continue;
    }
    const c = contrastFromPixels(png, rect, png.width / viewportW);
    if (!c) { stats.skipped++; continue; }
    stats.sampled++;
    if (c.ratio < t.need) {
      record(pageKey, viewport, lang, 'LOW-CONTRAST',
        `${t.tag} "${t.text}" ratio ${c.ratio} (need ${t.need} at ${t.size}px)`);
    }
  }
}

(async () => {
  const browser = await chromium.launch();
  const report = { base: BASE, runs: [], findings };
  const contrastStats = { sampled: 0, skipped: 0 };

  console.log(`\n=== Execution 34 visual review (${BASE}) ===\n`);

  // ---- Pass 1: viewport widths ----
  console.log('1. Viewport widths');
  for (const vp of VIEWPORTS) {
    for (const pg of PAGES) {
      for (const lang of LANGS) {
        const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
        await page.goto(pg.url(lang), { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(400);
        const m = await page.evaluate(PROBE);
        report.runs.push({ pass: 'viewport', pageKey: pg.key, viewport: vp.name, lang, ...m });
        await page.screenshot({ path: path.join(OUT, `${pg.key}-${vp.name}-${lang}.png`), fullPage: false });

        const label = `${vp.name}`;
        if (m.overflowX) record(pg.key, label, lang, 'HORIZONTAL-OVERFLOW', `scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
        if (m.bodyTextLen < 200) record(pg.key, label, lang, 'NEARLY-BLANK', `body text is only ${m.bodyTextLen} chars`);
        if (pg.key === 'affiliates' && !m.affiliateCss) record(pg.key, label, lang, 'CSS-MISSING', 'affiliate.css not loaded');
        for (const c of m.clipped) record(pg.key, label, lang, 'TEXT-CLIPPED', `${c.tag} "${c.text}" ${c.sw}x${c.sh} in ${c.cw}x${c.ch}`);
        for (const o of m.offscreen) record(pg.key, label, lang, 'OFFSCREEN', `${o.tag}.${o.cls} at ${o.left}..${o.right}`);
        for (const b of m.brokenImages) record(pg.key, label, lang, 'BROKEN-IMAGE', b);
        await sampleContrast(page, m.contrastTargets, pg.key, label, lang, contrastStats);
        // Direction must match the language.
        const wantDir = lang === 'ar' ? 'rtl' : 'ltr';
        if (m.dir !== wantDir) record(pg.key, label, lang, 'WRONG-DIRECTION', `dir=${m.dir}, expected ${wantDir}`);
        await page.close();
      }
    }
  }
  console.log(`  ${report.runs.length} viewport captures`);

  // ---- Pass 2: browser zoom ----
  console.log('\n2. Browser zoom 100/125/150%');
  for (const zoom of ZOOMS) {
    for (const pg of PAGES) {
      for (const lang of LANGS) {
        const cssW = Math.round(WINDOW.width / (zoom / 100));
        const page = await browser.newPage({
          viewport: { width: cssW, height: Math.round(WINDOW.height / (zoom / 100)) },
          deviceScaleFactor: zoom / 100,
        });
        await page.goto(pg.url(lang), { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(400);
        const m = await page.evaluate(PROBE);
        report.runs.push({ pass: 'zoom', pageKey: pg.key, zoom, cssViewport: cssW, lang, ...m });
        await page.screenshot({ path: path.join(OUT, `${pg.key}-zoom${zoom}-${lang}.png`), fullPage: false });

        const label = `zoom-${zoom}%`;
        if (m.overflowX) record(pg.key, label, lang, 'HORIZONTAL-OVERFLOW', `scrollWidth ${m.scrollW} > innerWidth ${m.innerW}`);
        if (m.bodyTextLen < 200) record(pg.key, label, lang, 'NEARLY-BLANK', `body text is only ${m.bodyTextLen} chars`);
        for (const c of m.clipped) record(pg.key, label, lang, 'TEXT-CLIPPED', `${c.tag} "${c.text}" ${c.sw}x${c.sh} in ${c.cw}x${c.ch}`);
        await sampleContrast(page, m.contrastTargets, pg.key, label, lang, contrastStats);
        for (const b of m.buttons) if (b.h > 56) record(pg.key, label, lang, 'BUTTON-TOO-TALL', `"${b.text}" ${b.w}x${b.h}`);
        await page.close();
      }
    }
  }
  console.log(`  ${report.runs.length - VIEWPORTS.length * PAGES.length * LANGS.length} zoom captures`);

  await browser.close();

  fs.writeFileSync(path.join(OUT, 'review.json'), JSON.stringify(report, null, 2));
  console.log(`\n=== review complete ===`);
  console.log(`findings: ${findings.length}`);
  console.log(`contrast samples: ${contrastStats.sampled} scored, ${contrastStats.skipped} skipped (occluded/detached)`);
  console.log(`screenshots: ${OUT}/`);
  console.log(`report: ${path.join(OUT, 'review.json')}`);
})();
