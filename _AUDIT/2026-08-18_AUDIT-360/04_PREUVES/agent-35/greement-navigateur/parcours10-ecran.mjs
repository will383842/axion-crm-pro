// PARCOURS 10 du §11, joué À LA MAIN SUR L'ÉCRAN.
// On bâtit une audience avec un vrai critère, on LIT l'aperçu que l'écran
// affiche, on enregistre, et on regarde ce qui atterrit en base.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const jar = fs.readFileSync(process.argv[2], 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fr-FR' });
await ctx.addCookies(jar);
const page = await ctx.newPage();

const echanges = [];
page.on('request', (r) => {
  if (r.url().includes('/api/v1/audiences') && r.method() === 'POST') {
    echanges.push({ sens: 'ENVOYÉ  ', url: r.url().split('/api/v1')[1], corps: (r.postData() || '').slice(0, 400) });
  }
});
page.on('response', async (r) => {
  if (r.url().includes('/api/v1/audiences') && r.request().method() === 'POST') {
    echanges.push({ sens: 'REÇU    ', url: r.url().split('/api/v1')[1], corps: (await r.text().catch(() => '')).slice(0, 400) });
  }
});

await page.goto(BASE + '/audiences/new', { waitUntil: 'networkidle' });
await page.waitForTimeout(800);

// 1) le nom
await page.locator('input:visible').first().fill('P10 batie a l ecran');
await page.waitForTimeout(200);

// 2) UN vrai critere : le secteur « IT / SaaS ». Deux de nos quatre fiches le
//    portent — l'aperçu doit donc annoncer 2, et l'audience en contenir 2.
const puce = page.getByText('IT / SaaS', { exact: false }).first();
await puce.click();
await page.waitForTimeout(2500); // l'apercu se recalcule a chaque modification

const apercu = await page.evaluate(() => (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim());
const chiffres = apercu.match(/(Aper[^.]{0,120})/i);
fs.writeFileSync(process.argv[3] + '-apercu.txt', apercu);
console.log('APERÇU LU À L\'ÉCRAN :', chiffres ? chiffres[1] : '(motif non trouvé, voir le fichier)');

await page.screenshot({ path: process.argv[3] + '-avant.png' });

// 3) enregistrer
for (const nom of [/^créer$/i, /enregistrer/i, /^créer l/i, /valider/i]) {
  const bt = page.getByRole('button', { name: nom }).last();
  if (await bt.count()) { await bt.click(); break; }
}
await page.waitForTimeout(3500);
await page.screenshot({ path: process.argv[3] + '-apres.png' });
console.log('URL apres enregistrement :', page.url().replace(BASE, ''));

fs.writeFileSync(process.argv[3] + '-echanges.json', JSON.stringify(echanges, null, 2));
for (const e of echanges) console.log(e.sens, e.url, '::', e.corps.slice(0, 260));
await b.close();
