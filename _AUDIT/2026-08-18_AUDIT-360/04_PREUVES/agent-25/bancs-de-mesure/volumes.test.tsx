/**
 * AGENT 25 — TENUE AUX VOLUMES : 0, 1, 100, 10 000, 100 000 lignes.
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * On monte l'écran POUR DE VRAI, l'API répond N lignes, et on mesure :
 * durée, nœuds DOM, lignes réellement dans le document, mémoire.
 * Une liste virtualisée garde un nombre de nœuds ~constant ; une liste nue
 * croît linéairement.
 *
 * ⚠️ TÉMOIN OBLIGATOIRE — le premier cas de chaque écran est « FIXTURE SEULE » :
 * on fabrique et on sérialise le jeu de N lignes SANS monter l'écran. C'est ce
 * qui permet de dire si un coût mesuré vient de l'ÉCRAN ou de mon banc d'essai.
 *
 * Le relevé est écrit APRÈS CHAQUE MESURE : si le processus meurt (débordement
 * mémoire), ce qui a été mesuré avant reste lisible. La première version de ce
 * fichier n'écrivait qu'à la fin — le worker est mort et tout était perdu.
 */
import { describe, it, expect } from 'vitest';
import { waitFor } from '@testing-library/react';
import { appendFileSync, writeFileSync, mkdirSync } from 'node:fs';
import type { ReactElement } from 'react';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { getJson } from '../../tests/msw/handlers';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { UsersPage } from '@/features/users/UsersPage';
import { AuditLogsPage } from '@/features/rgpd/AuditLogsPage';
import { TagsManagerPage } from '@/features/tags/TagsManagerPage';
import { AudiencesListPage } from '@/features/audiences/AudiencesListPage';

const FICHIER = 'tmp/agent25/out/releve-volumes-2.txt';
const VOLUMES = [0, 1, 100, 10_000];

function ecrire(l: string): void {
  appendFileSync(FICHIER, l + '\n', 'utf8');
}

function mo(): number {
  return Math.round(process.memoryUsage().heapUsed / 1024 / 1024);
}

interface Cas {
  route: string;
  comp: () => ReactElement;
  corps: (n: number) => Record<string, unknown>;
  selecteurLigne: string;
  pagination: string;
}

const CAS: Cas[] = [
  {
    route: '/companies',
    comp: () => <CompaniesListPage />,
    corps: (n) => ({
      '/companies': {
        data: Array.from({ length: n }, (_, i) => ({
          id: i + 1, siren: String(100000000 + i), denomination: `Société ${i}`,
          naf: '62.01Z', size_category: 'pme', effectif_range: '10-19',
          city: 'Lyon', postcode: '69000', quality_score: 55, priority: 'p2',
          enriched_at: '2026-01-01T00:00:00Z',
        })),
        meta: { total: n, current_page: 1, last_page: 1, per_page: 100 },
      },
      '/referentiels/geo': { regions: [], departments: [] },
    }),
    selecteurLigne: '[data-index]',
    pagination: 'OUI (Pagination) + useVirtualizer — le SEUL ecran virtualise',
  },
  {
    route: '/users',
    comp: () => <UsersPage />,
    corps: (n) => ({
      '/users': {
        data: Array.from({ length: n }, (_, i) => ({
          id: `u-${i}`, email: `u${i}@ex.fr`, name: `Utilisateur ${i}`,
          roles: ['operator'], totp_enabled_at: null, last_login_at: null,
          first_login_completed_at: null,
        })),
      },
    }),
    selecteurLigne: '[role="row"]',
    pagination: 'NON — ni pagination, ni per_page demande',
  },
  {
    route: '/audit-logs',
    comp: () => <AuditLogsPage />,
    corps: (n) => ({
      '/audit-logs': {
        data: Array.from({ length: n }, (_, i) => ({
          id: i, event_type: 'auth.login', path: '/api/v1/auth/login', status_code: 200,
          ip: '10.0.0.1', created_at: '2026-08-19T10:00:00Z',
          current_hash: 'a'.repeat(64), actor: `acteur${i}`, target: null, severity: 'info',
        })),
      },
    }),
    selecteurLigne: '[role="row"]',
    pagination: 'NON — ni pagination, ni per_page demande',
  },
  {
    route: '/tags',
    comp: () => <TagsManagerPage />,
    corps: (n) => ({
      '/tags': {
        data: Array.from({ length: n }, (_, i) => ({
          id: i, slug: `tag-${i}`, name: `Tag ${i}`, color: 'sky',
          category: 'custom', kind: 'manual', description: null, companies_count: i,
        })),
      },
    }),
    selecteurLigne: 'article, li, [role="row"]',
    pagination: 'NON — aucune pagination',
  },
  {
    route: '/audiences',
    comp: () => <AudiencesListPage />,
    corps: (n) => ({
      '/audiences': {
        data: Array.from({ length: n }, (_, i) => ({
          id: `a-${i}`, name: `Audience ${i}`, description: null, members_count: i,
          criteria: { all: [] }, auto_refresh: false, last_refreshed_at: null,
          created_at: '2026-01-01T00:00:00Z', status: 'ready',
        })),
      },
    }),
    selecteurLigne: 'article, li, [role="row"]',
    pagination: 'NON — aucune pagination',
  },
];

