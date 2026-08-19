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

const LIGHT = /^(bg|text|ring|border|divide|from|to|via|placeholder|fill|stroke)-(white|black|slate-(50|100|200|300)|gray-(50|100|200|300)|(sky|emerald|rose|amber|indigo|violet|blue|green|red|orange|yellow|teal|cyan|fuchsia|pink|purple|lime|brand)-(50|100|200))(\/[\d.]+)?$/;
const LITERAL = /#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;

/** Extrait chaque expression className= complete (attribut string OU accolade equilibree),
 *  ainsi que chaque valeur d'un objet/Record de classes (les TONE_MAP/VARIANTS des composants). */
function extractClassExprs(src) {
  const out = [];
  const re = /className\s*=\s*/g;
  let m;
  while ((m = re.exec(src))) {
    let i = m.index + m[0].length;
    const start = i;
    if (src[i] === '"' || src[i] === "'") {
      const q = src[i]; i++;
      while (i < src.length && src[i] !== q) { if (src[i] === '\\') i++; i++; }
      out.push({ start, text: src.slice(start, i + 1) });
    } else if (src[i] === '{') {
      let depth = 0;
      while (i < src.length) {
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') { const q = c; i++; while (i < src.length && src[i] !== q) { if (src[i] === '\\') i++; i++; } }
        else if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) break; }
        i++;
      }
      out.push({ start, text: src.slice(start, i + 1) });
    }
  }
  return out;
}

const results = [];
for (const f of walk(SRC)) {
  const src = fs.readFileSync(f, 'utf8');
  const lineOf = (idx) => src.slice(0, idx).split(/\r?\n/).length;
  const hits = [];
  const seen = new Set();

  for (const { start, text } of extractClassExprs(src)) {
    const covered = new Set([...text.matchAll(/dark:([a-z-]+)-/g)].map(x => x[1]));
    const strRe = /(["'`])((?:(?!\1)[^\\]|\\.)*?)\1/g;
    let s;
    const bare = [];
    while ((s = strRe.exec(text))) for (const t of s[2].split(/\s+/)) {
      if (!t || t.includes(':')) continue;
      if (!LIGHT.test(t)) continue;
      const prop = t.replace(/^([a-z]+)-.*/, '$1');
      // couverture : dark:<prop>-  present dans la MEME expression className
      let ok = false;
      for (const c of covered) if (c === prop || c.startsWith(prop + '-')) ok = true;
      if (!ok) bare.push(t);
    }
    if (bare.length) {
      const ln = lineOf(start);
      const key = ln + '|' + bare.join(',');
      if (!seen.has(key)) { seen.add(key); hits.push({ line: ln, tokens: bare, snippet: text.replace(/\s+/g, ' ').slice(0, 130) }); }
    }
  }

  // valeurs de Record<...,string> de classes hors className= (VARIANTS/TONES/PADS...)
  const objRe = /^\s*(?:'[^']*'|"[^"]*"|\w+)\s*:\s*(['"])([^'"]*(?:bg|text|ring|border)-[^'"]*)\1\s*,?\s*$/gm;
  let o;
  while ((o = objRe.exec(src))) {
    const toks = o[2].split(/\s+/).filter(Boolean);
    const covered = new Set(toks.filter(t => t.startsWith('dark:')).map(t => t.slice(5).replace(/^([a-z]+)-.*/, '$1')));
    const bare = toks.filter(t => !t.includes(':') && LIGHT.test(t) && !covered.has(t.replace(/^([a-z]+)-.*/, '$1')));
    if (bare.length) {
      const ln = lineOf(o.index);
      const key = ln + '|' + bare.join(',');
      if (!seen.has(key)) { seen.add(key); hits.push({ line: ln, tokens: bare, snippet: o[2].slice(0, 130) }); }
    }
  }

  // valeurs de classes dans des objets imbriques : { bg: 'bg-orange-100', fg: 'text-orange-800' }
  // couverture evaluee sur la LIGNE entiere (le dark: d'un tel objet, s'il existe, est sur la meme ligne)
  src.split(/\r?\n/).forEach((line, i) => {
    const covered = new Set([...line.matchAll(/dark:([a-z-]+)-/g)].map(x => x[1]));
    const vRe = /\b\w+\s*:\s*(['"])([^'"]*(?:bg|text|ring|border|from|to)-[^'"]*)\1/g;
    let v; const bare = [];
    while ((v = vRe.exec(line))) for (const t of v[2].split(/\s+/)) {
      if (!t || t.includes(':') || !LIGHT.test(t)) continue;
      const prop = t.replace(/^([a-z]+)-.*/, '$1');
      let ok = false; for (const c of covered) if (c === prop || c.startsWith(prop + '-')) ok = true;
      if (!ok) bare.push(t);
    }
    if (bare.length) {
      const key = (i + 1) + '|' + bare.join(',');
      if (!seen.has(key)) { seen.add(key); hits.push({ line: i + 1, tokens: bare, snippet: line.trim().slice(0, 130) }); }
    }
  });

  // literaux couleur
  src.split(/\r?\n/).forEach((line, i) => {
    const lit = line.match(LITERAL);
    if (lit) for (const x of lit) hits.push({ line: i + 1, lit: x, snippet: line.trim().slice(0, 120) });
  });

  if (hits.length) { hits.sort((a, b) => a.line - b.line); results.push({ file: f.replace(SRC + '/', ''), hits }); }
}

results.sort((a, b) => (b.hits.filter(h=>h.tokens).length) - (a.hits.filter(h=>h.tokens).length));
let tc = 0, tt = 0, tl = 0;
console.log('=== DETECTEUR v3 — expression className= complete (accolades equilibrees) + Records de classes ===\n');
for (const r of results) {
  const occ = r.hits.filter(h => h.tokens);
  const l = r.hits.filter(h => h.lit).length;
  tc += occ.length; tt += occ.reduce((a, h) => a + h.tokens.length, 0); tl += l;
  console.log(`### ${r.file}   occurrences=${occ.length}  jetons=${occ.reduce((a,h)=>a+h.tokens.length,0)}  literaux=${l}`);
  for (const h of r.hits) console.log(h.tokens ? `   L${h.line}  [${h.tokens.join(', ')}]  << ${h.snippet}` : `   L${h.line}  LITERAL ${h.lit}  << ${h.snippet}`);
  console.log('');
}
console.log(`TOTAL occurrences (expressions className fautives) = ${tc}`);
console.log(`TOTAL jetons de couleur claire sans dark:          = ${tt}`);
console.log(`TOTAL literaux couleur                            = ${tl}`);
console.log(`Fichiers touches                                  = ${results.length}`);
