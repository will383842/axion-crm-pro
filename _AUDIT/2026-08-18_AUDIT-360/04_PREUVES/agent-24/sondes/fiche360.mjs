// AGENT 24 — la fiche 360° est-elle ATTEIGNABLE quand la `person_key` existe ?
// A05-001 mesure que 0 contact sur 1 319 567 en porte une. On lève ici cette
// contrainte-là dans la doublure, pour savoir si le CHEMIN existe au moins
// dans le code : si même avec une clé le hub n'offre aucun lien, le défaut
// n'est pas seulement une colonne vide, c'est aussi un écran sans porte.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { poserLesDoublures } from './mock.mjs';

const OUT = process.argv[2];
const BASE = 'http://127.0.0.1:5224';
mkdirSync(OUT, { recursive: true });

const b = await chromium.launch();
const p = await (await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })).newPage();
await poserLesDoublures(p);
// Réécriture APRÈS : la personne PORTE une person_key.
await p.route('**/api/v1/crm/contacts-hub?*', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({
  data: [{ id: 1, siren: '100000001', denomination: 'ENTREPRISE 1 SAS', relation_type: 'prospect',
    lifecycle_stage: 'nouveau', legal_basis: 'legitimate_interest_b2b', city_name: 'GRENOBLE',
    department_code: '38', size_category: 'pme', email_generic: 'c@ent1.fr',
    updated_at: '2026-08-10T10:00:00Z', tags: ['sect:tech'],
    contacts: [{ id: 7, first_name: 'Marie', last_name: 'DUPONT', email: 'marie@dupont.fr', phone: '0600000000', person_key: 'pk-demo' }] }],
  meta: { per_page: 25, next_cursor: null, prev_cursor: null, has_more: false },
}) }));

const out = {};
for (const [nom, route] of [['hub avec person_key', '/console/contacts'], ['liste /contacts', '/contacts'], ['fiche entreprise', '/companies/1'], ['fiche 360°', '/console/personnes/pk-demo']]) {
  await p.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2600);
  out[nom] = await p.evaluate(() => {
    const main = document.querySelector('main');
    return {
      liens: [...new Set([...(main?.querySelectorAll('a[href]') || [])].map(a => a.getAttribute('href')))],
      boutons: [...(main?.querySelectorAll('button') || [])].map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean),
      texte: (main?.innerText || '').replace(/\s+/g, ' ').slice(0, 700),
    };
  });
  out[nom].route = route;
  await p.screenshot({ path: `${OUT}/fiche360-${route.replace(/\W+/g, '_')}.png` });
  console.log('====', nom, route);
  console.log('  liens  :', JSON.stringify(out[nom].liens));
  console.log('  boutons:', JSON.stringify(out[nom].boutons.slice(0, 14)));
  console.log('  texte  :', out[nom].texte.slice(0, 380));
}
writeFileSync(`${OUT}/fiche360.json`, JSON.stringify(out, null, 2));
await b.close();
