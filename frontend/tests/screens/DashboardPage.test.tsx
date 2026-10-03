/**
 * ÉCRAN `/` — `src/features/dashboard/DashboardPage.tsx`.
 *
 * NOUVEL ACCUEIL EN BLOCS (maquette validée par Will, 03/10/2026) : la date et
 * « Bonjour <prénom> », « À faire », « Ma base » (quatre tuiles), « Par taille »
 * et « Mes audiences ». Le fil « Activité récente », « Prochaines étapes » et
 * « Enrichies 24h » ont quitté l'accueil.
 *
 * Requêtes : `/auth/me`, `/config/features`, `/dashboard/stats`, `/audiences`
 * et — console ouverte — `/crm/a-traiter/compteurs`. `onUnhandledRequest:
 * 'error'` (tests/setup.ts) fait rougir un test qui en oublierait une.
 *
 * D25-008 — le premier écran ne doit jamais être une grille de zéros : tant
 * que `/dashboard/stats` n'a pas répondu, l'écran montre le SQUELETTE. La garde
 * rougit si quelqu'un remet un `placeholderData` dans la requête.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';

import { DashboardPage, dateDuJour, nombreCourt } from '@/features/dashboard/DashboardPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, getPending, getStatus, recordGet } from '../msw/handlers';

const PATH = '/';
const INDISPONIBLE = 'Chiffre indisponible pour le moment';

const STATS = {
  companies_total: 4_350_000,
  companies_enriched_24h: 1_204,
  contacts_qualified: 88_120,
  scraper_runs_24h: 12,
  llm_cost_eur_month: 41.5,
  quality_distribution: { complete: 100, partielle: 60, basique: 40 },
  quality_avg: 18,
  quality_a_recalculer_pct: 2,
  size_distribution: { tpe: 930, pme: 50, eti: 15, grand_groupe: 5 },
  companies_enriched: 826_500,
  prospects_joignables: 410_515,
  prospects_joignables_idf: 141_769,
  period_label: '30 derniers jours',
};

function audience(id: number, name: string, member_count: number, refreshed_at: string | null = '2026-10-03T02:00:00Z') {
  return {
    id,
    name,
    description: null,
    criteria: {},
    is_active: true,
    auto_refresh: true,
    member_count,
    refreshed_at,
    created_at: '2026-10-01T00:00:00Z',
  };
}

const AUDIENCES = {
  data: [
    audience(1, 'Prospects contactables', 410_515),
    audience(2, 'Île-de-France', 141_769),
    audience(3, 'E-mail de confiance A', 277_945),
    audience(4, 'Quatrième audience', 12),
  ],
};

/** Les requêtes des blocs enfants, invisibles depuis `DashboardPage.tsx`. */
function socle() {
  return [getJson('/audiences', AUDIENCES)];
}

function tuile(id: string): HTMLElement {
  return screen.getByTestId(`tuile-${id}`);
}

