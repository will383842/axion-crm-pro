import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const AXE_SRC = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const BASE = 'http://127.0.0.1:4188';
const DARK = process.env.DARK === '1';

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

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
await ctx.addInitScript((dark) => { try { localStorage.setItem('axion-theme', dark ? 'dark' : 'light'); } catch {} }, DARK);
const out = [];

for (const [nom, url] of ROUTES) {
  const page = await ctx.newPage();
  const res = { nom, url, mode: DARK ? 'sombre' : 'clair', erreur: null, htmlDark: null, total: 0,
    parImpact: { critical: 0, serious: 0, moderate: 0, minor: 0 }, regles: [], contrastes: [] };
  try {
    await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 25000 });
    await page.waitForTimeout(1000);
    res.htmlDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    res.tailleDom = await page.evaluate(() => document.querySelectorAll('*').length);
    await page.addScriptTag({ content: AXE_SRC });
    const r = await page.evaluate(async () => await axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
      resultTypes: ['violations'],
    }));
    for (const v of r.violations) {
      const imp = v.impact ?? 'minor';
      res.parImpact[imp] += v.nodes.length;
      res.total += v.nodes.length;
      res.regles.push({ id: v.id, impact: v.impact, noeuds: v.nodes.length, aide: v.help });
      if (v.id === 'color-contrast') {
        for (const n of v.nodes.slice(0, 60)) {
          const d = n.any?.[0]?.data ?? {};
          res.contrastes.push({ cible: n.target.join(' ').slice(0, 90), fg: d.fgColor, bg: d.bgColor, ratio: d.contrastRatio, attendu: d.expectedContrastRatio, taille: d.fontSize });
        }
      }
    }
  } catch (e) { res.erreur = String(e).slice(0, 200); }
  await page.close();
  out.push(res);
  console.error(`${nom.padEnd(22)} dark=${res.htmlDark} dom=${res.tailleDom} total=${res.total} crit=${res.parImpact.critical} ser=${res.parImpact.serious} mod=${res.parImpact.moderate} min=${res.parImpact.minor}`);
}
await browser.close();
console.log(JSON.stringify(out, null, 1));
