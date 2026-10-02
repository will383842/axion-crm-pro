/**
 * ÉCRAN `/` — `src/features/dashboard/DashboardPage.tsx`.
 *
 * Famille : TABLEAU DE BORD — beaucoup d'appels concurrents (`/auth/me`,
 * `/dashboard/stats`, `/coverage`, `/audit-logs`),
 * rafraîchissement.
 *
 * C'est l'écran où `onUnhandledRequest: 'error'` (tests/setup.ts) sert le
 * plus : les trois requêtes des cartes filles sont invisibles depuis le fichier
 * `DashboardPage.tsx`, et un mock de module ne les aurait pas fait remonter.
 * Ici, en oublier une fait rougir le test.
 *
 * ⚠️ NOTE PÉRIMÉE, CORRIGÉE LE 2026-08-22 (D25-008). Ce fichier disait jusqu'ici
 * que `placeholderData` — un OBJET de zéros — empêchait `DashboardSkeleton`
 * d'être rendu, et qu'« un test qui attendrait un squelette au démarrage
 * attendrait pour rien ». C'était exact, et c'était le défaut : le premier écran
 * du CRM était une grille de zéros. Le `placeholderData` a été retiré ; la garde
 * « D25-008 » ci-dessous attend désormais ce squelette, et rougit si quelqu'un
 * remet un objet de repli dans la requête.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { renderScreen } from '../helpers/renderScreen';
import { dynamicGet, getJson, getPending, getStatus, recordGet } from '../msw/handlers';

const PATH = '/';

const STATS = {
  companies_total: 4_294_898,
  companies_enriched_24h: 1_204,
  contacts_qualified: 88_120,
  scraper_runs_24h: 12,
  llm_cost_eur_month: 41.5,
  quality_distribution: { complete: 100, partielle: 60, basique: 40 },
  quality_avg: 62,
  quality_a_recalculer_pct: 2,
  size_distribution: { tpe: 900, pme: 300 },
  companies_new_7d: 5_400,
  period_label: 'derniers 30 jours',
};

const COUVERTURE = {
  cells: [
    { code: '38', name: 'Isère', total: 12_000, complete: 8_000, partial: 3_000 },
    { code: '69', name: 'Rhône', total: 9_000, complete: 5_000, partial: 2_000 },
  ],
};

const JOURNAL = {
  data: [
    {
      id: 'a1',
      action: 'company.enriched',
      actor_name: 'Will',
      actor_email: 'contact@axion-ia.com',
      resource_type: 'company',
      resource_id: '42',
      created_at: new Date().toISOString(),
    },
  ],
};

/** Les trois requêtes des cartes filles, invisibles depuis `DashboardPage.tsx`. */
function socle() {
  return [getJson('/coverage', COUVERTURE), getJson('/audit-logs', JOURNAL)];
}

/**
 * La carte (ou la vignette) qui PORTE ce titre.
 *
 * ⚠️ Deux pièges de cet écran, réglés ici pour tout le monde :
 *  - « 4 294 898 » apparaît DEUX fois (vignette « Total entreprises » et carte
 *    « Prochaines actions », qui reçoit `companiesTotal`). Une recherche par
 *    texte nu lève « found multiple elements » — un rouge qui n'accuse rien.
 *  - les cartes n'ont ni rôle ni `data-testid` : on remonte depuis leur titre
 *    jusqu'au premier ancêtre qui contient réellement le contenu cherché.
 */
function bloc(titre: string, contient: string): HTMLElement {
  let el: HTMLElement | null = screen.getByText(titre).parentElement;
  while (el !== null && el.querySelector(contient) === null) el = el.parentElement;
  if (el === null) throw new Error(`Aucun bloc « ${titre} » contenant « ${contient} ».`);
  return el;
}

/** La vignette KPI de ce libellé (racine `.rounded-2xl` de `KpiCard`). */
function vignette(label: string): HTMLElement {
  const carte = screen.getByText(label).closest('.rounded-2xl');
  if (carte === null) throw new Error(`Vignette « ${label} » introuvable.`);
  return carte as HTMLElement;
}

