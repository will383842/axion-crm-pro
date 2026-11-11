/**
 * DM2-CAM — suivi d'une campagne : progression, destinataires et état de chacun,
 * test A/B. Décision produit : aucun suivi des clics et aucune ouverture
 * affichée ; les réponses sont l'indicateur principal.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CampagneSuiviPage } from '@/features/envoi/campagnes/CampagneSuiviPage';
import { handlers } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

async function ouvrir(id: string, query = '') {
  return renderScreen(<CampagneSuiviPage />, {
    path: '/envoi/campagnes/$campagneId',
    url: `/envoi/campagnes/${id}${query}`,
    handlers,
    landingRoutes: ['/envoi/campagnes', '/envoi/campagnes/nouvelle'],
  });
}

describe('CampagneSuiviPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it('montre la progression et les réponses comme indicateur principal', async () => {
    await ouvrir('cmp-01');
    expect(await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' })).toBeInTheDocument();
    expect(screen.getByText('En cours')).toBeInTheDocument();
    expect(screen.getByText(/168 envoyés sur 290/)).toBeInTheDocument();
    const reponses = screen.getByTestId('tuile-reponses');
    expect(reponses).toHaveTextContent('9');
    expect(reponses).toHaveTextContent('3 intéressées');
  });

  it('onglet Destinataires : filtrable par état, Yves Roche « refusé : adresse inexistante »', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^Refusés/ }));
    const yves = await screen.findByRole('row', { name: /Yves Roche/ });
    expect(within(yves).getByText('Refusé')).toBeInTheDocument();
    expect(yves).toHaveTextContent('Adresse inexistante');
    // Le filtre écarte les autres états.
    expect(screen.queryByRole('row', { name: /Paul Morin/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^Ont répondu/ }));
    const paul = await screen.findByRole('row', { name: /Paul Morin/ });
    expect(within(paul).getByText('A répondu')).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /Yves Roche/ })).not.toBeInTheDocument();
  });

  it('chaque destinataire a un état en français', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^En file/ }));
    const lignes = await screen.findAllByRole('row');
    expect(lignes.length).toBeGreaterThan(2);
    expect(screen.getAllByText('En file').length).toBeGreaterThan(1);
  });

  it('une campagne n’affiche jamais d’ouverture, qu’elle ait ou non la case cochée', async () => {
    const user = userEvent.setup();
    for (const id of ['cmp-08', 'cmp-01']) {
      cleanup();
      await ouvrir(id);
      await screen.findByRole('heading', { level: 1 });
      expect(screen.queryByTestId('tuile-ouvertures')).not.toBeInTheDocument();
      await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
      await screen.findAllByRole('row');
      expect(screen.queryByRole('columnheader', { name: /ouverture/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('combobox', { name: /ouverture/i })).not.toBeInTheDocument();
      expect(document.body.textContent).not.toMatch(/ouverture|pixel/i);
      expect(document.body.textContent).not.toMatch(/\bclics?\b/i);
    }
  });

  it('contact exclu (opposé) : aucune ouverture affichée', async () => {
    const cible = demoDb.destinataires.tous().find((d) => d.campagne_id === 'cmp-01' && d.adresse.startsWith('mathilde.bertin@'));
    expect(cible).toBeDefined();
    demoDb.destinataires.modifier(cible!.id, {
      etat: 'exclu',
      motif: 'Inscrit au registre d’opposition',
    });
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1 });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^Exclus/ }));
    const ligne = await waitFor(() => {
      const l = screen.queryAllByRole('row').find((r) => /Bertin/.test(r.textContent ?? ''));
      if (!l) throw new Error('Mathilde Bertin absente de la liste des exclus');
      return l;
    });
    expect(ligne.textContent).toMatch(/Exclu/);
    expect(ligne.textContent).not.toMatch(/ouverture|Automatique probable/i);
  });

  it('Test A/B : deux versions et le critère « réponses »', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: 'Test A/B' }));
    expect(await screen.findByText(/Version A/)).toBeInTheDocument();
    expect(screen.getByText(/Version B/)).toBeInTheDocument();
    expect(screen.getByText(/critère : réponses/)).toBeInTheDocument();
  });

  it('Pause depuis le suivi, avec confirmation', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('button', { name: 'Mettre en pause' }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Mettre en pause ?' })).toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Mettre en pause' }));
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-01')?.statut).toBe('en_pause'));
  });

  it('campagne inconnue : message clair', async () => {
    await ouvrir('cmp-999');
    expect(await screen.findByText(/introuvable/i)).toBeInTheDocument();
  });

  it('état d’erreur : bouton Réessayer', async () => {
    definirEtatForce('erreur');
    await ouvrir('cmp-01');
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});
