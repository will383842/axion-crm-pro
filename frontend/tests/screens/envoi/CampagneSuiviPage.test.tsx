/**
 * DM2-CAM — suivi d'une campagne : progression, destinataires et état de chacun,
 * test A/B. Décision de Will : aucun suivi des clics ; les ouvertures ne sont
 * mesurées que si la campagne coche « suivre_ouvertures », affichées comme une
 * ESTIMATION, les ouvertures automatiques exclues du taux ; les réponses sont
 * l'indicateur principal.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
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

  it('campagne qui ne suit pas les ouvertures : aucune colonne ni tuile d’ouverture', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-08');
    await screen.findByRole('heading', { level: 1, name: /Salon des artisans de Vienne/ });
    expect(screen.queryByTestId('tuile-ouvertures')).not.toBeInTheDocument();
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await screen.findAllByRole('row');
    expect(screen.queryByRole('columnheader', { name: /ouverture/i })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/\bclics?\b/i);
  });

  it('campagne qui suit les ouvertures : ESTIMATION, automatiques exclues du taux', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    const tuile = await screen.findByTestId('tuile-ouvertures');
    expect(tuile).toHaveTextContent('estimation');
    expect(tuile).toHaveTextContent(/ouvertures automatiques/i);
    expect(tuile).toHaveTextContent(/exclues du taux/i);
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    expect(await screen.findByRole('columnheader', { name: /Ouverture \(estimation\)/ })).toBeInTheDocument();
    // Jamais un chiffre exact d'ouverture par destinataire, jamais de clic.
    expect(await screen.findAllByText(/Ouverture estimée/)).not.toHaveLength(0);
    expect(document.body.textContent).not.toMatch(/Ouvert \d+ fois/);
    expect(document.body.textContent).not.toMatch(/\bclics?\b/i);
  });

  it('contact exclu (opposé) : aucune ouverture, même si la campagne suit les ouvertures', async () => {
    // Un contact inscrit au registre d'opposition ne reçoit jamais de pixel : même
    // si une donnée d'ouverture traînait, elle ne doit jamais s'afficher.
    const cible = demoDb.destinataires.tous().find((d) => d.campagne_id === 'cmp-01' && d.adresse.startsWith('mathilde.bertin@'));
    expect(cible).toBeDefined();
    demoDb.destinataires.modifier(cible!.id, {
      etat: 'exclu',
      motif: 'Inscrit au registre d’opposition',
      ouverture: { nombre: 1, premiere_le: '2027-01-11T10:00:00.000Z', automatique_probable: false, raison_automatique: null },
    });
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByTestId('tuile-ouvertures');
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^Exclus/ }));
    const ligne = await waitFor(() => {
      const l = screen.queryAllByRole('row').find((r) => /Bertin/.test(r.textContent ?? ''));
      if (!l) throw new Error('Mathilde Bertin absente de la liste des exclus');
      return l;
    });
    expect(ligne.textContent).toMatch(/Exclu/);
    expect(ligne.textContent).not.toMatch(/Ouverture estimée|Automatique probable/);
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
