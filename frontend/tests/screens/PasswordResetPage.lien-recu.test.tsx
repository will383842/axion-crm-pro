/**
 * GARDE X39-037 — LE LIEN DE DEFINITION DE MOT DE PASSE DOIT MENER QUELQUE PART.
 *
 * ── CE QUI ETAIT MESURE, LE 2026-08-24 ────────────────────────────────────
 *
 * Le courriel de reinitialisation — et celui d'invitation, cable le meme jour —
 * pointe vers `/password-reset?token=…&email=…`. La page ignorait ces deux
 * parametres : elle reaffichait le formulaire de DEMANDE et redemandait
 * l'adresse. Cliquer le lien ramenait a son point de depart.
 *
 *     grep -rn "password/reset" frontend/src  →  AUCUN resultat
 *
 * `POST /auth/password/reset` existait cote serveur, complet et garde — et
 * PERSONNE ne l'appelait. Il n'y avait aucun ecran pour choisir un mot de passe.
 *
 * La consequence depassait la gene : un compte cree par « Inviter un
 * utilisateur » naît SANS mot de passe, par conception. Sans cet ecran il ne
 * pouvait JAMAIS se connecter — le produit etait structurellement
 * mono-utilisateur, quoi qu'affiche son ecran d'administration.
 *
 * ── CE QUE CETTE GARDE MESURE ─────────────────────────────────────────────
 *
 *   A. URL NUE  → le formulaire de DEMANDE (comportement d'origine, preserve).
 *   B. URL AVEC jeton + adresse → le formulaire de DEFINITION, et l'adresse est
 *      RAPPELEE au lieu d'etre redemandee. C'est la face qui aurait rougi sur
 *      le defaut.
 *   C. L'envoi atteint `POST /auth/password/reset` avec les QUATRE champs que
 *      le controleur valide, `password_confirmation` compris — un corps
 *      incomplet repartirait en 422 sans que l'ecran sache l'expliquer.
 *
 * ⚠️ CE QU'ELLE NE MESURE PAS : que le mot de passe soit accepte. Le controleur
 * impose 12 caracteres et un controle HIBP ; c'est son affaire, et
 * `PasswordResetTest` cote backend s'en charge.
 */

import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PasswordResetPage } from '@/features/auth/PasswordResetPage';
import { renderScreen } from '../helpers/renderScreen';
import { recordPost } from '../msw/handlers';

/** Un jeton de la longueur EXACTE qu'exige le controleur (`size:64`). */
const JETON = 'a'.repeat(64);
const ADRESSE = 'recrue@exemple.test';

describe('X39-037 — le lien recu par courriel mene au choix du mot de passe', () => {
  it('TEMOIN — sans parametres, l ecran reste celui de la DEMANDE', async () => {
    await renderScreen(<PasswordResetPage />, { path: '/password-reset', outsideLayout: true });

    // Le champ « Email » de la demande est present…
    expect(await screen.findByPlaceholderText(/prenom\.nom@exemple\.com/i)).toBeInTheDocument();
    // …et celui du choix de mot de passe, non.
    expect(screen.queryByText(/Nouveau mot de passe/i)).not.toBeInTheDocument();
  });

  it('avec jeton et adresse, propose de CHOISIR un mot de passe et rappelle l adresse', async () => {
    await renderScreen(<PasswordResetPage />, {
      path: '/password-reset',
      url: `/password-reset?token=${JETON}&email=${encodeURIComponent(ADRESSE)}`,
      outsideLayout: true,
    });

    expect(await screen.findByText(/Nouveau mot de passe/i)).toBeInTheDocument();
    expect(screen.getByText(/Confirmation/i)).toBeInTheDocument();

    // L'adresse est RAPPELEE, jamais redemandee : c'est tout l'objet du correctif.
    expect(screen.getByText(new RegExp(ADRESSE, 'i'))).toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/prenom\.nom@exemple\.com/i)).not.toBeInTheDocument();
  });

  it('poste vers /auth/password/reset avec les quatre champs attendus', async () => {
    const recu = recordPost<Record<string, string>>('/auth/password/reset', { reset: true });

    await renderScreen(<PasswordResetPage />, {
      path: '/password-reset',
      url: `/password-reset?token=${JETON}&email=${encodeURIComponent(ADRESSE)}`,
      outsideLayout: true,
      handlers: [recu.handler],
    });

    const secret = 'MotDePasseAssezLong2026!';
    const champs = await screen.findAllByPlaceholderText(/caractères minimum|Retapez le même/i);
    // Deux champs EXACTEMENT : le mot de passe et sa confirmation. L'assertion
    // ferme aussi le `| undefined` que `tsc` refuse sur un acces indexe.
    expect(champs).toHaveLength(2);
    const [champMotDePasse, champConfirmation] = champs as [HTMLElement, HTMLElement];
    await userEvent.type(champMotDePasse, secret);
    await userEvent.type(champConfirmation, secret);

    await userEvent.click(screen.getByRole('button', { name: /Enregistrer le mot de passe/i }));

    await waitFor(() => {
      expect(recu.bodies.at(-1)).toEqual({
        email: ADRESSE,
        token: JETON,
        password: secret,
        // `confirmed` cote serveur exige EXACTEMENT ce nom de champ.
        password_confirmation: secret,
      });
    });
  });
});
