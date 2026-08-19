import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.argv[2] || 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/src';

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (/\.tsx$/.test(e.name)) out.push(p.split(path.sep).join('/'));
  }
  return out;
}

const files = walk(ROOT);

// Découpe en balises JSX ouvrantes : de '<Nom' jusqu'au '>' de fin, en
// respectant accolades imbriquées et chaînes.
function tags(src) {
  const res = [];
  for (let i = 0; i < src.length; i++) {
    if (src[i] !== '<') continue;
    const m = /^<([A-Za-z][A-Za-z0-9._-]*)/.exec(src.slice(i, i + 40));
    if (!m) continue;
    let depth = 0, j = i + 1, q = null;
    for (; j < src.length; j++) {
      const c = src[j];
      if (q) { if (c === q && src[j - 1] !== '\\') q = null; continue; }
      if (c === '"' || c === "'" || c === '`') { q = c; continue; }
      if (c === '{') depth++;
      else if (c === '}') depth--;
      else if (c === '>' && depth === 0) break;
    }
    if (j >= src.length) continue;
    res.push({ name: m[1], raw: src.slice(i, j + 1), start: i, end: j + 1, line: src.slice(0, i).split('\n').length });
    i = j;
  }
  return res;
}

const FORM = new Set(['input', 'select', 'textarea']);
const report = {};

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const ts = tags(src);
  const rel = f.replace(/^.*\/frontend\/src\//, '');
  const R = report[rel] = {
    champs: [], champsSansLabel: [],
    boutonsIcone: [], boutonsIconeSansNom: [],
    focusOutlineNoneSansRing: [], boutonsNusSansFocusRing: [],
    onClickNonInteractif: [], imgSansAlt: [],
    roleRow: 0, roleTable: 0, roleGrid: 0, roleColumnheader: 0,
    tabIndexPositif: [], ariaLive: 0, mainLandmark: 0, navLandmark: 0, h1: 0,
    tableSemantique: 0,
  };

  const htmlFors = new Set();
  for (const t of ts) {
    const mf = /htmlFor=(?:"([^"]*)"|\{([^}]*)\})/.exec(t.raw);
    if (mf) htmlFors.add((mf[1] ?? mf[2]).trim());
  }

  for (const t of ts) {
    const raw = t.raw, n = t.name, L = t.line;
    const has = (a) => new RegExp(`(^|\\s)${a}(=|\\s|/?>)`).test(raw);

    if (FORM.has(n)) {
      const type = (/type="([^"]*)"/.exec(raw) || [])[1] || (n === 'input' ? 'text' : n);
      if (n === 'input' && ['hidden', 'submit', 'button', 'reset', 'image'].includes(type)) continue;
      const idm = /(^|\s)id=(?:"([^"]*)"|\{([^}]*)\})/.exec(raw);
      const id = idm ? (idm[2] ?? idm[3]).trim() : null;
      const wrappedByLabel = false; // mesuré séparément ci-dessous
      const labelled =
        has('aria-label') || has('aria-labelledby') ||
        (id && [...htmlFors].some((h) => h === id || h.includes(id)));
      R.champs.push({ line: L, type, tag: n });
      if (!labelled) {
        R.champsSansLabel.push({
          line: L, tag: n, type,
          placeholder: /placeholder=/.test(raw),
          title: has('title'),
          id: id || null,
        });
      }
    }

    if (n === 'button' || n === 'a') {
      const after = src.slice(t.end, t.end + 900);
      const closeIdx = after.indexOf(`</${n}>`);
      const inner = closeIdx >= 0 ? after.slice(0, closeIdx) : after;
      const texte = inner.replace(/<[^>]*>/g, '').replace(/\{[^}]*\}/g, '').replace(/\s/g, '');
      const svg = /<svg|<Icon|<[A-Z][A-Za-z]*\s*\/>/.test(inner);
      if (svg && texte.length === 0) {
        R.boutonsIcone.push(L);
        if (!has('aria-label') && !has('aria-labelledby') && !has('title')) R.boutonsIconeSansNom.push(L);
      }
      if (n === 'button') {
        const cls = /className=(?:"([^"]*)"|\{`([^`]*)`\}|\{([^]*?)\}(?=\s+[a-zA-Z-]+=|\s*\/?>))/.exec(raw);
        const c = cls ? (cls[1] ?? cls[2] ?? cls[3] ?? '') : '';
        const ring = /focus-visible:ring|focus:ring|focus-visible:outline-|focus:outline-\[|focus-within:ring/.test(c);
        if (/outline-none/.test(c) && !ring) R.focusOutlineNoneSansRing.push(L);
        if (c && !ring && /rounded|bg-|inline-flex|border/.test(c)) R.boutonsNusSansFocusRing.push(L);
      }
    }

    if (/^(div|span|tr|td|li|p|section|article|svg|h[1-6]|label)$/.test(n) && /(^|\s)onClick=/.test(raw)) {
      const role = (/role="([^"]*)"/.exec(raw) || [])[1] || null;
      const interactif = role && /^(button|link|menuitem|option|tab|switch|checkbox|radio)$/.test(role);
      const focusable = /tabIndex=/.test(raw);
      const key = /onKeyDown=|onKeyPress=|onKeyUp=/.test(raw);
      if (!(interactif && focusable && key)) {
        R.onClickNonInteractif.push({ line: L, tag: n, role, tabIndex: focusable, clavier: key });
      }
    }

    if (n === 'img' && !has('alt')) R.imgSansAlt.push(L);
    if (/role="row"/.test(raw)) R.roleRow++;
    if (/role="table"/.test(raw)) R.roleTable++;
    if (/role="grid"/.test(raw)) R.roleGrid++;
    if (/role="columnheader"/.test(raw)) R.roleColumnheader++;
    if (n === 'table') R.tableSemantique++;
    const ti = /tabIndex=\{?(-?\d+)/.exec(raw);
    if (ti && Number(ti[1]) > 0) R.tabIndexPositif.push(L);
    if (/aria-live=/.test(raw) || /role="(status|alert|log)"/.test(raw)) R.ariaLive++;
    if (n === 'main' || /role="main"/.test(raw)) R.mainLandmark++;
    if (n === 'nav' || /role="navigation"/.test(raw)) R.navLandmark++;
    if (n === 'h1') R.h1++;
  }
}

console.log(JSON.stringify(report, null, 1));
