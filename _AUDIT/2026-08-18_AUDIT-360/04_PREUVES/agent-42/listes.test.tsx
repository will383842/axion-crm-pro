/**
 * AGENT 42 — chronométrage du rendu des listes longues.
 *
 * Objet : monter les VRAIS écrans de liste avec 0, 1, 100, 10 000 et 100 000
 * lignes servies par MSW, et mesurer :
 *   - le délai entre `render()` et la présence des lignes dans le DOM ;
 *   - le nombre de lignes RÉELLEMENT rendues (`[role="row"]` moins l'en-tête) ;
 *   - le nombre total de nœuds DOM.
 *
 * ⚠️ Bornes de la mesure, à recopier dans le rapport :
 *   - jsdom n'a ni mise en page ni peinture : les chiffres mesurent le COÛT
 *     REACT + DOM, pas le temps perçu dans un navigateur (qui y ajoute style,
 *     layout et paint, donc DAVANTAGE).
 *   - `@tanstack/react-virtual` mesure son conteneur : sous jsdom
 *     `clientHeight` vaut 0. On le force à 600 px (la hauteur réelle du code,
 *     `className="h-[600px]"`) pour que la fenêtre virtuelle soit réaliste.
 *   - aucun réseau réel : MSW répond en mémoire. Le temps mesuré n'inclut donc
 *     PAS la sérialisation de la production (constat A-010).
 *
 * Ce fichier est un instrument d'audit, pas un test de non-régression : il
 * n'assure presque rien, il IMPRIME.
 */
import { appendFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';

import { ContactsListPage } from '@/features/contacts/ContactsListPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const VOLUMES = (process.env['AGENT42_VOLUMES'] ?? '0,1,100,10000')
  .split(',')
  .map((v) => Number(v.trim()));

// Journal INCRÉMENTAL : la mesure la plus intéressante est celle qui n'aboutit
// pas. Si le processus meurt (mémoire) sur 100 000 lignes, les volumes déjà
// mesurés doivent rester écrits sur le disque.
const JOURNAL = process.env['AGENT42_JOURNAL'] ?? '';
function journaliser(ligne: string): void {
  if (JOURNAL === '') return;
  appendFileSync(JOURNAL, ligne + String.fromCharCode(10), 'utf8');
}

// --- jsdom n'a pas de mise en page : on donne une hauteur au conteneur de
// défilement, sinon le virtualiseur croit la fenêtre haute de 0 px et ne rend
// qu'un overscan. 600 px = la valeur du code (`h-[600px]`).
function forcerHauteurs(): void {
  Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
    configurable: true,
    get(this: HTMLElement) {
      return this.classList?.contains('h-[600px]') ? 600 : 0;
    },
  });
  Object.defineProperty(HTMLElement.prototype, 'getBoundingClientRect', {
    configurable: true,
    value(this: HTMLElement) {
      const h = this.classList?.contains('h-[600px]') ? 600 : 0;
      return { x: 0, y: 0, top: 0, left: 0, right: 1280, bottom: h, width: 1280, height: h, toJSON: () => ({}) };
    },
  });
}
forcerHauteurs();

function contacts(n: number) {
  return {
    data: Array.from({ length: n }, (_, i) => ({
      id: i + 1,
      first_name: `Prenom${i}`,
      last_name: `Nom${i}`,
      role: 'Directeur',
      email: `contact${i}@exemple.fr`,
      email_status: 'valid',
      email_score: 80,
      phone: '+33100000000',
      linkedin_url: null,
      discovery_source: 'scraping',
      company_id: i + 1,
      company: { id: i + 1, denomination: `ENTREPRISE ${i}` },
    })),
    meta: { total: n },
  };
}

function companies(n: number) {
  return {
    data: Array.from({ length: n }, (_, i) => ({
      id: i + 1,
      siren: String(100000000 + i),
      denomination: `ENTREPRISE ${i}`,
      naf: '4321A',
      size_category: 'tpe',
      effectif_range: '11',
      city: 'Grenoble',
      postcode: '38000',
      quality_score: 72,
      priority: 'haute',
      enriched_at: '2026-08-01T00:00:00Z',
    })),
    meta: { total: n, last_page: 1, current_page: 1, per_page: 100 },
  };
}

function hub(n: number) {
  return {
    data: Array.from({ length: n }, (_, i) => ({
      id: i + 1,
      siren: String(100000000 + i),
      denomination: `ENTREPRISE ${i}`,
      relation_type: 'prospect',
      lifecycle_stage: 'nouveau',
      legal_basis: 'contrat',
      city_name: 'Grenoble',
      department_code: '38',
      size_category: 'tpe',
      email_generic: `contact${i}@exemple.fr`,
      updated_at: '2026-08-01T00:00:00Z',
      tags: ['sect:btp'],
      contacts: [],
    })),
    meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
  };
}

const GEO = { regions: [], departments: [] };
const COUNTS = {
  total: 4_294_898,
  by_relation_type: { client: 12, prospect: 4_294_886 },
  by_lifecycle_stage: { nouveau: 4_294_886, opportunite: 5, dormant: 900 },
};

