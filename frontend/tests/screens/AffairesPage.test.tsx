/**
 * ÉCRAN `/affaires` — `src/features/crm/affaires/AffairesPage.tsx` (lot DM1-ACC).
 *
 * Les affaires reposent sur l'étape de la fiche (`lifecycle_stage` étendu) :
 * les sept étapes de Will, du premier contact au gain ou à la perte.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { AffairesPage } from '@/features/crm/affaires/AffairesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/crm/affaires.handlers';
import { renderScreen } from '../helpers/renderScreen';

const PATH = '/affaires';
const ETAPES = ['À contacter', 'Contacté', 'Répondu', 'Rendez-vous', 'Devis', 'Gagné', 'Perdu'];

function colonne(nom: string): HTMLElement {
  return screen.getByRole('region', { name: new RegExp(`^${nom}`) });
}

describe('AffairesPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('montre les 7 colonnes de Will, dans l’ordre', async () => {
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    expect(await screen.findByRole('heading', { level: 1, name: 'Affaires' })).toBeVisible();
    await screen.findByRole('region', { name: /^À contacter/ });
    const titres = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent ?? '');
    const trouvees = ETAPES.map((e) => titres.findIndex((t) => t.startsWith(e)));
    expect(trouvees.every((i) => i >= 0)).toBe(true);
    expect([...trouvees].sort((a, b) => a - b)).toEqual(trouvees);
  });

  it('affiche le montant total de chaque colonne', async () => {
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    const devis = await screen.findByRole('region', { name: /^Devis/ });
    // Imprimerie du Lac 8 900 € + Transports Rivière 12 000 € = 20 900 €.
    expect(devis).toHaveTextContent(/Montant total/);
    expect(devis.textContent?.replace(/\s/g, '')).toContain('20900€');
    expect(within(colonne('Devis')).getByText('Imprimerie du Lac')).toBeVisible();
  });

  it('déplace une carte au clavier, par la liste « Déplacer vers »', async () => {
    const user = userEvent.setup();
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    const carte = await screen.findByRole('combobox', { name: /Déplacer Boulangerie des Tilleuls/ });
    carte.focus();
    await user.selectOptions(carte, 'Rendez-vous');

    await waitFor(() => expect(within(colonne('Rendez-vous')).getByText('Boulangerie des Tilleuls')).toBeVisible());
    expect(within(colonne('Répondu')).queryByText('Boulangerie des Tilleuls')).not.toBeInTheDocument();
  });

  it('« Perdu » demande une raison dans une liste courte', async () => {
    const user = userEvent.setup();
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    const liste = await screen.findByRole('combobox', { name: /Déplacer Imprimerie du Lac/ });
    await user.selectOptions(liste, 'Perdu');

    const dialogue = await screen.findByRole('dialog');
    const raison = within(dialogue).getByRole('combobox', { name: /Raison de la perte/ });
    const choix = within(raison).getAllByRole('option').map((o) => o.textContent);
    expect(choix.length).toBeLessThanOrEqual(7);
    expect(choix).toContain('Prix trop élevé');
    const confirmer = within(dialogue).getByRole('button', { name: 'Confirmer la perte' });
    expect(confirmer).toBeDisabled();

    await user.selectOptions(raison, 'Prix trop élevé');
    expect(confirmer).toBeEnabled();
    await user.click(confirmer);

    await waitFor(() => expect(within(colonne('Perdu')).getByText('Imprimerie du Lac')).toBeVisible());
    expect(within(colonne('Perdu')).getAllByText(/Prix trop élevé/).length).toBeGreaterThan(0);
  });

  it('renonce à la perte : la carte reste où elle est', async () => {
    const user = userEvent.setup();
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    const liste = await screen.findByRole('combobox', { name: /Déplacer Transports Rivière/ });
    await user.selectOptions(liste, 'Perdu');
    await user.click(await screen.findByRole('button', { name: 'Annuler' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(within(colonne('Devis')).getByText('Transports Rivière')).toBeVisible();
  });

  it('chaque carte donne la chance de réussite et la signature prévue', async () => {
    await renderScreen(<AffairesPage />, { path: PATH, handlers });

    const devis = await screen.findByRole('region', { name: /^Devis/ });
    expect(within(devis).getByText(/70 %/)).toBeVisible();
    expect(within(devis).getByText(/signature prévue/i)).toBeVisible();
  });

  it('état vide : « Aucune affaire »', async () => {
    window.sessionStorage.setItem('axion-demo:etat', 'vide');
    try {
      await renderScreen(<AffairesPage />, { path: PATH, handlers });
      expect(await screen.findByText('Aucune affaire')).toBeVisible();
    } finally {
      window.sessionStorage.removeItem('axion-demo:etat');
    }
  });

  it('état d’erreur : « Réessayer »', async () => {
    window.sessionStorage.setItem('axion-demo:etat', 'erreur');
    try {
      await renderScreen(<AffairesPage />, { path: PATH, handlers });
      expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeVisible();
    } finally {
      window.sessionStorage.removeItem('axion-demo:etat');
    }
  });
});