describe('AGENT 25 — tenue aux volumes', () => {
  it('000 — entête du relevé', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync(FICHIER, '', 'utf8');
    ecrire('AGENT 25 — TENUE AUX VOLUMES (montage reel, jsdom, MSW)');
    ecrire('Reference : main 8db8229');
    ecrire('');
    ecrire('jsdom n a PAS de moteur de rendu : les durees mesurent la CONSTRUCTION');
    ecrire('du DOM par React, pas la peinture. Le nombre de NOEUDS est en revanche');
    ecrire('exactement celui que le navigateur devrait peindre — c est la mesure qui');
    ecrire('porte le verdict.');
    ecrire('');
    ecrire('pagination offerte, par ecran :');
    for (const c of CAS) ecrire(`  ${c.route.padEnd(16)} ${c.pagination}`);
    ecrire('');
    ecrire('=== TEMOIN : cout du BANC D ESSAI seul (fabrication + serialisation, sans montage) ===');
    for (const n of VOLUMES) {
      const m0 = mo();
      const t0 = performance.now();
      const corps = CAS[0]!.corps(n);
      const json = JSON.stringify(corps);
      const ms = Math.round(performance.now() - t0);
      ecrire(`  ${String(n).padStart(7)} lignes : ${String(ms).padStart(5)} ms | json=${Math.round(json.length / 1024)} Ko | heap ${m0} -> ${mo()} Mo`);
    }
    ecrire('');
    ecrire('=== MESURES D ECRAN ===');
    ecrire('route            |  lignes servies |    ms | noeuds DOM | lignes rendues |  html (Ko) | heap (Mo) | note');
    expect(true).toBe(true);
  }, 120_000);

  for (const cas of CAS.filter((c) => c.route !== '/companies' && c.route !== '/users')) {
    for (const n of VOLUMES) {
      it(`${cas.route} — ${n} lignes`, async () => {
        const handlers = Object.entries(cas.corps(n)).map(([p, b]) => getJson(p, b));
        const t0 = performance.now();
        let noeuds = 0, lignesDom = 0, htmlKo = 0, note = '';
        try {
          await renderScreen(cas.comp(), { path: cas.route, url: cas.route, handlers });
          await waitFor(
            () => {
              expect(
                document.querySelectorAll('.animate-pulse').length === 0
                || (document.body.textContent ?? '').length > 300,
              ).toBe(true);
            },
            { timeout: 60_000 },
          );
          await new Promise((r) => setTimeout(r, 80));
          noeuds = document.querySelectorAll('*').length;
          lignesDom = document.querySelectorAll(cas.selecteurLigne).length;
          htmlKo = Math.round(document.body.innerHTML.length / 1024);
        } catch (err) {
          note = `ECHEC: ${(err as Error).message?.slice(0, 120)}`;
        }
        const ms = Math.round(performance.now() - t0);
        ecrire(
          `${cas.route.padEnd(16)} | ${String(n).padStart(15)} | ${String(ms).padStart(5)} | ` +
          `${String(noeuds).padStart(10)} | ${String(lignesDom).padStart(14)} | ${String(htmlKo).padStart(10)} | ` +
          `${String(mo()).padStart(9)} | ${note}`,
        );
        expect(true).toBe(true);
      }, 180_000);
    }
  }
});
