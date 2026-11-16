/**
 * Utilisabilité des écrans Envoi (audit) : brouillon rouvrable, vrai motif du
 * serveur, confirmations, retour après action, textes justes, feu vert, suivi.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

import { CampagnesPage } from '@/features/envoi/campagnes/CampagnesPage';
import { CampagneSuiviPage } from '@/features/envoi/campagnes/CampagneSuiviPage';
import { MessagesTypesPage } from '@/features/envoi/messages/MessagesTypesPage';
import { SequencesPage } from '@/features/envoi/sequences/SequencesPage';
import { TestMessage } from '@/features/envoi/messages/composants/TestMessage';
import { SuggestionIA } from '@/features/envoi/messages/composants/SuggestionIA';
import { FormulaireReponse } from '@/features/envoi/reponses/composants/FormulaireReponse';
import { ETAT_LIBELLE } from '@/features/envoi/campagnes/composants/libelles';
import { libelleAvancement, ETAPE_SUIVANTE } from '@/features/envoi/pilotage/feuVertLibelles';
import { handlers as handlersCampagnes } from '@/demo/handlers/envoi/campagnes.handlers';
import { handlers as handlersCreation } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const MOTIF = 'Motif du serveur : un test est déjà en cours.';
const refus = (methode: 'post', chemin: string, statut = 409) =>
  http[methode](`*${chemin}`, () => HttpResponse.json({ message: MOTIF }, { status: statut }));

beforeEach(() => {
  demoDb.reinitialiser();
  toast.success.mockClear();
});

describe('(1) brouillon rouvrable', () => {
  it('« Ouvrir » mène à l’assistant avec le brouillon', async () => {
    const view = await renderScreen(<CampagnesPage />, {
      path: '/envoi/campagnes',
      handlers: handlersCampagnes,
      landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'],
    });
    const brouillon = demoDb.campagnes.tous().find((c) => c.statut === 'brouillon')!;
    const ligne = await screen.findByRole('row', { name: new RegExp(brouillon.nom) });
    await userEvent.setup().click(within(ligne).getByRole('link', { name: new RegExp(`^Ouvrir : `) }));
    await waitFor(() => expect(view.router.state.location.pathname).toBe('/envoi/campagnes/nouvelle'));
    expect(view.router.state.location.search).toMatchObject({ brouillon: String(brouillon.id) });
  });

  it('le suivi d’un brouillon propose « Reprendre la création »', async () => {
    const brouillon = demoDb.campagnes.tous().find((c) => c.statut === 'brouillon')!;
    const view = await renderScreen(<CampagneSuiviPage />, {
      path: '/envoi/campagnes/$campagneId',
      url: `/envoi/campagnes/${brouillon.id}`,
      handlers: handlersCampagnes,
      landingRoutes: ['/envoi/campagnes/nouvelle'],
    });
    await userEvent.setup().click(await screen.findByRole('link', { name: 'Reprendre la création' }));
    await waitFor(() => expect(view.router.state.location.pathname).toBe('/envoi/campagnes/nouvelle'));
    expect(view.router.state.location.search).toMatchObject({ brouillon: String(brouillon.id) });
  });

  it('pas de « Reprendre la création » pour une campagne en cours', async () => {
    await renderScreen(<CampagneSuiviPage />, { path: '/envoi/campagnes/$campagneId', url: '/envoi/campagnes/cmp-01', handlers: handlersCampagnes });
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    expect(screen.queryByRole('link', { name: 'Reprendre la création' })).toBeNull();
  });
});

describe('(2) vrai motif du serveur', () => {
  it('TestMessage affiche le message du serveur', async () => {
    const onEnvoye = vi.fn();
    await renderScreen(<TestMessage ouvert onFermer={() => undefined} objet="o" objetB={null} corps="c" onEnvoye={onEnvoye} />, {
      handlers: [refus('post', '/envoi/messages/test'), ...handlersCreation],
    });
    await userEvent.setup().click(screen.getByRole('button', { name: 'Envoyer le test' }));
    await waitFor(() => expect(onEnvoye).toHaveBeenCalledWith(MOTIF));
  });

  it('TestMessage : « Aux boîtes témoins » sans nombre écrit en dur', async () => {
    await renderScreen(<TestMessage ouvert onFermer={() => undefined} objet="o" objetB={null} corps="c" onEnvoye={() => undefined} />, { handlers: handlersCreation });
    expect(screen.getByLabelText('Aux boîtes témoins')).toBeInTheDocument();
  });

  it('SuggestionIA affiche le message du serveur (501)', async () => {
    await renderScreen(<SuggestionIA ouverte onFermer={() => undefined} />, { handlers: [refus('post', '/envoi/messages/suggestion', 501), ...handlersCreation] });
    expect(await screen.findByRole('alert')).toHaveTextContent(MOTIF);
  });

  it('FormulaireReponse affiche le message du serveur', async () => {
    await renderScreen(<FormulaireReponse id="rep-01" />, { handlers: [http.post('*/envoi/reponses/*/repondre', () => HttpResponse.json({ message: MOTIF }, { status: 409 }))] });
    const user = userEvent.setup();
    await user.type(screen.getByRole('textbox'), 'Bonjour');
    await user.click(screen.getByRole('button', { name: 'Envoyer la réponse' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(MOTIF);
  });
});

describe('(3) confirmations', () => {
  it('Archiver demande confirmation, et il n’y a plus de « Désarchiver »', async () => {
    await renderScreen(<MessagesTypesPage />, { path: '/envoi/campagnes/messages', handlers: handlersCreation, landingRoutes: ['/envoi/campagnes/messages/$messageId'] });
    const user = userEvent.setup();
    await user.click((await screen.findAllByRole('button', { name: /^Archiver / }))[0]!);
    const dialogue = await screen.findByRole('dialog');
    expect(dialogue).toHaveTextContent('ne peut pas être annulé pour l’instant');
    await user.click(within(dialogue).getByRole('button', { name: 'Annuler' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await user.click(screen.getByRole('checkbox', { name: /archivés/ }));
    await waitFor(() => expect(screen.queryByRole('button', { name: /Désarchiver/ })).toBeNull());
  });
});

describe('(4) retour après action', () => {
  it('Pause : message de succès', async () => {
    await renderScreen(<CampagnesPage />, { path: '/envoi/campagnes', handlers: handlersCampagnes, landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'] });
    const user = userEvent.setup();
    const ligne = await screen.findByRole('row', { name: /Organisateurs Rhône-Alpes/ });
    await user.click(within(ligne).getByRole('button', { name: /^Pause/ }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Mettre en pause' }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith(expect.stringContaining('en pause')));
  });
});

describe('(5) à (8) textes et libellés', () => {
  it('feu vert : points validés sur le total, points restants, étape suivante', () => {
    expect(libelleAvancement({ faits: 3, total: 15 })).toEqual({ valides: '3 points validés sur 15', restants: '12 points restants' });
    expect(libelleAvancement({ faits: 14, total: 15 }).restants).toBe('1 point restant');
    expect(ETAPE_SUIVANTE).toBe('Après le feu vert, l’ouverture de l’envoi réel est une décision écrite du fondateur, appliquée sur le serveur.');
  });

  it('suivi : plainte ≠ exclu, adresse invalide ≠ refus temporaire', () => {
    expect(ETAT_LIBELLE.plainte).toBe('Plainte');
    expect(ETAT_LIBELLE.exclu).toBe('Exclu');
    expect(ETAT_LIBELLE.rebond_dur).toBe('Adresse invalide');
    expect(ETAT_LIBELLE.rebond_mou).toBe('Refusé temporairement');
  });

  it('séquences : l’état vide explique la création automatique', async () => {
    await renderScreen(<SequencesPage />, { path: '/envoi/sequences', handlers: [http.get('*/envoi/sequences', () => HttpResponse.json({ sequences: [] }))] });
    expect(await screen.findByText(/créée automatiquement quand vous programmez une campagne/)).toBeInTheDocument();
  });
});