describe('DashboardPage — rendu', () => {
  it('affiche les quatre indicateurs, la couverture et l’activité récente', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', STATS), ...socle()],
    });

    expect(await screen.findByRole('heading', { name: 'Tableau de bord' })).toBeVisible();

    // Le total, formaté en français (espaces insécables) — 4 294 898, pas 4294898.
    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
    expect(vignette('Enrichies 24h')).toHaveTextContent(/1.204/);
    expect(vignette('Nouvelles 7j')).toHaveTextContent(/5.400/);

    // Le prénom vient de `/auth/me` (handler par défaut : « Will Test »).
    expect(await screen.findByText('Bonjour Will')).toBeVisible();

    // Plus de période affichée : aucun chiffre de l'écran n'en dépend.
    expect(screen.getByText("Vue d'ensemble de votre base")).toBeVisible();

    // Lot 3 — la moyenne vient du SERVEUR (moyenne réelle de quality_score),
    // plus d'une pondération 100 / 60 / 25 inventée à l'écran.
    expect(vignette('Qualité moyenne')).toHaveTextContent('62/100');

    // Les cartes filles ont bien reçu leurs propres réponses.
    expect(await screen.findByText('Isère')).toBeVisible();
    // Lot 3 — l'action est traduite en phrase (« Will · Fiche enrichie ») :
    // on interroge donc un fragment, pas un nœud entier.
    expect(await screen.findByText(/Fiche enrichie/)).toBeVisible();
  });

  /**
   * D25-008 — « ATTENDRE » ET « RIEN » NE DOIVENT PAS SE RESSEMBLER.
   *
   * Le défaut ne vivait QUE dans l'état transitoire : un test d'état stable ne
   * pouvait pas le voir. `getPending` fige donc `/dashboard/stats` en vol, et on
   * regarde ce que l'écran montre à cet instant précis — c'est le premier écran
   * réel de l'application, celui qu'un opérateur voit chaque matin.
   *
   * Cette garde rougit si quelqu'un remet un `placeholderData` (ou tout autre
   * repli qui met `isPending` à faux) : le squelette disparaîtrait, et les
   * libellés de vignettes apparaîtraient au-dessus de zéros inventés.
   */
  it('dit l’âge des chiffres servis depuis le cache (`computed_at`)', async () => {
    const ilYA7Min = new Date(Date.now() - 7 * 60_000 - 5_000).toISOString();
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, computed_at: ilYA7Min }), ...socle()],
    });

    expect(await screen.findByText(/chiffres mis à jour il y a 7 min/)).toBeVisible();
  });

  it('D25-008 — tant que /dashboard/stats n’a pas répondu, l’écran montre le SQUELETTE, pas des zéros', async () => {
    const { handler, release } = getPending('/dashboard/stats', STATS);
    const vue = await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [handler, ...socle()],
    });

    await waitFor(() => {
      expect(
        vue.container.querySelectorAll('.animate-pulse').length,
        'D25-008 : aucun `.animate-pulse` pendant que `/dashboard/stats` est en vol — ' +
          'donc `DashboardSkeleton` n’est pas rendu. Cause connue : un ' +
          '`placeholderData` dans le `useQuery` de DashboardPage.tsx met `isPending` ' +
          'à faux, et `isLoading` (= isPending && isFetching) ne vaut plus jamais vrai. ' +
          'GESTE : retirer `placeholderData` du `useQuery` ; le repli ' +
          '`const stats = data ?? {…}` suffit déjà à protéger le rendu.',
      ).toBeGreaterThan(0);
    });

    // Aucune des deux conclusions ne doit être affichée tant qu'on n'a rien reçu :
    // ni les chiffres (ils seraient faux), ni l'état vide (il serait mensonger).
    expect(
      screen.queryByText('Total entreprises'),
      'D25-008 : une vignette KPI est affichée avant la réponse du serveur — ' +
        'la valeur qu’elle porte ne vient d’aucune mesure. GESTE : retirer le repli ' +
        'qui court-circuite `isLoading` dans DashboardPage.tsx.',
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText('Votre base est vide'),
      'D25-008 : l’état vide (« aucune entreprise ») s’affiche alors que le serveur ' +
        'n’a pas répondu. GESTE : `isEmpty` ne doit se calculer qu’une fois `isLoading` ' +
        'retombé, donc `isLoading` doit exister.',
    ).not.toBeInTheDocument();

    // Témoin : la réponse arrivée, le squelette cède la place aux vrais chiffres.
    // Sans ce témoin, un écran bloqué en squelette passerait la garde ci-dessus.
    // ⚠️ On ne compte PAS les `.animate-pulse` restants ici : les cartes filles
    // (ActivityFeed, TopDeptsCard) montent leur propre squelette au même instant,
    // et la garde deviendrait une course. La présence de la vignette suffit :
    // elle n'existe QUE dans la branche « chargé » du rendu.
    release();
    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
  });

  it('base vide : invite à récupérer des entreprises, sans afficher d’indicateurs faux', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        getJson('/dashboard/stats', {
          ...STATS,
          companies_total: 0,
          companies_new_7d: 0,
          quality_distribution: { complete: 0, partielle: 0, basique: 0 },
        }),
        ...socle(),
      ],
    });

    expect(await screen.findByText('Votre base est vide')).toBeVisible();
    expect(screen.queryByText('Total entreprises')).not.toBeInTheDocument();
    // Un libellé humain, plus d'URL brute (« Démarrer sur /coverage → »).
    expect(screen.getByRole('link', { name: 'Récupérer des entreprises' })).toHaveAttribute(
      'href',
      '/coverage',
    );
    // Vouvoiement, sans jargon.
    expect(document.body.textContent).not.toMatch(/scrape|\/coverage|\bLance\b|\bChoisis\b/);
  });

  /**
   * P0-1 (audit UX du 02/10) — LE TABLEAU DE BORD MENTAIT : sous une panne de
   * `/dashboard/stats`, il affichait « Aucune entreprise collectée » sur une
   * base de 4,3 M de fiches. Une panne doit se DIRE, avec « Réessayer ».
   */
  it('P0-1 — /dashboard/stats en panne : état d’erreur, JAMAIS « base vide »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getStatus('/dashboard/stats', 500), ...socle()],
    });

    expect(await screen.findByText('Le serveur est en panne')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeVisible();
    expect(
      screen.queryByText('Votre base est vide'),
      'P0-1 : l’état « base vide » s’affiche alors que le serveur a ÉCHOUÉ. ' +
        'GESTE : dans DashboardPage.tsx, tester `error !== null && data === undefined` ' +
        'AVANT `isEmpty`, et rendre `QueryErrorState`.',
    ).not.toBeInTheDocument();
    expect(screen.queryByText('Total entreprises')).not.toBeInTheDocument();
  });

  it('sans `companies_new_7d` du serveur, « Nouvelles 7j » affiche « — » et n’invente rien', async () => {
    const sansNouvelles = { ...STATS } as Record<string, unknown>;
    delete sansNouvelles['companies_new_7d'];
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', sansNouvelles), ...socle()],
    });

    await waitFor(() => {
      expect(vignette('Nouvelles 7j')).toHaveTextContent('—');
    });
    // L'ancien repli `enrichies 24 h × 7` aurait affiché 8 428.
    expect(vignette('Nouvelles 7j')).not.toHaveTextContent(/8.428/);
  });

  it('une carte fille en échec n’emporte PAS le tableau de bord, et DIT son échec', async () => {
    // `/coverage` peut répondre 500 sans que les indicateurs principaux soient
    // faux : l'écran doit dégrader la carte, pas la page.
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', STATS), getStatus('/coverage', 500), getJson('/audit-logs', JOURNAL)],
    });

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
    expect(screen.getByText('Top 5 départements')).toBeVisible();
    // P0-3 — la carte montre l'erreur, pas « Aucun département couvert ».
    await screen.findByText('Le serveur est en panne');
    const carte = bloc('Top 5 départements', '[role="alert"]');
    expect(within(carte).getByText('Le serveur est en panne')).toBeVisible();
    expect(screen.queryByText('Aucun département couvert')).not.toBeInTheDocument();
    expect(screen.queryByText('Isère')).not.toBeInTheDocument();
  });
});

