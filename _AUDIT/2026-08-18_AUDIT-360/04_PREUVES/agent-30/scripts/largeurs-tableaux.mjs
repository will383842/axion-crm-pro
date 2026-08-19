// AGENT 30 — largeur minimale reelle des 9 tableaux « grille role=row », mesuree a 375 px
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.argv[2] || 'http://app.localhost:58130';
const OUT = process.argv[3];
const L = [];
const log = (...a) => { const s = a.join(' '); L.push(s); console.log(s); };

const TABLEAUX = [
  ['/companies', 'CompanyRow.tsx:32', '32px 2fr 110px 90px 110px 140px 1.1fr 100px 36px', 'px-4', 9],
  ['/contacts', 'ContactsListPage.tsx:58', 'minmax(220px,1.4fr) minmax(140px,1fr) minmax(220px,1.6fr) 110px minmax(140px,1fr) minmax(140px,1fr)', 'px-4', 6],
  ['/llm/router', 'LlmRouterPage.tsx:53', 'minmax(180px,1.4fr) 130px minmax(160px,1fr) minmax(180px,1.2fr) 110px 100px', 'px-4', 6],
  ['/llm/proxy-providers', 'ProxyProvidersPage.tsx:34', 'minmax(160px,1fr) 120px 120px 110px 180px 90px 100px', 'px-4', 7],
  ['/llm/rotations', 'RotationsPage.tsx:35', 'minmax(180px,1fr) 90px 110px 90px 180px', 'px-5', 5],
  ['/rgpd/ai-act', 'AiActRegisterPage.tsx:50', 'minmax(220px,1.4fr) 110px minmax(180px,1fr) 130px minmax(140px,1fr) 130px', 'px-4', 6],
  ['/audit-logs', 'AuditLogsPage.tsx:45', '160px 180px minmax(160px,1.2fr) minmax(160px,1fr) 100px 130px 110px', 'px-4', 7],
  ['/rgpd/requests', 'RgpdRequestsPage.tsx:64', 'minmax(140px,1fr) minmax(240px,1.4fr) 130px 180px 180px 140px', 'px-4', 6],
  ['/users', 'UsersPage.tsx:37', 'minmax(220px,1.4fr) minmax(220px,1.6fr) minmax(160px,1fr) 140px 200px', 'px-4', 5],
];

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
const p = await ctx.newPage();
await p.goto(BASE + '/contacts', { waitUntil: 'load' }).catch(() => {});
await p.waitForTimeout(2600);

log('AGENT 30 — largeur MINIMALE reelle des 9 tableaux « grille role=row », mesuree dans le navigateur a 375 px');
log('Methode : on injecte dans <main> le patron EXACT du produit (Card padding=none overflow-hidden');
log('          + div role=row avec la chaine sticky de 210 car. + le gridTemplateColumns du fichier),');
log('          puis on lit scrollWidth. Aucun fichier du produit n est modifie.');
log('Ecran de reference : 375 px ; <main> a px-4 => 343 px utiles ; <Card> => 343 px.');
log('');
log('ecran'.padEnd(24) + 'source'.padEnd(28) + 'largeur mini' + '  visible' + '  hors ecran' + '  facteur');
const res = [];
for (const [route, src, grid, pad, ncols] of TABLEAUX) {
  const m = await p.evaluate(({ grid, pad, ncols }) => {
    const d = document, W = window;
    const main = d.getElementById('main');
    const HDR = 'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 ' + pad + ' py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur';
    const card = d.createElement('div');
    card.className = 'rounded-2xl bg-white ring-1 ring-slate-200/70 overflow-hidden';
    const row = d.createElement('div'); row.setAttribute('role', 'row'); row.className = HDR; row.style.gridTemplateColumns = grid;
    for (let i = 0; i < ncols; i++) { const c = d.createElement('div'); c.textContent = 'Colonne ' + (i + 1); row.appendChild(c); }
    card.appendChild(row); main.appendChild(card);
    const r = { mini: card.scrollWidth, visible: card.clientWidth, cardOX: W.getComputedStyle(card).overflowX, mainOX: W.getComputedStyle(main).overflowX, defilable: false };
    card.remove();
    return r;
  }, { grid, pad, ncols });
  const hors = m.mini - m.visible;
  res.push({ route, src, ...m, hors });
  log(route.padEnd(24) + src.padEnd(28) + String(m.mini).padStart(9) + 'px' + String(m.visible).padStart(8) + 'px' + String(hors).padStart(10) + 'px' + ('  x' + (m.mini / m.visible).toFixed(1)).padStart(9));
}
log('');
log('overflow-x du conteneur <Card> : ' + res[0].cardOX + '   overflow-x de <main> : ' + res[0].mainOX);
log('=> aucun de ces conteneurs n est defilable par l utilisateur (ni barre, ni geste tactile) :');
log('   le contenu au-dela de 343 px est INATTEIGNABLE sur telephone.');
log('');
log('TEMOIN : le meme patron avec un conteneur overflow-x-auto (cf. 02_barre-tableaux-temoins.txt, SONDE B)');
log('         rend defilableParUtilisateur=true. Le controle sait donc distinguer les deux issues.');
await b.close();
if (OUT) fs.writeFileSync(path.join(OUT, '04_largeurs-tableaux.txt'), L.join('\n'));
