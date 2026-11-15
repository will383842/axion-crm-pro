/**
 * T-BOITES — l'écran des boîtes face aux réponses réelles du palier 0 (écran inchangé) :
 * C1 ajout désactivé et expliqué, aucun POST /envoi/boites ; C2 suspendre puis réactiver une suspension manuelle ;
 * C3 réactiver une pause automatique ⇒ refus expliqué, sans renvoyer vers une commande.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { BoitePage } from '@/features/envoi/infrastructure/BoitePage';
import { BoitesPage } from '@/features/envoi/infrastructure/BoitesPage';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../helpers/renderScreen';
import { apiUrl } from '../../msw/handlers';

const ID = '11111111-1111-4111-8111-111111111111';
const ADRESSE = 'a@boites.exemple.test';
const moi = http.get(apiUrl('/auth/me'), () => HttpResponse.json({ permissions: ['users.manage', 'workspaces.manage'] }));

function boite(statut: 'prete' | 'suspendue') {
  return {
    id: ID, adresse: ADRESSE, domaine_id: null, nom_affiche: ADRESSE, etat: statut === 'prete' ? 'vert' : 'rouge', statut,
    quota_jour: 20, envoyes_aujourdhui: 0, taux_refus: null, reponses_7j: 0,
  };
}

/** Serveur du palier 0 : suspendre pose la suspension manuelle, réactiver la lève ; une pause automatique répond 409. */
function serveur(depart: 'prete' | 'suspendue', pauseAutomatique = false) {
  let statut: 'prete' | 'suspendue' = depart;
  const appels: string[] = [];
  const reponses = [
    http.get(apiUrl('/envoi/boites'), () => HttpResponse.json({ data: [boite(statut)] })),
    http.get(apiUrl(`/envoi/boites/${ID}`), () => HttpResponse.json({
      boite: boite(statut), reponses: [], jours: [], derniers_refus: [],
      reglages: { signature: '', mode_absence: { actif: false, jusqu_au: null, message: null } },
    })),
    http.post(apiUrl(`/envoi/boites/${ID}/suspendre`), () => { appels.push('suspendre'); statut = 'suspendue'; return HttpResponse.json(boite(statut)); }),
    http.post(apiUrl(`/envoi/boites/${ID}/reactiver`), () => {
      appels.push('reactiver');
      if (pauseAutomatique) return HttpResponse.json({ error: 'pause_automatique', message: 'Cette boîte est sous pause de frein ou pause automatique.' }, { status: 409 });
      statut = 'prete';
      return HttpResponse.json(boite(statut));
    }),
    http.post(apiUrl('/envoi/boites'), () => { appels.push('ajouter'); return HttpResponse.json({ error: 'serveur_reel_requis', message: 'Indisponible.' }, { status: 501 }); }),
  ];
  return { appels, reponses };
}

describe('T-BOITES — boîtes au palier 0', () => {
  it('C1 : le bouton d’ajout est désactivé et expliqué, aucun POST /envoi/boites', async () => {
    const u = userEvent.setup();
    const s = serveur('prete');
    await renderScreen(<BoitesPage />, { path: '/envoi/boites', handlers: [moi, ...s.reponses, ...handlers] });
    await screen.findByText(ADRESSE);
    const bouton = screen.getByRole('button', { name: 'Ajout de boîte : bientôt disponible' });
    expect(bouton).toBeDisabled();
    await u.click(bouton);
    expect(s.appels).not.toContain('ajouter');
  });

  it('C2 : suspendre puis réactiver une boîte suspendue à la main', async () => {
    const u = userEvent.setup();
    const s = serveur('prete');
    await renderScreen(<BoitePage />, { path: '/envoi/boites/$boiteId', url: `/envoi/boites/${ID}`, handlers: [moi, ...s.reponses, ...handlers] });
    const suspendre = await screen.findByRole('button', { name: 'Suspendre' });
    await waitFor(() => expect(suspendre).toBeEnabled());
    await u.click(suspendre);
    const reactiver = await screen.findByRole('button', { name: 'Réactiver' });
    await waitFor(() => expect(reactiver).toBeEnabled());
    await u.click(reactiver);
    expect(await screen.findByRole('button', { name: 'Suspendre' })).toBeInTheDocument();
    expect(s.appels).toEqual(['suspendre', 'reactiver']);
  });

  it('C3 : réactiver une pause automatique ⇒ refus expliqué, sans renvoyer vers une commande', async () => {
    const u = userEvent.setup();
    const s = serveur('suspendue', true);
    await renderScreen(<BoitePage />, { path: '/envoi/boites/$boiteId', url: `/envoi/boites/${ID}`, handlers: [moi, ...s.reponses, ...handlers] });
    const reactiver = await screen.findByRole('button', { name: 'Réactiver' });
    await waitFor(() => expect(reactiver).toBeEnabled());
    await u.click(reactiver);
    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent('pause de frein ou pause automatique');
    expect(alerte.textContent ?? '').not.toMatch(/envoi:|lever-pause|commande/i);
    expect(screen.getByRole('button', { name: 'Réactiver' })).toBeInTheDocument();
  });
});
