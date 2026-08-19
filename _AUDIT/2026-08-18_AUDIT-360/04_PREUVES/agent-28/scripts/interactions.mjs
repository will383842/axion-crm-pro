import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const AXE_SRC = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const BASE = 'http://127.0.0.1:4188';

const log = (...a) => console.log(...a);
const browser = await chromium.launch();

const etat = (page) => page.evaluate(() => {
  const el = document.activeElement;
  return { tag: el?.tagName, id: el?.id, nom: (el?.getAttribute('aria-label') || el?.textContent || '').trim().slice(0, 30),
    dansDialog: !!el?.closest('[role=dialog]'), dansMenu: !!el?.closest('[role=menu]') };
});

// ══════════════════════════════════════════════════════════════════════════
log('══ A. Recherche globale (⌘K) — piège de focus, restitution du focus ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.click('button[aria-label="Recherche globale"]');
  await page.waitForTimeout(400);
  log('  dialog present :', await page.locator('[role=dialog][aria-label="Recherche globale"]').count());
  log('  focus a l ouverture :', JSON.stringify(await etat(page)));
  const suite = [];
  for (let i = 0; i < 6; i++) { await page.keyboard.press('Tab'); suite.push(await etat(page)); }
  log('  6 Tab :', JSON.stringify(suite.map(s => `${s.tag}"${s.nom}" dialog=${s.dansDialog}`)));
  log('  >>> SORTIES du dialogue :', suite.filter(s => !s.dansDialog).length, '/ 6');
  await page.keyboard.press('Escape'); await page.waitForTimeout(300);
  log('  apres Escape, dialogue ferme ?', (await page.locator('[role=dialog][aria-label="Recherche globale"]').count()) === 0);
  log('  focus restitue a :', JSON.stringify(await etat(page)));
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ B. Drawer mobile (barre laterale) — piege de focus ══');
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.click('button[aria-label="Ouvrir le menu"]');
  await page.waitForTimeout(500);
  log('  drawer present :', await page.locator('[role=dialog][aria-modal=true]').count());
  log('  focus a l ouverture :', JSON.stringify(await etat(page)));
  const suite = [];
  for (let i = 0; i < 10; i++) { await page.keyboard.press('Tab'); suite.push(await etat(page)); }
  log('  10 Tab, dans le dialogue ? :', JSON.stringify(suite.map(s => s.dansDialog)));
  log('  >>> SORTIES du dialogue :', suite.filter(s => !s.dansDialog).length, '/ 10');
  log('  arriere-plan encore atteignable au clavier ?', suite.some(s => !s.dansDialog));
  // aria-hidden / inert sur l'arriere-plan ?
  log('  arriere-plan inert/aria-hidden ?', JSON.stringify(await page.evaluate(() => {
    const main = document.querySelector('#main');
    return { inert: main?.hasAttribute('inert'), ariaHidden: main?.getAttribute('aria-hidden') };
  })));
  await page.keyboard.press('Escape'); await page.waitForTimeout(300);
  log('  Escape ferme ?', (await page.locator('[role=dialog][aria-modal=true]').count()) === 0);
  log('  focus restitue a :', JSON.stringify(await etat(page)));
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ C. Modal mobile (recherche) — piege de focus + axe SUR LA MODALE OUVERTE ══');
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.click('button[aria-label="Rechercher"]');
  await page.waitForTimeout(400);
  const n = await page.locator('[role=dialog][aria-modal=true]').count();
  log('  modale presente :', n);
  log('  aria-labelledby sur la modale ?', JSON.stringify(await page.evaluate(() => {
    const d = document.querySelector('[role=dialog][aria-modal=true]');
    return { ariaLabel: d?.getAttribute('aria-label'), ariaLabelledby: d?.getAttribute('aria-labelledby'), titreH2: d?.querySelector('h2')?.textContent };
  })));
  log('  focus a l ouverture :', JSON.stringify(await etat(page)));
  const suite = [];
  for (let i = 0; i < 8; i++) { await page.keyboard.press('Tab'); suite.push(await etat(page)); }
  log('  >>> SORTIES du dialogue :', suite.filter(s => !s.dansDialog).length, '/ 8');
  await page.addScriptTag({ content: AXE_SRC });
  const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'] }, resultTypes: ['violations'] }));
  log('  axe sur la modale ouverte :', JSON.stringify(r.violations.map(v => `${v.id}[${v.impact}]x${v.nodes.length}`)));
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ D. Menu utilisateur (DropdownMenu) — bouton imbrique, clavier, axe ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const trigger = page.locator('button[aria-haspopup=menu]').first();
  log('  declencheurs aria-haspopup=menu :', await page.locator('button[aria-haspopup=menu]').count());
  log('  boutons imbriques dans le declencheur :', await page.evaluate(() =>
    [...document.querySelectorAll('button[aria-haspopup=menu]')].filter(b => b.querySelector('button')).length));
  await trigger.click(); await page.waitForTimeout(300);
  log('  menu ouvert :', await page.locator('[role=menu]').count());
  log('  focus juste apres ouverture :', JSON.stringify(await etat(page)));
  await page.keyboard.press('ArrowDown'); await page.waitForTimeout(150);
  log('  apres FlecheBas :', JSON.stringify(await etat(page)), '(un menu ARIA doit deplacer le focus)');
  await page.keyboard.press('Tab'); await page.waitForTimeout(150);
  log('  apres Tab :', JSON.stringify(await etat(page)));
  await page.addScriptTag({ content: AXE_SRC });
  const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'] }, resultTypes: ['violations'] }));
  log('  axe menu ouvert :', JSON.stringify(r.violations.map(v => `${v.id}[${v.impact}]x${v.nodes.length}`)));
  await page.keyboard.press('Escape'); await page.waitForTimeout(200);
  log('  Escape ferme ?', (await page.locator('[role=menu]').count()) === 0, '| focus :', JSON.stringify(await etat(page)));
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ E. Onglets (role=tab) — fleches, aria-controls, tabpanel ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/settings', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  log('  role=tablist :', await page.locator('[role=tablist]').count(), '| role=tab :', await page.locator('[role=tab]').count(), '| role=tabpanel :', await page.locator('[role=tabpanel]').count());
  log('  onglets avec aria-controls :', await page.evaluate(() => [...document.querySelectorAll('[role=tab]')].filter(t => t.hasAttribute('aria-controls')).length));
  const t0 = page.locator('[role=tab]').first();
  await t0.focus();
  const avant = await page.evaluate(() => document.activeElement.textContent.trim().slice(0,20));
  await page.keyboard.press('ArrowRight'); await page.waitForTimeout(200);
  const apres = await page.evaluate(() => document.activeElement.textContent.trim().slice(0,20));
  log('  FlecheDroite deplace-t-elle le focus ?', avant, '->', apres, '|', avant !== apres ? 'OUI' : 'NON');
  log('  tabindex des onglets (roving ?) :', JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('[role=tab]')].map(t => t.getAttribute('tabindex')))));
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ F. /coverage — le SegmentedControl local a-t-il perdu tablist/aria-selected ? ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/coverage', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  log('  /coverage : role=tablist =', await page.locator('[role=tablist]').count(), '| role=tab =', await page.locator('[role=tab]').count(), '| aria-selected =', await page.locator('[aria-selected]').count());
  await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(800);
  log('  TEMOIN /dashboard (SegmentedControl du DS) : role=tablist =', await page.locator('[role=tablist]').count(), '| role=tab =', await page.locator('[role=tab]').count(), '| aria-selected =', await page.locator('[aria-selected]').count());
  await ctx.close();
}

