// AGENT 24 — pourquoi trois parcours réels s'arrêtent : bouton absent, ou bouton DÉSACTIVÉ ?
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { poserLesDoublures } from './mock.mjs';
const OUT = process.argv[2]; const BASE = 'http://127.0.0.1:5224';
mkdirSync(OUT, { recursive: true });
const b = await chromium.launch();
const p = await (await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })).newPage();
await poserLesDoublures(p);
const etat = async () => p.evaluate(() => [...document.querySelectorAll('main button')].map(e => ({
  libelle: e.innerText.replace(/\s+/g, ' ').trim(), desactive: e.disabled, visible: e.getBoundingClientRect().height > 0 })));
const out = {};

await p.goto(BASE + '/companies', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500);
out['companies AVANT sélection'] = await etat();
await p.locator('main input[type="checkbox"]').first().click().catch(() => {});
await p.waitForTimeout(1000);
out['companies APRÈS 1 case cochée'] = await etat();
out['companies nb cases'] = await p.locator('main input[type="checkbox"]').count();
await p.screenshot({ path: `${OUT}/arret-companies-selection.png` });

await p.goto(BASE + '/console/arbitrage', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500);
out['arbitrage'] = await etat();
out['arbitrage champs'] = await p.evaluate(() => [...document.querySelectorAll('main input, main textarea')].map(e => ({ type: e.type, placeholder: e.placeholder, aria: e.getAttribute('aria-label') })));
await p.screenshot({ path: `${OUT}/arret-arbitrage.png` });

await p.goto(BASE + '/scraper-runs', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500);
out['scraper-runs'] = await etat();

await p.goto(BASE + '/settings', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500);
out['settings'] = await etat();

for (const [k, v] of Object.entries(out)) console.log('==', k, '\n  ', JSON.stringify(v).slice(0, 700));
writeFileSync(`${OUT}/arrets.json`, JSON.stringify(out, null, 2));
await b.close();
