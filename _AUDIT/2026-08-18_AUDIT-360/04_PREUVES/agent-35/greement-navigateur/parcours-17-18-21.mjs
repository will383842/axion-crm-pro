// PARCOURS 17 (recherche globale), 18 (sélecteur d'espace) et 21 (375 px,
// clavier seul, mode sombre) du §11.
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

// ─────────────────────────────────────────────────────────────────────────
// PARCOURS 21a — 375 px. Le corps de page doit-il défiler HORIZONTALEMENT ?
// ─────────────────────────────────────────────────────────────────────────
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, locale: 'fr-FR', isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await ctx.addCookies(jar);
  const ecrans = ['/', '/companies', '/console/contacts', '/audiences', '/settings', '/users', '/audit-logs', '/coverage'];
  const r = [];
  for (const e of ecrans) {
    const p = await ctx.newPage();
    await p.goto(BASE + e, { waitUntil: 'domcontentloaded' });
    await p.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
    await p.waitForTimeout(500);
    const m = await p.evaluate(() => {
      const de = document.documentElement;
      const debordants = [];
      for (const el of document.querySelectorAll('main *')) {
        const b = el.getBoundingClientRect();
        if (b.width > 0 && b.right > window.innerWidth + 1) {
          debordants.push((el.tagName.toLowerCase()) + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').slice(0, 2).join('.') : '') + ' [' + Math.round(b.right) + 'px]');
        }
      }
      return {
        largeurFenetre: window.innerWidth,
        largeurDocument: de.scrollWidth,
        defileHorizontal: de.scrollWidth > window.innerWidth + 1,
        debordants: [...new Set(debordants)].slice(0, 5),
        nbDebordants: debordants.length,
        h1: (document.querySelector('h1') || {}).textContent?.trim() || null,
      };
    });
    await p.screenshot({ path: `${OUT}-375-${e.replace(/[^a-z0-9]/gi, '_') || 'racine'}.png` });
    r.push({ ecran: e, ...m });
    console.log(`375px ${e.padEnd(20)} doc=${m.largeurDocument}px  defile=${m.defileHorizontal}  debordants=${m.nbDebordants}`);
    await p.close();
  }
  rapport.p21_375px = r;
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────
// PARCOURS 21b — AU CLAVIER SEUL. Le lien d'évitement, l'ordre de tabulation,
// et surtout : le focus est-il VISIBLE ?
// ─────────────────────────────────────────────────────────────────────────
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  await ctx.addCookies(jar);
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(600);
  const chaine = [];
  for (let i = 0; i < 22; i++) {
    await p.keyboard.press('Tab');
    const e = await p.evaluate(() => {
      const a = document.activeElement;
      if (!a || a === document.body) return { balise: '(aucun)', texte: null, contourVisible: false };
      const s = getComputedStyle(a);
      const largeurContour = parseFloat(s.outlineWidth) || 0;
      const ombre = s.boxShadow && s.boxShadow !== 'none';
      return {
        balise: a.tagName.toLowerCase(),
        texte: (a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 45),
        // un focus « visible » = un contour non nul OU une ombre portée
        contourVisible: (largeurContour > 0 && s.outlineStyle !== 'none') || ombre,
        contour: s.outline,
      };
    });
    chaine.push(e);
  }
  rapport.p21_clavier = {
    premierElement: chaine[0],
    sansFocusVisible: chaine.filter((e) => !e.contourVisible).length,
    total: chaine.length,
    chaine: chaine.map((e) => `${e.balise}:${e.texte}${e.contourVisible ? '' : '  ⚠️ SANS CONTOUR'}`),
  };
  console.log(`\nCLAVIER : ${rapport.p21_clavier.sansFocusVisible}/${chaine.length} elements SANS contour de focus visible`);
  console.log('  premier focusable :', JSON.stringify(chaine[0]?.texte));

  // ── PARCOURS 17 — la recherche globale, avec fautes et accents ──────────
  await p.keyboard.press('Control+k');
  await p.waitForTimeout(900);
  const paletteOuverte = await p.evaluate(() => !!document.querySelector('[role="dialog"], [cmdk-root], [data-palette]') || /rechercher/i.test(document.body.innerText.slice(0, 400)));
  const essais = [];
  for (const terme of ['Verif', 'verif', 'VÉRIF', 'vérif', 'Audit 360', 'verif audit', 'vrif', '552100554', 'redaction@exemple.test']) {
    const champ = p.locator('input:focus, [role="dialog"] input, [cmdk-input]').first();
    if (await champ.count()) {
      await champ.fill(terme).catch(() => {});
      await p.waitForTimeout(1100);
    }
    const vu = await p.evaluate(() => {
      const d = document.querySelector('[role="dialog"]') || document.body;
      return d.innerText.replace(/\s+/g, ' ').trim().slice(0, 260);
    });
    essais.push({ terme, vu });
    console.log(`  ⌘K « ${terme} » -> ${vu.slice(0, 110)}`);
  }
  rapport.p17_recherche = { paletteOuverte, essais };
  await p.screenshot({ path: OUT + '-p17-palette.png' });
  await p.keyboard.press('Escape');
  await p.waitForTimeout(400);

  // ── PARCOURS 18 — le sélecteur d'espace de travail ──────────────────────
  const sel = await p.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button')).find((x) => /workspace/i.test(x.textContent || ''));
    return b ? { texte: b.textContent.replace(/\s+/g, ' ').trim(), cliquable: !b.disabled } : null;
  });
  let apresClic = null;
  if (sel) {
    const bouton = p.getByRole('button', { name: /workspace/i }).first();
    await bouton.click().catch(() => {});
    await p.waitForTimeout(1200);
    apresClic = await p.evaluate(() => ({
      menuOuvert: !!document.querySelector('[role="menu"], [role="listbox"], [role="dialog"]'),
      texteVisible: document.body.innerText.replace(/\s+/g, ' ').slice(0, 300),
    }));
  }
  rapport.p18_selecteur = { selecteur: sel, apresClic };
  console.log('\nSELECTEUR D ESPACE :', JSON.stringify(sel), '-> menu ouvert :', apresClic?.menuOuvert);
  await p.screenshot({ path: OUT + '-p18-selecteur.png' });
  await p.close();
  await ctx.close();
}

