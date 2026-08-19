import { createRequire } from 'node:module';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:4188';
const ROUTES = [
  ['login', '/login'], ['2fa', '/2fa'], ['magic-link', '/magic-link'], ['password-reset', '/password-reset'],
  ['dashboard', '/'], ['companies', '/companies'], ['companies-$id', '/companies/1'],
  ['contacts', '/contacts'], ['roumanie', '/international/roumanie'],
  ['media', '/media'], ['media-$id', '/media/1'], ['journalists', '/journalists'],
  ['coverage', '/coverage'], ['scraper-runs', '/scraper-runs'],
  ['llm-router', '/llm/router'], ['llm-proxy-providers', '/llm/proxy-providers'], ['llm-rotations', '/llm/rotations'],
  ['rgpd-requests', '/rgpd/requests'], ['rgpd-ai-act', '/rgpd/ai-act'], ['audit-logs', '/audit-logs'],
  ['users', '/users'], ['settings', '/settings'],
  ['campaigns', '/campaigns'], ['campaigns-new', '/campaigns/new'], ['campaigns-$id', '/campaigns/1'],
  ['tags', '/tags'], ['audiences', '/audiences'], ['audiences-new', '/audiences/new'], ['audiences-$id', '/audiences/1'],
  ['admin-observability', '/admin/observability'],
  ['console-contacts', '/console/contacts'], ['console-vivier', '/console/vivier'],
  ['console-arbitrage', '/console/arbitrage'], ['console-personnes-$k', '/console/personnes/abc'],
  ['cold-email', '/cold-email'], ['linkedin', '/linkedin'],
  ['404', '/route-qui-nexiste-pas'],
];

const SONDE = `(() => {
  const SEL = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),summary,[contenteditable=""],[contenteditable=true]';
  const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && (r.width > 0 || r.height > 0); };
  const all = [...document.querySelectorAll(SEL)];
  return { focusablesDom: all.length, focusablesVisibles: all.filter(vis).length,
    tabIndexPositifs: all.filter(e => Number(e.getAttribute('tabindex')) > 0).length };
})()`;

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const out = [];

for (const [nom, url] of ROUTES) {
  const page = await ctx.newPage();
  const r = { nom, url };
  try {
    await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 25000 });
    await page.waitForTimeout(700);
    Object.assign(r, await page.evaluate(SONDE));

    // Parcours clavier réel : Tab jusqu'à 80 fois depuis <body>.
    await page.evaluate(() => document.body.focus());
    const seq = [];
    const N = Math.min(80, r.focusablesVisibles + 6);
    for (let i = 0; i < N; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return { tag: 'BODY' };
        const cs = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
        const ring = /rgb|rgba/.test(cs.boxShadow) && cs.boxShadow !== 'none';
        return {
          tag: el.tagName,
          nom: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40),
          x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.width), h: Math.round(rect.height),
          indicateur: outline ? 'outline' : ring ? 'box-shadow' : 'AUCUN',
          outlineWidth: cs.outlineWidth, outlineStyle: cs.outlineStyle, boxShadow: cs.boxShadow.slice(0, 60),
        };
      });
      seq.push(info);
      if (info.tag === 'BODY' && i > 2) break;
    }
    r.parcours = seq.length;
    r.sansIndicateur = seq.filter((s) => s.indicateur === 'AUCUN' && s.tag !== 'BODY').length;
    r.exemplesSansIndicateur = seq.filter((s) => s.indicateur === 'AUCUN' && s.tag !== 'BODY').slice(0, 5)
      .map((s) => `${s.tag}"${s.nom}"`);
    // Ordre de tabulation vs ordre visuel : nb d'inversions (y décroissant de plus de 40px)
    let inversions = 0;
    for (let i = 1; i < seq.length; i++) {
      const a = seq[i - 1], b = seq[i];
      if (a.y === undefined || b.y === undefined) continue;
      if (b.y < a.y - 40) inversions++;
    }
    r.inversionsOrdre = inversions;
    r.premierFocus = seq[0] ? `${seq[0].tag}"${seq[0].nom}"` : null;
  } catch (e) { r.erreur = String(e).slice(0, 150); }
  await page.close();
  out.push(r);
  console.error(`${nom.padEnd(24)} focusables=${r.focusablesVisibles} parcours=${r.parcours} sansIndicateur=${r.sansIndicateur} inversions=${r.inversionsOrdre} premier=${r.premierFocus}`);
}

await browser.close();
console.log(JSON.stringify(out, null, 1));
