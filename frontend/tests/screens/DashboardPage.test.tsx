/**
 * ÉCRAN `/` — `src/features/dashboard/DashboardPage.tsx`.
 *
 * Famille : TABLEAU DE BORD — beaucoup d'appels concurrents (`/auth/me`,
 * `/dashboard/stats`, `/coverage`, `/audit-logs`), période commutable,
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
    expect(await screen.findByText('Bonjour Will 👋')).toBeVisible();

    // La période affichée est celle que le SERVEUR renvoie (`period_label`),
    // pas celle que l'écran suppose.
    expect(screen.getByText("Vue d'ensemble · derniers 30 jours")).toBeVisible();

    // La qualité moyenne est CALCULÉE : (100×100 + 60×60 + 40×25) / 200 = 73.
    expect(vignette('Qualité moyenne')).toHaveTextContent('73/100');

    // Les cartes filles ont bien reçu leurs propres réponses.
    expect(await screen.findByText('Isère')).toBeVisible();
    // L'action est humanisée et collée à l'auteur (« Will · Company Enriched ») :
    // on interroge donc un fragment, pas un nœud entier.
    expect(await screen.findByText(/Company Enriched/)).toBeVisible();
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
      screen.queryByText('Lance ton premier scrape'),
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

  it('base vide : invite à lancer un premier scrape, sans afficher d’indicateurs faux', async () => {
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

    expect(await screen.findByText('Lance ton premier scrape')).toBeVisible();
    expect(screen.queryByText('Total entreprises')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Démarrer sur \/coverage/ })).toHaveAttribute(
      'href',
      '/coverage',
    );
  });

  it('une carte fille en échec n’emporte PAS le tableau de bord', async () => {
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
    expect(await screen.findByText('Aucun département couvert')).toBeVisible();
    expect(screen.queryByText('Isère')).not.toBeInTheDocument();
  });
});

describe('DashboardPage — parcours', () => {
  /**
   * 🔴 DÉFAUT DE PRODUIT MESURÉ ICI — le sélecteur de période NE FAIT RIEN.
   *
   * `DashboardPage.tsx:78` déclare `queryKey: ['dashboard-stats']` SANS
   * `period`. Changer de période met l'état React à jour, mais la clé de cache
   * ne bouge pas : React Query sert la réponse déjà en cache, aucune requête ne
   * part, et les chiffres restent ceux de 30 jours.
   *
   * Mesuré : après un clic sur « 7j », `urls` vaut toujours
   *   ["https://api.localhost/api/v1/dashboard/stats?period=30d"]
   * et le sous-titre reste « Vue d'ensemble · derniers 30 jours ».
   *
   * Le correctif tient en un mot — `queryKey: ['dashboard-stats', period]` —
   * mais il touche `src/**`, hors du périmètre de ce lot. Ce test CONSIGNE le
   * comportement actuel pour qu'il ne passe plus inaperçu : le jour où
   * quelqu'un corrige la clé, il ROUGIT, et il n'y aura qu'à inverser les deux
   * assertions ci-dessous (elles sont écrites pour ça).
   */
  it('🔴 changer de période ne déclenche AUCUNE requête (clé de cache sans `period`)', async () => {
    const user = userEvent.setup();
    const { handler, urls } = recordGet('/dashboard/stats', STATS);

    await renderScreen(<DashboardPage />, { path: PATH, handlers: [handler, ...socle()] });

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
    expect(urls).toHaveLength(1);
    expect(new URL(urls[0] as string).searchParams.get('period')).toBe('30d');

    await user.click(screen.getByRole('tab', { name: '7j' }));
    // On laisse au réseau le temps de partir — s'il devait partir.
    await new Promise((resolve) => { setTimeout(resolve, 300); });

    // ATTENDU LE JOUR DU CORRECTIF : une 2e requête avec `period=7d`.
    // CONSTATÉ AUJOURD'HUI : aucune requête, la période cliquée est ignorée.
    expect(urls).toHaveLength(1);
  });

  it('🔴 sans `period_label` du serveur, l’écran AFFIRME une période qu’il n’a pas chargée', async () => {
    // Conséquence du défaut ci-dessus, et la plus grave : le sous-titre retombe
    // sur `PERIOD_LABEL[period]`, qui suit l'état React. L'écran annonce donc
    // « derniers 7 jours » au-dessus de chiffres calculés sur 30 jours.
    const user = userEvent.setup();
    const sansLabel = { ...STATS } as Record<string, unknown>;
    delete sansLabel['period_label'];

    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', sansLabel), ...socle()],
    });

    await waitFor(() => {
      expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
    });
    expect(screen.getByText("Vue d'ensemble · derniers 30 jours")).toBeVisible();

    await user.click(screen.getByRole('tab', { name: '7j' }));

    // Le libellé change… sans que la moindre donnée ait été rechargée.
    expect(await screen.findByText("Vue d'ensemble · derniers 7 jours")).toBeVisible();
    expect(vignette('Total entreprises')).toHaveTextContent(/4.294.898/);
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
