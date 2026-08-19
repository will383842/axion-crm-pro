// AGENT 24 — l'écran d'accueil face au journal d'audit RÉEL.
// `audit_logs` porte la colonne `event_type` (migration 2026_05_16_000002:198) ;
// `AuditLogsController::index` rend les lignes du modèle TELLES QUELLES ;
// `ActivityFeed.tsx:19` lit `log.action`, qui n'existe donc jamais.
// On mesure les DEUX charges sur le MÊME écran : la réelle, puis un témoin.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { poserLesDoublures } from './mock.mjs';

const OUT = process.argv[2];
const BASE = 'http://127.0.0.1:5224';
mkdirSync(OUT, { recursive: true });

// Ligne EXACTE que rend le contrôleur : les colonnes du modèle `AuditLog`
// (`app/Models/AuditLog.php:15`), telles qu'écrites par le middleware
// `AuditHashChainLogger` sur un `POST /auth/login`.
const LIGNE_REELLE = {
  id: 1, workspace_id: 'ws1', user_id: 'u1', event_type: 'POST',
  path: 'api/v1/auth/login', status_code: 200, ip: '127.0.0.1',
  user_agent: 'Mozilla', payload_hash: 'ab', prev_hash: 'GENESIS', current_hash: 'cd',
  created_at: '2026-08-19T10:00:00Z',
};
// TÉMOIN : la même ligne PLUS le champ `action` que le composant attend.
const LIGNE_TEMOIN = { ...LIGNE_REELLE, action: 'company.updated', actor_name: 'Will' };

async function mesurer(nom, lignes) {
  const b = await chromium.launch();
  const p = await (await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } })).newPage();
  const erreurs = [];
  p.on('pageerror', e => erreurs.push(String(e).split('\n')[0].slice(0, 160)));
  await poserLesDoublures(p);
  // La doublure de `/audit-logs` est réécrite APRÈS, elle gagne (Playwright : dernière posée = première servie).
  await p.route('**/api/v1/audit-logs*', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: lignes, meta: { total: lignes.length } }) }));
  await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(6000);
  const r = {
    cas: nom,
    nbLignesAuditServies: lignes.length,
    barreLateralePresente: (await p.locator('[data-tour="sidebar"]').count()) > 0,
    mainPresent: (await p.locator('main').count()) > 0,
    texteBody: (await p.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 180),
    erreursNonRattrapees: erreurs,
  };
  await p.screenshot({ path: `${OUT}/accueil-${nom}.png` });
  await b.close();
  console.log(JSON.stringify(r, null, 2));
  return r;
}

const out = [];
out.push(await mesurer('journal-VIDE', []));                 // état d'aujourd'hui en production (A-012 : 0 connexion)
out.push(await mesurer('journal-REEL-1-ligne', [LIGNE_REELLE]));  // état dès la 1re connexion
out.push(await mesurer('TEMOIN-avec-champ-action', [LIGNE_TEMOIN]));
writeFileSync(`${OUT}/accueil-plante.json`, JSON.stringify(out, null, 2));