describe('DashboardPage — parcours', () => {
  /**
   * Le sélecteur 7j / 30j / 90j ne faisait RIEN (période absente de la clé de
   * cache, et ignorée par le serveur hormis pour un libellé) : il est retiré.
   * Cette garde rougit s'il revient sans agir — et aucune requête ne porte plus
   * de `period` que le serveur ignorerait.
   */
  it('aucun sélecteur de période factice, et aucune `period` envoyée', async () => {
    const { handler, urls } = recordGet('/dashboard/stats', STATS);

    await renderScreen(<DashboardPage />, { path: PATH, handlers: [handler, ...socle()] });

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
    expect(screen.queryByRole('tab', { name: '7j' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: '30j' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: '90j' })).not.toBeInTheDocument();
    expect(new URL(urls[0] as string).searchParams.has('period')).toBe(false);
  });

  it('« Actualiser » redemande les statistiques et l’écran reflète la NOUVELLE valeur', async () => {
    const user = userEvent.setup();
    // Handler « vivant » : la 2e réponse diffère de la 1re. C'est ce qui
    // distingue « l'écran a re-requêté » de « l'écran a RE-RENDU ».
    const { handler, urls } = dynamicGet('/dashboard/stats', (appel) => ({
      ...STATS,
      companies_total: appel === 1 ? 4_294_898 : 5_000_000,
    }));

    await renderScreen(<DashboardPage />, { path: PATH, handlers: [handler, ...socle()] });

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });

    await user.click(screen.getByRole('button', { name: /Actualiser/ }));

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/5.000.000/);
    });
    expect(urls.length).toBeGreaterThan(1);
  });

  it('la carte « Top 5 départements » classe par volume décroissant', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        getJson('/dashboard/stats', STATS),
        getJson('/coverage', {
          cells: [
            { code: '69', name: 'Rhône', total: 9_000 },
            { code: '38', name: 'Isère', total: 12_000 },
            { code: '75', name: 'Paris', total: 0 },
          ],
        }),
        getJson('/audit-logs', JOURNAL),
      ],
    });

    await screen.findByText('Isère');
    const carte = bloc('Top 5 départements', 'ul');
    const noms = within(carte)
      .getAllByRole('listitem')
      .map((li) => li.querySelector('[title]')?.textContent);

    // L'Isère (12 000) passe devant le Rhône (9 000) ; Paris (0) est ÉCARTÉ —
    // un département à zéro n'est pas un « top ».
    expect(noms).toEqual(['Isère', 'Rhône']);
  });
});

