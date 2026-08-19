// Agent 23 — mesure de la barre latérale réelle, écran ouvert pour de vrai.
// Auth mockée (comme frontend/tests/e2e/navigation.spec.ts) : on mesure la
// NAVIGATION, pas la session.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';

const OUT = process.argv[2];
const CONSOLE_V2 = process.argv[3] === 'v2';
mkdirSync(OUT, { recursive: true });

const USER = {
  id: 'user-uuid-1', email: 'test@axion-ia.local', name: 'Test User',
  current_workspace_id: 'ws-uuid-1', totp_enabled_at: null,
  first_login_completed_at: '2026-01-01T00:00:00Z',
  onboarding_tour_completed_at: CONSOLE_V2 ? '2026-01-01T00:00:00Z' : null,
};

const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

await page.route('**/auth/me', r => r.fulfill({ json: { user: USER, roles: ['owner'] } }));
await page.route('**/config/features', r => r.fulfill({ json: { console_v2: CONSOLE_V2, universes: { business: true, vivier: CONSOLE_V2 } } }));

await page.goto('http://127.0.0.1:5199/', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('[data-tour="sidebar"]', { timeout: 20000 }); await page.waitForTimeout(1500);

// 1. Dump complet de la barre : sections, entrées, libellés, href, compteurs.
const dump = await page.evaluate(() => {
  const aside = document.querySelector('[data-tour="sidebar"]');
  if (!aside) return { erreur: 'pas de [data-tour=sidebar]' };
  const sections = [...aside.querySelectorAll('nav > div')].map(div => {
    const btn = div.querySelector('h3 button');
    const ul = div.querySelector('ul');
    return {
      titre: btn ? btn.textContent.trim() : '(sans titre)',
      ouverte: btn ? btn.getAttribute('aria-expanded') : null,
      ulHidden: ul ? ul.classList.contains('hidden') : null,
      entrees: [...(ul ? ul.querySelectorAll('a') : [])].map(a => ({
        label: a.textContent.trim(),
        href: a.getAttribute('href'),
        dataTour: a.getAttribute('data-tour'),
        // un compteur serait un badge numérique dans l'entrée
        compteur: /\(\d+\)|^\d+$/.test((a.querySelector('span:last-child') || {}).textContent || '') || null,
        visible: a.getBoundingClientRect().height > 0,
      })),
    };
  });
  const pied = [...aside.querySelectorAll(':scope > div:last-child a, :scope > div:last-child button')]
    .map(e => e.textContent.trim());
  return { sections, pied, texteIntegral: aside.innerText };
});

// 2. Cibles de la visite guidée : présentes ? VISIBLES ?
const cibles = await page.evaluate(() => {
  const noms = ['sidebar', 'global-search', 'nav-companies', 'nav-dashboard', 'dark-mode', 'nav-settings', 'nav-campaigns'];
  return noms.map(n => {
    const el = document.querySelector(`[data-tour="${n}"]`);
    if (!el) return { nom: n, present: false, visible: false, raison: 'absent du DOM' };
    const r = el.getBoundingClientRect();
    const st = getComputedStyle(el);
    const parentCache = el.closest('ul') && el.closest('ul').classList.contains('hidden');
    return {
      nom: n, present: true,
      visible: r.width > 0 && r.height > 0 && st.display !== 'none' && st.visibility !== 'hidden',
      rect: { w: Math.round(r.width), h: Math.round(r.height) },
      parentUlHidden: !!parentCache,
    };
  });
});

// 3. Recherche de compteurs décoratifs ailleurs dans l'interface (tableau de bord).
const compteursTdb = await page.evaluate(() =>
  [...document.querySelectorAll('main *')]
    .filter(e => e.children.length === 0 && /^[\d\s .,]+$/.test(e.textContent.trim()) && e.textContent.trim() !== '')
    .slice(0, 40)
    .map(e => ({ valeur: e.textContent.trim(), tag: e.tagName, contexte: (e.parentElement?.textContent || '').trim().slice(0, 80) })));

writeFileSync(`${OUT}/cible-${CONSOLE_V2 ? 'v2' : 'v1'}.json`, JSON.stringify({ dump, cibles, compteursTdb }, null, 2));
await page.screenshot({ path: `${OUT}/cible-${CONSOLE_V2 ? 'v2' : 'v1'}-accueil.png`, fullPage: false });

// 4. Toutes les sections dépliées une à une + capture.
const boutons = await page.locator('[data-tour="sidebar"] h3 button').all();
const parSection = [];
for (const b of boutons) {
  const titre = (await b.textContent()).trim();
  if ((await b.getAttribute('aria-expanded')) !== 'true') await b.click();
  await page.waitForTimeout(150);
  const liens = await page.locator('[data-tour="sidebar"] nav ul:not(.hidden) a').allTextContents();
  parSection.push({ titre, liensVisiblesApresOuverture: liens });
}
writeFileSync(`${OUT}/cible-sections-${CONSOLE_V2 ? 'v2' : 'v1'}.json`, JSON.stringify(parSection, null, 2));

// 5. La visite guidée démarre-t-elle, et que voit-elle ? (tour non fait -> v1 seulement)
if (!CONSOLE_V2) {
  await page.goto('http://127.0.0.1:5199/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  const etapes = [];
  for (let i = 0; i < 8; i++) {
    const tooltip = await page.locator('.react-joyride__tooltip').first();
    const visible = await tooltip.isVisible().catch(() => false);
    if (!visible) { etapes.push({ i, etat: 'aucune infobulle' }); break; }
    const txt = (await tooltip.innerText()).slice(0, 200);
    const spot = await page.locator('.react-joyride__spotlight').first();
    const box = await spot.boundingBox().catch(() => null);
    etapes.push({ i, texte: txt.replace(/\n/g, ' | '), spotlight: box });
    await page.screenshot({ path: `${OUT}/cible-visite-etape-${i}.png` });
    const suivant = page.getByRole('button', { name: /Suivant|Next|Terminer|Last|Fin/i }).first();
    if (!(await suivant.isVisible().catch(() => false))) break;
    await suivant.click();
    await page.waitForTimeout(700);
  }
  writeFileSync(`${OUT}/cible-visite-guidee.json`, JSON.stringify(etapes, null, 2));
}

await browser.close();
console.log('OK', OUT, CONSOLE_V2 ? 'v2' : 'v1');
