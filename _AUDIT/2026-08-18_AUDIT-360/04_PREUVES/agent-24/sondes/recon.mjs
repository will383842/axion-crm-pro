// AGENT 24 — reconnaissance : ce que chaque écran offre réellement comme
// points d'appui (boutons, liens sortants), et s'il est un CUL-DE-SAC.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { poserLesDoublures } from './mock.mjs';

const OUT = process.argv[2];
const BASE = 'http://127.0.0.1:5224';
mkdirSync(OUT, { recursive: true });

const ROUTES = ['/', '/companies', '/companies/1', '/contacts', '/media', '/media/1', '/journalists',
  '/coverage', '/campaigns', '/campaigns/new', '/campaigns/7', '/scraper-runs', '/international/roumanie',
  '/audiences', '/audiences/new', '/audiences/3', '/admin/observability', '/tags',
  '/console/contacts', '/console/vivier', '/console/arbitrage', '/console/personnes/pk-demo',
  '/rgpd/requests', '/rgpd/ai-act', '/audit-logs', '/users', '/settings',
  '/llm/router', '/llm/proxy-providers', '/llm/rotations', '/cold-email', '/linkedin'];

const b = await chromium.launch();
const p = await (await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })).newPage();
const plantages = [];
p.on('pageerror', e => plantages.push({ url: p.url().replace(BASE, ''), err: String(e).split('\n')[0].slice(0, 140) }));
await poserLesDoublures(p);

const out = [];
for (const r of ROUTES) {
  await p.goto(BASE + r, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2200);
  const d = await p.evaluate(() => {
    const main = document.querySelector('main');
    const vis = (e) => { const b = e.getBoundingClientRect(); return b.width > 0 && b.height > 0; };
    return {
      coquille: !!document.querySelector('[data-tour="sidebar"]'),
      mainVide: !main || main.innerText.trim() === '',
      h1: (main?.querySelector('h1, h2')?.innerText || '(aucun)').trim().slice(0, 60),
      boutons: [...(main?.querySelectorAll('button') || [])].filter(vis).map(e => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 30),
      liensSortants: [...(main?.querySelectorAll('a[href]') || [])].filter(vis).map(e => e.getAttribute('href')).filter(h => h && h.startsWith('/')),
      texte: (main?.innerText || '').replace(/\s+/g, ' ').slice(0, 260),
    };
  });
  const hrefsUniques = [...new Set(d.liensSortants)];
  out.push({ route: r, ...d, liensSortants: hrefsUniques, nbLiensSortants: hrefsUniques.length,
    culDeSac: hrefsUniques.length === 0 });
  console.log(r.padEnd(32), d.coquille ? 'coquille' : '!!SANS COQUILLE!!', d.mainVide ? '!!MAIN VIDE!!' : '', '| liens=' + hrefsUniques.length, '| btns=' + d.boutons.length, '|', d.h1.slice(0, 34));
}
writeFileSync(`${OUT}/recon-ecrans.json`, JSON.stringify({ out, plantages }, null, 2));
console.log('\nPLANTAGES :', JSON.stringify(plantages));
await b.close();
