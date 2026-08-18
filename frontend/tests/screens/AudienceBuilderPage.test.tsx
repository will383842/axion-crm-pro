/**
 * ÉCRAN `/audiences/new` — `src/features/audiences/AudienceBuilderPage.tsx`.
 *
 * Famille : FORMULAIRE COMPLEXE — react-hook-form, huit familles de critères,
 * aperçu en direct anti-rebond (500 ms), création puis redirection.
 *
 * Le cœur de l'écran n'est pas visuel : c'est la TRADUCTION des gestes en
 * `criteria` (`{ field, op, value }`). Une erreur de `field` ou d'`op` produit
 * un segment qui a l'air correct et ne cible pas les bonnes entreprises. On
 * assure donc le corps RÉELLEMENT posté, pas seulement l'écran.
 *
 * ⚠️ ANTI-REBOND DE 500 ms — piège coûteux, réglé ici une fois pour toutes.
 * Le réflexe (`vi.useFakeTimers()` + `userEvent.setup({ advanceTimers })`) FAIT
 * EXPIRER les quatre parcours : MSW résout ses requêtes sur des minuteurs
 * réels, que les faux minuteurs gèlent — la réponse n'arrive jamais. On laisse
 * donc le temps s'écouler pour de vrai et on attend avec `waitFor({ timeout })`.
 * Coût mesuré : ~1 s par parcours. À copier tel quel pour tout écran anti-rebond.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { AudienceBuilderPage } from '@/features/audiences/AudienceBuilderPage';
import { renderScreen } from '../helpers/renderScreen';
import { postStatus, recordPost } from '../msw/handlers';

const PATH = '/audiences/new';
const LANDING = [{ path: '/audiences' }, { path: '/audiences/$audienceId' }];

/** Marge d'attente couvrant l'anti-rebond de 500 ms de l'aperçu. */
const DEBOUNCE = { timeout: 3000 } as const;

interface Critere {
  field: string;
  op: string;
  value: unknown;
}
interface Corps {
  name: string;
  description?: string;
  criteria: { all: Critere[] };
  is_active: boolean;
  auto_refresh: boolean;
}

/**
 * La puce d'un préréglage, désignée par son CONTENU et non par son nom
 * accessible.
 *
 * 🔴 DÉFAUT D'ACCESSIBILITÉ CONSTATÉ (non corrigé ici : `src/**` est hors du
 * périmètre de ce lot). `Field` (`AudienceBuilderPage.tsx:495`) enveloppe ses
 * enfants dans un `<label>`. Le PREMIER élément interactif d'un `<label>` en
 * devient le contrôle étiqueté : la première puce de chaque groupe hérite donc
 * du nom accessible du groupe ENTIER —
 *   « Départements Aucun département 92 Hauts-de-Seine 93 Seine-Saint-Denis … »
 * au lieu de « 75 Paris ». Mesuré via `computeAccessibleName` :
 *   puce 1 → le groupe entier · puces 2..n → leur propre libellé.
 * Un lecteur d'écran énonce toute la liste en arrivant sur la première puce.
 * Cela touche les CINQ groupes (Départements, Régions, Tailles, Secteurs,
 * Statuts) et vaut aussi pour les autres écrans qui réutilisent `Field`.
 *
 * Conséquence de test : `getByRole('button', { name: /75 Paris/ })` ne trouve
 * RIEN. On interroge donc le contenu textuel, qui vaut « 75Paris » (aucune
 * espace : les deux `<span>` sont collés dans le JSX).
 */
function puce(code: string, label: string): HTMLElement {
  const cible = screen
    .getAllByRole('button')
    .find((b) => b.textContent === `${code}${label}`);
  if (cible === undefined) throw new Error(`Puce « ${code} ${label} » introuvable.`);
  return cible;
}

