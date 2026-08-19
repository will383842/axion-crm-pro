import fs from 'node:fs';
import path from 'node:path';

const SRC = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';
const WINDOW = 6; // lignes de tolerance : un cn() multi-arguments peut porter le dark: 1-5 lignes plus loin

function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, acc);
    else if (/\.tsx$/.test(e.name)) acc.push(p.replace(/\\/g, '/'));
  }
  return acc;
}

const LIGHT = /^(bg|text|ring|border|divide|from|to|via|placeholder|fill|stroke)-(white|black|slate-(50|100|200|300)|gray-(50|100|200|300)|(sky|emerald|rose|amber|indigo|violet|blue|green|red|orange|yellow|teal|cyan|fuchsia|pink|purple|lime|brand)-(50|100|200))(\/\d+)?$/;
const LITERAL = /#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;

const results = [];
for (const f of walk(SRC)) {
  const lines = fs.readFileSync(f, 'utf8').split(/\r?\n/);
  const hits = [];
  lines.forEach((line, i) => {
    const ctx = lines.slice(Math.max(0, i - WINDOW), i + WINDOW + 1).join(' ');
    const strRe = /(["'`])((?:(?!\1)[^\\]|\\.)*?)\1/g;
    let m;
    while ((m = strRe.exec(line))) {
      const toks = m[2].split(/\s+/).filter(Boolean);
      for (const t of toks) {
        if (t.includes(':')) continue;
        if (!LIGHT.test(t)) continue;
        const prop = t.split('-')[0];
        if (new RegExp('dark:' + prop + '-').test(ctx)) continue; // couvert dans la fenetre
        hits.push({ line: i + 1, tok: t, snippet: line.trim().slice(0, 120) });
      }
    }
    const lit = line.match(LITERAL);
    if (lit) for (const x of lit) hits.push({ line: i + 1, lit: x, snippet: line.trim().slice(0, 120) });
  });
  if (hits.length) results.push({ file: f.replace(SRC + '/', ''), hits });
}

results.sort((a, b) => b.hits.length - a.hits.length);
let tc = 0, tl = 0;
console.log(`=== DETECTEUR v2 (fenetre +-${WINDOW} lignes pour absorber les cn() multi-arguments) ===\n`);
for (const r of results) {
  const c = r.hits.filter(h => h.tok).length, l = r.hits.filter(h => h.lit).length;
  tc += c; tl += l;
  console.log(`### ${r.file}   couleurs-claires-sans-dark=${c}  literaux=${l}`);
  for (const h of r.hits) console.log(h.tok ? `   L${h.line}  ${h.tok}   << ${h.snippet}` : `   L${h.line}  LITERAL ${h.lit}   << ${h.snippet}`);
  console.log('');
}
console.log(`TOTAL couleurs claires sans dark: = ${tc}`);
console.log(`TOTAL literaux couleur            = ${tl}`);
console.log(`Fichiers touches                  = ${results.length}`);
