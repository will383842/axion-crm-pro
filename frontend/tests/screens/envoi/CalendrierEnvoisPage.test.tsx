/**
 * DM2-CAM — calendrier des envois : la semaine, toutes campagnes confondues.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CalendrierEnvoisPage } from '@/features/envoi/campagnes/CalendrierEnvoisPage';
import { handlers } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

async function ouvrir(query = '') {
  return renderScreen(<CalendrierEnvoisPage />, {
    path: '/envoi/campagnes/calendrier',
    url: `/envoi/campagnes/calendrier${query}`,
    handlers,
    landingRoutes: ['/envoi/campagnes', '/envoi/campagnes/$campagneId'],
  });
}

describe('CalendrierEnvoisPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it('montre les sept jours de la semaine en cours et les messages prévus', async () => {
    await ouvrir();
    expect(await screen.findByRole('heading', { level: 1, name: 'Calendrier' })).toBeInTheDocument();
    // Semaine du lundi 11 au dimanche 17 janvier 2027.
    const jours = await screen.findAllByRole('listitem', { name: /^(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche) \d+ janvier/i });
    expect(jours).toHaveLength(7);
    expect(screen.getByTestId('tuile-prevus')).toHaveTextContent(/messages prévus/);
  });

  it('le jour affiche la campagne et son nombre de messages, avec un lien vers le suivi', async () => {
    await ouvrir();
    const mardi = await screen.findByRole('listitem', { name: /^mardi 12 janvier/i });
    const lien = within(mardi).getAllByRole('link')[0]!;
    expect(lien).toHaveAttribute('href', expect.stringMatching(/\/envoi\/campagnes\/cmp-/));
    expect(mardi).toHaveTextContent(/\d+ messages?/);
  });

  it('passe à la semaine suivante et revient à aujourd’hui', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await screen.findByRole('listitem', { name: /^mardi 12 janvier/i });
    await user.click(screen.getByRole('button', { name: 'Semaine suivante' }));
    expect(await screen.findByRole('listitem', { name: /^mardi 19 janvier/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Aujourd’hui' }));
    expect(await screen.findByRole('listitem', { name: /^mardi 12 janvier/i })).toBeInTheDocument();
  });

  it('état vide', async () => {
    definirEtatForce('vide');
    await ouvrir();
    expect(await screen.findByText(/Rien à afficher pour le moment/)).toBeInTheDocument();
  });

  it('état d’erreur', async () => {
    definirEtatForce('erreur');
    await ouvrir();
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});
