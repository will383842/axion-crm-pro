// PARCOURS 1 (la chaîne d'authentification) et 19 (la visite guidée) du §11.
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

// ── PARCOURS 1 — les écrans d'authentification, SANS session ────────────────
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  const r = [];
  for (const route of ['/login', '/magic-link', '/password-reset']) {
    const p = await ctx.newPage();
    await p.goto(BASE + route, { waitUntil: 'networkidle' });
    await p.waitForTimeout(700);
    const v = await p.evaluate(() => ({
      h1: (document.querySelector('h1') || {}).textContent?.trim() || null,
      champs: Array.from(document.querySelectorAll('input')).map((i) => ({ type: i.type, nomAccessible: !!(i.labels?.length || i.getAttribute('aria-label')) })),
      boutons: Array.from(document.querySelectorAll('button')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
    }));
    // Le geste : soumettre À VIDE, puis avec une adresse malformée.
    const messages = [];
    for (const essai of ['', 'pas-une-adresse']) {
      const champ = p.locator('input[type="email"], input[type="text"]').first();
      if (await champ.count()) { await champ.fill(essai).catch(() => {}); }
      const bt = p.locator('button[type="submit"]').first();
      if (await bt.count()) { await bt.click().catch(() => {}); await p.waitForTimeout(1200); }
      messages.push({
        essai: essai || '(vide)',
        vu: await p.evaluate(() => {
          const inv = document.querySelector('input:invalid');
          return {
            validationNative: inv ? inv.validationMessage : null,
            invalides: document.querySelectorAll('[aria-invalid="true"]').length,
            alertes: Array.from(document.querySelectorAll('[role="alert"],[role="status"],[data-sonner-toast]')).map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean),
          };
        }),
      });
    }
    r.push({ route, ...v, messages });
    console.log(`\n── ${route}  h1=${JSON.stringify(v.h1)}`);
    console.log('   champs :', JSON.stringify(v.champs));
    for (const m of messages) console.log(`   « ${m.essai} » ->`, JSON.stringify(m.vu));
    await p.screenshot({ path: `${OUT}-p1-${route.replace(/\W/g, '_')}.png` });
    await p.close();
  }
  // Session expirée : un cookie mort doit ramener à /login, pas planter.
  const p2 = await ctx.newPage();
  await ctx.addCookies([{ name: 'axion_crm_session', value: 'cookie-mort-fabrique', domain: 'verif.localhost', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
  await p2.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await p2.waitForTimeout(1500);
  rapport.p1_sessionExpiree = await p2.evaluate(() => ({ url: location.pathname, h1: (document.querySelector('h1') || {}).textContent?.trim() || null }));
  console.log('\n── session morte sur /companies ->', JSON.stringify(rapport.p1_sessionExpiree));
  await p2.screenshot({ path: OUT + '-p1-session-morte.png' });
  rapport.p1_ecrans = r;
  await ctx.close();
}

// ── PARCOURS 19 — la visite guidée, du début à la fin ───────────────────────
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 950 }, locale: 'fr-FR' });
  await ctx.addCookies(jar);
  const p = await ctx.newPage();
  // La visite ne se relance que si elle n'est pas marquée terminée.
  await p.addInitScript(() => { try { window.localStorage.removeItem('axion-tour-done'); } catch { /* vide */ } });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1800);
  const etapes = [];
  for (let i = 0; i < 10; i++) {
    const v = await p.evaluate(() => {
      const d = document.querySelector('[role="dialog"]');
      if (!d) return null;
      return { texte: d.innerText.replace(/\s+/g, ' ').trim().slice(0, 220), boutons: Array.from(d.querySelectorAll('button')).map((b) => b.textContent.replace(/\s+/g, ' ').trim()) };
    });
    if (!v) break;
    etapes.push(v);
    const suivant = p.locator('[role="dialog"] button').filter({ hasText: /next|suivant|terminer|finish/i }).last();
    if (!(await suivant.count())) break;
    await suivant.click().catch(() => {});
    await p.waitForTimeout(800);
  }
  rapport.p19_visite = { nbEtapes: etapes.length, etapes };
  console.log('\n══ P19 visite guidee ══');
  console.log('  etapes vues :', etapes.length);
  for (const [i, e] of etapes.entries()) console.log(`   ${i + 1}. ${e.texte.slice(0, 100)}  | boutons: ${JSON.stringify(e.boutons)}`);
  await p.screenshot({ path: OUT + '-p19.png' });
  await p.close(); await ctx.close();
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
console.log('\nOK');
