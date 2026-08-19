/**
 * /companies AVEC des lignes.
 *
 * La porte `a11y.yml` du dépôt mesure cet écran VIDE : sur le runner GitHub il
 * n'y a aucune API, la liste rend son état vide, et tout le balisage de ligne
 * (DropdownMenu + IconButton, QualityBadge, SizeCategoryBadge, role=row) n'est
 * jamais évalué. On sert ici 5 fiches au shape du code (`CompaniesResponse`,
 * `CompanyRowData`) et on remesure le MÊME écran.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = 'http://127.0.0.1:4188';

const FICHES = Array.from({ length: 5 }, (_, i) => ({
  id: i + 1, siren: `12345678${i}`, denomination: `Entreprise de demonstration ${i + 1}`,
  naf: '62.01Z', size_category: ['artisan', 'tpe', 'pme', 'eti', 'grande_entreprise'][i],
  effectif_range: '10-19', city: 'Paris', postcode: '75001',
  quality_score: [95, 60, 20, 88, 45][i], priority: 'haute', enriched_at: '2026-08-01T10:00:00Z',
}));

const CORS = { 'access-control-allow-origin': 'http://127.0.0.1:4188', 'access-control-allow-credentials': 'true',
  'access-control-allow-headers': '*', 'access-control-allow-methods': '*', 'content-type': 'application/json' };

for (const mode of ['clair', 'sombre']) {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  await ctx.addInitScript((m) => { try { localStorage.setItem('axion-theme', m); } catch {} }, mode === 'sombre' ? 'dark' : 'light');
  const page = await ctx.newPage();
  await page.route('**/api/v1/**', (r) => r.request().method() === 'OPTIONS'
    ? r.fulfill({ status: 204, headers: CORS })
    : r.fulfill({ status: 200, headers: CORS, body: r.request().url().includes('/companies?')
        ? JSON.stringify({ data: FICHES, meta: { total: 5, last_page: 1, current_page: 1, per_page: 100 } })
        : '{"data":[],"meta":{"total":0,"last_page":1}}' }));
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);

  console.log(`\n== /companies PEUPLE (5 fiches) -- mode ${mode} ==`);
  console.log('  role=row :', await page.locator('[role=row]').count(),
    '| role=table/grid :', await page.locator('[role=table],[role=grid]').count(),
    '| role=columnheader :', await page.locator('[role=columnheader]').count(),
    '| role=cell/gridcell :', await page.locator('[role=cell],[role=gridcell]').count());
  console.log('  declencheurs aria-haspopup=menu :', await page.locator('button[aria-haspopup=menu]').count(),
    '| dont contenant un <button> IMBRIQUE :', await page.evaluate(() =>
      [...document.querySelectorAll('button[aria-haspopup=menu]')].filter((b) => b.querySelector('button')).length));
  console.log('  cases a cocher sans libelle :', await page.evaluate(() =>
    [...document.querySelectorAll('input[type=checkbox]')].filter((c) =>
      !c.getAttribute('aria-label') && !c.getAttribute('aria-labelledby') && !c.closest('label') &&
      !(c.id && document.querySelector('label[for="' + c.id + '"]'))).length),
    '/', await page.locator('input[type=checkbox]').count());

  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'] }, resultTypes: ['violations'] }));
  let tot = 0; const parImpact = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  for (const v of r.violations) { tot += v.nodes.length; parImpact[v.impact ?? 'minor'] += v.nodes.length; }
  console.log('  axe :', tot, 'noeuds |', JSON.stringify(parImpact));
  for (const v of r.violations) {
    console.log(`   - ${v.id} [${v.impact}] x${v.nodes.length} : ${v.help}`);
    for (const n of v.nodes.slice(0, 4)) {
      const d = n.any?.[0]?.data ?? {};
      console.log(`       ${n.target.join(' ').slice(0, 110)}${d.contrastRatio ? ` (fg ${d.fgColor} / bg ${d.bgColor} = ${d.contrastRatio}:1)` : ''}`);
    }
  }
  const critiques = r.violations.filter((v) => v.impact === 'critical');
  console.log('  >>> ce que la porte a11y.yml retiendrait (elle ne garde QUE impact === "critical") :',
    critiques.map((v) => `${v.id} x${v.nodes.length}`).join(', ') || 'AUCUNE');
  await browser.close();
}
