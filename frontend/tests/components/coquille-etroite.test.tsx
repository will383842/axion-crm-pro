/**
 * LA COQUILLE SUR UN ÉCRAN ÉTROIT — D30-001 et D30-005.
 *
 * Deux défauts mesurés le 2026-08-22, tous deux dans `RootLayout` :
 *
 *  - D30-001 (S1) : `<main id="main">` portait `overflow-x-hidden`. Ce qui
 *    dépassait la largeur visible n'était pas décalé, il était PERDU — ni barre
 *    de défilement, ni geste pour l'atteindre. `TableScroll` ne couvre que les
 *    tableaux en grille ; tout le reste tombait dans ce trou.
 *  - D30-005 (S2) : les deux seuls `setMobileSidebarOpen(false)` étaient la
 *    croix du tiroir et le bouton de repli. Naviguer depuis le tiroir changeait
 *    d'écran SOUS un tiroir resté ouvert.
 *
 * ⚠️ CE QUE CES GARDES NE PROUVENT PAS : jsdom n'applique aucune feuille
 * Tailwind, ne calcule aucune largeur et ne défile pas. Elles inspectent donc
 * les CLASSES posées et le COMPORTEMENT de montage, jamais des pixels — la
 * mesure au pixel reste le travail des captures Playwright.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

let cheminCourant = '/';

vi.mock('@tanstack/react-router', () => ({
  Outlet: () => <div data-testid="ecran-courant" />,
  Link: ({ children, to }: { children?: ReactNode; to: string }) => <a href={to}>{children}</a>,
  // Present pour les composants du systeme de design charges par `@/components/ui`
  // (recherche globale, notamment) : ils l'appellent au rendu.
  useNavigate: () => () => {},
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: cheminCourant } }),
}));

vi.mock('@/lib/api', () => ({
  api: { get: () => Promise.resolve({ data: { user: { id: 'u1', current_workspace_id: null } } }) },
}));
vi.mock('@/lib/echo', () => ({ subscribeWorkspaceNotifications: () => () => {} }));

// L'en-tête réel n'apporte rien ici et traîne toute la recherche globale avec
// lui : on ne garde que le bouton qui ouvre le tiroir, seul point d'entrée du
// comportement mesuré.
vi.mock('@/components/layout/Header', () => ({
  Header: ({ onOpenMobileSidebar }: { onOpenMobileSidebar: () => void }) => (
    <button type="button" onClick={onOpenMobileSidebar}>
      Ouvrir le menu
    </button>
  ),
}));

vi.mock('@/components/layout/Sidebar', () => ({
  Sidebar: ({ pleineLargeur = false }: { pleineLargeur?: boolean }) => (
    <div data-testid="barre" data-pleine-largeur={String(pleineLargeur)} />
  ),
}));

vi.mock('@/components/OnboardingTour', () => ({ OnboardingTour: () => null }));

const { RootLayout } = await import('@/app/RootLayout');

function afficher(chemin = '/') {
  cheminCourant = chemin;
  // Le MÊME client au rendu et au re-rendu : en changer ferait remonter les
  // requêtes et brouillerait ce que le test observe. En revanche l'ÉLÉMENT est
  // reconstruit à chaque fois — repasser la même référence ferait court-circuiter
  // le re-rendu par React, et le test ne mesurerait rien.
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const arbre = () => (
    <QueryClientProvider client={client}>
      <RootLayout />
    </QueryClientProvider>
  );
  return { ...render(arbre()), arbre };
}

/** La barre rendue DANS le tiroir : c'est celle qui reçoit `pleineLargeur`. */
function barreDuTiroir(): HTMLElement | undefined {
  return screen
    .queryAllByTestId('barre')
    .find((n) => n.getAttribute('data-pleine-largeur') === 'true');
}

beforeEach(() => {
  cheminCourant = '/';
});

describe('D30-001 — le conteneur principal laisse défiler au lieu de rogner', () => {
  it('#main porte overflow-x-auto et plus overflow-x-hidden', () => {
    const { container } = afficher();
    const principal = container.querySelector('main#main');
    expect(principal).not.toBeNull();
    const classes = (principal as HTMLElement).className;

    expect(
      classes.includes('overflow-x-hidden'),
      'D30-001 : `<main id="main">` a repris `overflow-x-hidden` dans ' +
        '`src/app/RootLayout.tsx`. Cette classe COUPE le débordement horizontal ' +
        'sans offrir ni barre ni geste : à 375 px, ce qui dépasse est perdu pour ' +
        'l’utilisateur. GESTE : remettre `overflow-x-auto`.',
    ).toBe(false);

    expect(
      classes.includes('overflow-x-auto'),
      'D30-001 : `<main id="main">` n’offre plus de défilement horizontal. ' +
        'GESTE : rétablir `overflow-x-auto` sur le `<main>` de ' +
        '`src/app/RootLayout.tsx` — c’est la seule chose qui rende joignable un ' +
        'contenu plus large que l’écran hors des tableaux couverts par ' +
        '`TableScroll`.',
    ).toBe(true);
  });
});