// ══════════════════════════════════════════════════════════════════════════
log('\n══ G. Lien d evitement (skip-link) ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.keyboard.press('Tab');
  log('  1er Tab :', JSON.stringify(await etat(page)));
  log('  styles calcules du lien focalise :', JSON.stringify(await page.evaluate(() => {
    const a = document.querySelector('.skip-link'); a.focus();
    const cs = getComputedStyle(a); const r = a.getBoundingClientRect();
    return { left: cs.left, top: cs.top, background: cs.backgroundColor, color: cs.color, padding: cs.padding, zIndex: cs.zIndex, rect: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) } };
  })));
  // Quelle couleur est REELLEMENT derriere le lien ? (fond transparent = texte sur le contenu)
  log('  couleur derriere le lien (element a ce point) :', JSON.stringify(await page.evaluate(() => {
    const a = document.querySelector('.skip-link'); const r = a.getBoundingClientRect();
    const els = document.elementsFromPoint(r.x + r.width / 2, r.y + r.height / 2).slice(0, 4);
    return els.map(e => ({ tag: e.tagName, cls: (e.className || '').toString().slice(0, 40), bg: getComputedStyle(e).backgroundColor }));
  })));
  await page.keyboard.press('Enter'); await page.waitForTimeout(300);
  log('  apres Entree sur le lien, focus :', JSON.stringify(await etat(page)), '| hash =', await page.evaluate(() => location.hash));
  await ctx.close();
}

await browser.close();
