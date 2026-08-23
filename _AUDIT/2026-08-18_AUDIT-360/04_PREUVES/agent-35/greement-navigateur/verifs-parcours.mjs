// Trois vérifications qui décident si les relevés bruts sont des constats.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://verif.localhost:8080';
const OUT = process.argv[3];
const jar = fs.readFileSync(process.argv[2], 'utf8').split('\n')
  .filter((l) => l.trim() && (!l.startsWith('#') || l.startsWith('#HttpOnly_')))
  .map((l) => l.split('\t')).filter((c) => c.length >= 7)
  .map((c) => ({ name: c[5], value: c[6].trim(), domain: c[0].replace(/^#HttpOnly_/, ''), path: c[2], httpOnly: c[0].startsWith('#HttpOnly_'), secure: false, sameSite: 'Lax' }));

const b = await chromium.launch({ headless: false, args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1'] });
const rapport = {};

// ① 375 px : le tableau large est-il DÉFILABLE, ou coupé ?
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'fr-FR', isMobile: true, hasTouch: true });
  await ctx.addCookies(jar);
  const r = [];
  for (const e of ['/audit-logs', '/companies', '/users']) {
    const p = await ctx.newPage();
    await p.goto(BASE + e, { waitUntil: 'networkidle' }).catch(() => {});
    await p.waitForTimeout(700);
    r.push({ ecran: e, ...await p.evaluate(() => {
      // on cherche le conteneur qui PORTE le contenu large
      const larges = Array.from(document.querySelectorAll('main *'))
        .filter((el) => el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 100);
      return {
        conteneursDefilables: larges.map((el) => ({
          balise: el.tagName.toLowerCase(),
          classe: (typeof el.className === 'string' ? el.className : '').slice(0, 70),
          overflowX: getComputedStyle(el).overflowX,
          visible: el.clientWidth, contenu: el.scrollWidth,
        })).slice(0, 4),
        pageDefileHorizontalement: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    }) });
    await p.close();
  }
  rapport.conteneurs375 = r;
  for (const x of r) {
    console.log(`375px ${x.ecran} — page defile horiz. = ${x.pageDefileHorizontalement}`);
    for (const c of x.conteneursDefilables) console.log(`   ${c.balise}.${c.classe.split(' ')[0]}  overflow-x=${c.overflowX}  visible=${c.visible} contenu=${c.contenu}`);
  }
  await ctx.close();
}

// ② Le sélecteur d'espace : où mènent ses deux entrées ?
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  await ctx.addCookies(jar);
  const p = await ctx.newPage();
  const ko = [];
  p.on('response', (r) => { if (r.status() >= 400 && r.url().includes('/api/')) ko.push(r.status() + ' ' + r.url().split('/api/v1')[1]); });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  await p.getByRole('button', { name: /workspace/i }).first().click().catch(() => {});
  await p.waitForTimeout(900);
  const res = {};
  for (const libelle of ['Gérer les workspaces', 'Créer un workspace']) {
    await p.getByRole('button', { name: /workspace/i }).first().click().catch(() => {});
    await p.waitForTimeout(700);
    const cible = p.getByText(libelle, { exact: false }).first();
    if (await cible.count()) {
      await cible.click().catch(() => {});
      await p.waitForTimeout(2000);
      res[libelle] = {
        url: p.url().replace(BASE, ''),
        vu: await p.evaluate(() => (document.querySelector('[role="dialog"]') || document.querySelector('main') || document.body).innerText.replace(/\s+/g, ' ').trim().slice(0, 240)),
      };
      console.log(`\n« ${libelle} » -> ${res[libelle].url}\n   ${res[libelle].vu.slice(0, 200)}`);
      await p.keyboard.press('Escape').catch(() => {});
      await p.goto(BASE + '/', { waitUntil: 'networkidle' }).catch(() => {});
      await p.waitForTimeout(500);
    } else { res[libelle] = { absent: true }; }
  }
  rapport.selecteurEspace = { entrees: res, reseauKo: [...new Set(ko)] };
  console.log('\nreseau en echec :', JSON.stringify(rapport.selecteurEspace.reseauKo));
  await p.close(); await ctx.close();
}

// ③ La recherche : accents et courriel, dans le sens RÉALISTE
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  await ctx.addCookies(jar);
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(700);
  await p.keyboard.press('Control+k');
  await p.waitForTimeout(900);
  const essais = [];
  // La fiche « Société Générale de Vérification » a été semée AVANT ce script.
  for (const t of ['Société', 'Societe', 'societe generale', 'Générale', 'Generale', 'Vérification', 'Verification', 'jean.dupont@exemple.test', 'Dupont', '0102030405']) {
    const champ = p.locator('input:focus, [role="dialog"] input').first();
    if (await champ.count()) { await champ.fill(t).catch(() => {}); await p.waitForTimeout(1100); }
    const vu = await p.evaluate(() => {
      const d = document.querySelector('[role="dialog"]') || document.body;
      return d.innerText.replace(/\s+/g, ' ').trim().slice(0, 200);
    });
    const trouve = !/aucun r.sultat/i.test(vu);
    essais.push({ terme: t, trouve, vu });
    console.log(`  ⌘K « ${t.padEnd(28)} » -> ${trouve ? 'TROUVE ' : 'RIEN   '} ${vu.slice(0, 90)}`);
  }
  rapport.recherche = essais;
  await p.screenshot({ path: OUT + '-recherche.png' });
  await p.close(); await ctx.close();
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
console.log('\nOK');