describe('DashboardPage — rendu des blocs', () => {
  it('en-tête : la date du jour en français et « Bonjour <prénom> »', async () => {
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [getJson('/dashboard/stats', STATS), ...socle()] });

    // Le prénom vient de `/auth/me` (handler par défaut : « Will Test »).
    expect(await screen.findByRole('heading', { level: 1, name: 'Bonjour Will' })).toBeVisible();
    expect(screen.getByText(new RegExp(dateDuJour()))).toBeVisible();
    // Le mot du menu et du fil d'Ariane reste sur la page.
    expect(screen.getByText('Tableau de bord')).toBeVisible();
  });

  it('dateDuJour : « Samedi 3 octobre » ; nombreCourt : « 4,35 M »', () => {
    expect(dateDuJour(new Date(2026, 9, 3, 9, 0))).toBe('Samedi 3 octobre');
    expect(nombreCourt(4_350_000)).toBe(`4,35${String.fromCharCode(0xa0)}M`);
    expect(nombreCourt(410_515)).toMatch(/^410.515$/);
  });

  it('« Ma base » : quatre tuiles calculées depuis /dashboard/stats', async () => {
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [getJson('/dashboard/stats', STATS), ...socle()] });

    expect(await screen.findByRole('heading', { name: 'Ma base' })).toBeVisible();

    // Entreprises : format court à l'écran, nombre exact pour les lecteurs d'écran.
    expect(tuile('entreprises')).toHaveTextContent(/4,35\sM/);
    expect(tuile('entreprises')).toHaveTextContent(/4.350.000 entreprises/);
    // 930 TPE sur 1 000 fiches classées : 93 %.
    expect(tuile('entreprises')).toHaveTextContent('93 % de TPE');

    expect(tuile('joignables')).toHaveTextContent(/410.515/);
    expect(tuile('joignables')).toHaveTextContent(/dont 141.769 en Île-de-France/);

    // 826 500 / 4 350 000 = 19 %.
    expect(tuile('enrichies')).toHaveTextContent('19 %');
    expect(tuile('qualite')).toHaveTextContent('18 / 100');
  });

  it('« Par taille » et « Mes audiences » : barres, trois premières audiences, liens', async () => {
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [getJson('/dashboard/stats', STATS), ...socle()] });

    expect(await screen.findByRole('heading', { name: 'Par taille' })).toBeVisible();

    const bloc = (await screen.findByRole('heading', { name: 'Mes audiences' })).closest('.rounded-2xl') as HTMLElement;
    const liste = await within(bloc).findByRole('list');
    const liens = within(liste).getAllByRole('link');
    expect(liens).toHaveLength(3);
    expect(liens[0]).toHaveAttribute('href', '/audiences/1');
    expect(liens[0]).toHaveAccessibleName(/Prospects contactables : 410.515 membres/);
    expect(within(bloc).queryByText('Quatrième audience')).not.toBeInTheDocument();
    expect(within(bloc).getByRole('link', { name: 'Tout voir' })).toHaveAttribute('href', '/audiences');
    expect(within(bloc).getByText('Mises à jour chaque nuit')).toBeVisible();
  });

  it('ce qui a QUITTÉ l’accueil : activité récente, prochaines étapes, « Enrichies 24h »', async () => {
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [getJson('/dashboard/stats', STATS), ...socle()] });

    await screen.findByRole('heading', { name: 'Ma base' });
    expect(screen.queryByText(/Activité récente/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Prochaine/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Enrichies 24h/)).not.toBeInTheDocument();
  });

  it('dit l’âge des chiffres servis depuis le cache (`computed_at`)', async () => {
    const ilYA7Min = new Date(Date.now() - 7 * 60_000 - 5_000).toISOString();
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, computed_at: ilYA7Min }), ...socle()],
    });

    expect(await screen.findByText(/Chiffres mis à jour il y a 7 min/)).toBeVisible();
  });

  it('D25-008 — tant que /dashboard/stats n’a pas répondu, l’écran montre le SQUELETTE, pas des zéros', async () => {
    const { handler, release } = getPending('/dashboard/stats', STATS);
    const vue = await renderScreen(<DashboardPage />, { path: PATH, handlers: [handler, ...socle()] });

    await waitFor(() => {
      expect(
        vue.container.querySelectorAll('.animate-pulse').length,
        'D25-008 : aucun `.animate-pulse` pendant que `/dashboard/stats` est en vol. ' +
          'Cause connue : un `placeholderData` dans le `useQuery` de DashboardPage.tsx. ' +
          'GESTE : le retirer.',
      ).toBeGreaterThan(0);
    });
    expect(screen.queryByTestId('tuile-entreprises')).not.toBeInTheDocument();
    expect(screen.queryByText('Votre base est vide')).not.toBeInTheDocument();

    // Témoin : la réponse arrivée, le squelette cède la place aux vrais chiffres.
    release();
    await waitFor(() => {
      expect(tuile('entreprises')).toHaveTextContent(/4,35\sM/);
    });
  });

  it('base vide : invite à récupérer des entreprises, sans afficher de tuiles', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, companies_total: 0 }), ...socle()],
    });

    expect(await screen.findByText('Votre base est vide')).toBeVisible();
    expect(screen.queryByTestId('tuile-entreprises')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Récupérer des entreprises' })).toHaveAttribute('href', '/coverage');
  });

  it('P0-1 — /dashboard/stats en panne : état d’erreur, JAMAIS « base vide »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getStatus('/dashboard/stats', 500), ...socle()],
    });

    expect(await screen.findByText('Le serveur est en panne')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeVisible();
    expect(screen.queryByText('Votre base est vide')).not.toBeInTheDocument();
    expect(screen.queryByTestId('tuile-entreprises')).not.toBeInTheDocument();
  });

  it('aucune `period` envoyée au serveur (aucun chiffre n’en dépend)', async () => {
    const { handler, urls } = recordGet('/dashboard/stats', STATS);
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [handler, ...socle()] });

    await screen.findByTestId('tuile-entreprises');
    expect(new URL(urls[0] as string).searchParams.has('period')).toBe(false);
  });
});

