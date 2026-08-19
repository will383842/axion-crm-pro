import { chromium } from 'playwright';
import { poserLesDoublures } from './mock.mjs';
const BASE = 'http://127.0.0.1:5224';
const ROUTES = ['/campaigns/7', '/audiences', '/audiences/new', '/admin/observability',
  '/console/contacts', '/console/arbitrage', '/console/personnes/pk-demo', '/rgpd/requests'];
const b = await chromium.launch();
const p = await (await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })).newPage();
const errs = [];
p.on('console', m => { if (m.type() === 'error') errs.push(m.text().replace(/\s+/g, ' ').slice(0, 200)); });
await poserLesDoublures(p);
for (const r of ROUTES) {
  errs.length = 0;
  await p.goto(BASE + r, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2500);
  console.log('====', r);
  console.log('  body:', (await p.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 150));
  console.log('  err :', errs.slice(0, 2).join(' // ').slice(0, 260));
}
await b.close();
