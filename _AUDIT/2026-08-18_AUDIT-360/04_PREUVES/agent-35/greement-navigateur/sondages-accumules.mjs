// L'HYPOTHÈSE LAISSÉE OUVERTE LE 23/08 AU MATIN :
// « chaque écran installe un sondage périodique, les changements de route les
//   ACCUMULENT sans les arrêter, et le moteur finit par se figer. »
//
// On ne la teste PAS en regardant si ça gèle — ce chemin a déjà produit deux
// faux constats. On la teste par le seul témoin qui ne ment pas : le DÉBIT
// D'APPELS À L'API, mesuré sur la même fenêtre, avant et après une longue
// navigation SPA sans le moindre rechargement.
//
// Si les sondages s'accumulent, le débit au retour doit être NETTEMENT
// supérieur au débit de départ, sur le même écran.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const jar = fs.readFileSync(process.argv[2], 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await ctx.addCookies(jar);

// Instrumentation POSÉE AVANT LE DÉMARRAGE DE L'APPLICATION : on compte les
// minuteurs vivants, pas ceux qu'on croit vivants.
await ctx.addInitScript(() => {
  window.__minuteurs = { intervallesVivants: new Set(), poses: 0, coupes: 0 };
  const si = window.setInterval, ci = window.clearInterval;
  window.setInterval = function (...a) {
    const id = si.apply(this, a);
    window.__minuteurs.intervallesVivants.add(id); window.__minuteurs.poses++;
    return id;
  };
  window.clearInterval = function (id) {
    window.__minuteurs.intervallesVivants.delete(id); window.__minuteurs.coupes++;
    return ci.call(this, id);
  };
});

const page = await ctx.newPage();
let appels = [];
page.on('request', (r) => { if (r.url().includes('/api/v1/')) appels.push({ t: Date.now(), u: r.url().split('/api/v1')[1].split('?')[0] }); });

const etat = async () => page.evaluate(() => ({
  intervallesVivants: window.__minuteurs.intervallesVivants.size,
  poses: window.__minuteurs.poses,
  coupes: window.__minuteurs.coupes,
  observateursReactQuery: (() => { try { return window.__TANSTACK_QUERY_CLIENT__?.getQueryCache()?.getAll()?.length ?? null; } catch { return null; } })(),
}));

const fenetre = async (etiquette, secondes) => {
  appels = [];
  const t0 = Date.now();
  await page.waitForTimeout(secondes * 1000);
  const dedans = appels.filter((a) => a.t >= t0);
  const parRoute = {};
  for (const a of dedans) parRoute[a.u] = (parRoute[a.u] || 0) + 1;
  const e = await etat();
  const r = { etiquette, secondes, appels: dedans.length, debitParMinute: +(dedans.length / secondes * 60).toFixed(1), parRoute, ...e };
  console.log(`--- ${etiquette}: ${r.appels} appels en ${secondes}s (${r.debitParMinute}/min) · intervalles vivants=${r.intervallesVivants} (posés ${r.poses}, coupés ${r.coupes})`);
  return r;
};

const rapport = { etapes: [] };

// 1) référence : le tableau de bord, à froid, sans rien avoir visité.
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.waitForTimeout(2000);
rapport.etapes.push(await fenetre('AVANT — tableau de bord a froid', 40));

// 2) la longue navigation SPA : on CLIQUE, on ne recharge JAMAIS.
const parcours = ['/companies', '/contacts', '/media', '/journalists', '/coverage',
  '/scraper-runs', '/campaigns', '/audiences', '/tags', '/users', '/audit-logs',
  '/settings', '/admin/observability', '/llm/router', '/llm/rotations',
  '/rgpd/requests', '/rgpd/ai-act', '/console/contacts', '/console/arbitrage',
  '/international/roumanie'];
const visites = [];
for (const cible of parcours) {
  const lien = page.locator(`a[href="${cible}"]`).first();
  let mode = 'clic';
  if (await lien.count()) {
    await lien.click().catch(async () => { mode = 'routeur'; await page.evaluate((c) => window.history.pushState({}, '', c), cible); });
  } else {
    // pas de lien dans la barre latérale : on pousse par le routeur, toujours
    // SANS rechargement — c'est le geste que l'hypothèse accuse.
    mode = 'routeur';
    await page.evaluate((c) => { window.history.pushState({}, '', c); window.dispatchEvent(new PopStateEvent('popstate')); }, cible);
  }
  await page.waitForTimeout(2500);
  const e = await etat();
  visites.push({ cible, mode, url: page.url().replace(BASE, ''), ...e });
  console.log(`  visité ${cible} (${mode}) -> url=${page.url().replace(BASE, '')} intervalles=${e.intervallesVivants}`);
}
rapport.parcours = visites;

// 3) retour sur le MÊME écran qu'à l'étape 1, toujours sans rechargement.
const retour = page.locator('a[href="/"]').first();
if (await retour.count()) await retour.click().catch(() => {});
else await page.evaluate(() => { window.history.pushState({}, '', '/'); window.dispatchEvent(new PopStateEvent('popstate')); });
await page.waitForTimeout(2500);
rapport.etapes.push(await fenetre('APRES — meme ecran, 20 routes plus tard, sans un seul rechargement', 40));

// 4) le renderer répond-il encore ? (le gel a déjà été imputé a tort deux fois)
const t0 = Date.now();
const vivant = await page.evaluate(() => { const d = performance.now(); while (performance.now() - d < 5) {} return 'vivant'; });
rapport.rendererRepondEn_ms = Date.now() - t0;
rapport.renderer = vivant;
await page.screenshot({ path: process.argv[3] + '-final.png' });

fs.writeFileSync(process.argv[3] + '.json', JSON.stringify(rapport, null, 2));
console.log('\nrenderer:', vivant, 'en', rapport.rendererRepondEn_ms, 'ms');
await b.close();
