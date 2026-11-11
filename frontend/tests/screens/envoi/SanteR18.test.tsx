import { describe, it, expect } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { SantePage } from '@/features/envoi/pilotage/SantePage';
import { ReputationPage } from '@/features/envoi/pilotage/ReputationPage';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const FOURNISSEURS = ['gmail', 'microsoft', 'yahoo', 'orange', 'ovh', 'autre'];
const JOURS = Array.from({ length: 14 }, (_, i) => `2026-11-${String(i + 1).padStart(2, '0')}`);

const SANTE = {
  feu: 'ambre', boites: [{ id: 'b1', adresse: 'a@prospection.example', domaine_id: '1', nom_affiche: 'a', etat: 'vert', statut: 'prete', quota_jour: 20, envoyes_aujourdhui: 0, taux_refus: null, reponses_7j: 0 }],
  domaines: [{ id: '1', nom: 'prospection.example', etat: 'vert', boites: 1, dmarc: 'reject', dmarc_prochaine_etape: null, listes_noires: 0, ajoute_le: '2026-11-01T00:00:00Z', chauffe: null }],
  serveurs: [], test_du_matin: { le: '2026-11-14T07:00:00Z', temoins_ok: 5, temoins_total: 6, etat: 'ambre' },
  alertes: 0, verifie_le: '2026-11-14T07:05:00Z', volume_jour: { possibles: 20, envoyes: 0 }, rythme_messageries: [],
  envoyes_14_jours: JOURS.map((jour) => ({ jour, envoyes: 0 })),
  pauses: [{ motif: 'indesirable_chez_un_temoin', portee: 'fournisseur', fournisseur: 'yahoo', fin: '2026-11-15T10:00:00Z' }],
  temoins_par_fournisseur: FOURNISSEURS.map((fournisseur) => ({ fournisseur, jours: JOURS.map((jour, i) => ({ jour, etat: i === 13 && fournisseur === 'yahoo' ? 'indesirable' : i < 10 ? 'ok' : 'non_teste' })) })),
};

const sante = (corps: Record<string, unknown> = SANTE) => http.get(api('/envoi/sante'), () => HttpResponse.json(corps));

describe('Santé — témoins par fournisseur (R18)', () => {
  it('affiche 6 fournisseurs × 14 jours, la pause avec son motif et sa fin, sans bouton de saisie', async () => {
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers: [sante()] });
    const tableau = await screen.findByRole('table', { name: /Témoins par fournisseur/ });
    expect(within(tableau).getAllByRole('row')).toHaveLength(7);
    expect(within(tableau).getAllByRole('columnheader')).toHaveLength(15);
    expect(within(tableau).getAllByText('Yahoo').length).toBeGreaterThan(0);
    expect(screen.getByRole('list', { name: 'Pauses d’envoi actives' })).toHaveTextContent(/indésirables chez un témoin.*yahoo.*jusqu’à/);
    expect(screen.queryByRole('button', { name: /ajouter un témoin|saisir une journée/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /ajouter un témoin|saisir une journée/i })).toBeNull();
  });

  it('sans donnée de témoins : dit « non mesuré », pas de vert inventé', async () => {
    const sansTemoins: Record<string, unknown> = { ...SANTE };
    delete sansTemoins['temoins_par_fournisseur'];
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers: [sante(sansTemoins)] });
    expect(await screen.findByText(/Témoins par fournisseur non mesurés/)).toBeVisible();
  });

  it('le test du matin fermé (409) s’explique en français', async () => {
    const handlers = [sante(), http.post(api('/envoi/sante/test'), () => HttpResponse.json({ error: 'envoi_ferme_palier_0', message: 'Les envois sont fermés (palier 0) : aucun test ne peut partir.' }, { status: 409 }))];
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers });
    await userEvent.click(await screen.findByRole('button', { name: 'Lancer un test' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/Envois fermés \(palier 0\)/);
  });
});

describe('Réputation (R18)', () => {
  const REP = { fournisseurs: FOURNISSEURS.map((fournisseur) => ({ fournisseur, niveau: fournisseur === 'gmail' ? 'bonne' : 'inconnue', mesure_le: fournisseur === 'gmail' ? '2026-11-14T06:00:00Z' : null, source: fournisseur === 'gmail' ? 'saisie' : null })) };

  it('chargement puis lecture : une réputation bonne ne lève rien, inconnue reste inconnue', async () => {
    await renderScreen(<ReputationPage />, { path: '/envoi/sante/reputation', handlers: [http.get(api('/envoi/reputation'), () => HttpResponse.json(REP))] });
    expect(screen.getByRole('status', { name: /Chargement/ })).toBeVisible();
    const tableau = await screen.findByRole('table', { name: /Réputation par fournisseur/ });
    expect(within(tableau).getAllByRole('row')).toHaveLength(7);
    expect(screen.getByText(/ne lève jamais une pause/)).toBeVisible();
    expect(within(tableau).getAllByText('Inconnue')).toHaveLength(5);
  });

  it('vide, panne et saisie refusée', async () => {
    await renderScreen(<ReputationPage />, { path: '/envoi/sante/reputation', handlers: [http.get(api('/envoi/reputation'), () => HttpResponse.json({ fournisseurs: [] }))] });
    expect(await screen.findByText('Aucune réputation relevée')).toBeVisible();
  });

  it('panne de lecture : message de panne et pas de tableau', async () => {
    await renderScreen(<ReputationPage />, { path: '/envoi/sante/reputation', handlers: [http.get(api('/envoi/reputation'), () => HttpResponse.json({ error: 'x', message: 'x' }, { status: 500 }))] });
    expect(await screen.findByText('Réputation inconnue')).toBeVisible();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('saisie : envoie le fournisseur et le niveau choisis', async () => {
    let recu: unknown = null;
    const handlers = [http.get(api('/envoi/reputation'), () => HttpResponse.json(REP)), http.post(api('/envoi/reputation/saisies'), async ({ request }) => { recu = await request.json(); return HttpResponse.json({}, { status: 201 }); })];
    await renderScreen(<ReputationPage />, { path: '/envoi/sante/reputation', handlers });
    await userEvent.selectOptions(await screen.findByLabelText('Messagerie'), 'yahoo');
    await userEvent.selectOptions(screen.getByLabelText('Niveau constaté'), 'mauvaise');
    await userEvent.click(screen.getByRole('button', { name: 'Enregistrer la réputation' }));
    await screen.findByText(/Réputation enregistrée/);
    expect(recu).toMatchObject({ fournisseur: 'yahoo', niveau: 'mauvaise' });
  });
});