describe('DashboardPage — qualité (lot 3 : jamais un 0 trompeur)', () => {
  it('scores majoritairement périmés : « — » et « calcul en attente », ni moyenne ni barres', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        getJson('/dashboard/stats', {
          ...STATS,
          quality_distribution: { complete: 0, partielle: 273_281, basique: 4_072_988 },
          quality_avg: 10,
          quality_a_recalculer_pct: 79,
        }),
        ...socle(),
      ],
    });

    await waitFor(() => {
      expect(vignette('Qualité moyenne')).toHaveTextContent('—');
    });
    expect(vignette('Qualité moyenne')).toHaveTextContent(/Calcul en attente/);
    expect(vignette('Qualité moyenne')).not.toHaveTextContent('10/100');
    expect(screen.getByTestId('qualite-indisponible')).toHaveTextContent(/pas encore calculé/);
  });

  it('serveur sans moyenne (null) : « — », jamais 0/100', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        getJson('/dashboard/stats', { ...STATS, quality_avg: null, quality_a_recalculer_pct: null }),
        ...socle(),
      ],
    });

    await waitFor(() => {
      expect(vignette('Qualité moyenne')).toHaveTextContent('—');
    });
    expect(vignette('Qualité moyenne')).not.toHaveTextContent('0/100');
  });
});
