/**
 * « tailles système » (CDC §23.5) et reflow (WCAG 1.4.4 / 1.4.10).
 *
 * Le réglage « taille de police » du navigateur agit sur la taille de police
 * MEDIUM de la racine ; `rem` suit, `px` ne suit pas. On reproduit le réglage
 * en portant la police racine de 16 px à 24 px (150 %, le cran « Très grande »
 * de Chrome) et on relit les tailles CALCULÉES.
 */
import { createRequire } from 'node:module';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:4188';

const browser = await chromium.launch();

console.log('== 1. Reglage « taille de police » du navigateur : 16 px -> 24 px ==');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  const mesure = async () => page.evaluate(() => {
    const hote = document.createElement('div');
    hote.style.cssText = 'position:fixed;left:0;top:400px';
    document.body.appendChild(hote);
    const cls = ['text-xs', 'text-sm', 'text-base', 'text-[10px]', 'text-[11px]'];
    const out = {};
    for (const c of cls) { const s = document.createElement('span'); s.className = c; s.textContent = 'x';
      hote.appendChild(s); out[c] = getComputedStyle(s).fontSize; }
    out['_racine'] = getComputedStyle(document.documentElement).fontSize;
    hote.remove();
    return out;
  });
  console.log('  a 16 px :', JSON.stringify(await mesure()));
  await page.addStyleTag({ content: ':root { font-size: 24px !important; }' });
  await page.waitForTimeout(200);
  console.log('  a 24 px :', JSON.stringify(await mesure()));
  console.log('  (les classes text-[10px]/text-[11px] ne bougent pas : 113 occurrences dans 41 fichiers)');
  await ctx.close();
}

console.log('\n== 2. Reflow a 320 px (WCAG 1.4.10) : debordement horizontal ==');
{
  const ROUTES = [
    ['login', '/login'], ['dashboard', '/'], ['companies', '/companies'], ['companies-$id', '/companies/1'],
    ['contacts', '/contacts'], ['roumanie', '/international/roumanie'], ['media', '/media'],
    ['journalists', '/journalists'], ['coverage', '/coverage'], ['scraper-runs', '/scraper-runs'],
    ['llm-router', '/llm/router'], ['llm-proxy-providers', '/llm/proxy-providers'], ['llm-rotations', '/llm/rotations'],
    ['rgpd-requests', '/rgpd/requests'], ['rgpd-ai-act', '/rgpd/ai-act'], ['audit-logs', '/audit-logs'],
    ['users', '/users'], ['settings', '/settings'], ['campaigns', '/campaigns'], ['campaigns-new', '/campaigns/new'],
    ['tags', '/tags'], ['audiences', '/audiences'], ['audiences-new', '/audiences/new'],
    ['admin-observability', '/admin/observability'], ['console-contacts', '/console/contacts'],
    ['console-vivier', '/console/vivier'], ['console-arbitrage', '/console/arbitrage'],
  ];
  const ctx = await browser.newContext({ viewport: { width: 320, height: 800 } });
  const page = await ctx.newPage();
  let deborde = 0;
  for (const [nom, url] of ROUTES) {
    try {
      await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 20000 });
      await page.waitForTimeout(500);
      const r = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
      const ko = r.scroll > r.client + 1;
      if (ko) deborde++;
      console.log(`  ${nom.padEnd(22)} scrollWidth=${r.scroll} clientWidth=${r.client} ${ko ? '<< DEBORDE' : ''}`);
    } catch (e) { console.log(`  ${nom.padEnd(22)} ERREUR`); }
  }
  console.log(`  >>> ${deborde} ecran(s) sur ${ROUTES.length} debordent horizontalement a 320 px`);
  await ctx.close();
}

console.log('\n== 3. TEMOIN de la sonde de debordement ==');
{
  const ctx = await browser.newContext({ viewport: { width: 320, height: 800 } });
  const page = await ctx.newPage();
  await page.setContent('<body style="margin:0"><div style="width:900px;height:10px;background:#000"></div></body>');
  console.log('  page volontairement large :', JSON.stringify(await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }))));
  await page.setContent('<body style="margin:0"><div style="width:100%;height:10px;background:#000"></div></body>');
  console.log('  page sage                 :', JSON.stringify(await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }))));
  await ctx.close();
}

console.log('\n== 4. Hierarchie de titres, ecran par ecran (echantillon) ==');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  for (const u of ['/companies', '/settings', '/coverage', '/tags']) {
    await page.goto(BASE + u, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    const h = await page.evaluate(() => [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((x) => x.tagName + ' « ' + x.textContent.trim().slice(0, 28) + ' »'));
    console.log(`  ${u} :`, JSON.stringify(h));
  }
  await ctx.close();
}

await browser.close();
