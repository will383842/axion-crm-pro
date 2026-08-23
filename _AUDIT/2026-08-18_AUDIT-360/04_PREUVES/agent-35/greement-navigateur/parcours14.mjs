// PARCOURS 14 du §11 — « Utilisateurs : créer un compte de rôle limité, SE
// CONNECTER AVEC, tenter d'atteindre ce qui est interdit. »
//
// Première étape : le produit permet-il seulement de créer ce compte ?
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
const page = await ctx.newPage();

const reseau = [];
page.on('response', async (r) => {
  if (r.url().includes('/api/v1/users')) {
    reseau.push({ methode: r.request().method(), statut: r.status(), corps: (await r.text().catch(() => '')).slice(0, 200) });
  }
});

const rapport = {};
await page.goto(BASE + '/users', { waitUntil: 'networkidle' });
await page.waitForTimeout(800);
rapport.ecran = await page.evaluate(() => (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 600));
console.log('ECRAN /users :\n ', rapport.ecran.slice(0, 400), '\n');
await page.screenshot({ path: OUT + '-1-ecran.png' });

// Le geste : cliquer « Inviter ».
const bouton = page.getByRole('button', { name: /^inviter$/i }).first();
const boutonLong = page.getByRole('button', { name: /inviter un utilisateur/i }).first();
const cible = (await bouton.count()) ? bouton : boutonLong;
console.log('bouton trouve :', await cible.count());
await cible.click();
await page.waitForTimeout(900);
await page.screenshot({ path: OUT + '-2-dialogue.png' });

rapport.dialogue = await page.evaluate(() => {
  const d = document.querySelector('[role="dialog"]');
  return d ? { texte: d.innerText.replace(/\s+/g, ' ').trim().slice(0, 400), champs: d.querySelectorAll('input,select').length } : { absent: true };
});
console.log('DIALOGUE :', JSON.stringify(rapport.dialogue, null, 1), '\n');

// Remplir et soumettre, comme le ferait un dirigeant qui veut ajouter un collegue.
if (!rapport.dialogue.absent) {
  const champs = page.locator('[role="dialog"] input:visible');
  const n = await champs.count();
  for (let i = 0; i < n; i++) {
    const type = await champs.nth(i).getAttribute('type');
    await champs.nth(i).fill(type === 'email' || i === 0 ? 'collegue@verif.localhost' : 'Collegue Test').catch(() => {});
  }
  await page.waitForTimeout(300);
  reseau.length = 0;
  for (const nom of [/^inviter$/i, /envoyer/i, /^cr[ée]er$/i, /valider/i, /confirmer/i]) {
    const bt = page.locator('[role="dialog"]').getByRole('button', { name: nom }).last();
    if (await bt.count()) { await bt.click().catch(() => {}); break; }
  }
  await page.waitForTimeout(3000);
  rapport.apresEnvoi = await page.evaluate(() => ({
    dialogueEncoreLa: !!document.querySelector('[role="dialog"]'),
    alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[data-sonner-toast]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
    corps: (document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 300),
  }));
  rapport.reseau = reseau;
  console.log('APRES ENVOI :');
  console.log('  alertes  :', JSON.stringify(rapport.apresEnvoi.alertes));
  console.log('  dialogue :', rapport.apresEnvoi.dialogueEncoreLa ? 'toujours ouvert' : 'ferme');
  console.log('  reseau   :', JSON.stringify(reseau, null, 1));
  await page.screenshot({ path: OUT + '-3-apres.png' });
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
