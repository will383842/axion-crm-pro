/**
 * D24-002 — LA CLOCHE DE L'EN-TETE N'AVAIT AUCUN GESTIONNAIRE DE CLIC.
 *
 * ═══ LE DEFAUT MESURE ═══
 *
 * `Header.tsx` rendait, entre la recherche et le theme :
 *
 *     <IconButton label="Notifications" variant="ghost" size="sm" className="…">
 *       <Bell className="h-4 w-4" />
 *     </IconButton>
 *
 * Aucun `onClick`. Le clic reussit — c'est un vrai bouton — et il ne se passe
 * RIEN. La sonde `parcours.mjs` de l'agent 24 attendait 4 s l'apparition d'un
 * `[role="dialog"]`, `[role="menu"]` ou `[role="listbox"]` : expiration.
 *
 * Et ce n'est pas « un bouton en trop ». Le §23.4 fait partir le parcours n°1
 * — le plus frequent — de « notification ou boite de reception ». La cloche
 * PROMET un canal d'entrants. Un bouton qui promet et ne fait rien coute plus
 * cher qu'un bouton absent : l'utilisateur conclut qu'il n'a rien recu.
 *
 * ═══ CE QUI EXISTAIT DEJA, ET QUI N'ETAIT RELIE A RIEN ═══
 *
 *  - `GET /notifications` est REEL depuis le correctif B12-007 : il lit la table
 *    `notifications`, filtre sur l'espace ET sur la personne, et rend
 *    `{ data: […], unread_count: n }`. Avant B12-007 il rendait `[]` en dur.
 *  - `lib/echo.ts:85` ecoute deja `.notification.created` sur le canal du
 *    workspace et leve un toast.
 *  - Aucun fichier du frontend n'appelait `/notifications` (`grep` de l'agent 24
 *    → 2 lignes, deux commentaires).
 *
 * Trois moities de mecanisme, aucune reliee. Cette garde relie la troisieme.
 *
 * ═══ CE QUE LA GARDE EXIGE ═══
 *
 *  1. TEMOIN — avant le clic, aucun titre de notification n'est a l'ecran. Sans
 *     lui, un panneau rendu en permanence passerait au vert.
 *  2. Le clic OUVRE un panneau, et le panneau porte les notifications rendues
 *     par le serveur. Un panneau vide qui s'ouvre ne prouve rien : on cherche
 *     les titres du corps simule.
 *  3. Le compteur de non-lues est ANNONCE, y compris aux lectrices d'ecran (il
 *     entre dans le nom accessible du bouton).
 *  4. `200` + liste vide : l'ecran dit qu'il n'y a rien.
 *  5. `500` : l'ecran dit la PANNE et n'affirme PLUS « aucune notification » —
 *     c'est D25-001 applique a la cloche.
 *  6. `degraded: true` — le controleur repond `200` avec ce drapeau quand sa
 *     requete a leve : la liste est vide SANS QUE CELA VEUILLE DIRE « rien ».
 *     Le panneau doit le dire, jamais conclure a l'absence.
 *  7. HONNETETE SUR CE QUI N'EXISTE PAS — `markRead` et `markAllRead` rendent
 *     `501`. Le panneau ne doit donc offrir AUCUN bouton de marquage (ce serait
 *     recreer D24-002 un cran plus bas), et doit dire que la fonction n'est pas
 *     disponible. ⚠️ Le jour ou le serveur implemente ces deux routes, c'est
 *     CETTE assertion qu'il faut retourner — pas la contourner.
 *  8. `Echap` referme.
 *
 * ⚠️ Toutes les sous-chaines cherchees sont SANS LETTRE ACCENTUEE et sans
 * apostrophe typographique.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse } from '../msw/handlers';

/** Deux lignes, dont UNE non lue — la forme exacte rendue par `NotificationsController::index`. */
const DEUX_NOTIFICATIONS = {
  data: [
    {
      id: 'n-1',
      type: 'scrape.completed',
      title: 'Scrape termine',
      body: '12 entreprises creees sur Lyon',
      action_url: '/scraper-runs',
      read_at: null,
      created_at: '2026-08-19T10:00:00.000000Z',
    },
    {
      id: 'n-2',
      type: 'company.enriched',
      title: 'Fiche enrichie',
      body: 'Boulangerie Martin — score 82/100',
      action_url: null,
      read_at: '2026-08-19T09:00:00.000000Z',
      created_at: '2026-08-18T09:00:00.000000Z',
    },
  ],
  unread_count: 1,
};

const AUCUNE_NOTIFICATION = { data: [], unread_count: 0 };

/** Ce que le controleur rend quand SA PROPRE requete a leve (`catch` de `index`). */
const LECTURE_DEGRADEE = { data: [], unread_count: 0, degraded: true };

function texteEcran(): string {
  return document.body.textContent ?? '';
}

