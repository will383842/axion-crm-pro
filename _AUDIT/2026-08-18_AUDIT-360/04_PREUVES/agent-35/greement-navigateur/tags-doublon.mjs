// Le relais existe-t-il VRAIMENT à l'écran ?
// `/tags` duplique les règles du serveur côté client, en français — donc la
// longueur ne passe jamais. Mais le DOUBLON de slug, lui, n'a aucun miroir
// client : `TagsController.php:98-100` rend 409 { error: "slug already exists" },
// et `TagsManagerPage.tsx:137` affiche `extractApiMessage(err)` dans un toast.
// Geste réel : créer deux fois la même étiquette.
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
const page = await ctx.newPage();
const reponses = [];
page.on('response', async (r) => {
  if (r.url().includes('/api/v1/tags') && r.request().method() === 'POST') reponses.push({ statut: r.status(), corps: (await r.text().catch(() => '')).slice(0, 300) });
});

const trace = { tours: [] };
for (const tour of [1, 2]) {
  await page.goto(BASE + '/tags', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  const ouvrir = page.getByRole('button', { name: /nouveau tag/i }).first();
  await ouvrir.click();
  await page.waitForTimeout(700);
  await page.getByPlaceholder('Ex : VIP Client').fill('Etiquette de verification');
  await page.waitForTimeout(200);
  await page.getByRole('button', { name: /^créer$/i }).last().click();
  await page.waitForTimeout(3000);
  const vu = await page.evaluate(() => ({
    url: location.pathname,
    // les toasts vivent hors de <main> : on lit tout le document
    tout: document.body.innerText.replace(/\s+/g, ' ').trim(),
    alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[aria-live],[data-sonner-toast],.toast')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
  }));
  await page.screenshot({ path: process.argv[3] + '-tour' + tour + '.png' });
  trace.tours.push({ tour, alertes: vu.alertes, extraitFin: vu.tout.slice(-400) });
  console.log(`tour ${tour} — alertes: ${JSON.stringify(vu.alertes)}`);
}
trace.reponsesApi = reponses;
fs.writeFileSync(process.argv[3] + '.json', JSON.stringify(trace, null, 2));
console.log('reponses API:', JSON.stringify(reponses, null, 2));
await b.close();
