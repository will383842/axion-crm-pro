import { createRequire } from 'node:module';
import fs from 'node:fs';

const FRONT = 'C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/';
const require = createRequire(FRONT + 'package.json');
const { chromium } = require('playwright');
const AXE_SRC = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const BASE = process.env.BASE || 'http://127.0.0.1:4188';
const DARK = process.env.DARK === '1';

// Les 37 écrans, tirés de src/app/routeTree.tsx (pas d'un document).
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

const out = [];

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
await ctx.addInitScript(({ dark }) => {
  try { localStorage.setItem('axion-crm:theme', dark ? 'dark' : 'light'); } catch {}
  try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch {}
}, { dark: DARK });

for (const [nom, url] of ROUTES) {
  const page = await ctx.newPage();
  const res = { nom, url, erreur: null, rootVide: null, violations: [], parImpact: { critical: 0, serious: 0, moderate: 0, minor: 0, null: 0 }, total: 0 };
  try {
    await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 25000 });
    if (DARK) await page.evaluate(() => document.documentElement.classList.add('dark'));
    await page.waitForTimeout(900);
    res.rootVide = await page.evaluate(() => (document.querySelector('#root')?.innerHTML ?? '').trim().length === 0);
    await page.addScriptTag({ content: AXE_SRC });
    const r = await page.evaluate(async () => {
      // eslint-disable-next-line no-undef
      return await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }, resultTypes: ['violations'] });
    });
    for (const v of r.violations) {
      const imp = v.impact ?? 'null';
      res.parImpact[imp] = (res.parImpact[imp] ?? 0) + v.nodes.length;
      res.total += v.nodes.length;
      res.violations.push({ id: v.id, impact: v.impact, help: v.help, noeuds: v.nodes.length, exemples: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) });
    }
  } catch (e) {
    res.erreur = String(e).slice(0, 200);
  }
  await page.close();
  out.push(res);
  console.error(`${nom.padEnd(24)} total=${res.total} crit=${res.parImpact.critical} ser=${res.parImpact.serious} mod=${res.parImpact.moderate} min=${res.parImpact.minor}${res.erreur ? ' ERREUR ' + res.erreur : ''}${res.rootVide ? ' [#root VIDE]' : ''}`);
}

await browser.close();
console.log(JSON.stringify(out, null, 1));
