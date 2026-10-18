// Builds a side-by-side before/after contact sheet so the header and landing-page
// changes can be reviewed by eye rather than only through measurements.
const fs = require('fs');
const path = require('path');

const OUT = path.resolve(__dirname, '../docs/execution32/audit');
const VIEWPORTS = ['1440', '1280', '768', '390', '360'];

const img = (file, label, w) => {
  const p = path.join(OUT, file);
  if (!fs.existsSync(p)) return `<figure><figcaption>${label} — missing</figcaption></figure>`;
  return `<figure><img src="${file}" style="max-width:${w}px"><figcaption>${label}</figcaption></figure>`;
};

const sections = [];
for (const vp of VIEWPORTS) {
  for (const lang of ['ar', 'en']) {
    const rows = ['before', 'after']
      .map((s) => `<div class="col">${img(`header-${s}-${lang}-${vp}.png`, `${s.toUpperCase()} header · ${lang} · ${vp}px`, vp)}</div>`)
      .join('');
    sections.push(`<h2>Header ${vp}px · ${lang}</h2><div class="row">${rows}</div>`);
  }
}
for (const vp of VIEWPORTS) {
  for (const lang of ['ar', 'en']) {
    const rows = ['before', 'after']
      .map((s) => `<div class="col">${img(`landing-hero-${s}-${lang}-${vp}.png`, `${s.toUpperCase()} hero · ${lang} · ${vp}px`, vp)}${img(`landing-apply-${s}-${lang}-${vp}.png`, `${s.toUpperCase()} apply · ${lang} · ${vp}px`, vp)}</div>`)
      .join('');
    sections.push(`<h2>Landing ${vp}px · ${lang}</h2><div class="row">${rows}</div>`);
  }
}

const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Execution 32 visual audit</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;background:#111;color:#eee;margin:0;padding:16px}
h1{font-size:20px}h2{font-size:15px;margin:22px 0 6px;color:#9cf;border-top:1px solid #333;padding-top:10px}
.row{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap}
.col{flex:1;min-width:260px}
figure{margin:0 0 8px;background:#222;padding:6px;border-radius:4px}
img{display:block;width:100%;height:auto;border:1px solid #444;background:#fff}
figcaption{font-size:11px;color:#aaa;margin-top:4px}
</style></head><body>
<h1>Execution 32 — before (1c750e1) vs after (c1b074e)</h1>
<p>Left = before, right = after, for each viewport and language.</p>
${sections.join('\n')}
</body></html>`;

fs.writeFileSync(path.join(OUT, 'comparison.html'), html);
console.log('wrote', path.join(OUT, 'comparison.html'));