describe('D30-005 — le tiroir de navigation mobile', () => {
  it('se referme quand la route change', async () => {
    const utilisateur = userEvent.setup();
    const { rerender, arbre } = afficher('/');

    await utilisateur.click(screen.getByRole('button', { name: /ouvrir le menu/i }));
    expect(
      barreDuTiroir() !== undefined,
      'Préalable du test cassé : le clic sur « Ouvrir le menu » n’ouvre plus le ' +
        'tiroir. Vérifier `onOpenMobileSidebar` dans `src/app/RootLayout.tsx` ' +
        'avant de conclure quoi que ce soit sur D30-005.',
    ).toBe(true);

    // Une navigation, quelle qu'en soit l'origine : le chemin change, le
    // composant se re-rend.
    cheminCourant = '/companies';
    rerender(arbre());

    expect(
      barreDuTiroir() === undefined,
      'D30-005 : le tiroir de navigation reste OUVERT après une navigation. ' +
        'L’utilisateur change d’écran sous un tiroir qu’il doit ensuite refermer ' +
        'à la main — quatre appuis là où le bureau demande deux clics. GESTE : ' +
        'rétablir dans `src/app/RootLayout.tsx` le `useEffect` sur ' +
        '`useRouterState(... location.pathname)` qui appelle ' +
        '`setMobileSidebarOpen(false)`.',
    ).toBe(true);
  });

  it('rend la barre en pleine largeur dans le tiroir, et seulement là', async () => {
    const utilisateur = userEvent.setup();
    afficher('/');

    const barresAvant = screen.queryAllByTestId('barre');
    expect(
      barresAvant.every((n) => n.getAttribute('data-pleine-largeur') === 'false'),
      'D30-005 : la barre de bureau reçoit `pleineLargeur`. Ses 260 px sont une ' +
        'largeur de gabarit, pas un accident : seul l’exemplaire rendu dans le ' +
        'tiroir doit prendre la largeur de son conteneur.',
    ).toBe(true);

    await utilisateur.click(screen.getByRole('button', { name: /ouvrir le menu/i }));
    expect(
      barreDuTiroir() !== undefined,
      'D30-005 : la barre rendue dans le tiroir ne reçoit plus `pleineLargeur`. ' +
        'Elle retombe alors à `w-[260px]` dans un panneau plus large : mesure du ' +
        '2026-08-22, 115 px de bande morte sur un téléphone de 375 px, ni barre ' +
        'ni voile, où le tapotement ne fait rien. GESTE : repasser ' +
        '`pleineLargeur` au `<Sidebar>` du `<Drawer>` de ' +
        '`src/app/RootLayout.tsx`.',
    ).toBe(true);
  });

  it('laisse une bande de voile tapotable à côté du panneau', async () => {
    const utilisateur = userEvent.setup();
    const { container } = afficher('/');
    await utilisateur.click(screen.getByRole('button', { name: /ouvrir le menu/i }));

    const panneau = container.querySelector('[data-tiroir-panneau]');
    expect(panneau).not.toBeNull();
    const classes = (panneau as HTMLElement).className;

    expect(
      /(^|\s)w-full(\s|$)/.test(classes),
      'D30-005 : le panneau du `Drawer` est revenu à `w-full`. Plafonné à ' +
        '`max-w-sm` (384 px), il couvre l’écran ENTIER d’un téléphone de 375 px : ' +
        'le voile porteur de `onClick={onClose}` n’est alors atteignable nulle ' +
        'part. GESTE : remettre `w-[calc(100%-3rem)]` dans ' +
        '`src/components/ui/Modal.tsx`.',
    ).toBe(false);

    expect(
      classes.includes('w-[calc(100%-3rem)]'),
      'D30-005 : le panneau du `Drawer` ne réserve plus de bande de voile. ' +
        'GESTE : remettre `w-[calc(100%-3rem)]` sur le panneau dans ' +
        '`src/components/ui/Modal.tsx` — au-delà de 432 px le `max-w-*` reste le ' +
        'plus petit des deux, les tiroirs de bureau sont inchangés.',
    ).toBe(true);
  });
});
