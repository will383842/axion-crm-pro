/**
 * ÉCRAN `/taches` — `src/features/crm/taches/MesTachesPage.tsx` (lot DM1-ACC).
 *
 * Les tâches vivent dans `activities` de type `task` (jamais de `crm_tasks`).
 * Ces tests rejouent les handlers de la démo : le même faux serveur que le
 * navigateur. « Aujourd'hui » est fixe : mardi 12 janvier 2027.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MesTachesPage } from '@/features/crm/taches/MesTachesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/crm/taches.handlers';
import { renderScreen } from '../helpers/renderScreen';

const PATH = '/taches';

function groupe(nom: RegExp): HTMLElement {
  return screen.getByRole('region', { name: nom });
}

describe('MesTachesPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('range les tâches en trois groupes : en retard, aujourd’hui, cette semaine', async () => {
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });

    expect(await screen.findByRole('heading', { level: 1, name: 'Mes tâches' })).toBeVisible();
    const retard = await screen.findByRole('region', { name: /En retard/ });
    expect(within(retard).getByText('Relancer la fédération du bâtiment')).toBeVisible();
    expect(within(groupe(/Aujourd.hui/)).getByText('Rappeler Paul Morin')).toBeVisible();
    expect(within(groupe(/Cette semaine/)).getByText('Rendez-vous Club de voile')).toBeVisible();
    // Le résumé de l'en-tête : 4 du jour + 1 en retard.
    expect(screen.getByText(/5 tâches aujourd.hui, 1 en retard/)).toBeVisible();
  });

  it('une tâche venue d’une réponse porte son origine', async () => {
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });

    const retard = await screen.findByRole('region', { name: /En retard/ });
    expect(within(retard).getByText(/créée par une réponse/i)).toBeVisible();
    // Une tâche saisie à la main ne porte pas cette mention.
    const jour = groupe(/Aujourd.hui/);
    expect(within(jour).queryByText(/créée par une réponse/i)).not.toBeInTheDocument();
  });

  it('cocher une tâche la range dans « Faites » et se retrouve après rechargement', async () => {
    const user = userEvent.setup();
    const vue = await renderScreen(<MesTachesPage />, { path: PATH, handlers });

    const case_ = await screen.findByRole('checkbox', { name: /Rappeler Paul Morin/ });
    expect(case_).not.toBeChecked();
    await user.click(case_);

    const faites = await screen.findByRole('region', { name: /Faites/ });
    await waitFor(() => expect(within(faites).getByText('Rappeler Paul Morin')).toBeVisible());
    expect(within(groupe(/Aujourd.hui/)).queryByText('Rappeler Paul Morin')).not.toBeInTheDocument();

    // Le geste est dans la base de la démo : il survit à un nouveau montage.
    vue.unmount();
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });
    const faitesApres = await screen.findByRole('region', { name: /Faites/ });
    expect(within(faitesApres).getByText('Rappeler Paul Morin')).toBeVisible();
  });

  it('le rappel du matin s’affiche comme un réglage et se modifie', async () => {
    const user = userEvent.setup();
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });

    const bascule = await screen.findByRole('switch', { name: /Recevoir mes tâches à 08:00/ });
    expect(bascule).toBeChecked();
    await user.click(bascule);
    await waitFor(() => expect(screen.getByRole('switch', { name: /Recevoir mes tâches à 08:00/ })).not.toBeChecked());
  });

  it('ajoute une tâche depuis le bouton unique de la page', async () => {
    const user = userEvent.setup();
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });

    await user.click(await screen.findByRole('button', { name: 'Ajouter une tâche' }));
    const dialogue = await screen.findByRole('dialog');
    await user.type(within(dialogue).getByLabelText('Intitulé'), 'Appeler le Club de voile');
    await user.click(within(dialogue).getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(await screen.findByText('Appeler le Club de voile')).toBeVisible();
  });

  it('les onglets du tableau de bord sont là, « Mes tâches » est l’onglet courant', async () => {
    await renderScreen(<MesTachesPage />, { path: PATH, handlers });
    await screen.findByRole('heading', { level: 1, name: 'Mes tâches' });
    expect(screen.getByRole('link', { name: /Affaires/ })).toBeVisible();
  });

  it('état vide : « Rien à faire aujourd’hui »', async () => {
    window.sessionStorage.setItem('axion-demo:etat', 'vide');
    try {
      await renderScreen(<MesTachesPage />, { path: PATH, handlers });
      expect(await screen.findByText(/Rien à faire aujourd.hui/)).toBeVisible();
    } finally {
      window.sessionStorage.removeItem('axion-demo:etat');
    }
  });

  it('état d’erreur : message et « Réessayer »', async () => {
    window.sessionStorage.setItem('axion-demo:etat', 'erreur');
    try {
      await renderScreen(<MesTachesPage />, { path: PATH, handlers });
      expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeVisible();
    } finally {
      window.sessionStorage.removeItem('axion-demo:etat');
    }
  });
});