describe('AudienceBuilderPage — rendu', () => {
  it('affiche les cinq blocs de critères, et la création est VERROUILLÉE à l’arrivée', async () => {
    await renderScreen(<AudienceBuilderPage />, { path: PATH, landingRoutes: LANDING });

    expect(screen.getByRole('heading', { name: 'Nouvelle audience' })).toBeVisible();
    for (const titre of ['Informations', 'Géographie', 'Taille et secteur', 'Qualité et statut', 'Tags personnalisés']) {
      expect(screen.getByRole('heading', { name: titre })).toBeVisible();
    }

    // Sans nom, on ne peut pas créer — et l'écran DIT pourquoi.
    expect(screen.getByRole('button', { name: /Créer l'audience/ })).toBeDisabled();
    expect(
      screen.getByText('Renseigne un nom et au moins un critère pour activer la création.'),
    ).toBeVisible();
  });

  it('démarre avec le seul critère « prêt outreach », et l’affiche dans le récapitulatif', async () => {
    // Défaut produit : `statuses = ['ready_for_outreach']`. Un builder qui
    // démarrerait à vide enverrait la première campagne à toute la base.
    await renderScreen(<AudienceBuilderPage />, { path: PATH, landingRoutes: LANDING });

    expect(screen.getByText('Critères (1)')).toBeVisible();
    expect(screen.getByText('prospection_status')).toBeVisible();
  });
});

describe('AudienceBuilderPage — parcours', () => {
  it('cliquer un département et cocher « a un email » construit les CRITÈRES envoyés à l’aperçu', async () => {
    const user = userEvent.setup();
    const apercu = recordPost<{ criteria: { all: Critere[] } }>('/audiences/preview', {
      companies: 1234,
      contacts: 987,
    });

    await renderScreen(<AudienceBuilderPage />, {
      path: PATH,
      landingRoutes: LANDING,
      handlers: [apercu.handler],
    });

    await user.click(puce('75', 'Paris'));
    await user.click(screen.getByRole('checkbox', { name: /A au moins un contact avec email/ }));

    // L'anti-rebond fait son travail : rien n'est parti immédiatement.
    expect(apercu.bodies).toHaveLength(0);

    await waitFor(() => {
      expect(apercu.bodies.length).toBeGreaterThan(0);
    }, DEBOUNCE);

    const dernier = apercu.bodies[apercu.bodies.length - 1];
    expect(dernier?.criteria.all).toEqual(
      expect.arrayContaining([
        { field: 'department_code', op: 'in', value: ['75'] },
        { field: 'has_email', op: 'eq', value: true },
        { field: 'prospection_status', op: 'in', value: ['ready_for_outreach'] },
      ]),
    );

    // Et le nombre revenu s'affiche, formaté en français (espace insécable).
    expect(await screen.findByText(/1.234/)).toBeVisible();
    expect(screen.getByText('987')).toBeVisible();
  });

  it('re-cliquer un département le RETIRE — la puce est une bascule, pas un ajout', async () => {
    const user = userEvent.setup();
    const apercu = recordPost<{ criteria: { all: Critere[] } }>('/audiences/preview', {
      companies: 0,
      contacts: 0,
    });

    await renderScreen(<AudienceBuilderPage />, {
      path: PATH,
      landingRoutes: LANDING,
      handlers: [apercu.handler],
    });

    await user.click(puce('75', 'Paris'));
    await waitFor(() => expect(apercu.bodies.length).toBeGreaterThan(0), DEBOUNCE);
    expect(screen.getByText('Critères (2)')).toBeVisible();

    await user.click(puce('75', 'Paris'));

    await waitFor(() => {
      const dernier = apercu.bodies[apercu.bodies.length - 1];
      expect(dernier?.criteria.all.some((c) => c.field === 'department_code')).toBe(false);
    }, DEBOUNCE);
    expect(screen.getByText('Critères (1)')).toBeVisible();
  });

  it('nommer puis créer POSTe l’audience et ATTERRIT sur sa fiche', async () => {
    const user = userEvent.setup();
    const apercu = recordPost('/audiences/preview', { companies: 10, contacts: 4 });
    const creation = recordPost<Corps>('/audiences', { data: { id: 77 } });

    const view = await renderScreen(<AudienceBuilderPage />, {
      path: PATH,
      landingRoutes: LANDING,
      handlers: [apercu.handler, creation.handler],
    });

    const bouton = screen.getByRole('button', { name: /Créer l'audience/ });
    expect(bouton).toBeDisabled();

    await user.type(screen.getByPlaceholderText(/PME Île-de-France IT/), 'PME IDF — prêtes outreach');
    await user.click(puce('69', 'Rhône'));

    // Le verrou se lève dès qu'il y a un nom ET un critère.
    await waitFor(() => expect(bouton).toBeEnabled(), DEBOUNCE);

    await user.click(bouton);

    await waitFor(() => expect(creation.bodies).toHaveLength(1), DEBOUNCE);
    const corps = creation.bodies[0];
    expect(corps?.name).toBe('PME IDF — prêtes outreach');
    expect(corps?.is_active).toBe(true);
    expect(corps?.auto_refresh).toBe(false);
    expect(corps?.criteria.all).toEqual(
      expect.arrayContaining([{ field: 'department_code', op: 'in', value: ['69'] }]),
    );

    // On atterrit sur la fiche de l'audience CRÉÉE — id 77, celui du serveur.
    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/audiences/77');
    });
  });

  it('aperçu en échec : l’écran affiche le message du serveur, jamais un compte faux', async () => {
    // Le pire défaut possible ici serait d'afficher le dernier compte connu
    // après une erreur : on lancerait une campagne sur un volume imaginaire.
    const user = userEvent.setup();

    await renderScreen(<AudienceBuilderPage />, {
      path: PATH,
      landingRoutes: LANDING,
      handlers: [postStatus('/audiences/preview', 422, { message: 'Critère `region_code` inconnu.' })],
    });

    await user.click(puce('75', 'Paris'));

    expect(await screen.findByText('Critère `region_code` inconnu.', undefined, DEBOUNCE)).toBeVisible();
    expect(screen.queryByText('Entreprises')).not.toBeInTheDocument();
  });

  it('« Annuler » revient à la liste des audiences', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<AudienceBuilderPage />, { path: PATH, landingRoutes: LANDING });

    await user.click(screen.getByRole('button', { name: 'Annuler' }));

    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/audiences');
    });
  });
});
