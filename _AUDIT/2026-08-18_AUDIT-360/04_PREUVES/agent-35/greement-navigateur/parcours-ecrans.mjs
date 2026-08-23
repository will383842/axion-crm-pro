// Parcours 1, 2, 3, 4, 5, 15 et 19 du §11 — à l'écran, au clic.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const OUT = process.argv[3];
const jar = fs.readFileSync(process.argv[2], 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 950 }, locale: 'fr-FR' });
await ctx.addCookies(jar);
const rapport = {};

/** Ouvre une page NEUVE, à froid, et rend ce qu'on y voit. */
async function ouvrir(route, attente = 1200) {
  const p = await ctx.newPage();
  const ko = [];
  p.on('response', (r) => { if (r.status() >= 400 && r.url().includes('/api/v1/')) ko.push(r.status() + ' ' + r.url().split('/api/v1')[1].split('?')[0]); });
  p.on('pageerror', (e) => ko.push('PAGEERROR ' + String(e).slice(0, 120)));
  await p.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await p.waitForTimeout(attente);
  return { p, ko };
}
const lire = (p) => p.evaluate(() => ({
  h1: (document.querySelector('h1') || {}).textContent?.trim() || null,
  boutons: [...new Set(Array.from(document.querySelectorAll('button:not([disabled])')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 42))],
  corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 700),
}));

// ── PARCOURS 2 — le tableau de bord : chaque compteur, chaque lien ──────────
{
  const { p, ko } = await ouvrir('/');
  const v = await lire(p);
  const liens = await p.evaluate(() => Array.from(document.querySelectorAll('main a[href]')).map((a) => ({ texte: a.textContent.replace(/\s+/g, ' ').trim().slice(0, 40), href: a.getAttribute('href') })).filter((l) => l.texte));
  // chaque compteur est-il CLIQUABLE, comme le parcours l'exige ?
  const compteurs = await p.evaluate(() => Array.from(document.querySelectorAll('main [class*="card"],main [class*="Card"]'))
    .map((c) => { const t = c.textContent.replace(/\s+/g, ' ').trim(); return { extrait: t.slice(0, 46), cliquable: !!c.closest('a,button') || !!c.querySelector('a,button') }; })
    .filter((c) => /\d/.test(c.extrait)).slice(0, 10));
  rapport.p2_dashboard = { ...v, liens, compteurs, ko };
  console.log('\n══ P2 tableau de bord ══');
  console.log('  liens internes :', liens.length, '·', JSON.stringify(liens.slice(0, 6)));
  console.log('  compteurs      :', compteurs.map((c) => `${c.extrait.slice(0, 26)}${c.cliquable ? '' : ' [NON CLIQUABLE]'}`).join(' | ').slice(0, 400));
  if (ko.length) console.log('  API en echec   :', JSON.stringify([...new Set(ko)]));
  await p.screenshot({ path: OUT + '-p2.png' }); await p.close();
}

// ── PARCOURS 3 — hub contacts : recherche, filtres, tri, masse, export ──────
{
  const { p, ko } = await ouvrir('/console/contacts');
  const v = await lire(p);
  const outils = await p.evaluate(() => ({
    champsRecherche: document.querySelectorAll('input[type="search"],input[placeholder*="echerch" i]').length,
    cases: document.querySelectorAll('input[type="checkbox"]').length,
    entetesTriables: Array.from(document.querySelectorAll('th,[role="columnheader"]')).filter((h) => h.querySelector('button') || /sort/i.test(h.className)).length,
    pagination: /suivant|précédent|page/i.test(document.body.innerText),
  }));
  rapport.p3_hub = { ...v, outils, ko };
  console.log('\n══ P3 hub contacts ══');
  console.log('  h1:', JSON.stringify(v.h1), '· outils:', JSON.stringify(outils));
  console.log('  boutons:', JSON.stringify(v.boutons).slice(0, 300));
  if (ko.length) console.log('  API en echec :', JSON.stringify([...new Set(ko)]));
  await p.screenshot({ path: OUT + '-p3.png' }); await p.close();
}

// ── PARCOURS 4 — la fiche 360°, contre l'anatomie du §1.5 du CDC ────────────
{
  const cle = '95289698b544db25f9a4a74483189186589ea40df3be3a9e6ab2af18ee8facca';
  const { p, ko } = await ouvrir('/console/personnes/' + cle);
  const v = await lire(p);
  const anatomie = await p.evaluate(() => {
    const t = document.body.innerText;
    return {
      bandeau: /identit|fiche 360|touchpoint/i.test(t),
      timeline: /timeline|chronolog|touchpoint/i.test(t),
      universBusiness: /business/i.test(t),
      universVivier: /vivier/i.test(t),
      precedentSuivant: /précédent|suivant/i.test(t),
      note: /note/i.test(t), tache: /tâche|tache/i.test(t), tag: /tag|étiquette/i.test(t),
    };
  });
  rapport.p4_fiche360 = { ...v, anatomie, ko };
  console.log('\n══ P4 fiche 360 ══');
  console.log('  h1:', JSON.stringify(v.h1));
  console.log('  anatomie §1.5 :', JSON.stringify(anatomie));
  console.log('  boutons:', JSON.stringify(v.boutons).slice(0, 260));
  await p.screenshot({ path: OUT + '-p4.png' }); await p.close();
}

// ── PARCOURS 5 — vivier et arbitrage : l'étanchéité, attacher / écarter ─────
for (const [nom, route] of [['vivier', '/console/vivier'], ['arbitrage', '/console/arbitrage']]) {
  const { p, ko } = await ouvrir(route);
  const v = await lire(p);
  rapport['p5_' + nom] = { ...v, ko };
  console.log(`\n══ P5 ${nom} ══`);
  console.log('  h1:', JSON.stringify(v.h1), '· boutons:', JSON.stringify(v.boutons).slice(0, 200));
  console.log('  ', v.corps.slice(0, 220));
  await p.screenshot({ path: `${OUT}-p5-${nom}.png` }); await p.close();
}

// ── PARCOURS 15 — réglages : chaque onglet, sauvegarde, effet réel ──────────
{
  const { p, ko } = await ouvrir('/settings');
  const v = await lire(p);
  const onglets = await p.evaluate(() => Array.from(document.querySelectorAll('[role="tab"],button')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => /workspace|intégration|observabilit|apparence/i.test(t)));
  // Le geste : changer le nom et ENREGISTRER.
  const champNom = p.locator('input').first();
  let apresEnregistrement = null;
  if (await champNom.count()) {
    await champNom.fill('Axion-IA modifie par le parcours 15').catch(() => {});
    await p.waitForTimeout(300);
    const bt = p.getByRole('button', { name: /enregistrer/i }).first();
    if (await bt.count()) { await bt.click().catch(() => {}); await p.waitForTimeout(2500); }
    apresEnregistrement = await p.evaluate(() => ({
      alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[data-sonner-toast]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
    }));
  }
  rapport.p15_reglages = { ...v, onglets, apresEnregistrement, ko };
  console.log('\n══ P15 reglages ══');
  console.log('  onglets:', JSON.stringify(onglets));
  console.log('  apres « Enregistrer » :', JSON.stringify(apresEnregistrement));
  if (ko.length) console.log('  API en echec :', JSON.stringify([...new Set(ko)]));
  await p.screenshot({ path: OUT + '-p15.png' }); await p.close();
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
console.log('\nOK');
