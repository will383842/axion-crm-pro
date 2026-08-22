/**
 * D28-012 — LE PLAN DE TITRES ET LES REPÈRES DE RÉGION.
 *
 * Deux mesures du 2026-08-22 :
 *  - la barre latérale émettait SIX `<h3>` (un par groupe) avant le `<h1>` de
 *    la page : le plan de titres du produit commençait au niveau 3 ;
 *  - `grep -c '<main|role="banner"|<nav'` rendait 0 sur les quatre écrans
 *    d'authentification et sur le 404, tous rendus HORS de la coquille — donc
 *    hors du `<main id="main">` de `RootLayout`.
 *
 * La réparation ne SUPPRIME pas l'information : retirer les six `<h3>` aurait
 * ôté six points d'ancrage à qui s'en servait. Chaque liste de groupe devient un
 * repère de région nommé. Ces gardes vérifient les deux moitiés — le titre qui
 * disparaît ET le repère qui le remplace.
 *
 * ⚠️ Le 404 est couvert ailleurs : `tests/components/ecran-introuvable.test.tsx`
 * le monte par le routeur réel, ce qui prouve en plus qu'il s'affiche.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children?: ReactNode; to: string }) => <a href={to}>{children}</a>,
  useNavigate: () => () => {},
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: '/' } }),
}));

vi.mock('@/lib/api', () => ({ api: { get: () => Promise.resolve({ data: {} }), post: () => Promise.resolve({ data: {} }) } }));

const { Sidebar } = await import('@/components/layout/Sidebar');
const { AuthShell } = await import('@/features/auth/LoginPage');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

function afficherBarre() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, {
    console_v2: true,
    universes: { business: true, vivier: false },
  });
  return render(
    <QueryClientProvider client={client}>
      <Sidebar collapsed={false} onToggleCollapse={() => {}} />
    </QueryClientProvider>,
  );
}

describe('D28-012 — la barre latérale', () => {
  it('n’émet plus aucun titre de niveau 3 devant le <h1> de la page', () => {
    const { container } = afficherBarre();
    const titres = container.querySelectorAll('h1, h2, h3, h4, h5, h6');

    expect(
      titres.length,
      `D28-012 : la barre latérale émet de nouveau ${titres.length} titre(s). ` +
        'Rendue avant le contenu, elle place ces titres DEVANT le `<h1>` de la ' +
        'page (`components/ui/PageHeader.tsx`) : le plan de titres du produit ' +
        'recommence alors au niveau 3. GESTE : remettre les en-têtes de groupe ' +
        'dans un `<div>` (cf. `NavSectionBlock` dans ' +
        '`src/components/layout/Sidebar.tsx`), et garder le `<nav aria-label>` ' +
        'qui porte désormais l’ancrage.',
    ).toBe(0);
  });

  it('offre un repère de région nommé par groupe, à la place des titres retirés', () => {
    afficherBarre();

    // Les six groupes de l'étape 0 (F17), énumérés d'après
    // `src/components/layout/Sidebar.tsx` : si l'un disparaît de la barre, cette
    // garde le dit au lieu de compter à l'aveugle.
    for (const groupe of ["Aujourd'hui", 'Contacts', 'Collecte', 'Pilotage', 'Conformité', 'Réglages']) {
      const repere = screen.queryByRole('navigation', { name: groupe });
      expect(
        repere !== null,
        `D28-012 : le groupe « ${groupe} » n’est plus un repère de région nommé. ` +
          'Retirer les `<h3>` sans les remplacer supprime six points d’ancrage ' +
          'pour qui navigue par titres ou par régions. GESTE : rétablir le ' +
          '`<nav aria-label={section.title}>` autour du `<ul>` dans ' +
          '`NavSectionBlock` (`src/components/layout/Sidebar.tsx`).',
      ).toBe(true);
    }
  });
});

describe('D28-012 — la coquille des écrans d’authentification', () => {
  it('rend son contenu dans un <main>', () => {
    const { container } = render(
      <AuthShell title="Connexion" description="Coquille partagée par /login, /2fa, /magic-link et /password-reset.">
        <p>contenu</p>
      </AuthShell>,
    );

    expect(
      container.querySelector('main') !== null,
      'D28-012 : `AuthShell` ne rend plus de `<main>`. Les quatre écrans ' +
        'd’authentification vivent hors de la coquille (`rootRoute`, jamais ' +
        '`layoutRoute`) : sans cette balise, ils n’offrent AUCUN repère de ' +
        'région, sur les seuls écrans que tout utilisateur traverse. GESTE : ' +
        'rétablir le `<main>` racine d’`AuthShell` dans ' +
        '`src/features/auth/LoginPage.tsx` — un seul geste couvre les quatre.',
    ).toBe(true);
  });
});
