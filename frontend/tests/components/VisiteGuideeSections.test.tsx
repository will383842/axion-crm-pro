/**
 * D23-010 — LA VISITE GUIDÉE DÉPLIE CE QU'ELLE MONTRE.
 *
 * Mesure du 2026-08-22 : la visite compte SEPT étapes, dont deux visent des
 * entrées de la barre latérale (`nav-companies` dans « Contacts »,
 * `nav-settings` dans « Réglages »). La barre n'ouvre QU'UNE section à la fois
 * et masque les listes des autres. Au premier démarrage l'utilisateur est sur
 * `/` : seule « Aujourd'hui » est ouverte, les deux cibles sont `hidden`, et
 * CINQ étapes sur sept s'affichaient.
 *
 * Cette garde monte la barre RÉELLE et actionne le même geste que la visite.
 * Elle ne simule pas Joyride : ce qui pouvait casser, ce n'est pas la
 * bibliothèque, c'est le lien entre une cible et la section qui la porte.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

vi.mock('@tanstack/react-router', () => ({
  // ⚠️ LE BOUCHON DOIT FAIRE SUIVRE LE RESTE DES PROPRIETES.
  //
  // Premiere version : `({ children, to }) => <a href={to}>{children}</a>`.
  // Elle JETAIT tout le reste — dont `data-tour`, l'attribut meme que cette
  // garde cherche. Les trois cas rougissaient sur « cible absente de la barre »
  // alors que la barre etait parfaitement correcte : c'etait le bouchon qui
  // effacait la cible. *Un bouchon qui simplifie ce qu'il imite fait mesurer le
  // bouchon, pas le produit.*
  Link: ({ children, to, ...reste }: { children?: ReactNode; to: string } & Record<string, unknown>) => (
    <a href={to} {...reste}>
      {children}
    </a>
  ),
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    // Le premier démarrage de la visite se fait sur `/` : c'est là que le défaut
    // se produit, la section « Contacts » y est repliée.
    select({ location: { pathname: '/' } }),
}));

vi.mock('@/lib/api', () => ({ api: { get: () => Promise.resolve({ data: {} }), post: () => Promise.resolve({ data: {} }) } }));

const { Sidebar } = await import('@/components/layout/Sidebar');
const { ouvrirSectionDeLaCible } = await import('@/components/OnboardingTour');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

function afficherBarre() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, {
    console_v2: false,
    universes: { business: true, vivier: false },
  });
  return render(
    <QueryClientProvider client={client}>
      <Sidebar collapsed={false} onToggleCollapse={() => {}} />
    </QueryClientProvider>,
  );
}

function listeDe(cible: string): HTMLElement {
  const element = document.querySelector(cible);
  if (element === null) throw new Error(`Cible « ${cible} » absente de la barre — la visite ne pourrait rien montrer.`);
  const liste = element.closest('ul[id^="nav-section-"]');
  if (liste === null) throw new Error(`Cible « ${cible} » hors d'une liste de section.`);
  return liste as HTMLElement;
}

/**
 * ⚠️ `ouvrirSectionDeLaCible()` CLIQUE UN BOUTON REACT — d'où le `act()`.
 *
 * Le clic déclenche un `setState` dans `Sidebar`. Hors d'un `act()`, React 18
 * ne vide pas sa file avant que le test relise le DOM, et la garde accuserait
 * un correctif qui marche. *Une garde qui lit le DOM avant que React l'ait
 * écrit mesure sa propre impatience.*
 */
function deplier(cible: string): void {
  act(() => {
    ouvrirSectionDeLaCible(cible);
  });
}

describe('D23-010 — les cibles de la visite dans la barre latérale', () => {
  it('constate le point de départ : la section « Contacts » est repliée sur /', () => {
    afficherBarre();
    // Sans ce constat, le test suivant pourrait passer au vert sur une barre
    // qui n'a jamais rien replié : il ne prouverait alors rien.
    expect(
      listeDe('[data-tour="nav-companies"]').className.includes('hidden'),
      'Préalable du test cassé : la section portant `nav-companies` n’est plus ' +
        'repliée à l’arrivée sur `/`. La garde ci-dessous ne mesurerait plus le ' +
        'défaut D23-010. Vérifier l’accordéon de `src/components/layout/Sidebar.tsx`.',
    ).toBe(true);
  });

  it('déplie la section qui porte la cible, sans la refermer si elle est déjà ouverte', () => {
    afficherBarre();

    deplier('[data-tour="nav-companies"]');
    expect(
      listeDe('[data-tour="nav-companies"]').className.includes('hidden'),
      'D23-010 : la visite guidée ne déplie plus la section qui porte sa cible. ' +
        'Deux de ses sept étapes visent des entrées masquées : elles ne ' +
        's’affichent pas, et la visite se marque « faite » quand même. GESTE : ' +
        'rétablir l’appel à `ouvrirSectionDeLaCible` sur `EVENTS.STEP_BEFORE` ' +
        'dans `src/components/OnboardingTour.tsx`.',
    ).toBe(false);

    // Deuxième appel : une section déjà ouverte ne doit pas se refermer, sinon
    // la visite masquerait l'élément qu'elle vient de montrer.
    deplier('[data-tour="nav-companies"]');
    expect(
      listeDe('[data-tour="nav-companies"]').className.includes('hidden'),
      'D23-010 : un second appel REFERME la section. L’accordéon est une ' +
        'bascule : `ouvrirSectionDeLaCible` doit vérifier `aria-expanded` avant ' +
        'de cliquer.',
    ).toBe(false);
  });

  it('déplie aussi la section des Réglages, l’autre cible masquée', () => {
    afficherBarre();

    deplier('[data-tour="nav-settings"]');
    expect(
      listeDe('[data-tour="nav-settings"]').className.includes('hidden'),
      'D23-010 : la section « Réglages » reste repliée. C’est l’étape finale de ' +
        'la visite (« pensez à activer la double authentification ») : elle ne ' +
        's’affichait pas.',
    ).toBe(false);
  });

  it('ne fait rien, et ne casse rien, sur une cible hors de la barre', () => {
    afficherBarre();
    // `body`, `[data-tour="dark-mode"]`… : la moitié des étapes ne visent pas la
    // barre. La fonction doit les traverser sans effet.
    expect(() => ouvrirSectionDeLaCible('body')).not.toThrow();
    expect(() => ouvrirSectionDeLaCible('[data-tour="global-search"]')).not.toThrow();
    expect(screen.getByLabelText('Navigation latérale')).toBeInTheDocument();
  });
});
