// AGENT 30 — mesure des 37 ecrans en 375 px
// Usage : node mesure375.mjs <baseURL> <dossierPreuves> [routes,a,capturer]
import { chromium, devices } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.argv[2] || 'http://app.localhost:58130';
const OUT = process.argv[3];
const SHOTS = new Set((process.argv[4] || '').split(',').filter(Boolean));

const ROUTES = [
  ['/', 'Tableau de bord'],
  ['/companies', 'Entreprises'],
  ['/companies/11111111-1111-1111-1111-111111111111', 'Fiche entreprise'],
  ['/contacts', 'Contacts'],
  ['/international/roumanie', 'Roumanie'],
  ['/media', 'Medias'],
  ['/media/22222222-2222-2222-2222-222222222222', 'Fiche media'],
  ['/journalists', 'Journalistes'],
  ['/coverage', 'Couverture France'],
  ['/scraper-runs', 'Journaux de collecte'],
  ['/campaigns', 'Collectes'],
  ['/campaigns/new', 'Nouvelle collecte'],
  ['/campaigns/33333333-3333-3333-3333-333333333333', 'Fiche collecte'],
  ['/audiences', 'Audiences'],
  ['/audiences/new', 'Nouvelle audience'],
  ['/audiences/44444444-4444-4444-4444-444444444444', 'Fiche audience'],
  ['/tags', 'Tags'],
  ['/users', 'Utilisateurs'],
  ['/settings', 'Parametres'],
  ['/llm/router', 'LLM Router'],
  ['/llm/proxy-providers', 'Proxies'],
  ['/llm/rotations', 'Rotations'],
  ['/rgpd/requests', 'Requetes RGPD'],
  ['/rgpd/ai-act', 'Registre AI Act'],
  ['/audit-logs', 'Journaux audit'],
  ['/admin/observability', 'Observabilite'],
  ['/console/contacts', 'Console contacts'],
  ['/console/vivier', 'Console vivier'],
  ['/console/arbitrage', 'Console arbitrage'],
  ['/console/personnes/abc123', 'Fiche 360'],
  ['/cold-email', 'Cold email (stub)'],
  ['/linkedin', 'LinkedIn (stub)'],
  ['/login', 'Connexion'],
  ['/2fa', '2FA'],
  ['/magic-link', 'Lien magique'],
  ['/password-reset', 'Mot de passe oublie'],
  ['/route-inexistante-agent30', '404'],
];

