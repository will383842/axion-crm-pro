import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = 'http://127.0.0.1:4188';

const FICHES = Array.from({ length: 5 }, (_, i) => ({
  id: i + 1, siren: `12345678${i}`, denomination: `Entreprise ${i + 1}`, naf: '62.01Z',
  size_category: ['artisan', 'tpe', 'pme', 'eti', 'grande_entreprise'][i], effectif_range: '10-19',
  city: 'Paris', postcode: '75001', quality_score: [95, 60, 20, 88, 45][i], priority: 'haute',
  enriched_at: '2026-08-01T10:00:00Z',
}));
const CORS = { 'access-control-allow-origin': 'http://127.0.0.1:4188', 'access-control-allow-credentials': 'true',
  'access-control-allow-headers': '*', 'access-control-allow-methods': '*', 'content-type': 'application/json' };

const browser = await chromium.launch();

// ══ 1. FIDÉLITÉ DE LA PORTE : ses tags exacts, ses 4 URL, son filtre ══════
console.log('== 1. La porte a11y.yml, rejouee a l identique, puis avec des donnees ==');
console.log('   (tags de tests/e2e/a11y.spec.ts : wcag2a, wcag2aa, wcag22aa ; filtre : impact === "critical")');
{
  const URLS = ['/login', '/companies', '/coverage', '/rgpd/requests'];
  for (const avecDonnees of [false, true]) {
    console.log(`\n  --- ${avecDonnees ? 'AVEC 5 fiches servies sur /companies' : 'SANS API (etat exact du runner GitHub)'} ---`);
    for (const u of URLS) {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
      const page = await ctx.newPage();
      if (avecDonnees) {
        await page.route('**/api/v1/**', (r) => r.request().method() === 'OPTIONS'
          ? r.fulfill({ status: 204, headers: CORS })
          : r.fulfill({ status: 200, headers: CORS, body: r.request().url().includes('/companies?')
              ? JSON.stringify({ data: FICHES, meta: { total: 5, last_page: 1, current_page: 1, per_page: 100 } })
              : '{"data":[],"meta":{"total":0,"last_page":1}}' }));
      }
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);
      const rootVide = await page.evaluate(() => (document.querySelector('#root')?.innerHTML ?? '').trim().length === 0);
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag22aa'] }, resultTypes: ['violations'] }));
      let tot = 0; const imp = { critical: 0, serious: 0, moderate: 0, minor: 0 };
      for (const v of r.violations) { tot += v.nodes.length; imp[v.impact ?? 'minor'] += v.nodes.length; }
      const crit = r.violations.filter((v) => v.impact === 'critical');
      console.log(`   ${u.padEnd(16)} #root vide=${rootVide} | violations=${r.violations.length} regles / ${tot} noeuds | ${JSON.stringify(imp)}`);
      console.log(`   ${''.padEnd(16)} regles: ${r.violations.map((v) => v.id + '[' + v.impact + ']x' + v.nodes.length).join(', ') || 'aucune'}`);
      console.log(`   ${''.padEnd(16)} >>> LE TEST ${crit.length ? 'ECHOUE' : 'PASSE'} (il n assert que sur critical) — critical retenus : ${crit.map((v) => v.id).join(', ') || 'aucun'}`);
      await ctx.close();
    }
  }
}

// ══ 2. NOMS ACCESSIBLES DES CHAMPS, à l'exécution, sur les 37 écrans ══════
console.log('\n\n== 2. Champs de formulaire : nom accessible et sa provenance (37 ecrans) ==');
const ROUTES = [
  ['login', '/login'], ['2fa', '/2fa'], ['magic-link', '/magic-link'], ['password-reset', '/password-reset'],
  ['dashboard', '/'], ['companies', '/companies'], ['companies-$id', '/companies/1'],
  ['contacts', '/contacts'], ['roumanie', '/international/roumanie'],
  ['media', '/media'], ['media-$id', '/media/1'], ['journalists', '/journalists'],
  ['coverage', '/coverage'], ['scraper-runs', '/scraper-runs'],
  ['llm-router', '/llm/router'], ['llm-proxy-providers', '/llm/proxy-providers'], ['llm-rotations', '/llm/rotations'],
  ['rgpd-requests', '/rgpd/requests'], ['rgpd-ai-act', '/rgpd/ai-act'], ['audit-logs', '/audit-logs'],
  ['users', '/users'], ['settings', '/settings'],
  ['campaigns', '/campaigns'], ['campaigns-new', '/campaigns/new'], ['campaigns-$id', '/campaigns/1'],
  ['tags', '/tags'], ['audiences', '/audiences'], ['audiences-new', '/audiences/new'], ['audiences-$id', '/audiences/1'],
  ['admin-observability', '/admin/observability'],
  ['console-contacts', '/console/contacts'], ['console-vivier', '/console/vivier'],
  ['console-arbitrage', '/console/arbitrage'], ['console-personnes-$k', '/console/personnes/abc'],
  ['cold-email', '/cold-email'], ['linkedin', '/linkedin'],
  ['404', '/route-qui-nexiste-pas'],
];