// ─────────────────────────────────────────────────────────────────────────
// PARCOURS 21c — MODE SOMBRE. Le contraste tient-il ?
// ─────────────────────────────────────────────────────────────────────────
{
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', colorScheme: 'dark' });
  await ctx.addCookies(jar);
  const r = [];
  for (const e of ['/', '/companies', '/settings', '/audiences']) {
    const p = await ctx.newPage();
    await p.goto(BASE + e, { waitUntil: 'domcontentloaded' });
    await p.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
    await p.waitForTimeout(600);
    const m = await p.evaluate(() => {
      const lum = (c) => {
        const m = c.match(/\d+/g); if (!m) return null;
        const [r, g, bl] = m.map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
        return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
      };
      const fondPage = getComputedStyle(document.body).backgroundColor;
      const pires = [];
      for (const el of Array.from(document.querySelectorAll('main *')).slice(0, 900)) {
        if (!el.textContent || el.children.length > 0) continue;
        const s = getComputedStyle(el);
        const lt = lum(s.color); let fond = s.backgroundColor, n = el;
        while (fond === 'rgba(0, 0, 0, 0)' && n.parentElement) { n = n.parentElement; fond = getComputedStyle(n).backgroundColor; }
        const lf = lum(fond);
        if (lt === null || lf === null) continue;
        const ratio = (Math.max(lt, lf) + 0.05) / (Math.min(lt, lf) + 0.05);
        if (ratio < 4.5) pires.push({ texte: el.textContent.replace(/\s+/g, ' ').trim().slice(0, 40), ratio: +ratio.toFixed(2), couleur: s.color, fond });
      }
      pires.sort((a, b) => a.ratio - b.ratio);
      return { fondPage, theme: document.documentElement.getAttribute('data-theme') || document.documentElement.className.slice(0, 60), nbSousContraste: pires.length, pires: pires.slice(0, 6) };
    });
    await p.screenshot({ path: `${OUT}-sombre-${e.replace(/[^a-z0-9]/gi, '_') || 'racine'}.png` });
    r.push({ ecran: e, ...m });
    console.log(`SOMBRE ${e.padEnd(14)} fond=${m.fondPage}  textes sous 4.5:1 = ${m.nbSousContraste}`);
    await p.close();
  }
  rapport.p21_sombre = r;
  await ctx.close();
}

fs.writeFileSync(OUT + '.json', JSON.stringify(rapport, null, 2));
await b.close();
console.log('\nOK');
