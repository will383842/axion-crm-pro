import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
const OUT = process.argv[2]; mkdirSync(OUT, { recursive: true });
const USER = { id:'u1', email:'t@a.local', name:'T', current_workspace_id:'ws1', totp_enabled_at:null,
  first_login_completed_at:'2026-01-01T00:00:00Z', onboarding_tour_completed_at:'2026-01-01T00:00:00Z' };
const b = await chromium.launch();
const c = await b.newContext({ ignoreHTTPSErrors:true, viewport:{width:1440,height:900} });
const p = await c.newPage();
await p.route('**/auth/me', r => r.fulfill({ json:{ user:USER, roles:['owner'] } }));
await p.route('**/config/features', r => r.fulfill({ json:{ console_v2:true, universes:{business:true,vivier:true} } }));
const routes = ['/','/companies','/contacts','/media','/journalists','/tags','/audiences','/audiences/new',
 '/admin/observability','/international/roumanie','/console/contacts','/console/vivier','/console/arbitrage',
 '/console/personnes/abcdef0123456789','/campaigns','/campaigns/new','/scraper-runs','/rgpd/requests',
 '/rgpd/ai-act','/audit-logs','/users','/settings','/llm/router','/llm/proxy-providers','/llm/rotations',
 '/coverage','/cold-email','/linkedin','/crm','/analytics'];
const out = [];
for (const r of routes) {
  await p.goto('http://127.0.0.1:5199' + r, { waitUntil:'domcontentloaded' });
  await p.waitForTimeout(1200);
  const fil = await p.locator('nav[aria-label="Fil d\'Ariane"], nav[aria-label*="riane"], [aria-label*="readcrumb"]').first().innerText().catch(() => '(aucun fil)');
  const barre = await p.locator('[data-tour="sidebar"]').count();
  const titre = await p.locator('h1').first().innerText().catch(() => '(aucun h1)');
  out.push({ route: r, filDAriane: fil.replace(/\n/g,' › '), barreLaterale: barre > 0 ? 'présente' : 'ABSENTE', h1: titre });
}
writeFileSync(`${OUT}/fil-ariane-par-route.json`, JSON.stringify(out, null, 2));
out.forEach(o => console.log(o.route.padEnd(34), '|', o.barreLaterale.padEnd(9), '|', o.h1.slice(0,28).padEnd(30), '|', o.filDAriane.slice(0,70)));
await b.close();