const MESURE = () => {
  const de = document.documentElement;
  const VW = de.clientWidth;
  const W = window;
  const vis = (e) => {
    const s = W.getComputedStyle(e);
    if (s.display === 'none' || s.visibility === 'hidden') return false;
    const r = e.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const cls = (e) => (e.className && e.className.baseVal !== undefined ? e.className.baseVal : String(e.className || ''));
  const all = Array.from(document.querySelectorAll('*'));
  const main = document.getElementById('main');

  const scrollableAncestor = (e) => {
    let p = e.parentElement;
    while (p) {
      const s = W.getComputedStyle(p);
      if (/(auto|scroll)/.test(s.overflowX) && p.scrollWidth > p.clientWidth + 1) return p;
      p = p.parentElement;
    }
    return null;
  };
  const offenders = [];
  for (const e of all) {
    if (!vis(e)) continue;
    const r = e.getBoundingClientRect();
    if (r.right > VW + 1 && !scrollableAncestor(e)) {
      offenders.push({ tag: e.tagName.toLowerCase(), cls: cls(e).slice(0, 90), w: Math.round(r.width), right: Math.round(r.right), txt: (e.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 50) });
    }
  }
  const scrollers = [], clipped = [];
  for (const e of all) {
    if (!vis(e)) continue;
    const s = W.getComputedStyle(e);
    if (e.scrollWidth > e.clientWidth + 1) {
      const rec = { tag: e.tagName.toLowerCase(), cls: cls(e).slice(0, 80), sw: e.scrollWidth, cw: e.clientWidth, ox: s.overflowX };
      if (/(auto|scroll)/.test(s.overflowX)) scrollers.push(rec);
      else if (s.overflowX === 'hidden' || s.overflowX === 'clip') clipped.push(rec);
    }
  }

  const SEL = 'a[href],button,input:not([type=hidden]),select,textarea,[role="button"],[role="tab"],[role="menuitem"],[tabindex]:not([tabindex="-1"]),summary';
  const inter = Array.from(document.querySelectorAll(SEL)).filter(vis);
  const cibles = inter.map((e) => {
    const r = e.getBoundingClientRect();
    return {
      tag: e.tagName.toLowerCase(), w: +r.width.toFixed(1), h: +r.height.toFixed(1),
      ok: r.width >= 44 && r.height >= 44,
      coquille: !!e.closest('header[role="banner"]'),
      lbl: ((e.getAttribute('aria-label') || e.innerText || e.value || e.placeholder || '') + '').trim().replace(/\s+/g, ' ').slice(0, 40),
    };
  });

  const fixedBottom = all.filter((e) => {
    if (!vis(e)) return false;
    const s = W.getComputedStyle(e);
    if (s.position !== 'fixed' && s.position !== 'sticky') return false;
    const r = e.getBoundingClientRect();
    return r.bottom > W.innerHeight - 8 && r.width > VW * 0.7 && r.top > W.innerHeight * 0.5;
  }).map((e) => ({ tag: e.tagName.toLowerCase(), cls: cls(e).slice(0, 80), txt: (e.innerText || '').replace(/\n/g, ' | ').slice(0, 120) }));

  const tables = Array.from(document.querySelectorAll('table')).filter(vis).map((e) => ({ sw: e.scrollWidth, cw: e.clientWidth, parentOX: W.getComputedStyle(e.parentElement).overflowX }));
  const filArr = Array.from(document.querySelectorAll('header[role="banner"] .truncate')).map((e) => ({ sw: e.scrollWidth, cw: e.clientWidth, txt: (e.innerText || '').replace(/\n/g, ' ').trim() }));

  return {
    VW, pageSW: de.scrollWidth, pageOverflow: de.scrollWidth > VW + 1,
    mainSW: main ? main.scrollWidth : null, mainCW: main ? main.clientWidth : null,
    mainOX: main ? W.getComputedStyle(main).overflowX : null,
    pxHorsEcran: main ? Math.max(0, main.scrollWidth - main.clientWidth) : 0,
    offenders: offenders.slice(0, 12), nOffenders: offenders.length,
    scrollers, clipped, filAriane: filArr[0] || null,
    cibles, nCibles: cibles.length, nSous44: cibles.filter((c) => !c.ok).length,
    nSous44Coquille: cibles.filter((c) => !c.ok && c.coquille).length,
    nSous44Contenu: cibles.filter((c) => !c.ok && !c.coquille).length,
    conformes: cibles.filter((c) => c.ok).map((c) => c.lbl + ' ' + c.w + 'x' + c.h),
    fixedBottom, nBarreBasse: fixedBottom.length,
    tables, gridRows: document.querySelectorAll('[role="row"]').length,
    h1: (document.querySelector('h1') || {}).innerText || null,
    mainTxtLen: main ? (main.innerText || '').trim().length : null,
  };
};

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'fr-FR' });
const p = await ctx.newPage();
const res = [];
for (const [route, nom] of ROUTES) {
  try { await p.goto(BASE + route, { waitUntil: 'load', timeout: 25000 }); } catch (e) { /* SPA */ }
  await p.waitForTimeout(2600);
  const m = await p.evaluate(MESURE);
  m.route = route; m.nom = nom; res.push(m);
  if (OUT && (SHOTS.size === 0 || SHOTS.has(route))) {
    const f = route.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'accueil';
    await p.screenshot({ path: path.join(OUT, 'captures-375', f + '.png'), fullPage: false });
  }
  console.log(route.padEnd(48) + ' vw=' + m.VW + ' main=' + m.mainSW + '/' + m.mainCW + ' horsEcran=' + m.pxHorsEcran + 'px off=' + m.nOffenders + ' scrollers=' + m.scrollers.length + ' cibles=' + m.nCibles + ' sous44=' + m.nSous44 + ' (coq ' + m.nSous44Coquille + ' / contenu ' + m.nSous44Contenu + ') tables=' + m.tables.length + ' barreBasse=' + m.nBarreBasse);
}
const tot = {
  ecrans: res.length,
  ecransHorsEcran: res.filter((r) => r.pxHorsEcran > 0).map((r) => r.route + ' (' + r.pxHorsEcran + 'px)'),
  totalCibles: res.reduce((a, r) => a + r.nCibles, 0),
  totalSous44: res.reduce((a, r) => a + r.nSous44, 0),
  totalSous44Coquille: res.reduce((a, r) => a + r.nSous44Coquille, 0),
  totalSous44Contenu: res.reduce((a, r) => a + r.nSous44Contenu, 0),
  totalConformes: res.reduce((a, r) => a + (r.nCibles - r.nSous44), 0),
  totalBarresBasses: res.reduce((a, r) => a + r.nBarreBasse, 0),
  totalConteneursDefilables: res.reduce((a, r) => a + r.scrollers.length, 0),
  ecransAvecTable: res.filter((r) => r.tables.length > 0).map((r) => r.route),
};
console.log('\n=== TOTAUX ===\n' + JSON.stringify(tot, null, 2));
if (OUT) fs.writeFileSync(path.join(OUT, '01_mesure-375px.json'), JSON.stringify({ base: BASE, date: new Date().toISOString(), totaux: tot, ecrans: res }, null, 1));
await b.close();
