import fs from 'node:fs';
import path from 'node:path';
const SRC = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';

function walk(d, a = []) {
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, a);
    else if (/\.tsx$/.test(e.name)) a.push(p.replace(/\\/g, '/'));
  }
  return a;
}

function extract(src) {
  const out = []; const re = /className\s*=\s*/g; let m;
  while ((m = re.exec(src))) {
    let i = m.index + m[0].length; const st = i;
    if (src[i] === '"' || src[i] === "'") {
      const q = src[i]; i++;
      while (i < src.length && src[i] !== q) { if (src[i] === '\\') i++; i++; }
      out.push({ st, t: src.slice(st, i + 1) });
    } else if (src[i] === '{') {
      let d = 0;
      while (i < src.length) {
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') { const q = c; i++; while (i < src.length && src[i] !== q) { if (src[i] === '\\') i++; i++; } }
        else if (c === '{') d++;
        else if (c === '}') { d--; if (d === 0) break; }
        i++;
      }
      out.push({ st, t: src.slice(st, i + 1) });
    }
  }
  return out;
}

const PAIRS = [['bg-white', 'bg'], ['bg-slate-50', 'bg'], ['text-slate-900', 'text'], ['border-slate-200', 'border']];
const tot = {}, det = {};
for (const [u] of PAIRS) { tot[u] = { total: 0, avecDark: 0 }; det[u] = []; }

for (const f of walk(SRC)) {
  const src = fs.readFileSync(f, 'utf8');
  const lineOf = (i) => src.slice(0, i).split(/\r?\n/).length;
  for (const { st, t } of extract(src)) {
    const toks = [...t.matchAll(/(["'`])((?:(?!\1)[^\\]|\\.)*?)\1/g)].flatMap(x => x[2].split(/\s+/)).filter(Boolean);
    for (const [u, prop] of PAIRS) {
      if (!toks.includes(u)) continue;
      tot[u].total++;
      const darks = toks.filter(x => x.startsWith('dark:' + prop + '-'));
      if (darks.length) { tot[u].avecDark++; det[u].push(`${f.replace(SRC + '/', '')}:${lineOf(st)}  ${darks.join(' ')}`); }
    }
  }
}

console.log('=== Les 4 utilitaires ecrasees par src/styles/index.css:88-91  (.dark .X { ... !important }) ===\n');
for (const [u] of PAIRS) {
  console.log(`${u.padEnd(18)} elements qui la portent = ${String(tot[u].total).padStart(3)}   dont avec variante dark: EXPLICITE, donc neutralisee par le !important = ${tot[u].avecDark}`);
}
console.log('\n=== detail des variantes dark: neutralisees ===');
for (const [u] of PAIRS) { console.log(`\n--- ${u} ---`); det[u].forEach(x => console.log('   ' + x)); }
