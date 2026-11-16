/**
 * Suite de l'audit d'utilisabilité d'Envoi : messages de succès des gestes,
 * motif d'erreur dans l'assistant, textes retirés, bandeau de démonstration,
 * feu vert réellement affiché, brouillon rouvert sans destinataires.
 */
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

import { CampagnesPage } from '@/features/envoi/campagnes/CampagnesPage';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { BoitePage } from '@/features/envoi/infrastructure/BoitePage';
import { FeuVertPage } from '@/features/envoi/pilotage/FeuVertPage';
import { handlers as handlersCampagnes } from '@/demo/handlers/envoi/campagnes.handlers';
import { handlers as handlersCreation } from '@/demo/handlers/envoi/creation.handlers';
import { handlers as handlersInfra } from '@/demo/handlers/envoi/infrastructure.handlers';
import { handlers as handlersPilotage } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/nouvelle';
type Utilisateur = ReturnType<typeof userEvent.setup>;

beforeEach(() => {
  demoDb.reinitialiser();
  toast.success.mockClear();
});
afterEach(() => vi.unstubAllEnvs());

const liste = () =>
  renderScreen(<CampagnesPage />, { path: '/envoi/campagnes', handlers: handlersCampagnes, landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'] });

async function continuer(user: Utilisateur, nom: RegExp) {
  const bouton = await screen.findByRole('button', { name: nom });
  await waitFor(() => { expect(bouton).toBeEnabled(); });
  await user.click(bouton);
}

describe('messages de succès des gestes', () => {
  it('Terminer', async () => {
    await liste();
    const user = userEvent.setup();
    const ligne = await screen.findByRole('row', { name: /Commerces de Vienne/ });
    await user.click(within(ligne).getByRole('button', { name: /^Terminer/ }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Terminer la campagne' }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('« Commerces de Vienne » est terminée.'));
  });

  it('Changer la date', async () => {
    await liste();
    const user = userEvent.setup();
    const ligne = await screen.findByRole('row', { name: /Fédérations/ });
    await user.click(within(ligne).getByRole('button', { name: /^Changer la date/ }));
    const dialogue = await screen.findByRole('dialog');
    await user.click(within(dialogue).getByRole('button', { name: 'Demain 9:00' }));
    await user.click(within(dialogue).getByRole('button', { name: 'Reprogrammer' }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith(expect.stringMatching(/^Nouvelle date enregistrée : /)));
  });

  it('Reprise', async () => {
    await liste();
    const user = userEvent.setup();
    const ligne = await screen.findByRole('row', { name: /Commerces de Vienne/ });
    await user.click(within(ligne).getByRole('button', { name: /^Reprendre/ }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Reprendre la campagne' }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('« Commerces de Vienne » a repris : les envois repartent.'));
  });

  it('Dupliquer', async () => {
    await liste();
    const user = userEvent.setup();
    const ligne = await screen.findByRole('row', { name: /Organisateurs Rhône-Alpes/ });
    await user.click(within(ligne).getByRole('button', { name: /^Dupliquer/ }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Créer la copie (brouillon)' }));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('La copie « Organisateurs Rhône-Alpes (copie) » est créée en brouillon.'));
  });
});

describe('assistant', () => {
  const ouvrir = (url = `${CHEMIN}?etiquette=salon-artisans-2026`, extra: ReturnType<typeof http.get>[] = []) =>
    renderScreen(<NouvelleCampagnePage />, { path: CHEMIN, url, handlers: [...extra, ...handlersCreation], landingRoutes: ['/envoi/campagnes/$campagneId'] });

  it('EtapeMessage : « Continuer » affiche le motif du serveur', async () => {
    const motif = 'Enregistrement du message refusé : brouillon verrouillé.';
    await ouvrir(undefined, [http.put('*/envoi/brouillons/*/etapes/2', () => HttpResponse.json({ message: motif }, { status: 409 }))]);
    const user = userEvent.setup();
    await continuer(user, /Continuer : message/);
    await continuer(user, /Continuer : quand/);
    expect(await screen.findByRole('alert')).toHaveTextContent(motif);
  });

  it('EtapeCible : plus d’estimation à 12 % sous « Revérifier »', async () => {
    await ouvrir();
    await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    expect(await screen.findByText(/46 adresses retirées d’office/)).toBeVisible();
    expect(screen.getByText(/Revérifier les adresses vues/)).toBeVisible();
    expect(screen.queryByText(/adresses concernées/)).toBeNull();
  });

  async function jusquaLancement() {
    await ouvrir();
    const user = userEvent.setup();
    await continuer(user, /Continuer : message/);
    await continuer(user, /Continuer : quand/);
    await user.click(await screen.findByRole('button', { name: 'Demain 9:00' }));
    await continuer(user, /Continuer : vérification/);
    await continuer(user, /Continuer : lancement/);
    await user.click(await screen.findByRole('button', { name: 'Envoyer le code par e-mail' }));
    await screen.findByLabelText('Code reçu par e-mail');
  }

  it('la phrase du bandeau n’apparaît qu’en démonstration', async () => {
    vi.stubEnv('VITE_DEMO', '1');
    await jusquaLancement();
    expect(screen.getByText(/En démonstration, il est affiché dans le bandeau/)).toBeVisible();
    cleanup();
    vi.stubEnv('VITE_DEMO', '0');
    demoDb.reinitialiser();
    await jusquaLancement();
    expect(screen.getByText(/Code envoyé à /)).toBeVisible();
    expect(screen.queryByText(/bandeau/)).toBeNull();
  });

  it('brouillon rouvert sans destinataires : une phrase d’aide, sans toucher au serveur', async () => {
    const sansCible = http.get('*/envoi/brouillons/bro-9', () =>
      HttpResponse.json({ id: 'bro-9', nom: 'Copie sans cible', etape_courante: 1, etapes_faites: [], contenu: {}, mis_a_jour_le: '2027-01-12T09:00:00.000Z', campagne_id: null, annulable_jusqua: null }),
    );
    await ouvrir(`${CHEMIN}?brouillon=bro-9`, [sansCible]);
    expect(await screen.findByText('Choisissez de nouveau les destinataires.')).toBeVisible();
  });

  it('avec une cible déjà choisie, pas de phrase « de nouveau »', async () => {
    await ouvrir();
    await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    expect(screen.queryByText(/Choisissez de nouveau/)).toBeNull();
  });
});

describe('textes retirés ou corrigés', () => {
  it('fiche boîte : aucun « plafond 150 par jour » écrit en dur', async () => {
    await renderScreen(<BoitePage />, { path: '/envoi/boites/$boiteId', url: '/envoi/boites/boi-camille', handlers: handlersInfra });
    await screen.findByRole('heading', { level: 1, name: 'camille@prospection-b.example' });
    expect(screen.getByText('Quota du jour')).toBeInTheDocument();
    expect(screen.queryByText(/plafond 150/i)).toBeNull();
  });

  it('feu vert : l’écran réel montre l’avancement, la phrase de suite et la note de démonstration', async () => {
    vi.stubEnv('VITE_DEMO', '1');
    await renderScreen(<FeuVertPage />, { path: '/envoi/sante/feu-vert', handlers: handlersPilotage });
    expect(await screen.findByText(/points validés sur \d+/)).toBeVisible();
    expect(screen.getByText(/points? restants?/)).toBeVisible();
    expect(screen.getByText('Après le feu vert, l’ouverture de l’envoi réel est une décision écrite du propriétaire de l’espace, appliquée ensuite par l’exploitant.')).toBeVisible();
    expect(screen.queryByText(/fondateur/i)).toBeNull();
    expect(screen.getByRole('note')).toHaveTextContent(/Démonstration/);
  });
});
