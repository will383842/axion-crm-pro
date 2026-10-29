/**
 * Cartes « Tâches du CRM » et « Étiquettes » de la fiche entreprise (lot A4).
 * Les tâches et étiquettes restent dans le CRM : on vérifie les appels exacts.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TachesCrmCard } from '@/features/companies/components/TachesCrmCard';
import { EtiquettesCard } from '@/features/companies/components/EtiquettesCard';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordPost } from '../msw/handlers';

const PATH = '/companies/$companyId';

describe('TachesCrmCard', () => {
  const TACHES = {
    data: [
      { id: 7, title: 'Rappeler la direction', content: null, due_at: '2026-11-15T00:00:00Z', done_at: null, done: false },
      { id: 8, title: 'Envoyer le devis', content: null, due_at: null, done_at: '2026-10-01T00:00:00Z', done: true },
    ],
  };

  it('liste les tâches et marque une tâche comme faite', async () => {
    const terminer = recordPost('/companies/42/taches/7/terminer', { done: true });
    await renderScreen(<TachesCrmCard companyId={42} />, {
      path: PATH,
      url: '/companies/42',
      handlers: [getJson('/companies/42/taches', TACHES), terminer.handler],
    });

    expect(await screen.findByText('Rappeler la direction')).toBeVisible();
    expect(screen.getByText(/À faire avant le/)).toBeVisible();
    expect(screen.getByText('Faite')).toBeVisible();
    // La tâche déjà faite n'offre pas de second « Marquer comme faite ».
    expect(screen.getAllByRole('button', { name: /Marquer comme faite/ })).toHaveLength(1);

    await userEvent.click(screen.getByRole('button', { name: /Marquer comme faite/ }));
    await waitFor(() => expect(terminer.bodies).toHaveLength(1));
  });

  it('« Ajouter une tâche CRM » envoie l’intitulé et l’échéance', async () => {
    const ajout = recordPost('/companies/42/taches', { activity_id: 9 }, 201);
    await renderScreen(<TachesCrmCard companyId={42} />, {
      path: PATH,
      url: '/companies/42',
      handlers: [getJson('/companies/42/taches', { data: [] }), ajout.handler],
    });

    expect(await screen.findByText('Aucune tâche pour cette entreprise.')).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Ajouter une tâche CRM' }));
    await userEvent.type(screen.getByLabelText('Intitulé de la tâche'), 'Relancer');
    await userEvent.type(screen.getByLabelText('Avant le'), '2026-11-20');
    await userEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(ajout.bodies).toHaveLength(1));
    expect(ajout.bodies[0]).toEqual({ title: 'Relancer', due_at: '2026-11-20' });
  });
});

describe('EtiquettesCard', () => {
  const REFERENTIEL = {
    data: [
      { id: 1, slug: 'vip', name: 'VIP' },
      { id: 2, slug: 'salon', name: 'Salon' },
      { id: 3, slug: 'src:insee', name: 'Source INSEE', is_locked: true },
    ],
  };

  it('ajoute une étiquette du référentiel via l’action de masse à un élément', async () => {
    const bulk = recordPost('/companies/tags/bulk', { modifiees: 1, ignorees: 0 });
    await renderScreen(<EtiquettesCard companyId={42} etiquettes={[{ id: 1, slug: 'vip', name: 'VIP' }]} />, {
      path: PATH,
      url: '/companies/42',
      handlers: [getJson('/tags', REFERENTIEL), bulk.handler],
    });

    expect(screen.getByText('Étiquettes (propres au CRM, non synchronisées)')).toBeVisible();
    await screen.findByRole('option', { name: 'Salon' });
    // Déjà posée et verrouillée : non proposées.
    expect(screen.queryByRole('option', { name: 'VIP' })).not.toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Source INSEE' })).not.toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Étiquette à ajouter'), 'salon');
    await userEvent.click(screen.getByRole('button', { name: 'Ajouter' }));
    await waitFor(() => expect(bulk.bodies).toHaveLength(1));
    expect(bulk.bodies[0]).toEqual({ ids: [42], tag: 'salon', action: 'add' });
  });

  it('retire le lien avec l’entreprise (action remove), pas l’étiquette', async () => {
    const bulk = recordPost('/companies/tags/bulk', { modifiees: 1, ignorees: 0 });
    await renderScreen(<EtiquettesCard companyId={42} etiquettes={[{ id: 1, slug: 'vip', name: 'VIP' }]} />, {
      path: PATH,
      url: '/companies/42',
      handlers: [getJson('/tags', REFERENTIEL), bulk.handler],
    });

    await userEvent.click(screen.getByRole('button', { name: "Retirer l'étiquette VIP" }));
    await waitFor(() => expect(bulk.bodies).toHaveLength(1));
    expect(bulk.bodies[0]).toEqual({ ids: [42], tag: 'vip', action: 'remove' });
  });
});
