/**
 * GARDE X39-038 — LA CONSOLE DOIT SAVOIR REGLER ET FERMER UN COMPTE.
 *
 * ── CE QUI ETAIT MESURE, LE 2026-08-24 ────────────────────────────────────
 *
 *     grep -nE "api\.(put|delete)" src/features/users/UsersPage.tsx  →  RIEN
 *
 * `PUT /users/{user}` et `DELETE /users/{user}` existaient cote serveur depuis
 * le 2026-08-23, gardees par `permission:users.manage`, avec leurs refus ecrits
 * (on ne ferme pas son propre compte ; l'appartenance est REVOQUEE, pas
 * effacee). L'ecran ne les appelait ni l'une ni l'autre : on pouvait inviter
 * quelqu'un, jamais changer son role ni lui retirer l'acces.
 *
 * C'est le TROISIEME cas du meme motif en une journee — apres X39-027 (le
 * bouton d'invitation visait une route inexistante) et X39-037 (aucun ecran ne
 * consommait le lien de mot de passe). A chaque fois : le serveur savait faire,
 * la console ne demandait jamais. Cette garde ferme le cas des utilisateurs.
 *
 * ── CE QUE CETTE GARDE MESURE ─────────────────────────────────────────────
 *
 *   A. Changer le role appelle `PUT /users/{id}` avec le role choisi.
 *   B. Fermer un compte appelle `DELETE /users/{id}` — apres CONFIRMATION, et
 *      pas au premier clic.
 *   C. SON PROPRE COMPTE n'offre ni l'un ni l'autre. C'est la face qui compte :
 *      le serveur refuse deja ce geste, et un ecran qui le propose quand meme
 *      fabrique un bouton dont la reponse est toujours non — le motif D25-001
 *      sur lequel cet ecran s'est deja blesse.
 *
 * ⚠️ CE QU'ELLE NE MESURE PAS : les droits. L'interface ne consulte aucune
 * permission (constat D22-006, ouvert) ; la vraie garde est le middleware
 * `permission:users.manage`, et c'est lui qui protege.
 */

import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { UsersPage } from '@/features/users/UsersPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl } from '../msw/handlers';

const MOI = { id: 'u-moi', name: 'Moi Meme', email: 'moi@exemple.test' };
const AUTRE = { id: 'u-autre', name: 'Autre Personne', email: 'autre@exemple.test' };

/** `/auth/me` et `/users` peuples : deux comptes, dont le mien. */
function handlersDeBase() {
  return [
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ user: MOI })),
    http.get(apiUrl('/users'), () =>
      HttpResponse.json({
        data: [
          { ...MOI, roles: ['owner'] },
          { ...AUTRE, roles: ['viewer'] },
        ],
      }),
    ),
  ];
}

describe('X39-038 — régler et fermer un compte depuis la console', () => {
  it('mon PROPRE compte n offre ni changement de rôle ni fermeture', async () => {
    await renderScreen(<UsersPage />, { path: '/users', handlers: handlersDeBase() });

    await screen.findByText(AUTRE.name);

    // Un seul selecteur de role et un seul bouton « Fermer » : ceux de l'AUTRE.
    expect(screen.getAllByLabelText(/^Rôle de /)).toHaveLength(1);
    expect(screen.getByLabelText(`Rôle de ${AUTRE.name}`)).toBeInTheDocument();
    expect(screen.queryByLabelText(`Rôle de ${MOI.name}`)).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^Fermer$/ })).toHaveLength(1);
  });

  it('changer le rôle appelle PUT /users/{id} avec le rôle choisi', async () => {
    const recu: { corps: unknown; url: string }[] = [];

    await renderScreen(<UsersPage />, {
      path: '/users',
      handlers: [
        ...handlersDeBase(),
        http.put(apiUrl(`/users/${AUTRE.id}`), async ({ request }) => {
          recu.push({ corps: await request.json(), url: request.url });

          return HttpResponse.json({ data: { ...AUTRE, roles: ['operator'] } });
        }),
      ],
    });

    const selecteur = await screen.findByLabelText(`Rôle de ${AUTRE.name}`);
    await userEvent.selectOptions(selecteur, 'operator');

    await waitFor(() => {
      expect(recu).toHaveLength(1);
    });
    expect(recu[0]?.corps).toEqual({ role: 'operator' });
  });

  it('fermer un compte demande CONFIRMATION, puis appelle DELETE /users/{id}', async () => {
    let appels = 0;

    await renderScreen(<UsersPage />, {
      path: '/users',
      handlers: [
        ...handlersDeBase(),
        http.delete(apiUrl(`/users/${AUTRE.id}`), () => {
          appels += 1;

          return HttpResponse.json({ closed: AUTRE.id });
        }),
      ],
    });

    await userEvent.click(await screen.findByRole('button', { name: /^Fermer$/ }));

    // Le premier clic n'a RIEN ferme : il ouvre une demande de confirmation.
    expect(appels).toBe(0);
    expect(await screen.findByText(/Fermer ce compte \?/i)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Fermer le compte/i }));

    await waitFor(() => {
      expect(appels).toBe(1);
    });
  });
});
