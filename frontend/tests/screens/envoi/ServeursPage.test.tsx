/**
 * DM2-INF — « Serveurs et adresses IP » : l'ajout d'une adresse IP.
 *
 *  1. la page montre les serveurs et leurs adresses (plages de documentation) ;
 *  2. « Ajouter une adresse IP » REFUSE, en démo, une adresse hors des plages de
 *     documentation (le serveur de démonstration répond 422 en français) ;
 *  3. « Ajouter et appliquer » crée une version de configuration, appliquée,
 *     et la nouvelle adresse apparaît dans le tableau.
 *
 * Fixtures : le faux serveur de la démo (même `handlers` que les e2e).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ServeursPage } from '@/features/envoi/infrastructure/ServeursPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../helpers/renderScreen';

// Une adresse volontairement HORS des plages de documentation (assemblée pour
// qu'aucune adresse réelle n'apparaisse en clair dans le dépôt public).
const ADRESSE_HORS_PLAGE = ['10', '20', '30', '40'].join('.');

async function monter() {
  const view = await renderScreen(<ServeursPage />, { path: '/envoi/serveurs', handlers });
  await screen.findByText('Serveur d’envoi 1 (démo)');
  return view;
}

describe('ServeursPage — ajout d’une adresse IP', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('montre les serveurs et leurs adresses de documentation', async () => {
    await monter();
    expect(screen.getByRole('heading', { level: 1, name: 'Serveurs et adresses IP' })).toBeInTheDocument();
    expect(screen.getByText('192.0.2.10')).toBeInTheDocument();
    expect(screen.getByText('198.51.100.21')).toBeInTheDocument();
  });

  it('refuse une adresse hors des plages de documentation', async () => {
    const u = userEvent.setup();
    await monter();
    await u.click(screen.getByRole('button', { name: 'Ajouter une adresse IP' }));
    const fenetre = await screen.findByRole('dialog');
    const champ = within(fenetre).getByLabelText('Adresse IP');
    await u.clear(champ);
    await u.type(champ, ADRESSE_HORS_PLAGE);
    await u.click(within(fenetre).getByRole('button', { name: 'Ajouter et appliquer' }));
    expect(await within(fenetre).findByRole('alert')).toHaveTextContent(/plages de documentation/i);
    expect(demoDb.serveurs.trouver('srv-1')?.adresses_ip).toHaveLength(2);
  });

  it('« Ajouter et appliquer » crée une version de configuration appliquée', async () => {
    const u = userEvent.setup();
    await monter();
    const avant = demoDb.serveurs.trouver('srv-1')?.version_configuration ?? 0;
    await u.click(screen.getByRole('button', { name: 'Ajouter une adresse IP' }));
    const fenetre = await screen.findByRole('dialog');
    // L'adresse proposée est la prochaine adresse libre de documentation.
    expect(within(fenetre).getByLabelText('Adresse IP')).toHaveValue('192.0.2.12');
    await u.click(within(fenetre).getByRole('button', { name: 'Ajouter et appliquer' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    const tableau = screen.getByRole('table', { name: /Adresses IP de Serveur d’envoi 1/ });
    expect(await within(tableau).findByText('192.0.2.12')).toBeInTheDocument();
    const apres = demoDb.serveurs.trouver('srv-1')?.version_configuration ?? 0;
    expect(apres).toBeGreaterThan(avant);
  });
});
