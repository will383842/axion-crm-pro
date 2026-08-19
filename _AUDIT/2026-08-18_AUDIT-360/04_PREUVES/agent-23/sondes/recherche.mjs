// Agent 23 — « Retrouver ce qui a été dit à un contact » (CDC §23.4 : recherche → FICHE).
// On tape un nom dans ⌘K, on clique le contact trouvé, et on regarde OÙ on atterrit.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
const OUT = process.argv[2];
mkdirSync(OUT, { recursive: true });
const USER = { id:'u1', email:'t@a.local', name:'T', current_workspace_id:'ws1', totp_enabled_at:null,
  first_login_completed_at:'2026-01-01T00:00:00Z', onboarding_tour_completed_at:'2026-01-01T00:00:00Z' };

const b = await chromium.launch();
const c = await b.newContext({ ignoreHTTPSErrors:true, viewport:{width:1440,height:900} });
const p = await c.newPage();
await p.route('**/auth/me', r => r.fulfill({ json:{ user:USER, roles:['owner'] } }));
await p.route('**/config/features', r => r.fulfill({ json:{ console_v2:true, universes:{business:true,vivier:true} } }));
await p.route('**/search?*', r => r.fulfill({ json: {
  companies: [{ id: 42, siren: '123456789', denomination: 'DUPONT SAS' }],
  contacts:  [{ id: 7, first_name: 'Marie', last_name: 'Dupont', email: 'marie@dupont.fr', company_id: 42 }],
  tags: [],
} }));

await p.goto('http://127.0.0.1:5199/', { waitUntil:'domcontentloaded' });
await p.waitForSelector('[data-tour="sidebar"]', { timeout:20000 });
await p.waitForTimeout(1500);

const trace = { urlDepart: p.url() };
await p.keyboard.press('Control+k');
await p.waitForTimeout(600);
await p.keyboard.type('Dupont', { delay: 60 });
await p.waitForTimeout(1500);
trace.paletteTexte = await p.locator('[role="dialog"]').innerText().catch(() => '(pas de dialogue)');
await p.screenshot({ path: `${OUT}/recherche-palette.png` });

// On clique le RÉSULTAT PERSONNE (« Marie Dupont »).
const res = p.getByText('Marie Dupont', { exact: false }).first();
trace.resultatPersonneVisible = await res.isVisible().catch(() => false);
if (trace.resultatPersonneVisible) { await res.click(); await p.waitForTimeout(1500); }
trace.urlApresClicSurLaPersonne = p.url();
trace.titreEcranAtteint = await p.locator('h1, h2').first().innerText().catch(() => '(aucun titre)');
await p.screenshot({ path: `${OUT}/recherche-apres-clic.png` });

writeFileSync(`${OUT}/recherche-vers-fiche.json`, JSON.stringify(trace, null, 2));
console.log(JSON.stringify(trace, null, 2));
await b.close();