/** Monte la coquille complete — c'est bien l'EN-TETE qui est en cause, pas un composant isole. */
async function monterCoquille(reponse: unknown, status = 200): Promise<void> {
  await renderScreen(<p>contenu de page</p>, {
    path: '/',
    withLayout: true,
    handlers: [
      status === 200
        ? getJson('/notifications', reponse)
        : http.get(apiUrl('/notifications'), () => new HttpResponse(null, { status })),
    ],
  });
}

function cloche(): HTMLElement {
  return screen.getByRole('button', { name: /Notifications/ });
}

describe('D24-002 — la cloche de l’en-tete ouvre un panneau reel', () => {
  it('TEMOIN — la cloche existe, et AVANT le clic aucune notification n’est a l’ecran', async () => {
    await monterCoquille(DEUX_NOTIFICATIONS);

    expect(cloche()).toBeInTheDocument();
    // Sans ce temoin, un panneau rendu en permanence rendrait le cas suivant
    // vert sans qu'aucun clic n'ait jamais servi a rien.
    expect(texteEcran()).not.toContain('Scrape termine');
  });

  it('le clic OUVRE un panneau qui porte les notifications du serveur', async () => {
    await monterCoquille(DEUX_NOTIFICATIONS);
    const user = userEvent.setup();

    await user.click(cloche());

    const panneau = await screen.findByRole('dialog', { name: /Notifications/ });
    expect(panneau).toBeInTheDocument();

    await waitFor(() => {
      expect(texteEcran()).toContain('Scrape termine');
    });
    expect(texteEcran()).toContain('Fiche enrichie');
    expect(texteEcran()).toContain('12 entreprises creees sur Lyon');
  });

  it('le nombre de non-lues est annonce, y compris dans le nom accessible du bouton', async () => {
    await monterCoquille(DEUX_NOTIFICATIONS);

    // `unread_count: 1` — le nom accessible doit le porter : une pastille
    // coloree seule ne dit rien a une lectrice d'ecran.
    await waitFor(() => {
      expect(cloche().getAttribute('aria-label') ?? '').toContain('1 non lue');
    });
  });

  it('TEMOIN — sans non-lue, le bouton n’invente pas de compteur', async () => {
    await monterCoquille(AUCUNE_NOTIFICATION);

    await waitFor(() => {
      expect(cloche()).toBeInTheDocument();
    });
    expect(cloche().getAttribute('aria-label') ?? '').not.toContain('non lue');
  });

  it('200 + liste vide : le panneau dit qu’il n’y a rien', async () => {
    await monterCoquille(AUCUNE_NOTIFICATION);
    const user = userEvent.setup();

    await user.click(cloche());

    await waitFor(() => {
      expect(texteEcran()).toContain('Aucune notification');
    });
    expect(texteEcran()).not.toContain('pas pu lire');
    expect(texteEcran()).not.toContain('serveur est en panne');
  });

  it('500 : le panneau nomme la panne, et n’affirme PLUS « aucune notification »', async () => {
    await monterCoquille(null, 500);
    const user = userEvent.setup();

    await user.click(cloche());

    await waitFor(() => {
      expect(texteEcran()).toContain('serveur est en panne');
    });
    expect(texteEcran()).toContain('code HTTP 500');
    expect(texteEcran()).not.toContain('Aucune notification');
  });

  it('200 avec `degraded` : la liste est vide, l’ecran ne conclut PAS a l’absence', async () => {
    await monterCoquille(LECTURE_DEGRADEE);
    const user = userEvent.setup();

    await user.click(cloche());

    // Le controleur a repondu 200 : React Query est en succes. Seul le drapeau
    // du corps distingue « il n'y a rien » de « je n'ai pas pu lire ».
    await waitFor(() => {
      expect(texteEcran()).toContain('pas pu lire');
    });
    expect(texteEcran()).not.toContain('Aucune notification');
  });

  it('marquage comme lu : rien n’est offert, et l’ecran DIT pourquoi', async () => {
    await monterCoquille(DEUX_NOTIFICATIONS);
    const user = userEvent.setup();

    await user.click(cloche());
    const panneau = await screen.findByRole('dialog', { name: /Notifications/ });

    // `POST /notifications/{n}/read` et `/read-all` rendent 501. Un bouton qui
    // les appellerait serait exactement le defaut qu'on ferme, un cran plus bas.
    //
    // ⚠️ La recherche est CANTONNEE au panneau : la cloche elle-meme porte
    // « 1 non lue » dans son nom accessible, et un `queryByRole` global la
    // trouverait — la garde rougirait sur son propre correctif.
    expect(within(panneau).queryByRole('button', { name: /marquer/i })).toBeNull();
    expect(within(panneau).queryByRole('button', { name: /lue/i })).toBeNull();
    expect(texteEcran()).toContain('pas encore disponible');
  });

  it('Echap referme le panneau', async () => {
    await monterCoquille(DEUX_NOTIFICATIONS);
    const user = userEvent.setup();

    await user.click(cloche());
    await screen.findByRole('dialog', { name: /Notifications/ });

    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: /Notifications/ })).toBeNull();
    });
  });
});
