// PARCOURS 14, troisième temps — SE CONNECTER AVEC le compte limité et
// regarder ce que l'interface lui offre.
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
for (const route of ['/', '/users', '/companies', '/settings', '/audit-logs', '/console/contacts']) {
  const p = await ctx.newPage();
  const ko = [];
  p.on('response', (r) => { if (r.status() >= 400 && r.url().includes('/api/v1/')) ko.push(r.status() + ' ' + r.url().split('/api/v1')[1].split('?')[0]); });
  await p.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  await p.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
  await p.waitForTimeout(800);

  const vu = await p.evaluate(() => {
    const dangereux = /supprimer|effacer|inviter|lancer|d[ée]marrer|enregistrer|cr[ée]er|modifier|exporter|purger|r[ée]voquer/i;
    const boutons = Array.from(document.querySelectorAll('button:not([disabled])'))
      .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean);
    return {
      url: location.pathname,
      h1: (document.querySelector('h1') || {}).textContent?.trim() || null,
      // Les actions destructrices ou d'ecriture offertes a un LECTEUR
      actionsOffertes: [...new Set(boutons.filter((t) => dangereux.test(t) && t.length < 40))],
      corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 320),
    };
  });
  await p.screenshot({ path: `${OUT}-${route.replace(/[^a-z0-9]/gi, '_') || 'racine'}.png` });
  rapport[route] = { ...vu, reseauKo: [...new Set(ko)] };
  console.log(`\n── ${route}  (h1: ${JSON.stringify(vu.h1)})`);
  console.log('   actions d ECRITURE offertes :', JSON.stringify(vu.actionsOffertes));
  if (ko.length) console.log('   API en echec :', JSON.stringify([...new Set(ko)]));
  console.log('  ', vu.corps.slice(0, 200));
  await p.close();
}
fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
