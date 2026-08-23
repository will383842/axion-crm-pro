// PARCOURS 18 du §11 — « Sélecteur d'espace de travail : le changement se
// voit-il partout ? la teinte ? les données ? »
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const OUT = process.argv[3];
const jar = fs.readFileSync(process.argv[2], 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await ctx.addCookies(jar);
const p = await ctx.newPage();
const appels = [];
p.on('request', (r) => { if (r.url().includes('/api/v1/')) appels.push(`${r.method()} ${r.url().split('/api/v1')[1].split('?')[0]}`); });
p.on('response', (r) => { if (r.status() >= 400 && r.url().includes('/api/v1/')) appels.push(`  !! ${r.status()} ${r.url().split('/api/v1')[1].split('?')[0]}`); });

await p.goto(BASE + '/', { waitUntil: 'networkidle' });
await p.waitForTimeout(700);

const rapport = {};
await p.getByRole('button', { name: /workspace/i }).first().click();
await p.waitForTimeout(900);
await p.screenshot({ path: OUT + '-menu-ouvert.png' });

rapport.menu = await p.evaluate(() => {
  const conteneur = document.querySelector('[role="menu"],[role="listbox"],[data-radix-popper-content-wrapper]')
    || Array.from(document.querySelectorAll('div')).find((d) => /Créer un workspace/.test(d.innerText || '') && d.innerText.length < 400);
  if (!conteneur) return { absent: true };
  return {
    texte: conteneur.innerText.replace(/\s+/g, ' ').trim().slice(0, 300),
    entrees: Array.from(conteneur.querySelectorAll('button,a,[role="menuitem"],[role="option"]'))
      .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
  };
});
console.log('MENU :', JSON.stringify(rapport.menu, null, 1).slice(0, 700));

// Cliquer chaque entrée d'action, une par une, en repartant du menu.
rapport.actions = {};
for (const libelle of (rapport.menu.entrees || []).filter((e) => /créer|gérer/i.test(e))) {
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await p.getByRole('button', { name: /workspace/i }).first().click().catch(() => {});
  await p.waitForTimeout(800);
  appels.length = 0;
  const avant = p.url();
  await p.getByRole('button', { name: libelle }).first().click().catch(async () => {
    await p.getByText(libelle, { exact: true }).first().click().catch(() => {});
  });
  await p.waitForTimeout(2500);
  const apres = await p.evaluate(() => ({
    url: location.pathname,
    dialogue: !!document.querySelector('[role="dialog"]'),
    texteDialogue: (document.querySelector('[role="dialog"]') || {}).innerText?.replace(/\s+/g, ' ').trim().slice(0, 200) || null,
    alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[data-sonner-toast]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
  }));
  rapport.actions[libelle] = { urlAvant: avant.replace(BASE, ''), ...apres, appelsApi: [...new Set(appels)] };
  console.log(`\n« ${libelle} » -> url=${apres.url}  dialogue=${apres.dialogue}  alertes=${JSON.stringify(apres.alertes)}`);
  console.log('   appels API :', JSON.stringify([...new Set(appels)]));
  await p.screenshot({ path: `${OUT}-apres-${libelle.replace(/[^a-z0-9]/gi, '_')}.png` });
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
console.log('\nOK');
