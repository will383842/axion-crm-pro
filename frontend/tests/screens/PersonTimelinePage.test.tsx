/**
 * ÉCRAN `/console/personnes/$personKey` — `src/features/crm-console/PersonTimelinePage.tsx`.
 *
 * Famille : PARAMÈTRE DE ROUTE, sous `ConsoleGate`.
 *
 * L'écran fait `useParams({ from: '/layout/console/personnes/$personKey' })`.
 * Cette chaîne est un identifiant de route : si `routeTree.tsx` la renomme, ou
 * si quelqu'un se trompe d'un caractère, TanStack Router lève. Un routeur
 * SIMULÉ n'aurait rien vu — c'est le test qui aurait fourni le paramètre.
 * Ici le paramètre est EXTRAIT DE L'URL par un vrai routeur, puis on vérifie
 * qu'il arrive jusqu'à l'URL de la requête.
 *
 * L'écran n'a aucun bouton : son « parcours » est de s'ATTEINDRE. On le joue
 * donc pour de vrai — clic sur le lien d'une personne depuis le hub Contacts.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PersonTimelinePage } from '@/features/crm-console/PersonTimelinePage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import type { TimelineResponse } from '@/features/crm-console/types';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet } from '../msw/handlers';

const PATH = '/console/personnes/$personKey';

function timeline(overrides: Partial<TimelineResponse> = {}): TimelineResponse {
  return {
    person_key: 'p-42',
    universes: {
      business: { accessible: true, exists: true },
      vivier: { accessible: false, exists: true },
    },
    subjects: [
      {
        universe: 'business',
        type: 'contact',
        id: 7,
        first_name: 'Camille',
        last_name: 'Berger',
        email: 'camille.berger@exemple.fr',
        company: { id: 3, denomination: 'ACME GRENOBLE', siren: '123456789' },
      },
    ],
    data: [
      {
        id: 1,
        universe: 'business',
        kind: 'form_submission',
        title: 'Formulaire de contact',
        occurred_at: '2026-08-01T10:00:00Z',
        external_ref: 'AXI-FORM-2026-118',
        subject_type: 'contact',
        subject_id: 7,
      },
      {
        id: 2,
        universe: 'vivier',
        kind: 'application',
        title: null,
        occurred_at: '2026-07-12T08:30:00Z',
        external_ref: null,
        subject_type: 'candidate',
        subject_id: 9,
      },
    ],
    ...overrides,
  };
}

describe('PersonTimelinePage — rendu', () => {
  it('extrait `personKey` de l’URL et affiche la fiche de CETTE personne', async () => {
    const { handler, urls } = recordGet('/crm/persons/p-42/timeline', timeline());

    await renderScreen(<PersonTimelinePage />, {
      path: PATH,
      url: '/console/personnes/p-42',
      consoleFeatures: 'open',
      handlers: [handler],
    });

    expect(await screen.findByRole('heading', { name: 'Camille Berger' })).toBeVisible();
    expect(screen.getByText('camille.berger@exemple.fr')).toBeVisible();
    expect(screen.getByText('ACME GRENOBLE')).toBeVisible();

    // Le paramètre a traversé toute la chaîne : URL → useParams → requête.
    expect(urls).toHaveLength(1);
    expect(new URL(urls[0] as string).pathname).toBe('/api/v1/crm/persons/p-42/timeline');
  });

  it('affiche la timeline comme un INDEX : titre, date, source — jamais le contenu', async () => {
    await renderScreen(<PersonTimelinePage />, {
      path: PATH,
      url: '/console/personnes/p-42',
      consoleFeatures: 'open',
      handlers: [getJson('/crm/persons/p-42/timeline', timeline())],
    });

    expect(await screen.findByText('Formulaire de contact')).toBeVisible();
    // La référence externe est collée à l'univers dans le même bloc
    // (« Business · AXI-FORM-2026-118 ») : on interroge le bloc, pas un nœud.
    expect(screen.getByText(/AXI-FORM-2026-118/)).toBeVisible();
    // Une entrée sans titre retombe sur son `kind`, jamais sur du vide.
    expect(screen.getByText('application')).toBeVisible();
  });

  it('ÉTANCHÉITÉ : un univers non accessible n’affiche qu’un booléen, pas le nom', async () => {
    // Le fond du parti-pris §2.4.3 : sans cet encart, un opérateur business
    // recréerait une fiche pour quelqu'un qui en a déjà une dans le vivier.
    // Mais il ne doit RIEN apprendre d'autre que « ça existe ».
    await renderScreen(<PersonTimelinePage />, {
      path: PATH,
      url: '/console/personnes/p-42',
      consoleFeatures: 'open',
      handlers: [
        getJson(
          '/crm/persons/p-42/timeline',
          timeline({
            universes: {
              business: { accessible: true, exists: true },
              vivier: { accessible: false, exists: true },
            },
            subjects: [
              {
                universe: 'business',
                type: 'contact',
                id: 7,
                first_name: 'Camille',
                last_name: 'Berger',
                email: 'camille.berger@exemple.fr',
                company: null,
              },
            ],
          }),
        ),
      ],
    });

    expect(await screen.findByText('Existe — basculer d’univers pour voir')).toBeVisible();

    // Le vivier ne dit PAS « Fiche présente » — cette pastille-là est réservée
    // à un univers auquel on a droit, et elle s'accompagne d'une identité.
    const ligneVivier = screen.getByText('Vivier candidats').closest('li');
    expect(ligneVivier).not.toBeNull();
    expect(ligneVivier).not.toHaveTextContent('Fiche présente');

    // L'encart Identité ne liste que le sujet de l'univers ACCESSIBLE : une
    // seule entrée, celle du business. Rien du vivier n'a fuité.
    const identite = screen.getByText('Identité').closest('div');
    expect(identite?.querySelectorAll('ul > li')).toHaveLength(1);
  });

  it('drapeau console FERMÉ : l’écran ne demande RIEN et affiche le message du gardien', async () => {
    // Comportement produit, pas détail de test : `ConsoleGate` doit se taire
    // AVANT que la page ne consomme une route API qui répond 404.
    const { handler, urls } = recordGet('/crm/persons/p-42/timeline', timeline());

    await renderScreen(<PersonTimelinePage />, {
      path: PATH,
      url: '/console/personnes/p-42',
      consoleFeatures: 'closed',
      handlers: [handler],
    });

    expect(await screen.findByText('Console non activée')).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'Camille Berger' })).not.toBeInTheDocument();
    expect(urls).toEqual([]);
  });
});

describe('PersonTimelinePage — parcours', () => {
  it('cliquer une personne depuis le hub Contacts ouvre SA fiche', async () => {
    const user = userEvent.setup();
    const { handler: timelineHandler, urls } = recordGet('/crm/persons/pk-camille/timeline', timeline());

    const view = await renderScreen(<ContactsHubPage />, {
      path: '/console/contacts',
      consoleFeatures: 'open',
      handlers: [
        getJson('/crm/contacts-hub/counts', {
          total: 1,
          by_relation_type: { client: 1 },
          by_lifecycle_stage: { nouveau: 1 },
        }),
        getJson('/crm/contacts-hub', {
          data: [
            {
              id: 3,
              siren: '123456789',
              denomination: 'ACME GRENOBLE',
              relation_type: 'client',
              lifecycle_stage: 'nouveau',
              legal_basis: 'contrat',
              city_name: 'Grenoble',
              department_code: '38',
              size_category: 'tpe',
              email_generic: null,
              updated_at: '2026-08-01T00:00:00Z',
              tags: [],
              contacts: [
                { id: 7, first_name: 'Camille', last_name: 'Berger', person_key: 'pk-camille' },
              ],
            },
          ],
          meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
        }),
        timelineHandler,
      ],
      // La destination est le VRAI écran : c'est le seul moyen de prouver que
      // le `params` du `Link` arrive jusqu'à l'URL de la requête.
      landingRoutes: [{ path: '/console/personnes/$personKey', element: <PersonTimelinePage /> }],
    });

    const lien = await screen.findByRole('link', { name: 'Camille Berger' });
    await user.click(lien);

    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/console/personnes/pk-camille');
    });
    expect(await screen.findByRole('heading', { name: 'Camille Berger' })).toBeVisible();
    expect(urls).toHaveLength(1);
  });
});
