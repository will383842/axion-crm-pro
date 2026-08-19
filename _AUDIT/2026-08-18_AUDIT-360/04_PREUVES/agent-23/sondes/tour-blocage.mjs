// Agent 23 — après l'arrêt de la visite guidée, l'interface est-elle rendue ?
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
const OUT = process.argv[2];
mkdirSync(OUT, { recursive: true });
const USER = { id:'u1', email:'t@a.local', name:'T', current_workspace_id:'ws1', totp_enabled_at:null,
  first_login_completed_at:'2026-01-01T00:00:00Z', onboarding_tour_completed_at:null };

const b = await chromium.launch();
const c = await b.newContext({ ignoreHTTPSErrors:true, viewport:{width:1440,height:900} });
const p = await c.newPage();
await p.route('**/auth/me', r => r.fulfill({ json:{ user:USER, roles:['owner'] } }));
await p.route('**/config/features', r => r.fulfill({ json:{ console_v2:true, universes:{business:true,vivier:true} } }));
await p.goto('http://127.0.0.1:5199/', { waitUntil:'domcontentloaded' });
await p.waitForSelector('[data-tour="sidebar"]', { timeout:20000 });
await p.waitForTimeout(3500);

// On joue la visite jusqu'à son arrêt (clic « Next » tant qu'il y en a un).
for (let i = 0; i < 10; i++) {
  const btn = p.getByRole('button', { name: /Suivant|Next|Terminer|Last/i }).first();
  if (!(await btn.isVisible().catch(()=>false))) break;
  await btn.click({ timeout: 4000 }).catch(()=>{});
  await p.waitForTimeout(800);
}
await p.waitForTimeout(1500);

const etat = await p.evaluate(() => ({
  infobulle: !!document.querySelector('.react-joyride__tooltip'),
  overlay: !!document.querySelector('.react-joyride__overlay'),
  overlayRect: (() => { const o = document.querySelector('.react-joyride__overlay'); if (!o) return null;
    const r = o.getBoundingClientRect(); const s = getComputedStyle(o);
    return { w: Math.round(r.width), h: Math.round(r.height), display: s.display, pointerEvents: s.pointerEvents, zIndex: s.zIndex }; })(),
  // Que touche-t-on si l'on clique au milieu de la barre latérale ?
  auClicSurLaBarre: (() => { const el = document.elementFromPoint(130, 300); return el ? { tag: el.tagName, cls: (el.className||'').toString().slice(0,80) } : null; })(),
  auClicSurLeContenu: (() => { const el = document.elementFromPoint(800, 400); return el ? { tag: el.tagName, cls: (el.className||'').toString().slice(0,80) } : null; })(),
}));
await p.screenshot({ path: `${OUT}/visite-apres-arret.png` });

// Test de survie : peut-on ouvrir la section « Contacts » ?
let clicOK = 'oui';
try { await p.getByRole('button', { name: 'Contacts', exact: true }).click({ timeout: 6000 }); }
catch (e) { clicOK = 'NON — ' + String(e).split('\n')[0].slice(0,140); }

writeFileSync(`${OUT}/visite-blocage.json`, JSON.stringify({ etat, clicSurSectionContacts: clicOK }, null, 2));
console.log(JSON.stringify({ etat, clicSurSectionContacts: clicOK }, null, 2));
await b.close();
