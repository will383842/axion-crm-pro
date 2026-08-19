// Agent 29 — compte les chaînes visibles écrites en dur dans le JSX (pas via i18next).
// Méthode : AST TypeScript. Deux catégories :
//   A) JsxText : texte littéral entre balises
//   B) Attributs textuels : placeholder, title, aria-label, alt, label
// Un texte est retenu s'il contient au moins 2 lettres consécutives.
const ts = require('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/node_modules/typescript/lib/typescript.js');
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2];
const TEXT_ATTRS = new Set(['placeholder', 'title', 'aria-label', 'alt', 'label', 'aria-description']);

function walkDir(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walkDir(p, out);
    else if (e.name.endsWith('.tsx') && !e.name.endsWith('.test.tsx')) out.push(p);
  }
  return out;
}

const hasWords = (s) => /[A-Za-zÀ-ÿ]{2,}/.test(s);

const results = [];
for (const file of walkDir(ROOT)) {
  const src = fs.readFileSync(file, 'utf8');
  const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const hits = [];
  const usesT = /useTranslation|\bi18n\b/.test(src);
  function visit(node) {
    if (ts.isJsxText(node)) {
      const txt = node.text.trim();
      if (txt && hasWords(txt)) {
        hits.push({ kind: 'text', line: sf.getLineAndCharacterOfPosition(node.getStart()).line + 1, value: txt.replace(/\s+/g, ' ').slice(0, 90) });
      }
    }
    if (ts.isJsxAttribute(node) && node.name && TEXT_ATTRS.has(node.name.getText())) {
      const init = node.initializer;
      let v = null;
      if (init && ts.isStringLiteral(init)) v = init.text;
      else if (init && ts.isJsxExpression(init) && init.expression && ts.isStringLiteral(init.expression)) v = init.expression.text;
      if (v && hasWords(v)) {
        hits.push({ kind: 'attr:' + node.name.getText(), line: sf.getLineAndCharacterOfPosition(node.getStart()).line + 1, value: v.slice(0, 90) });
      }
    }
    ts.forEachChild(node, visit);
  }
  visit(sf);
  results.push({ file: path.relative(ROOT, file).replace(/\\/g, '/'), count: hits.length, usesT, hits });
}

results.sort((a, b) => b.count - a.count);
let total = 0;
for (const r of results) total += r.count;
console.log('FICHIERS SCANNES : ' + results.length);
console.log('TOTAL CHAINES EN DUR : ' + total);
console.log('FICHIERS UTILISANT i18next : ' + results.filter(r => r.usesT).length);
console.log('');
console.log('| fichier | chaines en dur | utilise i18next |');
console.log('|---|---:|---|');
for (const r of results) console.log('| ' + r.file + ' | ' + r.count + ' | ' + (r.usesT ? 'oui' : 'NON') + ' |');
if (process.argv[3] === '--detail') {
  console.log('\n\n===== DETAIL =====');
  for (const r of results) {
    if (!r.count) continue;
    console.log('\n--- ' + r.file + ' (' + r.count + ') ---');
    for (const h of r.hits) console.log('  ' + h.line + ' [' + h.kind + '] ' + h.value);
  }
}
