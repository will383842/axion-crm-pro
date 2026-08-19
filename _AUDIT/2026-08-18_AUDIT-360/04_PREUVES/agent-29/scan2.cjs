// Agent 29 — passe 2 : chaines de libelle hors JSX (proprietes d'objet et maps de libelles).
const ts = require('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/node_modules/typescript/lib/typescript.js');
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2];
const PROPS = new Set(['label', 'title', 'description', 'subtitle', 'sublabel', 'placeholder', 'hint', 'tooltip', 'heading', 'cta', 'message', 'text', 'name', 'help', 'legend', 'caption', 'empty', 'emptyLabel']);

function walkDir(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walkDir(p, out);
    else if ((e.name.endsWith('.tsx') || e.name.endsWith('.ts')) && !e.name.includes('.test.')) out.push(p);
  }
  return out;
}
const hasWords = (s) => /[A-Za-zÀ-ÿ]{2,}/.test(s) && /\s|[À-ÿ]/.test(s) === true || /[A-Za-zÀ-ÿ]{3,}/.test(s);

const results = [];
for (const file of walkDir(ROOT)) {
  const src = fs.readFileSync(file, 'utf8');
  const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const hits = [];
  function visit(node) {
    // { label: 'Texte' }
    if (ts.isPropertyAssignment(node) && node.name && PROPS.has(node.name.getText().replace(/['"]/g, ''))) {
      const init = node.initializer;
      if (init && ts.isStringLiteral(init) && hasWords(init.text)) {
        hits.push({ line: sf.getLineAndCharacterOfPosition(node.getStart()).line + 1, kind: 'prop:' + node.name.getText(), value: init.text.slice(0, 80) });
      }
    }
    // maps de libelles : Record<..., string> = { cle: 'Texte' } — valeurs de tout objet dont le nom contient LABEL
    if (ts.isVariableDeclaration(node) && node.name && /LABEL|Label/.test(node.name.getText()) && node.initializer && ts.isObjectLiteralExpression(node.initializer)) {
      for (const p of node.initializer.properties) {
        if (ts.isPropertyAssignment(p) && p.initializer && ts.isStringLiteral(p.initializer) && hasWords(p.initializer.text)) {
          hits.push({ line: sf.getLineAndCharacterOfPosition(p.getStart()).line + 1, kind: 'map:' + node.name.getText(), value: p.initializer.text.slice(0, 80) });
        }
      }
    }
    // toast.xxx('Texte')
    if (ts.isCallExpression(node) && /^toast(\.(success|error|info|warning|message))?$/.test(node.expression.getText())) {
      const a = node.arguments[0];
      if (a && ts.isStringLiteral(a) && hasWords(a.text)) {
        hits.push({ line: sf.getLineAndCharacterOfPosition(node.getStart()).line + 1, kind: 'toast', value: a.text.slice(0, 80) });
      }
    }
    // confirm('Texte') / window.confirm
    if (ts.isCallExpression(node) && /confirm$/.test(node.expression.getText())) {
      const a = node.arguments[0];
      if (a && ts.isStringLiteral(a) && hasWords(a.text)) {
        hits.push({ line: sf.getLineAndCharacterOfPosition(node.getStart()).line + 1, kind: 'confirm', value: a.text.slice(0, 80) });
      }
    }
    ts.forEachChild(node, visit);
  }
  visit(sf);
  if (hits.length) results.push({ file: path.relative(ROOT, file).replace(/\\/g, '/'), count: hits.length, hits });
}
results.sort((a, b) => b.count - a.count);
console.log('TOTAL passe 2 : ' + results.reduce((s, r) => s + r.count, 0));
console.log('| fichier | libelles hors JSX |');
console.log('|---|---:|');
for (const r of results) console.log('| ' + r.file + ' | ' + r.count + ' |');
console.log('\n===== DETAIL =====');
for (const r of results) { console.log('\n--- ' + r.file + ' ---'); for (const h of r.hits) console.log('  ' + h.line + ' [' + h.kind + '] ' + h.value); }
