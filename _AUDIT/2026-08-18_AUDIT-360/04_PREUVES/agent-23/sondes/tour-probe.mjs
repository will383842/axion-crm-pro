// Agent 23 — la visite guidée, jouée pour de vrai sur la barre ACTUELLE (code main).
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
const OUT = process.argv[2];
mkdirSync(OUT, { recursive: true });

const USER = {
  id: 'u1', email: 't@a.local', name: 'T', current_workspace_id: 'ws1',
  totp_enabled_at: null, first_login_completed_at: '2026-01-01T00:00:00Z',
  onboarding_tour_completed_at: null, // visite JAMAIS faite -> elle démarre
};

const b = await chromium.launch();
const c = await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
const p = await c.newPage();
await p.route('**/auth/me', r => r.fulfill({ json: { user: USER, roles: ['owner'] } }));
await p.route('**/config/features', r => r.fulfill({ json: { console_v2: true, universes: { business: true, vivier: true } } }));
const appelsComplete = [];
await p.route('**/onboarding/complete', r => { appelsComplete.push(r.request().url()); return r.fulfill({ json: { ok: true } }); });

await p.route('**/sanctum/csrf-cookie', r => r.fulfill({ status: 204, headers: { 'access-control-allow-origin': 'http://127.0.0.1:5199', 'access-control-allow-credentials': 'true' }, body: '' }));
await p.addInitScript(() => { window.__xhr = []; const o = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (m, u) { window.__xhr.push(m + ' ' + u); return o.apply(this, arguments); }; });
await p.goto('http://127.0.0.1:5199/', { waitUntil: 'domcontentloaded' });
await p.waitForSelector('[data-tour="sidebar"]', { timeout: 20000 });
// TÉMOIN NÉGATIF : si TEMOIN=1, on force toutes les sections dépliées (CSS
// seul, aucune modif produit). Si la sonde est capable de voir une visite qui
// va au bout, elle doit alors compter 7 étapes ET 1 appel /onboarding/complete.
if (process.env.TEMOIN === '1') {
  await p.addStyleTag({ content: '[data-tour="sidebar"] nav ul.hidden { display: block !important; }' });
}
await p.waitForTimeout(3500);

const etapes = [];
for (let i = 0; i < 10; i++) {
  const tip = p.locator('.react-joyride__tooltip').first();
  if (!(await tip.isVisible().catch(() => false))) { etapes.push({ i, etat: 'AUCUNE infobulle — la visite s’est arrêtée' }); break; }
  const texte = (await tip.innerText()).replace(/\s+/g, ' ').slice(0, 160);
  const spot = p.locator('.react-joyride__spotlight').first();
  const box = await spot.boundingBox().catch(() => null);
  // Quelle entrée de menu le projecteur éclaire-t-il RÉELLEMENT ?
  const sous = box ? await p.evaluate(([x, y, w, h]) => {
    const el = document.elementFromPoint(x + w / 2, y + h / 2);
    return el ? { tag: el.tagName, texte: (el.textContent || '').trim().slice(0, 60) } : null;
  }, [box.x, box.y, box.width, box.height]) : null;
  etapes.push({ i, texte, spotlight: box, sousLeProjecteur: sous });
  await p.screenshot({ path: `${OUT}/visite-etape-${i}.png` });
  const btn = p.getByRole('button', { name: /Suivant|Next|Terminer|Last|Fin|Close|Fermer/i }).first();
  if (!(await btn.isVisible().catch(() => false))) { etapes.push({ i, etat: 'plus de bouton suivant' }); break; }
  await btn.click({ timeout: 5000 }).catch(e => etapes.push({ i, erreurClic: String(e).slice(0, 120) }));
  await p.waitForTimeout(900);
}
const xhr = await p.evaluate(() => window.__xhr);
etapes.push({ appelsOnboardingComplete: appelsComplete.length, urls: appelsComplete, xhrOnboarding: xhr.filter(u => u.includes('onboarding')), nbXhr: xhr.length });
writeFileSync(`${OUT}/visite-guidee-code-actuel.json`, JSON.stringify(etapes, null, 2));
await b.close();
console.log(JSON.stringify(etapes, null, 2));