const SONDE = `(() => {
  const champs = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')];
  return champs.map((c) => {
    const id = c.id;
    const explicite = id ? !!document.querySelector('label[for="' + CSS.escape(id) + '"]') : false;
    const implicite = !!c.closest('label') && (c.closest('label').textContent || '').trim().length > 0;
    // Étiquette implicite : la spec HTML rattache le label à son PREMIER
    // descendant étiquetable — un <button> compte. On le vérifie.
    let implicitePremier = false;
    if (implicite) {
      const l = c.closest('label');
      const etiquetables = l.querySelectorAll('button,input:not([type=hidden]),select,textarea,meter,output,progress');
      implicitePremier = etiquetables[0] === c;
    }
    return { tag: c.tagName.toLowerCase(), type: c.type || '', id: id || null,
      ariaLabel: c.getAttribute('aria-label'), ariaLabelledby: c.getAttribute('aria-labelledby'),
      titre: c.getAttribute('title'), placeholder: c.getAttribute('placeholder'),
      explicite, implicite, implicitePremier,
      nom: explicite || implicitePremier || c.getAttribute('aria-label') || c.getAttribute('aria-labelledby') || c.getAttribute('title') ? 'OUI' : 'NON' };
  });
})()`;

let totalChamps = 0, totalSansNom = 0, totalPlaceholderSeul = 0;
const detail = [];
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  for (const [nom, url] of ROUTES) {
    try {
      await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 22000 });
      await page.waitForTimeout(700);
      const champs = await page.evaluate(SONDE);
      const sansNom = champs.filter((c) => c.nom === 'NON');
      const placeholderSeul = sansNom.filter((c) => c.placeholder);
      totalChamps += champs.length; totalSansNom += sansNom.length; totalPlaceholderSeul += placeholderSeul.length;
      if (champs.length) {
        console.log(`  ${nom.padEnd(22)} champs=${String(champs.length).padStart(2)} sansNomAccessible=${String(sansNom.length).padStart(2)} (dont ${placeholderSeul.length} avec placeholder seul)`);
        for (const c of sansNom) console.log(`     -> <${c.tag}${c.type ? ' type=' + c.type : ''}> placeholder=${JSON.stringify(c.placeholder)} implicite=${c.implicite} premierEtiquetable=${c.implicitePremier}`);
        detail.push({ nom, champs: champs.length, sansNom: sansNom.length });
      }
    } catch (e) { console.log(`  ${nom.padEnd(22)} ERREUR ${String(e).slice(0, 60)}`); }
  }
  await ctx.close();
}
console.log(`\n  TOTAL (etat SANS API) : ${totalChamps} champs rendus, ${totalSansNom} SANS nom accessible, dont ${totalPlaceholderSeul} n'ont qu'un placeholder.`);

// ── Témoin de la sonde de libellé ──────────────────────────────────────────
console.log('\n  -- TEMOIN de la sonde de libelle --');
{
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.setContent(`<!doctype html><html lang="fr"><body>
    <label for="a">A explicite</label><input id="a">
    <label>B implicite<input id="b"></label>
    <label>C : le premier etiquetable est un bouton<button>x</button><input id="c"></label>
    <input id="d" placeholder="D placeholder seul">
    <input id="e" aria-label="E aria-label">
  </body></html>`);
  const r = await page.evaluate(SONDE);
  for (const c of r) console.log(`     ${c.id} -> nom=${c.nom} (explicite=${c.explicite} implicite=${c.implicite} premier=${c.implicitePremier} aria=${c.ariaLabel})`);
  console.log('     attendu : a=OUI b=OUI c=NON d=NON e=OUI');
  await ctx.close();
}

await browser.close();
