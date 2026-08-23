// Que LIT l'utilisateur quand une validation serveur échoue ?
// L'API rend `validation.required` (clé brute, aucun lang/ dans le backend).
// Reste à savoir si l'écran la relaie telle quelle.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const JAR = process.argv[2];
const OUT = process.argv[3];

const jar = fs.readFileSync(JAR, 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await ctx.addCookies(jar);
const page = await ctx.newPage();

// On force une réponse 422 du serveur depuis l'écran lui-même, et on regarde
// ce que le gestionnaire d'erreurs du frontend en fait (toast, bandeau, rien).
await page.goto(BASE + '/audiences/new', { waitUntil: 'networkidle' });
await page.waitForTimeout(500);

const brut = await page.evaluate(async () => {
  const jeton = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
  const r = await fetch('/api/v1/audiences', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': jeton },
    body: JSON.stringify({ description: 'sans nom' }),
  });
  return { statut: r.status, corps: await r.text() };
});

// Puis le geste réel : remplir le nom, laisser le reste vide, et enregistrer.
const avant = (document) => null;
let vuApresGeste = null;
try {
  const nom = page.locator('input').first();
  await nom.fill('Audience de vérification');
  await page.waitForTimeout(300);
  const boutons = await page.locator('button').allTextContents();
  fs.writeFileSync(OUT + '-boutons.json', JSON.stringify(boutons, null, 2));
  const enregistrer = page.getByRole('button', { name: /enregistrer|créer|cré|valider|sauvegarder/i }).first();
  if (await enregistrer.count()) {
    await enregistrer.click();
    await page.waitForTimeout(2500);
  }
  vuApresGeste = await page.evaluate(() => ({
    url: location.pathname,
    corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 1200),
    alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[aria-live]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
    invalides: document.querySelectorAll('[aria-invalid="true"]').length,
  }));
} catch (e) { vuApresGeste = { erreur: String(e).slice(0, 200) }; }

await page.screenshot({ path: OUT + '.png' });
fs.writeFileSync(OUT + '.json', JSON.stringify({ brut, vuApresGeste }, null, 2));
console.log('API brute:', brut.statut, brut.corps.slice(0, 300));
console.log('APRES GESTE:', JSON.stringify(vuApresGeste, null, 2).slice(0, 1500));
await b.close();
