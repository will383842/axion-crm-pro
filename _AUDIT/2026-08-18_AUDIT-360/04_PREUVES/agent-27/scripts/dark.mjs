import fs from 'node:fs';
import path from 'node:path';

const SRC = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';

function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, acc);
    else if (/\.tsx$/.test(e.name)) acc.push(p.replace(/\\/g, '/'));
  }
  return acc;
}

// classes claires qui exigent une contrepartie dark:
const LIGHT = /^(bg|text|ring|border|divide|from|to|via|placeholder|shadow|fill|stroke|decoration|outline|accent|caret)-(white|black|slate-(50|100|200|300)|gray-(50|100|200|300)|zinc-(50|100|200|300)|neutral-(50|100|200|300)|(sky|emerald|rose|amber|indigo|violet|blue|green|red|orange|yellow|teal|cyan|fuchsia|pink|purple|lime)-(50|100|200))(\/\d+)?$/;
// couleurs literales CSS
const LITERAL = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;

const results = [];
for (const f of walk(SRC)) {
  const src = fs.readFileSync(f, 'utf8');
  const lines = src.split(/\r?\n/);
  const hits = [];
  lines.forEach((line, i) => {
    // extraire toutes les chaines de classes (attribut className="..." ou '...' dans cn(...))
    const strRe = /(["'`])((?:(?!\1)[^\\]|\\.)*?)\1/g;
    let m;
    while ((m = strRe.exec(line))) {
      const s = m[2];
      if (!/(^|\s)(bg|text|ring|border|from|to|placeholder|divide)-/.test(s)) continue;
      const toks = s.split(/\s+/).filter(Boolean);
      const hasDark = toks.some(t => t.startsWith('dark:'));
      const bare = toks.filter(t => !t.includes(':') && LIGHT.test(t));
      if (bare.length === 0) continue;
      // pour chaque token clair, y a-t-il un dark: pour la meme propriete ?
      const missing = [];
      for (const t of bare) {
        const prop = t.split('-')[0];
        const covered = toks.some(x => x.startsWith('dark:' + prop + '-'));
        if (!covered) missing.push(t);
      }
      if (missing.length) hits.push({ line: i + 1, tokens: missing, hasDark, snippet: s.slice(0, 110) });
    }
    // literaux couleur
    const lit = line.match(LITERAL);
    if (lit) {
      // ignorer les regex/viewBox
      const filtered = lit.filter(x => !/^#[0-9a-fA-F]{1,2}$/.test(x));
      if (filtered.length) hits.push({ line: i + 1, literal: filtered, snippet: line.trim().slice(0, 110) });
    }
  });
  if (hits.length) results.push({ file: f.replace(SRC + '/', ''), hits });
}

results.sort((a, b) => b.hits.length - a.hits.length);
let totalClass = 0, totalLit = 0;
console.log('=== COULEURS CLAIRES SANS VARIANTE dark: + LITERAUX COULEUR — par fichier ===\n');
for (const r of results) {
  const c = r.hits.filter(h => h.tokens).length;
  const l = r.hits.filter(h => h.literal).length;
  totalClass += c; totalLit += l;
  console.log(`### ${r.file}   (classes-sans-dark: ${c} occurrences | literaux couleur: ${l})`);
  for (const h of r.hits) {
    if (h.tokens) console.log(`   L${h.line}  MANQUE dark: pour [${h.tokens.join(', ')}]  << ${h.snippet}`);
    else console.log(`   L${h.line}  LITERAL ${h.literal.join(' ')}  << ${h.snippet}`);
  }
  console.log('');
}
console.log(`TOTAL occurrences classes claires sans dark: = ${totalClass}`);
console.log(`TOTAL lignes avec literaux couleur          = ${totalLit}`);
console.log(`Fichiers touches                            = ${results.length}`);
