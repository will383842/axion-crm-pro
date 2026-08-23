// X39-017, la moitié qui manquait : l'API rend `validation.required` en clair.
// Est-ce que l'UTILISATEUR le lit ?
// Geste réel, à la main, sur /tags : créer une étiquette dont le nom dépasse
// 120 signes (`TagsController.php:91`, 'max:120'). C'est un collage un peu long,
// pas une manipulation d'auditeur.
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
  if (r.url().includes('/api/v1/tags') && r.request().method() === 'POST') {
    reponses.push({ statut: r.status(), corps: await r.text().catch(() => '') });
  }
});

await page.goto(BASE + '/tags', { waitUntil: 'networkidle' });
await page.waitForTimeout(800);

const trace = { etapes: [] };
const lire = async (quoi) => {
  const v = await page.evaluate(() => ({
    corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 900),
    alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[aria-live]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
  }));
  trace.etapes.push({ quoi, ...v });
  return v;
};
await lire('a l ouverture');

// Ouvrir le formulaire de création.
for (const nom of [/nouvelle étiquette/i, /nouveau tag/i, /créer/i, /ajouter/i, /nouvelle/i, /nouveau/i]) {
  const bt = page.getByRole('button', { name: nom }).first();
  if (await bt.count()) { await bt.click().catch(() => {}); break; }
}
await page.waitForTimeout(900);
await lire('formulaire ouvert');

const champs = await page.locator('input:visible, textarea:visible').count();
trace.champsVisibles = champs;
if (champs) {
  // Le premier <input> visible de la page est la RECHERCHE GLOBALE de l'en-tete,
  // pas le champ du formulaire. On vise le champ « Nom » du dialogue.
  const dialogue = page.locator('[role="dialog"]').first();
  const cible = (await dialogue.count())
    ? dialogue.locator('input:visible').first()
    : page.getByLabel(/^nom/i).first();
  await cible.fill('X'.repeat(200));
  await page.waitForTimeout(300);
  for (const nom of [/enregistrer/i, /créer/i, /valider/i, /ajouter/i, /confirmer/i]) {
    const portee = (await page.locator('[role="dialog"]').count()) ? page.locator('[role="dialog"]').first() : page;
    const bt = portee.getByRole('button', { name: nom }).last();
    if (await bt.count()) { await bt.click().catch(() => {}); break; }
  }
  await page.waitForTimeout(3000);
  await lire('apres soumission d un nom de 200 signes');
}
trace.reponsesApi = reponses;
trace.boutons = await page.locator('button:visible').allTextContents();
await page.screenshot({ path: process.argv[3] + '.png' });
fs.writeFileSync(process.argv[3] + '.json', JSON.stringify(trace, null, 2));
console.log(JSON.stringify(trace, null, 2).slice(0, 3000));
await b.close();