interface Mesure {
  ecran: string;
  lignesServies: number;
  ms: number;
  lignesRendues: number;
  noeudsDom: number;
}

const mesures: Mesure[] = [];

function compter(): { lignes: number; noeuds: number } {
  return {
    // Liste de sélecteurs : le DOM dédoublonne, une ligne virtualisée qui porte
    // À LA FOIS `aria-rowindex` (enveloppe) et `role="row"` (contenu) n'est pas
    // comptée deux fois.
    lignes: document.querySelectorAll('[role="row"], [aria-rowindex], ul > li').length,
    noeuds: document.getElementsByTagName('*').length,
  };
}

async function chronometrer(
  ecran: string,
  lignesServies: number,
  monter: () => Promise<unknown>,
  attendre: () => Promise<unknown>,
): Promise<void> {
  const t0 = performance.now();
  await monter();
  await attendre();
  const ms = performance.now() - t0;
  const { lignes, noeuds } = compter();
  mesures.push({ ecran, lignesServies, ms: Math.round(ms), lignesRendues: lignes, noeudsDom: noeuds });
  journaliser(
    [ecran, lignesServies, Math.round(ms), lignes, noeuds, Math.round(process.memoryUsage().heapUsed / 1048576) + ' Mo'].join(' | '),
  );
}

describe('AGENT 42 — coût de rendu des listes', () => {
  for (const n of VOLUMES) {
    it(`ContactsListPage (non virtualisé) — ${n} lignes`, async () => {
      await chronometrer(
        'ContactsListPage (NON virtualisé)',
        n,
        () =>
          renderScreen(<ContactsListPage />, {
            path: '/contacts',
            handlers: [getJson('/contacts', contacts(n))],
          }),
        async () => {
          if (n === 0) await screen.findByText('Aucun contact', {}, { timeout: 300_000 });
          // en-tête + lignes portent tous role="row" : > 1 ⇒ au moins une ligne.
          else
            await waitFor(
              () => expect(document.querySelectorAll('[role="row"]').length).toBeGreaterThan(1),
              { timeout: 300_000, interval: 100 },
            );
        },
      );
      expect(true).toBe(true);
    }, 600_000);
  }

  for (const n of VOLUMES) {
    it(`CompaniesListPage (virtualisé) — ${n} lignes`, async () => {
      await chronometrer(
        'CompaniesListPage (VIRTUALISÉ)',
        n,
        () =>
          renderScreen(<CompaniesListPage />, {
            path: '/companies',
            handlers: [getJson('/companies', companies(n)), getJson('/referentiels/geo', GEO)],
          }),
        async () => {
          if (n === 0) await screen.findByText(/Aucune entreprise|Aucun résultat/i, {}, { timeout: 300_000 });
          // On attend la BRANCHE liste (le conteneur `role="rowgroup"`), pas
          // les lignes : si le virtualiseur n'en calcule aucune sous jsdom, le
          // chiffre « 0 rendue » est la mesure, pas une panne d'instrument.
          else {
            await waitFor(
              () => expect(document.querySelector('[role="rowgroup"]')).not.toBeNull(),
              { timeout: 120_000, interval: 100 },
            );
            // Laisse au virtualiseur le temps de sa première fenêtre.
            await new Promise((r) => setTimeout(r, 500));
          }
        },
      );
      expect(true).toBe(true);
    }, 600_000);
  }

  for (const n of VOLUMES) {
    it(`ContactsHubPage (console v2) — ${n} lignes`, async () => {
      await chronometrer(
        'ContactsHubPage (console v2)',
        n,
        () =>
          renderScreen(<ContactsHubPage />, {
            path: '/console/contacts',
            consoleFeatures: 'open',
            landingRoutes: [{ path: '/console/personnes/$personKey' }],
            handlers: [
              getJson('/crm/contacts-hub/counts', COUNTS),
              getJson('/crm/contacts-hub', hub(n)),
            ],
          }),
        async () => {
          if (n === 0)
            await screen.findByText(/Aucun contact dans cette vue/i, {}, { timeout: 300_000 });
          // Les fiches du hub sont des <li>, PAS des role="row".
          else
            await waitFor(
              () => expect(document.querySelectorAll('ul > li').length).toBeGreaterThan(0),
              { timeout: 240_000, interval: 100 },
            );
        },
      );
      expect(true).toBe(true);
    }, 600_000);
  }

  it('TABLEAU DES MESURES', () => {
    const l: string[] = [];
    l.push('');
    l.push('=========== AGENT 42 — CHRONOS DE RENDU (jsdom, sans peinture) ===========');
    l.push(
      'écran'.padEnd(34) +
        'servies'.padStart(9) +
        'ms'.padStart(9) +
        'rendues'.padStart(9) +
        'nœuds DOM'.padStart(12),
    );
    for (const m of mesures) {
      l.push(
        m.ecran.padEnd(34) +
          String(m.lignesServies).padStart(9) +
          String(m.ms).padStart(9) +
          String(m.lignesRendues).padStart(9) +
          String(m.noeudsDom).padStart(12),
      );
    }
    l.push('=========================================================================');
    console.info(l.join('\n'));
    expect(mesures.length).toBeGreaterThan(0);
  });
});
