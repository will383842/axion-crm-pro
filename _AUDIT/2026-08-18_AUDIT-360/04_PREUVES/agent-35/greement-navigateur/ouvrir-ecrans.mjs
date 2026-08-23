// Gréement d'ouverture des écrans — une page NEUVE, chargée À FROID, par écran.
// La règle « recharger l'onglet toutes les quelques pages » du journal devient
// structurelle : aucune page n'est réutilisée, aucun history.pushState n'enchaîne.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = 'http://verif.localhost:8080';
const JAR = process.argv[2];
const ROUTES = JSON.parse(process.argv[3]);
const OUT = process.argv[4];
const SHOTS = path.join(OUT, 'captures');
fs.mkdirSync(SHOTS, { recursive: true });

function lireJar(f) {
  return fs.readFileSync(f, 'utf8').split('\n')
    // ⚠️ NE PAS écarter les lignes '#' en bloc : curl préfixe '#HttpOnly_' au
    // domaine, et c'est exactement la ligne du cookie de SESSION. Le filtre naïf
    // ne transférait que XSRF-TOKEN — toute la passe rendait alors l'écran de
    // connexion, avec des 401 partout. Piège payé le 23/08, ne pas le repayer.
    .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
    .map((l) => l.split('\t'))
    .filter((c) => c.length >= 7)
    .map((c) => ({
      name: c[5], value: c[6].trim(),
      domain: c[0].replace(/^#HttpOnly_/, ''),
      path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'),
      secure: c[3] === 'TRUE', sameSite: 'Lax',
    }));
}

const navigateur = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const contexte = await navigateur.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await contexte.addCookies(lireJar(JAR));

const resultats = [];

for (const route of ROUTES) {
  const nom = route.replace(/[^a-z0-9]/gi, '_') || 'racine';
  const page = await contexte.newPage();
  const consoleErr = [];
  const reseauKo = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErr.push(m.text().slice(0, 300)); });
  page.on('pageerror', (e) => consoleErr.push('PAGEERROR ' + String(e).slice(0, 300)));
  page.on('requestfailed', (r) => reseauKo.push(r.url() + ' :: ' + (r.failure()?.errorText || '')));
  page.on('response', (r) => { if (r.status() >= 400) reseauKo.push(r.status() + ' ' + r.url()); });

  const t0 = Date.now();
  let statut = null, erreurNav = null;
  try {
    const rep = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 30000 });
    statut = rep?.status() ?? null;
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  } catch (e) { erreurNav = String(e).slice(0, 200); }
  const ms = Date.now() - t0;

  let vu = {};
  try {
    vu = await page.evaluate(() => {
      const txt = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
      const nav = document.querySelector('nav[aria-label*="ariane" i], nav[aria-label*="readcrumb" i], [aria-label*="ariane" i]');
      return {
        titre: document.title,
        url: location.pathname,
        h1: Array.from(document.querySelectorAll('h1')).map((h) => txt(h)),
        h2: Array.from(document.querySelectorAll('h2')).slice(0, 8).map((h) => txt(h)),
        ariane: txt(nav),
        lang: document.documentElement.lang,
        corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 1800),
        nbBoutons: document.querySelectorAll('button').length,
        nbLiens: document.querySelectorAll('a[href]').length,
        nbTables: document.querySelectorAll('table').length,
        champsSansNom: Array.from(document.querySelectorAll('input,select,textarea'))
          .filter((i) => i.type !== 'hidden' && !i.labels?.length && !i.getAttribute('aria-label') && !i.getAttribute('aria-labelledby')).length,
        nbChamps: document.querySelectorAll('input,select,textarea').length,
        imagesSansAlt: Array.from(document.querySelectorAll('img')).filter((i) => !i.hasAttribute('alt')).length,
        visible: document.visibilityState,
      };
    });
  } catch (e) { vu = { erreurLecture: String(e).slice(0, 200) }; }

  const capture = path.join(SHOTS, nom + '.png');
  try { await page.screenshot({ path: capture, fullPage: false }); } catch {}

  resultats.push({ route, statut, ms, erreurNav, ...vu, consoleErr: [...new Set(consoleErr)].slice(0, 6), reseauKo: [...new Set(reseauKo)].slice(0, 8), capture });
  console.log(`[${resultats.length}/${ROUTES.length}] ${route} -> ${statut} ${ms}ms  h1=${JSON.stringify(vu.h1)} titre=${JSON.stringify(vu.titre)}`);
  await page.close();
}

fs.writeFileSync(path.join(OUT, 'resultats.json'), JSON.stringify(resultats, null, 2));
await navigateur.close();
console.log('OK ' + resultats.length + ' écrans');
