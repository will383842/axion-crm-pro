// La fiche introuvable reste-t-elle VRAIMENT sur « Chargement… », ou est-ce
// seulement que je n'ai pas attendu ? On mesure sur 40 s, à intervalles, et on
// prend un témoin : la MÊME page sur un identifiant qui EXISTE.
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

const rapport = {};
for (const [nom, route] of [['introuvable', '/companies/999999'], ['temoin-existe', '/companies/1']]) {
  const page = await ctx.newPage();
  const appels = [];
  page.on('response', (r) => { if (r.url().includes('/api/v1/companies/')) appels.push(r.status() + ' ' + r.url().split('/api/v1')[1]); });
  await page.goto(BASE + route, { waitUntil: 'domcontentloaded' });
  const releves = [];
  for (const t of [2, 5, 10, 20, 30, 40]) {
    await page.waitForTimeout(t * 1000 - (releves.length ? [2, 5, 10, 20, 30, 40][releves.length - 1] * 1000 : 0));
    releves.push({
      t,
      h1: await page.evaluate(() => (document.querySelector('h1') || {}).textContent?.trim() || null),
      corps: await page.evaluate(() => (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 160)),
      // le renderer répond-il encore ? (piège du 23/08 : conclure au gel sans le prouver)
      vivant: await page.evaluate(() => 1 + 1) === 2,
    });
  }
  await page.screenshot({ path: process.argv[3] + '-' + nom + '.png' });
  rapport[nom] = { route, appels: [...new Set(appels)], releves };
  await page.close();
}
fs.writeFileSync(process.argv[3] + '.json', JSON.stringify(rapport, null, 2));
console.log(JSON.stringify(rapport, null, 2));
await b.close();
