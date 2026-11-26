/**
 * Execution 69 — contrast audit for the design previews.
 *
 * Walks every text node, resolves the nearest painted background (including
 * linear-gradient, which getComputedStyle reports as an image, not a colour),
 * and reports anything under 4.5:1. Gradient handling is the point: a DOM walk
 * that ignores it falls through to white and reports white-on-white, which is
 * how a navy-on-navy heading once shipped unnoticed.
 */
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8099';
const PAGES = ['01-ai-workspace','02-templates','03-editor','04-clients','05-case-file',
  '06-calendar','07-tasks','08-finance','09-chatbot','10-plans','11-client-portal','12-admin-hub'];

const PAGE_JS = () => {
  const parse = (c) => {
    const m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(',').map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const lum = ({ r, g, b }) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
  };
  // Darkest stop of a gradient is the worst case for light text.
  const gradientStops = (img) => {
    const out = [];
    const re = /rgba?\([^)]+\)/g;
    let m;
    while ((m = re.exec(img))) { const c = parse(m[0]); if (c) out.push(c); }
    return out;
  };
  const bgOf = (el) => {
    let n = el;
    while (n && n !== document.documentElement) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') {
        const stops = gradientStops(cs.backgroundImage);
        if (stops.length) return stops.reduce((a, b) => (lum(a) < lum(b) ? a : b));
      }
      const c = parse(cs.backgroundColor);
      if (c && c.a > 0.85) return c;
      n = n.parentElement;
    }
    return { r: 255, g: 255, b: 255, a: 1 };
  };
  const out = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
    const text = (node.textContent || '').trim();
    if (!text) continue;
    const el = node.parentElement;
    if (!el) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.1) continue;
    // content-visibility:hidden keeps a box and paints nothing — a closed <details>.
    if (cs.contentVisibility === 'hidden') continue;
    const fg = parse(cs.color);
    if (!fg) continue;
    const bg = bgOf(el);
    const r = ratio(fg, bg);
    const size = parseFloat(cs.fontSize);
    const bold = parseInt(cs.fontWeight, 10) >= 700;
    const large = size >= 24 || (size >= 18.66 && bold);
    const need = large ? 3 : 4.5;
    if (r < need) {
      out.push({ text: text.slice(0, 42), ratio: +r.toFixed(2), need,
        size: +size.toFixed(1), color: cs.color,
        bg: `rgb(${bg.r},${bg.g},${bg.b})` });
    }
  }
  return out;
};

(async () => {
  const browser = await chromium.launch();
  let bad = 0;
  for (const p of PAGES) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 980 } });
    await page.goto(`${BASE}/${p}.php`, { waitUntil: 'load' });
    await page.waitForTimeout(250);
    const fails = await page.evaluate(PAGE_JS);
    if (fails.length) {
      bad += fails.length;
      console.log(`\n${p}: ${fails.length} low-contrast`);
      fails.slice(0, 4).forEach((f) =>
        console.log(`   ${f.ratio}:1 (need ${f.need}) ${f.size}px  "${f.text}"  ${f.color} on ${f.bg}`));
    } else {
      console.log(`ok ${p}`);
    }
    await page.close();
  }
  await browser.close();
  console.log(`\n${bad} low-contrast text node(s)`);
  process.exit(bad ? 1 : 0);
})();