describe('DashboardPage — jamais un 0 trompeur', () => {
  it('compteurs null : « — » et « Chiffre indisponible pour le moment », jamais 0', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        getJson('/dashboard/stats', {
          ...STATS,
          companies_total: null,
          companies_enriched: null,
          prospects_joignables: null,
          prospects_joignables_idf: null,
        }),
        ...socle(),
      ],
    });

    await screen.findByTestId('tuile-entreprises');
    // Un null n'est PAS une base vide.
    expect(screen.queryByText('Votre base est vide')).not.toBeInTheDocument();
    for (const id of ['entreprises', 'joignables', 'enrichies']) {
      expect(tuile(id)).toHaveTextContent('—');
      expect(tuile(id)).toHaveTextContent(INDISPONIBLE);
      expect(tuile(id)).not.toHaveTextContent(/\b0\b/);
    }
    expect(tuile('joignables')).not.toHaveTextContent(/Île-de-France/);
    // Témoin : la qualité connue reste affichée.
    expect(tuile('qualite')).toHaveTextContent('18 / 100');
  });

  it('Île-de-France inconnue : la sous-ligne disparaît, le total joignable reste', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, prospects_joignables_idf: null }), ...socle()],
    });

    expect(await screen.findByTestId('tuile-joignables')).toHaveTextContent(/410.515/);
    expect(tuile('joignables')).not.toHaveTextContent(/Île-de-France/);
  });

  it('scores majoritairement périmés : « — » et « calcul en attente », pas de moyenne', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, quality_avg: 10, quality_a_recalculer_pct: 79 }), ...socle()],
    });

    await screen.findByTestId('tuile-qualite');
    expect(tuile('qualite')).toHaveTextContent('—');
    expect(tuile('qualite')).toHaveTextContent(/Calcul en attente \(≈ 79 %/);
    expect(tuile('qualite')).not.toHaveTextContent('10 / 100');
  });

  it('serveur sans moyenne (null) : « — », jamais 0 / 100', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, quality_avg: null, quality_a_recalculer_pct: null }), ...socle()],
    });

    await screen.findByTestId('tuile-qualite');
    expect(tuile('qualite')).toHaveTextContent('—');
    expect(tuile('qualite')).toHaveTextContent(INDISPONIBLE);
    expect(tuile('qualite')).not.toHaveTextContent('0 / 100');
  });

  it('répartition par taille indisponible (null) : ni barres, ni « % de TPE »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', { ...STATS, size_distribution: null }), ...socle()],
    });

    expect(await screen.findByTestId('tailles-indisponibles')).toHaveTextContent(INDISPONIBLE);
    expect(tuile('entreprises')).not.toHaveTextContent(/de TPE/);
    // Témoin : le total reste affiché.
    expect(tuile('entreprises')).toHaveTextContent(/4,35\sM/);
  });

  it('audiences illisibles côté serveur (`degraded`) : « indisponible », jamais « aucune audience »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', STATS), getJson('/audiences', { data: [], degraded: true })],
    });

    expect(await screen.findByTestId('audiences-indisponibles')).toHaveTextContent(INDISPONIBLE);
    expect(screen.queryByText(/Aucune audience/)).not.toBeInTheDocument();
  });

  it('une audience jamais calculée montre « — », pas 0 membre', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [getJson('/dashboard/stats', STATS), getJson('/audiences', { data: [audience(9, 'Neuve', 0, null)] })],
    });

    const lien = await screen.findByRole('link', { name: 'Neuve : pas encore calculée' });
    expect(lien).toHaveTextContent('—');
    expect(lien).not.toHaveTextContent(/\b0\b/);
  });
});

