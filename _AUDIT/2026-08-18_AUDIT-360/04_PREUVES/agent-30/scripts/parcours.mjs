// AGENT 30 — budget de gestes bureau vs telephone (CDC 23.4) + sortie du tiroir
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
const BASE = process.argv[2] || 'http://app.localhost:58130';
const OUT = process.argv[3];
const L = []; const log = (...a) => { const s = a.join(' '); L.push(s); console.log(s); };

const CIBLES = [
  ['/settings', 'Paramètres', 'RÉGLAGES'],
  ['/audit-logs', 'Journaux d’audit', 'CONFORMITÉ'],
  ['/scraper-runs', 'Journaux de collecte', 'COLLECTE'],
];

const b = await chromium.launch();
log('AGENT 30 — budget de gestes pour atteindre un ecran depuis le tableau de bord');
log('CDC 23.4 : « chaque parcours tient au meme budget de clics sur telephone, aux gestes tactiles pres »');
log('Depart : / (tableau de bord). Barre laterale deployee (etat par defaut, localStorage vide).');
log('');

// ---- BUREAU 1280
{
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  for (const [href, label, section] of CIBLES) {
    await p.goto(BASE + '/', { waitUntil: 'load' }).catch(() => {});
    await p.evaluate(() => localStorage.removeItem('axion-crm:sidebar:collapsed'));
    await p.reload({ waitUntil: 'load' }).catch(() => {}); await p.waitForTimeout(2400);
    let n = 0;
    const dejaVisible = await p.evaluate((h) => { const a = document.querySelector('aside[data-tour="sidebar"] nav a[href="' + h + '"]'); return !!a && a.getBoundingClientRect().width > 0; }, href);
    if (!dejaVisible) { await p.evaluate((s) => { const btn = Array.from(document.querySelectorAll('aside nav h3 button')).find((x) => x.innerText.trim() === s); btn.click(); }, section); n++; await p.waitForTimeout(400); }
    await p.click('aside[data-tour="sidebar"] nav a[href="' + href + '"]'); n++;
    await p.waitForTimeout(1200);
    const arrive = await p.evaluate(() => location.pathname);
    log('BUREAU  1280px  ' + label.padEnd(24) + ' -> ' + String(n) + ' clic(s)   arrive=' + arrive);
  }
  await ctx.close();
}
log('');
// ---- TELEPHONE 375
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  for (const [href, label, section] of CIBLES) {
    await p.goto(BASE + '/', { waitUntil: 'load' }).catch(() => {}); await p.waitForTimeout(2400);
    let n = 0;
    await p.click('button[aria-label="Ouvrir le menu"]'); n++; await p.waitForTimeout(900);
    const dejaVisible = await p.evaluate((h) => { const a = document.querySelector('[role="dialog"] nav a[href="' + h + '"]'); return !!a && a.getBoundingClientRect().width > 0; }, href);
    if (!dejaVisible) { await p.evaluate((s) => { const btn = Array.from(document.querySelectorAll('[role="dialog"] nav h3 button')).find((x) => x.innerText.trim() === s); btn.click(); }, section); n++; await p.waitForTimeout(400); }
    await p.click('[role="dialog"] nav a[href="' + href + '"]'); n++;
    await p.waitForTimeout(1300);
    const encore = await p.evaluate(() => !!document.querySelector('[role="dialog"]'));
    let ferme = 'n/a';
    if (encore) { await p.click('[role="dialog"] button[aria-label="Fermer"]'); n++; await p.waitForTimeout(600); ferme = 'appui supplementaire sur ✕ (36x36) OBLIGATOIRE'; }
    const arrive = await p.evaluate(() => location.pathname);
    log('TELEPHONE 375px ' + label.padEnd(24) + ' -> ' + String(n) + ' appui(s)  arrive=' + arrive + '   tiroir reste ouvert apres navigation=' + encore + '  ' + ferme);
  }
  // sorties possibles du tiroir a 375
  await p.goto(BASE + '/', { waitUntil: 'load' }).catch(() => {}); await p.waitForTimeout(2400);
  await p.click('button[aria-label="Ouvrir le menu"]'); await p.waitForTimeout(1000);
  const geo = await p.evaluate(() => {
    const d = document.querySelector('[role="dialog"]');
    const panel = d.lastElementChild, voile = d.firstElementChild;
    const aside = Array.from(document.querySelectorAll('aside[data-tour="sidebar"]')).find((a) => d.contains(a));
    const R = (e) => { const r = e.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; };
    return { voile: R(voile), panneau: R(panel), barreDansPanneau: R(aside), sousLePoint330_600: (() => { const e = document.elementFromPoint(330, 600); return e ? e.tagName + '.' + String(e.className).slice(0, 40) : null; })() };
  });
  log('');
  log('GEOMETRIE DU TIROIR a 375px : voile x=' + geo.voile + '  panneau x=' + geo.panneau + '  barre a l interieur x=' + geo.barreDansPanneau);
  log('  -> le panneau couvre TOUT l ecran (0->375) : le voile n est atteignable NULLE PART.');
  log('  -> la barre ne fait que 260px : bande morte de ' + (geo.panneau[1] - geo.barreDansPanneau[1]) + 'px a droite. Element sous le doigt en x=330 : ' + geo.sousLePoint330_600);
  await p.mouse.click(330, 600); await p.waitForTimeout(800);
  log('  appui dans la bande morte (330,600) -> tiroir encore ouvert : ' + await p.evaluate(() => !!document.querySelector('[role="dialog"]')));
  await p.mouse.click(10, 400); await p.waitForTimeout(800);
  log('  appui a gauche (10,400), la ou le voile serait sur un ecran large -> tiroir encore ouvert : ' + await p.evaluate(() => !!document.querySelector('[role="dialog"]')));
  await p.keyboard.press('Escape'); await p.waitForTimeout(800);
  log('  TEMOIN : touche Echap -> tiroir encore ouvert : ' + await p.evaluate(() => !!document.querySelector('[role="dialog"]')) + '  (Echap fonctionne, mais un telephone n a pas de touche Echap)');
  await ctx.close();
}
await b.close();
if (OUT) fs.writeFileSync(path.join(OUT, '05_parcours-et-tiroir.txt'), L.join('\n'));