describe('DashboardPage — « À faire »', () => {
  it('trois grandes cartes cliquables : nombres exacts, liens vers leurs écrans', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      consoleFeatures: 'open',
      handlers: [
        getJson('/dashboard/stats', STATS),
        getJson('/crm/a-traiter/compteurs', { doublons: 201, a_rattacher: 77, relances: 4 }),
        ...socle(),
      ],
    });

    expect(await screen.findByRole('heading', { name: 'À faire' })).toBeVisible();
    const doublons = await screen.findByRole('link', { name: /^201 doublons à vérifier/ });
    expect(doublons).toHaveAttribute('href', '/doublons');
    expect(doublons).toHaveTextContent('Ouvrir →');
    expect(screen.getByRole('link', { name: /^77 personnes à rattacher/ })).toHaveAttribute('href', '/console/arbitrage');
    expect(screen.getByRole('link', { name: /^4 événements à relancer/ })).toHaveAttribute(
      'href',
      '/evenements?onglet=relances',
    );
  });

  it('compteur null : « — » et « Chiffre indisponible pour le moment », jamais 0', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      consoleFeatures: 'open',
      handlers: [
        getJson('/dashboard/stats', STATS),
        getJson('/crm/a-traiter/compteurs', { doublons: null, a_rattacher: null, relances: null }),
        ...socle(),
      ],
    });

    const carte = await screen.findByRole('link', { name: /Doublons à vérifier : chiffre indisponible/ });
    expect(carte).toHaveTextContent('—');
    expect(carte).toHaveTextContent(INDISPONIBLE);
    expect(carte).not.toHaveTextContent(/\b0\b/);
    expect(screen.getByTestId('a-faire-relances')).toHaveTextContent(INDISPONIBLE);
  });

  it('compteurs en échec (aucune réponse) : « — » partout, pas de faux 0', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      consoleFeatures: 'open',
      handlers: [getJson('/dashboard/stats', STATS), getStatus('/crm/a-traiter/compteurs', 500), ...socle()],
    });

    await waitFor(() => {
      expect(screen.getByTestId('a-faire-doublons')).toHaveTextContent(INDISPONIBLE);
    });
    expect(screen.getByTestId('a-faire-a-rattacher')).toHaveTextContent('—');
  });

  it('serveur sans chiffre de relances : la carte « Événements à relancer » n’existe pas ; 0 se lit « À jour »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      consoleFeatures: 'open',
      handlers: [
        getJson('/dashboard/stats', STATS),
        getJson('/crm/a-traiter/compteurs', { doublons: 3, a_rattacher: 0 }),
        ...socle(),
      ],
    });

    await screen.findByRole('link', { name: /^3 doublons à vérifier/ });
    expect(screen.queryByTestId('a-faire-relances')).not.toBeInTheDocument();
    expect(screen.queryByText('Événements à relancer')).not.toBeInTheDocument();
    expect(screen.getByTestId('a-faire-a-rattacher')).toHaveTextContent('À jour');
  });

  it('console fermée : pas de bloc « À faire » (ses écrans et son point d’API n’existent pas)', async () => {
    await renderScreen(<DashboardPage />, { path: PATH, handlers: [getJson('/dashboard/stats', STATS), ...socle()] });

    await screen.findByTestId('tuile-entreprises');
    expect(screen.queryByRole('heading', { name: 'À faire' })).not.toBeInTheDocument();
  });
});

describe('DashboardPage — sans espace de travail (409)', () => {
  /** `GET /dashboard/stats` répond 409 avec ce code. */
  function conflit(code: string, message: string) {
    return http.get(apiUrl('/dashboard/stats'), () => HttpResponse.json({ error: code, message }, { status: 409 }));
  }

  it('409 no_workspace : un état clair, ni chiffres ni « base vide » ni « panne »', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [conflit('no_workspace', "Aucun espace de travail n'est rattaché à votre compte."), ...socle()],
    });

    expect(await screen.findByText('Aucun espace de travail')).toBeVisible();
    expect(
      screen.getByText('Aucun espace de travail n’est rattaché à votre compte. Contactez l’administrateur.'),
    ).toBeVisible();
    expect(screen.queryByTestId('tuile-entreprises')).not.toBeInTheDocument();
    expect(screen.queryByText('Votre base est vide')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).not.toBeInTheDocument();
  });

  it('409 workspace_not_selected : un message propre à ce cas', async () => {
    await renderScreen(<DashboardPage />, {
      path: PATH,
      handlers: [
        conflit(
          'workspace_not_selected',
          "Aucun espace de travail n'est sélectionné sur votre compte. Contactez l'administrateur pour qu'il en sélectionne un.",
        ),
        ...socle(),
      ],
    });

    expect(await screen.findByText('Aucun espace de travail sélectionné')).toBeVisible();
    expect(screen.getByText(/rattaché à un espace de travail, mais aucun n’est sélectionné/)).toBeVisible();
    expect(screen.queryByText(/n’est rattaché à votre compte/)).not.toBeInTheDocument();
    expect(screen.queryByTestId('tuile-entreprises')).not.toBeInTheDocument();
  });
});
